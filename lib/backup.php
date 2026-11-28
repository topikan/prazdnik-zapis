<?php
// Копии заявок: таблица для Excel и ночной архив с паролем в Telegram и на почту.
declare(strict_types=1);

const BACKUP_STATUS = ['new' => 'Новая', 'confirmed' => 'Подтверждена', 'done' => 'Выполнена', 'cancelled' => 'Отменена'];

// Таблица заявок агентства: CSV с разделителем «;» и BOM — Excel открывает её сразу с русскими буквами
function orders_csv(array $a): string {
    $cfg = $a['cfg'];
    $title = function (array $list, $id) { foreach ($list as $x) if (($x['id'] ?? null) === $id) return (string)($x['title'] ?? ''); return (string)$id; };
    $cell = function ($v): string { return '"' . str_replace('"', '""', (string)$v) . '"'; };
    // Текст, который ввёл клиент: если он начинается как формула, Excel её не выполнит
    $txt = function ($v): string { $v = (string)$v; return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v; };
    // Телефон как текст «8 999 123-45-67»: с «+» в начале Excel принял бы его за формулу или число
    $tel = function ($p): string { $d = preg_replace('/\D/', '', (string)$p); return strlen($d) === 11 ? '8 ' . substr($d, 1, 3) . ' ' . substr($d, 4, 3) . '-' . substr($d, 7, 2) . '-' . substr($d, 9) : $d; };
    $rows = [['№', 'Дата визита', 'Время', 'Повод', 'Кто приедет', 'Программа', 'Детей', 'Дети', 'Допы', 'Куда ехать', 'Имя', 'Телефон', 'Адрес', 'Пожелания', 'Сумма, ₽', 'Статус', 'Предоплата', 'Откуда', 'Заявка создана']];
    try { db()->query('SELECT paid FROM orders LIMIT 1'); $paidCol = 'paid'; } catch (Throwable $e) { $paidCol = '0 AS paid'; }
    try { db()->query('SELECT source FROM orders LIMIT 1'); $srcCol = 'source'; } catch (Throwable $e) { $srcCol = "'site' AS source"; }
    $src = ['site' => 'Сайт', 'phone' => 'Телефон', 'messenger' => 'Мессенджер', 'repeat' => 'Постоянный клиент', 'other' => 'Другое'];
    $st = db()->prepare("SELECT id, visit_date, visit_time, data, name, phone, address, total, status, $paidCol, $srcCol, created_at FROM orders WHERE agency_id = ? ORDER BY visit_date, visit_time, id");
    $st->execute([(int)$a['id']]);
    foreach ($st as $r) {
        $d = json_decode($r['data'], true) ?: [];
        $kids = implode('; ', array_map(function ($c) { return trim(($c['name'] ?? '') . (($c['age'] ?? '') !== '' ? ', ' . $c['age'] : '')); }, is_array($d['children'] ?? null) ? $d['children'] : []));
        $addons = implode(', ', array_map(function ($id) use ($cfg, $title) { return $title($cfg['addons'] ?? [], $id); }, is_array($d['addons'] ?? null) ? $d['addons'] : []));
        $rows[] = [$r['id'], ru_date((string)$r['visit_date']), $r['visit_time'], $title($cfg['occasions'] ?? [], $d['occasion'] ?? ''), $title($cfg['characters'] ?? [], $d['character'] ?? ''), $title($cfg['programs'] ?? [], $d['program'] ?? ''),
            $d['kids'] ?? '', $txt($kids), $addons, $title($cfg['zones'] ?? [], $d['zone'] ?? ''), $txt($r['name']), $tel($r['phone']), $txt($r['address']), $txt($d['comment'] ?? ''),
            $r['total'], BACKUP_STATUS[$r['status']] ?? $r['status'], (int)$r['paid'] ? 'да' : 'нет', $src[$r['source']] ?? 'Сайт', date('d.m.Y H:i', strtotime((string)$r['created_at']))];
    }
    return "\xEF\xBB\xBF" . implode("\r\n", array_map(function ($row) use ($cell) { return implode(';', array_map($cell, $row)); }, $rows)) . "\r\n";
}
function orders_count(int $aid): int { $st = db()->prepare('SELECT COUNT(*) FROM orders WHERE agency_id = ?'); $st->execute([$aid]); return (int)$st->fetchColumn(); }

// Может ли сервер делать архивы с паролем (ZipArchive с шифрованием AES)
function zip_password_ok(): bool { return class_exists('ZipArchive') && method_exists('ZipArchive', 'setEncryptionName') && defined('ZipArchive::EM_AES_256'); }

// Архив с паролем из файлов [имя => содержимое]; null, если не получилось
function zip_with_password(array $files, string $password): ?string {
    if (!zip_password_ok() || strlen($password) < 6) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'bpz');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { @unlink($tmp); return null; }
    $zip->setPassword($password);
    foreach ($files as $name => $bytes) {
        $zip->addFromString($name, $bytes);
        if (!$zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password)) { $zip->close(); @unlink($tmp); return null; }
    }
    $zip->close();
    $out = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $out !== '' ? $out : null;
}
function safe_file_part(string $s): string { $s = preg_replace('/[^a-z0-9-]+/i', '-', $s); return trim((string)$s, '-') ?: 'agency'; }

