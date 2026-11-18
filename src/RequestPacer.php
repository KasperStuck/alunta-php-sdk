<?php

namespace KasperStuck\Alunta\Sdk;

/**
 * Spaces the requests of a batch run. While pacing is on, every request after the first waits the
 * given milliseconds, including a retry. Share one instance between every client of the process,
 * so pacing covers them all.
 */
final class RequestPacer
{
    private ?int $milliseconds = null;

    private bool $requestSent = false;

    /**
     * Waits $milliseconds before every request after the first, until called again with null.
     */
    public function pace(?int $milliseconds): void
    {
        $this->milliseconds = $milliseconds;
        $this->requestSent = false;
    }

    /**
     * The wait before the request about to be sent, in milliseconds, or null for none. Counts the
     * request as sent.
     */
    public function delayBeforeRequest(): ?int
    {
        if ($this->milliseconds === null) {
            return null;
        }

        $delay = $this->requestSent ? $this->milliseconds : null;
        $this->requestSent = true;

        return $delay;
    }
}
