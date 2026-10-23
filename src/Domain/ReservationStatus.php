<?php

declare(strict_types=1);

namespace App\Domain;

enum ReservationStatus: string
{
    case Pending = 'pendiente';
    case Confirmed = 'confirmada';
    case Cancelled = 'cancelada';

    public function canTransitionTo(self $target): bool
    {
        return $target === $this || match ($this) {
            self::Pending => $target === self::Confirmed || $target === self::Cancelled,
            self::Confirmed => $target === self::Cancelled,
            self::Cancelled => false,
        };
    }
}
