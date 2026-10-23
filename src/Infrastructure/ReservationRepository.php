<?php
declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\ReservationFilter;
use App\Domain\ReservationInput;
use PDO;

final readonly class ReservationRepository
{
    public function __construct(private PDO $pdo) {}

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
        $statement = $this->pdo->prepare("SELECT * FROM reserva {$where}
            ORDER BY fecha_creacion DESC, id DESC LIMIT :limit OFFSET :offset");
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue(':limit', $filter->perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return [
            'data' => array_map($this->normalize(...), $statement->fetchAll()),
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
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['reserva_id'] = (int) $row['reserva_id'];
            $row['fecha_creacion'] = str_replace(' ', 'T', $row['fecha_creacion']) . 'Z';
            return $row;
        }, $statement->fetchAll());
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
        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $parameters];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['version'] = (int) $row['version'];
        $row['importe'] = (string) $row['importe'];
        $row['fecha_creacion'] = str_replace(' ', 'T', $row['fecha_creacion']) . 'Z';
        return $row;
    }
}