// Ночная копия одного агентства. Возвращает, куда отправлено.
function backup_agency(array $a): array {
    $b = is_array($a['cfg']['backup'] ?? null) ? $a['cfg']['backup'] : [];
    $res = ['tg' => 0, 'email' => 0, 'skipped' => ''];
    if (empty($b['tg']) && empty($b['email'])) { $res['skipped'] = 'off'; return $res; }
    if (!orders_count((int)$a['id'])) { $res['skipped'] = 'empty'; return $res; }
    $day = date('Y-m-d');
    $zip = zip_with_password(['zayavki-' . safe_file_part($a['slug']) . "-$day.csv" => orders_csv($a)], (string)($b['password'] ?? ''));
    if ($zip === null) { $res['skipped'] = 'zip'; return $res; }
    $file = 'zayavki-' . safe_file_part($a['slug']) . "-$day.zip";
    $name = (string)($a['cfg']['name'] ?? $a['slug']);
    $caption = "Копия заявок «{$name}» на " . ru_date($day) . ". Архив открывается паролем, который вы задали в настройках.";
    if (!empty($b['tg'])) foreach (tg_chats((int)$a['id']) as $chat) if (tg_send_document($chat, $file, $zip, $caption)) $res['tg']++;
    if (!empty($b['email'])) foreach (notify_emails($a['cfg']) as $m) if (send_mail_file($m, "Копия заявок на " . ru_date($day), $caption . "\n\nТаблица внутри архива открывается в Excel.", $file, $zip)) $res['email']++;
    return $res;
}

// Ночная копия для владельца: заявки всех агентств, по таблице на агентство
function backup_owner(): array {
    $b = setting_get('ownerbackup') ?: [];
    $res = ['tg' => 0, 'email' => 0, 'skipped' => ''];
    if (empty($b['tg']) && empty($b['email'])) { $res['skipped'] = 'off'; return $res; }
    $files = [];
    foreach (db()->query('SELECT id FROM agencies ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $a = agency_by_id((int)$id);
        if ($a && empty($a['cfg']['demo']) && orders_count((int)$a['id'])) $files[safe_file_part($a['slug']) . '.csv'] = orders_csv($a);
    }
    $day = date('Y-m-d');
    if (!$files) {
        // архива нет, но короткое сообщение показывает, что ночная проверка работает
        $msg = 'Ночная копия заявок на ' . ru_date($day) . ': заявок с сайта пока нет, архив не нужен. Как только появятся заявки, сюда будет приходить архив с паролем.';
        if (!empty($b['tg'])) foreach (tg_chats(0) as $chat) if (tg_send($chat, $msg)) $res['tg']++;
        if (!empty($b['email']) && owner_email() !== '' && mail_text(owner_email(), 'Копия заявок на ' . ru_date($day) . ': заявок пока нет', $msg)) $res['email']++;
        $res['skipped'] = 'empty'; return $res;
    }
    $zip = zip_with_password($files, (string)($b['password'] ?? ''));
    if ($zip === null) { $res['skipped'] = 'zip'; return $res; }
    $file = "anisis-vse-zayavki-$day.zip";
    $caption = 'Копия заявок всех агентств на ' . ru_date($day) . ' (' . count($files) . ' шт.). Пароль — из вкладки «Агентства».';
    if (!empty($b['tg'])) foreach (tg_chats(0) as $chat) if (tg_send_document($chat, $file, $zip, $caption)) $res['tg']++;
    if (!empty($b['email']) && owner_email() !== '' && send_mail_file(owner_email(), 'Копия всех заявок на ' . ru_date($day), $caption, $file, $zip)) $res['email']++;
    return $res;
}

// Итог ночного запуска понятными словами — показываем владельцу рядом с «Последний запуск»
function cron_result(?array $r): string {
    if (!$r) return '';
    $o = $r['owner'] ?? [];
    $why = ['off' => 'ваша копия выключена — поставьте галочку «В Telegram» или «На почту» в разделе «Копия всех заявок» и сохраните',
        'empty' => 'заявок с сайта пока нет, архив не нужен (демо-агентство не считается)', 'zip' => 'сервер не смог собрать архив с паролем'];
    if (!empty($o['error'])) $t = 'ваша копия: ошибка при отправке';
    elseif (($o['skipped'] ?? '') === 'empty') $t = $why['empty'] . (!empty($o['tg']) || !empty($o['email']) ? ', сообщение об этом отправлено' : '');
    elseif (!empty($o['skipped'])) $t = 'ваша копия не отправлена: ' . ($why[$o['skipped']] ?? $o['skipped']);
    else {
        $parts = [];
        if (!empty($o['tg'])) $parts[] = 'в Telegram';
        if (!empty($o['email'])) $parts[] = 'на почту';
        $t = $parts ? 'ваша копия отправлена ' . implode(' и ', $parts) : 'ваша копия не дошла — проверьте, что Telegram подключён';
    }
    $sent = 0; foreach (($r['agencies'] ?? []) as $a) if (!empty($a['tg']) || !empty($a['email'])) $sent++;
    return '. Итог: ' . $t . '. Агентствам отправлено копий: ' . $sent;
}

// Ключ для ссылки ночного запуска: без него никто посторонний не запустит рассылку копий
function cron_key(): string { global $APP; return substr(hash_hmac('sha256', 'cron-backup', (string)$APP['secret']), 0, 32); }
