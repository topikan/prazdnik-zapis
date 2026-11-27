<?php
// Страница записи агентства (anisis.ru/<адрес>): та же zapis.html, но с названием агентства
// в заголовке и тегах превью — чтобы ссылка в ВК и Telegram показывала карточку агентства.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$html = (string)@file_get_contents(__DIR__ . '/zapis.html');
$slug = (string)($_GET['a'] ?? '');
try { $a = preg_match('/^[a-z0-9-]{2,40}$/', $slug) ? agency_by_slug($slug) : null; } catch (Throwable $ex) { $a = null; } // база недоступна — страница всё равно открывается
$e = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$site = 'https://anisis.ru';
if ($a && $a['active']) {
    $cfg = $a['cfg'];
    $name = trim((string)($cfg['name'] ?? $slug));
    $title = $name . ' — онлайн-запись' . (!empty($cfg['city']) ? ', ' . $cfg['city'] : '');
    $desc = 'Выберите, кто приедет, программу и время — цена посчитается сразу, без звонков.';
    // логотипы хранятся в WebP, а ВК такие картинки в превью показывает ненадёжно — берём общую обложку JPG
    $img = $site . '/assets/img/og.jpg';
    $big = true;
    $meta = '<meta name="description" content="' . $e($desc) . '">'
        . '<meta property="og:type" content="website"><meta property="og:locale" content="ru_RU">'
        . '<meta property="og:site_name" content="' . $e($name) . '"><meta property="og:title" content="' . $e($title) . '">'
        . '<meta property="og:description" content="' . $e($desc) . '"><meta property="og:url" content="' . $e($site . '/' . $slug) . '">'
        . '<meta property="og:image" content="' . $e($img) . '">' . ($big ? '<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">' : '')
        . '<meta name="twitter:card" content="' . ($big ? 'summary_large_image' : 'summary') . '">';
    $html = preg_replace('~<title>.*?</title>~s', '<title>' . $e($title) . '</title>' . $meta, $html, 1);
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $html;
