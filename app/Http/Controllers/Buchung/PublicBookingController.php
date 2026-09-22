<?php

declare(strict_types=1);

namespace App\Http\Controllers\Buchung;

use App\Attribution\Beruehrungen;
use App\Attribution\Besucherkennung;
use App\Attribution\Zuordnung;
use App\Buchung\OeffentlicheVerfuegbarkeit;
use App\Datenschutz\Anhangspeicher;
use App\Enums\BookingChannel;
use App\Enums\HoldPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Buchung\BuchungRequest;
use App\Http\Requests\Buchung\ReservierungRequest;
use App\Jobs\KonversionMelden;
use App\Kontakte\Kontaktsuche;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Attachment;
use App\Models\Branding;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Practitioner;
use App\Models\SlotHold;
use App\Support\Markenstil;
use App\Support\Uuid;
use App\Tenancy\TenantContext;
use App\Termine\Terminplaner;
use App\Verfuegbarkeit\SlotHalter;
use App\Verfuegbarkeit\SlotNichtVerfuegbar;
use App\Verfuegbarkeit\Slotvorschlag;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Die oeffentliche Buchungsseite.
 *
 * Der Mandant steht bereits: ResolvePublicTenant hat ihn aus dem Slug
 * aufgeloest. Jede Abfrage hier laeuft ueber den Global Scope und sieht
 * deshalb nur diese eine Praxis.
 *
 * **Die Reservierung steht in der Sitzung, nicht im Formular.** Eine Hold-ID,
 * die der Browser zurueckschickt, ist eine fremde Reservierung, die jemand
 * uebernehmen kann.
 */
final class PublicBookingController extends Controller
{
    /** Schluessel der Reservierung in der Sitzung. */
    private const SITZUNG = 'buchung.hold';

    public function __construct(
        private readonly OeffentlicheVerfuegbarkeit $verfuegbarkeit,
        private readonly SlotHalter $halter,
        private readonly Terminplaner $planer,
        private readonly Kontaktsuche $kontakte,
        private readonly Besucherkennung $besucher,
        private readonly Beruehrungen $beruehrungen,
        private readonly Zuordnung $zuordnung,
    ) {}

    /**
     * Die Entscheidung ueber die Messung.
     *
     * **Ohne Einwilligung kein Cookie und kein Pixel** (Paragraf 25 TTDSG).
     * Eine Ablehnung wird ebenso festgehalten wie eine Zustimmung -- sonst
     * wird bei jedem Aufruf erneut gefragt, und das ist keine Entscheidung,
     * sondern Zermuerbung.
     */
    public function einwilligen(Request $request): RedirectResponse
    {
        $this->besucher->entscheide($request->boolean('ja'));

        return back();
    }

