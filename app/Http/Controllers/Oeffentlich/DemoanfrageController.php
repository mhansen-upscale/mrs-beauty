<?php

declare(strict_types=1);

namespace App\Http\Controllers\Oeffentlich;

use App\Http\Controllers\Controller;
use App\Http\Requests\Oeffentlich\DemoanfrageRequest;
use App\Models\DemoRequest;
use App\Notifications\Demoanfrage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Notification;

/**
 * Nimmt eine Demo-Anfrage an (WP-38).
 *
 * **Erst gespeichert, dann benachrichtigt.** Die Anfrage ist der Datensatz,
 * die Mail nur der Hinweis darauf: scheitert der Versand, steht sie trotzdem
 * im Backoffice, und der gescheiterte Auftrag in der Betriebslage.
 */
final class DemoanfrageController extends Controller
{
    public function store(DemoanfrageRequest $request): RedirectResponse
    {
        // **Feld fuer Feld**, nicht `create($request->validated())`: Honigtopf
        // und Merkmal sind keine Spalten, und `shouldBeStrict` wirft auf
        // jedes unbekannte Attribut.
        $anfrage = new DemoRequest;
        $anfrage->name = $request->string('name')->trim()->toString();
        $anfrage->practice_name = $request->string('practice_name')->trim()->toString();
        $anfrage->email = $request->string('email')->trim()->toString();
        $anfrage->phone = $this->freiwillig($request->string('phone')->trim()->toString());
        $anfrage->city = $this->freiwillig($request->string('city')->trim()->toString());
        $anfrage->save();

        Notification::route('mail', (string) config('mrs.oeffentlich.demoanfragen.empfaenger'))
            ->notify(new Demoanfrage($anfrage));

        return back()->with('erfolg', 'Danke! Ihre Anfrage ist bei uns angekommen. Wir melden uns bei Ihnen, um einen Termin für die Demo zu vereinbaren.');
    }

    private function freiwillig(string $wert): ?string
    {
        return $wert === '' ? null : $wert;
    }
}
