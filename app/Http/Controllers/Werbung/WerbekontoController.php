<?php

declare(strict_types=1);

namespace App\Http\Controllers\Werbung;

use App\Enums\Ability;
use App\Enums\SyncState;
use App\Http\Controllers\Controller;
use App\Jobs\KampagneUebertragen;
use App\Jobs\WerbestrukturAbgleichen;
use App\Jobs\WerbezahlenAbgleichen;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdSet;
use App\Models\Location;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use App\Werbung\Kontenauswahl;
use App\Werbung\Meta\Werbezugang;
use App\Werbung\Verwaltung\Kampagnenplan;
use App\Werbung\Verwaltung\Kampagnenverwaltung;
use App\Werbung\Werbefehler;
use App\Werbung\Werbekontoangabe;
use App\Werbung\Werbetoken;
use App\Werbung\Werbeuebersicht;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Werbekonto verbinden, ansehen, trennen.
 *
 * **Die Seite laedt auch, wenn Meta nicht antwortet** (Entscheidung B2,
 * Regel 4). Gezeigt wird der zuletzt gelesene Stand samt Hinweis, nicht ein
 * Fehler -- eine Praxis, deren Werbeuebersicht bei jeder Stoerung bei Meta
 * weiss bleibt, haelt das Produkt fuer kaputt.
 */
final class WerbekontoController extends Controller
{
    /** Ein Rueckkehrweg, der laenger offen steht, ist keiner mehr. */
    private const STATE_MINUTEN = 15;

