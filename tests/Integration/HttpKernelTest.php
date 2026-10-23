<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\ReservationInputTest;

final class HttpKernelTest extends DatabaseTestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kernel = Application::kernel($this->config, 'test-token');
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        return $this->kernel->handle(new Request(
            $method,
            $path,
            [],
            ['content-type' => 'application/json', 'x-csrf-token' => 'test-token'],
            $body === null ? '' : json_encode((object) $body, JSON_THROW_ON_ERROR)
        ));
    }

    public function testApiLifecycleAndLocation(): void
    {
        $response = $this->request('POST', '/api/reservas', ReservationInputTest::valid());
        self::assertSame(201, $response->status);
        $created = json_decode($response->body, true)['data'];
        self::assertSame('/api/reservas/' . $created['id'], $response->headers['Location']);
        self::assertSame(200, $this->request('GET', $response->headers['Location'])->status);
        $changed = $this->request('PATCH', $response->headers['Location'] . '/estado', ['estado' => 'cancelada', 'version' => 1]);
        self::assertSame(200, $changed->status);
        self::assertSame('cancelada', json_decode($changed->body, true)['data']['estado']);
    }

    /** @return iterable<string, array{string,string,int}> */
    public static function routingCases(): iterable
    {
        yield 'missing API' => ['GET', '/api/unknown', 404];
        yield 'missing page' => ['GET', '/unknown', 404];
        yield 'unsupported method' => ['DELETE', '/api/reservas', 405];
        yield 'nonexistent reservation' => ['GET', '/api/reservas/4294967295', 404];
        yield 'invalid id' => ['GET', '/api/reservas/-1', 404];
        yield 'oversized id' => ['GET', '/api/reservas/999999999999999999999', 404];
    }

    #[DataProvider('routingCases')]
    public function testRoutingStatusCodes(string $method, string $path, int $status): void
    {
        $response = $this->request($method, $path);
        self::assertSame($status, $response->status);
        if ($status === 405) {
            self::assertSame('GET, POST, HEAD', $response->headers['Allow']);
        }
    }

    public function testMissingCsrfAndInvalidDataDoNotCreateRows(): void
    {
        self::assertSame(403, $this->kernel->handle(new Request('POST', '/api/reservas'))->status);
        $response = $this->request('POST', '/api/reservas', []);
        self::assertSame(400, $response->status);
        self::assertArrayHasKey('email_huesped', json_decode($response->body, true)['error']['fields']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM reserva')->fetchColumn());
    }

    public function testTwigViewsEscapeGuestDataAndNotes(): void
    {
        $reservation = $this->create(['nombre_huesped' => '<script>alert(1)</script>', 'notas' => '<img src=x onerror=alert(2)>']);
        foreach (['/', '/reservas/' . $reservation['id']] as $path) {
            $response = $this->request('GET', $path);
            self::assertSame(200, $response->status, $response->body);
            self::assertStringNotContainsString('<script>alert(1)</script>', $response->body);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $response->body);
            self::assertStringContainsString("script-src 'self'", $response->headers['Content-Security-Policy']);
        }
    }

    public function testDatabaseErrorsAreSanitizedAndCorrelated(): void
    {
        $this->pdo->exec("CREATE TRIGGER fail_history BEFORE INSERT ON reserva_evento FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SECRET SQL DETAILS'");
        try {
            $response = $this->request('POST', '/api/reservas', ReservationInputTest::valid());
            self::assertSame(500, $response->status);
            self::assertStringNotContainsString('SECRET', $response->body);
            self::assertStringNotContainsString('SQLSTATE', $response->body);
            self::assertSame($response->headers['X-Request-ID'], json_decode($response->body, true)['error']['request_id']);
        } finally {
            $this->pdo->exec('DROP TRIGGER fail_history');
        }
    }
}
