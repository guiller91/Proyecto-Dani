<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\CsvExport;
use App\Http\HttpException;
use PHPUnit\Framework\TestCase;

final class CsvExportTest extends TestCase
{
    public function testCsvQuotesUnicodeNewlinesAndNeutralizesFormulaCells(): void
    {
        $response = CsvExport::response([['id' => 1, 'nombre_huesped' => '  =HYPERLINK("evil")',
            'email_huesped' => 'safe@example.test', 'nombre_alojamiento' => 'Casa; "Árbol"',
            'fecha_entrada' => '2028-01-01', 'fecha_salida' => '2028-01-02', 'estado' => 'pendiente',
            'importe' => '10.00', 'notas' => "@danger\nsecond line"]]);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, substr($response->body, 3));
        rewind($stream);
        fgetcsv($stream, 0, ';', '"', '');
        $row = fgetcsv($stream, 0, ';', '"', '');
        fclose($stream);
        self::assertSame("'  =HYPERLINK(\"evil\")", $row[1]);
        self::assertSame('Casa; "Árbol"', $row[3]);
        self::assertSame("'@danger\nsecond line", $row[8]);
    }

    public function testRefusesOversizedExportInsteadOfSilentlyTruncating(): void
    {
        $this->expectException(HttpException::class);
        CsvExport::response(array_fill(0, 5001, []));
    }
}
