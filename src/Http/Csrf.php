<?php

declare(strict_types=1);

namespace App\Http;

final class Csrf
{
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_name('reservas_session');
            session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax',
                'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'cookie_path' => '/']);
        }
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        $token = $_SESSION['csrf'];
        session_write_close();
        return $token;
    }

    public static function verify(Request $request, string $token): void
    {
        $provided = $request->headers['x-csrf-token'] ?? '';
        if ($provided === '' || !hash_equals($token, $provided)
            || ($request->headers['sec-fetch-site'] ?? '') === 'cross-site') {
            throw new HttpException(403, 'La sesión de seguridad ha caducado. Recarga la página y vuelve a intentarlo.');
        }
    }
}
