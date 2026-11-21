<?php
// Публичные настройки агентства и занятость на сезон: для страницы записи.
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/booking.php';
require dirname(__DIR__) . '/lib/billing.php';

ensure_billing_cols();
$a = agency_by_slug((string)($_GET['a'] ?? ''));
if (!$a) fail('Агентство не найдено', 404);
// Выключенную или закрытую за неоплату страницу само агентство (и владелец сервиса) видит в режиме просмотра
$viewer = (!$a['active'] || billing_closed($a)) ? session_peek() : [];
$preview = $viewer && ($viewer['role'] === 'super' || $viewer['agency'] === (int)$a['id']);
if (!$a['active'] && !$preview) fail('Агентство не найдено', 404);

$cfg = $a['cfg'];
unset($cfg['telegram'], $cfg['notify'], $cfg['backup']); // служебное: почта и пароль копий посетителям не показываются
if (billing_closed($a) && !$preview) {
    // Запись закрыта: показываем только название, оформление и телефон, чтобы клиент мог позвонить
    $keep = array_intersect_key($cfg, array_flip(['name', 'city', 'phone', 'logo', 'heroImage', 'colors', 'legal']));
    json_out($keep + ['closed' => true, 'live' => true]);
}
$season = $cfg['season'];
$cfg['live'] = true;
if ($preview) $cfg['preview'] = !$a['active'] ? 'off' : 'closed';
$cfg['occupancy'] = occupancy((int)$a['id'], $season['start'], $season['end']);
$cfg['legal']['processor'] = $APP['processor'] ?? '';
json_out($cfg);
