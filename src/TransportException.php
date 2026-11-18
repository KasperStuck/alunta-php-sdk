<?php

namespace KasperStuck\Alunta\Sdk;

use RuntimeException;

/**
 * A request that got no response. A transport throws it in place of its own exception, whose
 * message can hold the request URL or headers: it keeps only that exception's class, for the log,
 * and the structured request, which holds no token.
 */
final class TransportException extends RuntimeException
{
    /**
     * @param  string  $causeClass  the class of the transport's own exception
     */
    public function __construct(
        public readonly ApiRequest $request,
        public readonly string $causeClass,
    ) {
        parent::__construct('The HTTP request failed without a response.');
    }
}
