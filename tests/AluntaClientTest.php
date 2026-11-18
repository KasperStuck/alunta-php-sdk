<?php

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use KasperStuck\Alunta\Sdk\AluntaApiException;
use KasperStuck\Alunta\Sdk\AluntaClient;
use KasperStuck\Alunta\Sdk\ApiRequest;
use KasperStuck\Alunta\Sdk\Credentials;
use KasperStuck\Alunta\Sdk\CredentialsProvider;
use KasperStuck\Alunta\Sdk\PreparedCall;
use KasperStuck\Alunta\Sdk\Psr18Transport;
use KasperStuck\Alunta\Sdk\RequestPacer;
use KasperStuck\Alunta\Sdk\Sleeper;
use KasperStuck\Alunta\Sdk\Transport;
use KasperStuck\Alunta\Sdk\TransportException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;

const SDK_API_URL = 'https://app.alunta.com/api/v1';
const SDK_TOKEN = 'sdk-test-token-3b8e1f0c9a7d';
const SDK_PLAN_UUID = 'a1a1a1a1-0000-4000-8000-000000000002';
const SDK_SUBSCRIPTION_UUID = '123e4567-e89b-12d3-a456-426614174000';
const SDK_SESSION_UUID = '550e8400-e29b-41d4-a716-446655440000';
const SDK_CUSTOMER_UUID = '456e7890-e89b-12d3-a456-426614174001';

/**
 * An in-memory PSR-18 client that answers from a queue and keeps every request it got.
 */
final class SdkFakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $sent = [];

    /**
     * @param  list<ResponseInterface|Throwable>  $answers
     */
    public function __construct(private array $answers) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;
        $answer = count($this->answers) > 1 ? array_shift($this->answers) : ($this->answers[0] ?? new Response(500));

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }
}

/**
 * A sleeper that only records the waits, as ['s', seconds] or ['ms', milliseconds].
 */
final class SdkFakeSleeper implements Sleeper
{
    /** @var list<array{0: string, 1: int}> */
    public array $waits = [];

    public function sleepSeconds(int $seconds): void
    {
        $this->waits[] = ['s', $seconds];
    }

    public function sleepMilliseconds(int $milliseconds): void
    {
        $this->waits[] = ['ms', $milliseconds];
    }
}

/**
 * A PSR-3 logger that keeps every record.
 */
final class SdkMemoryLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

/**
 * A credentials provider that answers from a queue, repeating the last answer.
 */
final class SdkQueuedCredentials implements CredentialsProvider
{
    public int $reads = 0;

    /**
     * @param  list<?Credentials>  $answers
     */
    public function __construct(private array $answers) {}

    public function credentials(): ?Credentials
    {
        $this->reads++;

        return count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];
    }
}

/**
 * The SDK client wired to fakes, with the fakes for assertions.
 *
 * @param  list<ResponseInterface|Throwable>  $answers
 * @param  list<?Credentials>|null  $credentials
 * @return object{client: AluntaClient, http: SdkFakeHttpClient, sleeper: SdkFakeSleeper, logger: SdkMemoryLogger, pacer: RequestPacer, credentials: SdkQueuedCredentials}
 */
function sdkHarness(array $answers = [], ?array $credentials = null): object
{
    $factory = new HttpFactory;
    $http = new SdkFakeHttpClient($answers);
    $sleeper = new SdkFakeSleeper;
    $logger = new SdkMemoryLogger;
    $pacer = new RequestPacer;
    $provider = new SdkQueuedCredentials($credentials ?? [new Credentials(SDK_API_URL, SDK_TOKEN)]);

    return (object) [
        'client' => new AluntaClient(new Psr18Transport($http, $factory, $factory), $provider, $sleeper, $pacer, $logger),
        'http' => $http,
        'sleeper' => $sleeper,
        'logger' => $logger,
        'pacer' => $pacer,
        'credentials' => $provider,
    ];
}

/**
 * A JSON response.
 *
 * @param  array<string, string>  $headers
 */
function sdkJson(mixed $body, int $status = 200, array $headers = []): Response
{
    return new Response($status, ['Content-Type' => 'application/json', ...$headers], json_encode($body, JSON_THROW_ON_ERROR));
}

/**
 * @return array<string, mixed>
 */
function sdkSubscription(array $overrides = []): array
{
    return ['uuid' => SDK_SUBSCRIPTION_UUID, 'status' => 'active', ...$overrides];
}

/**
 * The Alunta exception the call throws.
 */
