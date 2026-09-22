<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kalender;

use App\Enums\Ability;
use App\Enums\CalendarConnectionStatus;
use App\Enums\CalendarPrivacyMode;
use App\Enums\CalendarProvider;
use App\Http\Controllers\Controller;
use App\Jobs\KalenderRueckabgleich;
use App\Kalender\Abonnements;
use App\Kalender\Blockerabgleich;
use App\Kalender\Kalenderdienste;
use App\Kalender\KalenderNichtErreichbar;
use App\Kalender\ZugangEntzogen;
use App\Models\CalendarConnection;
use App\Models\ExternalCalendarBlock;
use App\Models\Organization;
use App\Models\Practitioner;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kalender verbinden, ansehen, trennen.
 *
 * Die Seite ist zugleich der Ort, an dem R4 sichtbar wird: ein stiller
 * Ausfall erzeugt hier einen Hinweis, nicht nur einen Log-Eintrag. Ein Sync,
 * der unbemerkt steht, bedeutet Termine ueber belegten Zeiten.
 */
final class VerbindungController extends Controller
{
    /** Ein Rueckkehrweg, der laenger als das offen steht, ist keiner mehr. */
    private const STATE_MINUTEN = 15;

    public function __construct(
        private readonly Kalenderdienste $dienste,
        private readonly Abonnements $abonnements,
        private readonly Blockerabgleich $blocker,
    ) {}

    public function index(): Response
    {
        Gate::authorize(Ability::ManageMasterData->value);

        $verbindungen = CalendarConnection::query()
            ->get()
            ->groupBy(fn (CalendarConnection $v): string => (string) $v->practitioner_id);

        return Inertia::render('kalender/Verbindungen', [
            'practitioners' => Practitioner::query()
                ->where('is_active', true)
                ->orderBy('last_name')
                ->get()
                ->map(function (Practitioner $behandler) use ($verbindungen): array {
                    $eigene = $verbindungen->get((string) $behandler->getKey()) ?? collect();

                    return [
                        'uuid' => $behandler->uuid,
                        'name' => $behandler->name(),
                        'connections' => $eigene
                            ->map(fn (CalendarConnection $v): array => $this->darstellung($v))
                            ->values(),
                    ];
                })
                ->values(),

            // Ein Behandler kann beide Anbieter verbunden haben -- das Schema
            // laesst je Anbieter eine Verbindung zu.
            'providers' => collect(CalendarProvider::cases())
                ->map(fn (CalendarProvider $anbieter): array => [
                    'value' => $anbieter->value,
                    'label' => $anbieter->label(),
                ])
                ->values(),

            'privacy_modes' => collect(CalendarPrivacyMode::cases())
                ->map(fn (CalendarPrivacyMode $modus): array => [
                    'value' => $modus->value,
                    'label' => $modus->label(),
                ])
                ->values(),
        ]);
    }

    /**
     * Schickt zur Zustimmungsseite von Google.
     *
     * Der `state` ist verschluesselt und traegt Behandler, Organisation und
     * Zeitpunkt. Verschluesselt statt signiert, weil er sonst offenlegte,
     * welche Organisation gerade jemanden verbindet -- und weil ein
     * manipulierter Wert dann nicht entschluesselbar ist statt nur ungueltig.
     */
    public function verbinden(Request $request, Practitioner $practitioner, TenantContext $mandant): RedirectResponse
    {
        Gate::authorize(Ability::ManageMasterData->value);

        $organisation = $mandant->current();

        if (! $organisation instanceof Organization) {
            abort(404);
        }

        $anbieter = $this->anbieter($request);

        $state = Crypt::encryptString((string) json_encode([
            'behandler' => (string) $practitioner->uuid,
            'organisation' => (string) $organisation->uuid,
            'anbieter' => $anbieter->value,
            'zeitpunkt' => CarbonImmutable::now()->getTimestamp(),
        ]));

        return redirect()->away($this->dienste->fuer($anbieter)->weiterleitung($state));
    }

