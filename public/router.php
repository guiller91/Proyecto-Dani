<?php

declare(strict_types=1);

// Only explicitly public assets may bypass the front controller.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('#^/assets/[a-zA-Z0-9_.-]+\.(css|js|svg|woff2)$#D', $path)
    && is_file(__DIR__ . $path)) {
    return false;
}
require __DIR__ . '/index.php';
