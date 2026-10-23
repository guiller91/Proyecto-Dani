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
        throw new RuntimeException('La importación de pruebas requiere APP_ENV=test y una BD con sufijo _test.');
    }
    $pdo = Database::connect($config);
    if ($pdo->query('SHOW TABLES')->fetchAll() !== []) {
        throw new RuntimeException('La base de datos debe estar vacía. No se han sobrescrito datos.');
    }
    $sql = file_get_contents(dirname(__DIR__) . '/database/database.sql');
    if ($sql === false) {
        throw new RuntimeException('No se encuentra el fichero SQL.');
    }
    $pdo->exec($sql);
    echo "Importación completada: {$config->database} (16 reservas de ejemplo).\n";
} catch (Throwable $error) {
    // CLI-only diagnostics. Never exposed by the web document root.
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
