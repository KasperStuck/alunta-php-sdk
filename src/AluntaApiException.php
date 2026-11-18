<?php

namespace KasperStuck\Alunta\Sdk;

use RuntimeException;

/**
 * A failed Alunta API call. The message is a safe Danish text: it never contains the token, the
 * request URL or the response body, and the exception never wraps the HTTP client's exception.
 * Branch on $errorCode, never on the message.
 */
final class AluntaApiException extends RuntimeException
{
    /** Alunta is switched off or the token or API URL is missing; nothing was sent. */
    public const string NOT_CONFIGURED = 'not_configured';

    /** An identifier was not a UUID; nothing was sent. */
    public const string INVALID_ARGUMENT = 'invalid_argument';

    /** No response arrived, so the outcome of a write is unknown. */
    public const string CONNECTION = 'connection';

    public const string UNAUTHORIZED = 'unauthorized';

    /** The Alunta team itself has not paid Alunta and its management access is locked. */
    public const string PAYMENT_REQUIRED = 'payment_required';

    public const string FORBIDDEN = 'forbidden';

    public const string NOT_FOUND = 'not_found';

    /** The Idempotency-Key was used for a different request. */
    public const string CONFLICT = 'conflict';

    /** Alunta refused the request under a business rule, for example an already cancelled subscription. */
    public const string VALIDATION = 'validation';

    /** Alunta still answered 429 after the bounded retries. */
    public const string RATE_LIMITED = 'rate_limited';

    /** Any other unsuccessful status, including a redirect and a server error. */
    public const string HTTP_ERROR = 'http_error';

    /** A successful response without the documented shape. */
    public const string UNEXPECTED_RESPONSE = 'unexpected_response';

    /**
     * @param  string  $errorCode  one of the constants of this class
     * @param  ?int  $status  the HTTP status, or null when no response arrived
     * @param  ?string  $providerMessage  Alunta's own message of a 409 or 422, which names the broken rule and holds no secret
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly ?int $status,
        string $message,
        public readonly ?string $providerMessage = null,
    ) {
        parent::__construct($message);
    }
}
