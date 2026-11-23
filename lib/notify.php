<?php
// Уведомления агентству о новых заявках: Telegram-бот и почта.
// В уведомлении нет имени, телефона и адреса клиента: они только в личном кабинете (152-ФЗ).

function site_url(): string {
    global $APP;
    if (!empty($APP['site_url'])) return rtrim($APP['site_url'], '/');
    // Не берём адрес из заголовка Host: его можно подделать и подсунуть агентству ссылку на чужой сайт
    if (is_local_host()) return 'http://' . preg_replace('/[^a-z0-9.\-:\[\]]/i', '', (string)$_SERVER['HTTP_HOST']);
    return 'https://bronprazdnik.ru';
}

// Запрос к Telegram: напрямую или через реле в Google Apps Script.
// Реле нужно, если хостинг в России не достучится до api.telegram.org; токен бота тогда хранится только в Google.
function tg_relay(): bool { global $APP; return !empty($APP['tg_relay_url']); }
function http_post_json(string $url, string $body, int $timeout): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3]); // Google отвечает переадресацией на результат
        $r = curl_exec($ch);
        curl_close($ch);
    } else {
        $r = @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true]]));
    }
    return is_string($r) && $r !== '' ? $r : null;
}
function tg_api(string $method, array $params = []): ?array {
    global $APP;
    if (tg_relay()) {
        $r = http_post_json((string)$APP['tg_relay_url'], json_encode(['key' => (string)($APP['tg_relay_key'] ?? ''), 'method' => $method, 'params' => (object)$params], JSON_UNESCAPED_UNICODE), 10);
    } else {
        $token = (string)($APP['tg_bot_token'] ?? '');
        if ($token === '') return null;
        $r = http_post_json("https://api.telegram.org/bot$token/$method", json_encode((object)$params, JSON_UNESCAPED_UNICODE), 8);
    }
    if ($r === null) { error_log("telegram $method: нет ответа"); return null; }
    $j = json_decode($r, true);
    if (empty($j['ok'])) error_log("telegram $method: " . mb_substr($r, 0, 300));
    return is_array($j) ? $j : null;
}
function tg_ready(): bool { global $APP; return !empty($APP['tg_bot_token']) || tg_relay(); }
function tg_secret(): string { global $APP; return substr(hash_hmac('sha256', 'tg-webhook', (string)$APP['secret']), 0, 48); }
function tg_chats(int $aid): array { return array_values(array_map('intval', setting_get("tgchats:$aid")['chats'] ?? [])); }
function tg_set_chats(int $aid, array $chats): void {
    if ($chats) setting_set("tgchats:$aid", ['chats' => array_values(array_unique($chats))]);
    else db()->prepare('DELETE FROM settings WHERE name = ?')->execute(["tgchats:$aid"]);
}
// Узнаёт имя бота и включает приём сообщений: вебхук при прямом доступе, опрос при работе через реле.
// Результат запоминается, повторно не вызывается.
function tg_bot(): ?array {
    $mode = tg_relay() ? 'poll' : site_url() . '/api/tg.php';
    $bot = setting_get('tgbot');
    if ($bot && ($bot['mode'] ?? '') === $mode && !empty($bot['username'])) return $bot;
    $me = tg_api('getMe');
    if (empty($me['result']['username'])) return null;
    $r = $mode === 'poll'
        ? tg_api('deleteWebhook', ['drop_pending_updates' => false])
        : tg_api('setWebhook', ['url' => $mode, 'secret_token' => tg_secret(), 'allowed_updates' => ['message'], 'drop_pending_updates' => true]);
    if (empty($r['ok'])) return null;
    $bot = ['username' => $me['result']['username'], 'mode' => $mode];
    setting_set('tgbot', $bot);
    return $bot;
}
// Забирает новые сообщения боту (режим реле): подключения по ссылке и /stop
function tg_link_pending(): bool {
    foreach (db()->query("SELECT value FROM settings WHERE name LIKE 'tglink:%'")->fetchAll(PDO::FETCH_COLUMN) as $v) if ((json_decode($v, true)['exp'] ?? 0) >= time()) return true;
    return false;
}
function tg_poll(): void {
    if (!tg_relay()) return;
    $bot = setting_get('tgbot');
    if (($bot['mode'] ?? '') !== 'poll') return;
    $offset = (int)(setting_get('tgoffset')['v'] ?? 0);
    $r = tg_api('getUpdates', ['offset' => $offset, 'timeout' => 0, 'allowed_updates' => ['message']]);
    foreach ($r['result'] ?? [] as $u) {
        $offset = max($offset, (int)$u['update_id'] + 1);
        if (isset($u['message']['chat']['id'])) tg_handle($u['message']);
    }
    setting_set('tgoffset', ['v' => $offset]);
}
// Сообщение боту: /start <код> подключает чат к агентству, /stop отключает
function tg_handle(array $msg): void {
    $chat = (int)$msg['chat']['id'];
    $text = trim((string)($msg['text'] ?? ''));
    if (preg_match('~^/start(?:@\w+)?\s+([a-f0-9]{16})$~', $text, $m)) {
        $link = setting_get('tglink:' . $m[1]);
        db()->prepare('DELETE FROM settings WHERE name = ?')->execute(['tglink:' . $m[1]]);
        // aid = 0 — это владелец сервиса: ему приходят новые регистрации, загрузки и копии всех заявок
        if ($link && ($link['exp'] ?? 0) >= time() && (int)$link['aid'] === 0) {
            $chats = tg_chats(0);
            if (!in_array($chat, $chats, true)) $chats[] = $chat;
            tg_set_chats(0, array_slice($chats, -5));
            tg_send($chat, "Готово! Сюда будут приходить уведомления владельца: новые агентства, загрузки и ночные копии заявок.\n\nОтключить: /stop");
            return;
        }
        $a = $link && ($link['exp'] ?? 0) >= time() ? agency_by_id((int)$link['aid']) : null;
        if (!$a) { tg_send($chat, "Ссылка устарела. Откройте личный кабинет → «Настройки» → «Уведомления» и нажмите «Подключить Telegram» ещё раз."); return; }
        $chats = tg_chats((int)$a['id']);
        if (!in_array($chat, $chats, true)) $chats[] = $chat;
        tg_set_chats((int)$a['id'], array_slice($chats, -10));
        tg_send($chat, "Готово! Сюда будут приходить новые заявки «" . ($a['cfg']['name'] ?? $a['slug']) . "».\n\nОтключить уведомления: /stop");
    } elseif (preg_match('~^/stop(?:@\w+)?$~', $text)) {
        foreach (db()->query("SELECT name, value FROM settings WHERE name LIKE 'tgchats:%'")->fetchAll() as $row) {
            $chats = array_map('intval', json_decode($row['value'], true)['chats'] ?? []);
            if (in_array($chat, $chats, true)) tg_set_chats((int)substr($row['name'], 8), array_values(array_diff($chats, [$chat])));
        }
        tg_send($chat, 'Уведомления отключены. Подключить снова можно в личном кабинете.');
    } else {
        tg_send($chat, "Это бот уведомлений о заявках Бронь Праздник.\n\nЧтобы получать заявки, откройте личный кабинет → «Настройки» → «Уведомления» и нажмите «Подключить Telegram».");
    }
}
function tg_send(int $chat, string $text): bool {
    $r = tg_api('sendMessage', ['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => true]);
    return !empty($r['ok']);
}

// Файл в Telegram (копия заявок). Через реле файл передаётся в base64, реле само собирает отправку.
function tg_send_document(int $chat, string $filename, string $bytes, string $caption): bool {
    global $APP;
    if (tg_relay()) {
        $r = http_post_json((string)$APP['tg_relay_url'], json_encode(['key' => (string)($APP['tg_relay_key'] ?? ''), 'method' => 'sendDocument',
            'params' => ['chat_id' => $chat, 'caption' => $caption], 'file' => ['name' => $filename, 'mime' => 'application/zip', 'b64' => base64_encode($bytes)]], JSON_UNESCAPED_UNICODE), 60);
    } else {
        $token = (string)($APP['tg_bot_token'] ?? '');
        if ($token === '' || !function_exists('curl_init')) return false;
        $ch = curl_init("https://api.telegram.org/bot$token/sendDocument");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_POSTFIELDS => ['chat_id' => (string)$chat, 'caption' => $caption, 'document' => new CURLStringFile($bytes, $filename, 'application/zip')]]);
        $r = curl_exec($ch); curl_close($ch);
        $r = is_string($r) ? $r : null;
    }
    $j = $r ? json_decode($r, true) : null;
    if (empty($j['ok'])) { error_log('telegram sendDocument: ' . mb_substr((string)$r, 0, 300)); return false; }
    return true;
}
// Письмо с вложением (копия заявок архивом)
function send_mail_file(string $to, string $subject, string $text, string $filename, string $bytes): bool {
    global $APP;
    if (!empty($APP['mail_log'])) { @file_put_contents((string)$APP['mail_log'], date('c') . " $to | $subject | вложение $filename, " . strlen($bytes) . " байт\n$text\n\n", FILE_APPEND); return true; }
    $b = 'b' . bin2hex(random_bytes(12));
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
        . "--$b\r\nContent-Type: application/zip; name=\"$filename\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$filename\"\r\n\r\n" . chunk_split(base64_encode($bytes))
        . "--$b--\r\n";
    return mail_send($to, $subject, "Content-Type: multipart/mixed; boundary=\"$b\"", $body);
}

