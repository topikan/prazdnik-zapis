<?php
// Первичная установка: создаёт таблицы, демо-агентство и вашу учётную запись владельца.
// Работает только пока в базе нет ни одного пользователя. После установки удалите папку install с сервера.
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/lib/bootstrap.php';

$pdo = db();
$my = is_mysql();
$id = $my ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$text = $my ? 'MEDIUMTEXT' : 'TEXT';
$tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

$sql = [
"CREATE TABLE IF NOT EXISTS agencies (id $id, slug VARCHAR(40) NOT NULL UNIQUE, config $text NOT NULL, active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL)$tail",
"CREATE TABLE IF NOT EXISTS users (id $id, agency_id INT NULL, login VARCHAR(60) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL)$tail",
"CREATE TABLE IF NOT EXISTS orders (id $id, agency_id INT NOT NULL, visit_date DATE NOT NULL, visit_time CHAR(5) NOT NULL, span TINYINT NOT NULL DEFAULT 1, data $text NOT NULL, name VARCHAR(80) NOT NULL, phone VARCHAR(20) NOT NULL, address VARCHAR(255) NOT NULL, total INT NOT NULL, price_lines TEXT NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'new', created_at DATETIME NOT NULL)$tail",
"CREATE TABLE IF NOT EXISTS blocks (id $id, agency_id INT NOT NULL, visit_date DATE NOT NULL, visit_time CHAR(5) NOT NULL, teams TINYINT NOT NULL DEFAULT 1, note VARCHAR(100) NOT NULL DEFAULT '', created_at DATETIME NOT NULL)$tail",
"CREATE TABLE IF NOT EXISTS hits (id $id, kind VARCHAR(10) NOT NULL, key_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL)$tail",
"CREATE INDEX idx_orders_day ON orders (agency_id, visit_date)",
"CREATE INDEX idx_blocks_day ON blocks (agency_id, visit_date)",
"CREATE INDEX idx_hits ON hits (kind, key_hash, created_at)",
];

$isCli = PHP_SAPI === 'cli';
function say(string $s): void { global $isCli; echo $isCli ? $s . "\n" : '<p>' . htmlspecialchars($s) . '</p>'; }
if (!$isCli) echo '<!doctype html><meta charset="utf-8"><title>Установка</title><body style="font:16px system-ui;max-width:640px;margin:40px auto;padding:0 16px">';

foreach ($sql as $q) { try { $pdo->exec($q); } catch (PDOException $e) { if (!str_contains($e->getMessage(), 'exists') && !str_contains($e->getMessage(), 'Duplicate')) throw $e; } }

if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) { say('Установка уже выполнена. Удалите папку install с сервера.'); exit; }

$login = $isCli ? ($argv[1] ?? '') : (string)($_POST['login'] ?? '');
$pass  = $isCli ? ($argv[2] ?? '') : (string)($_POST['password'] ?? '');

if (!$isCli && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo '<h1>Установка</h1><p>Придумайте логин и пароль владельца сервиса (не короче 10 символов).</p>
    <form method="post"><p><input name="login" placeholder="Логин" required></p><p><input name="password" type="password" placeholder="Пароль" minlength="10" required></p><p><button>Установить</button></p></form>';
    exit;
}
if (!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/', $login) || strlen($pass) < 10) { say('Логин: латиница и цифры, от 3 символов. Пароль: не короче 10 символов.'); exit; }

$pdo->prepare('INSERT INTO users (agency_id, login, password_hash, role, created_at) VALUES (NULL, ?, ?, ?, ?)')
    ->execute([$login, password_hash($pass, PASSWORD_DEFAULT), 'super', date('Y-m-d H:i:s')]);

$demo = file_get_contents(APP_ROOT . '/agencies/demo.json');
$st = $pdo->prepare('SELECT COUNT(*) FROM agencies WHERE slug = ?'); $st->execute(['demo']);
if (!(int)$st->fetchColumn()) $pdo->prepare('INSERT INTO agencies (slug, config, active, created_at) VALUES (?, ?, 1, ?)')->execute(['demo', $demo, date('Y-m-d H:i:s')]);

say('Готово. Таблицы созданы, демо-агентство добавлено, владелец ' . $login . ' создан.');
say('Теперь удалите папку install с сервера и войдите в /admin/.');
