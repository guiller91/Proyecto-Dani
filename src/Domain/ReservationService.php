<?php

declare(strict_types=1);

namespace App\Domain;

use App\Http\HttpException;
use App\Infrastructure\ReservationRepository;
use PDO;

final readonly class ReservationService
{
    public function __construct(private PDO $pdo, private ReservationRepository $repository)
    {
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        return $this->pdo->inTransaction()
            ? $this->loadDetail($id)
            : $this->transaction(fn (): array => $this->loadDetail($id));
    }

    /** @return array<string, mixed> */
    private function loadDetail(int $id): array
    {
        $reservation = $this->repository->find($id);
        if ($reservation === null) {
            throw new HttpException(404, 'No encontramos esta reserva.');
        }
        $reservation['eventos'] = $this->repository->events($id);
        return $reservation;
    }

    /** @return array<string, mixed> */
    public function create(ReservationInput $input): array
    {
        return $this->transaction(function () use ($input): array {
            $id = $this->repository->insert($input);
            $this->repository->addEvent($id, 'creada', 'Reserva creada con estado pendiente.');
            return $this->detail($id);
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function changeStatus(int $id, array $data): array
    {
        $errors = [];
        $status = isset($data['estado']) && is_string($data['estado']) ? ReservationStatus::tryFrom($data['estado']) : null;
        if ($status === null || $status === ReservationStatus::Pending) {
            $errors['estado'] = 'El estado debe ser confirmada o cancelada.';
        }
        $version = $data['version'] ?? null;
        if (!is_int($version) || $version < 1 || $version > 2147483647) {
            $errors['version'] = 'Incluye la versión entera de la reserva que estás modificando.';
        }
        if (array_diff(array_keys($data), ['estado', 'version']) !== []) {
            $errors['_form'] = 'Hay campos no admitidos.';
        }
        if ($errors !== []) {
            throw new HttpException(400, 'Revisa el cambio de estado.', $errors);
        }
        return $this->transaction(fn (): array => $this->applyStatus($id, $status, $version));
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(int $id, array $data): array
    {
        $version = $data['version'] ?? null;
        if (!is_int($version) || $version < 1 || $version > 2147483647) {
            throw new HttpException(400, 'Incluye una versión válida.', ['version' => 'La versión debe ser un entero positivo.']);
        }
        unset($data['version']);
        $input = ReservationInput::fromArray($data);
        return $this->transaction(function () use ($id, $version, $input): array {
            $reservation = $this->locked($id, $version);
            if ($reservation['estado'] === 'cancelada') {
                throw new HttpException(409, 'Las reservas canceladas no se pueden editar.');
            }
            $fields = ['nombre_huesped' => $input->guestName, 'email_huesped' => $input->guestEmail,
                'nombre_alojamiento' => $input->accommodation, 'fecha_entrada' => $input->checkIn,
                'fecha_salida' => $input->checkOut, 'importe' => $input->amount, 'notas' => $input->notes];
            $labels = ['nombre_huesped' => 'huésped', 'email_huesped' => 'email', 'nombre_alojamiento' => 'alojamiento',
                'fecha_entrada' => 'entrada', 'fecha_salida' => 'salida', 'importe' => 'importe', 'notas' => 'notas'];
            $changed = [];
            foreach ($fields as $key => $value) {
                if ($reservation[$key] !== $value) {
                    $changed[] = $labels[$key];
                }
            }
            if ($changed !== []) {
                $this->repository->update($id, $input);
                $this->repository->addEvent($id, 'actualizada', 'Datos actualizados: ' . implode(', ', $changed) . '.');
            }
            return $this->detail($id);
        });
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function bulkStatus(array $data): array
    {
        $status = isset($data['estado']) && is_string($data['estado']) ? ReservationStatus::tryFrom($data['estado']) : null;
        $items = $data['reservas'] ?? null;
        if ($status === null || $status === ReservationStatus::Pending || !is_array($items) || !array_is_list($items)
            || count($items) < 1 || count($items) > 50 || array_diff(array_keys($data), ['estado', 'reservas']) !== []) {
            throw new HttpException(400, 'Selecciona entre 1 y 50 reservas y un estado de destino válido.');
        }
        $versions = [];
        foreach ($items as $item) {
            $item = $item instanceof \stdClass ? get_object_vars($item) : $item;
            if (!is_array($item) || !isset($item['id'], $item['version']) || !is_int($item['id']) || $item['id'] < 1
                || $item['id'] > 4294967295 || !is_int($item['version']) || $item['version'] < 1
                || $item['version'] > 2147483647 || isset($versions[$item['id']])
                || array_diff(array_keys($item), ['id', 'version']) !== []) {
                throw new HttpException(400, 'Cada reserva debe tener un ID único y una versión válidos.');
            }
            $versions[$item['id']] = $item['version'];
        }
        ksort($versions); // Lock consistently to reduce deadlock risk across overlapping batches.
        return $this->transaction(function () use ($versions, $status): array {
            $result = [];
            foreach ($versions as $id => $version) {
                $result[] = $this->applyStatus($id, $status, $version);
            }
            return ['data' => $result, 'updated' => count($result)];
        });
    }

    /** @return array<string,mixed> */
    private function locked(int $id, int $version): array
    {
        $reservation = $this->repository->find($id, true);
        if ($reservation === null) {
            throw new HttpException(404, 'No encontramos esta reserva.');
        }
        if ($reservation['version'] !== $version) {
            throw new HttpException(409, 'La reserva ha cambiado. Actualiza la página antes de volver a intentarlo.');
        }
        return $reservation;
    }

    /** @return array<string,mixed> */
    private function applyStatus(int $id, ReservationStatus $status, int $version): array
    {
        $reservation = $this->locked($id, $version);
        $current = ReservationStatus::from($reservation['estado']);
        if (!$current->canTransitionTo($status)) {
            throw new HttpException(409, "La reserva #{$id} está cancelada y no se puede reactivar. No se ha aplicado ningún cambio.");
        }
        if ($current !== $status) {
            $this->repository->updateStatus($id, $status->value);
            $this->repository->addEvent($id, $status->value, "Estado cambiado de {$current->value} a {$status->value}.");
        }
        return $this->detail($id);
    }

    /**
     * @param callable(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function transaction(callable $operation): array
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
