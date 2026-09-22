<?php

declare(strict_types=1);

use App\Http\Controllers\Kanaele\MailEingangController;
use App\Http\Controllers\Kanaele\MetaWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Kanal-Infrastruktur (WP-19, WP-20)
|--------------------------------------------------------------------------
|
| **Ein Endpunkt fuer alle Meta-Kanaele** (docs/integrationen/meta.md). Die
| Zustellung kommt ohne Anmeldung an und weist sich ueber die Signatur aus;
| die Ausnahme vom CSRF-Schutz steht in bootstrap/app.php.
|
| Die Drosselung ist grosszuegig: eine Praxis mit reger Kommunikation bekommt
| viele Zustellungen, und eine abgewiesene fuehrt zu einer Wiederholung --
| nicht zu einem Verlust, aber zu Last, die niemand braucht.
|
*/

Route::get('webhooks/meta', [MetaWebhookController::class, 'verify'])->name('meta.webhook.verify');

Route::post('webhooks/meta', MetaWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('meta.webhook');

/*
| Der E-Mail-Kanal hat einen eigenen Eingang (WP-20b): eine Mail traegt keine
| Signatur, die uns etwas ueber den Weg zu uns sagen wuerde. Ausgewiesen wird
| der Eingangsdienst ueber ein gemeinsames Geheimnis im Kopf X-Mrs-Token.
*/

Route::post('webhooks/mail', MailEingangController::class)
    ->middleware('throttle:600,1')
    ->name('mail.webhook');
