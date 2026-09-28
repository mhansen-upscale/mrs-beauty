<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Audit\Supportfreigabe;
use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Die Einmal-PIN der Praxis (WP-34b, C15): erzeugen und widerrufen.
 *
 * **Die PIN erscheint genau einmal.** Sie reist mit der Weiterleitung fuer
 * genau eine Anfrage in der (verschluesselten) Sitzung -- dasselbe Muster wie
 * die Wiederherstellungscodes (ZweiFaktorController). Beim Neuladen ist sie
 * weg.
 */
final class SupportPinController extends Controller
{
    public const NEUE_PIN = 'support_pin_neu';

    public function store(Request $request, Supportfreigabe $freigabe): RedirectResponse
    {
        Gate::authorize(Ability::ApproveImpersonation->value);

        $inhaberin = $request->user();
        abort_unless($inhaberin instanceof User, 403);

        $pin = $freigabe->erzeuge($inhaberin);

        return redirect()->route('team.index')->with(self::NEUE_PIN, [
            'pin' => $pin,
            'expires_at' => now()->addMinutes((int) config('mrs.support_pin.gueltig_minuten'))->toIso8601String(),
        ]);
    }

    public function destroy(Request $request, Supportfreigabe $freigabe): RedirectResponse
    {
        Gate::authorize(Ability::ApproveImpersonation->value);

        $inhaberin = $request->user();
        abort_unless($inhaberin instanceof User, 403);

        $freigabe->widerrufe($inhaberin);

        return redirect()->route('team.index')->with('erfolg', 'Die Einmal-PIN ist widerrufen.');
    }
}
