<?php
// Сведения о сервисе для правил и регистрации: кто оказывает услугу, контакт, даты бесплатного периода.
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/billing.php';
$land = setting_get('landing') ?: [];
$reg = setting_get('reg') ?: [];
$b = billing_settings();
json_out([
    'processor' => (string)($APP['processor'] ?? ''),
    'telegram' => preg_match('#^https://[^\s<>"]+$#', (string)($land['telegram'] ?? '')) ? $land['telegram'] : 'https://t.me/rodionfrontend',
    'email' => (string)((setting_get('owner') ?: [])['email'] ?? ''),
    'freeEnd' => ru_date($b['freeEnd']), 'seasonEnd' => ru_date($b['seasonEnd']),
    'freeText' => ru_day_month($b['freeEnd']), 'seasonText' => ru_day_month($b['seasonEnd']),
    'price' => (string)($land['pricePaidSum'] ?? '3 000 ₽'),
    'priceEarly' => $b['priceEarly'], 'priceFull' => $b['priceFull'], 'earlyHours' => $b['earlyHours'], 'soloEarly' => $b['soloEarly'], 'soloFull' => $b['soloFull'],
    // до какого дня бесплатно тому, кто зарегистрируется сегодня (включительно)
    'freeUntil' => ru_date(max(trial_for_new(), day_add($b['freeEnd'], -1))),
    'freeUntilText' => ru_day_month(max(trial_for_new(), day_add($b['freeEnd'], -1))),
    'priceMonth' => $b['priceMonth'], 'soloMonth' => $b['soloMonth'], 'trialDays' => $b['trialDays'],
    'regOpen' => ($reg['open'] ?? true) !== false,
    'site' => (string)($APP['site_url'] ?? 'https://anisis.ru'),
]);
