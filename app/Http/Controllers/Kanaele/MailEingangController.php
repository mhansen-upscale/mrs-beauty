<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kanaele;

use App\Enums\ChannelType;
use App\Http\Controllers\Controller;
use App\Jobs\RohereignisVerarbeiten;
use App\Kanaele\Eingangsnachricht;
use App\Kanaele\Email\Mailleser;
use App\Kanaele\Rohereignisse;
use App\Models\ChannelConnection;
use App\Models\ChannelRawEvent;
use App\Models\Organization;
use App\Support\Uuid;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Der Eingang des E-Mail-Kanals.
 *
 * Derselbe Ablauf wie bei Meta (Entscheidung A14), nur mit anderem Ausweis:
 *
 * 1. **Ausweisen** ueber ein gemeinsames Geheimnis, zeitkonstant verglichen.
 * 2. **Sofort quittieren**, ohne Verarbeitung.
 * 3. **Rohereignis speichern**, Job auf die Queue `realtime`.
 * 4. **Deduplizieren** ueber die Message-ID.
 *
 * **Kein X-Hub-Signature-256.** Eine Mail traegt keine Signatur des
 * Absenders, die uns etwas sagen wuerde -- DKIM gilt der sendenden Domain
 * und nicht dem Weg zu uns. Ausgewiesen wird deshalb der **Eingangsdienst**:
 * unser eigenes Relais, das die Mail hier ablegt. Wer den Endpunkt ohne
 * Geheimnis erreicht, bekommt 403 und hinterlaesst nichts.
 *
 * **Der Rumpf ist die Mail selbst**, RFC 5322, nicht das JSON eines
 * Anbieters. Damit taugt jeder Eingangsweg, der eine Mail weiterreicht --
 * und eine spaetere Abholung ueber IMAP braeuchte dieselbe Zeile.
 */
final class MailEingangController extends Controller
{
    public function __construct(
        private readonly Rohereignisse $rohereignisse,
        private readonly Mailleser $leser,
    ) {}

    public function __invoke(Request $request, TenantContext $mandant): Response
    {
        $geheimnis = (string) config('mrs.channels.email.inbound_token');
        $gesendet = $request->header('X-Mrs-Token');

        // Zeitkonstant, und ein leeres Geheimnis laesst niemanden durch: eine
        // nicht eingerichtete Umgebung ist keine offene Tuer.
        if ($geheimnis === '' || ! is_string($gesendet) || ! hash_equals($geheimnis, $gesendet)) {
            return response('', 403);
        }

        $roh = $request->getContent();

        if (trim($roh) === '') {
            return response('', 422);
        }

        $this->nimmAuf($mandant, $roh);

        // Quittiert wird immer, sobald die Mail liegt -- auch dann, wenn wir
        // keine Verbindung dazu finden. Ein 500 wuerde beim Eingangsdienst zu
        // Wiederholungen fuehren, die nichts besser machen.
        return response('', 200);
    }

    private function nimmAuf(TenantContext $mandant, string $roh): void
    {
        $empfaenger = $this->leser->empfaenger($roh);

        if ($empfaenger === null) {
            return;
        }

        // **Die Zustellung kommt ohne Anmeldung an.** Sie findet ihren
        // Mandanten ueber die Eingangsadresse und nur darueber. Der Weg
        // fuehrt ueber acrossTenants() mit Begruendung und ist damit im
        // Protokoll sichtbar.
        $verbindung = $mandant->acrossTenants(
            'E-Mail-Zustellung kommt ohne Anmeldung an und traegt nur die Eingangsadresse',
            fn (): ?ChannelConnection => ChannelConnection::query()
                ->where('channel', ChannelType::Email->value)
                ->where('external_id', $empfaenger)
                ->first(),
        );

        if (! $verbindung instanceof ChannelConnection) {
            return;
        }

        $organisation = Organization::query()->whereKey($verbindung->organization_id)->first();

        if (! $organisation instanceof Organization) {
            return;
        }

        $mandant->runAs($organisation, function () use ($verbindung, $roh): void {
            // Dedupliziert wird ueber die Message-ID -- sie ist die Kennung
            // dieser Mail. Ein Weiterleitungsdienst, der dieselbe Mail
            // zweimal abliefert, ist der Normalfall, nicht der Randfall.
            $gelesen = $this->leser->lies($roh);
            $kennung = $gelesen instanceof Eingangsnachricht ? $gelesen->externeId : hash('sha256', $roh);

            $ereignis = $this->rohereignisse->nimmAuf(
                $verbindung,
                $kennung,
                (string) json_encode(['raw' => $roh]),
            );

            if (! $ereignis instanceof ChannelRawEvent) {
                return;
            }

            RohereignisVerarbeiten::dispatch(
                (string) $ereignis->uuid,
                Uuid::toString($ereignis->organization_id),
            );
        });
    }
}