    public function __construct(
        private readonly Werbezugang $zugang,
        private readonly Kontenauswahl $konten,
        private readonly Werbeuebersicht $uebersicht,
        private readonly Kampagnenverwaltung $verwaltung,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        $zeitraum = $this->uebersicht->zeitraum(
            is_numeric($request->query('zeitraum')) ? (int) $request->query('zeitraum') : null
        );

        return Inertia::render('werbung/Index', [
            'konto' => $this->uebersicht->konto(),
            'kampagnen' => $this->uebersicht->kampagnen($zeitraum['von'], $zeitraum['bis']),
            'summe' => $this->uebersicht->summe($zeitraum['von'], $zeitraum['bis']),
            'verlauf' => $this->uebersicht->verlauf($zeitraum['von'], $zeitraum['bis']),
            'zeitraum' => [
                'tage' => $zeitraum['tage'],
                'von' => $zeitraum['von']->toDateString(),
                'bis' => $zeitraum['bis']->toDateString(),
                'auswahl' => array_map(intval(...), (array) config('mrs.ads.ranges')),
            ],

            // Die Auswahl steht nur unmittelbar nach dem Rueckweg an. Sie
            // liegt in der Sitzung und nicht in der Datenbank: ein Token, das
            // noch keinem Konto gehoert, hat dort nichts verloren.
            'auswahl' => $request->session()->get('werbung.auswahl'),

            // Die Vorgaben stehen in der Seite, damit sie dort **erklaert**
            // werden koennen -- erzwungen werden sie im Server (WP-27).
            'vorgaben' => [
                'ziele' => (array) config('mrs.ads.objectives'),
                'mindestalter' => (int) config('mrs.ads.min_age'),
                'hoechstalter' => (int) config('mrs.ads.max_age'),
                'mindestbudget' => Kampagnenplan::mindestbudget(AdAccount::query()->first()),
                'umkreis' => (array) config('mrs.ads.radius_km'),
            ],

            'standorte' => Location::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Location $standort): array => [
                    'uuid' => $standort->uuid,
                    'name' => $standort->name,
                    // Ein Ort, den Meta nicht kennt, kann keinen Umkreis
                    // tragen -- das faellt sonst erst beim Uebertragen auf.
                    'ort' => $standort->city,
                ])
                ->values(),
        ]);
    }

    /**
     * Schickt zur Anmeldung bei Meta.
     *
     * Der `state` ist verschluesselt und traegt Organisation und Zeitpunkt.
     * Verschluesselt statt signiert, weil er sonst offenlegte, welche
     * Organisation gerade verbindet.
     */
    public function verbinden(TenantContext $mandant): RedirectResponse
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        $organisation = $mandant->current();

        if (! $organisation instanceof Organization) {
            abort(404);
        }

        $state = Crypt::encryptString((string) json_encode([
            'organisation' => (string) $organisation->uuid,
            'zeitpunkt' => CarbonImmutable::now()->getTimestamp(),
        ]));

        return redirect()->away($this->zugang->weiterleitung($state));
    }

    /**
     * Der Rueckweg von Meta.
     *
     * Vier Gruende, hier nichts zu tun: die Praxis hat abgelehnt, der `state`
     * ist manipuliert, er ist alt, oder er gehoert zu einer anderen
     * Organisation. Keiner davon ist ein Fehler des Systems.
     */
    public function rueckkehr(Request $request, TenantContext $mandant): RedirectResponse
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        $organisation = $mandant->current();
        $code = $request->query('code');

        if (! $organisation instanceof Organization || ! is_string($code) || $code === '') {
            return $this->zurueck('Die Verbindung wurde nicht hergestellt.');
        }

        if (! $this->stateGilt($request->query('state'), (string) $organisation->uuid)) {
            return $this->zurueck('Der Rückweg konnte nicht zugeordnet werden. Bitte erneut versuchen.');
        }

        try {
            $token = $this->zugang->tausche($code);
            $konten = $this->konten->verfuegbare($token->zugang);
        } catch (Werbefehler) {
            return $this->zurueck('Meta hat den Zugang nicht bestätigt. Bitte erneut versuchen.');
        }

        if ($konten === []) {
            return $this->zurueck('Für diesen Zugang ist kein Werbekonto freigegeben.');
        }

        // **Die Auswahl ist ein eigener Schritt.** Wer mehrere Konten hat,
        // gibt oft alle frei; das falsche zu nehmen faellt spaet auf, weil
        // die Zahlen plausibel aussehen -- sie gehoeren nur jemand anderem.
        $request->session()->put('werbung.auswahl', [
            'token' => Crypt::encryptString($token->zugang),
            'laeuftAb' => $token->laeuftAb?->toIso8601String(),
            'konten' => array_map(fn (Werbekontoangabe $k): array => $k->toArray(), $konten),
        ]);

        return redirect()->route('werbung.index');
    }

    /** Genau eines wird verbunden. */
    public function auswaehlen(Request $request, TenantContext $mandant): RedirectResponse
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        $daten = $request->validate([
            'kennung' => ['required', 'string', 'max:64'],
        ]);

        $auswahl = $request->session()->get('werbung.auswahl');
        $organisation = $mandant->current();

        if (! is_array($auswahl) || ! $organisation instanceof Organization) {
            return $this->zurueck('Die Auswahl ist abgelaufen. Bitte erneut verbinden.');
        }

        $angabe = collect(is_array($auswahl['konten'] ?? null) ? $auswahl['konten'] : [])
            ->first(fn (mixed $k): bool => is_array($k) && ($k['kennung'] ?? null) === $daten['kennung']);

        if (! is_array($angabe)) {
            return $this->zurueck('Dieses Werbekonto steht nicht zur Auswahl.');
        }

        try {
            $zugang = Crypt::decryptString((string) $auswahl['token']);
        } catch (DecryptException) {
            return $this->zurueck('Die Auswahl ist abgelaufen. Bitte erneut verbinden.');
        }

        $laeuftAb = is_string($auswahl['laeuftAb'] ?? null) ? CarbonImmutable::parse($auswahl['laeuftAb']) : null;

        $konto = $this->konten->verbinde(
            new Werbekontoangabe(
                kennung: (string) $angabe['kennung'],
                name: is_string($angabe['name'] ?? null) ? $angabe['name'] : null,
                waehrung: is_string($angabe['waehrung'] ?? null) ? $angabe['waehrung'] : null,
                zeitzone: is_string($angabe['zeitzone'] ?? null) ? $angabe['zeitzone'] : null,
                business: is_string($angabe['business'] ?? null) ? $angabe['business'] : null,
                nutzbar: (bool) ($angabe['nutzbar'] ?? false),
            ),
            new Werbetoken($zugang, $laeuftAb),
        );

        $request->session()->forget('werbung.auswahl');

        WerbestrukturAbgleichen::dispatch((string) $organisation->uuid, (string) $konto->uuid);
        WerbezahlenAbgleichen::dispatch((string) $organisation->uuid, (string) $konto->uuid);

        // **Liegengebliebenes nachziehen.** Eine Aenderung, die an einem
        // abgelaufenen Zugang haengen blieb, ist weiterhin gewollt -- sie
        // wartet, bis die Verbindung wieder steht, und nicht darauf, dass
        // jemand sie erneut eintippt.
        AdCampaign::query()
            ->where('sync_state', SyncState::Pending->value)
            ->get()
            ->each(fn (AdCampaign $wartend) => KampagneUebertragen::dispatch(
                (string) $organisation->uuid,
                (string) $wartend->uuid,
            ));

        return redirect()->route('werbung.index')
            ->with('erfolg', 'Das Werbekonto ist verbunden. Die Kampagnen werden im Hintergrund geholt.');
    }

    /**
     * Ein Abgleich von Hand.
     *
     * **Der Knopf stellt ein, er ruft nicht auf** (Entscheidung B2). Eine
     * Seite, die auf Metas Antwort wartet, haengt bei jeder Stoerung dort.
     */
    public function abgleichen(AdAccount $werbekonto, TenantContext $mandant): RedirectResponse
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        $organisation = $mandant->current();

        if (! $organisation instanceof Organization) {
            abort(404);
        }

        // Struktur und Zahlen als zwei Auftraege: die Zahlen kosten ein
        // Vielfaches an Anfragen, und ein Rate-Limit dort soll nicht dazu
        // fuehren, dass auch die Struktur nicht ankommt.
        WerbestrukturAbgleichen::dispatch((string) $organisation->uuid, (string) $werbekonto->uuid);
        WerbezahlenAbgleichen::dispatch((string) $organisation->uuid, (string) $werbekonto->uuid);

        return back()->with('erfolg', 'Der Abgleich läuft.');
    }

    /**
     * Trennen.
     *
     * **Das Token geht, die Struktur bleibt.** Wer die Kampagnen mitloescht,
     * reisst die Verbindung zwischen einem Termin und der Anzeige, die ihn
     * gebracht hat.
     */
    /**
     * Die Facebook-Seite, in deren Namen die Anzeigen erscheinen.
     *
     * **Von Hand, nicht gelesen.** Metas Seitenliste braeuchte
     * `pages_show_list`, und jede zusaetzliche Berechtigung verzoegert den
     * App Review (WP-00). Eine Kennung abzutippen ist einmal Arbeit; eine
     * Berechtigung mehr ist Wochen.
     */
    public function seiteHinterlegen(Request $request, AdAccount $werbekonto): RedirectResponse
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        $daten = $request->validate([
            'seite' => ['nullable', 'string', 'regex:/^[0-9]{5,32}$/'],
        ], [
            'seite.regex' => 'Eine Seiten-ID besteht nur aus Ziffern.',
        ]);

        $werbekonto->page_external_id = isset($daten['seite']) && $daten['seite'] !== ''
            ? (string) $daten['seite']
            : null;
        $werbekonto->save();

        return back()->with('erfolg', 'Die Facebook-Seite ist hinterlegt.');
    }

    public function trennen(AdAccount $werbekonto): RedirectResponse
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        $this->konten->trenne($werbekonto);

        return back()->with('erfolg', 'Das Werbekonto ist getrennt. Die gelesenen Kampagnen bleiben sichtbar.');
    }

    /**
     * Eine Kampagne anlegen.
     *
     * **Die Pruefungen stehen im Server**, nicht in der Seite: ein Formular,
     * das Metas Regeln nur kennt, erzwingt sie nicht. Die Regeln kommen aus
     * Kampagnenplan -- derselben Fassung, aus der die Seite ihre Hinweise
     * nimmt.
     */
    public function kampagneAnlegen(Request $request, TenantContext $mandant): RedirectResponse
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        $konto = AdAccount::query()->whereNull('disconnected_at')->first();
        $organisation = $mandant->current();

        if (! $konto instanceof AdAccount || ! $organisation instanceof Organization) {
            return $this->zurueck('Es ist kein Werbekonto verbunden.');
        }

        $daten = $request->validate(Kampagnenplan::regeln($konto), [
            'altervon.min' => 'Werbung für ästhetische Behandlungen richtet sich nicht an Minderjährige.',
            'alterbis.min' => 'Werbung für ästhetische Behandlungen richtet sich nicht an Minderjährige.',
            'tagesbudget.min' => 'Unterhalb dieses Betrags liefert Meta nicht aus — das Geld läge fest, ohne zu wirken.',
        ]);

        $kampagne = $this->verwaltung->plane($konto, new Kampagnenplan(
            ziel: (string) $daten['ziel'],
            tagesbudgetMinor: (int) $daten['tagesbudget'],
            beginn: CarbonImmutable::parse((string) $daten['beginn']),
            ende: isset($daten['ende']) && is_string($daten['ende']) ? CarbonImmutable::parse($daten['ende']) : null,
            standort: (string) $daten['standort'],
            umkreisKm: (int) $daten['umkreis'],
            altervon: (int) $daten['altervon'],
            alterbis: (int) $daten['alterbis'],
            geschlecht: isset($daten['geschlecht']) && is_string($daten['geschlecht']) ? $daten['geschlecht'] : null,
        ));

        KampagneUebertragen::dispatch((string) $organisation->uuid, (string) $kampagne->uuid);

        return back()->with('erfolg', 'Die Kampagne ist angelegt und wird übertragen — pausiert, bis Sie sie starten.');
    }

    /**
     * Zustand oder Budget aendern.
     *
     * Der Name fehlt mit Absicht: eine fremde Kampagne benennen wir nicht um,
     * und unsere erzeugt das Produkt (C9).
     */
    public function kampagneAendern(Request $request, AdCampaign $kampagne, TenantContext $mandant): RedirectResponse
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        $konto = AdAccount::query()->whereNull('disconnected_at')->first();
        $organisation = $mandant->current();

        $daten = $request->validate([
            'zustand' => ['nullable', 'in:ACTIVE,PAUSED'],
            'tagesbudget' => ['nullable', 'integer', 'min:'.Kampagnenplan::mindestbudget($konto)],

            // Die Zielgruppe haengt an der Anzeigengruppe, nicht an der
            // Kampagne -- die Praxis will diese Ebene aber nicht
            // unterscheiden, also nimmt ein Formular beides entgegen.
            'umkreis' => [
                'nullable', 'integer',
                'min:'.(int) config('mrs.ads.radius_km.min'),
                'max:'.(int) config('mrs.ads.radius_km.max'),
            ],
            'altervon' => ['nullable', 'integer', 'min:'.(int) config('mrs.ads.min_age'), 'max:'.(int) config('mrs.ads.max_age')],
            'alterbis' => ['nullable', 'integer', 'min:'.(int) config('mrs.ads.min_age'), 'max:'.(int) config('mrs.ads.max_age'), 'gte:altervon'],
            'geschlecht' => ['nullable', 'in:weiblich,maennlich'],
        ], [
            'tagesbudget.min' => 'Unterhalb dieses Betrags liefert Meta nicht aus.',
            'altervon.min' => 'Ästhetische Eingriffe werden nicht an Minderjährige beworben.',
            'alterbis.gte' => 'Das Alter von muss unter dem Alter bis liegen.',
        ]);

        if (! $organisation instanceof Organization) {
            abort(404);
        }

        $this->verwaltung->passeAn(
            $kampagne,
            zustand: isset($daten['zustand']) && is_string($daten['zustand']) ? $daten['zustand'] : null,
            tagesbudgetMinor: isset($daten['tagesbudget']) ? (int) $daten['tagesbudget'] : null,
        );

        $gruppe = AdSet::query()->where('ad_campaign_id', $kampagne->getKey())->first();

        if ($gruppe instanceof AdSet) {
            $this->verwaltung->passeZielgruppeAn(
                $gruppe,
                umkreisKm: isset($daten['umkreis']) ? (int) $daten['umkreis'] : null,
                altervon: isset($daten['altervon']) ? (int) $daten['altervon'] : null,
                alterbis: isset($daten['alterbis']) ? (int) $daten['alterbis'] : null,
                geschlecht: isset($daten['geschlecht']) && is_string($daten['geschlecht']) ? $daten['geschlecht'] : null,
                geschlechtGesetzt: $request->has('geschlecht'),
            );
        }

        KampagneUebertragen::dispatch((string) $organisation->uuid, (string) $kampagne->uuid);

        return back()->with('erfolg', 'Die Änderung wird übertragen.');
    }

    private function stateGilt(mixed $state, string $organisation): bool
    {
        if (! is_string($state) || $state === '') {
            return false;
        }

        try {
            $daten = json_decode(Crypt::decryptString($state), true);
        } catch (DecryptException) {
            return false;
        }

        if (! is_array($daten) || ($daten['organisation'] ?? null) !== $organisation) {
            return false;
        }

        $zeitpunkt = $daten['zeitpunkt'] ?? null;

        return is_int($zeitpunkt)
            && CarbonImmutable::createFromTimestamp($zeitpunkt)
                ->greaterThan(CarbonImmutable::now()->subMinutes(self::STATE_MINUTEN));
    }

    private function zurueck(string $meldung): RedirectResponse
    {
        return redirect()->route('werbung.index')->with('fehler', $meldung);
    }
}
