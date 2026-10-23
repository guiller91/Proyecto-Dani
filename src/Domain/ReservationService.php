<?php
declare(strict_types=1);

namespace App\Domain;

use App\Http\HttpException;
use App\Infrastructure\ReservationRepository;
use PDO;

final readonly class ReservationService
{
    public function __construct(private PDO $pdo, private ReservationRepository $repository) {}

    /** @return array<string, mixed> */
    public function detail(int $id): array
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

    /** @param array<string, mixed> $data @return array<string, mixed> */
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
        if ($errors !== [] || $status === null) {
            throw new HttpException(400, 'Revisa el cambio de estado.', $errors);
        }
        return $this->transaction(function () use ($id, $status, $version): array {
            $reservation = $this->repository->find($id, true);
            if ($reservation === null) {
                throw new HttpException(404, 'No encontramos esta reserva.');
            }
            if ($reservation['version'] !== $version) {
                throw new HttpException(409, 'La reserva ha cambiado. Actualiza la página antes de volver a intentarlo.');
            }
            $current = ReservationStatus::from($reservation['estado']);
            if (!$current->canTransitionTo($status)) {
                throw new HttpException(409, 'Una reserva cancelada no se puede reactivar.');
            }
            if ($current !== $status) {
                $this->repository->updateStatus($id, $status->value);
                $this->repository->addEvent($id, $status->value,
                    "Estado cambiado de {$current->value} a {$status->value}.");
            }
            return $this->detail($id);
        });
    }

    /** @param callable(): array<string, mixed> $operation @return array<string, mixed> */
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
