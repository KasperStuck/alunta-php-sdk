<?php

namespace KasperStuck\Alunta\Sdk;

use Psr\Http\Message\ResponseInterface;

/**
 * An Alunta response with the API URL it came from.
 *
 * @internal
 */
final class ApiResponse
{
    private mixed $decoded = null;

    private bool $isDecoded = false;

    public function __construct(
        private readonly ResponseInterface $response,
        public readonly string $baseUrl,
    ) {}

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function successful(): bool
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    /**
     * The value at the dot-separated key of the decoded JSON body, or null when the body is not
     * JSON or the key is missing.
     */
    public function json(string $key): mixed
    {
        if (! $this->isDecoded) {
            $this->decoded = json_decode((string) $this->response->getBody(), true);
            $this->isDecoded = true;
        }

        $value = $this->decoded;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
