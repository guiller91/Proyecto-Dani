<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\ReservationFilter;
use App\Http\HttpException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReservationPersistenceTest extends DatabaseTestCase
{
    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidStatusChanges(): iterable
    {
        yield 'missing state' => [['version' => 1], 'estado'];
        yield 'invalid state' => [['estado' => 'inventada', 'version' => 1], 'estado'];
        yield 'pending target' => [['estado' => 'pendiente', 'version' => 1], 'estado'];
        yield 'missing version' => [['estado' => 'confirmada'], 'version'];
        yield 'string version' => [['estado' => 'confirmada', 'version' => '1'], 'version'];
        yield 'negative version' => [['estado' => 'confirmada', 'version' => -1], 'version'];
        yield 'mass assignment' => [['estado' => 'confirmada', 'version' => 1, 'importe' => '0'], '_form'];
    }

    #[DataProvider('invalidStatusChanges')]
    public function testRejectsInvalidStatusChangesWithoutWriting(array $input, string $field): void
    {
        $reservation = $this->create();
        try {
            $this->service->changeStatus($reservation['id'], $input);
            self::fail('Expected invalid change');
        } catch (HttpException $error) {
            self::assertSame(400, $error->status);
            self::assertArrayHasKey($field, $error->fields);
        }
        $unchanged = $this->service->detail($reservation['id']);
        self::assertSame('pendiente', $unchanged['estado']);
        self::assertCount(1, $unchanged['eventos']);
    }

    public function testCreatesReservationAndHistoryAndPreservesExactAmount(): void
    {
        $reservation = $this->create(['importe' => '99999999.99']);
        self::assertSame('pendiente', $reservation['estado']);
        self::assertSame('99999999.99', $reservation['importe']);
        self::assertSame(1, $reservation['version']);
        self::assertSame('creada', $reservation['eventos'][0]['tipo']);
        self::assertStringEndsWith('Z', $reservation['fecha_creacion']);
    }

    public function testConfirmsThenCancelsAndRejectsReactivation(): void
    {
        $reservation = $this->create();
        $confirmed = $this->service->changeStatus($reservation['id'], ['estado' => 'confirmada', 'version' => 1]);
        self::assertSame(2, $confirmed['version']);
        $cancelled = $this->service->changeStatus($reservation['id'], ['estado' => 'cancelada', 'version' => 2]);
        self::assertSame(['creada', 'confirmada', 'cancelada'], array_column($cancelled['eventos'], 'tipo'));
        $this->expectException(HttpException::class);
        $this->service->changeStatus($reservation['id'], ['estado' => 'confirmada', 'version' => 3]);
    }

    public function testRepeatedSameStatusDoesNotDuplicateEvent(): void
    {
        $reservation = $this->create();
        $this->service->changeStatus($reservation['id'], ['estado' => 'cancelada', 'version' => 1]);
        $again = $this->service->changeStatus($reservation['id'], ['estado' => 'cancelada', 'version' => 2]);
        self::assertSame(2, $again['version']);
        self::assertCount(2, $again['eventos']);
    }

    public function testStaleWriterCannotOverwriteAnotherChange(): void
    {
        $reservation = $this->create();
        $this->service->changeStatus($reservation['id'], ['estado' => 'confirmada', 'version' => 1]);
        try {
            $this->service->changeStatus($reservation['id'], ['estado' => 'cancelada', 'version' => 1]);
            self::fail('Expected conflict');
        } catch (HttpException $error) {
            self::assertSame(409, $error->status);
        }
        self::assertSame('confirmada', $this->service->detail($reservation['id'])['estado']);
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testMissingReservationIs404(): void
    {
        try {
            $this->service->detail(4294967295);
            self::fail('Expected missing');
        } catch (HttpException $error) {
            self::assertSame(404, $error->status);
        }
    }

    public function testFiltersOverlapAndTreatSearchWildcardsLiterally(): void
    {
        $first = $this->create(['nombre_huesped' => 'Lucía 100%_!', 'fecha_entrada' => '2028-03-01', 'fecha_salida' => '2028-03-05']);
        $this->service->changeStatus($first['id'], ['estado' => 'confirmada', 'version' => 1]);
        $this->create(['nombre_huesped' => 'Otra Lucía', 'fecha_entrada' => '2028-03-05', 'fecha_salida' => '2028-03-07']);
        $result = $this->repository->listing(ReservationFilter::fromQuery(['estado' => 'confirmada', 'desde' => '2028-03-04', 'hasta' => '2028-03-04', 'q' => '100%_!']));
        self::assertSame(1, $result['meta']['total']);
        self::assertSame($first['id'], $result['data'][0]['id']);
        self::assertSame(1, $this->repository->listing(ReservationFilter::fromQuery(['desde' => '2028-03-05', 'hasta' => '2028-03-05']))['meta']['total']);
        self::assertSame(0, $this->repository->listing(ReservationFilter::fromQuery(['q' => "' OR 1=1 --"]))['meta']['total']);
    }

    public function testPaginationAndTotalsCoverAllMatchingRows(): void
    {
        $one = $this->create(['importe' => '0.10']);
        $two = $this->create(['importe' => '0.20']);
        $this->create(['importe' => '50.00']);
        $this->service->changeStatus($one['id'], ['estado' => 'cancelada', 'version' => 1]);
        $result = $this->repository->listing(ReservationFilter::fromQuery(['per_page' => '1', 'page' => '999']));
        self::assertSame(3, $result['meta']['page']);
        self::assertSame(3, $result['meta']['total']);
        self::assertCount(1, $result['data']);
        self::assertSame('50.20', $result['summary']['importe_activo']);
        self::assertSame($one['id'], $result['data'][0]['id']);
    }

    public function testCreationRollsBackIfHistoryCannotBeWritten(): void
    {
        $this->pdo->exec("CREATE TRIGGER fail_history BEFORE INSERT ON reserva_evento FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated failure'");
        try {
            try {
                $this->create();
                self::fail('Expected database failure');
            } catch (PDOException) {
                self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM reserva')->fetchColumn());
            }
        } finally {
            $this->pdo->exec('DROP TRIGGER fail_history');
        }
    }

    public function testStatusAndVersionRollBackIfHistoryCannotBeWritten(): void
    {
        $reservation = $this->create();
        $this->pdo->exec("CREATE TRIGGER fail_history BEFORE INSERT ON reserva_evento FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated failure'");
        try {
            try {
                $this->service->changeStatus($reservation['id'], ['estado' => 'confirmada', 'version' => 1]);
                self::fail('Expected database failure');
            } catch (PDOException) {
                $unchanged = $this->service->detail($reservation['id']);
                self::assertSame('pendiente', $unchanged['estado']);
                self::assertSame(1, $unchanged['version']);
                self::assertCount(1, $unchanged['eventos']);
            }
        } finally {
            $this->pdo->exec('DROP TRIGGER fail_history');
        }
    }
}
