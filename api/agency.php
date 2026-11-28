<?php
// Публичные настройки агентства и занятость на сезон: для страницы записи.
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/booking.php';
require dirname(__DIR__) . '/lib/billing.php';
require dirname(__DIR__) . '/lib/payments.php';
require dirname(__DIR__) . '/lib/notify.php';

ensure_billing_cols();
$a = agency_by_slug((string)($_GET['a'] ?? ''));
if (!$a) fail('Агентство не найдено', 404);
// Выключенную или закрытую за неоплату страницу само агентство (и владелец сервиса) видит в режиме просмотра
$viewer = (!$a['active'] || billing_closed($a)) ? session_peek() : [];
$preview = $viewer && ($viewer['role'] === 'super' || $viewer['agency'] === (int)$a['id']);
if (!$a['active'] && !$preview) fail('Агентство не найдено', 404);

// Демо один раз получает поводы «Новый год» и «День рождения» из шаблона; свои фото и тексты демо не трогаем
if ($a['slug'] === 'demo' && !setting_get('demo_occasions')) {
    $tpl = json_decode((string)file_get_contents(APP_ROOT . '/agencies/demo.json'), true) ?: [];
    $c = $a['cfg'];
    foreach (['characters', 'programs', 'addons'] as $k) {
        $have = array_column($c[$k] ?? [], 'id');
        foreach ($tpl[$k] ?? [] as $x) if (!in_array($x['id'], $have, true)) $c[$k][] = $x;
    }
    foreach ($tpl['prices'] ?? [] as $ci => $row) foreach ($row as $pi => $v) if (!isset($c['prices'][$ci][$pi])) $c['prices'][$ci][$pi] = $v;
    $c['occasions'] = $tpl['occasions'] ?? [];
    $c['season'] = $tpl['season'] ?? $c['season'];
    db()->prepare('UPDATE agencies SET config = ? WHERE id = ?')->execute([json_encode($c, JSON_UNESCAPED_UNICODE), (int)$a['id']]);
    $a['cfg'] = $c;
    setting_set('demo_occasions', ['at' => date('c')]);
}
// Демо, второй шаг: зимние фото первого экрана — только у Нового года, баннер — для любого праздника
if ($a['slug'] === 'demo' && !setting_get('demo_occasions2')) {
    $c = $a['cfg'];
    $c['design'] = (is_array($c['design'] ?? null) ? $c['design'] : []) + ['ownWinterOnly' => true];
    $c['design']['ownWinterOnly'] = true;
    if (mb_strpos((string)($c['banner']['title'] ?? ''), 'Новый год') !== false) $c['banner'] = ['title' => 'Подарите ребёнку настоящий праздник!', 'text' => 'Забронируйте праздник — мы приедем к вам домой.', 'button' => 'Забронировать праздник', 'link' => (string)($c['banner']['link'] ?? '')];
    db()->prepare('UPDATE agencies SET config = ? WHERE id = ?')->execute([json_encode($c, JSON_UNESCAPED_UNICODE), (int)$a['id']]);
    $a['cfg'] = $c;
    setting_set('demo_occasions2', ['at' => date('c')]);
}
$cfg = $a['cfg'];
unset($cfg['telegram'], $cfg['notify'], $cfg['backup']); // служебное: почта и пароль копий посетителям не показываются
if (billing_closed($a) && !$preview) {
    // Запись закрыта: показываем только название, оформление и телефон, чтобы клиент мог позвонить
    $keep = array_intersect_key($cfg, array_flip(['name', 'city', 'phone', 'logo', 'heroImage', 'colors', 'legal']));
    json_out($keep + ['closed' => true, 'live' => true]);
}
$season = $cfg['season'] = season_window($cfg); // «круглый год» — окно от сегодняшнего дня
$cfg['live'] = true;
$cfg['kind'] = ($a['kind'] ?? 'agency') === 'solo' ? 'solo' : 'agency';
if ($preview) $cfg['preview'] = !$a['active'] ? 'off' : 'closed';
$cfg['occupancy'] = occupancy((int)$a['id'], $season['start'], $season['end'], $cfg);
$cfg['legal']['processor'] = $APP['processor'] ?? '';
// Предоплата: посетителю — только включена ли и как считается сумма; ключи магазина остаются на сервере
$p = $cfg['payment'] ?? [];
$online = prepay_online($a);
$cfg['payment'] = !empty($p['enabled']) && ($online || (($p['mode'] ?? 'link') === 'link' && !empty($p['link'])))
    ? ($online ? ['enabled' => true, 'mode' => 'yookassa', 'ykType' => $p['ykType'] ?? 'fixed', 'ykValue' => (int)($p['ykValue'] ?? 0)]
               : ['enabled' => true, 'mode' => 'link', 'link' => $p['link'], 'amount' => $p['amount'] ?? ''])
    : ['enabled' => false];
try { tg_poll_later(30); } catch (Throwable $e) {} // заодно ответить тем, кто написал боту
json_out($cfg);
