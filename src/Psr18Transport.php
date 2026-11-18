<?php

namespace KasperStuck\Alunta\Sdk;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;

/**
 * Sends through any PSR-18 client, for use without a framework. It encodes each request itself:
 * the API URL with the path and the query encoded per RFC 3986, the token as
 * "Authorization: Bearer <token>" trimmed like Laravel's withToken(), "Accept: application/json",
 * the extra headers, and a JSON body with "Content-Type: application/json" only when there is one.
 *
 * Redirects and timeouts are the PSR-18 client's job: configure it never to follow redirects, so
 * the token never reaches another host, and with AluntaClient's timeouts. This transport sends
 * each request once and returns the redirect as it came. A request that cannot be built, and a
 * ClientExceptionInterface from the PSR-18 client, become a TransportException; any other
 * exception from the PSR-18 client escapes unchanged.
 */
final readonly class Psr18Transport implements Transport
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function prepareCall(#[SensitiveParameter] Credentials $credentials): PreparedCall
    {
        return new Psr18Call($this->client, $this->requestFactory, $this->streamFactory, $credentials);
    }
}
