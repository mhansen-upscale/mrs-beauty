<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Audit\ImpersonationContext;
use App\Benachrichtigung\Mailmarke;
use App\Benachrichtigung\Mailvorschau;
use App\Benachrichtigung\Termindaten;
use App\Benachrichtigung\Vorlagen\Mailtext;
use App\Benachrichtigung\Vorlagen\Standardtexte;
use App\Compliance\Veroeffentlichungspruefung;
use App\Enums\Ability;
use App\Enums\Mailart;
use App\Enums\Mailfeld;
use App\Enums\Versandweg;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\MailvorlageRequest;
use App\Kanaele\Email\Postfach;
use App\Models\Attachment;
use App\Models\Branding;
use App\Models\MailTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Terminnachricht;
use App\Support\Markenstil;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Die Mails der Praxis (WP-36, P12): welche hinausgehen, und wie die fuenf
 * Terminmails klingen und aussehen.
 *
 * **Die Praxis schreibt um den Termin herum, nicht den Termin.** Eckdaten und
 * Kalenderdatei setzt das Produkt; die Vorlage schreibt davor und danach.
 *
 * **Die Vorschau verschickt nichts** (AK 17). Sie merkt sich den Entwurf in
 * der Sitzung -- nicht das HTML, das waere fuer eine Sitzung zu gross -- und
 * die Bearbeitungsseite rendert ihn.
 */
final class MailvorlagenController extends Controller
{
    private const ENTWURF = 'mailentwurf';

    public function __construct(private readonly Postfach $postfach) {}

    public function index(): Response
    {
        Gate::authorize(Ability::ManageWhitelabel->value);

        $bild = Branding::query()->first();
        $angepasst = MailTemplate::query()->get()->keyBy(fn (MailTemplate $vorlage): string => $vorlage->template->value);

        return Inertia::render('settings/Mails', [
            'postfach' => $this->postfachstand(),
            'marke' => [
                'farbe' => Markenstil::hexFuer($bild?->primary_color) ?? (string) config('mrs.mail.produktfarbe'),
                'eigeneFarbe' => is_string($bild?->primary_color) && $bild->primary_color !== '',
                'hatLogo' => $bild?->logo() instanceof Attachment,
            ],
            'signatur' => (string) $bild?->mail_signature,
            'signaturLaenge' => (int) config('mrs.mail.laenge.signatur'),
            'vorlagen' => array_map(fn (Mailart $art): array => [
                'art' => $art->value,
                'label' => $art->label(),
                'beschreibung' => $art->beschreibung(),
                'angepasst' => $angepasst->has($art->value),
                'geaendertAm' => $angepasst->get($art->value)?->updated_at?->toIso8601String(),
            ], Mailart::vorlagen(Versandweg::Praxis)),

            // **Welche Mails hinausgehen** -- auch die, die hier niemand
            // gestaltet. Die Frage "was bekommt eine Patientin, was mein
            // Team?" beantwortet diese Liste ganz.
            'weitere' => array_map(fn (Mailart $art): array => [
                'art' => $art->value,
                'label' => $art->label(),
                'beschreibung' => $art->beschreibung(),
                'weg' => $art->versandweg()->label(),
                'gestaltet' => $art->versandweg() === Versandweg::Plattform ? 'Betreiber' : 'Produkt',
            ], array_values(array_filter(
                Mailart::cases(),
                fn (Mailart $art): bool => ! ($art->versandweg() === Versandweg::Praxis && $art->istVorlage())
                    && ! $art->anDenBetreiber(),
            ))),
        ]);
    }

