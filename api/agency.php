<?php
// Публичные настройки агентства и занятость на сезон: для страницы записи.
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/booking.php';

$a = agency_by_slug((string)($_GET['a'] ?? ''));
if (!$a || !$a['active']) fail('Агентство не найдено', 404);

$cfg = $a['cfg'];
$season = $cfg['season'];
$cfg['live'] = true;
$cfg['occupancy'] = occupancy((int)$a['id'], $season['start'], $season['end']);
$cfg['legal']['processor'] = $APP['processor'] ?? '';
unset($cfg['telegram']); // служебное, клиенту не нужно
json_out($cfg);
