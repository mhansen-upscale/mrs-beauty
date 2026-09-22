<?php

declare(strict_types=1);

namespace App\Http\Controllers\Anzeigen;

use App\Abrechnung\Kontingente;
use App\Anzeigen\Bildmodell;
use App\Anzeigen\BildNichtErzeugt;
use App\Anzeigen\Entwurf;
use App\Anzeigen\Vorschlagslauf;
use App\Datenschutz\Anhangspeicher;
use App\Enums\Ability;
use App\Enums\Ampel;
use App\Enums\Vorschlagsstatus;
use App\Http\Controllers\Controller;
use App\Jobs\AnzeigenbildErzeugen;
use App\Jobs\AnzeigeUebertragen;
use App\Marke\Markenprofil;
use App\Models\Ad;
use App\Models\AdCampaign;
use App\Models\AdSet;
use App\Models\AdSuggestion;
use App\Models\Attachment;
use App\Models\Organization;
use App\Models\User;
use App\Werbung\Verwaltung\Anzeigenschaltung;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Die Anzeigenentwuerfe der Woche.
 *
 * **Es gibt keinen Zustand, in dem einer ohne Menschen hinausgeht.**
 * Freigeben laesst sich nur, was gruen ist -- oder was jemand mit Begruendung
 * uebersteuert hat (Entscheidung C3). Damit bekommt die Uebersteuerung aus
 * WP-30 ihre Oberflaeche.
 */
final class AnzeigenController extends Controller
{
    public function __construct(
        private readonly Vorschlagslauf $lauf,
        private readonly Markenprofil $profil,
        private readonly Bildmodell $bilder,
        private readonly Kontingente $kontingente,
        private readonly Anzeigenschaltung $schaltung,
    ) {}

    public function index(): Response
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        // Jenseits dieser Grenze entsteht nichts mehr -- der Lauf ist tot,
        // auch wenn niemand einen Fehler festhalten konnte.
        $frist = CarbonImmutable::now()->subMinutes((int) config('mrs.ads.image_timeout_minutes'));

        // **Liegt der Auftrag noch, oder ist er verloren?** Das sind zwei
        // verschiedene Fehler. Steht etwas in der Warteschlange und niemand
        // arbeitet sie ab, ist nichts "nicht angekommen" -- es hat nie
        // angefangen, und der Fehler liegt im Betrieb, nicht beim Anbieter.
        $steht = Queue::size('maintenance') > 0;

        $vorschlaege = AdSuggestion::query()
            ->with(['pruefung', 'attachments'])
            ->orderByDesc('week')
            ->orderBy('created_at')
            ->limit(30)
            ->get();

