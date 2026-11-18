<?php

namespace KasperStuck\Alunta\Sdk;

/**
 * Supplies the credentials for each call. The client asks again for every call, so a changed
 * configuration applies at once.
 */
interface CredentialsProvider
{
    /**
     * The credentials, or null when Alunta is switched off or the token or API URL is missing.
     */
    public function credentials(): ?Credentials;
}