    /**
     * Der Rueckweg vom Anbieter.
     *
     * Vier Gruende, hier nichts zu tun: der Nutzer hat abgelehnt, der `state`
     * ist manipuliert, er ist alt, oder er gehoert zu einer anderen
     * Organisation. Keiner davon ist ein Fehler des Systems -- alle vier
     * enden auf der Seite mit einer Meldung.
     */
    public function rueckkehr(Request $request, TenantContext $mandant): RedirectResponse
    {
        Gate::authorize(Ability::ManageMasterData->value);

        $organisation = $mandant->current();
        $code = $request->query('code');
        $anbieter = $this->anbieter($request);
        $dienst = $this->dienste->fuer($anbieter);

        if (! $organisation instanceof Organization || ! is_string($code) || $code === '') {
            return $this->zurueck('Die Verbindung wurde nicht hergestellt.');
        }

        $behandler = $this->ausState($request->query('state'), (string) $organisation->uuid, $anbieter);

        if (! $behandler instanceof Practitioner) {
            return $this->zurueck('Der Rückweg konnte nicht zugeordnet werden. Bitte erneut versuchen.');
        }

        try {
            $daten = $dienst->tausche($code);
        } catch (ZugangEntzogen|KalenderNichtErreichbar) {
            return $this->zurueck($anbieter->label().' hat den Zugang nicht bestätigt. Bitte erneut versuchen.');
        }

        $verbindung = CalendarConnection::query()->firstOrNew([
            'practitioner_id' => $behandler->getKey(),
            'provider' => $anbieter->value,
        ]);

        $verbindung->status = CalendarConnectionStatus::Active;
        $verbindung->access_token = $daten->zugang;
        $verbindung->access_expires_at = $daten->laeuftAb;
        $verbindung->last_error = null;
        $verbindung->failed_at = null;

        // Der Aktualisierungsschluessel kommt bei Google nur beim ersten Mal
        // und bei Microsoft bei jeder Erneuerung neu. Ein ueberschriebener
        // Null-Wert waere in beiden Faellen eine Verbindung, die in einer
        // Stunde tot ist.
        if ($daten->aktualisierung !== null) {
            $verbindung->refresh_token = $daten->aktualisierung;
        }

        // Beim Neuverbinden gilt das alte Delta-Token nicht mehr.
        $verbindung->sync_token = null;
        $verbindung->calendar_id ??= 'primary';
        $verbindung->privacy_mode ??= CalendarPrivacyMode::BusyOnly;
        $verbindung->save();

        try {
            $angaben = $dienst->kalender($verbindung);

            $verbindung->calendar_id = $angaben->kennung;
            $verbindung->account_email = $angaben->adresse;
            $verbindung->calendar_timezone = $angaben->zone;
            $verbindung->save();

            $this->abonnements->erneuere($verbindung);
        } catch (ZugangEntzogen|KalenderNichtErreichbar) {
            // Der Zugang steht, der Kalender antwortet gerade nicht. Die
            // Verbindung bleibt -- der naechste Erneuerungslauf holt das nach.
            $verbindung->last_error = 'setup_incomplete';
            $verbindung->save();
        }

        KalenderRueckabgleich::dispatch((string) $verbindung->uuid, (string) $organisation->uuid);

        return $this->zurueck('Der Kalender von '.$behandler->name().' ist verbunden.', fehler: false);
    }

    /**
     * Die einzige Einstellung einer Verbindung.
     *
     * Entscheidung B4. Was `details` bedeutet, steht in
     * App\Enums\CalendarPrivacyMode -- einen Weg zum Inhalt, keinen Inhalt.
     */
    public function aktualisieren(Request $request, CalendarConnection $verbindung): RedirectResponse
    {
        Gate::authorize(Ability::ManageMasterData->value);

        $daten = $request->validate([
            'privacy_mode' => ['required', Rule::enum(CalendarPrivacyMode::class)],
        ]);

        $verbindung->privacy_mode = CalendarPrivacyMode::from((string) $daten['privacy_mode']);
        $verbindung->save();

        return back();
    }

