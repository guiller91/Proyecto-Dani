<?php
declare(strict_types=1);

namespace App\Http;

final readonly class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $headers = [],
        public string $body = '',
    ) {}

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        $headers['content-type'] = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        $body = file_get_contents('php://input', false, null, 0, 16385);
        return new self((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/',
            $_GET, $headers, $body === false ? '' : $body);
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if (strlen($this->body) > 16384) {
            throw new HttpException(413, 'El cuerpo de la petición supera los 16 KB.');
        }
        if (strtolower(trim(explode(';', $this->headers['content-type'] ?? '')[0])) !== 'application/json') {
            throw new HttpException(415, 'Utiliza Content-Type: application/json.');
        }
        try {
            $object = json_decode($this->body, false, 32, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) {
                throw new HttpException(400, 'El cuerpo debe ser un objeto JSON.');
            }
            return get_object_vars($object);
        } catch (\JsonException) {
            throw new HttpException(400, 'El JSON no es válido.');
        }
    }
}
