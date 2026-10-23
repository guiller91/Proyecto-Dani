<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\ReservationFilter;
use App\Domain\ReservationInput;
use PDO;

final readonly class ReservationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>, summary: array<string, mixed>} */
    public function listing(ReservationFilter $filter): array
    {
        [$where, $parameters] = $this->criteria($filter);
        $stats = $this->pdo->prepare("SELECT COUNT(*) total,
            COALESCE(SUM(estado = 'pendiente'), 0) pendientes,
            COALESCE(SUM(estado = 'confirmada'), 0) confirmadas,
            COALESCE(SUM(estado = 'cancelada'), 0) canceladas,
            COALESCE(SUM(CASE WHEN estado <> 'cancelada' THEN importe ELSE 0 END), 0) importe_activo
            FROM reserva {$where}");
        $stats->execute($parameters);
        $summary = $stats->fetch();
        $total = (int) $summary['total'];
        $lastPage = max(1, (int) ceil($total / $filter->perPage));
        $page = min($filter->page, $lastPage);
        $offset = ($page - 1) * $filter->perPage;
        $order = $this->order($filter);
        $statement = $this->pdo->prepare("SELECT * FROM reserva {$where}
            ORDER BY {$order} LIMIT :limit OFFSET :offset");
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue(':limit', $filter->perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return [
            'data' => array_values(array_map($this->normalize(...), $statement->fetchAll())),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $filter->perPage, 'last_page' => $lastPage],
            'summary' => ['total' => $total, 'pendientes' => (int) $summary['pendientes'],
                'confirmadas' => (int) $summary['confirmadas'], 'canceladas' => (int) $summary['canceladas'],
                'importe_activo' => (string) $summary['importe_activo']],
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, bool $lock = false): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM reserva WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row === false ? null : $this->normalize($row);
    }

    /** @return list<array<string, mixed>> */
    public function events(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM reserva_evento WHERE reserva_id = ? ORDER BY fecha_creacion, id');
        $statement->execute([$id]);
        return array_values(array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['reserva_id'] = (int) $row['reserva_id'];
            $row['fecha_creacion'] = str_replace(' ', 'T', $row['fecha_creacion']) . 'Z';
            return $row;
        }, $statement->fetchAll()));
    }

    public function insert(ReservationInput $input): int
    {
        $statement = $this->pdo->prepare('INSERT INTO reserva
            (nombre_huesped, email_huesped, nombre_alojamiento, fecha_entrada, fecha_salida, importe, notas)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$input->guestName, $input->guestEmail, $input->accommodation,
            $input->checkIn, $input->checkOut, $input->amount, $input->notes]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->pdo->prepare('UPDATE reserva SET estado = ?, version = version + 1 WHERE id = ?')->execute([$status, $id]);
    }

    public function update(int $id, ReservationInput $input): void
    {
        $this->pdo->prepare('UPDATE reserva SET nombre_huesped=?, email_huesped=?, nombre_alojamiento=?,
            fecha_entrada=?, fecha_salida=?, importe=?, notas=?, version=version+1 WHERE id=?')
            ->execute([$input->guestName, $input->guestEmail, $input->accommodation, $input->checkIn,
                $input->checkOut, $input->amount, $input->notes, $id]);
    }

    /** @return list<string> */
    public function accommodations(): array
    {
        $statement = $this->pdo->prepare('SELECT DISTINCT nombre_alojamiento FROM reserva ORDER BY nombre_alojamiento');
        $statement->execute();
        return array_values($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string, mixed> */
    public function overview(string $today): array
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) total,
            COALESCE(SUM(estado='pendiente'),0) pendientes,
            COALESCE(SUM(estado='confirmada' AND fecha_entrada=?),0) llegadas,
            COALESCE(SUM(estado='confirmada' AND fecha_salida=?),0) salidas,
            COALESCE(SUM(estado='confirmada' AND fecha_entrada<=? AND fecha_salida>?),0) alojados,
            COALESCE(SUM(CASE WHEN estado<>'cancelada' THEN importe ELSE 0 END),0) importe_activo,
            COUNT(DISTINCT nombre_alojamiento) alojamientos FROM reserva");
        $statement->execute([$today, $today, $today, $today]);
        $row = $statement->fetch();
        foreach (['total', 'pendientes', 'llegadas', 'salidas', 'alojados', 'alojamientos'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['importe_activo'] = (string) $row['importe_activo'];
        return $row;
    }

    /** @return array{data: list<array<string,mixed>>, meta: array<string,mixed>} */
    public function agenda(string $from, string $to): array
    {
        $statement = $this->pdo->prepare("SELECT movements.*, COUNT(*) OVER() AS total_movimientos FROM (
            SELECT r.*, 'entrada' AS movimiento, fecha_entrada AS dia FROM reserva r
            WHERE estado<>'cancelada' AND fecha_entrada BETWEEN ? AND ?
            UNION ALL
            SELECT r.*, 'salida' AS movimiento, fecha_salida AS dia FROM reserva r
            WHERE estado<>'cancelada' AND fecha_salida BETWEEN ? AND ?
        ) movements ORDER BY dia, movimiento, id LIMIT 500");
        $statement->execute([$from, $to, $from, $to]);
        $rows = $statement->fetchAll();
        $total = isset($rows[0]) ? (int) $rows[0]['total_movimientos'] : 0;
        $data = [];
        foreach ($rows as $row) {
            unset($row['total_movimientos']);
            $data[] = $this->normalize($row);
        }
        return ['data' => $data, 'meta' => ['total' => $total, 'truncated' => $total > 500, 'desde' => $from, 'hasta' => $to]];
    }

    /** @return list<array<string,mixed>> */
    public function export(ReservationFilter $filter): array
    {
        [$where, $parameters] = $this->criteria($filter);
        $order = $this->order($filter);
        $statement = $this->pdo->prepare("SELECT * FROM reserva {$where} ORDER BY {$order} LIMIT 5001");
        $statement->execute($parameters);
        return array_values(array_map($this->normalize(...), $statement->fetchAll()));
    }

    private function order(ReservationFilter $filter): string
    {
        return match ($filter->sort) {
            'entrada' => 'fecha_entrada ASC, id DESC',
            'salida' => 'fecha_salida ASC, id DESC',
            'importe_desc' => 'importe DESC, id DESC',
            'huesped' => 'nombre_huesped ASC, id DESC',
            default => 'fecha_creacion DESC, id DESC',
        };
    }

    public function addEvent(int $id, string $type, string $description): void
    {
        $this->pdo->prepare('INSERT INTO reserva_evento (reserva_id, tipo, descripcion) VALUES (?, ?, ?)')
            ->execute([$id, $type, $description]);
    }

    /** @return array{string, array<string, string>} */
    private function criteria(ReservationFilter $filter): array
    {
        $clauses = [];
        $parameters = [];
        if ($filter->status !== null) {
            $clauses[] = 'estado = :status';
            $parameters[':status'] = $filter->status->value;
        }
        if ($filter->from !== '') {
            $clauses[] = 'fecha_salida > :from';
            $parameters[':from'] = $filter->from;
        }
        if ($filter->to !== '') {
            $clauses[] = 'fecha_entrada <= :to';
            $parameters[':to'] = $filter->to;
        }
        if ($filter->search !== '') {
            $clauses[] = "nombre_huesped LIKE :search ESCAPE '!'";
            $parameters[':search'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filter->search) . '%';
        }
        if ($filter->accommodation !== '') {
            $clauses[] = 'nombre_alojamiento = :accommodation';
            $parameters[':accommodation'] = $filter->accommodation;
        }
        foreach (['fecha_entrada' => $filter->arrival, 'fecha_salida' => $filter->departure] as $column => $date) {
            if ($date !== '') {
                $clauses[] = "{$column} = :{$column}";
                $parameters[":{$column}"] = $date;
            }
        }
        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $parameters];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['version'] = (int) $row['version'];
        $row['importe'] = (string) $row['importe'];
        $row['fecha_creacion'] = str_replace(' ', 'T', $row['fecha_creacion']) . 'Z';
        return $row;
    }
}