    public function show(Request $request): Response
    {
        $praxis = $this->praxis();

        // Der Touch entsteht hier und nur, wenn jemand eingewilligt hat.
        // Erst nach der Mandantenaufloesung: ein Touch ohne Mandanten waere
        // nicht zuzuordnen.
        $this->beruehrungen->erfasse($request);

        $standorte = Location::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $arten = AppointmentType::query()
            ->aktiv()
            ->where('is_public', true)
            ->with(['practitioners', 'locations', 'treatment'])
            ->orderBy('name')
            ->get()
            // Eine Terminart ohne Behandler oder ohne Standort wird nirgends
            // angeboten -- sie gehoert auch nicht in die Auswahl. Wer sie
            // anbietet, sagt die Terminart oder, wenn sie nichts sagt, die
            // Behandlung.
            ->filter(fn (AppointmentType $art): bool => $art->freigegebeneBehandler()->isNotEmpty() && $art->locations->isNotEmpty());

        return Inertia::render('buchung/Index', [
            'practice' => [
                'name' => $praxis->name,
                'slug' => $praxis->slug,
            ],

            // Die Markenfarbe der Praxis. Der Erzeuger gibt ausschliesslich
            // --primary, --primary-foreground und --ring aus; die Semantik
            // ist nicht ueberschreibbar (docs/design/farben.md).
            'brandStyle' => Markenstil::fuer($this->markenfarbe($praxis)),

            ...$this->rahmen($praxis),

            'appointmentTypes' => $arten
                ->map(fn (AppointmentType $art): array => [
                    'uuid' => $art->uuid,
                    'name' => $art->name,
                    'duration_minutes' => $art->duration_minutes,
                    // **Kein Preis, keine Beschreibung.** Beides steht im
                    // Katalog und wartet auf die HWG-Pruefung (WP-30).
                    'locations' => $art->locations->map(fn (Location $ort): string => (string) $ort->uuid)->values(),

                    // Wer sie macht. Ein Name und ein Gesicht nehmen einer
                    // Buchung mehr Unsicherheit als jeder Beschreibungstext
                    // -- und beides veroeffentlicht die Praxis ohnehin.
                    'practitioners' => $art->freigegebeneBehandler()
                        ->map(fn (Practitioner $behandler): array => [
                            'uuid' => $behandler->uuid,
                            'name' => $behandler->name(),
                            'avatar_url' => $behandler->avatarUrl(),
                            'initials' => $behandler->initialen(),
                        ])
                        ->values(),
                ])
                ->values(),

            'locations' => $standorte
                ->map(fn (Location $ort): array => [
                    'uuid' => $ort->uuid,
                    'name' => $ort->name,
                    'street' => $ort->street,
                    'postal_code' => $ort->postal_code,
                    'city' => $ort->city,
                    'timezone' => $ort->timezone,
                ])
                ->values(),

            'hold' => $this->reservierungFuerDieAnzeige(),

            // **Nur die ID, nichts sonst** (Regel 2). Was damit gesendet wird,
            // entscheidet das Layout: PageView und Lead, ohne Behandlung,
            // ohne Kontaktdaten. Ein Feld fuer einen ganzen Skriptschnipsel
            // waere die Hintertuer, die dieses Produkt gerade schliesst.
            // **Erst nach der Einwilligung.** Bis WP-32a feuerte das Pixel
            // hier ungefragt -- eine Praxis, die fuer Werbung wirbt, darf
            // nicht diejenige sein, die deswegen abgemahnt wird.
            'pixelId' => $this->besucher->eingewilligt($request)
                ? data_get($praxis->settings, 'tracking.meta_pixel_id')
                : null,

            // Null heisst: noch nicht gefragt. Dann erscheint die Abfrage.
            'messung' => $request->cookie((string) config('mrs.attribution.consent_cookie')),

            // Erst auf Anforderung berechnet -- ein Teilnachladevorgang von
            // Inertia, keine eigene API-Route (Entscheidung S2).
            'days' => Inertia::optional(fn (): array => $this->tage($request)),
        ]);
    }

    /** Haelt den gewaehlten Zeitpunkt, bevor das Formular erscheint. */
    public function reserve(ReservierungRequest $request): RedirectResponse
    {
        $daten = $request->validated();

        $this->gibFrei();

        $vorschlag = $this->vorschlagAus($daten);

        try {
            $hold = $this->halter->halte($vorschlag, HoldPurpose::PublicBooking);
        } catch (SlotNichtVerfuegbar $ausnahme) {
            throw ValidationException::withMessages(['blocked_from' => $ausnahme->getMessage()]);
        }

        $request->session()->put(self::SITZUNG, $hold->uuid);

        return back();
    }

    /**
     * Die Ereigniskennung eines Termins.
     *
     * Abgeleitet statt gespeichert: Pixel und Server kommen unabhaengig
     * voneinander auf denselben Wert, ohne dass jemand ihn durchreichen muss
     * (attribution.md, Testfall 8).
     */
    public static function ereignisId(Appointment $termin): string
    {
        return 'lead-'.$termin->uuid;
    }

    /** Gibt die Reservierung wieder frei -- "andere Zeit wählen". */
    public function release(): RedirectResponse
    {
        $this->gibFrei();

        return back();
    }

