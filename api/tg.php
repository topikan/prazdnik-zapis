<?php
// Вебхук Telegram-бота: подключение и отключение уведомлений агентства
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/notify.php';

if (!hash_equals(tg_secret(), (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) { http_response_code(403); exit; }
$u = json_decode((string)file_get_contents('php://input'), true);
$msg = $u['message'] ?? null;
if (!is_array($msg) || !isset($msg['chat']['id'])) exit('ok');
$chat = (int)$msg['chat']['id'];
$text = trim((string)($msg['text'] ?? ''));

if (preg_match('~^/start(?:@\w+)?\s+([a-f0-9]{16})$~', $text, $m)) {
    $link = setting_get('tglink:' . $m[1]);
    db()->prepare('DELETE FROM settings WHERE name = ?')->execute(['tglink:' . $m[1]]);
    $a = $link && ($link['exp'] ?? 0) >= time() ? agency_by_id((int)$link['aid']) : null;
    if (!$a) { tg_send($chat, "Ссылка устарела. Откройте личный кабинет → «Настройки» → «Уведомления» и нажмите «Подключить Telegram» ещё раз."); exit('ok'); }
    $chats = tg_chats((int)$a['id']);
    if (!in_array($chat, $chats, true)) $chats[] = $chat;
    tg_set_chats((int)$a['id'], array_slice($chats, -10));
    tg_send($chat, "Готово! Сюда будут приходить новые заявки «" . ($a['cfg']['name'] ?? $a['slug']) . "».\n\nОтключить уведомления: /stop");
} elseif (preg_match('~^/stop(?:@\w+)?$~', $text)) {
    $st = db()->query("SELECT name, value FROM settings WHERE name LIKE 'tgchats:%'");
    foreach ($st->fetchAll() as $row) {
        $chats = json_decode($row['value'], true)['chats'] ?? [];
        if (in_array($chat, $chats, true)) tg_set_chats((int)substr($row['name'], 8), array_values(array_diff($chats, [$chat])));
    }
    tg_send($chat, 'Уведомления отключены. Подключить снова можно в личном кабинете.');
} else {
    tg_send($chat, "Это бот уведомлений о заявках БронПраздник.\n\nЧтобы получать заявки, откройте личный кабинет → «Настройки» → «Уведомления» и нажмите «Подключить Telegram».");
}
echo 'ok';
