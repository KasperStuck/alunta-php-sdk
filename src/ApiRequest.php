<?php

namespace KasperStuck\Alunta\Sdk;

/**
 * One Alunta API request as structured data: the transport turns it into HTTP. It never holds
 * the token; the transport adds that from the credentials of the call.
 */
final readonly class ApiRequest
{
    /**
     * @param  'GET'|'POST'|'PUT'|'DELETE'  $method
     * @param  string  $path  the path below the API URL, starting with a slash
     * @param  array<string, scalar>  $query  the query parameters, empty for none
     * @param  array<string, mixed>|null  $json  the JSON body, or null for none
     * @param  array<string, string>  $headers  extra headers, such as Idempotency-Key
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public ?array $json = null,
        public array $headers = [],
    ) {}
}
