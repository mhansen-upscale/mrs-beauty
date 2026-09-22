<?php

declare(strict_types=1);

namespace App\Audit;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Schreibt Protokolleintraege.
 *
 * **Das Protokoll verhindert den Vorgang nicht.** Schlaegt das Schreiben fehl,
 * wird das als Fehler geloggt und ein Alarm erzeugt -- aber die Ausnahme
 * fliegt nicht weiter. Ein Praxisteam, dem ein Termin an einem vollen
 * Protokolldatentraeger scheitert, verliert das Vertrauen ins Produkt
 * schneller als an einer Luecke im Protokoll.
 *
 * Die Abwaegung ist bewusst so getroffen und steht in
 * specs/WP-05-audit-log-impersonation.md.
 */
final class AuditLogger
{
    /** Verhindert, dass ein Protokolleintrag selbst wieder protokolliert wird. */
    private bool $schreibtGerade = false;

    /**
     * @param  array<int, string>  $geaenderteFelder
     * @param  array<string, mixed>  $kontext
     */
    public function record(
        AuditEvent $ereignis,
        ?Model $gegenstand = null,
        array $geaenderteFelder = [],
        array $kontext = [],
        ?string $begruendung = null,
        ?string $organizationId = null,
        bool $ohneOrganisation = false,
    ): ?AuditLog {
        if ($this->schreibtGerade) {
            return null;
        }

        $this->schreibtGerade = true;

        try {
            $eintrag = new AuditLog;

            if ($ohneOrganisation) {
                // Ein mandantenuebergreifender Vorgang gehoert zu keiner
                // einzelnen Organisation.
                $eintrag->organization_id = null;
            } elseif ($organizationId !== null) {
                $eintrag->organization_id = $organizationId;
            } elseif ($gegenstand !== null && is_string($gegenstand->getAttributes()['organization_id'] ?? null)) {
                // **Ueber getAttributes(), nicht ueber getAttribute().**
                // Model::shouldBeStrict wirft beim Zugriff auf ein Feld, das
                // ein Modell nicht hat -- und Organization hat keine
                // organization_id, sie **ist** der Mandant. Die Ausnahme
                // landete im catch unten, und der Protokolleintrag entstand
                // nie: ein Protokoll, das stillschweigend nichts schreibt,
                // ist keines (gefunden in WP-34).
                $eintrag->organization_id = $gegenstand->getAttributes()['organization_id'];
            } else {
                $eintrag->organization_id = app(TenantContext::class)->id();
            }

            $handelnde = Auth::user();

            $eintrag->event = $ereignis;
            $eintrag->actor_user_id = $handelnde instanceof User ? $handelnde->getKey() : null;
            $eintrag->actor_label = $handelnde instanceof User ? $handelnde->name : 'System';
            $eintrag->subject_type = $gegenstand?->getMorphClass();
            $eintrag->subject_id = $this->schluesselVon($gegenstand);
            $eintrag->changed_fields = $geaenderteFelder === [] ? null : array_values($geaenderteFelder);
            $eintrag->context = $kontext === [] ? null : $kontext;
            $eintrag->reason = $begruendung;
            $eintrag->ip_address = Request::ip();
            $eintrag->impersonation_session_id = app(ImpersonationContext::class)->sessionId();
            $eintrag->occurred_at = now();

            $eintrag->save();

            return $eintrag;
        } catch (Throwable $fehler) {
            Log::error('Protokolleintrag konnte nicht geschrieben werden.', [
                'event' => $ereignis->value,
                'exception' => $fehler->getMessage(),
            ]);

            return null;
        } finally {
            $this->schreibtGerade = false;
        }
    }

    private function schluesselVon(?Model $gegenstand): ?string
    {
        $schluessel = $gegenstand?->getKey();

        return is_string($schluessel) ? $schluessel : null;
    }
}
