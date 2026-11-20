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

function client_key(): string {
    global $APP;
    return hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '0', $APP['secret']);
}

// Ограничение частоты: не больше $max событий вида $kind за $seconds с одного адреса
function rate_limit(string $kind, int $max, int $seconds): void {
    $pdo = db();
    $since = date('Y-m-d H:i:s', time() - $seconds);
    $st = $pdo->prepare('SELECT COUNT(*) FROM hits WHERE kind = ? AND key_hash = ? AND created_at > ?');
    $st->execute([$kind, client_key(), $since]);
    if ((int)$st->fetchColumn() >= $max) fail('Слишком много попыток. Попробуйте через несколько минут.', 429);
    $pdo->prepare('INSERT INTO hits (kind, key_hash, created_at) VALUES (?, ?, ?)')->execute([$kind, client_key(), date('Y-m-d H:i:s')]);
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

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('bp_admin');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_start();
}

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
