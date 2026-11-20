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

// ---------- вход ----------
if ($action === 'login') {
    rate_limit('login', 8, 900);
    $st = db()->prepare('SELECT * FROM users WHERE login = ?');
    $st->execute([(string)($in['login'] ?? '')]);
    $u = $st->fetch();
    if (!$u || !password_verify((string)($in['password'] ?? ''), $u['password_hash'])) fail('Неверный логин или пароль', 401);
    session_regenerate_id(true);
    $_SESSION = ['uid' => (int)$u['id'], 'role' => $u['role'], 'agency' => $u['agency_id'] ? (int)$u['agency_id'] : null, 'csrf' => bin2hex(random_bytes(24))];
    json_out(me());
}

// ---------- дальше только для вошедших ----------
if (empty($_SESSION['uid'])) fail('Нужно войти', 401);
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
function valid_date(string $d): string { if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) fail('Неверная дата'); return $d; }

switch ($action) {
    case 'me': json_out(me());

    case 'logout': $_SESSION = []; session_destroy(); json_out(['ok' => true]);

    case 'password': // сменить свой пароль
        $new = (string)($in['new'] ?? '');
        if (strlen($new) < 10) fail('Пароль не короче 10 символов');
        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?'); $st->execute([$_SESSION['uid']]);
        if (!password_verify((string)($in['old'] ?? ''), (string)$st->fetchColumn())) fail('Старый пароль неверный');
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['uid']]);
        json_out(['ok' => true]);

    // ----- заявки -----
    case 'orders':
        $a = current_agency();
        $from = valid_date((string)($in['from'] ?? date('Y-m-d')));
        $to = valid_date((string)($in['to'] ?? $a['cfg']['season']['end']));
        $st = db()->prepare('SELECT id, visit_date, visit_time, span, data, name, phone, address, total, price_lines, status, created_at FROM orders WHERE agency_id = ? AND visit_date BETWEEN ? AND ? ORDER BY visit_date, visit_time, id');
        $st->execute([$a['id'], $from, $to]);
        $rows = [];
        foreach ($st as $r) { $r['data'] = json_decode($r['data'], true); $r['price_lines'] = json_decode($r['price_lines'], true); $rows[] = $r; }
        json_out(['orders' => $rows]);

    case 'order_status':
        $a = current_agency();
        $status = (string)($in['status'] ?? '');
        if (!in_array($status, ['new', 'confirmed', 'done', 'cancelled'], true)) fail('Неверный статус');
        $st = db()->prepare('UPDATE orders SET status = ? WHERE id = ? AND agency_id = ?');
        $st->execute([$status, (int)($in['id'] ?? 0), $a['id']]);
        json_out(['ok' => $st->rowCount() > 0]);

    // ----- расписание -----
    case 'schedule':
        $a = current_agency(); $cfg = $a['cfg'];
        $date = valid_date((string)($in['date'] ?? date('Y-m-d', strtotime('+1 day'))));
        $occ = occupancy((int)$a['id'], $date, $date);
        $st = db()->prepare('SELECT id, visit_time, teams, note FROM blocks WHERE agency_id = ? AND visit_date = ? ORDER BY visit_time');
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
        $date = valid_date((string)($in['date'] ?? ''));
        $time = (string)($in['time'] ?? '');
        if (!in_array($time, slot_times($a['cfg'], $date), true)) fail('Неверное время');
        $teams = max(1, min((int)$a['cfg']['pairs'], (int)($in['teams'] ?? 1)));
        db()->prepare('INSERT INTO blocks (agency_id, visit_date, visit_time, teams, note, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$a['id'], $date, $time, $teams, mb_substr((string)($in['note'] ?? ''), 0, 100), date('Y-m-d H:i:s')]);
        json_out(['ok' => true]);

    case 'block_remove':
        $a = current_agency();
        db()->prepare('DELETE FROM blocks WHERE id = ? AND agency_id = ?')->execute([(int)($in['id'] ?? 0), $a['id']]);
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
        $errors = validate_config($c);
        if ($errors) fail(implode('. ', $errors));
        db()->prepare('UPDATE agencies SET config = ? WHERE id = ?')->execute([json_encode($c, JSON_UNESCAPED_UNICODE), $a['id']]);
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
        foreach (['telegram', 'footerLink'] as $k) if (!empty($d[$k]) && !preg_match('#^https://#', (string)$d[$k])) fail('Ссылки должны начинаться с https://');
        if (!empty($d['demoSlug']) && !preg_match('/^[a-z0-9-]{2,40}$/', (string)$d['demoSlug'])) fail('Адрес демо: латиница, цифры и дефис');
        $clean = function ($v, $max) { return mb_substr(trim((string)$v), 0, $max); };
        $out = [];
        foreach (['telegram', 'heroTitle', 'heroLead', 'demoButton', 'contactButton', 'itemsTitle', 'stepsTitle', 'faqTitle', 'finalTitle', 'finalDemoButton', 'finalContactButton', 'footer', 'footerLink', 'demoSlug'] as $k) $out[$k] = $clean($d[$k] ?? '', 600);
        foreach (['items' => ['title', 'text'], 'steps' => ['title', 'text'], 'faq' => ['q', 'a']] as $list => $keys) {
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
        $slug = strtolower(trim((string)($in['slug'] ?? '')));
        $name = trim((string)($in['name'] ?? ''));
        if (!preg_match('/^[a-z0-9-]{2,40}$/', $slug)) fail('Адрес ссылки: латиница, цифры и дефис, от 2 символов');
        if (in_array($slug, ['admin', 'api', 'assets', 'agencies', 'install', 'lib'], true)) fail('Такой адрес занят системой');
        if (agency_by_slug($slug)) fail('Такой адрес уже есть');
        if ($name === '') fail('Укажите название');
        $tpl = json_decode(file_get_contents(APP_ROOT . '/agencies/demo.json'), true);
        $tpl['name'] = mb_substr($name, 0, 80);
        $tpl['city'] = mb_substr(trim((string)($in['city'] ?? '')), 0, 60);
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
        if ((string)($in['confirm'] ?? '') !== $a['slug']) fail('Для удаления введите адрес агентства точно');
        $pdo = db(); $aid = (int)$a['id'];
        $pdo->beginTransaction();
        foreach (['orders', 'blocks', 'users'] as $t) $pdo->prepare("DELETE FROM $t WHERE agency_id = ?")->execute([$aid]);
        $pdo->prepare('DELETE FROM agencies WHERE id = ?')->execute([$aid]);
        $pdo->commit();
        if ((int)($_SESSION['agency'] ?? 0) === $aid) unset($_SESSION['agency']);
        json_out(me());

    case 'agency_user': // создать или сбросить вход для агентства
        need_super();
        $aid = (int)($in['id'] ?? 0);
        if (!agency_by_id($aid)) fail('Агентство не найдено', 404);
        $login = (string)($in['login'] ?? ''); $pass = (string)($in['password'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/', $login)) fail('Логин: латиница и цифры, от 3 символов');
        if (strlen($pass) < 10) fail('Пароль не короче 10 символов');
        $st = db()->prepare('SELECT id, agency_id FROM users WHERE login = ?'); $st->execute([$login]); $u = $st->fetch();
        if ($u && (int)$u['agency_id'] !== $aid) fail('Такой логин уже занят');
        if ($u) db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
        else db()->prepare('INSERT INTO users (agency_id, login, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$aid, $login, password_hash($pass, PASSWORD_DEFAULT), 'agency', date('Y-m-d H:i:s')]);
        json_out(['ok' => true]);
}
fail('Неизвестное действие', 404);
