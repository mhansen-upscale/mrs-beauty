<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Audit\Impersonation;
use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Auth\Events\Logout;

/**
 * Wer sich abmeldet, beendet seine Impersonation (WP-34a).
 *
 * Sonst stuende in der Praxis bis zum Ablauf "Support hat Zugriff", obwohl
 * niemand mehr da ist. Das gilt fuer jedes Abmelden -- von Hand, nach dem
 * Leerlauf oder beim Deaktivieren des Kontos.
 *
 * Gefunden ueber die Ereigniserkennung in app/Listeners, nicht von Hand
 * registriert: beides zusammen liefe zweimal.
 */
final class ImpersonationBeimAbmeldenBeenden
{
    public function __construct(private readonly Impersonation $impersonation) {}

    public function handle(Logout $ereignis): void
    {
        $benutzer = $ereignis->user;

        if (! $benutzer instanceof User || ! $benutzer->istBetreiber()) {
            return;
        }

        $sitzung = $this->impersonation->laufendeVon($benutzer);

        if ($sitzung instanceof ImpersonationSession) {
            $this->impersonation->end($sitzung, 'logout');
        }
    }
}
