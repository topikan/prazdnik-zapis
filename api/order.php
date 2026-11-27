<?php
// Приём заявки. Сервер сам проверяет всё и сам считает цену: то, что прислал браузер, — только пожелания клиента.
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/booking.php';
require dirname(__DIR__) . '/lib/notify.php';
require dirname(__DIR__) . '/lib/billing.php';
require dirname(__DIR__) . '/lib/payments.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Нужен POST', 405);
$in = body_json();

// Ловушка для ботов: поле, которое человек не видит и не заполняет
// Ловушка для ботов: скрытое поле с ничего не значащим именем. Старое имя «website» Chrome заполнял сам, и заявки людей пропадали
if (!empty($in['trap'])) json_out(['ok' => true, 'id' => 0]);

rate_limit('order', 8, 600);

$a = agency_by_slug(in_str($in, 'agency'));
if (!$a || !$a['active']) fail('Агентство не найдено', 404);
$cfg = $a['cfg'];
ensure_pay_tables();
if (!empty($cfg['demo'])) fail('Это демо-версия, заявки не принимаются', 403);
ensure_billing_cols();
if (billing_closed($a)) fail('Онлайн-запись временно недоступна.' . (!empty($cfg['phone']) ? ' Позвоните, пожалуйста: ' . $cfg['phone'] : ''), 403);

// --- проверка полей ---
$str = function ($v, int $max): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$v)), 0, $max); };
foreach (['character', 'program', 'date', 'time', 'zone', 'name', 'phone', 'address', 'comment'] as $k) if (isset($in[$k]) && !is_scalar($in[$k])) fail('Проверьте заполнение формы');
$o = [
    'character' => in_str($in, 'character'),
    'program'   => in_str($in, 'program'),
    'date'      => in_str($in, 'date'),
    'time'      => in_str($in, 'time'),
    'kids'      => (int)($in['kids'] ?? 1),
    'addons'    => array_values(array_filter((array)($in['addons'] ?? []), 'is_string')),
    'zone'      => in_str($in, 'zone'),
];
$name = $str($in['name'] ?? '', 60);
$phoneDigits = preg_replace('/\D/', '', in_str($in, 'phone'));
if (strlen($phoneDigits) === 11 && $phoneDigits[0] === '8') $phoneDigits = '7' . substr($phoneDigits, 1);
$address = $str($in['address'] ?? '', 200);
$comment = $str($in['comment'] ?? '', 500);
$children = [];
foreach (array_slice(is_array($in['children'] ?? null) ? $in['children'] : [], 0, (int)$cfg['kids']['max']) as $c) {
    if (!is_array($c)) continue;
    $cn = $str($c['name'] ?? '', 40); $ca = preg_replace('/\D/', '', (string)($c['age'] ?? ''));
    if ($cn !== '' || $ca !== '') $children[] = ['name' => $cn, 'age' => substr($ca, 0, 2)];
}

if (!find_item($cfg['characters'], $o['character']) || !find_item($cfg['programs'], $o['program'])) fail('Выберите персонажей и программу');
if (!empty(find_item($cfg['characters'], $o['character'])['off']) || !empty(find_item($cfg['programs'], $o['program'])['off'])) fail('Этот вариант сейчас недоступен, выберите другой');
if (!find_item($cfg['zones'], $o['zone'])) fail('Выберите, куда ехать');
if ($o['kids'] < 1 || $o['kids'] > (int)$cfg['kids']['max']) fail('Неверное количество детей');
$known = array_column(array_filter($cfg['addons'] ?? [], function ($x) { return empty($x['off']); }), 'id'); // скрытые допы не принимаются
$o['addons'] = array_values(array_unique(array_intersect($o['addons'], $known)));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $o['date']) || !checkdate((int)substr($o['date'], 5, 2), (int)substr($o['date'], 8, 2), (int)substr($o['date'], 0, 4)) || $o['date'] < $cfg['season']['start'] || $o['date'] > $cfg['season']['end']) fail('Эта дата недоступна');
if (!in_array($o['time'], slot_times($cfg, $o['date']), true)) fail('Это время недоступно');
$lead = max(0, min(72, (int)($cfg['leadHours'] ?? 3)));
if (agency_ts($cfg, $o['date'], $o['time']) < time() + $lead * 3600)
    fail($lead ? "Это время уже недоступно: заказ принимается минимум за $lead ч. Выберите другое." : 'Это время уже прошло. Выберите другое.');
if ($name === '') fail('Укажите имя');
if (strlen($phoneDigits) !== 11 || $phoneDigits[0] !== '7') fail('Проверьте номер телефона');
if ($address === '') fail('Укажите адрес');
if (empty($in['consent'])) fail('Нужно согласие на обработку персональных данных');

