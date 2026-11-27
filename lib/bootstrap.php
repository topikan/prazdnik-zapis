<?php
// Общая загрузка: настройки, база, сессии, ответы в JSON.
declare(strict_types=1);

if (!defined('APP_ROOT')) define('APP_ROOT', dirname(__DIR__));

$cfgFile = APP_ROOT . '/config.local.php';
if (!is_file($cfgFile)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Сервис ещё не настроен']);
    exit;
}
$APP = require $cfgFile;
date_default_timezone_set($APP['timezone'] ?? 'Europe/Moscow');

// Ошибки не показываем посетителю (там пути и детали сервера), только пишем в журнал
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_error_handler(function (int $no, string $msg, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $no)) return false;
    error_log("PHP: $msg в $file:$line"); // предупреждение пишем в журнал, а не в ответ (иначе ломается JSON)
    return true;
});
set_exception_handler(function (Throwable $e): void {
    error_log('Необработанная ошибка: ' . get_class($e) . ': ' . $e->getMessage() . ' в ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); }
    echo json_encode(['error' => 'Что-то пошло не так. Попробуйте ещё раз через минуту.'], JSON_UNESCAPED_UNICODE);
});

function db(): PDO {
    static $pdo = null;
    global $APP;
    if ($pdo) return $pdo;
    $pdo = new PDO($APP['db_dsn'], $APP['db_user'] ?? null, $APP['db_pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if (is_mysql()) $pdo->exec("SET NAMES utf8mb4");
    else $pdo->exec('PRAGMA busy_timeout = 5000'); // SQLite (тесты): одновременные заявки ждут друг друга, а не падают
    return $pdo;
}
function is_mysql(): bool { global $APP; return str_starts_with($APP['db_dsn'], 'mysql:'); }

// Дела «после ответа» (уведомления владельцу и т.п.): пользователь не ждёт, пока сообщение дойдёт через Google
function after_response(callable $f): void {
    static $reg = false;
    $GLOBALS['AFTER_RESPONSE'][] = $f;
    if (!$reg) { $reg = true; register_shutdown_function('run_after_response'); }
}
function run_after_response(): void {
    while (!empty($GLOBALS['AFTER_RESPONSE'])) {
        $f = array_shift($GLOBALS['AFTER_RESPONSE']);
        try { $f(); } catch (Throwable $e) { error_log('after_response: ' . $e->getMessage()); }
    }
}
function json_out($data, int $code = 200): void {
    if (!empty($GLOBALS['AFTER_RESPONSE']) && $code < 400 && PHP_SAPI !== 'cli') { http_response_code($code); respond_early($data); run_after_response(); exit; }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code = 400): void { json_out(['error' => $msg], $code); }

// Отдать ответ браузеру сразу и продолжить работу (уведомления) уже без него.
// На хостинге не всегда есть fastcgi_finish_request: тогда закрываем соединение сами — через
// Content-Length и Connection: close, иначе клиент ждал бы, пока уйдут Telegram и почта (20–30 секунд).
function respond_early(array $data): void {
    $body = json_encode($data, JSON_UNESCAPED_UNICODE);
    ignore_user_abort(true);
    @set_time_limit(120);
    while (ob_get_level() > 0) @ob_end_clean();
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Encoding: identity');        // иначе сжатие сервера может держать ответ до конца скрипта
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;
    @flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
}

// Старое название и адрес в сохранённых текстах (до переименования) заменяем на новые
function rebrand(array $d): array {
    array_walk_recursive($d, function (&$v) { if (is_string($v)) $v = str_replace(['Бронь Праздник', 'bronprazdnik.ru'], ['Аниматорская Система', 'anisis.ru'], $v); });
    return $d;
}

// Тело запроса JSON. Обычные запросы — до 200 КБ; загрузка картинок разрешает больше.
// Если тело длиннее лимита, оно обрезается и не читается — $GLOBALS['BODY_TOO_BIG'] это отмечает.
function body_json(int $max = 200000): array {
    $raw = (string)file_get_contents('php://input', false, null, 0, $max + 1);
    $GLOBALS['BODY_TOO_BIG'] = strlen($raw) > $max;
    if ($GLOBALS['BODY_TOO_BIG']) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

// Настоящий адрес посетителя. На хостинге перед сайтом стоит прокси, и REMOTE_ADDR у всех может быть
// адресом прокси — тогда лимиты срабатывали бы на всех посетителей сразу. Заголовкам прокси верим,
// только если запрос пришёл с внутреннего адреса (подделать их снаружи тогда нельзя).
// Строковое поле запроса: массив или объект вместо строки считается пустым значением
function in_str(array $in, string $k, string $def = ''): string { return isset($in[$k]) && is_scalar($in[$k]) ? (string)$in[$k] : $def; }

function client_ip(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0');
    $internal = !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($internal) {
        $real = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($real === '' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) { $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']); $real = trim((string)end($parts)); }
        if (filter_var($real, FILTER_VALIDATE_IP)) return $real;
    }
    return $ip;
}
function client_key(string $extra = ''): string {
    global $APP;
    return hash_hmac('sha256', $extra !== '' ? $extra : client_ip(), $APP['secret']);
}
function is_local_host(): bool { return (bool)preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', (string)($_SERVER['HTTP_HOST'] ?? '')); }

// Счётчик неудач (например, неверных паролей): проверить, не превышен ли лимит, и записать новую неудачу
function fails_exceeded(string $kind, int $max, int $seconds, string $key = ''): bool {
    $st = db()->prepare('SELECT COUNT(*) FROM hits WHERE kind = ? AND key_hash = ? AND created_at > ?');
    $st->execute([$kind, client_key($key), date('Y-m-d H:i:s', time() - $seconds)]);
    return (int)$st->fetchColumn() >= $max;
}
function fail_hit(string $kind, string $key = ''): void {
    db()->prepare('INSERT INTO hits (kind, key_hash, created_at) VALUES (?, ?, ?)')->execute([$kind, client_key($key), date('Y-m-d H:i:s')]);
}

// Ограничение частоты: не больше $max событий вида $kind за $seconds с одного адреса (или по ключу $key)
function rate_limit(string $kind, int $max, int $seconds, string $key = ''): void {
    $pdo = db();
    $since = date('Y-m-d H:i:s', time() - $seconds);
    $k = client_key($key);
    $st = $pdo->prepare('SELECT COUNT(*) FROM hits WHERE kind = ? AND key_hash = ? AND created_at > ?');
    $st->execute([$kind, $k, $since]);
    if ((int)$st->fetchColumn() >= $max) fail('Слишком много попыток. Попробуйте через несколько минут.', 429);
    $pdo->prepare('INSERT INTO hits (kind, key_hash, created_at) VALUES (?, ?, ?)')->execute([$kind, $k, date('Y-m-d H:i:s')]);
    if (random_int(1, 50) === 1) $pdo->prepare('DELETE FROM hits WHERE created_at < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
}

function agency_by_slug(string $slug): ?array {
    if (!preg_match('/^[a-z0-9-]{2,40}$/', $slug)) return null;
    $st = db()->prepare('SELECT * FROM agencies WHERE slug = ?');
    $st->execute([$slug]);
    $a = $st->fetch();
    if (!$a) return null;
    $a['cfg'] = json_decode($a['config'], true) ?: [];
    return $a;
}
function agency_by_id(int $id): ?array {
    $st = db()->prepare('SELECT * FROM agencies WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) return null;
    $a['cfg'] = json_decode($a['config'], true) ?: [];
    return $a;
}

const SESSION_DAYS = 14;
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    // Свои файлы сессий в закрытой папке: на общем хостинге чужая очистка не выкидывает из кабинета каждые 24 минуты
    $dir = APP_ROOT . '/data/sessions';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    ini_set('session.gc_maxlifetime', (string)(SESSION_DAYS * 86400));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.sid_length', '48');
    session_name('bp_admin');
    session_set_cookie_params([
        // Secure всегда, кроме локальной проверки: хостинг за прокси не сообщает PHP, что соединение https
        'lifetime' => SESSION_DAYS * 86400, 'path' => '/', 'secure' => !is_local_host(),
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_start();
}
// Отпечаток пароля: после смены пароля или сброса входа старые сессии перестают работать
function pass_fp(string $hash): string { global $APP; return substr(hash_hmac('sha256', $hash, $APP['secret']), 0, 32); }

// Настройки сервиса (тексты главной и т. п.): таблица создаётся при первом обращении
function ensure_settings(): void {
    static $done = false; if ($done) return;
    $text = is_mysql() ? 'MEDIUMTEXT' : 'TEXT';
    $tail = is_mysql() ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    db()->exec("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(40) NOT NULL PRIMARY KEY, value $text NOT NULL)$tail");
    $done = true;
}
function setting_get(string $name): ?array {
    ensure_settings();
    $st = db()->prepare('SELECT value FROM settings WHERE name = ?'); $st->execute([$name]);
    $v = $st->fetchColumn();
    return $v === false ? null : (json_decode($v, true) ?: null);
}
function setting_set(string $name, array $value): void {
    ensure_settings();
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    db()->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
    db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?)')->execute([$name, $json]);
}

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
// Открыть сессию кабинета для пользователя (после входа или регистрации)
function session_login(array $u): void {
    start_session();
    remember_device((string)$u['login']);
    session_regenerate_id(true);
    $_SESSION = ['uid' => (int)$u['id'], 'role' => $u['role'], 'agency' => $u['agency_id'] ? (int)$u['agency_id'] : null,
        'csrf' => bin2hex(random_bytes(24)), 'fp' => pass_fp($u['password_hash']), 'seen' => time()];
}
// Кто открыл страницу: только если у посетителя уже есть кука кабинета (новую сессию не заводим)
function session_peek(): array {
    if (empty($_COOKIE['bp_admin']) || !preg_match('/^[A-Za-z0-9,-]{20,128}$/', (string)$_COOKIE['bp_admin'])) return [];
    start_session();
    $s = $_SESSION;
    session_write_close();
    if (empty($s['uid'])) return [];
    $st = db()->prepare('SELECT role, agency_id, password_hash FROM users WHERE id = ?'); $st->execute([(int)$s['uid']]);
    $u = $st->fetch();
    if (!$u || $u['role'] !== ($s['role'] ?? '') || !hash_equals(pass_fp($u['password_hash']), (string)($s['fp'] ?? ''))) return [];
    return ['role' => $u['role'], 'agency' => $u['role'] === 'super' ? (int)($s['agency'] ?? 0) : (int)$u['agency_id']];
}
// ---------- почта ----------
// Если в config.local.php заданы smtp_*, письма идут через настоящий почтовый ящик на домене (надёжно доходят до Gmail).
// Иначе — через mail() хостинга. Последняя ошибка отправки хранится для кнопки «Проверить почту» в админке.
function mail_from(): string {
    global $APP;
    if (!empty($APP['smtp_user']) && filter_var($APP['smtp_user'], FILTER_VALIDATE_EMAIL)) return (string)$APP['smtp_user'];
    $host = parse_url((string)($APP['site_url'] ?? 'https://anisis.ru'), PHP_URL_HOST) ?: 'anisis.ru';
    return "noreply@$host";
}
function mail_error(?string $e = null): string { static $last = ''; if ($e !== null) $last = $e; return $last; }
function mail_send(string $to, string $subject, string $contentHeaders, string $body): bool {
    global $APP;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) { mail_error('Неверный адрес'); return false; }
    $from = mail_from();
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $fromHdr = '=?UTF-8?B?' . base64_encode('Аниматорская Система') . "?= <$from>";
    if (!empty($APP['smtp_host'])) return smtp_send($to, $subj, $fromHdr, $from, $contentHeaders, $body);
    $ok = @mail($to, $subj, $body, "From: $fromHdr\r\nMIME-Version: 1.0\r\n" . $contentHeaders, "-f$from");
    mail_error($ok ? '' : 'Хостинг не принял письмо (функция mail вернула ошибку)');
    return $ok;
}
// Простой SMTP-клиент: SSL на 465 или STARTTLS на 587, вход по логину и паролю ящика
function smtp_send(string $to, string $subj, string $fromHdr, string $from, string $contentHeaders, string $body): bool {
    global $APP;
    $host = (string)$APP['smtp_host']; $port = (int)($APP['smtp_port'] ?? 465);
    $err = '';
    $fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . "$host:$port", $no, $str, 15, STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]));
    if (!$fp) { mail_error("Нет соединения с почтовым сервером $host:$port ($str)"); return false; }
    stream_set_timeout($fp, 20);
    $read = function () use ($fp) { $r = ''; while (($l = fgets($fp, 1024)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
    $cmd = function (string $c, array $okCodes) use ($fp, $read, &$err) { if ($c !== '') fwrite($fp, $c . "\r\n"); $r = $read(); if (!in_array((int)substr($r, 0, 3), $okCodes, true)) { $err = trim($r) ?: 'нет ответа'; return false; } return true; };
    $ehlo = 'EHLO ' . (parse_url((string)($APP['site_url'] ?? ''), PHP_URL_HOST) ?: 'localhost');
    $ok = $cmd('', [220]) && $cmd($ehlo, [250]);
    if ($ok && $port !== 465 && ($APP['smtp_secure'] ?? '') !== 'none') { // 'none' — только для проверки на своём компьютере
        $ok = $cmd('STARTTLS', [220]) && @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) && $cmd($ehlo, [250]);
        if (!$ok && $err === '') $err = 'не удалось включить шифрование';
    }
    $ok = $ok && $cmd('AUTH LOGIN', [334]) && $cmd(base64_encode((string)$APP['smtp_user']), [334]) && $cmd(base64_encode((string)($APP['smtp_pass'] ?? '')), [235]);
    if (!$ok && stripos($err, '535') !== false) $err = 'Неверный логин или пароль почтового ящика (' . $err . ')';
    $ok = $ok && $cmd("MAIL FROM:<$from>", [250]) && $cmd("RCPT TO:<$to>", [250, 251]) && $cmd('DATA', [354]);
    if ($ok) {
        $msg = "Date: " . date('r') . "\r\nFrom: $fromHdr\r\nTo: <$to>\r\nSubject: $subj\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . '@' . substr(strrchr($from, '@'), 1) . ">\r\nMIME-Version: 1.0\r\n"
            . $contentHeaders . "\r\n\r\n" . $body;
        $msg = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $msg)); // точка в начале строки удваивается
        $ok = $cmd($msg . "\r\n.", [250]);
    }
    @fwrite($fp, "QUIT\r\n"); fclose($fp);
    mail_error($ok ? '' : 'Почтовый сервер ответил: ' . $err);
    return $ok;
}
// Оформленное письмо: тёмная шапка с логотипом, белая карточка, подпись. Вёрстка таблицами и встроенными стилями —
// так письмо одинаково выглядит в Gmail, Яндексе, Mail.ru и Outlook.
function mail_html(string $innerHtml, string $preheader = ''): string {
    global $APP;
    $site = rtrim((string)($APP['site_url'] ?? 'https://anisis.ru'), '/');
    $host = parse_url($site, PHP_URL_HOST) ?: 'anisis.ru';
    $f = "font-family:Arial,Helvetica,sans-serif;";
    return '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Аниматорская Система</title></head>'
        . '<body style="margin:0;padding:0;background:#F4EFE7;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#F4EFE7;">' . htmlspecialchars($preheader) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4EFE7;"><tr><td align="center" style="padding:28px 12px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border-radius:16px;overflow:hidden;">'
        . '<tr><td style="background:#0E1A33;padding:20px 28px;"><table role="presentation" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="padding-right:12px;"><img src="' . $site . '/assets/img/mail-logo-anisis.png" width="40" height="40" alt="" style="display:block;border-radius:10px;"></td>'
        . '<td style="' . $f . 'font-size:19px;font-weight:bold;color:#FFFFFF;">Аниматорская Система</td></tr></table></td></tr>'
        . '<tr><td style="' . $f . 'padding:30px 28px 26px;font-size:16px;line-height:1.55;color:#14213D;">' . $innerHtml . '</td></tr>'
        . '<tr><td style="' . $f . 'padding:18px 28px;border-top:1px solid #EFE8DD;font-size:12px;line-height:1.5;color:#7A8293;">Письмо отправлено автоматически, отвечать на него не нужно.<br>'
        . '<a href="' . $site . '" style="color:#7A8293;">' . htmlspecialchars($host) . '</a></td></tr>'
        . '</table></td></tr></table></body></html>';
}
// Простой текст → оформленное письмо: абзацы, переносы и ссылки сохраняются
function mail_html_from_text(string $text): string {
    $parts = preg_split("/\n{2,}/", trim($text));
    $html = '';
    foreach ($parts as $n => $p) {
        $e = nl2br(htmlspecialchars($p), false);
        $e = preg_replace('~(https?://[^\s<]+)~', '<a href="$1" style="color:#B3203A;">$1</a>', $e);
        // первый абзац — заголовок письма
        $html .= $n === 0 && !str_contains($p, "\n") ? '<p style="margin:0 0 16px;font-size:20px;font-weight:bold;line-height:1.3;">' . $e . '</p>' : '<p style="margin:0 0 14px;">' . $e . '</p>';
    }
    return mail_html($html, mb_substr(strtok(trim($text), "\n"), 0, 90));
}
// Письмо: текстовая версия + оформленная (почтовая программа показывает оформленную, если умеет)
function mail_text(string $to, string $subject, string $text, ?string $html = null): bool {
    global $APP;
    if (!empty($APP['mail_log'])) { @file_put_contents((string)$APP['mail_log'], date('c') . " $to | $subject\n$text\n\n", FILE_APPEND); return true; } // для проверки на своём компьютере
    $html = $html ?? mail_html_from_text($text);
    $b = 'a' . bin2hex(random_bytes(12));
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
        . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
        . "--$b--\r\n";
    $ok = mail_send($to, $subject, "Content-Type: multipart/alternative; boundary=\"$b\"", $body);
    if (!$ok) error_log('mail to ' . $to . ': ' . mail_error());
    return $ok;
}

