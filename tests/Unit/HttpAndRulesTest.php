<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ReservationFilter;
use App\Domain\ReservationStatus;
use App\Http\Csrf;
use App\Http\HttpException;
use App\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpAndRulesTest extends TestCase
{
    public function testStatusTransitionMatrix(): void
    {
        $allowed = ['pendiente' => ['pendiente', 'confirmada', 'cancelada'],
            'confirmada' => ['confirmada', 'cancelada'], 'cancelada' => ['cancelada']];
        foreach (ReservationStatus::cases() as $from) {
            foreach (ReservationStatus::cases() as $to) {
                self::assertSame(in_array($to->value, $allowed[$from->value], true), $from->canTransitionTo($to));
            }
        }
    }

    public function testCombinesFilters(): void
    {
        $filter = ReservationFilter::fromQuery(['estado' => 'confirmada', 'desde' => '2028-01-01', 'hasta' => '2028-02-01', 'q' => ' Lucía ', 'page' => '2', 'per_page' => '25']);
        self::assertSame(ReservationStatus::Confirmed, $filter->status);
        self::assertSame('Lucía', $filter->search);
        self::assertSame(25, $filter->perPage);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badFilters(): iterable
    {
        yield 'state' => [['estado' => 'invalid']];
        yield 'range' => [['desde' => '2028-03-01', 'hasta' => '2028-02-01']];
        yield 'array' => [['q' => ['injection']]];
        yield 'page' => [['page' => '0']];
        yield 'limit' => [['per_page' => '101']];
        yield 'invalid date' => [['desde' => '2028-02-30']];
    }

    #[DataProvider('badFilters')]
    public function testRejectsBadFilters(array $query): void
    {
        $this->expectException(HttpException::class);
        ReservationFilter::fromQuery($query);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function invalidBodies(): iterable
    {
        yield 'malformed' => ['{"bad"', 'application/json', 400];
        yield 'list' => ['[]', 'application/json', 400];
        yield 'null' => ['null', 'application/json', 400];
        yield 'content type' => ['{}', 'text/plain', 415];
        yield 'oversize' => [str_repeat('x', 16385), 'application/json', 413];
    }

    #[DataProvider('invalidBodies')]
    public function testRejectsBadRequestBody(string $body, string $type, int $status): void
    {
        try {
            (new Request('POST', '/api/reservas', [], ['content-type' => $type], $body))->json();
            self::fail('Expected request error');
        } catch (HttpException $error) {
            self::assertSame($status, $error->status);
        }
    }

    public function testAcceptsJsonObjectWithCharset(): void
    {
        self::assertSame(['name' => 'Lucía'], (new Request(
            'POST',
            '/',
            [],
            ['content-type' => 'application/json; charset=UTF-8'],
            '{"name":"Lucía"}'
        ))->json());
    }

    public function testCsrfRejectsMissingAndCrossSiteTokens(): void
    {
        foreach ([[], ['x-csrf-token' => 'bad'], ['x-csrf-token' => 'valid', 'sec-fetch-site' => 'cross-site']] as $headers) {
            try {
                Csrf::verify(new Request('POST', '/', [], $headers), 'valid');
                self::fail('Expected CSRF error');
            } catch (HttpException $error) {
                self::assertSame(403, $error->status);
            }
        }
        Csrf::verify(new Request('POST', '/', [], ['x-csrf-token' => 'valid']), 'valid');
        self::addToAssertionCount(1);
    }
}
