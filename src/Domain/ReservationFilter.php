<?php

declare(strict_types=1);

namespace App\Domain;

use App\Http\HttpException;

final readonly class ReservationFilter
{
    public function __construct(
        public ?ReservationStatus $status = null,
        public string $from = '',
        public string $to = '',
        public string $search = '',
        public int $page = 1,
        public int $perPage = 10,
        public string $accommodation = '',
        public string $sort = 'recientes',
        public string $arrival = '',
        public string $departure = '',
    ) {
    }

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query): self
    {
        $errors = [];
        $read = static function (string $key) use ($query, &$errors): string {
            $value = $query[$key] ?? '';
            if (!is_string($value)) {
                $errors[$key] = 'Parámetro inválido.';
                return '';
            }
            return trim($value);
        };
        $state = $read('estado');
        $from = $read('desde');
        $to = $read('hasta');
        $search = $read('q');
        $page = $read('page');
        $perPage = $read('per_page');
        $accommodation = $read('alojamiento');
        $sort = $read('orden') ?: 'recientes';
        $arrival = $read('entrada');
        $departure = $read('salida');
        if (!in_array($sort, ['recientes', 'entrada', 'salida', 'importe_desc', 'huesped'], true)) {
            $errors['orden'] = 'Orden no válido.';
        }
        if (mb_strlen($accommodation) > 160 || !mb_check_encoding($accommodation, 'UTF-8')) {
            $errors['alojamiento'] = 'El alojamiento admite hasta 160 caracteres UTF-8.';
        }
        $status = ReservationStatus::tryFrom($state);
        if ($state !== '' && $status === null) {
            $errors['estado'] = 'Estado no válido.';
        }
        foreach (['desde' => $from, 'hasta' => $to, 'entrada' => $arrival, 'salida' => $departure] as $key => $value) {
            if ($value !== '' && !ReservationInput::validDate($value)) {
                $errors[$key] = 'Introduce una fecha válida.';
            }
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            $errors['hasta'] = 'El final del intervalo no puede ser anterior al inicio.';
        }
        if (mb_strlen($search) > 120 || !mb_check_encoding($search, 'UTF-8')) {
            $errors['q'] = 'La búsqueda admite hasta 120 caracteres UTF-8.';
        }
        foreach (['page' => [$page, 100000], 'per_page' => [$perPage, 100]] as $key => [$value, $max]) {
            if ($value !== '' && (!ctype_digit($value) || (int) $value < 1 || (int) $value > $max)) {
                $errors[$key] = "Debe ser un entero entre 1 y {$max}.";
            }
        }
        if ($errors !== []) {
            throw new HttpException(400, 'Revisa los filtros.', $errors);
        }
        return new self(
            $status,
            $from,
            $to,
            $search,
            $page === '' ? 1 : (int) $page,
            $perPage === '' ? 10 : (int) $perPage,
            $accommodation,
            $sort,
            $arrival,
            $departure
        );
    }
}