function sdkCaught(Closure $call): AluntaApiException
{
    try {
        $call();
    } catch (AluntaApiException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected an AluntaApiException.');
}

it('sends the checkout body as the exact JSON bytes with the token, an idempotency key and JSON headers', function () {
    $harness = sdkHarness([sdkJson(['data' => [
        'id' => SDK_SESSION_UUID,
        'checkout_url' => 'https://app.alunta.com/checkout/'.SDK_SESSION_UUID,
        'expires_at' => '2026-04-02T10:30:00.000000Z',
    ]], 201)]);

    $session = $harness->client->createSubscriptionCheckout(
        SDK_PLAN_UUID,
        'shop-1',
        'https://app.example.test/subscription',
        'https://app.example.test/subscription?payment=processing',
        ['checkout_attempt' => '17', 'trial_enabled' => false, 'name' => 'Æble & Ø "ApS"'],
        'attempt-key',
    );

    $request = $harness->http->sent[0];
    $body = '{"type":"subscription","plan_id":"a1a1a1a1-0000-4000-8000-000000000002","external_customer_id":"shop-1","back_url":"https:\/\/app.example.test\/subscription","success_url":"https:\/\/app.example.test\/subscription?payment=processing","metadata":{"checkout_attempt":"17","trial_enabled":false,"name":"\\u00c6ble & \\u00d8 \"ApS\""}}';

    expect($session)->toBe([
        'id' => SDK_SESSION_UUID,
        'checkout_url' => 'https://app.alunta.com/checkout/'.SDK_SESSION_UUID,
        'expires_at' => '2026-04-02T10:30:00.000000Z',
    ])
        ->and($harness->http->sent)->toHaveCount(1)
        ->and($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe(SDK_API_URL.'/checkout-sessions')
        ->and((string) $request->getBody())->toBe($body)
        ->and($body)->toBe(json_encode([
            'type' => 'subscription',
            'plan_id' => SDK_PLAN_UUID,
            'external_customer_id' => 'shop-1',
            'back_url' => 'https://app.example.test/subscription',
            'success_url' => 'https://app.example.test/subscription?payment=processing',
            'metadata' => ['checkout_attempt' => '17', 'trial_enabled' => false, 'name' => 'Æble & Ø "ApS"'],
        ], JSON_THROW_ON_ERROR))
        ->and($request->getHeaders())->toBe([
            'Host' => ['app.alunta.com'],
            'Authorization' => ['Bearer '.SDK_TOKEN],
            'Accept' => ['application/json'],
            'Idempotency-Key' => ['attempt-key'],
            'Content-Type' => ['application/json'],
        ]);
});

it('sends a read without a body or content type and builds the query with RFC 3986 encoding', function () {
    $harness = sdkHarness([sdkJson(['data' => [sdkSubscription()]])]);

    $harness->client->listSubscriptionsByExternalCustomer('shop-æ ø+&=/');

    $request = $harness->http->sent[0];
    expect($request->getMethod())->toBe('GET')
        ->and((string) $request->getUri())->toBe(SDK_API_URL.'/subscriptions?external_customer_id=shop-%C3%A6%20%C3%B8%2B%26%3D%2F&per_page=100')
        ->and((string) $request->getBody())->toBe('')
        ->and($request->getHeaders())->toBe([
            'Host' => ['app.alunta.com'],
            'Authorization' => ['Bearer '.SDK_TOKEN],
            'Accept' => ['application/json'],
        ]);
});

it('sends every operation to its documented path and body', function () {
    $subscription = sdkSubscription();
    $harness = sdkHarness([
        sdkJson(['data' => ['id' => SDK_SESSION_UUID, 'status' => 'completed', 'can_restart' => false, 'subscription_uuid' => SDK_SUBSCRIPTION_UUID]]),
        sdkJson(['data' => $subscription]),
        sdkJson(['data' => [['uuid' => '789e0123-e89b-12d3-a456-426614174002']]]),
        sdkJson(['data' => ['uuid' => '789e0123-e89b-12d3-a456-426614174002', 'status' => 'paid']]),
        sdkJson(['data' => [['uuid' => 'aa0e8400-e29b-41d4-a716-446655440001', 'amount' => 100]]]),
        sdkJson(['data' => ['end_date' => '2026-04-30T21:59:59.999999Z', 'notice_applied' => false]]),
        sdkJson(['data' => $subscription]),
        sdkJson(['data' => $subscription]),
        sdkJson(['data' => $subscription, 'meta' => ['scheduled_change_uuid' => 'aa0e8400-e29b-41d4-a716-446655440009', 'effective_date' => '2026-05-01']]),
        sdkJson(['data' => $subscription]),
        sdkJson(['data' => ['url' => 'https://portal.example.com/verify/1?signature=abc', 'expires_at' => '2026-03-03T12:15:00.000000Z']]),
        sdkJson(['team_uuid' => '123e4567-e89b-12d3-a456-426614174010', 'team_name' => 'Acme ApS']),
    ]);
    $client = $harness->client;
    $invoice = '789e0123-e89b-12d3-a456-426614174002';

    expect($client->getCheckoutSession(SDK_SESSION_UUID))->toBe(['status' => 'completed', 'can_restart' => false, 'subscription_uuid' => SDK_SUBSCRIPTION_UUID])
        ->and($client->getSubscription(SDK_SUBSCRIPTION_UUID))->toBe($subscription)
        ->and($client->listSubscriptionTransactions(SDK_SUBSCRIPTION_UUID))->toBe([['uuid' => $invoice]])
        ->and($client->getInvoice($invoice))->toBe(['uuid' => $invoice, 'status' => 'paid'])
        ->and($client->listInvoicePayments($invoice))->toBe([['uuid' => 'aa0e8400-e29b-41d4-a716-446655440001', 'amount' => 100]])
        ->and($client->cancelPreview(SDK_SUBSCRIPTION_UUID))->toBe(['end_date' => '2026-04-30T21:59:59.999999Z', 'notice_applied' => false])
        ->and($client->cancel(SDK_SUBSCRIPTION_UUID))->toBe($subscription)
        ->and($client->resume(SDK_SUBSCRIPTION_UUID))->toBe($subscription)
        ->and($client->changePlan(SDK_SUBSCRIPTION_UUID, SDK_PLAN_UUID, 12, '2026-05-01'))->toBe([
            'subscription' => $subscription,
            'scheduled_change_uuid' => 'aa0e8400-e29b-41d4-a716-446655440009',
            'effective_date' => '2026-05-01',
        ])
        ->and($client->deleteScheduledChange(SDK_SUBSCRIPTION_UUID, 'aa0e8400-e29b-41d4-a716-446655440009'))->toBe($subscription)
        ->and($client->portalLink(SDK_CUSTOMER_UUID))->toBe(['url' => 'https://portal.example.com/verify/1?signature=abc', 'expires_at' => '2026-03-03T12:15:00.000000Z'])
        ->and($client->me())->toBe(['team_uuid' => '123e4567-e89b-12d3-a456-426614174010', 'team_name' => 'Acme ApS']);

    $base = SDK_API_URL.'/subscriptions/'.SDK_SUBSCRIPTION_UUID;
    $wire = array_map(fn (RequestInterface $request): string => $request->getMethod().' '.$request->getUri().' '.$request->getBody(), $harness->http->sent);

    expect($wire)->toBe([
        'GET '.SDK_API_URL.'/checkout-sessions/'.SDK_SESSION_UUID.' ',
        'GET '.$base.' ',
        'GET '.SDK_API_URL.'/subscription-transactions?membership_uuid='.SDK_SUBSCRIPTION_UUID.'&per_page=100 ',
        'GET '.SDK_API_URL.'/invoices/'.$invoice.' ',
        'GET '.SDK_API_URL.'/payments?invoice_uuid='.$invoice.'&per_page=100 ',
        'GET '.$base.'/cancel-preview?immediate=0 ',
        'POST '.$base.'/cancel ',
        'POST '.$base.'/resume ',
        'PUT '.$base.'/plan {"plan_uuid":"'.SDK_PLAN_UUID.'","interval":12,"effective_date":"2026-05-01"}',
        'DELETE '.$base.'/scheduled-changes/aa0e8400-e29b-41d4-a716-446655440009 ',
        'POST '.SDK_API_URL.'/portal-link/'.SDK_CUSTOMER_UUID.' {"expires_in_minutes":15}',
        'GET '.SDK_API_URL.'/me ',
    ]);
});

it('leaves the effective date out of a plan change without one', function () {
    $harness = sdkHarness([sdkJson(['data' => sdkSubscription()])]);

    expect($harness->client->changePlan(SDK_SUBSCRIPTION_UUID, SDK_PLAN_UUID, 1))->toBe([
        'subscription' => sdkSubscription(),
        'scheduled_change_uuid' => null,
        'effective_date' => null,
    ])
        ->and((string) $harness->http->sent[0]->getBody())->toBe('{"plan_uuid":"'.SDK_PLAN_UUID.'","interval":1}');
});

it('retries a rate limited request after its Retry-After delay', function () {
    $harness = sdkHarness([
        sdkJson(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '2']),
        sdkJson(['data' => sdkSubscription()]),
    ]);

    expect($harness->client->getSubscription(SDK_SUBSCRIPTION_UUID)['uuid'])->toBe(SDK_SUBSCRIPTION_UUID)
        ->and($harness->http->sent)->toHaveCount(2)
        ->and($harness->sleeper->waits)->toBe([['s', 2]]);
});

it('gives up on a rate limit after three attempts with the Retry-After clamped to 10 seconds', function () {
    $harness = sdkHarness([sdkJson(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '600'])]);

    $exception = sdkCaught(fn () => $harness->client->getSubscription(SDK_SUBSCRIPTION_UUID));

    expect($exception->errorCode)->toBe(AluntaApiException::RATE_LIMITED)
        ->and($exception->status)->toBe(429)
        ->and($exception->getMessage())->toBe('Alunta har for mange forespørgsler lige nu. Prøv igen om lidt.')
        ->and($harness->http->sent)->toHaveCount(3)
        ->and($harness->sleeper->waits)->toBe([['s', 10], ['s', 10]]);
});

