<?php

namespace KasperStuck\Alunta\Sdk;

/**
 * Waits between requests: the Retry-After delay of a 429 and the pacing delay.
 */
interface Sleeper
{
    public function sleepSeconds(int $seconds): void;

    public function sleepMilliseconds(int $milliseconds): void;
}
