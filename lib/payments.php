<?php
// Онлайн-оплата через ЮKassa:
//   sub   — агентство оплачивает сезон сервиса (деньги в магазин владельца сервиса, ключи в config.local.php);
//   order — клиент вносит предоплату за праздник (деньги сразу в магазин агентства, его ключи в кабинете).
//
// Правила безопасности:
//   * сумму считает только сервер; из браузера приходит лишь токен заявки или сессия кабинета;
//   * засчитываются только платежи, которые создал сам сервер (они записаны в таблицу payments);
//   * уведомлению ЮKassa не верим: по номеру платежа заново спрашиваем ЮKassa с нашими ключами
//     и сверяем статус, сумму, валюту и метаданные с тем, что записано у нас;
//   * зачисление выполняется один раз: условное UPDATE ... WHERE status <> 'succeeded';
//   * секретный ключ агентства хранится зашифрованным и никогда не отдаётся в браузер.
declare(strict_types=1);

const YK_API = 'https://api.yookassa.ru/v3';

// Адрес API меняется только в config.local.php (для тестового стенда), из запроса — никогда
function yk_base(): string { global $APP; return rtrim((string)($APP['yk_api'] ?? YK_API), '/'); }

function ensure_pay_tables(): void {
    static $done = false; if ($done) return;
    $my = is_mysql();
    $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    db()->exec("CREATE TABLE IF NOT EXISTS payments (id VARCHAR(64) NOT NULL PRIMARY KEY, kind VARCHAR(8) NOT NULL, agency_id INT NOT NULL, order_id INT NOT NULL DEFAULT 0, amount INT NOT NULL, shop VARCHAR(20) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL, test TINYINT NOT NULL DEFAULT 0, conf_url VARCHAR(500) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, paid_at DATETIME NULL)$tail");
    try { db()->query('SELECT pay_token, prepaid FROM orders LIMIT 1'); }
    catch (Throwable $e) {
        foreach (["pay_token CHAR(64) NOT NULL DEFAULT ''", 'prepaid INT NOT NULL DEFAULT 0'] as $col) { try { db()->exec("ALTER TABLE orders ADD COLUMN $col"); } catch (Throwable $e2) {} }
    }
    $done = true;
}

/* ---------- ключи ---------- */
function pay_key(): string { global $APP; return hash('sha256', 'yookassa-secret|' . (string)$APP['secret'], true); }
function secret_encrypt(string $plain): string {
    if (!function_exists('openssl_encrypt')) throw new RuntimeException('no openssl');
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', pay_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) throw new RuntimeException('encrypt failed');
    return base64_encode($iv . $tag . $ct);
}
function secret_decrypt(string $box): string {
    $raw = base64_decode($box, true);
    if ($raw === false || strlen($raw) < 29 || !function_exists('openssl_decrypt')) return '';
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', pay_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}
function shop_id_ok(string $s): bool { return (bool)preg_match('/^\d{3,12}$/', $s); }
function secret_key_ok(string $s): bool { return (bool)preg_match('/^(live|test)_[A-Za-z0-9_\-]{10,120}$/', $s); }

// Магазин владельца сервиса — для оплаты сезона
function owner_cred(): ?array {
    global $APP;
    $shop = (string)($APP['yk_shop_id'] ?? ''); $sec = (string)($APP['yk_secret'] ?? '');
    return shop_id_ok($shop) && secret_key_ok($sec) ? ['shop' => $shop, 'secret' => $sec, 'receipt' => !empty($APP['yk_receipt'])] : null;
}
function owner_pay_ready(): bool { return owner_cred() !== null; }

// Магазин агентства — для предоплаты клиентов
function agency_cred(int $aid): ?array {
    $c = setting_get('yk:' . $aid);
    if (!$c || !shop_id_ok((string)($c['shop'] ?? ''))) return null;
    $sec = secret_decrypt((string)($c['sec'] ?? ''));
    return secret_key_ok($sec) ? ['shop' => (string)$c['shop'], 'secret' => $sec, 'receipt' => !empty($c['receipt'])] : null;
}
function agency_cred_public(int $aid): array {
    $c = setting_get('yk:' . $aid) ?: [];
    $ok = agency_cred($aid) !== null;
    return ['connected' => $ok, 'shop' => $ok ? (string)$c['shop'] : '', 'tail' => $ok ? (string)($c['tail'] ?? '') : '', 'test' => $ok && !empty($c['test']), 'receipt' => !empty($c['receipt'])];
}

/* ---------- запросы к ЮKassa ---------- */
function yk_request(string $method, string $path, array $cred, ?array $body = null, string $idem = ''): array {
    if (!function_exists('curl_init')) return ['code' => 0, 'json' => null];
    $ch = curl_init(yk_base() . $path);
    $h = ['Content-Type: application/json'];
    if ($idem !== '') $h[] = 'Idempotence-Key: ' . $idem;
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_USERPWD => $cred['shop'] . ':' . $cred['secret'], CURLOPT_HTTPHEADER => $h,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $r = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'json' => is_string($r) ? json_decode($r, true) : null];
}
// Проверка ключей при сохранении: ЮKassa должна узнать магазин с этим номером
function yk_check_cred(array $cred): array {
    $r = yk_request('GET', '/me', $cred);
    if ($r['code'] === 401 || $r['code'] === 403) return ['ok' => false, 'error' => 'ЮKassa не приняла ключи. Проверьте номер магазина (shopId) и секретный ключ.'];
    if ($r['code'] !== 200 || !is_array($r['json'])) return ['ok' => false, 'error' => 'Не удалось связаться с ЮKassa. Попробуйте ещё раз через минуту.'];
    if ((string)($r['json']['account_id'] ?? '') !== $cred['shop']) return ['ok' => false, 'error' => 'Ключ относится к другому магазину ЮKassa. Проверьте номер магазина.'];
    return ['ok' => true, 'test' => !empty($r['json']['test'])];
}
function confirmation_url_ok(string $u): bool {
    $h = strtolower((string)parse_url($u, PHP_URL_HOST));
    global $APP;
    if (!empty($APP['yk_api']) && is_local_host()) return str_starts_with($u, 'http://127.0.0.1') || str_starts_with($u, 'https://');  // тестовый стенд
    return str_starts_with($u, 'https://') && ($h === 'yoomoney.ru' || str_ends_with($h, '.yoomoney.ru') || $h === 'yookassa.ru' || str_ends_with($h, '.yookassa.ru'));
}
function money(int $rub): array { return ['value' => $rub . '.00', 'currency' => 'RUB']; }

