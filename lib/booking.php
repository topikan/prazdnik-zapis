<?php
// Расчёт цены и занятости. Логика совпадает с assets/app.js:
// браузер показывает цену заранее, но окончательно её считает только сервер.
declare(strict_types=1);

const ACTIVE_STATUSES = ['new', 'confirmed'];

function minutes_of(string $t): int { [$h, $m] = array_map('intval', explode(':', $t)); return $h * 60 + $m; }
function hhmm(int $m): string { return sprintf('%02d:%02d', intdiv($m, 60) % 24, $m % 60); }
function round100(float $n): int { return (int)(round($n / 100) * 100); }
function find_item(array $list, string $id): ?array { foreach ($list as $x) if (($x['id'] ?? null) === $id) return $x; return null; }

function slot_times(array $cfg, string $date): array {
    $from = minutes_of($cfg['hours']['from']); $to = minutes_of($cfg['hours']['to']);
    if (!empty($cfg['hours']['lastDay']) && $cfg['hours']['lastDay']['date'] === $date) $to = minutes_of($cfg['hours']['lastDay']['to']);
    $out = [];
    for ($t = $from; $t <= $to; $t += 60) $out[] = hhmm($t);
    return $out;
}

// Сколько часов занимает заказ: программа + дорога до следующего адреса
function order_span(array $cfg, string $programId): int {
    $p = find_item($cfg['programs'], $programId);
    $mins = ($p['minutes'] ?? 30) + (int)($cfg['travelMinutes'] ?? 30);
    return max(1, (int)ceil($mins / 60));
}

// Занятость: [дата][время] => сколько составов занято (заказы + ручные блокировки)
function occupancy(int $agencyId, string $from, string $to): array {
    $pdo = db();
    $occ = [];
    $in = implode(',', array_fill(0, count(ACTIVE_STATUSES), '?'));
    $st = $pdo->prepare("SELECT visit_date, visit_time, span FROM orders WHERE agency_id = ? AND visit_date BETWEEN ? AND ? AND status IN ($in)");
    $st->execute(array_merge([$agencyId, $from, $to], ACTIVE_STATUSES));
    foreach ($st as $r) {
        $start = minutes_of($r['visit_time']);
        for ($i = 0; $i < max(1, (int)$r['span']); $i++) {
            if ($start + $i * 60 >= 1440) break; // после полуночи — это уже другой день, его не трогаем
            $t = hhmm($start + $i * 60);
            $occ[$r['visit_date']][$t] = ($occ[$r['visit_date']][$t] ?? 0) + 1;
        }
    }
    $st = $pdo->prepare('SELECT visit_date, visit_time, teams FROM blocks WHERE agency_id = ? AND visit_date BETWEEN ? AND ?');
    $st->execute([$agencyId, $from, $to]);
    foreach ($st as $r) $occ[$r['visit_date']][$r['visit_time']] = ($occ[$r['visit_date']][$r['visit_time']] ?? 0) + (int)$r['teams'];
    return $occ;
}

function free_teams(array $cfg, array $occ, string $date, string $time): int {
    return max(0, (int)$cfg['pairs'] - (int)($occ[$date][$time] ?? 0));
}

function date_rule(array $cfg, string $date): array {
    foreach ($cfg['dateRules'] ?? [] as $r) if ($date >= $r['from'] && $date <= $r['to']) return $r;
    return ['mult' => 1, 'label' => ''];
}
function mult_for(array $cfg, string $date, string $time): array {
    $r = date_rule($cfg, $date); $m = (float)$r['mult']; $label = $r['label'] ?? '';
    foreach ($cfg['timeRules'] ?? [] as $t) {
        if ($t['date'] === $date && minutes_of($time) >= minutes_of($t['after']) && $t['mult'] > $m) { $m = (float)$t['mult']; $label = $t['label']; }
    }
    return [$m, $label];
}
function surge_for(array $cfg, int $free): array {
    $share = $free / max(1, (int)$cfg['pairs']); $best = ['plus' => 0, 'label' => ''];
    foreach ($cfg['demandPricing'] ?? [] as $r) if ($share <= $r['freeShareAtMost'] && $r['plus'] > $best['plus']) $best = $r;
    return $best;
}
function plural_ru(int $n, string $a, string $b, string $c): string {
    $n10 = $n % 10; $n100 = $n % 100;
    if ($n10 === 1 && $n100 !== 11) return $a;
    if ($n10 >= 2 && $n10 <= 4 && ($n100 < 10 || $n100 >= 20)) return $b;
    return $c;
}

