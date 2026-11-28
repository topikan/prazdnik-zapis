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

// Сколько выездов работает в этот день и час: обычное число из настроек или исключение на этот день
// (capDays: {"2026-12-31": [{"from":"00:00","pairs":4},{"from":"17:00","pairs":2}]}; действует последнее правило, начавшееся не позже часа)
function cap_at(array $cfg, string $date, string $time): int {
    $n = (int)($cfg['pairs'] ?? 1);
    foreach ($cfg['capDays'][$date] ?? [] as $r) if (minutes_of($r['from']) <= minutes_of($time)) $n = (int)$r['pairs'];
    return $n;
}
function cap_max(array $cfg): int {
    $n = (int)($cfg['pairs'] ?? 1);
    foreach ($cfg['capDays'] ?? [] as $day) foreach ($day as $r) $n = max($n, (int)$r['pairs']);
    return $n;
}

// Занятые отрезки времени: заказы (программа + дорога) и закрытые вручную часы. [дата] => [[начало, конец, выездов], ...]
function busy_intervals(int $agencyId, string $from, string $to): array {
    $pdo = db();
    $out = [];
    $in = implode(',', array_fill(0, count(ACTIVE_STATUSES), '?'));
    $st = $pdo->prepare("SELECT visit_date, visit_time, span FROM orders WHERE agency_id = ? AND visit_date BETWEEN ? AND ? AND status IN ($in)");
    $st->execute(array_merge([$agencyId, $from, $to], ACTIVE_STATUSES));
    foreach ($st as $r) { $s = minutes_of($r['visit_time']); $out[$r['visit_date']][] = [$s, min(1440, $s + max(1, (int)$r['span']) * 60), 1]; }
    $st = $pdo->prepare('SELECT visit_date, visit_time, teams FROM blocks WHERE agency_id = ? AND visit_date BETWEEN ? AND ?');
    $st->execute([$agencyId, $from, $to]);
    foreach ($st as $r) { $s = minutes_of($r['visit_time']); $out[$r['visit_date']][] = [$s, $s + 60, (int)$r['teams']]; }
    return $out;
}
// Сколько выездов одновременно занято в самый загруженный момент отрезка [s, e)
function busy_peak(array $list, int $s, int $e): int {
    $points = [$s];
    foreach ($list as $iv) if ($iv[0] > $s && $iv[0] < $e) $points[] = $iv[0];
    $peak = 0;
    foreach ($points as $p) { $n = 0; foreach ($list as $iv) if ($iv[0] <= $p && $iv[1] > $p) $n += $iv[2]; $peak = max($peak, $n); }
    return $peak;
}
// Занятость по кнопкам времени: [дата][время] => сколько выездов занято в этот час.
// Считается по пересечению отрезков, поэтому закрытые часы и заказы не «теряются», если поменять первый визит (10:00 → 03:30).
function occupancy(int $agencyId, string $from, string $to, array $cfg): array {
    $occ = [];
    foreach (busy_intervals($agencyId, $from, $to) as $date => $list) {
        foreach (slot_times($cfg, $date) as $t) {
            $m = minutes_of($t);
            $n = busy_peak($list, $m, $m + 60);
            if ($n > 0) $occ[$date][$t] = $n;
        }
    }
    return $occ;
}