// Уведомление владельцу сервиса: в его Telegram (подключается во вкладке «Агентства») и на почту из настроек
function owner_email(): string { global $APP; $m = (string)((setting_get('owner') ?: [])['email'] ?? ($APP['owner_email'] ?? '')); return filter_var($m, FILTER_VALIDATE_EMAIL) ? $m : ''; }
function notify_owner(string $subject, string $text): void {
    try {
        foreach (tg_chats(0) as $chat) tg_send($chat, $text);
        if (owner_email() !== '') send_mail(owner_email(), $subject, $text);
    } catch (Throwable $e) { error_log('notify_owner: ' . $e->getMessage()); }
}
// Не чаще раза в 15 минут на одно агентство: чтобы 20 загруженных фото не стали 20 сообщениями
function notify_owner_throttled(string $key, string $subject, string $text): void {
    $name = 'own:' . substr(hash('sha256', $key), 0, 20);
    $last = (int)((setting_get($name) ?: [])['t'] ?? 0);
    if (time() - $last < 900) return;
    setting_set($name, ['t' => time()]);
    notify_owner($subject, $text);
}

function notify_emails(array $cfg): array {
    $raw = trim((string)($cfg['notify']['email'] ?? ''));
    if ($raw === '') return [];
    return array_slice(array_values(array_filter(preg_split('/[\s,;]+/', $raw), function ($m) { return filter_var($m, FILTER_VALIDATE_EMAIL); })), 0, 3);
}
function send_mail(string $to, string $subject, string $text): bool { return mail_text($to, $subject, $text); }

