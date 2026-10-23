<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__), '.env.test')->safeLoad();
date_default_timezone_set('UTC');
