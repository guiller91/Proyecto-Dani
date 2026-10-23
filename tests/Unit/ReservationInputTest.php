<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ReservationInput;
use App\Http\HttpException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReservationInputTest extends TestCase
{
    /** @return array<string, string> */
    public static function valid(): array
    {
        return ['nombre_huesped' => '  Lucía Martín  ', 'email_huesped' => 'lucia@example.test',
            'nombre_alojamiento' => 'Casa del Olivo', 'fecha_entrada' => '2028-02-28',
            'fecha_salida' => '2028-02-29', 'importe' => '120.5', 'notas' => '  Cuna  '];
    }

    public function testNormalizesTextAndMoneyWithoutFloatingPoint(): void
    {
        $input = ReservationInput::fromArray(self::valid());
        self::assertSame('Lucía Martín', $input->guestName);
        self::assertSame('120.50', $input->amount);
        self::assertSame('Cuna', $input->notes);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidInputs(): iterable
    {
        yield 'required' => ['nombre_huesped', ' '];
        yield 'array injection' => ['nombre_huesped', ['bad']];
        yield 'length' => ['nombre_huesped', str_repeat('á', 121)];
        yield 'email' => ['email_huesped', 'invalid'];
        yield 'impossible date' => ['fecha_entrada', '2027-02-29'];
        yield 'date format' => ['fecha_entrada', '28/02/2028'];
        yield 'zero year' => ['fecha_entrada', '0000-01-01'];
        yield 'same day' => ['fecha_salida', '2028-02-28'];
        yield 'earlier departure' => ['fecha_salida', '2028-02-27'];
        yield 'negative money' => ['importe', '-1.00'];
        yield 'excess precision' => ['importe', '1.001'];
        yield 'overflow' => ['importe', '100000000.00'];
        yield 'float deliberately rejected' => ['importe', 12.34];
        yield 'null money' => ['importe', null];
        yield 'control characters' => ['notas', "Bad\x00notes"];
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInputs(string $field, mixed $value): void
    {
        $data = self::valid();
        $data[$field] = $value;
        try {
            ReservationInput::fromArray($data);
            self::fail('Expected validation error');
        } catch (HttpException $error) {
            self::assertSame(400, $error->status);
            self::assertArrayHasKey($field, $error->fields);
        }
    }

    public function testRejectsMassAssignment(): void
    {
        $this->expectException(HttpException::class);
        ReservationInput::fromArray(self::valid() + ['estado' => 'confirmada']);
    }

    public function testAllowsZeroAndLargestAmountAndOptionalNotes(): void
    {
        $data = self::valid();
        unset($data['notas']);
        $data['importe'] = '0';
        self::assertSame('0.00', ReservationInput::fromArray($data)->amount);
        $data['importe'] = '99999999.99';
        self::assertSame('99999999.99', ReservationInput::fromArray($data)->amount);
    }
}
