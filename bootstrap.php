<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
date_default_timezone_set('UTC');

return App\Config::fromEnvironment();
