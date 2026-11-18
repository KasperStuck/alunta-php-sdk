<?php

namespace KasperStuck\Alunta\Sdk;

/**
 * Waits with PHP's own sleep functions.
 */
final class NativeSleeper implements Sleeper
{
    public function sleepSeconds(int $seconds): void
    {
        sleep(max(0, $seconds));
    }

    public function sleepMilliseconds(int $milliseconds): void
    {
        usleep(max(0, $milliseconds) * 1000);
    }
}
