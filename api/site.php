<?php
// Тексты главной страницы, отредактированные в админке. Если их нет — страница возьмёт тексты по умолчанию.
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
$d = setting_get('landing');
if (!$d) json_out(new stdClass()); // текстов ещё нет: главная возьмёт тексты по умолчанию
json_out($d);
