<?php
// API админки. Все действия — POST с JSON и заголовком X-CSRF (кроме входа).
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/booking.php';
require dirname(__DIR__) . '/lib/notify.php';

start_session();
$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? body_json() : [];
$action = (string)($_GET['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Нужен POST', 405);

// «Знакомое устройство»: подписанная кука после удачного входа (без пароля и без доступа к кабинету)
function device_sig(string $login, string $rnd): string { global $APP; return substr(hash_hmac('sha256', 'dev|' . mb_strtolower($login) . '|' . $rnd, (string)$APP['secret']), 0, 32); }
function known_device(string $login): bool {
    $parts = explode('.', (string)($_COOKIE['bp_dev'] ?? ''));
    return count($parts) === 2 && preg_match('/^[a-f0-9]{16}$/', $parts[0]) && hash_equals(device_sig($login, $parts[0]), $parts[1]);
}
function remember_device(string $login): void {
    $rnd = bin2hex(random_bytes(8));
    setcookie('bp_dev', $rnd . '.' . device_sig($login, $rnd), ['expires' => time() + 365 * 86400, 'path' => '/', 'secure' => !is_local_host(), 'httponly' => true, 'samesite' => 'Strict']);
}

// ---------- вход ----------
if ($action === 'login') {
    $login = is_scalar($in['login'] ?? null) ? mb_substr(trim((string)$in['login']), 0, 60) : '';
    $pass = is_scalar($in['password'] ?? null) ? (string)$in['password'] : '';
    // Считаем только неудачные попытки: 8 с одного адреса и 30 на один логин за 15 минут.
    // Так перебор пароля невозможен, а настоящего владельца нельзя «запереть» парой чужих попыток.
    $ukey = 'u:' . mb_strtolower($login);
    // С устройства, где этот логин уже входил, лимит на логин не действует: чужой перебор не запрёт владельца
    $known = known_device($login);
    if (fails_exceeded('login', 8, 900) || (!$known && fails_exceeded('loginu', 30, 900, $ukey))) fail('Слишком много неудачных попыток. Попробуйте через 15 минут.', 429);
    $st = db()->prepare('SELECT * FROM users WHERE login = ?');
    $st->execute([$login]);
    $u = $st->fetch();
    // проверяем пароль даже для несуществующего логина, чтобы по времени ответа нельзя было угадать логины
    $ok = password_verify($pass, $u ? $u['password_hash'] : '$2y$10$q5sdvpWX2/YTdl9MyGFC7OSeoTpADJYzKj5F00tnYaTMzjAhj5UGS');
    if (!$u || !$ok) { fail_hit('login'); fail_hit('loginu', $ukey); fail('Неверный логин или пароль', 401); }
    if ($u['role'] === 'agency') { $ag = agency_by_id((int)$u['agency_id']); if (!$ag) fail('Неверный логин или пароль', 401); }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        $u['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$u['password_hash'], $u['id']]);
    }
    remember_device($login);
    session_regenerate_id(true);
    $_SESSION = ['uid' => (int)$u['id'], 'role' => $u['role'], 'agency' => $u['agency_id'] ? (int)$u['agency_id'] : null,
        'csrf' => bin2hex(random_bytes(24)), 'fp' => pass_fp($u['password_hash']), 'seen' => time()];
    json_out(me());
}

// ---------- дальше только для вошедших ----------
function kick(string $msg = 'Нужно войти'): void { $_SESSION = []; if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); fail($msg, 401); }
if (empty($_SESSION['uid'])) kick();
if (time() - (int)($_SESSION['seen'] ?? 0) > SESSION_DAYS * 86400) kick('Сессия закончилась, войдите снова');
// Пользователь мог быть удалён, или его пароль сменили: тогда эта сессия больше не действует
$stU = db()->prepare('SELECT id, role, agency_id, password_hash FROM users WHERE id = ?');
$stU->execute([(int)$_SESSION['uid']]);
$user = $stU->fetch();
if (!$user || $user['role'] !== $_SESSION['role'] || !hash_equals(pass_fp($user['password_hash']), (string)($_SESSION['fp'] ?? ''))) kick('Сессия устарела, войдите снова');
if ($user['role'] !== 'super' && (int)$user['agency_id'] !== (int)($_SESSION['agency'] ?? 0)) kick('Сессия устарела, войдите снова');
if (time() - (int)$_SESSION['seen'] > 300) $_SESSION['seen'] = time();
// «me» только читает и отдаёт токен; остальное требует токен в заголовке
if ($action !== 'me' && !hash_equals($_SESSION['csrf'] ?? '', (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('Сессия устарела, обновите страницу', 403);
$isSuper = $_SESSION['role'] === 'super';

function me(): array {
    $agency = null;
    if (!empty($_SESSION['agency'])) { $a = agency_by_id((int)$_SESSION['agency']); if ($a) $agency = ['id' => (int)$a['id'], 'slug' => $a['slug'], 'name' => $a['cfg']['name'] ?? $a['slug'], 'active' => (bool)$a['active']]; }
    return ['role' => $_SESSION['role'], 'agency' => $agency, 'csrf' => $_SESSION['csrf']];
}
function current_agency(): array {
    if (empty($_SESSION['agency'])) fail('Выберите агентство', 400);
    $a = agency_by_id((int)$_SESSION['agency']);
    if (!$a) fail('Агентство не найдено', 404);
    return $a;
}
function need_super(): void { global $isSuper; if (!$isSuper) fail('Нет доступа', 403); }
// Удаляет загруженный файл агентства (только свой и только из папок загрузок)
function remove_upload(int $aid, string $path): void {
    if (preg_match('~^uploads/(logos|heroes|chars)/a' . $aid . '-[a-f0-9]+\.(webp|png|jpe?g)$~', $path)) @unlink(APP_ROOT . '/' . $path);
}
// Колонка-метка, объединяющая часы одного заказа по телефону; в старых базах добавляется при первом обращении
function ensure_blocks_grp(): void {
    static $done = false; if ($done) return;
    try { db()->query('SELECT grp FROM blocks LIMIT 1'); }
    catch (Throwable $e) { db()->exec("ALTER TABLE blocks ADD COLUMN grp VARCHAR(16) NOT NULL DEFAULT ''"); }
    $done = true;
}
function valid_date(string $d): string { if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4))) fail('Неверная дата'); return $d; }

