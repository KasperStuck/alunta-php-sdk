<?php

use KasperStuck\Alunta\Sdk\WebhookEnvelope;

const ALUNTA_SDK_ENVELOPE_TEAM = '123e4567-e89b-12d3-a456-426614174010';

/**
 * A webhook body as Alunta sends it, with the overrides applied to the envelope.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $overrides
 */
function aluntaSdkWebhookBody(string $event = 'subscription.created', array $data = [], array $overrides = []): string
{
    return json_encode([
        'event' => $event,
        'team_uuid' => ALUNTA_SDK_ENVELOPE_TEAM,
        'test_mode' => false,
        'timestamp' => '2026-04-02T08:00:00Z',
        'data' => $data,
        ...$overrides,
    ], JSON_THROW_ON_ERROR);
}

it('parses a webhook payload with its event, data and subscription', function () {
    $body = aluntaSdkWebhookBody('subscription.payment_failed', ['subscription' => ['uuid' => '550E8400-E29B-41D4-A716-446655440000']]);

    $envelope = WebhookEnvelope::parse($body);

    expect($envelope)->not->toBeNull()
        ->and($envelope->event)->toBe('subscription.payment_failed')
        ->and($envelope->payload)->toBe(json_decode($body, true))
        ->and($envelope->data())->toBe(['subscription' => ['uuid' => '550E8400-E29B-41D4-A716-446655440000']])
        ->and($envelope->subscriptionUuid())->toBe('550e8400-e29b-41d4-a716-446655440000')
        ->and($envelope->teamUuid())->toBe(ALUNTA_SDK_ENVELOPE_TEAM);
});

it('refuses a body that is not a webhook payload', function (string $body) {
    expect(WebhookEnvelope::parse($body))->toBeNull();
})->with([
    'invalid json' => ['{"event":'],
    'a list' => ['[1,2]'],
    'a scalar' => ['"subscription.created"'],
    'an empty object' => ['{}'],
    'no event' => [json_encode(['team_uuid' => ALUNTA_SDK_ENVELOPE_TEAM, 'data' => []])],
    'an empty event' => [json_encode(['event' => ''])],
    'an event that is not a string' => [json_encode(['event' => 12])],
    'an event over 64 bytes' => [json_encode(['event' => str_repeat('a', 65)])],
]);

it('takes an event of up to 64 bytes', function () {
    expect(WebhookEnvelope::parse(json_encode(['event' => str_repeat('a', 64)]))?->event)->toBe(str_repeat('a', 64));
});

it('reads data and the subscription only where they have the documented shape', function (array $overrides, array $data, ?string $subscriptionUuid) {
    $envelope = WebhookEnvelope::parse(aluntaSdkWebhookBody(overrides: $overrides));

    expect($envelope?->data())->toBe($data)
        ->and($envelope?->subscriptionUuid())->toBe($subscriptionUuid);
})->with([
    'no data' => [['data' => null], [], null],
    'data that is a string' => [['data' => 'x'], [], null],
    'a subscription that is not an object' => [['data' => ['subscription' => 'x']], ['subscription' => 'x'], null],
    'a subscription uuid that is not a uuid' => [['data' => ['subscription' => ['uuid' => 'x']]], ['subscription' => ['uuid' => 'x']], null],
    'a subscription uuid that is not a string' => [['data' => ['subscription' => ['uuid' => 7]]], ['subscription' => ['uuid' => 7]], null],
]);

it('belongs to the team in any case and the exact test mode', function (array $overrides, bool $testMode, bool $belongs) {
    expect(WebhookEnvelope::parse(aluntaSdkWebhookBody(overrides: $overrides))?->belongsTo(ALUNTA_SDK_ENVELOPE_TEAM, $testMode))->toBe($belongs);
})->with([
    'the team' => [[], false, true],
    'the team in upper case' => [['team_uuid' => strtoupper(ALUNTA_SDK_ENVELOPE_TEAM)], false, true],
    'the team in test mode' => [['test_mode' => true], true, true],
    'another team' => [['team_uuid' => '99999999-e89b-12d3-a456-426614174010'], false, false],
    'no team' => [['team_uuid' => null], false, false],
    'a team that is not a string' => [['team_uuid' => 1], false, false],
    'another test mode' => [['test_mode' => true], false, false],
    'no test mode' => [['test_mode' => null], false, false],
    'a test mode that is not a boolean' => [['test_mode' => 0], false, false],
]);

it('knows a synthetic test delivery', function (string $event, array $overrides, bool $isTest) {
    expect(WebhookEnvelope::parse(aluntaSdkWebhookBody($event, overrides: $overrides))?->isTest())->toBe($isTest);
})->with([
    'the connection ping' => ['test', [], true],
    'a synthetic example' => ['subscription.created', ['is_test' => true], true],
    'a truthy is_test that is not true' => ['subscription.created', ['is_test' => 1], false],
    'a real delivery' => ['subscription.created', [], false],
]);

it('keys a repeated delivery by the event id, else the body hash', function (array $data, string $expected) {
    $body = aluntaSdkWebhookBody(data: $data);

    expect(WebhookEnvelope::parse($body)?->dedupKey())->toBe($expected === 'body' ? hash('sha256', $body) : $expected);
})->with([
    'the event id, in lower case' => [['event_id' => '123E4567-E89B-12D3-A456-426614174099'], '123e4567-e89b-12d3-a456-426614174099'],
    'the body without an event id' => [[], 'body'],
    'the body when the event id is not a uuid' => [['event_id' => 'evt_1'], 'body'],
    'the body when the event id is not a string' => [['event_id' => 7], 'body'],
]);

it('takes no delivery id, so the unsigned X-Alunta-Webhook-Id header can never become the key', function () {
    expect((new ReflectionMethod(WebhookEnvelope::class, 'dedupKey'))->getNumberOfParameters())->toBe(0);
});