    /** Ein Abgleich von Hand -- der Weg zurueck nach einem Ausfall. */
    public function abgleichen(CalendarConnection $verbindung, TenantContext $mandant): RedirectResponse
    {
        Gate::authorize(Ability::ManageMasterData->value);

        $organisation = $mandant->current();

        if ($organisation instanceof Organization) {
            KalenderRueckabgleich::dispatch((string) $verbindung->uuid, (string) $organisation->uuid);
        }

        return $this->zurueck('Der Abgleich läuft.', fehler: false);
    }

    /**
     * Trennt die Verbindung und gibt die Zeit frei.
     *
     * Die Reihenfolge ist Absicht: erst die Slots, dann die Blocker, dann das
     * Abonnement. Bricht ein Schritt nach aussen ab, bleibt keine Zeit belegt,
     * fuer die es keinen Grund mehr gibt.
     */
    public function trennen(CalendarConnection $verbindung): RedirectResponse
    {
        Gate::authorize(Ability::ManageMasterData->value);

        $blocker = ExternalCalendarBlock::query()
            ->where('calendar_connection_id', $verbindung->getKey())
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        $this->blocker->gibFrei($blocker);

        ExternalCalendarBlock::query()
            ->where('calendar_connection_id', $verbindung->getKey())
            ->delete();

        // Nach aussen darf beides scheitern, ohne das Trennen aufzuhalten:
        // die Verbindung ist danach ohnehin weg, und ein Kanal ohne
        // Gegenstelle laeuft von selbst ab.
        try {
            $this->abonnements->beende($verbindung);
            $this->dienste->zu($verbindung)->widerrufe($verbindung);
        } catch (ZugangEntzogen|KalenderNichtErreichbar) {
            // nichts zu tun
        }

        $verbindung->delete();

        return $this->zurueck('Die Verbindung wurde getrennt.', fehler: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function darstellung(CalendarConnection $verbindung): array
    {
        return [
            'uuid' => $verbindung->uuid,
            'provider' => $verbindung->provider->value,
            'provider_label' => $verbindung->provider->label(),
            'status' => $verbindung->status->value,
            'status_label' => $verbindung->status->label(),
            'needs_attention' => $verbindung->status->brauchtAufmerksamkeit(),
            'privacy_mode' => $verbindung->privacy_mode->value,
            'account' => $verbindung->account_email,
            'timezone' => $verbindung->calendar_timezone,
            'last_synced_at' => $verbindung->last_synced_at?->toIso8601String(),
            'expires_at' => $verbindung->channel_expires_at?->toIso8601String(),
            'error' => $verbindung->last_error,
        ];
    }

    /** Welcher Anbieter -- steht in der Route, nicht in der Anfrage. */
    private function anbieter(Request $request): CalendarProvider
    {
        $wert = $request->route('anbieter');

        return CalendarProvider::tryFrom(is_string($wert) ? $wert : '') ?? CalendarProvider::Google;
    }

    private function ausState(mixed $state, string $organisation, CalendarProvider $anbieter): ?Practitioner
    {
        if (! is_string($state) || $state === '') {
            return null;
        }

        try {
            $inhalt = json_decode(Crypt::decryptString($state), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($inhalt) || ($inhalt['organisation'] ?? null) !== $organisation) {
            return null;
        }

        // Der Rueckweg gehoert zu dem Anbieter, bei dem er losging. Sonst
        // liesse sich ein Google-Code an den Microsoft-Endpunkt reichen.
        if (($inhalt['anbieter'] ?? null) !== $anbieter->value) {
            return null;
        }

        $zeitpunkt = $inhalt['zeitpunkt'] ?? null;

        if (! is_int($zeitpunkt) || CarbonImmutable::now()->getTimestamp() - $zeitpunkt > self::STATE_MINUTEN * 60) {
            return null;
        }

        $behandler = $inhalt['behandler'] ?? null;

        return is_string($behandler)
            ? Practitioner::query()->whereUuid($behandler)->first()
            : null;
    }

    private function zurueck(string $meldung, bool $fehler = true): RedirectResponse
    {
        return redirect()
            ->route('kalender.index')
            ->with($fehler ? 'fehler' : 'erfolg', $meldung);
    }
}
