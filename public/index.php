<?php
declare(strict_types=1);

use App\Application;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

try {
    $config = require dirname(__DIR__) . '/bootstrap.php';
    $request = Request::fromGlobals();
    Application::kernel($config, Csrf::token())->handle($request)->send($request->method === 'HEAD');
} catch (Throwable $error) {
    $id = bin2hex(random_bytes(8));
    error_log("[{$id}] Bootstrap failure: " . $error::class);
    http_response_code(503);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Request-ID: ' . $id);
    if (str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['message' => 'Servicio temporalmente no disponible.', 'fields' => new stdClass(), 'request_id' => $id]]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Servicio no disponible</title><h1>Volvemos en un momento</h1><p>Servicio temporalmente no disponible. Inténtalo de nuevo más tarde.</p></html>';
    }
}