// Итоговая цена заказа. $free — сколько составов свободно на это время ДО этого заказа.
function calc_price(array $cfg, array $o, int $free): array {
    $base = $cfg['prices'][$o['character']][$o['program']] ?? null;
    if ($base === null) throw new InvalidArgumentException('Нет такой программы');
    $char = find_item($cfg['characters'], $o['character']);
    $prog = find_item($cfg['programs'], $o['program']);
    [$mult, $label] = mult_for($cfg, $o['date'], $o['time']);
    $lines = [];
    $program = round100($base * $mult);
    $lines[] = [$char['title'] . ', ' . $prog['minutes'] . ' мин' . ($mult != 1 ? ' (' . mb_strtolower($label) . ')' : ''), $program];
    $surge = surge_for($cfg, $free);
    if ($surge['plus'] > 0) $lines[] = ['Наценка за спрос +' . round($surge['plus'] * 100) . '%', round100($program * $surge['plus'])];
    $extra = max(0, $o['kids'] - (int)$cfg['kids']['included']);
    if ($extra) $lines[] = ['Ещё ' . $extra . ' ' . plural_ru($extra, 'ребёнок', 'ребёнка', 'детей') . ' сверх ' . $cfg['kids']['included'], $extra * (int)$cfg['kids']['extraPrice']];
    if (!empty($cfg['promo']['percent']) && agency_today($cfg) <= ($cfg['promo']['until'] ?? '')) {
        $lines[] = ['Скидка за раннее бронирование −' . $cfg['promo']['percent'] . '%', -round100($program * $cfg['promo']['percent'] / 100)];
    }
    foreach ($cfg['addons'] ?? [] as $a) if (in_array($a['id'], $o['addons'], true)) $lines[] = [$a['title'], (int)$a['price']];
    $z = find_item($cfg['zones'] ?? [], $o['zone']);
    if ($z && $z['price']) $lines[] = ['Выезд: ' . mb_strtolower($z['title']), (int)$z['price']];
    $total = array_sum(array_column($lines, 1));
    return ['total' => $total, 'lines' => $lines];
}

// Часовые пояса России: агентство из Екатеринбурга видит «сегодня» и запас по времени по своему времени
const RU_TIMEZONES = [
    'Europe/Kaliningrad' => 'Калининград (МСК−1)', 'Europe/Moscow' => 'Москва (МСК)', 'Europe/Samara' => 'Самара (МСК+1)',
    'Asia/Yekaterinburg' => 'Екатеринбург (МСК+2)', 'Asia/Omsk' => 'Омск (МСК+3)', 'Asia/Krasnoyarsk' => 'Красноярск, Новосибирск (МСК+4)',
    'Asia/Irkutsk' => 'Иркутск (МСК+5)', 'Asia/Yakutsk' => 'Якутск (МСК+6)', 'Asia/Vladivostok' => 'Владивосток (МСК+7)',
    'Asia/Magadan' => 'Магадан (МСК+8)', 'Asia/Kamchatka' => 'Камчатка (МСК+9)',
];
function agency_tz(array $cfg): DateTimeZone {
    $tz = (string)($cfg['timezone'] ?? 'Europe/Moscow');
    return new DateTimeZone(isset(RU_TIMEZONES[$tz]) ? $tz : 'Europe/Moscow');
}
function agency_today(array $cfg): string { return (new DateTime('now', agency_tz($cfg)))->format('Y-m-d'); }
function agency_ts(array $cfg, string $date, string $time): int { return (new DateTime("$date $time", agency_tz($cfg)))->getTimestamp(); }