    public function store(BuchungRequest $request): RedirectResponse
    {
        $hold = $this->reservierung();

        if (! $hold instanceof SlotHold || ! $hold->giltNoch()) {
            $this->gibFrei();

            throw ValidationException::withMessages([
                'blocked_from' => 'Die Reservierung ist abgelaufen. Bitte wählen Sie die Zeit erneut.',
            ]);
        }

        $daten = $request->validated();

        $kontakt = $this->kontakte->findeOderLege([
            'first_name' => (string) $daten['first_name'],
            'last_name' => (string) $daten['last_name'],
            'email' => (string) $daten['email'],
            'phone' => is_string($daten['phone'] ?? null) ? (string) $daten['phone'] : null,
        ]);

        try {
            $termin = $this->planer->loeseEin(
                hold: $hold,
                vorschlag: $this->vorschlagAusHold($hold),
                kontakt: $kontakt,
                kanal: BookingChannel::Public,
                einwilligung: CarbonImmutable::now(),
            );
        } catch (SlotNichtVerfuegbar $ausnahme) {
            $this->gibFrei();

            throw ValidationException::withMessages(['blocked_from' => $ausnahme->getMessage()]);
        } catch (Throwable $ausnahme) {
            report($ausnahme);

            throw ValidationException::withMessages([
                'blocked_from' => 'Der Termin liess sich nicht anlegen. Bitte versuchen Sie es noch einmal.',
            ]);
        }

        // **Rueckwirkend verknuepfen.** Vorher gab es eine Zufalls-ID,
        // hinterher einen Menschen -- die Kette entsteht erst mit dem Lead.
        $besucher = $this->besucher->kennung($request);

        if ($besucher !== null) {
            $this->beruehrungen->verknuepfe($besucher, $kontakt);

            // **Einfrieren, nicht verweisen** (Entscheidung D13): Kampagnen
            // werden umbenannt, und eine Auswertung, die dem folgt, ist nach
            // einem halben Jahr wertlos.
            $stand = $this->zuordnung->standFuer($kontakt, CarbonImmutable::now());

            if ($stand !== null) {
                $termin->attribution_snapshot = (string) json_encode($stand);

                // Offen daneben: die Kennung traegt keine Aussage, und ohne
                // sie liesse sich nicht gruppieren (WP-32b).
                $termin->attribution_campaign_id = is_string($stand['kampagne'] ?? null)
                    ? $stand['kampagne']
                    : null;

                $termin->save();
            }
        }

        // **Dieselbe Kennung wie im Pixel.** Aus der Termin-UUID abgeleitet
        // statt gespeichert: beide Seiten kommen ohne zweite Quelle darauf.
        if ($besucher !== null) {
            KonversionMelden::dispatch(
                (string) $this->praxis()->uuid,
                (string) $termin->uuid,
                self::ereignisId($termin),
                $besucher,
            );
        }

        $request->session()->forget(self::SITZUNG);

        return to_route('buchung.bestaetigt', ['praxis' => $this->praxis()->slug])
            ->with('buchung', $termin->uuid);
    }