    public function signatur(Request $request): RedirectResponse
    {
        Gate::authorize(Ability::ManageWhitelabel->value);

        $daten = $request->validate([
            'signatur' => ['nullable', 'string', 'max:'.(int) config('mrs.mail.laenge.signatur')],
        ]);

        $text = trim((string) ($daten['signatur'] ?? ''));

        if (preg_match('/<\s*[a-z!\/?]/iu', $text) === 1) {
            return back()->withErrors(['signatur' => 'HTML ist nicht erlaubt.']);
        }

        $bild = Branding::query()->firstOrNew([]);
        $bild->mail_signature = $text === '' ? null : $text;
        $bild->save();

        return back()->with('erfolg', 'Die Signatur ist gespeichert.');
    }

    public function edit(Request $request, Mailart $mailart, TenantContext $mandant): Response
    {
        Gate::authorize(Ability::ManageWhitelabel->value);
        $this->nurPraxis($mailart);

        $vorlage = MailTemplate::query()->where('template', $mailart->value)->with('pruefung')->first();
        $text = $vorlage instanceof MailTemplate ? $vorlage->text() : Standardtexte::fuer($mailart);

        $entwurf = $request->session()->get(self::ENTWURF.'.'.$mailart->value);
        $gezeigt = is_array($entwurf) ? Mailtext::aus($entwurf) : $text;

        $pruefung = $vorlage?->pruefung;
        $benutzer = $request->user();

        return Inertia::render('settings/Mailvorlage', [
            'art' => [
                'wert' => $mailart->value,
                'label' => $mailart->label(),
                'beschreibung' => $mailart->beschreibung(),
                'festerKern' => $mailart->festerKern(),
            ],
            'felder' => $text->alsFelder(),
            'standard' => Standardtexte::fuer($mailart)->alsFelder(),
            'angepasst' => $vorlage instanceof MailTemplate,
            'platzhalter' => $this->platzhalter($mailart),
            'grenzen' => $this->grenzen(),
            'entwurf' => is_array($entwurf) ? $gezeigt->alsFelder() : null,
            'vorschau' => $this->vorschauFuer($mailart, $gezeigt, $this->praxis($mandant)),
            'hwg' => $pruefung === null ? null : [
                'ampel' => $pruefung->result->value,
                'befunde' => $pruefung->befunde(),
            ],
            'postfach' => $this->postfachstand(),
            'probeAn' => $benutzer instanceof User ? $benutzer->email : null,
        ]);
    }

    public function update(MailvorlageRequest $request, Mailart $mailart, Veroeffentlichungspruefung $hwg): RedirectResponse
    {
        Gate::authorize(Ability::ManageWhitelabel->value);
        $this->nurPraxis($mailart);

        $vorlage = MailTemplate::query()->firstOrNew(['template' => $mailart->value]);
        $vorlage->fill($this->spalten($request->text()));
        $vorlage->save();

        $request->session()->forget(self::ENTWURF.'.'.$mailart->value);

        // **Ein Hinweis, keine Sperre** (P12): gespeichert ist die Vorlage
        // schon, der Befund steht danach an ihr.
        $hwg->mailvorlage($vorlage);

        return back()->with('erfolg', 'Die Vorlage ist gespeichert. Sie gilt ab der nächsten Mail.');
    }

    public function destroy(Request $request, Mailart $mailart): RedirectResponse
    {
        Gate::authorize(Ability::ManageWhitelabel->value);
        $this->nurPraxis($mailart);

        $vorlage = MailTemplate::query()->where('template', $mailart->value)->first();

        if ($vorlage instanceof MailTemplate) {
            $vorlage->pruefung()->delete();
            $vorlage->delete();
        }

        $request->session()->forget(self::ENTWURF.'.'.$mailart->value);

        return back()->with('erfolg', 'Es gilt wieder der Standardtext.');
    }

    /** Merkt den Entwurf -- die Bearbeitungsseite zeigt ihn als Vorschau. */
    public function vorschau(MailvorlageRequest $request, Mailart $mailart): RedirectResponse
    {
        Gate::authorize(Ability::ManageWhitelabel->value);
        $this->nurPraxis($mailart);

        $request->session()->put(self::ENTWURF.'.'.$mailart->value, $request->text()->alsFelder());

        return back();
    }