// Проверка и очистка настроек агентства перед сохранением из админки.
// Сохраняются только известные поля нужных типов и в разумных пределах: всё остальное отбрасывается.
function normalize_config(array $c, array &$err): array {
    $str = function ($v, int $max): string { return is_scalar($v) ? mb_substr(trim((string)$v), 0, $max) : ''; };
    $int = function ($v, int $min, int $max, int $def) { return is_numeric($v) ? max($min, min($max, (int)round((float)$v))) : $def; };
    $num = function ($v, float $min, float $max, float $def) { return is_numeric($v) ? max($min, min($max, round((float)$v, 3))) : $def; };
    $date = function ($v) { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : ''; };
    $time = function ($v) { return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : ''; };
    $id = function ($v) { return is_string($v) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $v) ? $v : ''; };
    $list = function ($v, int $max) { return is_array($v) ? array_slice(array_values(array_filter($v, 'is_array')), 0, $max) : []; };
    $icons = function ($v, array $allowed, string $def) { return in_array($v, $allowed, true) ? $v : $def; };

    $o = [];
    $o['name'] = $str($c['name'] ?? '', 80);
    if ($o['name'] === '') $err[] = 'Укажите название агентства';
    $o['city'] = $str($c['city'] ?? '', 60);
    $o['phone'] = $str($c['phone'] ?? '', 30);
    $o['demo'] = !empty($c['demo']);
    $L = is_array($c['legal'] ?? null) ? $c['legal'] : [];
    $o['legal'] = ['operator' => $str($L['operator'] ?? '', 200), 'inn' => $str($L['inn'] ?? '', 20), 'ogrn' => $str($L['ogrn'] ?? '', 40),
        'address' => $str($L['address'] ?? '', 300), 'email' => $str($L['email'] ?? '', 120), 'keepMonths' => $int($L['keepMonths'] ?? 12, 1, 60, 12),
        'updated' => $str($L['updated'] ?? '', 20), 'policyUrl' => $str($L['policyUrl'] ?? '', 300)];
    if ($o['legal']['email'] !== '' && !filter_var($o['legal']['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Почта для обращений: проверьте адрес';
    if ($o['legal']['policyUrl'] !== '' && !preg_match('~^https://[^\s<>"]+$~', $o['legal']['policyUrl'])) $err[] = 'Ссылка на политику должна начинаться с https://';
    $col = is_array($c['colors'] ?? null) ? $c['colors'] : [];
    $o['colors'] = [];
    foreach (['accent', 'gold'] as $k) if (!empty($col[$k])) { if (is_string($col[$k]) && preg_match('/^#[0-9a-fA-F]{6}$/', $col[$k])) $o['colors'][$k] = $col[$k]; else $err[] = 'Цвет в формате #RRGGBB'; }
    $o['leadHours'] = $int($c['leadHours'] ?? 3, 0, 72, 3);
    $o['timezone'] = isset(RU_TIMEZONES[$c['timezone'] ?? '']) ? $c['timezone'] : 'Europe/Moscow';

    $o['season'] = ['start' => $date($c['season']['start'] ?? ''), 'end' => $date($c['season']['end'] ?? '')];
    if (!$o['season']['start'] || !$o['season']['end']) $err[] = 'Укажите даты сезона';
    elseif ($o['season']['start'] > $o['season']['end']) $err[] = 'Сезон заканчивается раньше, чем начинается';
    elseif ((strtotime($o['season']['end']) - strtotime($o['season']['start'])) > 400 * 86400) $err[] = 'Сезон не длиннее года';
    $o['hours'] = ['from' => $time($c['hours']['from'] ?? ''), 'to' => $time($c['hours']['to'] ?? '')];
    if (!$o['hours']['from'] || !$o['hours']['to']) $err[] = 'Укажите часы работы';
    elseif ($o['hours']['from'] > $o['hours']['to']) $err[] = 'Первый визит позже последнего';
    $ld = $c['hours']['lastDay'] ?? null;
    if (is_array($ld) && $date($ld['date'] ?? '') && $time($ld['to'] ?? '')) $o['hours']['lastDay'] = ['date' => $ld['date'], 'to' => $ld['to']];
    $o['pairs'] = $int($c['pairs'] ?? 1, 1, 50, 1);
    $o['travelMinutes'] = $int($c['travelMinutes'] ?? 30, 0, 240, 30);

    $o['characters'] = [];
    foreach ($list($c['characters'] ?? [], 20) as $x) {
        $i = $id($x['id'] ?? ''); if ($i === '') continue;
        $o['characters'][] = ['id' => $i, 'title' => $str($x['title'] ?? '', 80), 'note' => $str($x['note'] ?? '', 120), 'icon' => $icons($x['icon'] ?? '', ['santa', 'duo', 'trio'], 'santa'), 'badge' => $str($x['badge'] ?? '', 40)];
    }
    $o['programs'] = [];
    foreach ($list($c['programs'] ?? [], 20) as $x) {
        $i = $id($x['id'] ?? ''); if ($i === '') continue;
        $o['programs'][] = ['id' => $i, 'title' => $str($x['title'] ?? '', 80), 'minutes' => $int($x['minutes'] ?? 30, 5, 600, 30), 'note' => $str($x['note'] ?? '', 160), 'icon' => $icons($x['icon'] ?? '', ['gift', 'tree', 'ball'], 'tree')];
    }
    if (!$o['characters'] || !$o['programs']) $err[] = 'Нужен хотя бы один вариант «Кто приедет» и одна программа';
    if (count(array_unique(array_column($o['characters'], 'id'))) !== count($o['characters']) || count(array_unique(array_column($o['programs'], 'id'))) !== count($o['programs'])) $err[] = 'Повторяются строки в «Кто приедет» или «Программы»';
    $o['prices'] = [];
    foreach ($o['characters'] as $ch) foreach ($o['programs'] as $p) {
        $v = $c['prices'][$ch['id']][$p['id']] ?? null;
        if (!is_numeric($v) || $v < 0) { $err[] = "Нет цены: {$ch['title']} / {$p['title']}"; continue; }
        $o['prices'][$ch['id']][$p['id']] = min(1000000, (int)round((float)$v));
    }
    $o['dateRules'] = [];
    foreach ($list($c['dateRules'] ?? [], 60) as $x) {
        $f = $date($x['from'] ?? ''); $t = $date($x['to'] ?? ''); if (!$f || !$t) continue;
        $o['dateRules'][] = ['from' => $f, 'to' => $t, 'mult' => $num($x['mult'] ?? 1, 0.1, 10, 1), 'label' => $str($x['label'] ?? '', 60)];
    }
    $o['timeRules'] = [];
    foreach ($list($c['timeRules'] ?? [], 60) as $x) {
        $d = $date($x['date'] ?? ''); $t = $time($x['after'] ?? ''); if (!$d || !$t) continue;
        $o['timeRules'][] = ['date' => $d, 'after' => $t, 'mult' => $num($x['mult'] ?? 1, 0.1, 10, 1), 'label' => $str($x['label'] ?? '', 60)];
    }
    $k = is_array($c['kids'] ?? null) ? $c['kids'] : [];
    $kmax = $int($k['max'] ?? 15, 1, 50, 15);
    $o['kids'] = ['included' => $int($k['included'] ?? 5, 0, $kmax, min(5, $kmax)), 'max' => $kmax, 'extraPrice' => $int($k['extraPrice'] ?? 0, 0, 100000, 0)];
    $o['addons'] = [];
    foreach ($list($c['addons'] ?? [], 30) as $x) {
        $i = $id($x['id'] ?? ''); if ($i === '') continue;
        $o['addons'][] = ['id' => $i, 'title' => $str($x['title'] ?? '', 80), 'note' => $str($x['note'] ?? '', 160), 'price' => $int($x['price'] ?? 0, 0, 1000000, 0), 'icon' => $str($x['icon'] ?? 'star', 20)];
    }
    $o['zones'] = [];
    foreach ($list($c['zones'] ?? [], 20) as $x) {
        $i = $id($x['id'] ?? ''); if ($i === '') continue;
        $o['zones'][] = ['id' => $i, 'title' => $str($x['title'] ?? '', 80), 'price' => $int($x['price'] ?? 0, 0, 1000000, 0)];
    }
    if (!$o['zones']) $err[] = 'Нужна хотя бы одна зона выезда';
    $o['demandPricing'] = [];
    foreach ($list($c['demandPricing'] ?? [], 10) as $x) {
        $o['demandPricing'][] = ['freeShareAtMost' => $num($x['freeShareAtMost'] ?? 0, 0, 1, 0), 'plus' => $num($x['plus'] ?? 0, 0, 3, 0), 'label' => $str($x['label'] ?? '', 80)];
    }
    $pr = $c['promo'] ?? null;
    if (is_array($pr) && is_numeric($pr['percent'] ?? null) && $pr['percent'] > 0 && $date($pr['until'] ?? '')) {
        $o['promo'] = ['percent' => $int($pr['percent'], 1, 90, 10), 'until' => $pr['until'], 'text' => $str($pr['text'] ?? '', 120)];
    }
    $mail = $str($c['notify']['email'] ?? '', 300);
    if ($mail !== '') foreach (preg_split('/[\s,;]+/', $mail) as $m) if (!filter_var($m, FILTER_VALIDATE_EMAIL)) $err[] = "Почта для уведомлений: проверьте адрес $m";
    $o['notify'] = ['email' => $mail];
    $logo = $c['logo'] ?? '';
    if (is_string($logo) && preg_match('~^uploads/logos/[a-z0-9-]+\.(webp|png|jpe?g)$~', $logo)) $o['logo'] = $logo;
    return $o;
}
function validate_config(array $c): array { $err = []; normalize_config($c, $err); return array_values(array_unique($err)); }
