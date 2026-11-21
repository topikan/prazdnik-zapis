<?php
// Регистрация агентства и восстановление пароля. Почта подтверждается кодом из письма.
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/booking.php';
require dirname(__DIR__) . '/lib/notify.php';
require dirname(__DIR__) . '/lib/billing.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Нужен POST', 405);
$in = body_json();
$action = (string)($_GET['action'] ?? '');
ensure_billing_cols();

const CODE_TTL = 1800;   // код действует 30 минут
const CODE_TRIES = 5;    // после 5 неверных кодов нужно начинать заново

function reg_settings(): array { $r = setting_get('reg') ?: []; return ['open' => ($r['open'] ?? true) !== false, 'manual' => !empty($r['manual'])]; }
function norm_email(string $e): string {
    $e = mb_strtolower(trim($e));
    if (strlen($e) > 60 || !filter_var($e, FILTER_VALIDATE_EMAIL)) fail('Проверьте адрес почты');
    return $e;
}
function new_code(): string { return sprintf('%06d', random_int(0, 999999)); }
function code_hash(string $code, string $salt): string { global $APP; return hash_hmac('sha256', $salt . '|' . $code, (string)$APP['secret']); }
function pending_get(string $name): ?array { $p = setting_get($name); return $p && ($p['exp'] ?? 0) >= time() ? $p : null; }
function pending_del(string $name): void { db()->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]); }
function pending_cleanup(): void {
    if (random_int(1, 20) !== 1) return;
    foreach (db()->query("SELECT name, value FROM settings WHERE name LIKE 'reg:%' OR name LIKE 'rst:%'")->fetchAll() as $r) {
        if ((json_decode($r['value'], true)['exp'] ?? 0) < time()) pending_del($r['name']);
    }
}
function user_by_email(string $email): ?array {
    $st = db()->prepare('SELECT * FROM users WHERE login = ?'); $st->execute([$email]);
    $u = $st->fetch();
    if ($u) return $u;
    // вход, созданный владельцем вручную: ищем агентство по почте регистрации
    $st = db()->prepare("SELECT u.* FROM users u JOIN agencies a ON a.id = u.agency_id WHERE a.email = ? AND u.role = 'agency' LIMIT 1"); $st->execute([$email]);
    $u = $st->fetch();
    return $u ?: null;
}
function send_code(string $email, string $code, string $why): bool {
    $text = "Ваш код: $code\n\n$why Код действует 30 минут.\n\nЕсли вы ничего не запрашивали, просто удалите это письмо.\n\n— Бронь Праздник, " . site_url();
    return mail_text($email, "Код $code — Бронь Праздник", $text);
}
pending_cleanup();

