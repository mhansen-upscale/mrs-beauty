<?php

declare(strict_types=1);

use App\Enums\CalendarProvider;
use App\Http\Controllers\Kalender\GoogleWebhookController;
use App\Http\Controllers\Kalender\MicrosoftWebhookController;
use App\Http\Controllers\Kalender\VerbindungController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Kalendersync (WP-14, WP-15)
|--------------------------------------------------------------------------
|
| Die Zustellungen stehen ausserhalb der Anmeldung: sie kommen vom Anbieter
| und weisen sich ueber das Geheimnis aus, das wir selbst vergeben haben
| (Entscheidung A14). Die Ausnahmen vom CSRF-Schutz stehen in
| bootstrap/app.php -- an einer Stelle, nicht an dreien.
|
| **Je Anbieter ein eigener Empfang.** Die Nutzlasten haben nichts gemein: bei
| Google steht alles in Koepfen, bei Graph im Rumpf, und Graph verlangt
| vorweg einen Handschlag. Das zusammenzulegen hiesse, zwei Formate in einer
| Methode zu unterscheiden -- genau die Abstraktion, vor der
| docs/integrationen/kalender.md warnt.
|
| Drosselung, weil ein offener Endpunkt sonst ein Weg ist, Abgleiche
| auszuloesen.
|
*/

Route::post('kalender/google/zustellung', GoogleWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('kalender.google.webhook');

Route::post('kalender/microsoft/zustellung', MicrosoftWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('kalender.microsoft.webhook');

Route::middleware(['auth', 'verified', 'can:masterdata.manage'])->group(function () {
    Route::get('kalender', [VerbindungController::class, 'index'])->name('kalender.index');

    Route::patch('kalender/{verbindung}', [VerbindungController::class, 'aktualisieren'])
        ->name('kalender.aktualisieren');

    Route::post('kalender/{verbindung}/abgleichen', [VerbindungController::class, 'abgleichen'])
        ->name('kalender.abgleichen');

    Route::delete('kalender/{verbindung}', [VerbindungController::class, 'trennen'])
        ->name('kalender.trennen');

    // Hin- und Rueckweg je Anbieter, aus einer Vorlage. Der Anbieter steht in
    // der Route und nicht in der Anfrage: er gehoert zur Adresse, die bei
    // Google und Microsoft hinterlegt ist, und laesst sich damit nicht von
    // aussen umbiegen.
    foreach (CalendarProvider::cases() as $anbieter) {
        Route::get("kalender/{$anbieter->value}/{practitioner}/verbinden", [VerbindungController::class, 'verbinden'])
            ->defaults('anbieter', $anbieter->value)
            ->name("kalender.{$anbieter->value}.verbinden");

        // Der Pfad steht seit WP-02 in .env als GOOGLE_REDIRECT_URI bzw.
        // MICROSOFT_REDIRECT_URI. Er ist beim Anbieter hinterlegt, und ihn
        // spaeter zu aendern heisst, jede bestehende Verbindung anzufassen --
        // deshalb der vorgegebene und nicht der, der zum Rest dieser Datei
        // passen wuerde.
        Route::get("oauth/{$anbieter->value}/callback", [VerbindungController::class, 'rueckkehr'])
            ->defaults('anbieter', $anbieter->value)
            ->name("kalender.{$anbieter->value}.rueckkehr");
    }
});
