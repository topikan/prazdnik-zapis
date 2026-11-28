<?php
// Оплата сервиса агентствами: бесплатный период, оплата до даты, 3 дня отсрочки, потом страница записи закрывается.
// Даты храним строками ГГГГ-ММ-ДД: одинаково в MySQL и SQLite.
declare(strict_types=1);

// Оплата помесячная. Новогодний сезон (freeEnd — seasonEnd) дороже: цена сезона, со скидкой в первые часы после регистрации.
// Новым агентствам — trialDays бесплатных дней с регистрации, в любой месяц года.
const BILLING_DEFAULTS = ['freeEnd' => '2026-12-15', 'seasonEnd' => '2027-01-10', 'graceDays' => 3, 'trialDays' => 14, 'priceEarly' => 3000, 'priceFull' => 5000, 'earlyHours' => 24, 'soloEarly' => 1000, 'soloFull' => 2000, 'priceMonth' => 1500, 'soloMonth' => 700];

// Колонки оплаты и регистрации у агентств; в старых базах добавляются при первом обращении
function ensure_billing_cols(): void {
    static $done = false; if ($done) return;
    try { db()->query('SELECT paid_until, trial_until, email, kind FROM agencies LIMIT 1'); }
    catch (Throwable $e) {
        // kind: agency — агентство, solo — частный исполнитель (один выезд, своя цена). Меняет только владелец сервиса.
        foreach (["paid_until VARCHAR(10) NOT NULL DEFAULT ''", "trial_until VARCHAR(10) NOT NULL DEFAULT ''", "email VARCHAR(120) NOT NULL DEFAULT ''", "kind VARCHAR(8) NOT NULL DEFAULT 'agency'"] as $col) {
            try { db()->exec("ALTER TABLE agencies ADD COLUMN $col"); } catch (Throwable $e2) { /* колонка уже есть */ }
        }
    }
    $done = true;
}

// Даты бесплатного периода и сезона; владелец может поменять их на следующий сезон
function billing_settings(): array {
    $s = setting_get('billing') ?: [];
    $o = BILLING_DEFAULTS;
    foreach (['freeEnd', 'seasonEnd'] as $k) if (!empty($s[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s[$k])) $o[$k] = $s[$k];
    foreach (BILLING_NUMS as $k => $r) if (isset($s[$k]) && is_numeric($s[$k]) && $s[$k] >= $r[0] && $s[$k] <= $r[1]) $o[$k] = (int)$s[$k];
    return $o;
}

const BILLING_NUMS = ['priceEarly' => [100, 1000000], 'priceFull' => [100, 1000000], 'earlyHours' => [1, 720], 'soloEarly' => [100, 1000000], 'soloFull' => [100, 1000000],
    'priceMonth' => [100, 1000000], 'soloMonth' => [100, 1000000], 'trialDays' => [1, 60]];

// Что агентство оплачивает следующим: если следующий неоплаченный день попадает в новогодний сезон — сезон целиком
// (в первые часы после регистрации — со скидкой), иначе — месяц.
// Считается только здесь, на сервере: сумму из браузера не принимаем никогда.
function billing_price(array $a, ?int $now = null): array {
    $now = $now ?? time();
    $s = billing_settings();
    $created = strtotime((string)($a['created_at'] ?? '')) ?: $now;
    $until = $created + $s['earlyHours'] * 3600;
    $solo = is_solo($a);
    $pe = $solo ? $s['soloEarly'] : $s['priceEarly']; $pf = $solo ? $s['soloFull'] : $s['priceFull'];
    $pm = $solo ? $s['soloMonth'] : $s['priceMonth'];
    // первый день, за который ещё не заплачено
    $st = billing_state($a, date('Y-m-d', $now));
    $from = max(date('Y-m-d', $now), day_add((string)($st['lastDay'] ?? date('Y-m-d', $now)), 1));
    $season = ($from >= $s['freeEnd'] && $from <= $s['seasonEnd']) || ($from < $s['freeEnd'] && day_add($from, 30) >= $s['freeEnd']);
    $early = $season && $now < $until;
    $periodEnd = $season ? $s['seasonEnd'] : day_add((new DateTime($from))->modify('+1 month')->format('Y-m-d'), -1);
    return ['amount' => $season ? ($early ? $pe : $pf) : $pm, 'season' => $season, 'early' => $early, 'priceEarly' => $pe, 'priceFull' => $pf, 'priceMonth' => $pm, 'solo' => $solo,
        'from' => $from, 'until' => $periodEnd,
        'earlyUntil' => date('Y-m-d H:i', $until), 'earlyLeft' => $season ? max(0, $until - $now) : 0, 'earlyHours' => $s['earlyHours']];
}
// Через DateTime: строку вида «2026-12-15 -1 day» strtotime читает как часовой пояс −1 и ошибается на два дня
function day_add(string $d, int $n): string { return (new DateTime($d))->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d'); }
function days_between(string $a, string $b): int { return (int)(new DateTime($a))->diff(new DateTime($b))->format('%r%a'); }
// Метки дат в текстах главной: на их место главная подставляет даты из «Настроек сервиса».
// Тексты, сохранённые раньше с датами «15 декабря» и «10 января», переводятся на метки — тогда смена дат в настройках меняет и их.
const DATE_TOKENS = ['15 декабря' => '[бесплатно до]', '10 января' => '[конец сезона]'];
function landing_tokens($v) {
    if (is_string($v)) return strtr($v, DATE_TOKENS);
    if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = landing_tokens($x); }
    return $v;
}
function ru_day_month(string $d): string {
    $m = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    return preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $d, $x) ? (int)$x[2] . ' ' . $m[(int)$x[1] - 1] : '';
}
function ru_date(string $d): string { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? substr($d, 8, 2) . '.' . substr($d, 5, 2) . '.' . substr($d, 0, 4) : ''; }

