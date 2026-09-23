<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Die Einfuehrung ist gesehen.
 *
 * **Nur ein Endpunkt, und der schreibt nur beim ersten Mal.** Wer die
 * Fuehrung spaeter erneut ansieht, hat sie nicht zum ersten Mal gesehen --
 * der Zeitpunkt bliebe sonst nicht der, den er benennt. Das erneute Starten
 * ist reiner Oberflaechenzustand und braucht den Server nicht.
 *
 * Keine Faehigkeit davor: das Merkmal haengt an der Person, nicht an einer
 * Rolle. Und keine eigene API-Schicht (Entscheidung S2) -- eine gewoehnliche
 * Inertia-Route mit Rueckleitung.
 */
class EinfuehrungController extends Controller
{
    public function gesehen(Request $request): RedirectResponse
    {
        $benutzer = $request->user();
        assert($benutzer instanceof User);

        if ($benutzer->einfuehrungStehtAus()) {
            $benutzer->einfuehrung_gesehen_at = CarbonImmutable::now();
            $benutzer->save();
        }

        return back();
    }
}