// Создать платёж и записать его у себя. Возвращает ['url' => ...] или ['error' => ...]
function yk_create(string $kind, array $cred, int $aid, int $oid, int $amount, string $desc, string $returnUrl, ?array $receipt): array {
    ensure_pay_tables();
    if ($amount < 1 || $amount > 1000000) return ['error' => 'Неверная сумма'];
    $body = ['amount' => money($amount), 'capture' => true, 'confirmation' => ['type' => 'redirect', 'return_url' => $returnUrl],
        'description' => mb_substr($desc, 0, 128), 'metadata' => ['kind' => $kind, 'agency' => (string)$aid, 'order' => (string)$oid]];
    if ($receipt) $body['receipt'] = $receipt;
    $r = yk_request('POST', '/payments', $cred, $body, bin2hex(random_bytes(16)));
    $j = $r['json'];
    $id = (string)($j['id'] ?? ''); $url = (string)($j['confirmation']['confirmation_url'] ?? '');
    if ($r['code'] !== 200 || !preg_match('/^[0-9a-f][0-9a-f\-]{15,63}$/i', $id) || !confirmation_url_ok($url)) {
        error_log("yookassa create $kind: HTTP {$r['code']} " . mb_substr(json_encode($j['description'] ?? $j['code'] ?? '', JSON_UNESCAPED_UNICODE), 0, 200));
        $why = (string)($j['code'] ?? '');
        return ['error' => $why === 'invalid_credentials' ? 'Оплата временно недоступна: ключи ЮKassa не подходят.' : 'Не удалось создать платёж. Попробуйте ещё раз через минуту.'];
    }
    // сумма в ответе должна совпасть с запрошенной
    if (amount_cents($j['amount'] ?? null) !== $amount * 100) { error_log("yookassa create $kind: сумма в ответе не совпала"); return ['error' => 'Не удалось создать платёж.']; }
    db()->prepare('INSERT INTO payments (id, kind, agency_id, order_id, amount, shop, status, test, conf_url, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $kind, $aid, $oid, $amount, $cred['shop'], 'pending', !empty($j['test']) ? 1 : 0, $url, date('Y-m-d H:i:s')]);
    return ['url' => $url, 'id' => $id];
}
function amount_cents($a): int {
    if (!is_array($a) || ($a['currency'] ?? '') !== 'RUB' || !preg_match('/^\d{1,9}(\.\d{1,2})?$/', (string)($a['value'] ?? ''))) return -1;
    $p = explode('.', (string)$a['value']);
    return (int)$p[0] * 100 + (int)str_pad($p[1] ?? '0', 2, '0');
}

// Проверить платёж в ЮKassa и, если он оплачен, засчитать (ровно один раз).
// Возвращает статус: succeeded / pending / canceled / unknown / error
function yk_apply(string $pid): string {
    ensure_pay_tables();
    if (!preg_match('/^[0-9a-f][0-9a-f\-]{15,63}$/i', $pid)) return 'unknown';
    $st = db()->prepare('SELECT * FROM payments WHERE id = ?'); $st->execute([$pid]);
    $p = $st->fetch();
    if (!$p) return 'unknown';                         // платёж создан не нами — ничего не засчитываем
    if ($p['status'] === 'succeeded' || $p['status'] === 'canceled') return $p['status'];
    $cred = $p['kind'] === 'sub' ? owner_cred() : agency_cred((int)$p['agency_id']);
    if (!$cred || $cred['shop'] !== $p['shop']) return 'error';  // ключи сменились — проверить нечем
    $r = yk_request('GET', '/payments/' . rawurlencode($pid), $cred);
    $j = $r['json'];
    if ($r['code'] !== 200 || !is_array($j) || (string)($j['id'] ?? '') !== $pid) return 'error';
    $status = (string)($j['status'] ?? '');
    if ($status === 'canceled') { db()->prepare("UPDATE payments SET status = 'canceled' WHERE id = ? AND status = 'pending'")->execute([$pid]); return 'canceled'; }
    if ($status !== 'succeeded') return 'pending';
    $meta = is_array($j['metadata'] ?? null) ? $j['metadata'] : [];
    $okMeta = ($meta['kind'] ?? '') === $p['kind'] && (string)($meta['agency'] ?? '') === (string)$p['agency_id'] && (string)($meta['order'] ?? '') === (string)$p['order_id'];
    $okSum = amount_cents($j['amount'] ?? null) === (int)$p['amount'] * 100 && ($j['paid'] ?? false) === true;
    global $APP;
    $okTest = empty($j['test']) || $p['kind'] === 'order' || !empty($APP['yk_allow_test']);  // тестовые деньги не продлевают сервис
    if (!$okMeta || !$okSum || !$okTest) { error_log("yookassa apply $pid: данные платежа не совпали с записанными"); return 'error'; }
    $up = db()->prepare("UPDATE payments SET status = 'succeeded', paid_at = ?, test = ? WHERE id = ? AND status <> 'succeeded'");
    $up->execute([date('Y-m-d H:i:s'), !empty($j['test']) ? 1 : 0, $pid]);
    if ($up->rowCount() !== 1) return 'succeeded';      // уже засчитан параллельным запросом
    pay_effect($p, !empty($j['test']));
    return 'succeeded';
}

function pay_effect(array $p, bool $test): void {
    $aid = (int)$p['agency_id'];
    if ($p['kind'] === 'sub') {
        $s = billing_settings();
        db()->prepare('UPDATE agencies SET paid_until = ? WHERE id = ? AND paid_until < ?')->execute([$s['seasonEnd'], $aid, $s['seasonEnd']]);
        $a = agency_by_id($aid);
        $t = '💳 Оплата сезона: «' . ($a['cfg']['name'] ?? '') . '» (' . ($a['slug'] ?? '') . '), ' . number_format((int)$p['amount'], 0, '', ' ') . ' ₽. Доступ до ' . ru_date($s['seasonEnd']) . '.';
        try { notify_owner('Оплата сезона: ' . ($a['slug'] ?? ''), $t); } catch (Throwable $e) {}
        return;
    }
    db()->prepare('UPDATE orders SET prepaid = prepaid + ?, paid = 1 WHERE id = ? AND agency_id = ?')->execute([(int)$p['amount'], (int)$p['order_id'], $aid]);
    $a = agency_by_id($aid);
    if ($a) {
        $t = '💳 Предоплата по заявке № ' . (int)$p['order_id'] . ': ' . number_format((int)$p['amount'], 0, '', ' ') . ' ₽' . ($test ? ' (тестовый платёж, деньги не настоящие)' : '') . '. Деньги поступили в вашу ЮKassa.';
        try { notify_agency($a, 'Предоплата по заявке № ' . (int)$p['order_id'], $t); } catch (Throwable $e) {}
    }
}

/* ---------- предоплата: сумма ---------- */
// Сколько клиент должен внести по заявке — из настроек агентства и суммы заявки, записанной на сервере
function prepay_amount(array $cfg, int $total): int {
    $p = $cfg['payment'] ?? [];
    $v = (int)($p['ykValue'] ?? 0);
    if (($p['ykType'] ?? 'fixed') === 'percent') $v = (int)round($total * max(1, min(100, $v)) / 100);
    return max(0, min($v, $total));
}
function prepay_online(array $a): bool {
    $p = $a['cfg']['payment'] ?? [];
    return empty($a['cfg']['demo']) && !empty($p['enabled']) && ($p['mode'] ?? 'link') === 'yookassa' && agency_cred((int)$a['id']) !== null;
}
function receipt_for(string $desc, int $amount, string $phone, string $email, string $mode): array {
    $cust = [];
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) === 11) $cust['phone'] = '7' . substr($digits, 1);
    if ($email !== '') $cust['email'] = $email;
    return ['customer' => $cust, 'items' => [['description' => mb_substr($desc, 0, 128), 'quantity' => '1.00', 'amount' => money($amount), 'vat_code' => 1, 'payment_mode' => $mode, 'payment_subject' => 'service']]];
}
