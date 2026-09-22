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
 * Die Zustellung von Microsoft Graph.
 *
 * Dieselben vier Schritte wie bei Google (Entscheidung A14) -- mit einem
 * Schritt davor, den Google nicht kennt:
 *
 * **Der Handschlag.** Beim Anlegen eines Abonnements ruft Graph diese Adresse
 * sofort auf und erwartet den mitgegebenen `validationToken` binnen weniger
 * Sekunden **als reinen Text** zurueck. Bleibt die Antwort aus, entsteht das
 * Abonnement gar nicht. Deshalb steht diese Pruefung ganz vorn, vor jeder
 * Datenbankarbeit.
 *
 * Danach wie gehabt: `clientState` zeitkonstant vergleichen, sofort
 * quittieren, asynchron verarbeiten, ueber die externe ID deduplizieren.
 */
final class MicrosoftWebhookController extends Controller
{
    public function __invoke(Request $request, TenantContext $mandant): Response
    {
        $merkmal = $request->query('validationToken');

        if (is_string($merkmal)) {
            // Wortwoertlich zurueck, als reiner Text. Nichts davor, nichts
            // danach -- Graph vergleicht die Antwort Zeichen fuer Zeichen.
            return response($merkmal, 200, ['Content-Type' => 'text/plain']);
        }

        foreach ((array) $request->input('value', []) as $meldung) {
            if (is_array($meldung)) {
                $this->verarbeite($mandant, $meldung);
            }
        }

        // 202: angenommen, noch nicht verarbeitet. Genau das ist der Fall.
        return response('', 202);
    }

    /**
     * @param  array<string, mixed>  $meldung
     */
    private function verarbeite(TenantContext $mandant, array $meldung): void
    {
        $abonnement = $meldung['subscriptionId'] ?? null;
        $geheimnis = $meldung['clientState'] ?? null;

        if (! is_string($abonnement) || $abonnement === '' || ! is_string($geheimnis)) {
            return;
        }

        // Die Zustellung kommt ohne Anmeldung an und traegt nur die Kennung
        // des Abonnements. Der Weg zum Mandanten fuehrt ueber acrossTenants()
        // mit Begruendung und ist damit im Protokoll sichtbar.
        $verbindung = $mandant->acrossTenants(
            'Kalender-Zustellung kommt ohne Anmeldung an und traegt nur die Abonnementkennung',
            fn (): ?CalendarConnection => CalendarConnection::query()->where('channel_id', $abonnement)->first(),
        );

        if (! $verbindung instanceof CalendarConnection) {
            return;
        }

        if (! is_string($verbindung->channel_token) || ! hash_equals($verbindung->channel_token, $geheimnis)) {
            return;
        }

        // Graph nummeriert seine Zustellungen nicht. Dedupliziert wird ueber
        // die Kennung der geaenderten Ressource -- zwei Meldungen zu
        // demselben Event sind eine.
        $ressource = $meldung['resourceData']['id'] ?? $meldung['resource'] ?? '';
        $schluessel = 'kalender:zustellung:'.$abonnement.':'.sha1((string) (is_string($ressource) ? $ressource : ''));

        if (! Cache::add($schluessel, true, 60)) {
            return;
        }

        KalenderRueckabgleich::dispatch(
            (string) $verbindung->uuid,
            Uuid::toString($verbindung->organization_id),
        );
    }
}
