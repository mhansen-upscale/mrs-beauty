<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Die Meta-Pixel-ID der Praxis.
 *
 * **Regel 2 gilt hier besonders.** Ein Pixel auf einer Buchungsseite fuer
 * aesthetische Eingriffe ist genau die Stelle, an der Gesundheitsdaten zu
 * Meta abfliessen koennten: die gewaehlte Behandlung steht auf der Seite, und
 * ein unbedacht eingebautes Pixel schickt Seitentitel, Adresse und
 * Formularinhalte mit.
 *
 * Deshalb baut dieses Produkt das Pixel selbst ein und nicht der Kunde per
 * Skriptschnipsel: nur so laesst sich zusichern, dass ausschliesslich
 * `PageView` und `Lead` gesendet werden -- ohne Behandlungsname, ohne
 * Kontaktdaten, ohne Parameter.
 */
final class TrackingController extends Controller
{
    public function edit(TenantContext $mandant): Response
    {
        Gate::authorize(Ability::ManageOrganization->value);

        $organisation = $mandant->current();

        return Inertia::render('settings/Tracking', [
            'meta_pixel_id' => $organisation instanceof Organization
                ? data_get($organisation->settings, 'tracking.meta_pixel_id')
                : null,
        ]);
    }

    public function update(Request $request, TenantContext $mandant): RedirectResponse
    {
        Gate::authorize(Ability::ManageOrganization->value);

        $daten = $request->validate([
            // Meta vergibt eine reine Ziffernfolge. Ein Feld, in das sich ein
            // ganzer Skriptschnipsel einfuegen laesst, waere die Hintertuer,
            // die dieses Produkt gerade schliessen will.
            'meta_pixel_id' => ['nullable', 'string', 'regex:/^[0-9]{6,20}$/'],
        ], [
            'meta_pixel_id.regex' => 'Die Pixel-ID besteht nur aus Ziffern — bitte nur die ID einfügen, nicht den ganzen Code.',
        ]);

        $organisation = $mandant->current();

        if ($organisation instanceof Organization) {
            $einstellungen = $organisation->settings ?? [];
            data_set($einstellungen, 'tracking.meta_pixel_id', $daten['meta_pixel_id'] ?: null);

            $organisation->settings = $einstellungen;
            $organisation->save();
        }

        return back();
    }
}
