<?php

namespace KasperStuck\Alunta\Sdk;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;
use Throwable;

/**
 * One call of Psr18Transport, which documents the encoding and the failure handling.
 *
 * @internal
 */
final readonly class Psr18Call implements PreparedCall
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        #[SensitiveParameter] private Credentials $credentials,
    ) {}

    public function send(ApiRequest $request): ResponseInterface
    {
        try {
            $psrRequest = $this->psrRequest($request);
        } catch (Throwable $exception) {
            throw new TransportException($request, $exception::class);
        }

        try {
            return $this->client->sendRequest($psrRequest);
        } catch (ClientExceptionInterface $exception) {
            throw new TransportException($request, $exception::class);
        }
    }

    /**
     * @throws \JsonException when the body cannot be encoded, also when a JsonSerializable value
     *                        throws one
     * @throws \InvalidArgumentException when a header value is invalid, such as one with CR or LF
     */
    private function psrRequest(ApiRequest $request): RequestInterface
    {
        $uri = rtrim($this->credentials->baseUrl, '/').$request->path;

        if ($request->query !== []) {
            $uri .= '?'.http_build_query($request->query, '', '&', PHP_QUERY_RFC3986);
        }

        $psrRequest = $this->requestFactory->createRequest($request->method, $uri)
            ->withHeader('Authorization', trim('Bearer '.$this->credentials->token))
            ->withHeader('Accept', 'application/json');

        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }

        if ($request->json !== null) {
            $psrRequest = $psrRequest->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode($request->json, JSON_THROW_ON_ERROR)));
        }

        return $psrRequest;
    }
}
