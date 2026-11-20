<?php
// Тексты главной страницы, отредактированные в админке. Если их нет — страница возьмёт тексты по умолчанию.
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
$d = setting_get('landing');
if (!$d) fail('Нет текстов', 404);
json_out($d);
