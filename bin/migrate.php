<?php

declare(strict_types=1);

use App\Config;
use App\Infrastructure\Database;

require dirname(__DIR__) . '/vendor/autoload.php';
$test = in_array('--test', $argv, true);
Dotenv\Dotenv::createImmutable(dirname(__DIR__), $test ? '.env.test' : '.env')->safeLoad();
try {
    $config = Config::fromEnvironment();
    if ($test && ($config->environment !== 'test' || !str_ends_with($config->database, '_test'))) {
        throw new RuntimeException('Se requiere una base de pruebas con sufijo _test.');
    }
    $pdo = Database::connect($config);
    $column = $pdo->query("SHOW COLUMNS FROM reserva_evento LIKE 'tipo'")->fetch();
    if ($column === false) {
        throw new RuntimeException('Primero importa database/database.sql.');
    }
    if (!str_contains($column['Type'], "'actualizada'")) {
        $sql = file_get_contents(dirname(__DIR__) . '/database/migrations/002_reserva_actualizada.sql');
        if ($sql === false) {
            throw new RuntimeException('No se encuentra la migración.');
        }
        $pdo->exec($sql);
        echo "Migración 002 aplicada.\n";
    } else {
        echo "La base de datos ya está actualizada.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
