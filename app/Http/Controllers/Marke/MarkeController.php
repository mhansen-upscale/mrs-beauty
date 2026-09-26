<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marke;

use App\Enums\Ability;
use App\Enums\BrandAddress;
use App\Enums\BrandReferenceKind;
use App\Enums\BrandTermKind;
use App\Enums\BrandTone;
use App\Http\Controllers\Controller;
use App\Marke\Markenprofil;
use App\Marke\Referenzablage;
use App\Models\Attachment;
use App\Models\BrandGuide;
use App\Models\BrandReference;
use App\Models\BrandTerm;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Der Brand Guide: wie diese Praxis klingt und womit sie wirbt.
 *
 * Farben und Logo stehen hier **nicht** -- die gehoeren zum Whitelabel
 * (WP-07). Das Praxiswissen des Assistenten ebenfalls nicht: eine Aenderung
 * am Werbeton soll nicht die Antwort auf eine Terminfrage veraendern.
 */
final class MarkeController extends Controller
{
    public function __construct(
        private readonly Markenprofil $profil,
        private readonly Referenzablage $ablage,
    ) {}

    public function index(): Response
    {
        Gate::authorize(Ability::ManageBrandGuide->value);

        $guide = $this->profil->guide();

        return Inertia::render('marke/Index', [
            // Kein Brand Guide ist kein Fehler, sondern der erste Tag.
            'guide' => $guide === null ? null : [
                'tone' => $guide->tone?->value,
                'addressForm' => $guide->address_form?->value,
                'audience' => $guide->audience,
                'positioning' => $guide->positioning,
                'claim' => $guide->claim,
                'noGoTopics' => $guide->no_go_topics,
            ],

            'begriffe' => BrandTerm::query()
                ->orderBy('kind')
                ->orderBy('term')
                ->get()
                ->map(fn (BrandTerm $begriff): array => [
                    'uuid' => $begriff->uuid,
                    'art' => $begriff->kind->value,
                    'artText' => $begriff->kind->label(),
                    'begriff' => $begriff->term,
                    'ersatz' => $begriff->replacement,
                    'begruendung' => $begriff->reason,
                ])
                ->values(),

            'referenzen' => BrandReference::query()
                ->with('attachments')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (BrandReference $referenz): array => [
                    'uuid' => $referenz->uuid,
                    'art' => $referenz->kind->value,
                    'artText' => $referenz->kind->label(),
                    'titel' => $referenz->title,
                    'notiz' => $referenz->note,
                    'erklaert' => $referenz->declared_at->toIso8601String(),
                    'erklaerung' => $referenz->declaration_text,
                    // **Nicht nur `every`.** Eine Referenz ohne Datei -- ein
                    // Hochladen, das nach dem Speichern abgebrochen ist --
                    // haette sonst als geprueft gegolten: `every` auf einer
                    // leeren Menge ist wahr.
                    'freigegeben' => $referenz->attachments->isNotEmpty()
                        && $referenz->attachments->every(fn (Attachment $anhang): bool => $anhang->istFreigegeben()),

                    // Die Vorschau (offen seit WP-29): ueber dieselbe Route
                    // wie jeder andere Anhang -- und wie dort nur, was
                    // geprueft ist.
                    'vorschau' => $referenz->attachments
                        ->first(fn (Attachment $anhang): bool => $anhang->istBild() && $anhang->istFreigegeben())
                        ?->uuid,
                ])
                ->values(),

            'reifegrad' => $this->profil->reifegrad(),
            'erklaerung' => (string) config('mrs.brand.declaration'),

            'toene' => collect(BrandTone::cases())
                ->map(fn (BrandTone $ton): array => [
                    'value' => $ton->value,
                    'label' => $ton->label(),
                    'beschreibung' => $ton->beschreibung(),
                ])
                ->values(),

            'ansprachen' => collect(BrandAddress::cases())
                ->map(fn (BrandAddress $form): array => ['value' => $form->value, 'label' => $form->label()])
                ->values(),

            'referenzarten' => collect(BrandReferenceKind::cases())
                ->map(fn (BrandReferenceKind $art): array => [
                    'value' => $art->value,
                    'label' => $art->label(),
                    'hinweis' => $art->hinweis(),
                ])
                ->values(),
        ]);
    }

    public function speichern(Request $request): RedirectResponse
    {
        Gate::authorize(Ability::ManageBrandGuide->value);

        // Leere Auswahlfelder kommen als leerer String aus dem Formular --
        // 'nullable' allein liesse ihn durch und die Enum-Regel scheitern
        // daran.
        $request->merge([
            'tone' => $request->input('tone') === '' ? null : $request->input('tone'),
            'addressForm' => $request->input('addressForm') === '' ? null : $request->input('addressForm'),
        ]);

        $daten = $request->validate([
            'tone' => ['nullable', Rule::enum(BrandTone::class)],
            'addressForm' => ['nullable', Rule::enum(BrandAddress::class)],
            'audience' => ['nullable', 'string', 'max:2000'],
            'positioning' => ['nullable', 'string', 'max:2000'],
            'claim' => ['nullable', 'string', 'max:191'],
            'noGoTopics' => ['nullable', 'string', 'max:2000'],
        ]);

        // firstOrNew statt create: einer je Mandant, und der Unique-Index
        // besteht ebenfalls darauf.
        $guide = BrandGuide::query()->firstOrNew([]);

        $guide->tone = $daten['tone'] === null ? null : BrandTone::from((string) $daten['tone']);
        $guide->address_form = $daten['addressForm'] === null ? null : BrandAddress::from((string) $daten['addressForm']);
        $guide->audience = $daten['audience'] ?? null;
        $guide->positioning = $daten['positioning'] ?? null;
        $guide->claim = $daten['claim'] ?? null;
        $guide->no_go_topics = $daten['noGoTopics'] ?? null;
        $guide->save();

        return back()->with('erfolg', 'Der Brand Guide ist gespeichert.');
    }

    public function begriffAnlegen(Request $request): RedirectResponse
    {
        Gate::authorize(Ability::ManageBrandGuide->value);

        $daten = $request->validate([
            'art' => ['required', Rule::enum(BrandTermKind::class)],
            'begriff' => ['required', 'string', 'max:120'],
            'ersatz' => ['nullable', 'string', 'max:120'],
            'begruendung' => ['nullable', 'string', 'max:191'],
        ]);

        $begriff = BrandTerm::query()->firstOrNew([
            'kind' => (string) $daten['art'],
            'term' => trim((string) $daten['begriff']),
        ]);

        $begriff->replacement = $daten['ersatz'] ?? null;
        $begriff->reason = $daten['begruendung'] ?? null;
        $begriff->save();

        return back();
    }

    public function begriffEntfernen(BrandTerm $begriff): RedirectResponse
    {
        Gate::authorize(Ability::ManageBrandGuide->value);

        $begriff->delete();

        return back();
    }

    /**
     * Referenzmaterial hochladen.
     *
     * **Ohne Erklaerung geht nichts.** Sie ist kein Formalismus: eine Praxis,
     * die gefragt wird "womit wollen Sie werben", laedt Vorher-Nachher-Bilder
     * hoch -- und das ist zweimal falsch (Artikel 9 DSGVO und BGH
     * I ZR 170/24). Pruefen kann das hier nichts; die Bildpruefung kommt mit
     * WP-30. Festgehalten wird, was zugesichert wurde.
     */
    public function referenzAnlegen(Request $request): RedirectResponse
    {
        Gate::authorize(Ability::ManageBrandGuide->value);

        $daten = $request->validate([
            'art' => ['required', Rule::enum(BrandReferenceKind::class)],
            'titel' => ['required', 'string', 'max:191'],
            'notiz' => ['nullable', 'string', 'max:500'],
            'erklaert' => ['required', 'accepted'],
            'datei' => [
                'required',
                'file',
                'mimetypes:'.implode(',', (array) config('mrs.brand.reference_mimes')),
                'max:'.((int) config('mrs.brand.reference_max_mb') * 1024),
            ],
        ], [
            'erklaert.accepted' => 'Ohne diese Bestätigung können wir das Material nicht aufnehmen.',
        ]);

        $datei = $request->file('datei');
        $benutzer = $request->user();

        if (! $datei instanceof UploadedFile || ! $benutzer instanceof User) {
            abort(422);
        }

        $this->ablage->lege(
            art: BrandReferenceKind::from((string) $daten['art']),
            titel: (string) $daten['titel'],
            inhalt: (string) file_get_contents($datei->getRealPath()),
            dateiname: $datei->getClientOriginalName(),
            wer: $benutzer,
            notiz: $daten['notiz'] ?? null,
        );

        return back()->with('erfolg', 'Das Material ist aufgenommen.');
    }

    public function referenzEntfernen(BrandReference $referenz): RedirectResponse
    {
        Gate::authorize(Ability::ManageBrandGuide->value);

        $this->ablage->entferne($referenz);

        return back();
    }
}
