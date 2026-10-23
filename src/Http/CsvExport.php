<?php

declare(strict_types=1);

namespace App\Http;

final class CsvExport
{
    /** @param list<array<string,mixed>> $rows */
    public static function response(array $rows): Response
    {
        if (count($rows) > 5000) {
            throw new HttpException(422, 'La exportación admite hasta 5.000 reservas. Acota los filtros.');
        }
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('No se puede preparar la exportación.');
        }
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            $keys = ['id', 'nombre_huesped', 'email_huesped', 'nombre_alojamiento', 'fecha_entrada', 'fecha_salida', 'estado', 'importe', 'notas'];
            fputcsv($stream, ['Reserva', 'Huésped', 'Email', 'Alojamiento', 'Entrada', 'Salida', 'Estado', 'Importe EUR', 'Notas'], ';', '"', '', "\r\n");
            foreach ($rows as $row) {
                $values = [];
                foreach ($keys as $key) {
                    $value = (string) $row[$key];
                    // Excel/LibreOffice must not interpret guest text as executable formulas.
                    $values[] = preg_match('/^[\s\x{FEFF}]*[=+@-]/u', $value) ? "'" . $value : $value;
                }
                fputcsv($stream, $values, ';', '"', '', "\r\n");
            }
            rewind($stream);
            $body = stream_get_contents($stream);
            if ($body === false) {
                throw new \RuntimeException('No se puede leer la exportación.');
            }
            return new Response($body, 200, ['Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="reservas.csv"']);
        } finally {
            fclose($stream);
        }
    }
}
