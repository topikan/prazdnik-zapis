<?php
// Приём заявки. Сервер сам проверяет всё и сам считает цену: то, что прислал браузер, — только пожелания клиента.
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/booking.php';
require dirname(__DIR__) . '/lib/notify.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Нужен POST', 405);
$in = body_json();

// Ловушка для ботов: поле, которое человек не видит и не заполняет
if (!empty($in['website'])) json_out(['ok' => true, 'id' => 0]);

rate_limit('order', 8, 600);

$a = agency_by_slug((string)($in['agency'] ?? ''));
if (!$a || !$a['active']) fail('Агентство не найдено', 404);
$cfg = $a['cfg'];
if (!empty($cfg['demo'])) fail('Это демо-версия, заявки не принимаются', 403);

// --- проверка полей ---
$str = function ($v, int $max): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$v)), 0, $max); };
foreach (['character', 'program', 'date', 'time', 'zone', 'name', 'phone', 'address', 'comment'] as $k) if (isset($in[$k]) && !is_scalar($in[$k])) fail('Проверьте заполнение формы');
$o = [
    'character' => (string)($in['character'] ?? ''),
    'program'   => (string)($in['program'] ?? ''),
    'date'      => (string)($in['date'] ?? ''),
    'time'      => (string)($in['time'] ?? ''),
    'kids'      => (int)($in['kids'] ?? 1),
    'addons'    => array_values(array_filter((array)($in['addons'] ?? []), 'is_string')),
    'zone'      => (string)($in['zone'] ?? ''),
];
$name = $str($in['name'] ?? '', 60);
$phoneDigits = preg_replace('/\D/', '', (string)($in['phone'] ?? ''));
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
if (!find_item($cfg['zones'], $o['zone'])) fail('Выберите, куда ехать');
if ($o['kids'] < 1 || $o['kids'] > (int)$cfg['kids']['max']) fail('Неверное количество детей');
$known = array_column($cfg['addons'] ?? [], 'id');
$o['addons'] = array_values(array_unique(array_intersect($o['addons'], $known)));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $o['date']) || $o['date'] < $cfg['season']['start'] || $o['date'] > $cfg['season']['end']) fail('Эта дата недоступна');
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

    $occ = occupancy((int)$a['id'], $o['date'], $o['date']);
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
    $data = ['character' => $o['character'], 'program' => $o['program'], 'kids' => $o['kids'], 'addons' => $o['addons'], 'zone' => $o['zone'], 'children' => $children, 'comment' => $comment];
    $pdo->prepare('INSERT INTO orders (agency_id, visit_date, visit_time, span, data, name, phone, address, total, price_lines, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$a['id'], $o['date'], $o['time'], $span, json_encode($data, JSON_UNESCAPED_UNICODE), $name, $phone, $address, $price['total'], json_encode($price['lines'], JSON_UNESCAPED_UNICODE), 'new', date('Y-m-d H:i:s')]);
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
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['ok' => true, 'id' => $id, 'total' => $price['total'], 'lines' => $price['lines']], JSON_UNESCAPED_UNICODE);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
try { notify_order($a, $id, $o, (int)$price['total']); } catch (Throwable $e) { error_log('notify: ' . $e->getMessage()); }
exit;
