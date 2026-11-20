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
    if (!empty($cfg['promo']['percent']) && date('Y-m-d') <= ($cfg['promo']['until'] ?? '')) {
        $lines[] = ['Скидка за раннее бронирование −' . $cfg['promo']['percent'] . '%', -round100($program * $cfg['promo']['percent'] / 100)];
    }
    foreach ($cfg['addons'] ?? [] as $a) if (in_array($a['id'], $o['addons'], true)) $lines[] = [$a['title'], (int)$a['price']];
    $z = find_item($cfg['zones'] ?? [], $o['zone']);
    if ($z && $z['price']) $lines[] = ['Выезд: ' . mb_strtolower($z['title']), (int)$z['price']];
    $total = array_sum(array_column($lines, 1));
    return ['total' => $total, 'lines' => $lines];
}

// Проверка настроек агентства перед сохранением из админки
function validate_config(array $c): array {
    $err = [];
    foreach (['name', 'season', 'hours', 'pairs', 'characters', 'programs', 'prices', 'kids', 'zones'] as $k) if (!isset($c[$k])) $err[] = "Нет поля $k";
    if ($err) return $err;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $c['season']['start'] ?? '') || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $c['season']['end'] ?? '')) $err[] = 'Даты сезона в формате ГГГГ-ММ-ДД';
    if (!preg_match('/^\d{2}:\d{2}$/', $c['hours']['from'] ?? '') || !preg_match('/^\d{2}:\d{2}$/', $c['hours']['to'] ?? '')) $err[] = 'Часы работы в формате ЧЧ:ММ';
    if ((int)$c['pairs'] < 1 || (int)$c['pairs'] > 50) $err[] = 'Количество составов от 1 до 50';
    if (!count($c['characters']) || !count($c['programs'])) $err[] = 'Нужен хотя бы один состав персонажей и одна программа';
    if (!count($c['zones'])) $err[] = 'Нужна хотя бы одна зона выезда';
    foreach ($c['characters'] as $ch) foreach ($c['programs'] as $p) {
        if (!isset($c['prices'][$ch['id']][$p['id']]) || !is_numeric($c['prices'][$ch['id']][$p['id']])) $err[] = "Нет цены: {$ch['title']} / {$p['title']}";
    }
    foreach (['accent', 'gold'] as $k) if (!empty($c['colors'][$k]) && !preg_match('/^#[0-9a-fA-F]{6}$/', $c['colors'][$k])) $err[] = 'Цвет в формате #RRGGBB';
    return $err;
}
