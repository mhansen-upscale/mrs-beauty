<?php

declare(strict_types=1);

namespace App\Http\Controllers\Whitelabel;

use App\Datenschutz\Anhangspeicher;
use App\Enums\Ability;
use App\Enums\AttachmentContext;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Branding;
use App\Models\User;
use App\Whitelabel\Farbpruefung;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Das Erscheinungsbild der Buchungsseite.
 *
 * **Nicht des Admin-Bereichs** (docs/design/farben.md): der bleibt Petrol,
 * gleich was hier eingetragen wird.
 */
final class ErscheinungsbildController extends Controller
{
    public function __construct(
        private readonly Farbpruefung $farben,
        private readonly Anhangspeicher $anhaenge,
    ) {}

    public function edit(): Response
    {
        Gate::authorize(Ability::ManageWhitelabel->value);

        $bild = Branding::query()->first();

        return Inertia::render('settings/Erscheinungsbild', [
            'branding' => [
                'primaryColor' => $bild?->primary_color,
                'imprintUrl' => $bild?->imprint_url,
                'privacyUrl' => $bild?->privacy_url,
                'hatLogo' => $bild?->logo() instanceof Attachment,

                // Nur ein **Befund** haelt ein Logo zurueck, nicht die
                // fehlende Pruefung.
                'logoBeanstandet' => $bild instanceof Branding
                    && $bild->attachments()->exists()
                    && ! $bild->logo() instanceof Attachment,
                'rechtlichVollstaendig' => $bild?->rechtlichVollstaendig() ?? false,
            ],

            // Die Produktfarbe als Vergleich -- sie gilt, solange keine
            // eigene hinterlegt ist.
            'produktfarbe' => '#1F5D5B',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize(Ability::ManageWhitelabel->value);

        $daten = $request->validate([
            'primaryColor' => ['nullable', 'string', 'max:7'],

            // **Nur https.** Ein Impressum, das über http erreichbar ist,
            // ist eines, dessen Aufruf sich mitlesen laesst.
            'imprintUrl' => ['nullable', 'url:https', 'max:255'],
            'privacyUrl' => ['nullable', 'url:https', 'max:255'],
        ]);

        $befund = $this->farben->pruefe(is_string($daten['primaryColor'] ?? null) ? $daten['primaryColor'] : null);

        if (! $befund->angenommen) {
            return back()->withErrors(['primaryColor' => (string) $befund->ablehnung]);
        }

        $bild = Branding::query()->firstOrNew([]);
        $bild->primary_color = $befund->farbe === '' ? null : $befund->farbe;
        $bild->imprint_url = $daten['imprintUrl'] ?? null;
        $bild->privacy_url = $daten['privacyUrl'] ?? null;
        $bild->save();

        return back()->with('hinweise', $befund->hinweise);
    }

    public function logo(Request $request): RedirectResponse
    {
        Gate::authorize(Ability::ManageWhitelabel->value);

        $request->validate([
            'datei' => [
                'required',
                'file',
                'mimetypes:'.implode(',', (array) config('mrs.whitelabel.logo_mimes')),
                'max:'.((int) config('mrs.whitelabel.logo_max_mb') * 1024),
            ],
        ]);

        $datei = $request->file('datei');
        $benutzer = $request->user();

        if (! $datei instanceof UploadedFile || ! $benutzer instanceof User) {
            abort(422);
        }

        $bild = Branding::query()->firstOrCreate([]);

        // Ein Logo ist eine Datei von aussen, auch wenn sie von der Praxis
        // kommt: verschluesselt abgelegt und durch dieselbe Virenpruefung wie
        // jeder Anhang (WP-33).
        foreach ($bild->attachments()->get() as $alt) {
            $this->anhaenge->entferne($alt);
        }

        $this->anhaenge->lege(
            traeger: $bild,
            inhalt: (string) file_get_contents($datei->getRealPath()),
            dateiname: $datei->getClientOriginalName(),
            kontext: AttachmentContext::BrandReference,
            wer: $benutzer,
        );

        return back()->with('erfolg', 'Das Logo ist hinterlegt.');
    }

    public function logoEntfernen(): RedirectResponse
    {
        Gate::authorize(Ability::ManageWhitelabel->value);

        $bild = Branding::query()->first();

        if ($bild instanceof Branding) {
            foreach ($bild->attachments()->get() as $anhang) {
                $this->anhaenge->entferne($anhang);
            }
        }

        return back();
    }
}
