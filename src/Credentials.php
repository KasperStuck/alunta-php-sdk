<?php

namespace KasperStuck\Alunta\Sdk;

use SensitiveParameter;

/**
 * The API URL and token of one Alunta team. The token goes only into the Authorization header of
 * requests to that URL.
 */
final readonly class Credentials
{
    public function __construct(
        public string $baseUrl,
        #[SensitiveParameter] public string $token,
    ) {}
}
