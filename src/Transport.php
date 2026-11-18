<?php

namespace KasperStuck\Alunta\Sdk;

use SensitiveParameter;

/**
 * Sends Alunta API requests. AluntaClient calls prepareCall() once per API call: after it has
 * checked the arguments and read the credentials, before the first attempt and outside its error
 * handling, so any exception it throws escapes the call unchanged and is not logged. Every attempt
 * of the call, including a retry after a 429, is sent through the PreparedCall it returns.
 *
 * The transport sends the token only in the Authorization header and must not follow redirects,
 * so the token never reaches another host.
 */
interface Transport
{
    public function prepareCall(#[SensitiveParameter] Credentials $credentials): PreparedCall;
}
