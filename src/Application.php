<?php

declare(strict_types=1);

namespace App;

use App\Domain\ReservationService;
use App\Http\Kernel;
use App\Http\ReservationController;
use App\Infrastructure\Database;
use App\Infrastructure\ReservationRepository;
use Twig\Environment;
use Twig\Extension\CoreExtension;
use Twig\Loader\FilesystemLoader;

final class Application
{
    public static function kernel(Config $config, string $token): Kernel
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), [
            'autoescape' => 'html', 'strict_variables' => true,
            'cache' => $config->environment === 'production' ? dirname(__DIR__) . '/var/twig' : false,
        ]);
        $twig->getExtension(CoreExtension::class)->setTimezone($config->timezone);
        $twig->addGlobal('csrf_token', $token);
        $twig->addGlobal('app_timezone', $config->timezone);
        $pdo = Database::connect($config);
        $repository = new ReservationRepository($pdo);
        return new Kernel(new ReservationController($repository, new ReservationService($pdo, $repository), $twig), $twig, $token);
    }
}
