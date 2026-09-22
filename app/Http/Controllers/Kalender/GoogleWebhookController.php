<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kalender;

use App\Http\Controllers\Controller;
use App\Jobs\KalenderRueckabgleich;
use App\Models\CalendarConnection;
use App\Support\Uuid;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Die Zustellung von Google.
 *
 * Entscheidung A14 in vier Schritten, und die Reihenfolge ist der Punkt:
 * **Signatur pruefen, sofort quittieren, asynchron verarbeiten, ueber die
 * externe ID deduplizieren.** Google erwartet innerhalb weniger Sekunden eine
 * Antwort und wiederholt sonst -- eine Zustellung, die einen vollen Abgleich
 * abwartet, erzeugt genau die Last, die sie melden soll.
 *
 * Google unterschreibt nicht, es **spiegelt**: der Kanal traegt ein Token,
 * das wir beim Bestellen vergeben haben, und es kommt bei jeder Zustellung
 * zurueck. Verglichen wird zeitkonstant.
 *
 * **Die Zustellung kommt ohne Anmeldung an.** Sie traegt nur die
 * Kanalkennung, und die ist als einziges Feld dieses Pakets
 * mandantenuebergreifend eindeutig. Der Weg dorthin fuehrt ueber
 * acrossTenants() mit Begruendung -- und ist damit im Protokoll sichtbar.
 */
final class GoogleWebhookController extends Controller
{
    public function __invoke(Request $request, TenantContext $mandant): Response
    {
        $kanal = (string) $request->header('X-Goog-Channel-ID', '');
        $token = (string) $request->header('X-Goog-Channel-Token', '');
        $zustand = (string) $request->header('X-Goog-Resource-State', '');
        $nummer = (string) $request->header('X-Goog-Message-Number', '');

        // Quittiert wird immer. Ein Fehlercode brächte Google dazu, es erneut
        // zu versuchen -- bei einer Zustellung, die wir bewusst verwerfen, ist
        // das die falsche Antwort.
        if ($kanal === '' || $token === '') {
            return response()->noContent();
        }

        // Die erste Zustellung bestaetigt nur, dass der Kanal steht.
        if ($zustand === 'sync') {
            return response()->noContent();
        }

        $verbindung = $mandant->acrossTenants(
            'Kalender-Zustellung kommt ohne Anmeldung an und traegt nur die Kanalkennung',
            fn (): ?CalendarConnection => CalendarConnection::query()->where('channel_id', $kanal)->first(),
        );

        if (! $verbindung instanceof CalendarConnection) {
            return response()->noContent();
        }

        if (! is_string($verbindung->channel_token) || ! hash_equals($verbindung->channel_token, $token)) {
            return response()->noContent();
        }

        // Deduplizierung ueber die Nachrichtennummer des Anbieters. Google
        // stellt bei jedem Zweifel erneut zu; zwei Laeufe haetten dasselbe
        // Ergebnis und die doppelte Last.
        if ($nummer !== '' && ! Cache::add("kalender:zustellung:{$kanal}:{$nummer}", true, 600)) {
            return response()->noContent();
        }

        KalenderRueckabgleich::dispatch(
            (string) $verbindung->uuid,
            Uuid::toString($verbindung->organization_id),
        );

        return response()->noContent();
    }
}
