<?php

namespace KasperStuck\Alunta\Sdk;

/**
 * The envelope of a verified Alunta webhook delivery: a JSON object with an event name of 1 to 64
 * bytes, the team_uuid and test_mode it was sent for, and the event's data. Parse it only after
 * WebhookSignature verified the body.
 */
final readonly class WebhookEnvelope
{
    private const int MAX_EVENT_BYTES = 64;

    /**
     * @param  array<string, mixed>  $payload  the decoded body
     */
    private function __construct(
        public string $body,
        public array $payload,
        public string $event,
    ) {}

    /**
     * The envelope of the raw body, or null when the body is not a webhook payload: not a JSON
     * object, or without an event name of 1 to 64 bytes.
     */
    public static function parse(string $body): ?self
    {
        $payload = json_decode($body, true);
        $event = is_array($payload) ? ($payload['event'] ?? null) : null;

        if (! is_array($payload) || array_is_list($payload) || ! is_string($event) || $event === '' || strlen($event) > self::MAX_EVENT_BYTES) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        return new self($body, $payload, $event);
    }

    /**
     * The team_uuid the delivery was sent for, as sent, or null when it is not a string.
     */
    public function teamUuid(): ?string
    {
        $teamUuid = $this->payload['team_uuid'] ?? null;

        return is_string($teamUuid) ? $teamUuid : null;
    }

    /**
     * Whether the delivery was sent for the team, in any letter case, and exactly the test mode.
     */
    public function belongsTo(string $teamUuid, bool $testMode): bool
    {
        $deliveryTeamUuid = $this->teamUuid();

        return $deliveryTeamUuid !== null && strcasecmp($deliveryTeamUuid, $teamUuid) === 0
            && ($this->payload['test_mode'] ?? null) === $testMode;
    }

    /**
     * Whether the delivery is a synthetic test: the connection ping (event "test") or an example
     * marked is_test.
     */
    public function isTest(): bool
    {
        return $this->event === 'test' || ($this->payload['is_test'] ?? null) === true;
    }

    /**
     * The event's data, or an empty array when the delivery has none.
     *
     * @return array<mixed>
     */
    public function data(): array
    {
        return is_array($this->payload['data'] ?? null) ? $this->payload['data'] : [];
    }

    /**
     * The UUID of the subscription the data names, in lower case, or null.
     */
    public function subscriptionUuid(): ?string
    {
        $data = $this->data();
        $subscriptionUuid = is_array($data['subscription'] ?? null) ? ($data['subscription']['uuid'] ?? null) : null;

        return is_string($subscriptionUuid) && Uuid::isValid($subscriptionUuid) ? strtolower($subscriptionUuid) : null;
    }

    /**
     * The key a repeated delivery of the same event shares, taken only from the signed body:
     * data.event_id where Alunta sends a UUID there (lower-cased), else the SHA-256 of the raw body.
     *
     * The X-Alunta-Webhook-Id delivery header is never part of the key. The Signature covers only
     * the body, so anyone holding one signed delivery could resend it under a new header and have
     * it stored and processed again. Alunta's spec: "Authenticate the raw request body with
     * Signature before trusting any delivery ID for deduplication."
     */
    public function dedupKey(): string
    {
        $eventId = $this->data()['event_id'] ?? null;

        if (is_string($eventId) && Uuid::isValid($eventId)) {
            return strtolower($eventId);
        }

        return hash('sha256', $this->body);
    }
}
