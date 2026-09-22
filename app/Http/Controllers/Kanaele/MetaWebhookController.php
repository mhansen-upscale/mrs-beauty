<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kanaele;

use App\Enums\ChannelType;
use App\Http\Controllers\Controller;
use App\Jobs\RohereignisVerarbeiten;
use App\Kanaele\MetaSignatur;
use App\Kanaele\Rohereignisse;
use App\Models\ChannelConnection;
use App\Models\Organization;
use App\Support\Uuid;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Ein Endpunkt fuer alle Meta-Kanaele.
 *
 * Der Ablauf steht in docs/integrationen/meta.md und ist nicht verhandelbar:
 *
 * 1. Signatur ueber `X-Hub-Signature-256` pruefen, bei Fehlschlag verwerfen
 * 2. **Sofort mit 200 quittieren, ohne Verarbeitung**
 * 3. Rohereignis speichern, Job auf Queue `realtime`
 * 4. Dedupliziert ueber die externe Nachrichten-ID
 *
 * Wer vor dem Quittieren verarbeitet, bekommt Wiederholungen -- und Meta
 * wiederholt, bis es aufgibt. Dann hat man zweimal dieselbe Arbeit und muss
 * sie trotzdem deduplizieren.
 */
final class MetaWebhookController extends Controller
{
    public function __construct(private readonly Rohereignisse $rohereignisse) {}

    /**
     * Die Bestaetigung beim Einrichten.
     *
     * Meta ruft die Adresse einmal auf und erwartet `hub.challenge` im Klartext
     * zurueck, wenn das Token stimmt.
     */
    public function verify(Request $request): Response
    {
        $token = (string) config('mrs.meta.webhook_verify_token');
        $gesendet = $request->query('hub_verify_token');

        if ($token === '' || ! is_string($gesendet) || ! hash_equals($token, $gesendet)) {
            return response('', 403);
        }

        $challenge = $request->query('hub_challenge');

        return response(is_string($challenge) ? $challenge : '', 200, ['Content-Type' => 'text/plain']);
    }

    public function __invoke(Request $request, TenantContext $mandant): Response
    {
        $rumpf = $request->getContent();

        // **Schritt 1, vor allem anderen.** Eine Zustellung, die nicht von
        // Meta stammt, wird nicht einmal gespeichert.
        if (! MetaSignatur::stimmt($rumpf, $request->header('X-Hub-Signature-256'), (string) config('mrs.meta.app_secret'))) {
            return response('', 403);
        }

        $kanal = $this->kanal((string) $request->input('object', ''));

        foreach ((array) $request->input('entry', []) as $eintrag) {
            if (is_array($eintrag) && $kanal instanceof ChannelType) {
                $this->nimmAuf($mandant, $kanal, $eintrag);
            }
        }

        // Schritt 2: quittieren. Verarbeitet wird auf der Queue.
        return response('', 200);
    }

    /**
     * @param  array<string, mixed>  $eintrag
     */
    private function nimmAuf(TenantContext $mandant, ChannelType $kanal, array $eintrag): void
    {
        $gegenstelle = $eintrag['id'] ?? null;

        if (! is_string($gegenstelle) || $gegenstelle === '') {
            return;
        }

        // **Die Zustellung kommt ohne Anmeldung an.** Sie traegt die Kennung
        // der Gegenstelle -- Seite, Rufnummer, Konto --, und nur darueber
        // findet sie ihren Mandanten. Der Weg fuehrt ueber acrossTenants()
        // mit Begruendung und ist damit im Protokoll sichtbar.
        $verbindung = $mandant->acrossTenants(
            'Meta-Zustellung kommt ohne Anmeldung an und traegt nur die Kennung der Gegenstelle',
            fn (): ?ChannelConnection => ChannelConnection::query()
                ->where('channel', $kanal->value)
                ->where('external_id', $gegenstelle)
                ->first(),
        );

        if (! $verbindung instanceof ChannelConnection) {
            return;
        }

        $organisation = Organization::query()->whereKey($verbindung->organization_id)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        $mandant->runAs($organisation, function () use ($verbindung, $eintrag): void {
            $nutzlast = (string) json_encode($eintrag);

            // Meta vergibt keine Kennung je Zustellung. Dedupliziert wird
            // ueber den Inhalt: eine woertlich gleiche Wiederholung ist
            // dieselbe Zustellung. Die feinere Deduplizierung je Nachricht
            // macht der Unique-Index auf messages.
            $ereignis = $this->rohereignisse->nimmAuf($verbindung, hash('sha256', $nutzlast), $nutzlast);

            if ($ereignis === null) {
                return;
            }

            RohereignisVerarbeiten::dispatch(
                (string) $ereignis->uuid,
                Uuid::toString($ereignis->organization_id),
            );
        });
    }

    private function kanal(string $gegenstand): ?ChannelType
    {
        return match ($gegenstand) {
            'page' => ChannelType::Messenger,
            'instagram' => ChannelType::Instagram,
            'whatsapp_business_account' => ChannelType::WhatsApp,
            default => null,
        };
    }
}
