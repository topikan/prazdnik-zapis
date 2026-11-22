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
    return $pdo;
}
function is_mysql(): bool { global $APP; return str_starts_with($APP['db_dsn'], 'mysql:'); }

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code = 400): void { json_out(['error' => $msg], $code); }

function body_json(): array {
    $raw = file_get_contents('php://input', false, null, 0, 200000);
    $d = json_decode($raw ?: '', true);
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
// Письмо с текстом (коды подтверждения и т. п.)
function mail_text(string $to, string $subject, string $text): bool {
    global $APP;
    $host = parse_url((string)($APP['site_url'] ?? 'https://bronprazdnik.ru'), PHP_URL_HOST) ?: 'bronprazdnik.ru';
    $headers = "From: =?UTF-8?B?" . base64_encode('Бронь Праздник') . "?= <noreply@$host>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
    if (!empty($APP['mail_log'])) { @file_put_contents((string)$APP['mail_log'], date('c') . " $to | $subject\n$text\n\n", FILE_APPEND); return true; } // для проверки на своём компьютере
    // -f: отправитель конверта — адрес на нашем домене. Без него письмо уходит от технического адреса сервера,
    // проверка SPF у Gmail идёт не по bronprazdnik.ru, и письма с кодами попадают в спам или теряются.
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', chunk_split(base64_encode($text)), $headers, "-fnoreply@$host");
}

