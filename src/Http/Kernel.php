<?php

declare(strict_types=1);

namespace App\Http;

use Twig\Environment;

final readonly class Kernel
{
    public function __construct(private ReservationController $controller, private Environment $twig, private string $csrfToken)
    {
    }

    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(8));
        try {
            $response = $this->route($request);
        } catch (\Throwable $error) {
            $status = $error instanceof HttpException ? $error->status : 500;
            $message = $error instanceof HttpException ? $error->getMessage() : 'No hemos podido completar la operación. Inténtalo de nuevo.';
            if ($status >= 500) {
                // Deliberately exclude SQL, request bodies and guest PII from logs.
                error_log("[{$requestId}] " . $error::class . ' code=' . $error->getCode());
            }
            $headers = $error instanceof HttpException ? $error->headers : [];
            $response = str_starts_with($request->path, '/api/')
                ? Response::json(['error' => ['message' => $message, 'fields' => $error instanceof HttpException ? (object) $error->fields : new \stdClass(),
                    'request_id' => $requestId]], $status, $headers)
                : new Response($this->twig->render('error.html.twig', ['status' => $status, 'message' => $message, 'request_id' => $requestId]), $status, $headers);
        }
        return new Response($response->body, $response->status, $response->headers + [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Request-ID' => $requestId,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ]);
    }

    private function route(Request $request): Response
    {
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $path = $request->path;
        $allowed = match (true) {
            $path === '/', $path === '/reservas' => ['GET'],
            $path === '/api/session' => ['GET'],
            $path === '/api/reservas' => ['GET', 'POST'],
            $path === '/api/reservas/lote' => ['POST'],
            in_array($path, ['/api/overview', '/api/agenda', '/api/reservas/exportar'], true) => ['GET'],
            (bool) preg_match('#^/api/reservas/[1-9]\d{0,9}/estado$#D', $path) => ['PATCH'],
            (bool) preg_match('#^/api/reservas/[1-9]\d{0,9}$#D', $path) => ['GET', 'PATCH'],
            (bool) preg_match('#^/reservas/[1-9]\d{0,9}$#D', $path) => ['GET'],
            default => throw new HttpException(404, 'No encontramos esta página o recurso.'),
        };
        if (!in_array($method, $allowed, true)) {
            throw new HttpException(405, 'Método no permitido.', [], ['Allow' => implode(', ', in_array('GET', $allowed, true) ? [...$allowed, 'HEAD'] : $allowed)]);
        }
        if (in_array($method, ['POST', 'PATCH'], true)) {
            Csrf::verify($request, $this->csrfToken);
        }
        if ($path === '/api/session') {
            return Response::json(['csrf_token' => $this->csrfToken]);
        }
        if ($path === '/' || $path === '/reservas') {
            return $this->controller->indexPage($request);
        }
        if ($path === '/api/reservas') {
            return $method === 'GET' ? $this->controller->listing($request) : $this->controller->create($request);
        }
        if ($path === '/api/reservas/lote') {
            return $this->controller->bulkStatus($request);
        }
        if ($path === '/api/overview') {
            return $this->controller->overview();
        }
        if ($path === '/api/agenda') {
            return $this->controller->agenda($request);
        }
        if ($path === '/api/reservas/exportar') {
            return $this->controller->export($request);
        }
        if (!preg_match('#/reservas/(\d+)#', $path, $matches)) {
            throw new HttpException(404, 'No encontramos esta reserva.');
        }
        $id = (int) $matches[1];
        if (str_ends_with($path, '/estado')) {
            return $this->controller->changeStatus($id, $request);
        }
        if ($method === 'PATCH') {
            return $this->controller->update($id, $request);
        }
        return str_starts_with($path, '/api/') ? $this->controller->detail($id) : $this->controller->detailPage($id);
    }
}