it('waits one second for a Retry-After that is missing, zero or not a whole number of seconds', function (?string $retryAfter) {
    $headers = $retryAfter === null ? [] : ['Retry-After' => $retryAfter];
    $harness = sdkHarness([sdkJson([], 429, $headers), sdkJson(['data' => sdkSubscription()])]);

    $harness->client->getSubscription(SDK_SUBSCRIPTION_UUID);

    expect($harness->sleeper->waits)->toBe([['s', 1]]);
})->with([
    'missing' => [null],
    'zero' => ['0'],
    'a date' => ['Wed, 21 Oct 2026 07:28:00 GMT'],
    'negative' => ['-5'],
]);

it('paces every request after the first, including a retry, until pacing stops', function () {
    $harness = sdkHarness([
        sdkJson(['data' => sdkSubscription()]),
        sdkJson([], 429, ['Retry-After' => '3']),
        sdkJson(['data' => sdkSubscription()]),
        sdkJson(['data' => sdkSubscription()]),
    ]);
    $harness->pacer->pace(700);

    $harness->client->getSubscription(SDK_SUBSCRIPTION_UUID);
    $harness->client->getSubscription(SDK_SUBSCRIPTION_UUID);

    expect($harness->sleeper->waits)->toBe([['ms', 700], ['s', 3], ['ms', 700]]);

    $harness->pacer->pace(null);
    $harness->client->getSubscription(SDK_SUBSCRIPTION_UUID);

    expect($harness->sleeper->waits)->toHaveCount(3);
});

it('starts a new pacing run without a wait before its first request', function () {
    $harness = sdkHarness([sdkJson(['data' => sdkSubscription()])]);
    $harness->pacer->pace(700);
    $harness->client->getSubscription(SDK_SUBSCRIPTION_UUID);

    $harness->pacer->pace(500);
    $harness->client->getSubscription(SDK_SUBSCRIPTION_UUID);
    $harness->client->getSubscription(SDK_SUBSCRIPTION_UUID);

    expect($harness->sleeper->waits)->toBe([['ms', 500]]);
});