        return Inertia::render('anzeigen/Index', [
            'vorschlaege' => $vorschlaege
                ->map(function (AdSuggestion $v) use ($frist, $steht): array {
                    $bild = $v->bild();
                    $laeuft = ! $bild instanceof Attachment
                        && $v->image_requested_at !== null
                        && $v->image_error === null
                        && $v->image_requested_at->greaterThan($frist);

                    return [
                        'uuid' => $v->uuid,
                        'woche' => $v->week->toDateString(),
                        'ueberschrift' => $v->headline,
                        'text' => $v->body,
                        'beschreibung' => $v->description,
                        'handlungsaufruf' => $v->cta,
                        'status' => $v->status->value,
                        'statusText' => $v->status->label(),
                        'ampel' => $v->ampel()?->value,
                        'ampelText' => $v->ampel()?->label(),
                        'befunde' => $v->pruefung?->befunde() ?? [],
                        'uebersteuert' => $v->pruefung?->uebersteuert() ?? false,
                        'uebersteuerungsgrund' => $v->pruefung?->override_reason,
                        'darfFreigeben' => $v->darfFreigegebenWerden(),
                        'hatBild' => $bild instanceof Attachment,

                        // **Laeuft gerade**: angefordert, noch keine Datei, kein
                        // Fehler, und noch innerhalb der Frist. Ohne diesen
                        // Zustand sieht die Kachel nach einem Klick
                        // minutenlang unveraendert aus -- ohne die Frist
                        // dreht sie ewig.
                        'bildLaeuft' => $laeuft,

                        // In welchen Kampagnen diese Anzeige schon laeuft --
                        // damit niemand dieselbe zweimal schaltet.
                        'motiv' => $v->image_brief,

                        // **Welches Modell die Grafik gemacht hat.** Am
                        // 22.09.2026 lieferte ein Worker, der seit dem
                        // Vortag lief, stillschweigend das alte Modell und
                        // den alten Auftrag -- ohne Bildmotiv. Sichtbar
                        // waere das in einer Zeile gewesen.
                        'bildmodell' => $v->image_model,
                        'geschaltet' => Ad::query()
                            ->where('ad_suggestion_id', $v->getKey())
                            ->count(),
                        'bildFehler' => $v->image_error ?? ($this->abgebrochen($v, $bild, $laeuft)
                            ? ($steht
                                ? 'Der Auftrag wartet noch — die Warteschlange wird gerade nicht abgearbeitet.'
                                : 'Die Grafik ist nicht angekommen. Bitte noch einmal versuchen.')
                            : null),

                        // Die Adresse bleibt dieselbe, wenn eine Grafik ersetzt
                        // wird. Ohne den Zeitstempel zeigte der Browser die alte.
                        'bildUrl' => $bild instanceof Attachment
                            ? route('anzeigen.bild', ['vorschlag' => $v->uuid, 'v' => $bild->created_at?->timestamp])
                            : null,
                    ];
                })
                ->values(),

            // Der Hinweis erscheint erst, wenn etwas ueberfaellig ist. Waehrend
            // eines normalen Laufs liegt ebenfalls ein Auftrag in der
            // Warteschlange -- daraus einen Betriebsfehler zu melden, hiesse
            // bei jeder Grafik Alarm zu schlagen.
            // Wohin eine freigegebene Anzeige gehen kann. Nur eigene
            // Kampagnen: eine aus Metas Bestand uebernommene hat keine
            // Anzeigengruppe, die wir kennen.
            'kampagnen' => AdCampaign::query()
                ->where('managed_by_us', true)
                ->whereNull('vanished_at')
                ->get()
                ->map(fn (AdCampaign $k): array => [
                    'uuid' => $k->uuid,
                    'name' => $k->name,
                    'zustand' => $k->effective_status ?? $k->status,
                ])
                ->values(),

            'warteschlangeSteht' => $steht && $vorschlaege->contains(
                fn (AdSuggestion $v): bool => $v->bild() === null
                    && $v->image_requested_at !== null
                    && $v->image_requested_at->lessThanOrEqualTo($frist)
            ),
            'reifegrad' => $this->profil->reifegrad(),
            'laengen' => [
                'ueberschrift' => (int) config('mrs.ads.text.headline_max'),
                'text' => (int) config('mrs.ads.text.body_max'),
                'beschreibung' => (int) config('mrs.ads.text.description_max'),
                'handlungsaufruf' => (int) config('mrs.ads.text.cta_max'),
                'motiv' => (int) config('mrs.ads.text.brief_max'),
            ],
            'mindestReifegrad' => (int) config('mrs.ads.min_reifegrad'),
            'bildmodell' => $this->bilder->angebunden(),
            'bilderRest' => $this->kontingente->rest()['bilder'] ?? 0,
        ]);
    }

    /**
     * Ein Lauf, von dem nichts mehr kommt.
     *
     * Angefordert, keine Datei, kein festgehaltener Grund -- und die Frist
     * ist abgelaufen. Ein Worker, den jemand abschiesst, hinterlaesst genau
     * diesen Zustand, und stillschweigend waere er das Schlimmste: die
     * Praxis hat bezahlt und sieht nichts.
     */
    private function abgebrochen(AdSuggestion $vorschlag, ?Attachment $bild, bool $laeuft): bool
    {
        return ! $bild instanceof Attachment
            && ! $laeuft
            && $vorschlag->image_requested_at !== null;
    }

    /**
     * Eine Anzeige, die jemand selbst schreibt.
     *
     * **Der woechentliche Lauf schlaegt vor, er verwaltet nicht.** Eine
     * Praxis, die den Tag der offenen Tuer bewerben will, wartet damit nicht
     * bis Montag -- und sie braucht dafuer auch kein Sprachmodell.
     */
    public function speichern(Request $request): RedirectResponse
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        $daten = $request->validate([
            'ueberschrift' => ['required', 'string', 'max:'.(int) config('mrs.ads.text.headline_max')],
            'text' => ['required', 'string', 'max:'.(int) config('mrs.ads.text.body_max')],
            'beschreibung' => ['nullable', 'string', 'max:'.(int) config('mrs.ads.text.description_max')],
            'handlungsaufruf' => ['nullable', 'string', 'max:'.(int) config('mrs.ads.text.cta_max')],
            'motiv' => ['nullable', 'string', 'max:'.(int) config('mrs.ads.text.brief_max')],
        ], [
            'ueberschrift.required' => 'Ohne Überschrift ist es keine Anzeige.',
            'text.required' => 'Bitte schreiben Sie den Anzeigentext.',
        ]);

        $vorschlag = $this->lauf->legeVonHand(new Entwurf(
            ueberschrift: (string) $daten['ueberschrift'],
            text: (string) $daten['text'],
            beschreibung: isset($daten['beschreibung']) ? (string) $daten['beschreibung'] : null,
            handlungsaufruf: isset($daten['handlungsaufruf']) ? (string) $daten['handlungsaufruf'] : null,
        ));

        // Das Motiv gehoert zum Entwurf, nicht zum Auftrag: es ueberlebt
        // jede weitere Grafik.
        if (isset($daten['motiv']) && $daten['motiv'] !== '') {
            $vorschlag->image_brief = (string) $daten['motiv'];
            $vorschlag->save();
        }

        return back()->with(
            'erfolg',
            $vorschlag->ampel() === Ampel::Gruen
                ? 'Die Anzeige ist angelegt. Jetzt fehlt nur noch die Grafik.'
                : 'Die Anzeige ist angelegt — die Prüfung hat etwas angemerkt.',
        );
    }

    /**
     * Freigeben.
     *
     * **Nur, was gruen ist oder begruendet uebersteuert wurde.** Gelb heisst
     * "jemand muss hinsehen" -- das ist nicht dasselbe wie hingesehen haben.
     */
    public function freigeben(AdSuggestion $vorschlag): RedirectResponse
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        if (! $vorschlag->darfFreigegebenWerden()) {
            return back()->with(
                'fehler',
                'Dieser Entwurf ist nicht freigegeben. Beheben Sie die Befunde oder übersteuern Sie mit Begründung.',
            );
        }

        $vorschlag->status = Vorschlagsstatus::Freigegeben;
        $vorschlag->save();

        return back()->with('erfolg', 'Der Entwurf ist freigegeben.');
    }

    public function verwerfen(AdSuggestion $vorschlag): RedirectResponse
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        $vorschlag->status = Vorschlagsstatus::Verworfen;
        $vorschlag->save();

        return back();
    }

    /**
     * Aus dem freigegebenen Entwurf wird eine Anzeige.
     *
     * **Nur freigegeben, nur mit Grafik.** Ein Entwurf ist ein Entwurf, und
     * eine Anzeige ohne Bild ist keine -- Meta liefert sie nicht aus, und im
     * Feed waere sie unsichtbar.
     *
     * Angelegt wird lokal und pausiert; hinaus traegt sie ein Auftrag
     * (Regel 4).
     */
    public function schalten(Request $request, AdSuggestion $vorschlag): RedirectResponse
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        $daten = $request->validate([
            'kampagne' => ['required', 'string'],
        ]);

        if ($vorschlag->status !== Vorschlagsstatus::Freigegeben) {
            return back()->with('fehler', 'Erst freigeben, dann schalten.');
        }

        if (! $vorschlag->bild() instanceof Attachment) {
            return back()->with('fehler', 'Ohne Grafik ist es keine Anzeige. Bitte erzeugen Sie zuerst eine.');
        }

        $kampagne = AdCampaign::query()->whereUuid((string) $daten['kampagne'])->first();
        $gruppe = $kampagne instanceof AdCampaign
            ? AdSet::query()->where('ad_campaign_id', $kampagne->getKey())->first()
            : null;

        if (! $gruppe instanceof AdSet) {
            return back()->with('fehler', 'Diese Kampagne hat keine Anzeigengruppe.');
        }

        // Zweimal derselbe Entwurf in derselben Kampagne waere zweimal
        // dieselbe Anzeige -- und zweimal Geld.
        $schon = Ad::query()
            ->where('ad_set_id', $gruppe->getKey())
            ->where('ad_suggestion_id', $vorschlag->getKey())
            ->exists();

        if ($schon) {
            return back()->with('fehler', 'Diese Anzeige läuft in dieser Kampagne bereits.');
        }

        $organisation = $vorschlag->organization;

        if (! $organisation instanceof Organization) {
            abort(404);
        }

        $anzeige = $this->schaltung->plane($gruppe, $vorschlag);

        AnzeigeUebertragen::dispatch((string) $organisation->uuid, (string) $anzeige->uuid);

        return back()->with('erfolg', 'Die Anzeige wird angelegt — pausiert, damit Sie sie vorher ansehen können.');
    }

    /**
     * Zurueck zum Entwurf.
     *
     * **Kein Zustand ohne Ausgang.** Verworfen war eine Sackgasse: die
     * Kachel blieb stehen, und keine Aktion war mehr erreichbar. Dasselbe
     * gilt fuer die Freigabe -- wer sie zurueckzieht, hat es sich anders
     * ueberlegt, und das ist kein Fehler.
     */
    public function zurueckholen(AdSuggestion $vorschlag): RedirectResponse
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        $vorschlag->status = Vorschlagsstatus::Entwurf;
        $vorschlag->save();

        return back()->with('erfolg', 'Der Entwurf ist wieder in Arbeit.');
    }

    /**
     * Die Uebersteuerung (Entscheidung C3).
     *
     * **Ohne Begruendung geht nichts.** Ein Protokolleintrag ohne Grund waere
     * eine Liste von Zeitstempeln -- und das Produkt ist eine Pruefhilfe,
     * keine Rechtsberatung: wer gegen sie entscheidet, soll sagen, warum.
     */
    public function uebersteuern(Request $request, AdSuggestion $vorschlag): RedirectResponse
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        $daten = $request->validate([
            'grund' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'grund.required' => 'Bitte begründen Sie, warum dieser Befund hier nicht zutrifft.',
            'grund.min' => 'Bitte begründen Sie es in einem ganzen Satz.',
        ]);

        $pruefung = $vorschlag->pruefung()->first();
        $benutzer = $request->user();

        if ($pruefung === null || ! $benutzer instanceof User) {
            abort(404);
        }

        $pruefung->override_reason = (string) $daten['grund'];
        $pruefung->overridden_by_user_id = $benutzer->getKey();
        $pruefung->overridden_at = CarbonImmutable::now();
        $pruefung->save();

        return back()->with('erfolg', 'Die Übersteuerung ist festgehalten.');
    }

    /**
     * Die Grafik zu diesem Entwurf -- auf Anforderung, nicht im Lauf.
     *
     * **Angestossen, nicht abgewartet** (Regel 4). Das Bildmodell braucht ein
     * bis drei Minuten; wer darauf im Anfragezyklus wartet, bekommt einen
     * Zeitablauf statt einer Grafik.
     *
     * Was sofort zu beantworten ist -- kein Bildmodell, kein Kontingent --
     * wird hier beantwortet, nicht erst in der Warteschlange.
     *
     * Das Bildmodell setzt den Text mit in die Grafik. Damit steht darauf
     * eine Werbeaussage, die keine Pruefung gesehen hat -- abgesichert durch
     * die Regel aus WP-30: ein Bild bekommt nie gruen, und freigeben kann nur
     * ein Mensch, der es angesehen hat.
     */
    public function bildAnfordern(Request $request, AdSuggestion $vorschlag): RedirectResponse
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        $benutzer = $request->user();

        if (! $benutzer instanceof User) {
            abort(404);
        }

        $daten = $request->validate([
            'motiv' => ['nullable', 'string', 'max:'.(int) config('mrs.ads.text.brief_max')],
        ], [
            'motiv.max' => 'Bitte fassen Sie das Motiv kürzer — es soll ein Motiv bleiben, keine Anweisung.',
        ]);

        // Leer heisst: kein eigenes Motiv, nicht "loesche das alte" -- ein
        // Nacherzeugen ohne Eingabe soll dasselbe Motiv verwenden.
        if ($request->has('motiv')) {
            $vorschlag->image_brief = isset($daten['motiv']) && $daten['motiv'] !== ''
                ? (string) $daten['motiv']
                : null;
        }

        try {
            $this->lauf->pruefeBildmoeglich();
        } catch (BildNichtErzeugt $fehler) {
            return back()->with('fehler', $fehler->getMessage());
        }

        $organisation = $vorschlag->organization;

        if (! $organisation instanceof Organization) {
            abort(404);
        }

        // **Der Zustand steht sofort, nicht erst wenn der Worker anfaengt.**
        // Sonst zeigt die Kachel nach dem Klick weiter "noch ohne Grafik",
        // und niemand weiss, ob etwas passiert. Gezaehlt wird dadurch beim
        // Anfordern -- je Entwurf einmal, ein zweiter Versuch zaehlt nicht
        // doppelt.
        $vorschlag->image_requested_at = CarbonImmutable::now();
        $vorschlag->image_error = null;
        $vorschlag->save();

        AnzeigenbildErzeugen::dispatch(
            (string) $organisation->uuid,
            (string) $vorschlag->uuid,
            (string) $benutzer->uuid,
        );

        return back()->with('erfolg', 'Die Grafik wird erzeugt — das dauert ein bis drei Minuten.');
    }

    public function bild(AdSuggestion $vorschlag, Anhangspeicher $speicher): StreamedResponse
    {
        Gate::authorize(Ability::ManageCampaigns->value);

        $anhang = $vorschlag->bild();

        abort_unless($anhang instanceof Attachment, 404);

        $inhalt = $speicher->rohinhalt($anhang);

        return response()->stream(
            function () use ($inhalt): void {
                echo $inhalt;
            },
            200,
            [
                'Content-Type' => (string) $anhang->mime,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Length' => (string) strlen($inhalt),
            ],
        );
    }
}
