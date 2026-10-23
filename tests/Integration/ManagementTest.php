<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application;
use App\Domain\ReservationFilter;
use App\Http\HttpException;
use App\Http\Request;
use PDOException;
use Tests\Unit\ReservationInputTest;

final class ManagementTest extends DatabaseTestCase
{
    public function testEditPersistsAndAuditsOnlyChangedFieldNames(): void
    {
        $created = $this->create();
        $input = array_replace(ReservationInputTest::valid(), ['nombre_huesped' => 'Nombre actualizado', 'importe' => '222.10', 'notas' => 'Nota privada']);
        $updated = $this->service->update($created['id'], $input + ['version' => 1]);
        self::assertSame(2, $updated['version']);
        self::assertSame('222.10', $updated['importe']);
        self::assertSame('Nombre actualizado', $updated['nombre_huesped']);
        self::assertSame('actualizada', $updated['eventos'][1]['tipo']);
        self::assertStringContainsString('huésped, importe, notas', $updated['eventos'][1]['descripcion']);
        self::assertStringNotContainsString('Nota privada', $updated['eventos'][1]['descripcion']);
        $same = $this->service->update($created['id'], $input + ['version' => 2]);
        self::assertSame(2, $same['version']);
        self::assertCount(2, $same['eventos']);
        try {
            $this->service->update($created['id'], $input + ['version' => 1]);
            self::fail('Expected stale edit');
        } catch (HttpException $error) {
            self::assertSame(409, $error->status);
        }
    }

    public function testCancelledReservationsCannotBeEdited(): void
    {
        $created = $this->create();
        $this->service->changeStatus($created['id'], ['estado' => 'cancelada', 'version' => 1]);
        try {
            $this->service->update($created['id'], ReservationInputTest::valid() + ['version' => 2]);
            self::fail('Expected cancelled edit refusal');
        } catch (HttpException $error) {
            self::assertSame(409, $error->status);
        }
        self::assertCount(2, $this->service->detail($created['id'])['eventos']);
    }

    public function testEditRollsBackWhenHistoryFails(): void
    {
        $created = $this->create();
        $this->pdo->exec("CREATE TRIGGER fail_history BEFORE INSERT ON reserva_evento FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Failure'");
        try {
            try {
                $this->service->update($created['id'], array_replace(ReservationInputTest::valid(), ['notas' => 'Changed']) + ['version' => 1]);
                self::fail('Expected rollback');
            } catch (PDOException) {
                self::assertSame($created['notas'], $this->service->detail($created['id'])['notas']);
            }
        } finally {
            $this->pdo->exec('DROP TRIGGER fail_history');
        }
        self::assertSame(1, $this->service->detail($created['id'])['version']);
    }

    public function testBatchChangeWritesEachHistoryAndRollsBackEntireStaleBatch(): void
    {
        $a = $this->create();
        $b = $this->create();
        $batch = ['estado' => 'confirmada', 'reservas' => [['id' => $a['id'], 'version' => 1], ['id' => $b['id'], 'version' => 1]]];
        $result = $this->service->bulkStatus($batch);
        self::assertSame(2, $result['updated']);
        self::assertSame(['confirmada', 'confirmada'], array_column($result['data'], 'estado'));
        $this->service->changeStatus($b['id'], ['estado' => 'cancelada', 'version' => 2]);
        try {
            $this->service->bulkStatus(['estado' => 'cancelada', 'reservas' => [['id' => $a['id'], 'version' => 2], ['id' => $b['id'], 'version' => 2]]]);
            self::fail('Expected stale batch');
        } catch (HttpException $error) {
            self::assertSame(409, $error->status);
        }
        $unchanged = $this->service->detail($a['id']);
        self::assertSame('confirmada', $unchanged['estado']);
        self::assertSame(2, $unchanged['version']);
        self::assertCount(2, $unchanged['eventos']);
    }

