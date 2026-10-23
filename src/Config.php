<?php
declare(strict_types=1);

namespace App;

final readonly class Config
{
    public function __construct(
        public string $environment,
        public string $timezone,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
    ) {}

    public static function fromEnvironment(): self
    {
        $read = static fn (string $key, string $default = ''): string =>
            (string) ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default);
        $database = $read('DB_DATABASE', 'reservas');
        $port = $read('DB_PORT', '3306');
        $host = $read('DB_HOST', '127.0.0.1');
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $database)
            || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
            || !preg_match('/^[a-zA-Z0-9.:-]+$/D', $host)) {
            throw new \RuntimeException('Configuración de base de datos inválida.');
        }
        $timezone = $read('APP_TIMEZONE', 'Europe/Madrid');
        new \DateTimeZone($timezone);

        return new self($read('APP_ENV', 'production'), $timezone, $host, (int) $port,
            $database, $read('DB_USERNAME', 'reservas'), $read('DB_PASSWORD'));
    }
}
