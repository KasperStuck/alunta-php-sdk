<?php

namespace KasperStuck\Alunta\Sdk;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Framework-free client for the Alunta public API (app.alunta.com/docs/v1/openapi.yaml). It reads
 * the credentials for every call, describes each request as an ApiRequest and sends it through the
 * Transport: Psr18Transport for any PSR-18 client, or a framework's own HTTP client. The transport
 * prepares each call once, outside the error handling, so an exception from that escapes
 * unchanged, without a log.
 *
 * The token goes only in the Authorization header, which the transport sets. The transport must
 * not follow redirects, so the token never reaches another host; a redirect is an unsuccessful
 * response. A TransportException becomes an AluntaApiException without a previous exception; any
 * other exception from the transport escapes unchanged. Only the operation, failure class, status,
 * error code and Alunta's request id are logged; never the token, a response body, a checkout URL
 * or a portal link. A 429 is retried at most twice after its Retry-After delay, capped at 10
 * seconds.
 *
 * The transport applies CONNECT_TIMEOUT_SECONDS and a request timeout of at most
 * MAX_TIMEOUT_SECONDS (timeoutSecondsFrom()); with Psr18Transport, configure the PSR-18 client
 * with them, as PSR-18 has no timeout option. Then one call takes at most
 * WORST_CASE_REQUEST_SECONDS, which callers that hold a lock size its lease from. A batch caller
 * can pace every request through the shared RequestPacer.
 */
final class AluntaClient
{
    public const int MAX_ATTEMPTS = 3;

    public const int MAX_RETRY_AFTER_SECONDS = 10;

    public const int CONNECT_TIMEOUT_SECONDS = 5;

    public const int MAX_TIMEOUT_SECONDS = 20;

    public const int DEFAULT_TIMEOUT_SECONDS = 15;

    /**
     * The longest one call can take: every attempt with its connect and request timeout, plus the
     * capped Retry-After waits between them (3 × (5 + 20) + 2 × 10 = 95 seconds).
     */
    public const int WORST_CASE_REQUEST_SECONDS = self::MAX_ATTEMPTS * (self::CONNECT_TIMEOUT_SECONDS + self::MAX_TIMEOUT_SECONDS)
        + (self::MAX_ATTEMPTS - 1) * self::MAX_RETRY_AFTER_SECONDS;

    /**
     * The rows one list request asks for (per_page), the most the API returns per page.
     */
    public const int LIST_PAGE_SIZE = 100;

    private const int PROVIDER_MESSAGE_LIMIT = 300;