    public function confirmed(Request $request): Response
    {
        $uuid = $request->session()->get('buchung');

        $termin = is_string($uuid)
            ? Appointment::query()->whereUuid($uuid)->with(['appointmentType', 'practitioner', 'location'])->first()
            : null;

        abort_unless($termin instanceof Appointment, 404);

        $standort = $termin->location;

        return Inertia::render('buchung/Bestaetigt', [
            'practice' => ['name' => $this->praxis()->name, 'slug' => $this->praxis()->slug],
            'brandStyle' => Markenstil::fuer($this->markenfarbe($this->praxis())),

            ...$this->rahmen($this->praxis()),

            'pixelId' => $this->besucher->eingewilligt($request)
                ? data_get($this->praxis()->settings, 'tracking.meta_pixel_id')
                : null,

            'messung' => $request->cookie((string) config('mrs.attribution.consent_cookie')),

            // Dieselbe Kennung wie im Serverereignis -- sonst zaehlt Meta
            // doppelt (attribution.md, Testfall 8). Ohne Einwilligung steht
            // hier nichts, weil auch kein Pixel laedt.
            'leadEventId' => $this->besucher->eingewilligt($request) ? self::ereignisId($termin) : null,

            // Genau hier faellt der Lead an -- und nur hier. Das Ereignis
            // traegt **keine** Angaben: die Behandlung waere ein
            // Gesundheitsdatum (Regel 2).
            'trackLead' => true,
            'appointment' => [
                'type_name' => $termin->appointmentType->name,
                'practitioner_name' => $termin->practitioner->name(),
                'location_name' => $standort->name,
                'street' => $standort->street,
                'postal_code' => $standort->postal_code,
                'city' => $standort->city,
                'date' => $standort->ortszeit($termin->starts_at)->translatedFormat('l, j. F Y'),
                'starts_at' => $standort->ortszeit($termin->starts_at)->format('H:i'),
                'ends_at' => $standort->ortszeit($termin->ends_at)->format('H:i'),
            ],
        ]);
    }

    /**
     * @return list<array{date: string, weekday: string, slots: list<array<string, mixed>>}>
     */
    private function tage(Request $request): array
    {
        $art = $this->artAus($request->string('type')->toString());
        $standort = $this->standortAus($request->string('location')->toString());

        if (! $art instanceof AppointmentType || ! $standort instanceof Location) {
            return [];
        }

        return $this->verfuegbarkeit->tage($art, $standort, CarbonImmutable::now());
    }

    /**
     * @param  array<string, mixed>  $daten
     */
    private function vorschlagAus(array $daten): Slotvorschlag
    {
        $art = $this->artAus((string) $daten['appointment_type']);
        $standort = $this->standortAus((string) $daten['location']);
        $behandler = Practitioner::query()
            ->where('is_active', true)
            ->whereUuid((string) $daten['practitioner'])
            ->first();

        // 404 statt Formularfehler: eine ID, die es in dieser Praxis nicht
        // gibt, kam nicht aus unserer Oberflaeche.
        abort_unless(
            $art instanceof AppointmentType && $standort instanceof Location && $behandler instanceof Practitioner,
            404
        );

        return Slotvorschlag::ab(
            $art,
            $behandler,
            $standort,
            CarbonImmutable::parse((string) $daten['blocked_from'])->utc(),
        );
    }

    private function vorschlagAusHold(SlotHold $hold): Slotvorschlag
    {
        $art = AppointmentType::query()->whereKey($hold->appointment_type_id)->firstOrFail();
        $behandler = Practitioner::query()->whereKey($hold->practitioner_id)->firstOrFail();
        $standort = Location::query()->whereKey($hold->location_id)->firstOrFail();

        return Slotvorschlag::ab($art, $behandler, $standort, $hold->blocked_from);
    }

    private function artAus(string $uuid): ?AppointmentType
    {
        if (! Uuid::isCanonical($uuid)) {
            return null;
        }

        return AppointmentType::query()
            ->aktiv()
            ->where('is_public', true)
            ->whereUuid($uuid)
            ->first();
    }

    private function standortAus(string $uuid): ?Location
    {
        if (! Uuid::isCanonical($uuid)) {
            return null;
        }

        return Location::query()->where('is_active', true)->whereUuid($uuid)->first();
    }