$phone = '+' . $phoneDigits;

// Защита от забивания расписания фальшивыми заявками: не больше 3 активных заявок с одного номера в агентстве
$st = db()->prepare("SELECT COUNT(*) FROM orders WHERE agency_id = ? AND phone = ? AND status IN ('new','confirmed') AND visit_date >= ?");
$st->execute([$a['id'], $phone, agency_today($cfg)]);
if ((int)$st->fetchColumn() >= 3) fail('С этого номера уже есть несколько заявок. Позвоните в агентство, если нужно ещё.', 429);
$span = order_span($cfg, $o['program']);

// --- бронь под блокировкой, чтобы последнее окно не забрали двое ---
$pdo = db();
try {
    if (is_mysql()) { $pdo->beginTransaction(); $pdo->prepare('SELECT id FROM agencies WHERE id = ? FOR UPDATE')->execute([$a['id']]); }
    else { $pdo->exec('BEGIN IMMEDIATE'); }

    $occ = occupancy((int)$a['id'], $o['date'], $o['date'], $cfg);
    $times = slot_times($cfg, $o['date']);
    $startIdx = array_search($o['time'], $times, true);
    $free = free_teams($cfg, $occ, $o['date'], $o['time']);
    // заказ занимает несколько часов подряд (программа + дорога): нужны свободные составы на все эти часы
    for ($i = 0; $i < $span; $i++) {
        $t = hhmm(minutes_of($o['time']) + $i * 60);
        if ($i > 0 && !in_array($t, $times, true)) break; // после последнего часа работы — уже не важно
        if (free_teams($cfg, $occ, $o['date'], $t) < 1) {
            if (is_mysql()) $pdo->rollBack(); else $pdo->exec('ROLLBACK');
            fail('Это время только что заняли. Выберите другое.', 409);
        }
    }

    $price = calc_price($cfg, $o, $free);
    // Клиент видел одну цену, а пока он заполнял форму, время стали разбирать и цена выросла: не оформляем молча
    $expected = $in['expectedTotal'] ?? null;
    if (is_numeric($expected) && (int)$expected !== (int)$price['total']) {
        if (is_mysql()) $pdo->rollBack(); else $pdo->exec('ROLLBACK');
        json_out(['error' => 'Пока вы заполняли форму, цена на это время изменилась: теперь ' . number_format((int)$price['total'], 0, '', ' ') . ' ₽. Проверьте итог внизу и нажмите «Заказать» ещё раз.', 'priceChanged' => true, 'total' => $price['total']], 409);
    }
    $data = ['character' => $o['character'], 'program' => $o['program'], 'kids' => $o['kids'], 'addons' => $o['addons'], 'zone' => $o['zone'], 'children' => $children, 'comment' => $comment];
    // токен для онлайн-предоплаты: знает только этот клиент, в базе хранится его хеш
    $payToken = bin2hex(random_bytes(24));
    $pdo->prepare('INSERT INTO orders (agency_id, visit_date, visit_time, span, data, name, phone, address, total, price_lines, status, created_at, pay_token) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$a['id'], $o['date'], $o['time'], $span, json_encode($data, JSON_UNESCAPED_UNICODE), $name, $phone, $address, $price['total'], json_encode($price['lines'], JSON_UNESCAPED_UNICODE), 'new', date('Y-m-d H:i:s'), hash('sha256', $payToken)]);
    $id = (int)$pdo->lastInsertId();
    if (is_mysql()) $pdo->commit(); else $pdo->exec('COMMIT');
} catch (Throwable $e) {
    if (is_mysql() && $pdo->inTransaction()) $pdo->rollBack();
    elseif (!is_mysql()) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $x) {} }
    error_log('order: ' . $e->getMessage());
    fail('Не удалось сохранить заявку. Позвоните нам, пожалуйста.', 500);
}

// Удаляем персональные данные старых заказов по сроку из политики (по умолчанию 12 месяцев после визита)
if (random_int(1, 20) === 1) {
    $months = (int)($cfg['legal']['keepMonths'] ?? 12);
    db()->prepare('DELETE FROM orders WHERE agency_id = ? AND visit_date < ?')->execute([$a['id'], date('Y-m-d', strtotime("-$months months"))]);
}

// Ответ клиенту сразу, уведомления агентству — после, чтобы не задерживать страницу
$prepay = prepay_online($a) ? prepay_amount($cfg, (int)$price['total']) : 0;
respond_early(['ok' => true, 'id' => $id, 'total' => $price['total'], 'lines' => $price['lines'], 'payToken' => $prepay > 0 ? $payToken : '', 'prepay' => $prepay]);
try { notify_order($a, $id, $o, (int)$price['total']); } catch (Throwable $e) { error_log('notify: ' . $e->getMessage()); }
exit;