it('maps a rejected request to its error code and Danish message', function (int $status, string $errorCode, string $message) {
    $harness = sdkHarness([sdkJson(['message' => 'Provider says no. '.SDK_TOKEN], $status, ['X-Request-Id' => 'req-42'])]);

    $exception = sdkCaught(fn () => $harness->client->resume(SDK_SUBSCRIPTION_UUID));

    expect($exception->errorCode)->toBe($errorCode)
        ->and($exception->status)->toBe($status)
        ->and($exception->getMessage())->toBe($message)
        ->and($exception->getPrevious())->toBeNull()
        ->and($exception->providerMessage)->toBe(in_array($status, [409, 422], true) ? 'Provider says no. '.SDK_TOKEN : null)
        ->and(end($harness->logger->records))->toBe([
            'level' => 'warning',
            'message' => 'Alunta API request was not successful.',
            'context' => ['operation' => 'resume', 'status' => $status, 'error_code' => $errorCode, 'request_id' => 'req-42'],
        ]);
})->with([
    'unauthorized' => [401, AluntaApiException::UNAUTHORIZED, 'Alunta afviste API-nøglen.'],
    'payment required' => [402, AluntaApiException::PAYMENT_REQUIRED, 'Alunta-kontoen er låst, indtil den er betalt hos Alunta.'],
    'forbidden' => [403, AluntaApiException::FORBIDDEN, 'Alunta tillader ikke handlingen for denne konto.'],
    'not found' => [404, AluntaApiException::NOT_FOUND, 'Alunta kender ikke det efterspurgte.'],
    'conflict' => [409, AluntaApiException::CONFLICT, 'Alunta afviste en gentaget forespørgsel med andet indhold.'],
    'validation' => [422, AluntaApiException::VALIDATION, 'Alunta afviste ændringen.'],
    'redirect' => [302, AluntaApiException::HTTP_ERROR, 'Alunta kunne ikke behandle forespørgslen (HTTP 302).'],
    'server error' => [500, AluntaApiException::HTTP_ERROR, 'Alunta kunne ikke behandle forespørgslen (HTTP 500).'],
]);

it('logs a null request id when the response has none', function () {
    $harness = sdkHarness([sdkJson([], 404)]);

    sdkCaught(fn () => $harness->client->getSubscription(SDK_SUBSCRIPTION_UUID));

    expect($harness->logger->records[0]['context']['request_id'])->toBeNull();
});

it('limits the provider message to 300 characters', function () {
    $harness = sdkHarness([sdkJson(['message' => str_repeat('æ', 299).'øå tail'], 422)]);

    $exception = sdkCaught(fn () => $harness->client->resume(SDK_SUBSCRIPTION_UUID));

    expect($exception->providerMessage)->toBe(str_repeat('æ', 299).'ø...');
});

it('keeps no provider message that is not a string', function () {
    $harness = sdkHarness([sdkJson(['message' => ['nested']], 422)]);

    expect(sdkCaught(fn () => $harness->client->resume(SDK_SUBSCRIPTION_UUID))->providerMessage)->toBeNull();
});

it('turns a transport failure into a connection error without the previous exception or the token', function () {
    $failure = new class('cURL error 7 with Bearer '.SDK_TOKEN) extends RuntimeException implements NetworkExceptionInterface
    {
        public function getRequest(): RequestInterface
        {
            return (new HttpFactory)->createRequest('GET', SDK_API_URL);
        }
    };
    $harness = sdkHarness([$failure]);

    $exception = sdkCaught(fn () => $harness->client->getSubscription(SDK_SUBSCRIPTION_UUID));

    expect($exception->errorCode)->toBe(AluntaApiException::CONNECTION)
        ->and($exception->status)->toBeNull()
        ->and($exception->getMessage())->toBe('Forbindelsen til Alunta mislykkedes, før Alunta svarede.')
        ->and($exception->getPrevious())->toBeNull()
        ->and($harness->http->sent)->toHaveCount(1)
        ->and($harness->logger->records)->toBe([[
            'level' => 'warning',
            'message' => 'Alunta API request failed without a response.',
            'context' => ['operation' => 'get_subscription', 'exception' => $failure::class],
        ]]);
});

it('logs the class of the failure a transport exception wraps', function () {
    $call = new class implements PreparedCall
    {
        public function send(ApiRequest $request): ResponseInterface
        {
            throw new TransportException($request, 'Illuminate\Http\Client\ConnectionException');
        }
    };
    $logger = new SdkMemoryLogger;
    $client = new AluntaClient(new SdkFakeTransport($call), new SdkQueuedCredentials([new Credentials(SDK_API_URL, SDK_TOKEN)]), new SdkFakeSleeper, new RequestPacer, $logger);

    sdkCaught(fn () => $client->me());

    expect($logger->records[0]['context'])->toBe(['operation' => 'me', 'exception' => 'Illuminate\Http\Client\ConnectionException']);
});

it('builds a transport exception that names no cause and keeps the structured request', function () {
    $request = new ApiRequest('POST', '/me', json: ['a' => 1], headers: ['Idempotency-Key' => 'key']);
    $exception = new TransportException($request, 'Some\Failure');

    expect($exception->request)->toBe($request)
        ->and($exception->causeClass)->toBe('Some\Failure')
        ->and($exception->getMessage())->toBe('The HTTP request failed without a response.')
        ->and($exception->getPrevious())->toBeNull();
});

it('trims the bearer token header like Laravel withToken', function () {
    $harness = sdkHarness([sdkJson(['team_uuid' => SDK_SUBSCRIPTION_UUID, 'team_name' => 'Acme'])], [new Credentials(SDK_API_URL, SDK_TOKEN."\n")]);

    $harness->client->me();

    expect($harness->http->sent[0]->getHeaderLine('Authorization'))->toBe('Bearer '.SDK_TOKEN);
});