    public function testRejectsDuplicateAndOversizedBatchWithoutWrites(): void
    {
        $a = $this->create();
        foreach ([array_fill(0, 2, ['id' => $a['id'], 'version' => 1]), array_fill(0, 51, ['id' => $a['id'], 'version' => 1]), []] as $items) {
            try {
                $this->service->bulkStatus(['estado' => 'confirmada', 'reservas' => $items]);
                self::fail('Expected invalid batch');
            } catch (HttpException $error) {
                self::assertSame(400, $error->status);
            }
        }
        self::assertCount(1, $this->service->detail($a['id'])['eventos']);
    }

    public function testAccommodationDateAndSortFiltersAreCombinable(): void
    {
        $a = $this->create(['nombre_alojamiento' => 'Casa A', 'importe' => '10.00', 'fecha_entrada' => '2028-06-10', 'fecha_salida' => '2028-06-12']);
        $b = $this->create(['nombre_alojamiento' => 'Casa A', 'importe' => '20.00', 'fecha_entrada' => '2028-06-10', 'fecha_salida' => '2028-06-13']);
        $this->create(['nombre_alojamiento' => 'Casa B', 'importe' => '30.00']);
        $result = $this->repository->listing(ReservationFilter::fromQuery(['alojamiento' => 'Casa A', 'entrada' => '2028-06-10', 'orden' => 'importe_desc']));
        self::assertSame([$b['id'], $a['id']], array_column($result['data'], 'id'));
        self::assertSame(['Casa A', 'Casa B'], $this->repository->accommodations());
        $this->expectException(HttpException::class);
        ReservationFilter::fromQuery(['orden' => 'id; DROP TABLE reserva']);
    }

    public function testAgendaHasBothMovementsAndExcludesCancelledReservations(): void
    {
        $a = $this->create(['fecha_entrada' => '2028-06-10', 'fecha_salida' => '2028-06-13']);
        $b = $this->create(['fecha_entrada' => '2028-06-10', 'fecha_salida' => '2028-06-13']);
        $this->service->changeStatus($b['id'], ['estado' => 'cancelada', 'version' => 1]);
        $result = $this->repository->agenda('2028-06-10', '2028-06-13');
        self::assertSame(2, $result['meta']['total']);
        self::assertSame(['entrada', 'salida'], array_column($result['data'], 'movimiento'));
        self::assertSame([$a['id'], $a['id']], array_column($result['data'], 'id'));
        self::assertFalse($result['meta']['truncated']);
    }

    public function testOverviewCountsConfirmedMovementsInCalendarDays(): void
    {
        $a = $this->create(['fecha_entrada' => '2028-06-10', 'fecha_salida' => '2028-06-13']);
        $b = $this->create(['fecha_entrada' => '2028-06-09', 'fecha_salida' => '2028-06-10']);
        $this->create(['fecha_entrada' => '2028-06-10', 'fecha_salida' => '2028-06-13']);
        foreach ([$a, $b] as $row) {
            $this->service->changeStatus($row['id'], ['estado' => 'confirmada', 'version' => 1]);
        }
        $result = $this->repository->overview('2028-06-10');
        self::assertSame(1, $result['llegadas']);
        self::assertSame(1, $result['salidas']);
        self::assertSame(1, $result['alojados']);
        self::assertSame(1, $result['pendientes']);
        self::assertSame(3, $result['total']);
    }

    public function testNewHttpEndpointsValidateCsrfAndServeCsv(): void
    {
        $a = $this->create(['nombre_huesped' => '=2+3']);
        $kernel = Application::kernel($this->config, 'token');
        foreach (['/api/reservas/' . $a['id'], '/api/reservas/lote'] as $path) {
            self::assertSame(403, $kernel->handle(new Request($path === '/api/reservas/lote' ? 'POST' : 'PATCH', $path))->status);
        }
        $response = $kernel->handle(new Request('GET', '/api/reservas/exportar', ['q' => '=2+3']));
        self::assertSame(200, $response->status);
        self::assertSame('text/csv; charset=utf-8', $response->headers['Content-Type']);
        self::assertStringContainsString("'=2+3", $response->body);
        self::assertSame(400, $kernel->handle(new Request('GET', '/api/agenda', ['desde' => '2028-01-01', 'hasta' => '2028-12-31']))->status);
        self::assertSame(200, $kernel->handle(new Request('GET', '/api/overview'))->status);
    }
}
