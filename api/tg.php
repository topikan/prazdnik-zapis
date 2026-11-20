<?php
// Вебхук Telegram-бота (при прямом доступе к Telegram, без реле)
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/notify.php';

if (!hash_equals(tg_secret(), (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) { http_response_code(403); exit; }
$u = json_decode((string)file_get_contents('php://input'), true);
if (is_array($u['message'] ?? null) && isset($u['message']['chat']['id'])) tg_handle($u['message']);
echo 'ok';