switch ($action) {
    case 'me': json_out(me());

    case 'logout': $_SESSION = []; session_destroy(); json_out(['ok' => true]);

    case 'password': // сменить свой пароль
        $new = in_str($in, 'new');
        if (strlen($new) < 10) fail('Пароль не короче 10 символов');
        rate_limit('passwd', 10, 900);
        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?'); $st->execute([$_SESSION['uid']]);
        if (!password_verify(in_str($in, 'old'), (string)$st->fetchColumn())) fail('Старый пароль неверный');
        if (strlen($new) > 200) fail('Слишком длинный пароль');
        $hash = password_hash($new, PASSWORD_DEFAULT);
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $_SESSION['uid']]);
        $_SESSION['fp'] = pass_fp($hash); // эта сессия остаётся, остальные (на других устройствах) выходят
        json_out(['ok' => true]);

    // ----- заявки -----
    case 'orders':
        $a = current_agency();
        $from = valid_date(in_str($in, 'from', (string)(date('Y-m-d'))));
        $to = valid_date(in_str($in, 'to', (string)($a['cfg']['season']['end'])));
        $st = db()->prepare('SELECT id, visit_date, visit_time, span, data, name, phone, address, total, price_lines, status, created_at FROM orders WHERE agency_id = ? AND visit_date BETWEEN ? AND ? ORDER BY visit_date, visit_time, id');
        $st->execute([$a['id'], $from, $to]);
        $rows = [];
        foreach ($st as $r) { $r['data'] = json_decode($r['data'], true); $r['price_lines'] = json_decode($r['price_lines'], true); $rows[] = $r; }
        json_out(['orders' => $rows]);

    case 'order_status':
        $a = current_agency();
        $status = in_str($in, 'status');
        if (!in_array($status, ['new', 'confirmed', 'done', 'cancelled'], true)) fail('Неверный статус');
        $st = db()->prepare('UPDATE orders SET status = ? WHERE id = ? AND agency_id = ?');
        $st->execute([$status, (int)($in['id'] ?? 0), $a['id']]);
        json_out(['ok' => $st->rowCount() > 0]);

    case 'order_delete': // удалить заявку навсегда (персональные данные клиента тоже удаляются)
        $a = current_agency();
        $st = db()->prepare('DELETE FROM orders WHERE id = ? AND agency_id = ?');
        $st->execute([(int)($in['id'] ?? 0), $a['id']]);
        if (!$st->rowCount()) fail('Заявка не найдена', 404);
        json_out(['ok' => true]);

    // ----- расписание -----
    case 'schedule':
        $a = current_agency(); $cfg = $a['cfg'];
        $date = valid_date(in_str($in, 'date', date('Y-m-d', strtotime('+1 day'))));
        $occ = occupancy((int)$a['id'], $date, $date);
        ensure_blocks_grp();
        $st = db()->prepare('SELECT id, visit_time, teams, note, grp FROM blocks WHERE agency_id = ? AND visit_date = ? ORDER BY visit_time, id');
        $st->execute([$a['id'], $date]);
        $blocks = $st->fetchAll();
        $st = db()->prepare("SELECT id, visit_time, span, name, address, status FROM orders WHERE agency_id = ? AND visit_date = ? AND status IN ('new','confirmed') ORDER BY visit_time");
        $st->execute([$a['id'], $date]);
        $orders = $st->fetchAll();
        $slots = [];
        foreach (slot_times($cfg, $date) as $t) $slots[] = ['time' => $t, 'busy' => (int)($occ[$date][$t] ?? 0), 'total' => (int)$cfg['pairs']];
        json_out(['date' => $date, 'slots' => $slots, 'blocks' => $blocks, 'orders' => $orders]);

    case 'block_add':
        $a = current_agency();
        $date = valid_date(in_str($in, 'date'));
        $time = is_scalar($in['time'] ?? null) ? (string)$in['time'] : '';
        $times = slot_times($a['cfg'], $date);
        if (!in_array($time, $times, true)) fail('Неверное время');
        $teams = max(1, min((int)$a['cfg']['pairs'], (int)($in['teams'] ?? 1)));
        // Заказ по телефону занимает столько часов, сколько длится программа вместе с дорогой — как заказ с сайта
        $hours = 1;
        if (is_scalar($in['program'] ?? null) && $in['program'] !== '') {
            if (!find_item($a['cfg']['programs'], (string)$in['program'])) fail('Нет такой программы');
            $hours = order_span($a['cfg'], (string)$in['program']);
        }
        $note = is_scalar($in['note'] ?? null) ? mb_substr(trim((string)$in['note']), 0, 100) : '';
        ensure_blocks_grp();
        $grp = bin2hex(random_bytes(8)); // общая метка для всех часов одного заказа
        $ins = db()->prepare('INSERT INTO blocks (agency_id, visit_date, visit_time, teams, note, created_at, grp) VALUES (?,?,?,?,?,?,?)');
        for ($i = 0; $i < $hours; $i++) {
            $t = hhmm(minutes_of($time) + $i * 60);
            if ($i > 0 && !in_array($t, $times, true)) break;
            $ins->execute([$a['id'], $date, $t, $teams, $note, date('Y-m-d H:i:s'), $grp]);
        }
        json_out(['ok' => true]);

    case 'block_remove': // убирает блокировку целиком, со всеми часами этого заказа
        $a = current_agency();
        ensure_blocks_grp();
        $st = db()->prepare('SELECT grp FROM blocks WHERE id = ? AND agency_id = ?');
        $st->execute([(int)($in['id'] ?? 0), $a['id']]);
        $g = $st->fetchColumn();
        if ($g !== false && $g !== '') db()->prepare('DELETE FROM blocks WHERE agency_id = ? AND grp = ?')->execute([$a['id'], $g]);
        else db()->prepare('DELETE FROM blocks WHERE id = ? AND agency_id = ?')->execute([(int)($in['id'] ?? 0), $a['id']]);
        json_out(['ok' => true]);

    // ----- настройки агентства -----
    case 'config_get':
        $a = current_agency();
        json_out(['slug' => $a['slug'], 'config' => $a['cfg']]);

    case 'config_save':
        $a = current_agency();
        $c = $in['config'] ?? null;
        if (!is_array($c)) fail('Нет настроек');
        if (!$isSuper) $c['demo'] = $a['cfg']['demo'] ?? false; // демо-режим меняет только владелец сервиса
        $c['logo'] = $a['cfg']['logo'] ?? '';                   // логотип и фон меняются только загрузкой файла
        $c['heroImage'] = $a['cfg']['heroImage'] ?? '';
        $errors = [];
        $clean = normalize_config($c, $errors);
        if ($errors) fail(implode('. ', array_slice(array_values(array_unique($errors)), 0, 5)));
        $aid = (int)$a['id'];
        // фото составов — только свои файлы этого агентства
        $used = [];
        foreach ($clean['characters'] as &$ch) {
            if (!empty($ch['photo']) && (!preg_match('~^uploads/chars/a' . $aid . '-~', $ch['photo']) || !is_file(APP_ROOT . '/' . $ch['photo']))) unset($ch['photo']);
            if (!empty($ch['photo'])) $used[] = $ch['photo'];
        }
        unset($ch);
        db()->prepare('UPDATE agencies SET config = ? WHERE id = ?')->execute([json_encode($clean, JSON_UNESCAPED_UNICODE), $aid]);
        foreach (glob(APP_ROOT . '/uploads/chars/a' . $aid . '-*') ?: [] as $f) if (!in_array('uploads/chars/' . basename($f), $used, true)) @unlink($f);
        json_out(['ok' => true, 'config' => $clean]);

    // ----- картинки агентства: логотип, фон первого экрана, фото составов -----
    case 'logo_upload': $in['kind'] = 'logo'; // старое название действия
    case 'image_upload':
        $a = current_agency(); $aid = (int)$a['id'];
        $kind = in_str($in, 'kind');
        $dirs = ['logo' => 'logos', 'hero' => 'heroes', 'char' => 'chars'];
        $maxSide = ['logo' => 2000, 'hero' => 4000, 'char' => 3000];
        if (!isset($dirs[$kind])) fail('Неизвестный вид картинки');
        $charId = in_str($in, 'char');
        if ($kind === 'char' && !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $charId)) fail('Не выбран состав');
        if (!preg_match('~^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$~', in_str($in, 'data'), $m)) fail('Загрузите картинку PNG, JPG или WebP');
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > 1500000) fail('Картинка слишком большая, нужна до 1,5 МБ');
        $info = @getimagesizefromstring($bin);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true) || $info[0] > $maxSide[$kind] || $info[1] > $maxSide[$kind]) fail('Не похоже на картинку PNG, JPG или WebP');
        $dir = APP_ROOT . '/uploads/' . $dirs[$kind];
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) fail('Не удалось сохранить файл на сервере', 500);
        $name = 'a' . $aid . '-' . bin2hex(random_bytes(4));
        // перекодируем через GD: в файле остаётся только картинка, без посторонних данных
        $img = function_exists('imagecreatefromstring') ? @imagecreatefromstring($bin) : false;
        if ($img && function_exists('imagewebp')) {
            imagepalettetotruecolor($img); imagealphablending($img, true); imagesavealpha($img, true);
            $file = "$name.webp"; $ok = imagewebp($img, "$dir/$file", $kind === 'hero' ? 82 : 88); imagedestroy($img);
        } else {
            $file = $name . ['png' => '.png', 'jpeg' => '.jpg', 'webp' => '.webp'][$m[1]]; $ok = file_put_contents("$dir/$file", $bin) !== false;
        }
        if (!$ok) fail('Не удалось сохранить файл на сервере', 500);
        $path = 'uploads/' . $dirs[$kind] . '/' . $file;
        $cfg = $a['cfg'];
        if ($kind === 'char') {
            // если состав уже сохранён, сразу ставим фото; новый состав получит его при сохранении цен
            foreach ($cfg['characters'] as &$ch) if (($ch['id'] ?? '') === $charId) { remove_upload($aid, (string)($ch['photo'] ?? '')); $ch['photo'] = $path; }
            unset($ch);
        } else {
            $field = $kind === 'logo' ? 'logo' : 'heroImage';
            remove_upload($aid, (string)($cfg[$field] ?? ''));
            $cfg[$field] = $path;
        }
        db()->prepare('UPDATE agencies SET config = ? WHERE id = ?')->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE), $aid]);
        json_out(['path' => $path, 'logo' => $path]);

    case 'logo_remove': $in['kind'] = 'logo';
    case 'image_remove':
        $a = current_agency(); $aid = (int)$a['id']; $cfg = $a['cfg'];
        $kind = in_str($in, 'kind');
        if ($kind === 'char') {
            $charId = in_str($in, 'char');
            foreach ($cfg['characters'] as &$ch) if (($ch['id'] ?? '') === $charId) { remove_upload($aid, (string)($ch['photo'] ?? '')); unset($ch['photo']); }
            unset($ch);
        } elseif ($kind === 'logo' || $kind === 'hero') {
            $field = $kind === 'logo' ? 'logo' : 'heroImage';
            remove_upload($aid, (string)($cfg[$field] ?? ''));
            unset($cfg[$field]);
        } else fail('Неизвестный вид картинки');
        db()->prepare('UPDATE agencies SET config = ? WHERE id = ?')->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE), $aid]);
        json_out(['ok' => true]);

    // ----- уведомления -----
    case 'notify_get':
        $a = current_agency();
        if (tg_ready()) { try { tg_poll(); } catch (Throwable $e) { error_log('tg_poll: ' . $e->getMessage()); } }
        json_out(['tgReady' => tg_ready(), 'tgChats' => count(tg_chats((int)$a['id']))]);

    case 'tg_link':
        $a = current_agency();
        if (!tg_ready()) fail('Уведомления в Telegram пока не настроены владельцем сервиса');
        $bot = tg_bot();
        if (!$bot) fail('Не удалось связаться с Telegram. Попробуйте через минуту.', 502);
        foreach (db()->query("SELECT name, value FROM settings WHERE name LIKE 'tglink:%'")->fetchAll() as $row) {
            if ((json_decode($row['value'], true)['exp'] ?? 0) < time()) db()->prepare('DELETE FROM settings WHERE name = ?')->execute([$row['name']]);
        }
        $code = bin2hex(random_bytes(8));
        setting_set('tglink:' . $code, ['aid' => (int)$a['id'], 'exp' => time() + 3600]);
        json_out(['url' => 'https://t.me/' . $bot['username'] . '?start=' . $code]);

    case 'tg_off':
        $a = current_agency();
        tg_set_chats((int)$a['id'], []);
        json_out(['ok' => true]);

    case 'notify_test':
        $a = current_agency();
        rate_limit('ntest', 5, 600);
        $sent = notify_agency($a, 'Проверка уведомлений', "Проверка: уведомления о заявках «" . ($a['cfg']['name'] ?? $a['slug']) . "» работают.

Так будут приходить новые заявки.");
        json_out($sent);

    // ----- главная страница (владелец) -----
    case 'site_get':
        need_super();
        json_out(['landing' => setting_get('landing')]);

    case 'site_save':
        need_super();
        $d = $in['landing'] ?? null;
        if (!is_array($d) || trim((string)($d['heroTitle'] ?? '')) === '') fail('Заполните заголовок');
        if (!empty($d['telegram']) && !preg_match('#^https://[^\s<>"]+$#', (string)$d['telegram'])) fail('Ссылка на Telegram должна начинаться с https://');
        if (!empty($d['demoSlug']) && !preg_match('/^[a-z0-9-]{2,40}$/', (string)$d['demoSlug'])) fail('Адрес демо: латиница, цифры и дефис');
        $clean = function ($v, $max) { return mb_substr(trim((string)$v), 0, $max); };
        $out = [];
        foreach (['telegram', 'heroBadge', 'heroTitle', 'heroAccent', 'heroLead', 'demoButton', 'contactButton', 'loginLink', 'itemsTitle', 'itemsLead', 'stepsTitle', 'faqTitle', 'finalTitle', 'finalText', 'finalDemoButton', 'finalContactButton', 'footer', 'footerTagline', 'demoSlug'] as $k) $out[$k] = $clean($d[$k] ?? '', 600);
        foreach (['strip' => ['title'], 'items' => ['title', 'text'], 'steps' => ['title', 'text'], 'faq' => ['q', 'a']] as $list => $keys) {
            $out[$list] = [];
            foreach (array_slice((array)($d[$list] ?? []), 0, 20) as $row) { $r = []; foreach ($keys as $k) $r[$k] = $clean($row[$k] ?? '', 1000); if (implode('', $r) !== '') $out[$list][] = $r; }
        }
        setting_set('landing', $out);
        json_out(['ok' => true]);

    // ----- только владелец сервиса -----
    case 'agencies':
        need_super();
        $rows = [];
        foreach (db()->query('SELECT a.id, a.slug, a.config, a.active, a.created_at, (SELECT COUNT(*) FROM orders o WHERE o.agency_id = a.id) AS orders_count, (SELECT login FROM users u WHERE u.agency_id = a.id LIMIT 1) AS login FROM agencies a ORDER BY a.id DESC') as $r) {
            $cfg = json_decode($r['config'], true) ?: [];
            $rows[] = ['id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $cfg['name'] ?? $r['slug'], 'city' => $cfg['city'] ?? '', 'demo' => !empty($cfg['demo']), 'active' => (bool)$r['active'], 'orders' => (int)$r['orders_count'], 'login' => $r['login'], 'created' => $r['created_at']];
        }
        json_out(['agencies' => $rows]);

    case 'agency_create':
        need_super();
        $slug = strtolower(trim(in_str($in, 'slug')));
        $name = trim(in_str($in, 'name'));
        if (!preg_match('/^[a-z0-9-]{2,40}$/', $slug)) fail('Адрес ссылки: латиница, цифры и дефис, от 2 символов');
        if (in_array($slug, ['admin', 'api', 'assets', 'agencies', 'install', 'lib', 'uploads', 'docs', 'data', 'zapis', 'consent', 'privacy', 'index', 'demo', 'www', 'mail'], true)) fail('Такой адрес занят системой');
        if (agency_by_slug($slug)) fail('Такой адрес уже есть');
        if ($name === '') fail('Укажите название');
        $tpl = json_decode(file_get_contents(APP_ROOT . '/agencies/demo.json'), true);
        $tpl['name'] = mb_substr($name, 0, 80);
        $tpl['city'] = mb_substr(trim(in_str($in, 'city')), 0, 60);
        $tpl['demo'] = false;
        $tpl['legal'] = ['operator' => '', 'inn' => '', 'ogrn' => '', 'address' => '', 'email' => '', 'keepMonths' => 12, 'updated' => date('d.m.Y'), 'policyUrl' => ''];
        db()->prepare('INSERT INTO agencies (slug, config, active, created_at) VALUES (?, ?, 1, ?)')->execute([$slug, json_encode($tpl, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]);
        json_out(['ok' => true, 'id' => (int)db()->lastInsertId()]);

    case 'agency_open': // зайти в агентство как владелец сервиса
        need_super();
        $a = agency_by_id((int)($in['id'] ?? 0));
        if (!$a) fail('Агентство не найдено', 404);
        $_SESSION['agency'] = (int)$a['id'];
        json_out(me());

    case 'agency_toggle':
        need_super();
        db()->prepare('UPDATE agencies SET active = ? WHERE id = ?')->execute([!empty($in['active']) ? 1 : 0, (int)($in['id'] ?? 0)]);
        json_out(['ok' => true]);

    case 'agency_delete': // удалить агентство вместе с заявками, блокировками и входом
        need_super();
        $a = agency_by_id((int)($in['id'] ?? 0));
        if (!$a) fail('Агентство не найдено', 404);
        if ($a['slug'] === 'demo') fail('Демо-агентство удалить нельзя: его показывают на главной');
        if (in_str($in, 'confirm') !== $a['slug']) fail('Для удаления введите адрес агентства точно');
        $pdo = db(); $aid = (int)$a['id'];
        $pdo->beginTransaction();
        foreach (['orders', 'blocks', 'users'] as $t) $pdo->prepare("DELETE FROM $t WHERE agency_id = ?")->execute([$aid]);
        $pdo->prepare('DELETE FROM agencies WHERE id = ?')->execute([$aid]);
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute(["tgchats:$aid"]);
        $pdo->commit();
        foreach (['logos', 'heroes', 'chars'] as $d) foreach (glob(APP_ROOT . "/uploads/$d/a$aid-*") ?: [] as $f) @unlink($f);
        if ((int)($_SESSION['agency'] ?? 0) === $aid) unset($_SESSION['agency']);
        json_out(me());

    case 'agency_user': // создать или сбросить вход для агентства
        need_super();
        $aid = (int)($in['id'] ?? 0);
        if (!agency_by_id($aid)) fail('Агентство не найдено', 404);
        $login = in_str($in, 'login'); $pass = in_str($in, 'password');
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/', $login)) fail('Логин: латиница и цифры, от 3 символов');
        if (strlen($pass) < 10) fail('Пароль не короче 10 символов');
        $st = db()->prepare('SELECT id, agency_id FROM users WHERE login = ?'); $st->execute([$login]); $u = $st->fetch();
        if ($u && (int)$u['agency_id'] !== $aid) fail('Такой логин уже занят');
        // У агентства один вход: новый логин заменяет старый, а новый пароль выкидывает всех, кто был внутри
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $st = db()->prepare("SELECT id FROM users WHERE agency_id = ? AND role = 'agency' ORDER BY id"); $st->execute([$aid]); $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($ids) {
            db()->prepare('UPDATE users SET login = ?, password_hash = ? WHERE id = ?')->execute([$login, $hash, $ids[0]]);
            if (count($ids) > 1) db()->prepare('DELETE FROM users WHERE agency_id = ? AND role = ? AND id <> ?')->execute([$aid, 'agency', $ids[0]]);
        } else {
            db()->prepare('INSERT INTO users (agency_id, login, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$aid, $login, $hash, 'agency', date('Y-m-d H:i:s')]);
        }
        json_out(['ok' => true]);
}
fail('Неизвестное действие', 404);