function free_teams(array $cfg, array $occ, string $date, string $time): int {
    return max(0, cap_at($cfg, $date, $time) - (int)($occ[$date][$time] ?? 0));
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
function surge_for(array $cfg, int $free, int $cap = 0): array {
    $share = $free / max(1, $cap ?: (int)$cfg['pairs']); $best = ['plus' => 0, 'label' => ''];
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
    $surge = surge_for($cfg, $free, cap_at($cfg, $o['date'], $o['time']));
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

// Когда принимаются заказы. «Круглый год» (season.ahead = N дней): с сегодняшнего дня на N дней вперёд, каждый день сдвигается сам.
// Иначе — заданные даты сезона.
function season_window(array $cfg): array {
    $ahead = (int)($cfg['season']['ahead'] ?? 0);
    if ($ahead > 0) { $t = agency_today($cfg); return ['start' => $t, 'end' => (new DateTime($t))->modify("+$ahead day")->format('Y-m-d'), 'ahead' => $ahead]; }
    return ['start' => (string)($cfg['season']['start'] ?? ''), 'end' => (string)($cfg['season']['end'] ?? '')];
}

// Поводы («Новый год», «День рождения»…): у каждого свои персонажи и программы и, по желанию, даты показа ДД.ММ, каждый год.
// Даты через Новый год (01.12 — 15.01) тоже работают.
function md_num(string $md): int { return preg_match('/^(\d{2})\.(\d{2})$/', $md, $m) ? (int)$m[2] * 100 + (int)$m[1] : 0; }
function occasion_has_date(array $oc, string $date): bool {
    $f = md_num((string)($oc['from'] ?? '')); $t = md_num((string)($oc['to'] ?? ''));
    if (!$f || !$t) return true;
    $x = (int)substr($date, 5, 2) * 100 + (int)substr($date, 8, 2);
    return $f <= $t ? ($x >= $f && $x <= $t) : ($x >= $f || $x <= $t);
}
function visible_occasions(array $cfg): array {
    return array_values(array_filter($cfg['occasions'] ?? [], function ($x) { return empty($x['off']); }));
}

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
    $o['logoLink'] = $str($c['logoLink'] ?? '', 300);
    if ($o['logoLink'] !== '' && !preg_match('~^https://[^\s<>"]+$~', $o['logoLink'])) $err[] = 'Ссылка с логотипа должна начинаться с https://';
    if ($o['logoLink'] === '') unset($o['logoLink']);
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
    $ahead = $int($c['season']['ahead'] ?? 0, 0, 400, 0);
    if ($ahead > 0) {
        // круглый год: даты сезона не нужны, но храним текущее окно — по нему кабинет подставляет даты по умолчанию
        $o['season']['ahead'] = max(14, $ahead);
        $w = season_window($o + ['timezone' => $c['timezone'] ?? 'Europe/Moscow']);
        $o['season']['start'] = $w['start']; $o['season']['end'] = $w['end'];
    }
    elseif (!$o['season']['start'] || !$o['season']['end']) $err[] = 'Укажите даты сезона';
    elseif ($o['season']['start'] > $o['season']['end']) $err[] = 'Сезон заканчивается раньше, чем начинается';
    elseif ((strtotime($o['season']['end']) - strtotime($o['season']['start'])) > 400 * 86400) $err[] = 'Сезон не длиннее года';
    $o['hours'] = ['from' => $time($c['hours']['from'] ?? ''), 'to' => $time($c['hours']['to'] ?? '')];
    if (!$o['hours']['from'] || !$o['hours']['to']) $err[] = 'Укажите часы работы';
    elseif ($o['hours']['from'] > $o['hours']['to']) $err[] = 'Первый визит позже последнего';
    $ld = $c['hours']['lastDay'] ?? null;
    if (is_array($ld) && $date($ld['date'] ?? '') && $time($ld['to'] ?? '')) $o['hours']['lastDay'] = ['date' => $ld['date'], 'to' => $ld['to']];
    $o['pairs'] = $int($c['pairs'] ?? 1, 1, 50, 1);
    // исключения по дням: другое число выездов на весь день или с определённого часа (0 — в это время не работаем)
    $o['capDays'] = [];
    if (is_array($c['capDays'] ?? null)) foreach (array_slice($c['capDays'], 0, 400, true) as $d => $rules) {
        if (!$date((string)$d) || !is_array($rules)) continue;
        $day = [];
        foreach (array_slice(array_values(array_filter($rules, 'is_array')), 0, 12) as $r) {
            $f = $time($r['from'] ?? ''); if ($f === '') continue;
            $day[$f] = ['from' => $f, 'pairs' => $int($r['pairs'] ?? 0, 0, 50, 0)];
        }
        if ($day) { ksort($day); $o['capDays'][(string)$d] = array_values($day); }
    }
    if (!$o['capDays']) unset($o['capDays']);
    $o['travelMinutes'] = $int($c['travelMinutes'] ?? 30, 0, 240, 30);

    $o['characters'] = [];
    foreach ($list($c['characters'] ?? [], 20) as $x) {
        $i = $id($x['id'] ?? ''); if ($i === '') continue;
        $row = ['id' => $i, 'title' => $str($x['title'] ?? '', 80), 'note' => $str($x['note'] ?? '', 120), 'icon' => $icons($x['icon'] ?? '', ['santa', 'duo', 'trio', 'party', 'hero', 'pair', 'logo'], 'santa'), 'badge' => $str($x['badge'] ?? '', 40)];
        if (is_string($x['photo'] ?? null) && preg_match('~^uploads/chars/[a-z0-9-]+\.(webp|png|jpe?g)$~', $x['photo'])) $row['photo'] = $x['photo'];
        // какую часть фото показывать в карточке: 0 — верх, 50 — середина, 100 — низ
        if (isset($x['pos']) && is_numeric($x['pos']) && in_array((int)$x['pos'], [0, 25, 75, 100], true)) $row['pos'] = (int)$x['pos'];
        if (!empty($x['off'])) $row['off'] = true; // скрыт со страницы записи, но остаётся в кабинете
        $o['characters'][] = $row;
    }
    $o['programs'] = [];
    foreach ($list($c['programs'] ?? [], 20) as $x) {
        $i = $id($x['id'] ?? ''); if ($i === '') continue;
        $pg = ['id' => $i, 'title' => $str($x['title'] ?? '', 80), 'minutes' => $int($x['minutes'] ?? 30, 5, 600, 30), 'note' => $str($x['note'] ?? '', 160), 'icon' => $icons($x['icon'] ?? '', ['gift', 'tree', 'ball', 'cake', 'balloon', 'star', 'music', 'own', 'none'], 'tree')];
        if (is_string($x['img'] ?? null) && preg_match('~^uploads/chars/[a-z0-9-]+\.(webp|png|jpe?g)$~', $x['img'])) $pg['img'] = $x['img'];
        if ($pg['icon'] === 'own' && empty($pg['img'])) $pg['icon'] = 'gift';   // «своя картинка», но её не загрузили
        $o['programs'][] = $pg + (!empty($x['off']) ? ['off' => true] : []);
    }
    if (!$o['characters'] || !$o['programs']) $err[] = 'Нужен хотя бы один вариант «Кто приедет» и одна программа';
    elseif (!array_filter($o['characters'], function ($x) { return empty($x['off']); }) || !array_filter($o['programs'], function ($x) { return empty($x['off']); })) $err[] = 'Хотя бы один состав и одна программа должны быть показаны на странице';
    if (count(array_unique(array_column($o['characters'], 'id'))) !== count($o['characters']) || count(array_unique(array_column($o['programs'], 'id'))) !== count($o['programs'])) $err[] = 'Повторяются строки в «Кто приедет» или «Программы»';
    // Поводы: какие персонажи и программы показывать в каждом
    $o['occasions'] = [];
    $md = function ($v) {
        if (!is_string($v) || !preg_match('/^\s*(\d{1,2})[.\/-](\d{1,2})\s*$/', $v, $m)) return '';
        return checkdate((int)$m[2], (int)$m[1], 2024) ? sprintf('%02d.%02d', (int)$m[1], (int)$m[2]) : '';
    };
    $charIds = array_column($o['characters'], 'id'); $progIds = array_column($o['programs'], 'id');
    $addonIds = array_values(array_filter(array_map(function ($x) use ($id) { return is_array($x) ? $id($x['id'] ?? '') : ''; }, is_array($c['addons'] ?? null) ? $c['addons'] : [])));
    foreach ($list($c['occasions'] ?? [], 12) as $x) {
        $i = $id($x['id'] ?? ''); if ($i === '') continue;
        $oc = ['id' => $i, 'title' => $str($x['title'] ?? '', 40),
            'chars' => array_values(array_intersect(array_unique(array_filter((array)($x['chars'] ?? []), 'is_string')), $charIds)),
            'progs' => array_values(array_intersect(array_unique(array_filter((array)($x['progs'] ?? []), 'is_string')), $progIds)),
            'from' => $md($x['from'] ?? ''), 'to' => $md($x['to'] ?? '')];
        // доп. услуги повода; ничего не отмечено — показываются все
        $adds = array_values(array_intersect(array_unique(array_filter((array)($x['addons'] ?? []), 'is_string')), $addonIds));
        if ($adds) $oc['addons'] = $adds;
        if ($oc['title'] === '') { $err[] = 'У повода нет названия'; continue; }
        if (($oc['from'] === '') !== ($oc['to'] === '')) $err[] = "Повод «{$oc['title']}»: укажите обе даты показа или ни одной";
        if (($x['from'] ?? '') !== '' && $oc['from'] === '' || ($x['to'] ?? '') !== '' && $oc['to'] === '') $err[] = "Повод «{$oc['title']}»: даты показа в виде ДД.ММ, например 01.12";
        if (!empty($x['winter'])) $oc['winter'] = true; // зимнее оформление: снег, зимний фон, Дед Мороз
        if (!empty($x['off'])) $oc['off'] = true;
        elseif (!$oc['chars'] || !$oc['progs']) $err[] = "Повод «{$oc['title']}»: отметьте хотя бы одного персонажа и одну программу";
        $o['occasions'][] = $oc;
    }
    if ($o['occasions'] && !array_filter($o['occasions'], function ($x) { return empty($x['off']); })) $err[] = 'Хотя бы один повод должен быть показан на странице';
    if (count(array_unique(array_column($o['occasions'], 'id'))) !== count($o['occasions'])) $err[] = 'Повторяются строки в «Поводах»';
    // Цены: без поводов — на каждое сочетание; с поводами — только на сочетания, которые клиент может выбрать
    $need = [];
    $vis = array_filter($o['occasions'], function ($x) { return empty($x['off']); });
    if ($vis) { foreach ($vis as $oc) foreach ($oc['chars'] as $ci) foreach ($oc['progs'] as $pi) $need[$ci][$pi] = true; }
    else foreach ($o['characters'] as $ch) foreach ($o['programs'] as $p) $need[$ch['id']][$p['id']] = true;
    if (!$o['occasions']) unset($o['occasions']);
    $o['prices'] = [];
    foreach ($o['characters'] as $ch) foreach ($o['programs'] as $p) {
        $v = $c['prices'][$ch['id']][$p['id']] ?? null;
        $ok = is_numeric($v) && $v >= 0;
        if (!$ok) { if (!empty($need[$ch['id']][$p['id']])) $err[] = "Нет цены: {$ch['title']} / {$p['title']}"; continue; }
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
        $o['addons'][] = ['id' => $i, 'title' => $str($x['title'] ?? '', 80), 'note' => $str($x['note'] ?? '', 160), 'price' => $int($x['price'] ?? 0, 0, 1000000, 0), 'icon' => $str($x['icon'] ?? 'star', 20)] + (!empty($x['off']) ? ['off' => true] : []);
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
    $hero = $c['heroImage'] ?? '';
    if (is_string($hero) && preg_match('~^uploads/heroes/[a-z0-9-]+\.(webp|png|jpe?g)$~', $hero)) $o['heroImage'] = $hero;
    foreach (['heroPerson', 'bannerImage'] as $k) { $v = $c[$k] ?? ''; if (is_string($v) && preg_match('~^uploads/heroes/[a-z0-9-]+\.(webp|png|jpe?g)$~', $v)) $o[$k] = $v; }
    // оформление: показывать ли персонажа и картинку баннера, снег и кнопку «Отключить анимацию»
    $dz = is_array($c['design'] ?? null) ? $c['design'] : [];
    $o['design'] = ['personOff' => !empty($dz['personOff']), 'bannerImgOff' => !empty($dz['bannerImgOff']), 'snow' => in_array($dz['snow'] ?? '', ['button', 'on', 'off'], true) ? $dz['snow'] : 'button'];
    if (!empty($dz['ownWinterOnly'])) $o['design']['ownWinterOnly'] = true; // свои фон и персонаж — только у зимних поводов
    // Предоплата: кнопка со ссылкой агентства после заявки; деньги идут агентству напрямую
    $pay = is_array($c['payment'] ?? null) ? $c['payment'] : [];
    $mode = ($pay['mode'] ?? 'link') === 'yookassa' ? 'yookassa' : 'link';
    $ykType = ($pay['ykType'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed';
    $ykValue = (int)($pay['ykValue'] ?? 0);
    $o['payment'] = ['enabled' => !empty($pay['enabled']), 'mode' => $mode, 'link' => $str($pay['link'] ?? '', 500), 'amount' => $str($pay['amount'] ?? '', 40), 'ykType' => $ykType, 'ykValue' => $ykValue];
    if ($o['payment']['link'] !== '' && !preg_match('~^https://[^\s<>"]+$~', $o['payment']['link'])) $err[] = 'Ссылка на оплату должна начинаться с https://';
    if ($o['payment']['enabled'] && $mode === 'link' && $o['payment']['link'] === '') $err[] = 'Чтобы показывать кнопку предоплаты, вставьте ссылку на оплату';
    if ($o['payment']['enabled'] && $mode === 'yookassa') {
        if ($ykType === 'percent' && ($ykValue < 1 || $ykValue > 100)) $err[] = 'Предоплата в процентах — от 1 до 100';
        if ($ykType === 'fixed' && ($ykValue < 1 || $ykValue > 500000)) $err[] = 'Укажите сумму предоплаты в рублях';
    }
    // Баннер внизу страницы записи: пустой заголовок — баннера нет
    if (is_array($c['banner'] ?? null)) {
        $bn = $c['banner'];
        $o['banner'] = ['title' => $str($bn['title'] ?? '', 120), 'text' => $str($bn['text'] ?? '', 300), 'button' => $str($bn['button'] ?? '', 40), 'link' => $str($bn['link'] ?? '', 300)];
        if ($o['banner']['link'] !== '' && !preg_match('~^(https://[^\s<>"]+|tel:\+?[0-9()\s-]{5,20})$~', $o['banner']['link'])) $err[] = 'Ссылка кнопки баннера: адрес с https:// или телефон вида tel:+79991234567';
    }
    // Свои заголовки и подписи страницы записи; пусто — стандартный текст
    $tx = is_array($c['texts'] ?? null) ? $c['texts'] : [];
    $o['texts'] = [];
    foreach (['heroTitle' => 60, 'heroAccent' => 60, 'heroLead' => 200, 'whoTitle' => 60, 'whoSub' => 120, 'progTitle' => 60, 'progSub' => 120, 'dateTitle' => 60, 'dateSub' => 120,
        'extraTitle' => 60, 'extraSub' => 120, 'zoneTitle' => 60, 'contTitle' => 60, 'contSub' => 120] as $k => $max) { $v = $str($tx[$k] ?? '', $max); if ($v !== '') $o['texts'][$k] = $v; }
    // Портфолио: фото и видео агентства на странице записи. Файлы добавляются и убираются отдельными действиями.
    $pf = is_array($c['portfolio'] ?? null) ? $c['portfolio'] : [];
    $o['portfolio'] = ['enabled' => !empty($pf['enabled']), 'title' => $str($pf['title'] ?? '', 80), 'items' => []];
    $np = 0; $nv = 0;
    foreach ($list($pf['items'] ?? [], PORTFOLIO_MAX_PHOTOS + PORTFOLIO_MAX_VIDEOS) as $x) {
        $it = portfolio_item_clean($x); if (!$it) continue;
        if ($it['t'] === 'photo') { if (++$np > PORTFOLIO_MAX_PHOTOS) continue; } elseif (++$nv > PORTFOLIO_MAX_VIDEOS) continue;
        $o['portfolio']['items'][] = $it;
    }
    // Ночная копия заявок: архив с паролем в Telegram и/или на почту
    $bk = is_array($c['backup'] ?? null) ? $c['backup'] : [];
    $o['backup'] = ['tg' => !empty($bk['tg']), 'email' => !empty($bk['email']), 'password' => is_scalar($bk['password'] ?? null) ? mb_substr((string)$bk['password'], 0, 100) : ''];
    if (($o['backup']['tg'] || $o['backup']['email']) && strlen($o['backup']['password']) < 6) $err[] = 'Пароль для архива с копией заявок — не короче 6 символов';
    return $o;
}

const PORTFOLIO_MAX_PHOTOS = 20;
const PORTFOLIO_MAX_VIDEOS = 5;
const VIDEO_MAX_BYTES = 50 * 1024 * 1024;
function portfolio_item_clean($x): ?array {
    if (!is_array($x)) return null;
    $t = $x['t'] ?? ''; $src = is_string($x['src'] ?? null) ? $x['src'] : '';
    // категория в галерее (например «Утренники», «На дому»): короткий текст, по желанию
    $cat = is_scalar($x['cat'] ?? null) ? mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$x['cat'])), 0, 30) : '';
    $extra = $cat !== '' ? ['cat' => $cat] : [];
    // у видео — своя обложка и надписи на ней (например «Видеоэкскурсия» / «Познакомьтесь с нами»)
    if ($t === 'video' || $t === 'embed') {
        $pv = is_string($x['poster'] ?? null) ? $x['poster'] : '';
        if (preg_match('~^uploads/portfolio/[a-z0-9-]+\.(webp|png|jpe?g)$~', $pv)) $extra['poster'] = $pv;
    }
    // подпись на слайде — у фото и у видео
    foreach (['title' => 60, 'sub' => 100] as $k => $max) { $v = is_scalar($x[$k] ?? null) ? mb_substr(trim((string)$x[$k]), 0, $max) : ''; if ($v !== '') $extra[$k] = $v; }
    if ($t === 'photo' && preg_match('~^uploads/portfolio/[a-z0-9-]+\.(webp|png|jpe?g)$~', $src)) return ['t' => 'photo', 'src' => $src] + $extra;
    if ($t === 'video' && preg_match('~^uploads/videos/[a-z0-9-]+\.(mp4|webm)$~', $src)) return ['t' => 'video', 'src' => $src] + $extra;
    if ($t === 'embed' && embed_ok($src)) return ['t' => 'embed', 'src' => $src, 'provider' => str_contains($src, 'rutube.ru') ? 'rutube' : 'vk'] + $extra;
    return null;
}
// Ролик можно вставить только с VK Видео и Rutube, и только по адресу их плеера
function embed_ok(string $src): bool {
    return (bool)preg_match('~^https://rutube\.ru/play/embed/[a-f0-9]{32}(\?p=[A-Za-z0-9_-]{1,64})?$~', $src)
        || (bool)preg_match('~^https://vk\.com/video_ext\.php\?oid=-?\d{1,20}&id=\d{1,20}(&hash=[a-f0-9]{1,40})?$~', $src);
}
// Ссылка на ролик или код вставки → адрес плеера. Понимает vk.com, vkvideo.ru, rutube.ru (обычные ролики и shorts).
function embed_from_url(string $u): ?string {
    $u = trim(html_entity_decode($u, ENT_QUOTES));
    if (preg_match('~src=["\']([^"\']+)["\']~i', $u, $m)) $u = $m[1]; // вставили код <iframe …>
    if (preg_match('~^(?:https?:)?//rutube\.ru/(?:video|shorts|play/embed)/(?:private/)?([a-f0-9]{32})/?(?:\?(?:.*&)?p=([A-Za-z0-9_-]{1,64}))?~i', $u, $m)) {
        return 'https://rutube.ru/play/embed/' . strtolower($m[1]) . (!empty($m[2]) ? '?p=' . $m[2] : '');
    }
    if (preg_match('~^(?:https?:)?//(?:www\.|m\.)?(?:vk\.com|vk\.ru|vkvideo\.ru)/video_ext\.php\?(.+)$~i', $u, $m)) {
        parse_str(html_entity_decode($m[1]), $q);
        if (isset($q['oid'], $q['id']) && preg_match('/^-?\d+$/', (string)$q['oid']) && preg_match('/^\d+$/', (string)$q['id'])) {
            $h = (isset($q['hash']) && preg_match('/^[a-f0-9]{1,40}$/', (string)$q['hash'])) ? '&hash=' . $q['hash'] : '';
            return 'https://vk.com/video_ext.php?oid=' . $q['oid'] . '&id=' . $q['id'] . $h;
        }
    }
    if (preg_match('~^(?:https?://)?(?:www\.|m\.)?(?:vk\.com|vk\.ru|vkvideo\.ru)/.*?video(-?\d{1,20})_(\d{1,20})~i', $u, $m)) {
        return 'https://vk.com/video_ext.php?oid=' . $m[1] . '&id=' . $m[2];
    }
    return null;
}
function validate_config(array $c): array { $err = []; normalize_config($c, $err); return array_values(array_unique($err)); }
