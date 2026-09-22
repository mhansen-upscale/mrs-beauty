<?php

declare(strict_types=1);

namespace App\Audit;

use App\Enums\ImpersonationMode;
use App\Models\ImpersonationSession;

/**
 * Die laufende Impersonation dieser Anfrage.
 *
 * Als Singleton gebunden. Gesetzt vom Middleware, gelesen von der
 * Maskierungsschicht und vom Protokoll.
 */
final class ImpersonationContext
{
    private ?ImpersonationSession $session = null;

    public function set(ImpersonationSession $session): void
    {
        $this->session = $session;
    }

    public function forget(): void
    {
        $this->session = null;
    }

    public function current(): ?ImpersonationSession
    {
        return $this->session;
    }

    public function sessionId(): ?string
    {
        $id = $this->session?->getKey();

        return is_string($id) ? $id : null;
    }

    public function isActive(): bool
    {
        return $this->session instanceof ImpersonationSession;
    }

    /**
     * Wird gerade maskiert?
     *
     * Die Vorgabe ist Maskierung. Nur eine laufende, freigegebene und noch
     * nicht abgelaufene Sitzung im Modus `full` hebt sie auf.
     */
    public function masks(): bool
    {
        if (! $this->session instanceof ImpersonationSession) {
            return false;
        }

        return $this->session->mode !== ImpersonationMode::Full
            || ! $this->session->hatVollzugriff();
    }
}