    private function reservierung(): ?SlotHold
    {
        $uuid = session(self::SITZUNG);

        if (! is_string($uuid) || ! Uuid::isCanonical($uuid)) {
            return null;
        }

        return SlotHold::query()->whereUuid($uuid)->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reservierungFuerDieAnzeige(): ?array
    {
        $hold = $this->reservierung();

        if (! $hold instanceof SlotHold || ! $hold->giltNoch()) {
            return null;
        }

        $art = AppointmentType::query()->whereKey($hold->appointment_type_id)->first();
        $standort = Location::query()->whereKey($hold->location_id)->first();
        $behandler = Practitioner::query()->whereKey($hold->practitioner_id)->first();

        if (! $art instanceof AppointmentType || ! $standort instanceof Location || ! $behandler instanceof Practitioner) {
            return null;
        }

        $vorschlag = Slotvorschlag::ab($art, $behandler, $standort, $hold->blocked_from);
        $ortszeit = $standort->ortszeit($vorschlag->startsAt);

        return [
            'type_name' => $art->name,
            'practitioner_name' => $behandler->name(),
            'location_name' => $standort->name,
            'date' => $ortszeit->translatedFormat('l, j. F Y'),
            'starts_at' => $ortszeit->format('H:i'),
            'ends_at' => $standort->ortszeit($vorschlag->endsAt)->format('H:i'),
            'expires_at' => $hold->expires_at->toIso8601String(),
        ];
    }

    private function gibFrei(): void
    {
        $hold = $this->reservierung();

        if ($hold instanceof SlotHold) {
            $this->halter->gibFrei($hold);
        }

        session()->forget(self::SITZUNG);
    }

    private function praxis(): Organization
    {
        $organisation = app(TenantContext::class)->current();

        abort_unless($organisation instanceof Organization, 404);

        return $organisation;
    }

    /**
     * Das Erscheinungsbild dieser Praxis (WP-07).
     *
     * Eine Zeile je Mandant. Der Wert lag bis dahin in
     * organizations.settings -- zwei Orte fuer dieselbe Farbe waeren zwei
     * Farben, sobald jemand einen davon aendert.
     */
    private function erscheinungsbild(): ?Branding
    {
        return Branding::query()->first();
    }

    private function markenfarbe(Organization $praxis): ?string
    {
        return $this->erscheinungsbild()?->primary_color;
    }

    /**
     * Logo und Rechtslinks fuer den Rahmen der Buchungsseite.
     *
     * **Die Buchungsseite ist eine oeffentliche Website** und damit
     * impressumspflichtig. Beides sind Seiten der Praxis, unter ihrer Domain
     * -- wir zeigen sie nur.
     *
     * @return array<string, string|null>
     */
    private function rahmen(Organization $praxis): array
    {
        $bild = $this->erscheinungsbild();

        return [
            // Nur wenn die Virenpruefung es freigegeben hat (WP-33).
            'logoUrl' => $bild?->logo() instanceof Attachment
                ? route('buchung.logo', ['praxis' => $praxis->slug])
                : null,

            'imprintUrl' => $bild?->imprint_url,
            'privacyUrl' => $bild?->privacy_url,
        ];
    }

    /**
     * Liefert das Logo aus.
     *
     * Eine eigene Route, weil Anhaenge verschluesselt liegen: es gibt keinen
     * oeffentlichen Pfad zur Datei, nur diesen Weg -- und er gibt nur heraus,
     * was geprueft ist.
     */
    public function logo(Anhangspeicher $speicher): StreamedResponse
    {
        $anhang = $this->erscheinungsbild()?->logo();

        abort_unless($anhang instanceof Attachment, 404);

        // **Nur die erlaubten Bildarten verlassen das Haus.** Steht an der
        // Datei etwas anderes, geht sie gar nicht hinaus: der Ausgang
        // entscheidet, nicht die Eingabe von damals.
        $erlaubt = (array) config('mrs.whitelabel.logo_mimes');

        abort_unless(in_array($anhang->mime, $erlaubt, true), 404);

        $inhalt = $speicher->rohinhalt($anhang);

        return response()->stream(
            function () use ($inhalt): void {
                echo $inhalt;
            },
            200,
            [
                'Content-Type' => $anhang->mime,

                // Ohne das raet der Browser den Typ selbst -- und raet bei
                // einer praeparierten Datei falsch.
                'X-Content-Type-Options' => 'nosniff',
                'Content-Length' => (string) strlen($inhalt),
                'Cache-Control' => 'public, max-age=3600',
            ],
        );
    }
}
