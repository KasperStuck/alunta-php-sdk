<?php

namespace KasperStuck\Alunta\Sdk;

use SensitiveParameter;

/**
 * The signature of an Alunta webhook delivery: its Signature header is the lower-case hex
 * HMAC-SHA256 of the raw request body under the webhook secret. Verify it before the body is
 * trusted, on the body exactly as received.
 */
final class WebhookSignature
{
    /**
     * Whether the signature is the body's HMAC-SHA256 under the secret, compared in constant time.
     * The signature is taken as sent: no timestamp window, no trimming and no case folding.
     */
    public static function isValid(string $body, ?string $signature, #[SensitiveParameter] string $secret): bool
    {
        return $signature !== null && hash_equals(hash_hmac('sha256', $body, $secret), $signature);
    }
}