    public function __construct(
        private readonly Transport $transport,
        private readonly CredentialsProvider $credentials,
        private readonly Sleeper $sleeper = new NativeSleeper,
        private readonly RequestPacer $pacer = new RequestPacer,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {}

    /**
     * Creates a subscription checkout session for the plan. Never sends quantity or
     * payment_providers: Alunta rejects both on subscription sessions of a fixed-price plan, and the
     * payment methods come from the plan. The same Idempotency-Key with the same body replays the
     * original session, whose checkout_url is null once it can no longer be paid.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{id: string, checkout_url: ?string, expires_at: ?string}
     *
     * @throws AluntaApiException
     */
    public function createSubscriptionCheckout(
        string $planUuid,
        string $externalCustomerId,
        string $backUrl,
        string $successUrl,
        array $metadata,
        string $idempotencyKey,
    ): array {
        self::assertUuid($planUuid);

        $response = $this->send(new ApiRequest('POST', '/checkout-sessions', json: [
            'type' => 'subscription',
            'plan_id' => $planUuid,
            'external_customer_id' => $externalCustomerId,
            'back_url' => $backUrl,
            'success_url' => $successUrl,
            'metadata' => $metadata,
        ], headers: ['Idempotency-Key' => $idempotencyKey]), 'create_checkout');

        $session = $response->json('data');
        $id = is_array($session) ? ($session['id'] ?? null) : null;
        $checkoutUrl = is_array($session) ? ($session['checkout_url'] ?? null) : null;

        if (! is_string($id) || ! Uuid::isValid($id) || ! ($checkoutUrl === null || (is_string($checkoutUrl) && self::hasApiOrigin($checkoutUrl, $response->baseUrl)))) {
            throw $this->unexpectedResponse('create_checkout', $response);
        }

        return [
            'id' => $id,
            'checkout_url' => $checkoutUrl,
            'expires_at' => is_string($session['expires_at'] ?? null) ? $session['expires_at'] : null,
        ];
    }

    /**
     * The recovery state of a checkout session. Alunta allows a restart only when can_restart is
     * true: the session expired and no provider operation is unresolved. Expiry alone is not
     * enough. The checkout URL is never returned.
     *
     * @return array{status: string, can_restart: bool, subscription_uuid: ?string}
     *
     * @throws AluntaApiException
     */
    public function getCheckoutSession(string $sessionUuid): array
    {
        self::assertUuid($sessionUuid);

        $response = $this->send(new ApiRequest('GET', "/checkout-sessions/{$sessionUuid}"), 'get_checkout_session');
        $session = self::jsonObject($response->json('data'));
        $status = $session['status'] ?? null;
        $canRestart = $session['can_restart'] ?? null;
        $subscriptionUuid = $session['subscription_uuid'] ?? null;

        if ($session === null || ($session['id'] ?? null) !== $sessionUuid || ! is_string($status) || ! is_bool($canRestart)
            || ! ($subscriptionUuid === null || Uuid::isValid($subscriptionUuid))) {
            throw $this->unexpectedResponse('get_checkout_session', $response);
        }

        /** @var ?string $subscriptionUuid */
        return ['status' => $status, 'can_restart' => $canRestart, 'subscription_uuid' => $subscriptionUuid];
    }

    /**
     * @return array<string, mixed> the Subscription object
     *
     * @throws AluntaApiException
     */
    public function getSubscription(string $subscriptionUuid): array
    {
        self::assertUuid($subscriptionUuid);

        return $this->subscriptionFrom($this->send(new ApiRequest('GET', "/subscriptions/{$subscriptionUuid}"), 'get_subscription'), $subscriptionUuid, 'get_subscription');
    }

    /**
     * The newest 100 subscriptions created with the external customer id.
     *
     * @return list<array<string, mixed>>
     *
     * @throws AluntaApiException
     */
    public function listSubscriptionsByExternalCustomer(string $externalCustomerId): array
    {
        return $this->listFrom($this->send(new ApiRequest('GET', '/subscriptions', [
            'external_customer_id' => $externalCustomerId,
            'per_page' => 100,
        ]), 'list_subscriptions'), 'list_subscriptions');
    }

    /**
     * The billing periods (transactions) of the subscription, newest period first, at most
     * LIST_PAGE_SIZE. A period is invoiced once `invoice` names its invoice; a deferred first
     * partial period carries `bill_from`, the day it is billed with the next period.
     *
     * $invoiced, when given, keeps only the invoiced (true) or the not invoiced (false) periods; it
     * is sent as 1 or 0, the boolean form the specification's own filter examples use. $page, when
     * given, reads that page (1 is the first). Without them the request is sent as before.
     *
     * @return list<array<string, mixed>>
     *
     * @throws AluntaApiException
     */
    public function listSubscriptionTransactions(string $subscriptionUuid, ?bool $invoiced = null, ?int $page = null): array
    {
        self::assertUuid($subscriptionUuid);

        if ($page !== null && $page < 1) {
            throw new AluntaApiException(AluntaApiException::INVALID_ARGUMENT, null, 'Et Alunta-sidetal var ugyldigt.');
        }

        $query = ['membership_uuid' => $subscriptionUuid, 'per_page' => self::LIST_PAGE_SIZE];

        if ($invoiced !== null) {
            $query['invoiced'] = $invoiced ? 1 : 0;
        }

        if ($page !== null) {
            $query['page'] = $page;
        }

        return $this->listFrom($this->send(new ApiRequest('GET', '/subscription-transactions', $query), 'list_subscription_transactions'), 'list_subscription_transactions');
    }

    /**
     * The invoice: its status, total with VAT, outstanding balance and nullable pay_url. Read
     * $invoice['pay_url'] ?? null for its hosted payment link. The link is sensitive: never log,
     * store or include it in an exception. Invalid links become null without affecting the invoice
     * read. Only absolute links on the API origin, without user information, are accepted.
     *
     * @return array<string, mixed>
     *
     * @throws AluntaApiException
     */
    public function getInvoice(string $invoiceUuid): array
    {
        self::assertUuid($invoiceUuid);

        $response = $this->send(new ApiRequest('GET', "/invoices/{$invoiceUuid}"), 'get_invoice');
        $invoice = self::jsonObject($response->json('data'));

        if ($invoice === null || ($invoice['uuid'] ?? null) !== $invoiceUuid) {
            throw $this->unexpectedResponse('get_invoice', $response);
        }

        $payUrl = $invoice['pay_url'] ?? null;

        if ($payUrl !== null && (! is_string($payUrl) || filter_var($payUrl, FILTER_VALIDATE_URL) === false
            || ! self::hasApiOrigin($payUrl, $response->baseUrl)
            || parse_url($payUrl, PHP_URL_USER) !== null || parse_url($payUrl, PHP_URL_PASS) !== null)) {
            $invoice['pay_url'] = null;
            $this->logger->warning('Alunta invoice payment link was invalid.', ['operation' => 'get_invoice']);
        }

        return $invoice;
    }

    /**
     * The payments recorded against the invoice, at most 100: each with its amount including VAT
     * and the provider that collected it.
     *
     * @return list<array<string, mixed>>
     *
     * @throws AluntaApiException
     */
    public function listInvoicePayments(string $invoiceUuid): array
    {
        self::assertUuid($invoiceUuid);

        return $this->listFrom($this->send(new ApiRequest('GET', '/payments', [
            'invoice_uuid' => $invoiceUuid,
            'per_page' => 100,
        ]), 'list_payments'), 'list_payments');
    }

    /**
     * The end date a cancellation at the end of the period would store, without cancelling.
     *
     * @return array{end_date: string, notice_applied: bool}
     *
     * @throws AluntaApiException
     */
    public function cancelPreview(string $subscriptionUuid): array
    {
        self::assertUuid($subscriptionUuid);

        $response = $this->send(new ApiRequest('GET', "/subscriptions/{$subscriptionUuid}/cancel-preview", ['immediate' => 0]), 'cancel_preview');

        $endDate = $response->json('data.end_date');
        $noticeApplied = $response->json('data.notice_applied');

        if (! is_string($endDate) || ! is_bool($noticeApplied)) {
            throw $this->unexpectedResponse('cancel_preview', $response);
        }

        return ['end_date' => $endDate, 'notice_applied' => $noticeApplied];
    }

    /**
     * Cancels at the end of the current billing period.
     *
     * @return array<string, mixed> the updated Subscription object
     *
     * @throws AluntaApiException
     */
    public function cancel(string $subscriptionUuid): array
    {
        self::assertUuid($subscriptionUuid);

        return $this->subscriptionFrom($this->send(new ApiRequest('POST', "/subscriptions/{$subscriptionUuid}/cancel"), 'cancel'), $subscriptionUuid, 'cancel');
    }

    /**
     * @return array<string, mixed> the updated Subscription object
     *
     * @throws AluntaApiException
     */
    public function resume(string $subscriptionUuid): array
    {
        self::assertUuid($subscriptionUuid);

        return $this->subscriptionFrom($this->send(new ApiRequest('POST', "/subscriptions/{$subscriptionUuid}/resume"), 'resume'), $subscriptionUuid, 'resume');
    }

    /**
     * Schedules a move to another plan at a renewal: the next unlocked one, or $effectiveDate
     * (Y-m-d) from the subscription's available_plan_change_dates. The change is scheduled, never
     * immediate.
     *
     * @return array{subscription: array<string, mixed>, scheduled_change_uuid: ?string, effective_date: ?string}
     *
     * @throws AluntaApiException
     */
    public function changePlan(string $subscriptionUuid, string $planUuid, int $interval, ?string $effectiveDate = null): array
    {
        self::assertUuid($subscriptionUuid);
        self::assertUuid($planUuid);

        $body = ['plan_uuid' => $planUuid, 'interval' => $interval];

        if ($effectiveDate !== null) {
            $body['effective_date'] = $effectiveDate;
        }

        $response = $this->send(new ApiRequest('PUT', "/subscriptions/{$subscriptionUuid}/plan", json: $body), 'change_plan');
        $scheduledChangeUuid = $response->json('meta.scheduled_change_uuid');
        $scheduledOn = $response->json('meta.effective_date');

        return [
            'subscription' => $this->subscriptionFrom($response, $subscriptionUuid, 'change_plan'),
            'scheduled_change_uuid' => is_string($scheduledChangeUuid) && Uuid::isValid($scheduledChangeUuid) ? $scheduledChangeUuid : null,
            'effective_date' => is_string($scheduledOn) ? $scheduledOn : null,
        ];
    }

    /**
     * Deletes a pending scheduled change, such as a plan change.
     *
     * @return array<string, mixed> the updated Subscription object
     *
     * @throws AluntaApiException
     */
    public function deleteScheduledChange(string $subscriptionUuid, string $scheduledChangeUuid): array
    {
        self::assertUuid($subscriptionUuid);
        self::assertUuid($scheduledChangeUuid);

        $response = $this->send(new ApiRequest('DELETE', "/subscriptions/{$subscriptionUuid}/scheduled-changes/{$scheduledChangeUuid}"), 'delete_scheduled_change');

        return $this->subscriptionFrom($response, $subscriptionUuid, 'delete_scheduled_change');
    }

    /**
     * A signed auto-login link to the customer portal, valid for 15 minutes. The link is a
     * credential: mint it when the customer clicks, and never log or store it.
     *
     * @return array{url: string, expires_at: ?string}
     *
     * @throws AluntaApiException
     */
    public function portalLink(string $customerUuid): array
    {
        self::assertUuid($customerUuid);

        $response = $this->send(new ApiRequest('POST', "/portal-link/{$customerUuid}", json: ['expires_in_minutes' => 15]), 'portal_link');

        $url = $response->json('data.url');
        $expiresAt = $response->json('data.expires_at');

        if (! is_string($url) || ! (str_starts_with($url, 'https://') || self::hasApiOrigin($url, $response->baseUrl)) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw $this->unexpectedResponse('portal_link', $response);
        }

        return ['url' => $url, 'expires_at' => is_string($expiresAt) ? $expiresAt : null];
    }

    /**
     * The team the token belongs to.
     *
     * @return array{team_uuid: string, team_name: string}
     *
     * @throws AluntaApiException
     */
    public function me(): array
    {
        $response = $this->send(new ApiRequest('GET', '/me'), 'me');
        $teamUuid = $response->json('team_uuid');
        $teamName = $response->json('team_name');

        if (! is_string($teamUuid) || ! Uuid::isValid($teamUuid) || ! is_string($teamName)) {
            throw $this->unexpectedResponse('me', $response);
        }

        return ['team_uuid' => $teamUuid, 'team_name' => $teamName];
    }

    /**
     * The configured request timeout in seconds (default 15), clamped to 1 through
     * MAX_TIMEOUT_SECONDS.
     */
    public static function timeoutSecondsFrom(mixed $configured): int
    {
        return max(1, min(self::MAX_TIMEOUT_SECONDS, is_int($configured) ? $configured : self::DEFAULT_TIMEOUT_SECONDS));
    }

    /**
     * The configured API URL without a trailing slash, or null when it is not an http(s) URL.
     */
    public static function baseUrlFrom(mixed $configured): ?string
    {
        if (! is_string($configured) || filter_var($configured, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($configured, PHP_URL_SCHEME), ['https', 'http'], true)) {
            return null;
        }

        return rtrim($configured, '/');
    }

    /**
     * Sends the request, retrying a 429, and returns its successful response.
     *
     * @throws AluntaApiException
     */
    private function send(ApiRequest $request, string $operation): ApiResponse
    {
        $credentials = $this->credentials->credentials()
            ?? throw new AluntaApiException(AluntaApiException::NOT_CONFIGURED, null, 'Betaling via Alunta er ikke sat op.');

        // The transport prepares the call once, outside the error handling below: an exception
        // from that escapes unchanged, and every attempt goes through the same prepared call.
        $call = $this->transport->prepareCall($credentials);

        for ($attempt = 1; ; $attempt++) {
            $this->waitForPacing();

            try {
                $response = $call->send($request);
            } catch (TransportException $exception) {
                throw $this->connectionFailed($operation, $exception->causeClass);
            }

            if ($response->getStatusCode() !== 429 || $attempt >= self::MAX_ATTEMPTS) {
                break;
            }

            $this->sleeper->sleepSeconds(self::retryAfterSeconds($response));
        }

        $apiResponse = new ApiResponse($response, $credentials->baseUrl);

        if ($apiResponse->successful()) {
            return $apiResponse;
        }

        throw $this->rejected($operation, $apiResponse);
    }

    /**
     * Logs a request that got no response and returns the connection error for it.
     *
     * @param  string  $failureClass  the class of the failure, the only detail logged
     */
    private function connectionFailed(string $operation, string $failureClass): AluntaApiException
    {
        $this->logger->warning('Alunta API request failed without a response.', [
            'operation' => $operation,
            'exception' => $failureClass,
        ]);

        return new AluntaApiException(AluntaApiException::CONNECTION, null, 'Forbindelsen til Alunta mislykkedes, før Alunta svarede.');
    }

    private function waitForPacing(): void
    {
        $delay = $this->pacer->delayBeforeRequest();

        if ($delay !== null) {
            $this->sleeper->sleepMilliseconds($delay);
        }
    }

    private function rejected(string $operation, ApiResponse $response): AluntaApiException
    {
        $status = $response->status();

        [$errorCode, $message] = match ($status) {
            401 => [AluntaApiException::UNAUTHORIZED, 'Alunta afviste API-nøglen.'],
            402 => [AluntaApiException::PAYMENT_REQUIRED, 'Alunta-kontoen er låst, indtil den er betalt hos Alunta.'],
            403 => [AluntaApiException::FORBIDDEN, 'Alunta tillader ikke handlingen for denne konto.'],
            404 => [AluntaApiException::NOT_FOUND, 'Alunta kender ikke det efterspurgte.'],
            409 => [AluntaApiException::CONFLICT, 'Alunta afviste en gentaget forespørgsel med andet indhold.'],
            422 => [AluntaApiException::VALIDATION, 'Alunta afviste ændringen.'],
            429 => [AluntaApiException::RATE_LIMITED, 'Alunta har for mange forespørgsler lige nu. Prøv igen om lidt.'],
            default => [AluntaApiException::HTTP_ERROR, "Alunta kunne ikke behandle forespørgslen (HTTP {$status})."],
        };

        $this->logger->warning('Alunta API request was not successful.', [
            'operation' => $operation,
            'status' => $status,
            'error_code' => $errorCode,
            'request_id' => $response->header('X-Request-Id') ?: null,
        ]);

        $providerMessage = $response->json('message');

        return new AluntaApiException(
            $errorCode,
            $status,
            $message,
            $operation !== 'get_invoice' && in_array($status, [409, 422], true) && is_string($providerMessage) ? self::limit($providerMessage) : null,
        );
    }

    private function unexpectedResponse(string $operation, ApiResponse $response): AluntaApiException
    {
        $this->logger->warning('Alunta API returned an unexpected response.', [
            'operation' => $operation,
            'status' => $response->status(),
            'error_code' => AluntaApiException::UNEXPECTED_RESPONSE,
            'request_id' => $response->header('X-Request-Id') ?: null,
        ]);

        return new AluntaApiException(AluntaApiException::UNEXPECTED_RESPONSE, $response->status(), 'Alunta svarede på en uventet måde.');
    }

    /**
     * The Subscription object in data, which must carry the requested uuid.
     *
     * @return array<string, mixed>
     *
     * @throws AluntaApiException
     */
    private function subscriptionFrom(ApiResponse $response, string $subscriptionUuid, string $operation): array
    {
        $subscription = self::jsonObject($response->json('data'));

        if ($subscription === null || ($subscription['uuid'] ?? null) !== $subscriptionUuid) {
            throw $this->unexpectedResponse($operation, $response);
        }

        return $subscription;
    }

    /**
     * The objects of a list response's data, each of which must carry a uuid.
     *
     * @return list<array<string, mixed>>
     *
     * @throws AluntaApiException
     */
    private function listFrom(ApiResponse $response, string $operation): array
    {
        $data = $response->json('data');

        if (! is_array($data) || ! array_is_list($data)) {
            throw $this->unexpectedResponse($operation, $response);
        }

        $items = [];

        foreach ($data as $item) {
            $object = self::jsonObject($item);

            if ($object === null || ! Uuid::isValid($object['uuid'] ?? null)) {
                throw $this->unexpectedResponse($operation, $response);
            }

            $items[] = $object;
        }

        return $items;
    }

    /**
     * The named fields of a decoded JSON object, or null when the value is not an object. A JSON
     * object's numeric keys decode to integers; Alunta's objects have none, so they are dropped.
     *
     * @return array<string, mixed>|null
     */
    private static function jsonObject(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $object = [];

        foreach ($value as $key => $field) {
            if (is_string($key)) {
                $object[$key] = $field;
            }
        }

        return $object;
    }

    /**
     * Whether the URL has the scheme, host and port of the API URL.
     */
    private static function hasApiOrigin(string $url, string $baseUrl): bool
    {
        $api = parse_url($baseUrl);
        $candidate = parse_url($url);

        return is_array($api) && is_array($candidate)
            && ($candidate['scheme'] ?? null) === ($api['scheme'] ?? null)
            && ($candidate['host'] ?? null) === ($api['host'] ?? null)
            && ($candidate['port'] ?? null) === ($api['port'] ?? null);
    }

    private static function retryAfterSeconds(ResponseInterface $response): int
    {
        $retryAfter = trim($response->getHeaderLine('Retry-After'));

        return ctype_digit($retryAfter) ? max(1, min(self::MAX_RETRY_AFTER_SECONDS, (int) $retryAfter)) : 1;
    }

    /**
     * The value cut to PROVIDER_MESSAGE_LIMIT display columns, with "..." when it was longer.
     */
    private static function limit(string $value): string
    {
        if (mb_strwidth($value, 'UTF-8') <= self::PROVIDER_MESSAGE_LIMIT) {
            return $value;
        }

        return rtrim(mb_strimwidth($value, 0, self::PROVIDER_MESSAGE_LIMIT, '', 'UTF-8')).'...';
    }

    /**
     * @throws AluntaApiException
     */
    private static function assertUuid(string $value): void
    {
        if (! Uuid::isValid($value)) {
            throw new AluntaApiException(AluntaApiException::INVALID_ARGUMENT, null, 'Et Alunta-id var ugyldigt.');
        }
    }
}
