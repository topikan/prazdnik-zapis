<?php
// API админки. Все действия — POST с JSON и заголовком X-CSRF (кроме входа).
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/booking.php';
require dirname(__DIR__) . '/lib/notify.php';
require dirname(__DIR__) . '/lib/billing.php';
require dirname(__DIR__) . '/lib/backup.php';
require dirname(__DIR__) . '/lib/payments.php';

start_session();
ensure_billing_cols();
$action = (string)($_GET['action'] ?? '');
// картинка приходит строкой base64: до 1,5 МБ файла это около 2 МБ текста
$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? body_json($action === 'image_upload' ? 2200000 : 200000) : [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Нужен POST', 405);

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
    $u['login'] = $login;
    session_login($u);
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
// Сессию отпускаем сразу: иначе пока один запрос ждёт Telegram или почту, все остальные запросы админки стоят в очереди
if (!in_array($action, ['logout', 'password', 'agency_open', 'agency_close', 'agency_delete'], true)) session_write_close();
// «me» только читает и отдаёт токен; остальное требует токен в заголовке
if ($action !== 'me' && !hash_equals($_SESSION['csrf'] ?? '', (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('Сессия устарела, обновите страницу', 403);
$isSuper = $_SESSION['role'] === 'super';

function me(): array {
    $agency = null;
    if (!empty($_SESSION['agency'])) { $a = agency_by_id((int)$_SESSION['agency']); if ($a) $agency = ['id' => (int)$a['id'], 'slug' => $a['slug'], 'name' => $a['cfg']['name'] ?? $a['slug'], 'active' => (bool)$a['active'], 'billing' => billing_state($a), 'price' => billing_price($a), 'payOnline' => owner_pay_ready(), 'kind' => is_solo($a) ? 'solo' : 'agency']; }
    $land = setting_get('landing') ?: [];
    $contact = preg_match('#^https://[^\s<>"]+$#', (string)($land['telegram'] ?? '')) ? $land['telegram'] : 'https://t.me/rodionfrontend';
    return ['role' => $_SESSION['role'], 'agency' => $agency, 'csrf' => $_SESSION['csrf'], 'contact' => $contact, 'price' => (string)($land['pricePaidSum'] ?? '3 000 ₽')];
}
function current_agency(): array {
    if (empty($_SESSION['agency'])) fail('Выберите агентство', 400);
    $a = agency_by_id((int)$_SESSION['agency']);
    if (!$a) fail('Агентство не найдено', 404);
    return $a;
}
// какие картинки страницы записи хранятся одним полем в настройках агентства
const IMG_FIELDS = ['logo' => 'logo', 'hero' => 'heroImage', 'person' => 'heroPerson', 'bimg' => 'bannerImage'];
function need_super(): void { global $isSuper; if (!$isSuper) fail('Нет доступа', 403); }
// Удаляет загруженный файл агентства (только свой и только из папок загрузок)
function remove_upload(int $aid, string $path): void {
    if (preg_match('~^uploads/(logos|heroes|chars|portfolio)/a' . $aid . '-[a-f0-9]+\.(webp|png|jpe?g)$~', $path) || preg_match('~^uploads/videos/a' . $aid . '-[a-f0-9]+\.(mp4|webm)$~', $path)) @unlink(APP_ROOT . '/' . $path);
}
// Владельцу сообщаем о том, что появляется на страницах агентств (свой домен — своя ответственность)
function tell_owner(array $a, string $what): void {
    global $isSuper;
    if ($isSuper || !empty($a['cfg']['demo'])) return;
    notify_owner_throttled('up:' . $a['id'] . ':' . $what, 'Изменения у агентства', '«' . ($a['cfg']['name'] ?? $a['slug']) . "» $what\n\nПосмотреть: " . site_url() . '/' . $a['slug']);
}
function save_cfg(int $aid, array $cfg): void { db()->prepare('UPDATE agencies SET config = ? WHERE id = ?')->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE), $aid]); }
function portfolio_counts(array $cfg): array {
    $p = 0; $v = 0;
    foreach ($cfg['portfolio']['items'] ?? [] as $it) { if (($it['t'] ?? '') === 'photo') $p++; else $v++; }
    return [$p, $v];
}
// Отметка «предоплата получена» у заявки; в старых базах колонка добавляется при первом обращении
function ensure_orders_paid(): void {
    static $done = false; if ($done) return;
    try { db()->query('SELECT paid FROM orders LIMIT 1'); }
    catch (Throwable $e) { db()->exec('ALTER TABLE orders ADD COLUMN paid TINYINT NOT NULL DEFAULT 0'); }
    $done = true;
}
// Откуда заявка: site — с сайта, phone / messenger / repeat / other — внесена вручную в кабинете
const ORDER_SOURCES = ['phone' => 'Телефон', 'messenger' => 'Мессенджер', 'repeat' => 'Постоянный клиент', 'other' => 'Другое'];
function ensure_orders_source(): void {
    static $done = false; if ($done) return;
    try { db()->query('SELECT source FROM orders LIMIT 1'); }
    catch (Throwable $e) { try { db()->exec("ALTER TABLE orders ADD COLUMN source VARCHAR(12) NOT NULL DEFAULT 'site'"); } catch (Throwable $e2) {} }
    $done = true;
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
        if (strlen($new) < 8) fail('Пароль не короче 8 символов');
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
        ensure_orders_paid();
        ensure_pay_tables();
        ensure_orders_source();
        $st = db()->prepare('SELECT id, visit_date, visit_time, span, data, name, phone, address, total, price_lines, status, paid, prepaid, source, created_at FROM orders WHERE agency_id = ? AND visit_date BETWEEN ? AND ? ORDER BY visit_date, visit_time, id');
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

    case 'order_paid':
        $a = current_agency();
        ensure_orders_paid();
        $chk = db()->prepare('SELECT 1 FROM orders WHERE id = ? AND agency_id = ?'); $chk->execute([(int)($in['id'] ?? 0), $a['id']]);
        if (!$chk->fetchColumn()) fail('Заявка не найдена', 404);
        db()->prepare('UPDATE orders SET paid = ? WHERE id = ? AND agency_id = ?')->execute([!empty($in['paid']) ? 1 : 0, (int)($in['id'] ?? 0), $a['id']]);
        json_out(['ok' => true]);

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
        $occ = occupancy((int)$a['id'], $date, $date, $cfg);
        ensure_blocks_grp();
        $st = db()->prepare('SELECT id, visit_time, teams, note, grp FROM blocks WHERE agency_id = ? AND visit_date = ? ORDER BY visit_time, id');
        $st->execute([$a['id'], $date]);
        $blocks = $st->fetchAll();
        ensure_orders_source();
        $st = db()->prepare("SELECT id, visit_time, span, name, address, status, source FROM orders WHERE agency_id = ? AND visit_date = ? AND status IN ('new','confirmed') ORDER BY visit_time");
        $st->execute([$a['id'], $date]);
        $orders = $st->fetchAll();
        $slots = [];
        foreach (slot_times($cfg, $date) as $t) $slots[] = ['time' => $t, 'busy' => (int)($occ[$date][$t] ?? 0), 'total' => cap_at($cfg, $date, $t)];
        json_out(['date' => $date, 'slots' => $slots, 'blocks' => $blocks, 'orders' => $orders, 'capDay' => $cfg['capDays'][$date] ?? []]);

    case 'cap_day_save': // другое число выездов на один день: на весь день и/или с определённого часа
        $a = current_agency(); $cfg = $a['cfg'];
        if (is_solo($a)) fail('У частного исполнителя всегда один выезд');
        $date = valid_date(in_str($in, 'date'));
        $rules = is_array($in['rules'] ?? null) ? $in['rules'] : [];
        $caps = $cfg['capDays'] ?? [];
        $caps[$date] = $rules;
        $c = $cfg; $c['capDays'] = $caps;
        $err = []; $clean = normalize_config($c, $err);
        if ($err) fail(implode('. ', array_slice(array_values(array_unique($err)), 0, 3)));
        $cfg['capDays'] = $clean['capDays'] ?? [];
        // правило, совпадающее с обычным числом на весь день, ничего не меняет — не храним
        if (isset($cfg['capDays'][$date]) && count($cfg['capDays'][$date]) === 1 && $cfg['capDays'][$date][0]['from'] <= ($cfg['hours']['from'] ?? '00:00') && (int)$cfg['capDays'][$date][0]['pairs'] === (int)$cfg['pairs']) unset($cfg['capDays'][$date]);
        if (!$cfg['capDays']) unset($cfg['capDays']);
        save_cfg((int)$a['id'], $cfg);
        json_out(['ok' => true, 'config' => $cfg]);

    case 'order_add': // заявка, внесённая вручную: заказ по телефону, из мессенджера, постоянный клиент
        $a = current_agency(); $cfg = $a['cfg'];
        ensure_orders_paid(); ensure_pay_tables(); ensure_orders_source();
        $date = valid_date(in_str($in, 'date'));
        $time = in_str($in, 'time');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) fail('Укажите время');
        $ch = find_item($cfg['characters'], in_str($in, 'character'));
        $pr = find_item($cfg['programs'], in_str($in, 'program'));
        if (!$ch || !$pr) fail('Выберите, кто приедет, и программу');
        $clip = function (string $k, int $max) use ($in): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', in_str($in, $k))), 0, $max); };
        $name = $clip('name', 60); $address = $clip('address', 200); $comment = $clip('comment', 500);
        if ($name === '') fail('Укажите, кто заказал');
        $pd = preg_replace('/\D/', '', in_str($in, 'phone'));
        if (strlen($pd) === 11 && $pd[0] === '8') $pd = '7' . substr($pd, 1);
        if (strlen($pd) === 10) $pd = '7' . $pd;
        if ($pd !== '' && strlen($pd) !== 11) fail('Проверьте номер телефона или оставьте его пустым');
        $source = in_str($in, 'source', 'phone'); if (!isset(ORDER_SOURCES[$source])) $source = 'other';
        $status = in_str($in, 'status', 'confirmed'); if (!in_array($status, ['new', 'confirmed', 'done'], true)) $status = 'confirmed';
        $kids = max(1, min(50, (int)($in['kids'] ?? 1)));
        $span = order_span($cfg, $pr['id']);
        $start = minutes_of($time);
        // хватает ли выездов на всё время заказа; если нет — спрашиваем, точно ли записать сверх
        $iv = busy_intervals((int)$a['id'], $date, $date)[$date] ?? [];
        $over = '';
        for ($i = 0; $i < $span && $start + $i * 60 < 1440; $i++) {
            $t = hhmm($start + $i * 60);
            if (busy_peak($iv, $start + $i * 60, $start + $i * 60 + 60) >= cap_at($cfg, $date, $t)) { $over = $t; break; }
        }
        if ($over !== '' && empty($in['force'])) json_out(['error' => "В $over все выезды уже заняты. Всё равно записать?", 'busy' => true], 409);
        $o = ['character' => $ch['id'], 'program' => $pr['id'], 'date' => $date, 'time' => $time, 'kids' => $kids, 'addons' => [], 'zone' => ''];
        $lines = [];
        if (is_numeric($in['total'] ?? null) && (string)$in['total'] !== '') $total = max(0, min(10000000, (int)$in['total']));
        else { $p = calc_price($cfg, $o, 999); $total = (int)$p['total']; $lines = $p['lines']; }
        $data = ['character' => $ch['id'], 'program' => $pr['id'], 'kids' => $kids, 'addons' => [], 'zone' => '', 'children' => [], 'comment' => $comment];
        db()->prepare('INSERT INTO orders (agency_id, visit_date, visit_time, span, data, name, phone, address, total, price_lines, status, created_at, source) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$a['id'], $date, $time, $span, json_encode($data, JSON_UNESCAPED_UNICODE), $name, $pd === '' ? '' : '+' . $pd, $address, $total, json_encode($lines, JSON_UNESCAPED_UNICODE), $status, date('Y-m-d H:i:s'), $source]);
        json_out(['ok' => true, 'id' => (int)db()->lastInsertId(), 'total' => $total]);

    case 'block_add':
        $a = current_agency();
        $date = valid_date(in_str($in, 'date'));
        $time = is_scalar($in['time'] ?? null) ? (string)$in['time'] : '';
        $times = slot_times($a['cfg'], $date);
        if (!in_array($time, $times, true)) fail('Неверное время');
        $teams = max(1, min(cap_max($a['cfg']), (int)($in['teams'] ?? 1)));
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
        $c['heroPerson'] = $a['cfg']['heroPerson'] ?? '';
        $c['bannerImage'] = $a['cfg']['bannerImage'] ?? '';
        // фото и видео портфолио добавляются и убираются своими действиями; здесь меняются только «показывать» и заголовок
        $c['portfolio'] = ['enabled' => !empty($c['portfolio']['enabled']), 'title' => $c['portfolio']['title'] ?? '', 'items' => $a['cfg']['portfolio']['items'] ?? []];
        $errors = [];
        $clean = normalize_config($c, $errors);
        if (is_solo($a)) $clean['pairs'] = 1;   // частный исполнитель — всегда один выезд
        if ($errors) fail(implode('. ', array_slice(array_values(array_unique($errors)), 0, 5)));
        $aid = (int)$a['id'];
        // фото составов — только свои файлы этого агентства
        $used = [];
        foreach ($clean['characters'] as &$ch) {
            if (!empty($ch['photo']) && (!preg_match('~^uploads/chars/a' . $aid . '-~', $ch['photo']) || !is_file(APP_ROOT . '/' . $ch['photo']))) unset($ch['photo']);
            if (!empty($ch['photo'])) $used[] = $ch['photo'];
        }
        unset($ch);
        foreach ($clean['programs'] as &$pg) {   // свои картинки программ лежат там же, их тоже не удаляем
            if (!empty($pg['img']) && (!preg_match('~^uploads/chars/a' . $aid . '-~', $pg['img']) || !is_file(APP_ROOT . '/' . $pg['img']))) { unset($pg['img']); if ($pg['icon'] === 'own') $pg['icon'] = 'gift'; }
            if (!empty($pg['img'])) $used[] = $pg['img'];
        }
        unset($pg);
        db()->prepare('UPDATE agencies SET config = ? WHERE id = ?')->execute([json_encode($clean, JSON_UNESCAPED_UNICODE), $aid]);
        foreach (glob(APP_ROOT . '/uploads/chars/a' . $aid . '-*') ?: [] as $f) if (!in_array('uploads/chars/' . basename($f), $used, true)) @unlink($f);
        $oldLink = (string)($a['cfg']['banner']['link'] ?? ''); $newLink = (string)($clean['banner']['link'] ?? '');
        if ($newLink !== '' && $newLink !== $oldLink && !$isSuper && empty($clean['demo'])) notify_owner('Новая ссылка в баннере', '«' . $clean['name'] . "» поставило ссылку в баннере:\n$newLink\n\nСтраница: " . site_url() . '/' . $a['slug']);
        json_out(['ok' => true, 'config' => $clean]);

    // ----- картинки агентства: логотип, фон первого экрана, фото составов -----
    case 'logo_upload': $in['kind'] = 'logo'; // старое название действия
    case 'image_upload':
        $a = current_agency(); $aid = (int)$a['id'];
        if (!empty($GLOBALS['BODY_TOO_BIG'])) fail('Картинка слишком большая, нужна до 1,5 МБ. Уменьшите её или сохраните в JPG.');
        $kind = in_str($in, 'kind');
        $dirs = ['logo' => 'logos', 'hero' => 'heroes', 'person' => 'heroes', 'bimg' => 'heroes', 'char' => 'chars', 'prog' => 'chars', 'port' => 'portfolio', 'poster' => 'portfolio'];
        $maxSide = ['logo' => 2000, 'hero' => 4000, 'person' => 3000, 'bimg' => 3000, 'char' => 3000, 'prog' => 3000, 'port' => 3000, 'poster' => 3000];
        // обложка — только для видео, которое уже есть в портфолио
        $posterOf = in_str($in, 'src'); $posterIdx = null;
        if ($kind === 'poster') {
            foreach ($a['cfg']['portfolio']['items'] ?? [] as $i => $it) if (($it['src'] ?? '') === $posterOf && in_array($it['t'] ?? '', ['video', 'embed'], true)) $posterIdx = $i;
            if ($posterIdx === null) fail('Видео не найдено');
        }
        if ($kind === 'port' && portfolio_counts($a['cfg'])[0] >= PORTFOLIO_MAX_PHOTOS) fail('В портфолио уже ' . PORTFOLIO_MAX_PHOTOS . ' фото. Удалите какое-нибудь, чтобы добавить новое.');
        if (!isset($dirs[$kind])) fail('Неизвестный вид картинки');
        $charId = in_str($in, 'char');
        if (($kind === 'char' || $kind === 'prog') && !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $charId)) fail($kind === 'prog' ? 'Не выбрана программа' : 'Не выбран состав');
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
            $file = "$name.webp"; $ok = imagewebp($img, "$dir/$file", in_array($kind, ['hero', 'port', 'bimg'], true) ? 82 : 88); imagedestroy($img);
        } else {
            $file = $name . ['png' => '.png', 'jpeg' => '.jpg', 'webp' => '.webp'][$m[1]]; $ok = file_put_contents("$dir/$file", $bin) !== false;
        }
        if (!$ok) fail('Не удалось сохранить файл на сервере', 500);
        $path = 'uploads/' . $dirs[$kind] . '/' . $file;
        $cfg = $a['cfg'];
        if ($kind === 'poster') {
            remove_upload($aid, (string)($cfg['portfolio']['items'][$posterIdx]['poster'] ?? ''));
            $cfg['portfolio']['items'][$posterIdx]['poster'] = $path;
            save_cfg($aid, $cfg);
            tell_owner($a, 'загрузило обложку видео в портфолио');
            json_out(['path' => $path, 'portfolio' => $cfg['portfolio']]);
        }
        if ($kind === 'port') {
            $cfg['portfolio'] = is_array($cfg['portfolio'] ?? null) ? $cfg['portfolio'] : ['enabled' => true, 'title' => '', 'items' => []];
            $cfg['portfolio']['items'][] = ['t' => 'photo', 'src' => $path];
            save_cfg($aid, $cfg);
            tell_owner($a, 'загрузило фото в портфолио');
            json_out(['path' => $path, 'portfolio' => $cfg['portfolio']]);
        }
        if ($kind === 'char') {
            // если состав уже сохранён, сразу ставим фото; новый состав получит его при сохранении цен
            foreach ($cfg['characters'] as &$ch) if (($ch['id'] ?? '') === $charId) { remove_upload($aid, (string)($ch['photo'] ?? '')); $ch['photo'] = $path; }
            unset($ch);
        } elseif ($kind === 'prog') {
            // своя картинка программы вместо стандартной иконки
            foreach ($cfg['programs'] as &$pg) if (($pg['id'] ?? '') === $charId) { remove_upload($aid, (string)($pg['img'] ?? '')); $pg['img'] = $path; $pg['icon'] = 'own'; }
            unset($pg);
        } else {
            $field = IMG_FIELDS[$kind];
            remove_upload($aid, (string)($cfg[$field] ?? ''));
            $cfg[$field] = $path;
        }
        db()->prepare('UPDATE agencies SET config = ? WHERE id = ?')->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE), $aid]);
        tell_owner($a, ['logo' => 'загрузило логотип', 'hero' => 'загрузило фон страницы', 'person' => 'загрузило картинку персонажа на первый экран', 'bimg' => 'загрузило картинку баннера', 'prog' => 'загрузило картинку программы'][$kind] ?? 'загрузило фото состава');
        json_out(['path' => $path, 'logo' => $path]);

    // ----- портфолио: видео файлом, ролик с VK или Rutube, удаление -----
    case 'video_upload':
        $a = current_agency(); $aid = (int)$a['id'];
        if (portfolio_counts($a['cfg'])[1] >= PORTFOLIO_MAX_VIDEOS) fail('В портфолио уже ' . PORTFOLIO_MAX_VIDEOS . ' видео. Удалите какое-нибудь, чтобы добавить новое.');
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || is_array($f['error'] ?? null)) fail('Выберите файл видео');
        if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) fail('Файл больше, чем разрешает хостинг (' . ini_get('upload_max_filesize') . '). Нужно до 50 МБ.');
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) fail('Файл не загрузился. Попробуйте ещё раз.');
        if ($f['size'] > VIDEO_MAX_BYTES) fail('Видео больше 50 МБ. Сократите ролик или вставьте ссылку на VK Видео или Rutube.');
        $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 16);
        if (substr($head, 4, 4) === 'ftyp' && substr($head, 8, 2) !== 'qt') $ext = 'mp4';
        elseif (substr($head, 0, 4) === "\x1A\x45\xDF\xA3") $ext = 'webm';
        else fail('Нужно видео MP4 или WebM. Ролики с iPhone (MOV) сначала сохраните как MP4 или загрузите на VK Видео.');
        $dir = APP_ROOT . '/uploads/videos';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) fail('Не удалось сохранить файл на сервере', 500);
        $path = 'uploads/videos/a' . $aid . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], APP_ROOT . '/' . $path)) fail('Не удалось сохранить файл на сервере', 500);
        @chmod(APP_ROOT . '/' . $path, 0644);
        $a = current_agency(); $cfg = $a['cfg']; // заново: пока шла загрузка, настройки могли поменяться
        $cfg['portfolio'] = is_array($cfg['portfolio'] ?? null) ? $cfg['portfolio'] : ['enabled' => true, 'title' => '', 'items' => []];
        $cfg['portfolio']['items'][] = ['t' => 'video', 'src' => $path];
        save_cfg($aid, $cfg);
        tell_owner($a, 'загрузило видео в портфолио');
        json_out(['portfolio' => $cfg['portfolio']]);

    case 'portfolio_embed':
        $a = current_agency(); $aid = (int)$a['id']; $cfg = $a['cfg'];
        if (portfolio_counts($cfg)[1] >= PORTFOLIO_MAX_VIDEOS) fail('В портфолио уже ' . PORTFOLIO_MAX_VIDEOS . ' видео. Удалите какое-нибудь, чтобы добавить новое.');
        $src = embed_from_url(mb_substr(in_str($in, 'url'), 0, 2000));
        if (!$src || !embed_ok($src)) fail('Не похоже на ссылку на ролик VK Видео или Rutube. Скопируйте адрес страницы с роликом или код вставки.');
        foreach ($cfg['portfolio']['items'] ?? [] as $it) if (($it['src'] ?? '') === $src) fail('Этот ролик уже есть в портфолио');
        $cfg['portfolio'] = is_array($cfg['portfolio'] ?? null) ? $cfg['portfolio'] : ['enabled' => true, 'title' => '', 'items' => []];
        $cfg['portfolio']['items'][] = ['t' => 'embed', 'src' => $src, 'provider' => str_contains($src, 'rutube.ru') ? 'rutube' : 'vk'];
        save_cfg($aid, $cfg);
        tell_owner($a, "добавило ролик в портфолио:\n$src");
        json_out(['portfolio' => $cfg['portfolio']]);

    case 'portfolio_remove':
        $a = current_agency(); $aid = (int)$a['id']; $cfg = $a['cfg'];
        $src = in_str($in, 'src'); $items = [];
        foreach ($cfg['portfolio']['items'] ?? [] as $it) { if (($it['src'] ?? '') === $src) { if (($it['t'] ?? '') !== 'embed') remove_upload($aid, $src); remove_upload($aid, (string)($it['poster'] ?? '')); continue; } $items[] = $it; }
        $cfg['portfolio']['items'] = $items;
        save_cfg($aid, $cfg);
        json_out(['portfolio' => $cfg['portfolio']]);

    case 'portfolio_move': // сдвинуть фото или видео в списке
        $a = current_agency(); $aid = (int)$a['id']; $cfg = $a['cfg'];
        $items = $cfg['portfolio']['items'] ?? []; $src = in_str($in, 'src'); $dir = (int)($in['dir'] ?? 0) < 0 ? -1 : 1;
        foreach ($items as $i => $it) if (($it['src'] ?? '') === $src && isset($items[$i + $dir])) { [$items[$i], $items[$i + $dir]] = [$items[$i + $dir], $items[$i]]; break; }
        $cfg['portfolio']['items'] = array_values($items);
        save_cfg($aid, $cfg);
        json_out(['portfolio' => $cfg['portfolio']]);

    case 'portfolio_video': // надписи на обложке видео или убрать обложку
        $a = current_agency(); $aid = (int)$a['id']; $cfg = $a['cfg'];
        $src = in_str($in, 'src'); $found = false;
        foreach ($cfg['portfolio']['items'] ?? [] as $i => $it) if (($it['src'] ?? '') === $src) {
            $found = true;
            foreach (['title' => 60, 'sub' => 100] as $k => $max) if (array_key_exists($k, $in)) {
                $v = mb_substr(trim(in_str($in, $k)), 0, $max);
                if ($v === '') unset($cfg['portfolio']['items'][$i][$k]); else $cfg['portfolio']['items'][$i][$k] = $v;
            }
            if (!empty($in['removePoster'])) { remove_upload($aid, (string)($it['poster'] ?? '')); unset($cfg['portfolio']['items'][$i]['poster']); }
        }
        if (!$found) fail('Видео не найдено');
        save_cfg($aid, $cfg);
        json_out(['portfolio' => $cfg['portfolio']]);

    case 'portfolio_cat': // категория фото или видео в галерее
        $a = current_agency(); $aid = (int)$a['id']; $cfg = $a['cfg'];
        $src = in_str($in, 'src'); $cat = mb_substr(trim(preg_replace('/\s+/u', ' ', in_str($in, 'cat'))), 0, 30);
        $found = false;
        foreach ($cfg['portfolio']['items'] ?? [] as $i => $it) if (($it['src'] ?? '') === $src) {
            if ($cat === '') unset($cfg['portfolio']['items'][$i]['cat']); else $cfg['portfolio']['items'][$i]['cat'] = $cat;
            $found = true;
        }
        if (!$found) fail('Не найдено');
        save_cfg($aid, $cfg);
        json_out(['portfolio' => $cfg['portfolio']]);

    // ----- копии заявок -----
    // ----- оплата сезона агентством через ЮKassa владельца -----
    case 'sub_start':
        if ($isSuper) fail('Оплачивает само агентство из своего кабинета');
        $a = current_agency();
        $cred = owner_cred();
        if (!$cred) fail('Онлайн-оплата пока не подключена. Напишите владельцу сервиса.');
        $b = billing_state($a);
        if (in_array($b['status'] ?? '', ['paid', 'demo'], true) || (string)$a['paid_until'] >= billing_settings()['seasonEnd']) fail('Сезон уже оплачен');
        rate_limit('substart', 10, 3600, 'a' . (int)$a['id']);
        $price = billing_price($a);
        ensure_pay_tables();
        $pp = db()->prepare("SELECT conf_url FROM payments WHERE kind = 'sub' AND agency_id = ? AND status = 'pending' AND amount = ? AND shop = ? AND created_at > ? ORDER BY created_at DESC LIMIT 1");
        $pp->execute([(int)$a['id'], $price['amount'], $cred['shop'], date('Y-m-d H:i:s', time() - 1800)]);
        if ($url = $pp->fetchColumn()) json_out(['url' => $url]);
        $desc = 'Аниматорская Система: доступ на сезон до ' . ru_date(billing_settings()['seasonEnd']) . ', ' . $a['slug'];
        $r = yk_create('sub', $cred, (int)$a['id'], 0, $price['amount'], $desc, site_url() . '/admin/?paid=1',
            $cred['receipt'] ? receipt_for($desc, $price['amount'], '', (string)($a['email'] ?? ''), 'full_payment') : null);
        if (isset($r['error'])) fail($r['error'], 502);
        json_out(['url' => $r['url']]);
    case 'sub_check':
        $a = current_agency();
        ensure_pay_tables();
        $pp = db()->prepare("SELECT id FROM payments WHERE kind = 'sub' AND agency_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 3");
        $pp->execute([(int)$a['id']]);
        $res = 'none';
        foreach ($pp->fetchAll(PDO::FETCH_COLUMN) as $pid) { $r = yk_apply((string)$pid); if ($r === 'succeeded') { $res = $r; break; } if ($res === 'none') $res = $r; }
        $a = agency_by_id((int)$a['id']);
        json_out(['status' => $res, 'billing' => billing_state($a)]);

    // ----- ключи ЮKassa агентства для предоплаты клиентов -----
    case 'pay_settings_get':
        $a = current_agency();
        json_out(agency_cred_public((int)$a['id']) + ['webhook' => site_url() . '/api/yookassa.php']);
    case 'pay_settings_save':
        $a = current_agency();
        rate_limit('ykkeys', 10, 3600, 'a' . (int)$a['id']);
        $shop = trim(in_str($in, 'shop'));
        $sec = trim(in_str($in, 'secret'));
        $old = agency_cred((int)$a['id']);
        if (!shop_id_ok($shop)) fail('Номер магазина (shopId) — только цифры, его видно в личном кабинете ЮKassa');
        if ($sec === '' && $old && $old['shop'] === $shop) $sec = $old['secret'];  // ключ не меняли
        if (!secret_key_ok($sec)) fail('Секретный ключ начинается с live_ или test_. Скопируйте его из раздела «Интеграция — Ключи API» в ЮKassa');
        $chk = yk_check_cred(['shop' => $shop, 'secret' => $sec]);
        if (!$chk['ok']) fail($chk['error']);
        setting_set('yk:' . (int)$a['id'], ['shop' => $shop, 'sec' => secret_encrypt($sec), 'tail' => substr($sec, -4), 'test' => !empty($chk['test']) || str_starts_with($sec, 'test_'), 'receipt' => !empty($in['receipt'])]);
        tell_owner($a, 'подключило онлайн-предоплату через ЮKassa (магазин ' . $shop . ')');
        json_out(agency_cred_public((int)$a['id']) + ['webhook' => site_url() . '/api/yookassa.php']);
    case 'pay_settings_off':
        $a = current_agency();
        db()->prepare('DELETE FROM settings WHERE name = ?')->execute(['yk:' . (int)$a['id']]);
        json_out(['ok' => true]);

    case 'orders_export':
        $a = current_agency();
        json_out(['filename' => 'zayavki-' . safe_file_part($a['slug']) . '-' . date('Y-m-d') . '.csv', 'csv' => orders_csv($a)]);

    case 'backup_info':
        $a = current_agency();
        json_out(['zip' => zip_password_ok(), 'tgChats' => count(tg_chats((int)$a['id'])), 'emails' => count(notify_emails($a['cfg']))]);

    case 'backup_test': // отправить копию прямо сейчас, чтобы проверить
        $a = current_agency();
        rate_limit('bktest', 4, 3600);
        if (!zip_password_ok()) fail('Сервер не умеет делать архивы с паролем. Напишите владельцу сервиса.');
        $r = backup_agency($a);
        if ($r['skipped'] === 'off') fail('Включите отправку в Telegram или на почту и сохраните настройки');
        if ($r['skipped'] === 'empty') { if (!$r['tg'] && !$r['email']) fail('Заявок пока нет, и отправить сообщение не удалось: проверьте, что ваш Telegram подключён'); json_out($r + ['empty' => true]); }
        if ($r['skipped'] === 'zip') fail('Не удалось собрать архив. Проверьте, что пароль не короче 6 символов, и сохраните настройки.');
        json_out($r);

    case 'logo_remove': $in['kind'] = 'logo';
    case 'image_remove':
        $a = current_agency(); $aid = (int)$a['id']; $cfg = $a['cfg'];
        $kind = in_str($in, 'kind');
        if ($kind === 'char') {
            $charId = in_str($in, 'char');
            foreach ($cfg['characters'] as &$ch) if (($ch['id'] ?? '') === $charId) { remove_upload($aid, (string)($ch['photo'] ?? '')); unset($ch['photo']); }
            unset($ch);
        } elseif ($kind === 'prog') {
            $charId = in_str($in, 'char');
            foreach ($cfg['programs'] as &$pg) if (($pg['id'] ?? '') === $charId) { remove_upload($aid, (string)($pg['img'] ?? '')); unset($pg['img']); if (($pg['icon'] ?? '') === 'own') $pg['icon'] = 'gift'; }
            unset($pg);
        } elseif (isset(IMG_FIELDS[$kind])) {
            $field = IMG_FIELDS[$kind];
            remove_upload($aid, (string)($cfg[$field] ?? ''));
            unset($cfg[$field]);
        } else fail('Неизвестный вид картинки');
        db()->prepare('UPDATE agencies SET config = ? WHERE id = ?')->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE), $aid]);
        json_out(['ok' => true]);

    // ----- уведомления -----
    case 'notify_get':
        $a = current_agency();
        // спрашиваем Telegram только когда ждём подключения по свежей ссылке — иначе вкладка открывается мгновенно
        if (tg_ready() && tg_link_pending()) { try { tg_poll(); } catch (Throwable $e) { error_log('tg_poll: ' . $e->getMessage()); } }
        else tg_poll_later();   // остальные сообщения боту — после ответа, вкладка открывается без задержки
        json_out(['tgReady' => tg_ready(), 'tgChats' => count(tg_chats((int)$a['id']))]);

    case 'tg_link':
        $a = current_agency();
        if (!tg_ready()) fail('Уведомления в Telegram пока не настроены владельцем сервиса');
        $bot = tg_bot(true); // при подключении всегда спрашиваем Telegram: бот мог смениться
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
        $l = setting_get('landing');
        json_out(['landing' => $l ? rebrand($l) : null]);

    case 'site_save':
        need_super();
        $d = $in['landing'] ?? null;
        if (!is_array($d) || trim((string)($d['heroTitle'] ?? '')) === '') fail('Заполните заголовок');
        if (!empty($d['telegram']) && !preg_match('#^https://[^\s<>"]+$#', (string)$d['telegram'])) fail('Ссылка на Telegram должна начинаться с https://');
        if (!empty($d['demoSlug']) && !preg_match('/^[a-z0-9-]{2,40}$/', (string)$d['demoSlug'])) fail('Адрес демо: латиница, цифры и дефис');
        $clean = function ($v, $max) { return mb_substr(trim((string)$v), 0, $max); };
        $out = [];
        foreach (['telegram', 'heroBadge', 'heroTitle', 'heroAccent', 'regButton', 'ctaNote', 'demoButton', 'contactButton', 'loginLink', 'stepsTitle',
            'priceTitle', 'priceFreeTitle', 'priceFreeText', 'pricePaidTitle', 'pricePaidSum', 'pricePaidText', 'buyButton', 'priceNote',
            'faqTitle', 'finalTitle', 'finalText', 'finalDemoButton', 'finalContactButton', 'footer', 'footerTagline', 'demoSlug'] as $k) $out[$k] = $clean($d[$k] ?? '', 600);
        $out['v'] = 6;
        foreach (['points' => ['title'], 'steps' => ['title', 'text'], 'faq' => ['q', 'a']] as $list => $keys) {
            $out[$list] = [];
            foreach (array_slice((array)($d[$list] ?? []), 0, 20) as $row) { $r = []; foreach ($keys as $k) $r[$k] = $clean($row[$k] ?? '', 1000); if (implode('', $r) !== '') $out[$list][] = $r; }
        }
        setting_set('landing', $out);
        json_out(['ok' => true]);

    // ----- только владелец сервиса -----
    case 'agencies':
        need_super();
        $rows = [];
        foreach (db()->query('SELECT a.id, a.slug, a.config, a.active, a.created_at, a.paid_until, a.trial_until, a.email, (SELECT COUNT(*) FROM orders o WHERE o.agency_id = a.id) AS orders_count, (SELECT login FROM users u WHERE u.agency_id = a.id LIMIT 1) AS login FROM agencies a ORDER BY a.id DESC') as $r) {
            $cfg = json_decode($r['config'], true) ?: [];
            $r['cfg'] = $cfg;
            $rows[] = ['billing' => billing_state($r), 'phone' => $cfg['phone'] ?? '', 'email' => $r['email'], 'id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $cfg['name'] ?? $r['slug'], 'city' => $cfg['city'] ?? '', 'demo' => !empty($cfg['demo']), 'kind' => $r['kind'] ?? 'agency', 'legal' => trim((string)($cfg['legal']['operator'] ?? '')) !== '' && trim((string)($cfg['legal']['inn'] ?? '')) !== '', 'active' => (bool)$r['active'], 'orders' => (int)$r['orders_count'], 'login' => $r['login'], 'created' => $r['created_at']];
        }
        json_out(['agencies' => $rows]);

    case 'agency_create':
        need_super();
        $slug = strtolower(trim(in_str($in, 'slug')));
        $name = trim(in_str($in, 'name'));
        if (($p = slug_problem($slug)) !== '') fail($p);
        if ($name === '') fail('Укажите название');
        $id = create_agency($slug, $name, trim(in_str($in, 'city')), '', '', true, trial_for_new());
        json_out(['ok' => true, 'id' => $id]);

    case 'agency_paid': // отметить оплату: до какой даты работает страница (пусто — снять отметку)
        need_super();
        $a = agency_by_id((int)($in['id'] ?? 0));
        if (!$a) fail('Агентство не найдено', 404);
        $until = in_str($in, 'until');
        if ($until !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || !checkdate((int)substr($until, 5, 2), (int)substr($until, 8, 2), (int)substr($until, 0, 4)))) fail('Неверная дата');
        db()->prepare('UPDATE agencies SET paid_until = ? WHERE id = ?')->execute([$until, (int)$a['id']]);
        json_out(['ok' => true]);

    // ----- настройки владельца: регистрация, свой Telegram, копии всех заявок -----
    case 'owner_get':
        need_super();
        $last = setting_get('cronlast') ?: [];
        json_out(['reg' => (setting_get('reg') ?: ['open' => true, 'manual' => false]), 'backup' => (setting_get('ownerbackup') ?: ['tg' => false, 'email' => false, 'password' => '']),
            'email' => owner_email(), 'tgReady' => tg_ready(), 'tgChats' => count(tg_chats(0)), 'zip' => zip_password_ok(), 'billing' => billing_settings(), 'payOnline' => owner_pay_ready(),
            'cronUrl' => site_url() . '/api/cron.php?key=' . cron_key(), 'cronLast' => ($last['day'] ?? '') ? ru_date($last['day']) . ' в ' . ($last['at'] ?? '') . cron_result($last['report'] ?? null) : '',
            'uploadMax' => ini_get('upload_max_filesize'), 'postMax' => ini_get('post_max_size'),
            'mailMethod' => empty($APP['smtp_host']) ? 'mail' : 'smtp', 'mailFrom' => mail_from()]);

    case 'owner_save':
        need_super();
        $email = trim(in_str($in, 'email'));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Проверьте почту владельца');
        $bk = is_array($in['backup'] ?? null) ? $in['backup'] : [];
        $pw = is_scalar($bk['password'] ?? null) ? mb_substr((string)$bk['password'], 0, 100) : '';
        if ((!empty($bk['tg']) || !empty($bk['email'])) && strlen($pw) < 6) fail('Пароль для архива — не короче 6 символов');
        $bl = is_array($in['billing'] ?? null) ? $in['billing'] : [];
        foreach (['freeEnd', 'seasonEnd'] as $k) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($bl[$k] ?? ''))) fail('Укажите даты бесплатного периода и конца сезона');
        setting_set('owner', ['email' => $email]);
        setting_set('reg', ['open' => !empty($in['reg']['open']), 'manual' => !empty($in['reg']['manual'])]);
        setting_set('ownerbackup', ['tg' => !empty($bk['tg']), 'email' => !empty($bk['email']), 'password' => $pw]);
        $nums = [];
        foreach (['priceEarly' => [100, 1000000], 'priceFull' => [100, 1000000], 'earlyHours' => [1, 720], 'soloEarly' => [100, 1000000], 'soloFull' => [100, 1000000]] as $k => $r) {
            $v = $bl[$k] ?? billing_settings()[$k];   // не прислали — оставляем как было
            if (!is_numeric($v) || (int)$v < $r[0] || (int)$v > $r[1]) fail('Проверьте цены и срок скидки');
            $nums[$k] = (int)$v;
        }
        $oldEnd = billing_settings()['seasonEnd'];
        setting_set('billing', ['freeEnd' => $bl['freeEnd'], 'seasonEnd' => $bl['seasonEnd']] + $nums);
        // сезон продлили: кто оплатил до старого конца сезона, тот получает доступ до нового
        if ($bl['seasonEnd'] > $oldEnd) db()->prepare('UPDATE agencies SET paid_until = ? WHERE paid_until = ?')->execute([$bl['seasonEnd'], $oldEnd]);
        json_out(['ok' => true]);

    case 'mail_test': // проверить отправку писем: отправляет тестовое письмо и показывает точную ошибку
        need_super();
        rate_limit('mtest', 10, 600);
        $to = trim(in_str($in, 'to'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) fail('Укажите почту, на которую отправить проверку');
        $ok = mail_text($to, 'Проверка почты — Аниматорская Система', "Это проверочное письмо с сайта " . site_url() . ".\n\nЕсли вы его видите, коды регистрации и уведомления тоже доходят.");
        json_out(['ok' => $ok, 'error' => $ok ? '' : mail_error(), 'method' => empty($APP['smtp_host']) ? 'mail' : 'smtp', 'from' => mail_from()]);

    case 'tg_link_owner':
        need_super();
        if (!tg_ready()) fail('Telegram не настроен в config.local.php');
        $bot = tg_bot(true); // при подключении всегда спрашиваем Telegram: бот мог смениться
        if (!$bot) fail('Не удалось связаться с Telegram. Попробуйте через минуту.', 502);
        $code = bin2hex(random_bytes(8));
        setting_set('tglink:' . $code, ['aid' => 0, 'exp' => time() + 3600]);
        json_out(['url' => 'https://t.me/' . $bot['username'] . '?start=' . $code]);

    case 'owner_poll': // проверить, нажал ли владелец «Старт» в боте
        need_super();
        if (tg_ready() && tg_link_pending()) { try { tg_poll(); } catch (Throwable $e) { error_log('tg_poll: ' . $e->getMessage()); } }
        json_out(['tgChats' => count(tg_chats(0))]);

    case 'owner_tg_off':
        need_super();
        tg_set_chats(0, []);
        json_out(['ok' => true]);

    case 'owner_backup_test':
        need_super();
        rate_limit('bktest', 4, 3600);
        $r = backup_owner();
        if ($r['skipped'] === 'off') fail('Включите отправку и сохраните настройки');
        if ($r['skipped'] === 'empty') { if (!$r['tg'] && !$r['email']) fail('Заявок пока нет, и отправить сообщение не удалось: проверьте, что ваш Telegram подключён'); json_out($r + ['empty' => true]); }
        if ($r['skipped'] === 'zip') fail('Не удалось собрать архив с паролем на этом сервере');
        json_out($r);

    case 'agency_open': // зайти в агентство как владелец сервиса
        need_super();
        $a = agency_by_id((int)($in['id'] ?? 0));
        if (!$a) fail('Агентство не найдено', 404);
        $_SESSION['agency'] = (int)$a['id'];
        json_out(me());

    case 'agency_kind': // владелец меняет тип: агентство или частный исполнитель
        need_super();
        $a = agency_by_id((int)($in['id'] ?? 0));
        if (!$a) fail('Агентство не найдено', 404);
        $kind = in_str($in, 'kind') === 'solo' ? 'solo' : 'agency';
        db()->prepare('UPDATE agencies SET kind = ? WHERE id = ?')->execute([$kind, (int)$a['id']]);
        if ($kind === 'solo') { $cfg = $a['cfg']; $cfg['pairs'] = 1; save_cfg((int)$a['id'], $cfg); }
        json_out(['ok' => true]);

    case 'agency_close': // выйти из агентства обратно в кабинет владельца
        need_super();
        unset($_SESSION['agency']);
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
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute(["yk:$aid"]);   // ключи ЮKassa агентства
        $pdo->commit();
        foreach (['logos', 'heroes', 'chars', 'portfolio', 'videos'] as $d) foreach (glob(APP_ROOT . "/uploads/$d/a$aid-*") ?: [] as $f) @unlink($f);
        if ((int)($_SESSION['agency'] ?? 0) === $aid) unset($_SESSION['agency']);
        json_out(me());

    case 'agency_user': // создать или сбросить вход для агентства
        need_super();
        $aid = (int)($in['id'] ?? 0);
        if (!agency_by_id($aid)) fail('Агентство не найдено', 404);
        $login = in_str($in, 'login'); $pass = in_str($in, 'password');
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/', $login)) fail('Логин: латиница и цифры, от 3 символов');
        if (strlen($pass) < 8) fail('Пароль не короче 8 символов');
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