switch ($action) {
    case 'slug': // свободен ли адрес страницы
        rate_limit('rslug', 60, 600);
        $slug = strtolower(trim(in_str($in, 'slug')));
        $p = slug_problem($slug);
        json_out(['ok' => $p === '', 'error' => $p]);

    case 'start':
        if (!empty($in['website'])) json_out(['token' => bin2hex(random_bytes(16)), 'email' => '']); // ловушка для ботов
        if (!reg_settings()['open']) fail('Регистрация временно закрыта. Напишите нам в Telegram, подключим вручную.');
        rate_limit('regip', 6, 3600);
        $name = trim(preg_replace('/\s+/u', ' ', in_str($in, 'name')));
        $city = trim(preg_replace('/\s+/u', ' ', in_str($in, 'city')));
        $phoneDigits = preg_replace('/\D/', '', in_str($in, 'phone'));
        if (strlen($phoneDigits) === 11 && $phoneDigits[0] === '8') $phoneDigits = '7' . substr($phoneDigits, 1);
        if (strlen($phoneDigits) === 10) $phoneDigits = '7' . $phoneDigits;
        $email = norm_email(in_str($in, 'email'));
        $pass = in_str($in, 'password');
        $slug = strtolower(trim(in_str($in, 'slug')));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) fail('Укажите название агентства');
        if ($city === '' || mb_strlen($city) > 60) fail('Укажите город');
        if (strlen($phoneDigits) !== 11 || $phoneDigits[0] !== '7') fail('Проверьте номер телефона');
        if (strlen($pass) < 10) fail('Пароль — не короче 10 символов');
        if (strlen($pass) > 200) fail('Слишком длинный пароль');
        if (empty($in['agreeRules']) || empty($in['agreePd'])) fail('Нужно принять правила сервиса и согласие на обработку данных');
        if (($p = slug_problem($slug)) !== '') fail($p);
        if (user_by_email($email)) fail('Эта почта уже зарегистрирована. Войдите или восстановите пароль.');
        rate_limit('regmail', 4, 3600, 'm:' . $email);
        $token = bin2hex(random_bytes(16)); $code = new_code();
        $phone = '+7 (' . substr($phoneDigits, 1, 3) . ') ' . substr($phoneDigits, 4, 3) . '-' . substr($phoneDigits, 7, 2) . '-' . substr($phoneDigits, 9);
        setting_set('reg:' . $token, ['name' => mb_substr($name, 0, 80), 'city' => mb_substr($city, 0, 60), 'phone' => $phone, 'email' => $email, 'slug' => $slug,
            'pass' => password_hash($pass, PASSWORD_DEFAULT), 'code' => code_hash($code, $token), 'exp' => time() + CODE_TTL, 'tries' => 0, 'sent' => time(), 'ip' => client_key()]);
        if (!send_code($email, $code, 'Введите его на странице регистрации, чтобы подтвердить почту.')) { pending_del('reg:' . $token); fail('Не получилось отправить письмо. Проверьте адрес или попробуйте через пару минут.', 502); }
        json_out(['token' => $token, 'email' => $email]);

    case 'resend':
        $token = in_str($in, 'token');
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !($p = pending_get('reg:' . $token))) fail('Регистрация устарела, заполните форму ещё раз', 410);
        if (time() - (int)$p['sent'] < 60) fail('Отправить код ещё раз можно через минуту');
        rate_limit('regmail', 4, 3600, 'm:' . $p['email']);
        $code = new_code();
        $p['code'] = code_hash($code, $token); $p['tries'] = 0; $p['sent'] = time(); $p['exp'] = time() + CODE_TTL;
        setting_set('reg:' . $token, $p);
        if (!send_code($p['email'], $code, 'Введите его на странице регистрации, чтобы подтвердить почту.')) fail('Не получилось отправить письмо. Попробуйте через пару минут.', 502);
        json_out(['ok' => true]);

    case 'verify':
        rate_limit('regver', 30, 900);
        $token = in_str($in, 'token'); $code = preg_replace('/\D/', '', in_str($in, 'code'));
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !($p = pending_get('reg:' . $token))) fail('Регистрация устарела, заполните форму ещё раз', 410);
        if (!hash_equals((string)$p['code'], code_hash($code, $token))) {
            $p['tries'] = (int)$p['tries'] + 1;
            if ($p['tries'] >= CODE_TRIES) { pending_del('reg:' . $token); fail('Слишком много неверных кодов. Заполните форму ещё раз.', 410); }
            setting_set('reg:' . $token, $p);
            fail('Неверный код. Осталось попыток: ' . (CODE_TRIES - $p['tries']));
        }
        pending_del('reg:' . $token);
        if (($pr = slug_problem($p['slug'])) !== '') fail($pr . '. Заполните форму ещё раз с другим адресом.', 409);
        if (user_by_email($p['email'])) fail('Эта почта уже зарегистрирована. Войдите или восстановите пароль.', 409);
        $manual = reg_settings()['manual'];
        $pdo = db();
        $pdo->beginTransaction();
        $aid = create_agency($p['slug'], $p['name'], $p['city'], $p['phone'], $p['email'], !$manual, trial_for_new());
        $pdo->prepare('INSERT INTO users (agency_id, login, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$aid, $p['email'], $p['pass'], 'agency', date('Y-m-d H:i:s')]);
        $uid = (int)$pdo->lastInsertId();
        $pdo->commit();
        session_login(['id' => $uid, 'login' => $p['email'], 'role' => 'agency', 'agency_id' => $aid, 'password_hash' => $p['pass']]);
        session_write_close();
        notify_owner('Новое агентство: ' . $p['name'], "Новое агентство зарегистрировалось\n\n{$p['name']}, {$p['city']}\nТелефон: {$p['phone']}\nПочта: {$p['email']}\nСтраница: " . site_url() . '/' . $p['slug']
            . ($manual ? "\n\nСтраница ждёт одобрения: «Агентства» → «Включить»." : "\n\nЕсли что-то не так: «Агентства» → «Выключить».") . "\n" . site_url() . '/admin/');
        json_out(['ok' => true, 'manual' => $manual]);

    case 'reset_start':
        rate_limit('rstip', 6, 3600);
        $email = norm_email(in_str($in, 'email'));
        $u = user_by_email($email);
        // ответ одинаковый, есть такая почта или нет: так нельзя проверить, кто зарегистрирован
        if ($u && $u['role'] === 'agency') {
            rate_limit('rstmail', 3, 3600, 'm:' . $email);
            $key = 'rst:' . substr(hash('sha256', $email), 0, 32); $code = new_code();
            setting_set($key, ['uid' => (int)$u['id'], 'code' => code_hash($code, $key), 'exp' => time() + CODE_TTL, 'tries' => 0]);
            send_code($email, $code, 'Введите его на странице входа, чтобы задать новый пароль.');
        }
        json_out(['ok' => true]);

    case 'reset_finish':
        rate_limit('rstfin', 15, 900);
        $email = norm_email(in_str($in, 'email'));
        $code = preg_replace('/\D/', '', in_str($in, 'code'));
        $pass = in_str($in, 'password');
        if (strlen($pass) < 10) fail('Новый пароль — не короче 10 символов');
        if (strlen($pass) > 200) fail('Слишком длинный пароль');
        $key = 'rst:' . substr(hash('sha256', $email), 0, 32);
        $p = pending_get($key);
        if (!$p) fail('Код устарел или неверный. Запросите новый.', 410);
        if (!hash_equals((string)$p['code'], code_hash($code, $key))) {
            $p['tries'] = (int)$p['tries'] + 1;
            if ($p['tries'] >= CODE_TRIES) { pending_del($key); fail('Слишком много неверных кодов. Запросите новый.', 410); }
            setting_set($key, $p);
            fail('Неверный код. Осталось попыток: ' . (CODE_TRIES - $p['tries']));
        }
        pending_del($key);
        // новый пароль выкидывает из кабинета все старые сессии (у них другой отпечаток пароля)
        db()->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'agency'")->execute([password_hash($pass, PASSWORD_DEFAULT), (int)$p['uid']]);
        json_out(['ok' => true]);
}
fail('Неизвестное действие', 404);