    /**
     * Eine Probemail an die angemeldete Person -- **ueber das Postfach der
     * Praxis**, mit Beispielwerten, ueber die Warteschlange (AK 20).
     */
    public function probe(MailvorlageRequest $request, Mailart $mailart, TenantContext $mandant, ImpersonationContext $impersonation): RedirectResponse
    {
        Gate::authorize(Ability::ManageWhitelabel->value);
        $this->nurPraxis($mailart);

        // Wer in eine Praxis hineinsieht, verschickt nicht in ihrem Namen.
        abort_if($impersonation->isActive(), 403);

        $benutzer = $request->user();
        $praxis = $this->praxis($mandant);
        $kind = $mailart->termin();

        if (! $benutzer instanceof User || $kind === null) {
            abort(403);
        }

        if (! $this->postfach->versandbereit()) {
            return back()->withErrors(['probe' => 'Ohne sendebereites Postfach geht keine Mail hinaus. Bitte zuerst das Postfach einrichten.']);
        }

        Notification::route('mail', [$benutzer->email => $benutzer->name])->notify(new Terminnachricht(
            Termindaten::beispiel($praxis),
            $kind,
            $praxis->name,
            Mailmarke::fuer($praxis),
            text: $request->text(),
            vorsatz: 'Probe: ',
        ));

        return back()->with('erfolg', "Die Probemail ist unterwegs an {$benutzer->email}.");
    }

    /**
     * @return array{betreff: string, html: string, text: string}
     */
    private function vorschauFuer(Mailart $art, Mailtext $text, Organization $praxis): array
    {
        $kind = $art->termin();
        abort_if($kind === null, 404);

        $nachricht = (new Terminnachricht(
            Termindaten::beispiel($praxis),
            $kind,
            $praxis->name,
            Mailmarke::fuer($praxis),
            text: $text,
        ))->toMail(new AnonymousNotifiable);

        return Mailvorschau::aus($nachricht);
    }

    /**
     * @return array{eingerichtet: bool, eigenerServer: bool, bereit: bool, absender: string|null, darfEinrichten: bool}
     */
    private function postfachstand(): array
    {
        $verbindung = $this->postfach->verbindung();

        return [
            'eingerichtet' => $verbindung !== null,
            'eigenerServer' => $verbindung?->hatEigenesPostfach() ?? false,
            'bereit' => $verbindung?->kannVersenden() ?? false,
            'absender' => $verbindung?->sender_id,
            'darfEinrichten' => Gate::allows(Ability::ManageOrganization->value),
        ];
    }

    /**
     * @return array<string, list<array{name: string, label: string}>>
     */
    private function platzhalter(Mailart $art): array
    {
        $liste = [];

        foreach (Mailfeld::cases() as $feld) {
            $liste[$feld->value] = array_map(fn ($platzhalter): array => [
                'name' => $platzhalter->value,
                'label' => $platzhalter->label(),
            ], $art->platzhalter($feld));
        }

        return $liste;
    }

    /**
     * @return array<string, int>
     */
    private function grenzen(): array
    {
        $grenzen = [];

        foreach (Mailfeld::cases() as $feld) {
            $grenzen[$feld->value] = $feld->hoechstlaenge();
        }

        return $grenzen;
    }

    /**
     * @return array<string, string>
     */
    private function spalten(Mailtext $text): array
    {
        return $text->alsFelder();
    }

    private function nurPraxis(Mailart $art): void
    {
        // Die Mails an Konten gestaltet der Betreiber (WP-37) -- unter der
        // Praxis gibt es sie nicht (AK 3).
        abort_unless($art->versandweg() === Versandweg::Praxis && $art->istVorlage(), 404);
    }

    private function praxis(TenantContext $mandant): Organization
    {
        $praxis = $mandant->current();
        abort_unless($praxis instanceof Organization, 404);

        return $praxis;
    }
}
