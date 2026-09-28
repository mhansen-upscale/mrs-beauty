<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Audit\AuditLogger;
use App\Benachrichtigung\Mailvorschau;
use App\Benachrichtigung\Plattformmails;
use App\Benachrichtigung\Vorlagen\Mailtext;
use App\Benachrichtigung\Vorlagen\Standardtexte;
use App\Enums\AuditEvent;
use App\Enums\Mailart;
use App\Enums\Mailfeld;
use App\Enums\Versandweg;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\MailvorlageRequest;
use App\Models\PlatformMailSetting;
use App\Models\PlatformMailTemplate;
use App\Models\User;
use App\Notifications\Mailprobe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Die Produktmails (WP-37): welche hinausgehen, und wie die an Konten klingen.
 *
 * **Fuer alle Praxen zugleich.** Eine geaenderte Vorlage wirkt ab der
 * naechsten Mail -- gelesen wird zur Versandzeit, ohne Neustart (AK 8).
 *
 * **Links, Codes, Fristen und der Alarmsatz sind Kern** (C17): die Vorlage
 * schreibt davor und danach.
 */
final class PlattformmailController extends Controller
{
    private const ENTWURF = 'plattformentwurf';

    public function __construct(private readonly AuditLogger $protokoll) {}

    public function index(): Response
    {
        $angepasst = PlatformMailTemplate::query()->get()->keyBy(fn (PlatformMailTemplate $vorlage): string => $vorlage->template->value);
        $einstellung = PlatformMailSetting::aktuell();

        return Inertia::render('backoffice/Mails', [
            'versand' => [
                'hinterlegt' => $einstellung->hatServer(),
                'gilt' => $einstellung->serverGilt(),
                'stoerung' => $einstellung->last_error,
            ],
            'vorlagen' => array_map(fn (Mailart $art): array => [
                'art' => $art->value,
                'label' => $art->label(),
                'beschreibung' => $art->beschreibung(),
                'angepasst' => $angepasst->has($art->value),
                'geaendertAm' => $angepasst->get($art->value)?->updated_at?->toIso8601String(),
            ], Mailart::vorlagen(Versandweg::Plattform)),

            // Die ganze Antwort auf "welche Mails gehen hinaus?" -- auch die,
            // die jede Praxis selbst gestaltet.
            'weitere' => array_map(fn (Mailart $art): array => [
                'art' => $art->value,
                'label' => $art->label(),
                'beschreibung' => $art->beschreibung(),
                'weg' => $art->versandweg()->label(),
                'gestaltet' => $art->istVorlage() ? 'Praxis' : 'Produkt',
            ], array_values(array_filter(
                Mailart::cases(),
                fn (Mailart $art): bool => ! ($art->versandweg() === Versandweg::Plattform && $art->istVorlage()),
            ))),
        ]);
    }

    public function edit(Request $request, Mailart $mailart): Response
    {
        $this->nurPlattform($mailart);

        $vorlage = PlatformMailTemplate::query()->where('template', $mailart->value)->first();
        $text = $vorlage instanceof PlatformMailTemplate ? $vorlage->text() : Standardtexte::fuer($mailart);

        $entwurf = $request->session()->get(self::ENTWURF.'.'.$mailart->value);
        $gezeigt = is_array($entwurf) ? Mailtext::aus($entwurf) : $text;
        $benutzer = $request->user();

        return Inertia::render('backoffice/Mailvorlage', [
            'art' => [
                'wert' => $mailart->value,
                'label' => $mailart->label(),
                'beschreibung' => $mailart->beschreibung(),
                'festerKern' => $mailart->festerKern(),
            ],
            'felder' => $text->alsFelder(),
            'standard' => Standardtexte::fuer($mailart)->alsFelder(),
            'angepasst' => $vorlage instanceof PlatformMailTemplate,
            'platzhalter' => $this->platzhalter($mailart),
            'grenzen' => $this->grenzen(),
            'entwurf' => is_array($entwurf) ? $gezeigt->alsFelder() : null,
            'vorschau' => Mailvorschau::aus(app(Plattformmails::class)->beispiel($mailart, $gezeigt)),
            'probeAn' => $benutzer instanceof User ? $benutzer->email : null,
        ]);
    }

    public function update(MailvorlageRequest $request, Mailart $mailart): RedirectResponse
    {
        $this->nurPlattform($mailart);
        $request->validate(BackofficeController::PASSWORT);

        $benutzer = $request->user();
        $vorlage = PlatformMailTemplate::query()->firstOrNew(['template' => $mailart->value]);
        $vorlage->fill($request->text()->alsFelder());
        $vorlage->updated_by_user_id = $benutzer instanceof User ? $benutzer->getKey() : null;

        $felder = array_values(array_intersect(array_keys($vorlage->getDirty()), array_map(fn (Mailfeld $feld): string => $feld->value, Mailfeld::cases())));
        $vorlage->save();

        $request->session()->forget(self::ENTWURF.'.'.$mailart->value);

        // **Mailart und Feldnamen, nie der Text** (C5, C17).
        $this->protokoll->record(
            AuditEvent::PlatformMailTemplateChanged,
            geaenderteFelder: $felder,
            kontext: ['mailart' => $mailart->value],
            ohneOrganisation: true,
        );

        return back()->with('erfolg', 'Die Vorlage ist gespeichert. Sie gilt ab der nächsten Mail, für alle Praxen.');
    }

    public function destroy(Request $request, Mailart $mailart): RedirectResponse
    {
        $this->nurPlattform($mailart);
        $request->validate(BackofficeController::PASSWORT);

        PlatformMailTemplate::query()->where('template', $mailart->value)->delete();
        $request->session()->forget(self::ENTWURF.'.'.$mailart->value);

        $this->protokoll->record(
            AuditEvent::PlatformMailTemplateReset,
            kontext: ['mailart' => $mailart->value],
            ohneOrganisation: true,
        );

        return back()->with('erfolg', 'Es gilt wieder der Standardtext.');
    }

    public function vorschau(MailvorlageRequest $request, Mailart $mailart): RedirectResponse
    {
        $this->nurPlattform($mailart);

        $request->session()->put(self::ENTWURF.'.'.$mailart->value, $request->text()->alsFelder());

        return back();
    }

    /** Eine Probe an die angemeldete Person -- ueber denselben Weg wie die echte Mail. */
    public function probe(MailvorlageRequest $request, Mailart $mailart): RedirectResponse
    {
        $this->nurPlattform($mailart);

        $benutzer = $request->user();

        if (! $benutzer instanceof User) {
            abort(403);
        }

        Notification::route('mail', [$benutzer->email => $benutzer->name])->notify(new Mailprobe($mailart, $request->text()));

        return back()->with('erfolg', "Die Probemail ist unterwegs an {$benutzer->email}.");
    }

    private function nurPlattform(Mailart $art): void
    {
        abort_unless($art->versandweg() === Versandweg::Plattform && $art->istVorlage(), 404);
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
}
