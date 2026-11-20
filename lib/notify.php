<?php
// Уведомления агентству о новых заявках: Telegram-бот и почта.
// В уведомлении нет имени, телефона и адреса клиента: они только в личном кабинете (152-ФЗ).

function site_url(): string {
    global $APP;
    if (!empty($APP['site_url'])) return rtrim($APP['site_url'], '/');
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', $_SERVER['HTTP_HOST'] ?? 'bronprazdnik.ru');
    return 'https://' . $host;
}

function tg_api(string $method, array $params = []): ?array {
    global $APP;
    $token = (string)($APP['tg_bot_token'] ?? '');
    if ($token === '') return null;
    $url = "https://api.telegram.org/bot$token/$method";
    $body = json_encode($params, JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4]);
        $r = curl_exec($ch);
        curl_close($ch);
    } else {
        $r = @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'timeout' => 6, 'ignore_errors' => true]]));
    }
    if (!is_string($r) || $r === '') { error_log("telegram $method: нет ответа"); return null; }
    $j = json_decode($r, true);
    if (empty($j['ok'])) error_log("telegram $method: " . mb_substr($r, 0, 300));
    return is_array($j) ? $j : null;
}
function tg_ready(): bool { global $APP; return !empty($APP['tg_bot_token']); }
function tg_secret(): string { global $APP; return substr(hash_hmac('sha256', 'tg-webhook', (string)$APP['secret']), 0, 48); }
function tg_chats(int $aid): array { return array_values(array_map('intval', setting_get("tgchats:$aid")['chats'] ?? [])); }
function tg_set_chats(int $aid, array $chats): void {
    if ($chats) setting_set("tgchats:$aid", ['chats' => array_values(array_unique($chats))]);
    else db()->prepare('DELETE FROM settings WHERE name = ?')->execute(["tgchats:$aid"]);
}
// Подключает вебхук и узнаёт имя бота; результат запоминается, повторно не вызывается
function tg_bot(): ?array {
    $url = site_url() . '/api/tg.php';
    $bot = setting_get('tgbot');
    if ($bot && ($bot['webhook'] ?? '') === $url && !empty($bot['username'])) return $bot;
    $me = tg_api('getMe');
    if (empty($me['result']['username'])) return null;
    $wh = tg_api('setWebhook', ['url' => $url, 'secret_token' => tg_secret(), 'allowed_updates' => ['message'], 'drop_pending_updates' => true]);
    if (empty($wh['ok'])) return null;
    $bot = ['username' => $me['result']['username'], 'webhook' => $url];
    setting_set('tgbot', $bot);
    return $bot;
}
function tg_send(int $chat, string $text): bool {
    $r = tg_api('sendMessage', ['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => true]);
    return !empty($r['ok']);
}

function notify_emails(array $cfg): array {
    $raw = trim((string)($cfg['notify']['email'] ?? ''));
    if ($raw === '') return [];
    return array_slice(array_values(array_filter(preg_split('/[\s,;]+/', $raw), function ($m) { return filter_var($m, FILTER_VALIDATE_EMAIL); })), 0, 3);
}
function send_mail(string $to, string $subject, string $text): bool {
    $host = parse_url(site_url(), PHP_URL_HOST) ?: 'bronprazdnik.ru';
    $headers = "From: =?UTF-8?B?" . base64_encode('БронПраздник') . "?= <noreply@$host>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', chunk_split(base64_encode($text)), $headers);
}

// Возвращает, куда удалось отправить: ['tg' => n, 'email' => n]
function notify_agency(array $a, string $subject, string $text): array {
    $sent = ['tg' => 0, 'email' => 0];
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
    $text = "Новая заявка №$id\n\n$d {$months[$m - 1]}, {$o['time']}\n$who\n$prog\nДетей: {$o['kids']}\nСумма: " . number_format($total, 0, '', ' ') . " ₽\n\n"
        . "Контакты клиента — в личном кабинете:\n" . site_url() . "/admin/";
    notify_agency($a, "Новая заявка №$id на $d {$months[$m - 1]}, {$o['time']}", $text);
}