it('turns a request that cannot be built into a connection error that logs only the failure class', function () {
    $harness = sdkHarness(credentials: [new Credentials(SDK_API_URL, "abc\r\nInjected: ".SDK_TOKEN)]);

    $exception = sdkCaught(fn () => $harness->client->me());

    expect($exception->errorCode)->toBe(AluntaApiException::CONNECTION)
        ->and($exception->getMessage())->toBe('Forbindelsen til Alunta mislykkedes, før Alunta svarede.')
        ->and($exception->getPrevious())->toBeNull()
        ->and($harness->http->sent)->toBe([])
        ->and($harness->logger->records)->toBe([[
            'level' => 'warning',
            'message' => 'Alunta API request failed without a response.',
            'context' => ['operation' => 'me', 'exception' => InvalidArgumentException::class],
        ]]);
});

it('sends nothing when no credentials are configured', function () {
    $harness = sdkHarness(credentials: [null]);

    $exception = sdkCaught(fn () => $harness->client->getSubscription(SDK_SUBSCRIPTION_UUID));

    expect($exception->errorCode)->toBe(AluntaApiException::NOT_CONFIGURED)
        ->and($exception->getMessage())->toBe('Betaling via Alunta er ikke sat op.')
        ->and($harness->http->sent)->toBe([]);
});

it('reads the credentials again for every call', function () {
    $harness = sdkHarness(
        [sdkJson(['data' => sdkSubscription()])],
        [new Credentials(SDK_API_URL, 'first-token'), new Credentials('https://alunta.example.org/api/v1', 'second-token')],
    );

    $harness->client->getSubscription(SDK_SUBSCRIPTION_UUID);
    $harness->client->getSubscription(SDK_SUBSCRIPTION_UUID);

    expect($harness->http->sent[0]->getHeaderLine('Authorization'))->toBe('Bearer first-token')
        ->and((string) $harness->http->sent[1]->getUri())->toBe('https://alunta.example.org/api/v1/subscriptions/'.SDK_SUBSCRIPTION_UUID)
        ->and($harness->http->sent[1]->getHeaderLine('Authorization'))->toBe('Bearer second-token');
});

it('refuses an identifier that is not a uuid before reading credentials or sending', function () {
    $harness = sdkHarness();

    $exception = sdkCaught(fn () => $harness->client->getSubscription('../me'));

    expect($exception->errorCode)->toBe(AluntaApiException::INVALID_ARGUMENT)
        ->and($exception->getMessage())->toBe('Et Alunta-id var ugyldigt.')
        ->and($harness->credentials->reads)->toBe(0)
        ->and($harness->http->sent)->toBe([]);
});

it('accepts a checkout url only on the origin of the api', function (?string $checkoutUrl, bool $accepted) {
    $harness = sdkHarness([sdkJson(['data' => ['id' => SDK_SESSION_UUID, 'checkout_url' => $checkoutUrl]], 201)]);
    $create = fn () => $harness->client->createSubscriptionCheckout(SDK_PLAN_UUID, 'shop-1', 'https://a.test/b', 'https://a.test/s', [], 'key');

    if ($accepted) {
        expect($create()['checkout_url'])->toBe($checkoutUrl);
    } else {
        expect(sdkCaught($create)->errorCode)->toBe(AluntaApiException::UNEXPECTED_RESPONSE)
            ->and($harness->logger->records[0]['message'])->toBe('Alunta API returned an unexpected response.');
    }
})->with([
    'same origin' => ['https://app.alunta.com/checkout/x', true],
    'no url' => [null, true],
    'another host' => ['https://alunta.example.net/checkout/x', false],
    'another scheme' => ['http://app.alunta.com/checkout/x', false],
    'another port' => ['https://app.alunta.com:8443/checkout/x', false],
]);

it('accepts a portal link over https on any host, or on the origin of the api', function (string $apiUrl, string $portalUrl, bool $accepted) {
    $harness = sdkHarness([sdkJson(['data' => ['url' => $portalUrl, 'expires_at' => null]])], [new Credentials($apiUrl, SDK_TOKEN)]);
    $mint = fn () => $harness->client->portalLink(SDK_CUSTOMER_UUID);

    if ($accepted) {
        expect($mint())->toBe(['url' => $portalUrl, 'expires_at' => null]);
    } else {
        expect(sdkCaught($mint)->errorCode)->toBe(AluntaApiException::UNEXPECTED_RESPONSE);
    }
})->with([
    'https elsewhere' => [SDK_API_URL, 'https://portal.example.com/verify/1', true],
    'http on the api origin' => ['http://127.0.0.1:4010/api/v1', 'http://127.0.0.1:4010/portal/verify/1', true],
    'http elsewhere' => [SDK_API_URL, 'http://portal.example.com/verify/1', false],
    'http on another port' => ['http://127.0.0.1:4010/api/v1', 'http://127.0.0.1:4011/portal/verify/1', false],
    'not a url' => [SDK_API_URL, 'https://', false],
]);

