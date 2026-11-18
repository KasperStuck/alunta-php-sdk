<?php

namespace KasperStuck\Alunta\Sdk;

use Psr\Http\Message\ResponseInterface;

/**
 * Sends the attempts of one API call with the credentials Transport::prepareCall() got.
 */
interface PreparedCall
{
    /**
     * Sends the request and returns the response as it came, including a redirect or an error
     * status.
     *
     * @throws TransportException when no response arrived; AluntaClient turns it into a
     *                            connection error and logs its causeClass. Any other exception
     *                            escapes the call unchanged.
     */
    public function send(ApiRequest $request): ResponseInterface;
}
