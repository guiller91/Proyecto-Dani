<?php
declare(strict_types=1);

namespace App\Infrastructure;

use App\Config;
use PDO;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $pdo = new PDO(
            "mysql:host={$config->host};port={$config->port};dbname={$config->database};charset=utf8mb4",
            $config->username,
            $config->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 5],
        );
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }
}