it('refuses a response without the documented shape', function (Closure $call, mixed $body) {
    $harness = sdkHarness([sdkJson($body)]);

    expect(sdkCaught(fn () => $call($harness->client))->errorCode)->toBe(AluntaApiException::UNEXPECTED_RESPONSE);
})->with([
    'subscription with another uuid' => [fn (AluntaClient $client) => $client->getSubscription(SDK_SUBSCRIPTION_UUID), ['data' => sdkSubscription(['uuid' => '00000000-0000-4000-8000-000000000000'])]],
    'list that is an object' => [fn (AluntaClient $client) => $client->listSubscriptionTransactions(SDK_SUBSCRIPTION_UUID), ['data' => ['uuid' => SDK_SUBSCRIPTION_UUID]]],
    'list item without a uuid' => [fn (AluntaClient $client) => $client->listInvoicePayments(SDK_SUBSCRIPTION_UUID), ['data' => [['amount' => 1]]]],
    'checkout session with a text can_restart' => [fn (AluntaClient $client) => $client->getCheckoutSession(SDK_SESSION_UUID), ['data' => ['id' => SDK_SESSION_UUID, 'status' => 'expired', 'can_restart' => 'yes']]],
    'cancel preview without notice_applied' => [fn (AluntaClient $client) => $client->cancelPreview(SDK_SUBSCRIPTION_UUID), ['data' => ['end_date' => '2026-04-30']]],
    'me without a team uuid' => [fn (AluntaClient $client) => $client->me(), ['team_name' => 'Acme']],
    'invoice with another uuid' => [fn (AluntaClient $client) => $client->getInvoice(SDK_SUBSCRIPTION_UUID), ['data' => ['uuid' => SDK_SESSION_UUID]]],
]);

it('refuses a response body that is not JSON', function () {
    $harness = sdkHarness([new Response(200, [], '<html>')]);

    expect(sdkCaught(fn () => $harness->client->me())->errorCode)->toBe(AluntaApiException::UNEXPECTED_RESPONSE);
});

it('drops the numeric keys of a returned object', function () {
    $harness = sdkHarness([new Response(200, [], '{"data":{"uuid":"'.SDK_SUBSCRIPTION_UUID.'","0":"x","status":"active"}}')]);

    expect($harness->client->getSubscription(SDK_SUBSCRIPTION_UUID))->toBe(['uuid' => SDK_SUBSCRIPTION_UUID, 'status' => 'active']);
});

it('normalizes the configured api url', function (mixed $configured, ?string $baseUrl) {
    expect(AluntaClient::baseUrlFrom($configured))->toBe($baseUrl);
})->with([
    'trailing slash' => ['https://app.alunta.com/api/v1/', 'https://app.alunta.com/api/v1'],
    'http' => ['http://127.0.0.1:4010/api/v1', 'http://127.0.0.1:4010/api/v1'],
    'another scheme' => ['ftp://app.alunta.com/api/v1', null],
    'not a url' => ['app.alunta.com', null],
    'not a string' => [123, null],
    'missing' => [null, null],
]);

it('clamps the configured request timeout to 1 through 20 seconds', function (mixed $configured, int $seconds) {
    expect(AluntaClient::timeoutSecondsFrom($configured))->toBe($seconds);
})->with([
    'in range' => [7, 7],
    'above the maximum' => [99, 20],
    'below the minimum' => [0, 1],
    'not an integer' => ['30', 15],
    'missing' => [null, 15],
]);

it('bounds one call at 95 seconds', function () {
    expect(AluntaClient::CONNECT_TIMEOUT_SECONDS)->toBe(5)
        ->and(AluntaClient::MAX_TIMEOUT_SECONDS)->toBe(20)
        ->and(AluntaClient::WORST_CASE_REQUEST_SECONDS)->toBe(95);
});

it('lets an exception that is not a PSR-18 client exception escape the send unchanged, without a log', function () {
    $failure = new LogicException('set up failed');
    $harness = sdkHarness([$failure]);

    expect(fn () => $harness->client->me())->toThrow($failure);

    expect($harness->http->sent)->toHaveCount(1)
        ->and($harness->logger->records)->toBe([]);
});

it('logs a body that cannot be encoded as a JSON exception, before anything is sent', function () {
    $harness = sdkHarness();

    $exception = sdkCaught(fn () => $harness->client->createSubscriptionCheckout(SDK_PLAN_UUID, 'org-1', 'https://a.example', 'https://b.example', ['name' => "bad \xB1\x31"], 'key'));

    expect($exception->errorCode)->toBe(AluntaApiException::CONNECTION)
        ->and($exception->getPrevious())->toBeNull()
        ->and($harness->http->sent)->toBe([])
        ->and($harness->logger->records)->toBe([[
            'level' => 'warning',
            'message' => 'Alunta API request failed without a response.',
            'context' => ['operation' => 'create_checkout', 'exception' => JsonException::class],
        ]]);
});

/**
 * A transport that hands out the given prepared call, or one through the fake PSR-18 client, and
 * keeps the credentials of every call.
 */
final class SdkFakeTransport implements Transport
{
    /** @var list<Credentials> */
    public array $prepared = [];

    public function __construct(private readonly PreparedCall|ClientInterface $call, private readonly ?Throwable $failure = null) {}

    public function prepareCall(Credentials $credentials): PreparedCall
    {
        $this->prepared[] = $credentials;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->call instanceof PreparedCall
            ? $this->call
            : (new Psr18Transport($this->call, new HttpFactory, new HttpFactory))->prepareCall($credentials);
    }
}

it('prepares the call once per call and sends every attempt of the call through it', function () {
    $http = new SdkFakeHttpClient([
        sdkJson(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '2']),
        sdkJson(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '3']),
        sdkJson(['team_uuid' => SDK_SUBSCRIPTION_UUID, 'team_name' => 'Acme ApS']),
    ]);
    $transport = new SdkFakeTransport($http);
    $sleeper = new SdkFakeSleeper;
    $credentials = new Credentials(SDK_API_URL, SDK_TOKEN);
    $client = new AluntaClient($transport, new SdkQueuedCredentials([$credentials]), $sleeper);

    expect($client->me()['team_name'])->toBe('Acme ApS')
        ->and($transport->prepared)->toBe([$credentials])
        ->and($http->sent)->toHaveCount(3)
        ->and($sleeper->waits)->toBe([['s', 2], ['s', 3]]);

    $client->me();

    expect($transport->prepared)->toBe([$credentials, $credentials]);
});