// Состояние оплаты агентства:
//   free — бесплатный период, paid — оплачено, grace — срок прошёл, но 3 дня страница ещё работает,
//   closed — страница записи закрыта, demo — демо-агентство без оплаты
function billing_state(array $a, ?string $today = null): array {
    $today = $today ?? date('Y-m-d');
    if (!empty($a['cfg']['demo'])) return ['status' => 'demo'];
    $s = billing_settings();
    $paid = (string)($a['paid_until'] ?? '');
    $trial = (string)($a['trial_until'] ?? '');
    $last = day_add($s['freeEnd'], -1);           // последний бесплатный день для всех
    if ($trial !== '' && $trial > $last) $last = $trial; // зарегистрировались после — свои 3 дня
    if ($paid !== '' && $paid > $last) $last = $paid;
    $closes = day_add($last, $s['graceDays'] + 1); // первый день, когда страница закрыта
    $st = ['lastDay' => $last, 'closesOn' => $closes, 'paidUntil' => $paid, 'seasonEnd' => $s['seasonEnd']];
    if ($today <= $last) {
        $st['status'] = ($paid !== '' && $paid >= $today) ? 'paid' : 'free';
        $st['daysLeft'] = days_between($today, $last) + 1;
    } elseif ($today < $closes) {
        $st['status'] = 'grace';
        $st['daysLeft'] = days_between($today, $closes);
    } else {
        $st['status'] = 'closed';
    }
    return $st;
}
function billing_closed(array $a): bool { return (billing_state($a)['status'] ?? '') === 'closed'; }

function is_solo(array $a): bool { return ($a['kind'] ?? 'agency') === 'solo'; }

// Пробный период: trialDays дней с регистрации (сегодня — первый). Если общий бесплатный период длиннее — действует он (см. billing_state).
function trial_for_new(?string $today = null): string {
    $today = $today ?? date('Y-m-d');
    $s = billing_settings();
    return day_add($today, $s['trialDays'] - 1);
}

// Адреса, которые нельзя занять агентству: они нужны самому сайту
const RESERVED_SLUGS = ['admin', 'api', 'assets', 'agencies', 'install', 'lib', 'uploads', 'docs', 'data', 'zapis', 'consent', 'privacy', 'index', 'demo', 'www', 'mail', 'register', 'rules', 'reset', 'login', 'help', 'support', 'blog', 'pay', 'price', 'app'];
function slug_problem(string $slug): string {
    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/', $slug) || strlen($slug) < 3) return 'Адрес: латинские буквы, цифры и дефис, от 3 символов';
    if (in_array($slug, RESERVED_SLUGS, true)) return 'Этот адрес занят системой, выберите другой';
    if (agency_by_slug($slug)) return 'Такой адрес уже занят, выберите другой';
    return '';
}
// Новое агентство из шаблона (цены и программы-примеры, которые агентство потом меняет)
function create_agency(string $slug, string $name, string $city, string $phone, string $email, bool $active, string $trial, string $kind = 'agency'): int {
    ensure_billing_cols();
    $tpl = json_decode((string)file_get_contents(APP_ROOT . '/agencies/demo.json'), true);
    $tpl['name'] = mb_substr($name, 0, 80);
    $tpl['city'] = mb_substr($city, 0, 60);
    if ($phone !== '') $tpl['phone'] = $phone;
    $tpl['demo'] = false;
    $tpl['legal'] = ['operator' => '', 'inn' => '', 'ogrn' => '', 'address' => '', 'email' => '', 'keepMonths' => 12, 'updated' => date('d.m.Y'), 'policyUrl' => ''];
    $tpl['notify'] = ['email' => $email];
    if ($kind === 'solo') {
        // частник: один выезд одновременно, тексты от первого лица
        $tpl['pairs'] = 1;
        $tpl['texts'] = ['contSub' => 'Я перезвоню, чтобы подтвердить визит'];
    }
    unset($tpl['portfolio'], $tpl['backup'], $tpl['logo'], $tpl['heroImage']);
    db()->prepare('INSERT INTO agencies (slug, config, active, created_at, paid_until, trial_until, email, kind) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$slug, json_encode($tpl, JSON_UNESCAPED_UNICODE), $active ? 1 : 0, date('Y-m-d H:i:s'), '', $trial, $email, $kind === 'solo' ? 'solo' : 'agency']);
    return (int)db()->lastInsertId();
}
