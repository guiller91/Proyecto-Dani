<?php

declare(strict_types=1);

namespace App\Http;

final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(public string $body, public int $status = 200, public array $headers = [])
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'] + $headers
        );
    }

    public function send(bool $head = false): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        if (!$head) {
            echo $this->body;
        }
    }
}