it('lets an exception from preparing the call escape unchanged, without a log, even a transport exception', function () {
    $failure = new TransportException(new ApiRequest('GET', '/me'), 'SetupFailed');
    $http = new SdkFakeHttpClient([]);
    $logger = new SdkMemoryLogger;
    $client = new AluntaClient(new SdkFakeTransport($http, $failure), new SdkQueuedCredentials([new Credentials(SDK_API_URL, SDK_TOKEN)]), new SdkFakeSleeper, new RequestPacer, $logger);

    expect(fn () => $client->me())->toThrow($failure)
        ->and($http->sent)->toBe([])
        ->and($logger->records)->toBe([]);
});

it('checks the identifier and the credentials before it prepares the call', function () {
    $transport = new SdkFakeTransport(new SdkFakeHttpClient([]), new LogicException('set up'));
    $client = new AluntaClient($transport, new SdkQueuedCredentials([null]), new SdkFakeSleeper);

    expect(sdkCaught(fn () => $client->getSubscription('not-a-uuid'))->errorCode)->toBe(AluntaApiException::INVALID_ARGUMENT)
        ->and(sdkCaught(fn () => $client->me())->errorCode)->toBe(AluntaApiException::NOT_CONFIGURED)
        ->and($transport->prepared)->toBe([]);
});

it('logs a JSON exception thrown by a JsonSerializable value while the body is encoded as a JSON exception', function () {
    $harness = sdkHarness();
    $metadata = ['a' => new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            throw new JsonException('thrown by jsonSerialize');
        }
    }];

    $exception = sdkCaught(fn () => $harness->client->createSubscriptionCheckout(SDK_PLAN_UUID, 'org-1', 'https://a.example', 'https://b.example', $metadata, 'key'));

    expect($exception->errorCode)->toBe(AluntaApiException::CONNECTION)
        ->and($exception->getPrevious())->toBeNull()
        ->and($harness->http->sent)->toBe([])
        ->and($harness->logger->records)->toBe([[
            'level' => 'warning',
            'message' => 'Alunta API request failed without a response.',
            'context' => ['operation' => 'create_checkout', 'exception' => JsonException::class],
        ]]);
});

/**
 * The call Psr18Transport prepares over the fake PSR-18 client.
 */
function sdkPsr18Call(SdkFakeHttpClient $http, string $apiUrl = SDK_API_URL, string $token = SDK_TOKEN): PreparedCall
{
    $factory = new HttpFactory;

    return (new Psr18Transport($http, $factory, $factory))->prepareCall(new Credentials($apiUrl, $token));
}

