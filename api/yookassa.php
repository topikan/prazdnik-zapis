<?php
// Уведомления ЮKassa о платежах (адрес указывается в настройках магазина: https://anisis.ru/api/yookassa.php).
// Содержимому уведомления не верим: берём из него только номер платежа и спрашиваем ЮKassa сами, с нашими ключами.
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/booking.php';
require dirname(__DIR__) . '/lib/notify.php';
require dirname(__DIR__) . '/lib/billing.php';
require dirname(__DIR__) . '/lib/payments.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Нужен POST', 405);
$raw = (string)file_get_contents('php://input', false, null, 0, 65536);
$j = json_decode($raw, true);
$pid = is_array($j) ? (string)($j['object']['id'] ?? '') : '';
if ($pid === '') json_out(['ok' => true]);
$res = yk_apply($pid);
// Если не смогли спросить ЮKassa — отвечаем ошибкой, и ЮKassa повторит уведомление позже
if ($res === 'error') json_out(['ok' => false], 503);
json_out(['ok' => true]);
