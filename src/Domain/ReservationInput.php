<?php

declare(strict_types=1);

namespace App\Domain;

use App\Http\HttpException;

final readonly class ReservationInput
{
    public function __construct(
        public string $guestName,
        public string $guestEmail,
        public string $accommodation,
        public string $checkIn,
        public string $checkOut,
        public string $amount,
        public string $notes,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $allowed = ['nombre_huesped', 'email_huesped', 'nombre_alojamiento', 'fecha_entrada', 'fecha_salida', 'importe', 'notas'];
        if (array_diff(array_keys($data), $allowed) !== []) {
            $errors['_form'] = 'Hay campos no admitidos. El estado inicial es siempre pendiente.';
        }
        $text = static function (string $key, int $max, bool $required = true) use ($data, &$errors): string {
            $value = $data[$key] ?? '';
            if (!is_string($value)) {
                $errors[$key] = 'Debe ser un texto.';
                return '';
            }
            $value = trim($value);
            if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                $errors[$key] = 'Contiene caracteres no válidos.';
            } elseif ($required && $value === '') {
                $errors[$key] = 'Este campo es obligatorio.';
            } elseif (mb_strlen($value) > $max) {
                $errors[$key] = "Máximo {$max} caracteres.";
            }
            return $value;
        };
        $name = $text('nombre_huesped', 120);
        $email = $text('email_huesped', 254);
        $accommodation = $text('nombre_alojamiento', 160);
        $checkIn = $text('fecha_entrada', 10);
        $checkOut = $text('fecha_salida', 10);
        $notes = $text('notas', 2000, false);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email_huesped'] = 'Introduce un email válido.';
        }
        foreach (['fecha_entrada' => $checkIn, 'fecha_salida' => $checkOut] as $key => $value) {
            if (!self::validDate($value)) {
                $errors[$key] = 'Introduce una fecha real con formato AAAA-MM-DD.';
            }
        }
        if (!isset($errors['fecha_entrada'], $errors['fecha_salida']) && self::validDate($checkIn)
            && self::validDate($checkOut) && $checkOut <= $checkIn) {
            $errors['fecha_salida'] = 'La salida debe ser posterior a la entrada.';
        }
        $amount = $data['importe'] ?? '';
        if (is_int($amount)) {
            $amount = (string) $amount;
        }
        if (!is_string($amount) || !preg_match('/^(0|[1-9]\d{0,7})(\.\d{1,2})?$/D', $amount)) {
            $errors['importe'] = 'Introduce un importe entre 0 y 99999999.99, con un máximo de dos decimales.';
            $amount = '0';
        }
        if ($errors !== []) {
            throw new HttpException(400, 'Revisa los campos indicados.', $errors);
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return new self(
            $name,
            $email,
            $accommodation,
            $checkIn,
            $checkOut,
            $whole . '.' . str_pad($fraction, 2, '0'),
            $notes
        );
    }

    public static function validDate(string $value): bool
    {
        if (!preg_match('/^[1-9]\d{3}-\d{2}-\d{2}$/D', $value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