// Возвращает, куда удалось отправить: ['tg' => n, 'email' => n]
function notify_agency(array $a, string $subject, string $text): array {
    $sent = ['tg' => 0, 'email' => 0];
    try { tg_poll(); } catch (Throwable $e) { error_log('tg_poll: ' . $e->getMessage()); } // учесть свежие /stop
    foreach (tg_chats((int)$a['id']) as $chat) if (tg_send($chat, $text)) $sent['tg']++;
    foreach (notify_emails($a['cfg']) as $m) if (send_mail($m, $subject, $text)) $sent['email']++;
    return $sent;
}

function notify_order(array $a, int $id, array $o, int $total): void {
    $cfg = $a['cfg'];
    $months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    [$y, $m, $d] = array_map('intval', explode('-', $o['date']));
    $who = ''; foreach ($cfg['characters'] as $c) if ($c['id'] === $o['character']) $who = $c['title'];
    $prog = ''; foreach ($cfg['programs'] as $p) if ($p['id'] === $o['program']) $prog = $p['title'] . ', ' . $p['minutes'] . ' мин';
    $text = "🔔 Новая заявка №$id — «" . ($cfg['name'] ?? $a['slug']) . "»\n\n📅 Дата: $d {$months[$m - 1]}\n🕚 Время: {$o['time']}\n🎅 Кто: $who\n🎉 Программа: $prog\n👧 Детей: {$o['kids']}\n💰 Сумма: " . number_format($total, 0, '', ' ') . " ₽\n\n"
        . "Контакты клиента — в личном кабинете:\n" . site_url() . "/admin/";
    notify_agency($a, "Новая заявка №$id на $d {$months[$m - 1]}, {$o['time']}", $text);
}
