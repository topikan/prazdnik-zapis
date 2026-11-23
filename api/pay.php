<?php
// Предоплата клиента за праздник: деньги идут сразу в ЮKassa агентства.
// Браузер присылает только токен заявки (выдаётся при оформлении); сумму считает сервер.
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/booking.php';
require dirname(__DIR__) . '/lib/notify.php';
require dirname(__DIR__) . '/lib/billing.php';
require dirname(__DIR__) . '/lib/payments.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Нужен POST', 405);
$in = body_json();
$action = (string)($_GET['action'] ?? '');
ensure_pay_tables();

$token = in_str($in, 'token');
if (!preg_match('/^[a-f0-9]{48}$/', $token)) fail('Ссылка на оплату устарела', 404);
rate_limit('pay', 20, 600);
$st = db()->prepare('SELECT id, agency_id, total, prepaid, status, phone FROM orders WHERE pay_token = ?');
$st->execute([hash('sha256', $token)]);
$o = $st->fetch();
if (!$o) { fail_hit('paybad'); fail('Заявка не найдена', 404); }
$a = agency_by_id((int)$o['agency_id']);
if (!$a || !$a['active']) fail('Агентство не найдено', 404);
$due = prepay_amount($a['cfg'], (int)$o['total']);
$left = max(0, $due - (int)$o['prepaid']);

if ($action === 'start') {
    if (!prepay_online($a)) fail('Онлайн-оплата у агентства сейчас выключена. Свяжитесь с агентством.');
    if ($o['status'] === 'cancelled') fail('Заявка отменена');
    if ($left < 1) json_out(['paid' => true, 'prepaid' => (int)$o['prepaid']]);
    $cred = agency_cred((int)$a['id']);
    // недавний неоплаченный платёж на ту же сумму — отдаём его же, чтобы двойной клик не создал два платежа
    $pp = db()->prepare("SELECT conf_url FROM payments WHERE kind = 'order' AND order_id = ? AND status = 'pending' AND amount = ? AND shop = ? AND created_at > ? ORDER BY created_at DESC LIMIT 1");
    $pp->execute([(int)$o['id'], $left, $cred['shop'], date('Y-m-d H:i:s', time() - 1800)]);
    $url = $pp->fetchColumn();
    if ($url) json_out(['url' => $url]);
    $desc = 'Предоплата за праздник, заявка № ' . (int)$o['id'] . ', ' . mb_substr((string)($a['cfg']['name'] ?? ''), 0, 60);
    $r = yk_create('order', $cred, (int)$a['id'], (int)$o['id'], $left, $desc, site_url() . '/' . $a['slug'] . '?pay=' . $token,
        $cred['receipt'] ? receipt_for($desc, $left, (string)$o['phone'], '', 'prepayment') : null);
    if (isset($r['error'])) fail($r['error'], 502);
    json_out(['url' => $r['url']]);
}

if ($action === 'check') {
    // последний платёж по заявке — спрашиваем ЮKassa (на случай, если уведомление ещё не дошло)
    $pp = db()->prepare("SELECT id FROM payments WHERE kind = 'order' AND order_id = ? ORDER BY created_at DESC LIMIT 1");
    $pp->execute([(int)$o['id']]);
    $pid = (string)$pp->fetchColumn();
    $status = $pid !== '' ? yk_apply($pid) : 'none';
    $st->execute([hash('sha256', $token)]); $o = $st->fetch();
    json_out(['status' => $status, 'prepaid' => (int)$o['prepaid'], 'due' => $due, 'paid' => (int)$o['prepaid'] >= $due && $due > 0, 'order' => (int)$o['id'], 'agency' => (string)($a['cfg']['name'] ?? '')]);
}
fail('Неизвестное действие');
