<?php
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
spl_autoload_register(function (string $c): void {
    if (!str_starts_with($c, 'App\\')) return;
    $f = __DIR__ . '/' . str_replace('\\', '/', substr($c, 4)) . '.php';
    if (is_file($f)) require $f;
});
require_once __DIR__ . '/Core/Http.php';
\App\Core\Env::load(ROOT . '/.env');
date_default_timezone_set('UTC');