it('encodes the structured request into the exact URI, query, headers and body bytes', function () {
    $http = new SdkFakeHttpClient([new Response(201)]);

    $response = sdkPsr18Call($http, SDK_API_URL.'/', SDK_TOKEN." \n")->send(new ApiRequest(
        'POST',
        '/checkout-sessions',
        ['external_customer_id' => 'org æ+&=/', 'per_page' => 100, 'flag' => false],
        ['name' => 'Æble "ApS"', 'empty' => new stdClass, 'url' => 'https://a.example/b'],
        ['Idempotency-Key' => 'attempt-key'],
    ));

    $request = $http->sent[0];
    expect($response->getStatusCode())->toBe(201)
        ->and($http->sent)->toHaveCount(1)
        ->and($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe(SDK_API_URL.'/checkout-sessions?external_customer_id=org%20%C3%A6%2B%26%3D%2F&per_page=100&flag=0')
        ->and($request->getHeaders())->toBe([
            'Host' => ['app.alunta.com'],
            'Authorization' => ['Bearer '.SDK_TOKEN],
            'Accept' => ['application/json'],
            'Idempotency-Key' => ['attempt-key'],
            'Content-Type' => ['application/json'],
        ])
        ->and((string) $request->getBody())->toBe('{"name":"\\u00c6ble \"ApS\"","empty":{},"url":"https:\/\/a.example\/b"}');
});

it('sends a request without a body with neither a body nor a content type', function () {
    $http = new SdkFakeHttpClient([new Response(200)]);

    sdkPsr18Call($http)->send(new ApiRequest('DELETE', '/subscriptions/x'));

    expect((string) $http->sent[0]->getUri())->toBe(SDK_API_URL.'/subscriptions/x')
        ->and((string) $http->sent[0]->getBody())->toBe('')
        ->and($http->sent[0]->getHeaders())->toBe([
            'Host' => ['app.alunta.com'],
            'Authorization' => ['Bearer '.SDK_TOKEN],
            'Accept' => ['application/json'],
        ]);
});

it('returns a redirect as it came and sends the request once, leaving redirects to the PSR-18 client', function () {
    $http = new SdkFakeHttpClient([new Response(302, ['Location' => 'https://evil.example.com/collect'])]);

    $response = sdkPsr18Call($http)->send(new ApiRequest('GET', '/me'));

    expect($response->getStatusCode())->toBe(302)
        ->and($http->sent)->toHaveCount(1);
});

it('turns a failure to build or send the request into a transport exception that keeps only the failure class', function (Closure $prepare, ApiRequest $request, string $causeClass, int $sent) {
    $failure = new class('cURL error 7 with Bearer '.SDK_TOKEN) extends RuntimeException implements NetworkExceptionInterface
    {
        public function getRequest(): RequestInterface
        {
            return (new HttpFactory)->createRequest('GET', SDK_API_URL);
        }
    };
    $http = new SdkFakeHttpClient([$failure]);
    $call = $prepare($http);
    $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

    try {
        $call->send($request);
        $caught = null;
    } catch (TransportException $exception) {
        $caught = $exception;
    } finally {
        ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
    }

    $arguments = array_merge(...array_map(fn (array $frame): array => $frame['args'] ?? [], $caught?->getTrace() ?? []));

    expect($caught)->not->toBeNull()
        ->and($caught->causeClass)->toBe($causeClass === 'network' ? $failure::class : $causeClass)
        ->and($caught->request)->toBe($request)
        ->and($caught->getPrevious())->toBeNull()
        ->and($caught->getMessage())->toBe('The HTTP request failed without a response.')
        ->and($arguments)->not->toBeEmpty()
        ->and(array_filter($arguments, fn (mixed $argument): bool => $argument instanceof RequestInterface || $argument instanceof Credentials || (is_string($argument) && str_contains($argument, SDK_TOKEN))))->toBe([])
        ->and($caught->getTraceAsString())->not->toContain(SDK_TOKEN)
        ->and($http->sent)->toHaveCount($sent);
})->with([
    'network failure' => [fn (SdkFakeHttpClient $http) => sdkPsr18Call($http), new ApiRequest('POST', '/me', json: ['a' => 1]), 'network', 1],
    'token with CR LF' => [fn (SdkFakeHttpClient $http) => sdkPsr18Call($http, token: "abc\r\nInjected: ".SDK_TOKEN), new ApiRequest('GET', '/me'), InvalidArgumentException::class, 0],
    'body that cannot be encoded' => [fn (SdkFakeHttpClient $http) => sdkPsr18Call($http), new ApiRequest('POST', '/me', json: ['name' => "bad \xB1\x31"]), JsonException::class, 0],
]);

it('lets an exception from the PSR-18 client that is not a client exception escape unchanged', function () {
    $failure = new LogicException('not a client exception');

    expect(fn () => sdkPsr18Call(new SdkFakeHttpClient([$failure]))->send(new ApiRequest('GET', '/me')))->toThrow($failure);
});

it('filters the subscription transactions by invoicing and reads a given page, after the unchanged filters', function () {
    $harness = sdkHarness([sdkJson(['data' => []])]);

    $harness->client->listSubscriptionTransactions(SDK_SUBSCRIPTION_UUID, invoiced: true, page: 2);
    $harness->client->listSubscriptionTransactions(SDK_SUBSCRIPTION_UUID, invoiced: false);
    $harness->client->listSubscriptionTransactions(SDK_SUBSCRIPTION_UUID, page: 3);
    $harness->client->listSubscriptionTransactions(SDK_SUBSCRIPTION_UUID);

    $base = SDK_API_URL.'/subscription-transactions?membership_uuid='.SDK_SUBSCRIPTION_UUID.'&per_page=100';

    expect(array_map(fn (RequestInterface $request): string => (string) $request->getUri(), $harness->http->sent))->toBe([
        $base.'&invoiced=1&page=2',
        $base.'&invoiced=0',
        $base.'&page=3',
        $base,
    ])->and(AluntaClient::LIST_PAGE_SIZE)->toBe(100);
});

it('refuses a transactions page below 1 before it sends anything', function (int $page) {
    $harness = sdkHarness();

    expect(sdkCaught(fn () => $harness->client->listSubscriptionTransactions(SDK_SUBSCRIPTION_UUID, page: $page))->errorCode)->toBe(AluntaApiException::INVALID_ARGUMENT)
        ->and($harness->http->sent)->toBe([]);
})->with([0, -1]);

it('reads the nullable invoice pay URL without logging it', function (?string $url) {
    $harness = sdkHarness([sdkJson(['data' => ['uuid' => SDK_SESSION_UUID, 'pay_url' => $url]])]);

    expect($harness->client->getInvoice(SDK_SESSION_UUID)['pay_url'])->toBe($url)
        ->and($harness->logger->records)->toBe([]);
})->with([null, 'https://app.alunta.com/pay-invoice/private-link']);

it('discards an unsafe pay URL without failing the invoice read or logging the URL', function (mixed $url) {
    $harness = sdkHarness([sdkJson(['data' => ['uuid' => SDK_SESSION_UUID, 'pay_url' => $url]])]);

    expect($harness->client->getInvoice(SDK_SESSION_UUID))->toBe(['uuid' => SDK_SESSION_UUID, 'pay_url' => null]);
    expect(json_encode($harness->logger->records))->not->toContain('private-link');
})->with([
    'other origin' => ['https://other.example/pay/private-link'],
    'insecure' => ['http://app.alunta.com/pay/private-link'],
    'relative' => ['/pay/private-link'],
    'script' => ['javascript:private-link'],
    'user info' => ['https://private-link@app.alunta.com/pay'],
    'different port' => ['https://app.alunta.com:8443/pay/private-link'],
    'not a string' => [42],
]);

it('accepts an invoice pay link on the configured http emulator origin', function () {
    $url = 'http://127.0.0.1:43210/pay/private-link';
    $harness = sdkHarness([sdkJson(['data' => ['uuid' => SDK_SESSION_UUID, 'pay_url' => $url]])], [new Credentials('http://127.0.0.1:43210/api/v1', SDK_TOKEN)]);

    expect($harness->client->getInvoice(SDK_SESSION_UUID)['pay_url'])->toBe($url)
        ->and($harness->logger->records)->toBe([]);
});

it('keeps an invoice pay URL in a rejected response out of exceptions and logs', function () {
    $url = 'https://app.alunta.com/pay-invoice/private-link';
    $harness = sdkHarness([sdkJson(['message' => $url, 'pay_url' => $url], 422)]);

    $error = sdkCaught(fn () => $harness->client->getInvoice(SDK_SESSION_UUID));

    expect($error->providerMessage)->toBeNull()
        ->and(json_encode($harness->logger->records))->not->toContain($url);
});
