<?php

use KasperStuck\Alunta\Sdk\WebhookSignature;

const ALUNTA_SDK_SIGNATURE_SECRET = 'webhook-test-secret';
const ALUNTA_SDK_SIGNATURE_BODY = '{"event":"subscription.created","data":{}}';

it('accepts the hex hmac-sha256 of the raw body under the secret', function () {
    $signature = hash_hmac('sha256', ALUNTA_SDK_SIGNATURE_BODY, ALUNTA_SDK_SIGNATURE_SECRET);

    expect(WebhookSignature::isValid(ALUNTA_SDK_SIGNATURE_BODY, $signature, ALUNTA_SDK_SIGNATURE_SECRET))->toBeTrue();
});

it('refuses a missing, wrong or differently formed signature', function (?string $signature) {
    expect(WebhookSignature::isValid(ALUNTA_SDK_SIGNATURE_BODY, $signature, ALUNTA_SDK_SIGNATURE_SECRET))->toBeFalse();
})->with([
    'missing' => [null],
    'empty' => [''],
    'another secret' => [hash_hmac('sha256', ALUNTA_SDK_SIGNATURE_BODY, 'another-secret')],
    'upper case hex' => [strtoupper(hash_hmac('sha256', ALUNTA_SDK_SIGNATURE_BODY, ALUNTA_SDK_SIGNATURE_SECRET))],
    'too short' => [substr(hash_hmac('sha256', ALUNTA_SDK_SIGNATURE_BODY, ALUNTA_SDK_SIGNATURE_SECRET), 0, 63)],
    'too long' => [hash_hmac('sha256', ALUNTA_SDK_SIGNATURE_BODY, ALUNTA_SDK_SIGNATURE_SECRET).'0'],
    'surrounding space' => [' '.hash_hmac('sha256', ALUNTA_SDK_SIGNATURE_BODY, ALUNTA_SDK_SIGNATURE_SECRET)],
    'prefixed' => ['sha256='.hash_hmac('sha256', ALUNTA_SDK_SIGNATURE_BODY, ALUNTA_SDK_SIGNATURE_SECRET)],
    'raw binary' => [hash_hmac('sha256', ALUNTA_SDK_SIGNATURE_BODY, ALUNTA_SDK_SIGNATURE_SECRET, true)],
]);

it('signs the raw body, not a re-encoding of it', function () {
    $prettyBody = json_encode(json_decode(ALUNTA_SDK_SIGNATURE_BODY, true), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

    expect(WebhookSignature::isValid($prettyBody, hash_hmac('sha256', ALUNTA_SDK_SIGNATURE_BODY, ALUNTA_SDK_SIGNATURE_SECRET), ALUNTA_SDK_SIGNATURE_SECRET))->toBeFalse();
});
