<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config;
use App\Domain\ReservationInput;
use App\Domain\ReservationService;
use App\Infrastructure\Database;
use App\Infrastructure\ReservationRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Unit\ReservationInputTest;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected ReservationRepository $repository;
    protected ReservationService $service;
    protected Config $config;

    protected function setUp(): void
    {
        $this->config = Config::fromEnvironment();
        if ($this->config->environment !== 'test' || !str_ends_with($this->config->database, '_test')) {
            self::markTestSkipped('Configure APP_ENV=test and a dedicated database ending in _test. See README.');
        }
        $this->pdo = Database::connect($this->config);
        $this->pdo->exec('DELETE FROM reserva_evento');
        $this->pdo->exec('DELETE FROM reserva');
        $this->repository = new ReservationRepository($this->pdo);
        $this->service = new ReservationService($this->pdo, $this->repository);
    }

    /** @param array<string, string> $overrides @return array<string, mixed> */
    protected function create(array $overrides = []): array
    {
        return $this->service->create(ReservationInput::fromArray(array_replace(ReservationInputTest::valid(), $overrides)));
    }
}
