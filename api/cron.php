<?php
// Ночной запуск: копии заявок агентствам и владельцу. Запускается заданием по расписанию в панели хостинга:
//   wget -q -O - "https://anisis.ru/api/cron.php?key=КЛЮЧ"
// Ключ показан во вкладке «Агентства». Без ключа ничего не происходит. За сутки копии уходят один раз.
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/booking.php';
require dirname(__DIR__) . '/lib/notify.php';
require dirname(__DIR__) . '/lib/billing.php';
require dirname(__DIR__) . '/lib/backup.php';

if (!hash_equals(cron_key(), (string)($_GET['key'] ?? ''))) fail('Нет доступа', 403);
@set_time_limit(600);
ignore_user_abort(true);

$today = date('Y-m-d');
$last = setting_get('cronlast') ?: [];
if (($last['day'] ?? '') === $today && empty($_GET['again'])) json_out(['ok' => true, 'skipped' => 'Сегодня копии уже отправлены']);
setting_set('cronlast', ['day' => $today, 'at' => date('H:i')]);

ensure_billing_cols();
$report = ['agencies' => [], 'owner' => null];
foreach (db()->query('SELECT id FROM agencies WHERE active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $a = agency_by_id((int)$id);
    if (!$a || !empty($a['cfg']['demo'])) continue;
    try { $r = backup_agency($a); } catch (Throwable $e) { error_log('backup ' . $a['slug'] . ': ' . $e->getMessage()); $r = ['error' => true]; }
    if (($r['skipped'] ?? '') !== 'off') $report['agencies'][$a['slug']] = $r;
}
try { $report['owner'] = backup_owner(); } catch (Throwable $e) { error_log('backup owner: ' . $e->getMessage()); $report['owner'] = ['error' => true]; }
setting_set('cronlast', ['day' => $today, 'at' => date('H:i'), 'report' => $report]);
json_out(['ok' => true, 'report' => $report]);
