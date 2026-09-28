<?php

declare(strict_types=1);

use App\Enums\Mailart;
use App\Enums\Versandweg;
use App\Http\Controllers\Audit\ImpersonationController;
use App\Http\Controllers\Settings\AgentController;
use App\Http\Controllers\Settings\EinfuehrungController;
use App\Http\Controllers\Settings\MailvorlagenController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\PostfachController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TrackingController;
use App\Http\Controllers\Settings\WhatsAppController;
use App\Http\Controllers\Settings\ZweiFaktorController;
use App\Http\Controllers\Whitelabel\ErscheinungsbildController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Einstellungen
|--------------------------------------------------------------------------
|
| Hier steht, was die **eigene Person** betrifft -- und die wenigen
| technischen Einstellungen der Praxis, die kein Arbeitsbereich sind: die
| Pixel-ID und das Postfach. Stammdaten, Katalog, Team und Protokoll sind
| Arbeit und stehen in der Hauptnavigation -- siehe routes/stammdaten.php und
| routes/organisation.php.
|
*/

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Die Einfuehrung gehoert zur Person, nicht zur Praxis -- deshalb hier
    // und ohne can:. Sie schreibt nur beim ersten Mal (WP ohne Nummer:
    // "Onboarding einer neuen Praxis" steht in docs/produkt.md offen).
    Route::post('settings/einfuehrung', [EinfuehrungController::class, 'gesehen'])->name('einfuehrung.gesehen');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    // Der zweite Faktor (WP-35, C16) -- fuer Praxis und Betreiber, ohne can:.
    // Er haengt an der Person, nicht an einer Rolle. Bestaetigen ist
    // gedrosselt: sechs Stellen sind ratbar, wenn man darf.
    Route::get('settings/zwei-faktor', [ZweiFaktorController::class, 'edit'])->name('zwei-faktor.edit');
    Route::delete('settings/zwei-faktor', [ZweiFaktorController::class, 'destroy'])->name('zwei-faktor.destroy');
    Route::post('settings/zwei-faktor/app', [ZweiFaktorController::class, 'starteApp'])->name('zwei-faktor.app');
    Route::post('settings/zwei-faktor/app/bestaetigen', [ZweiFaktorController::class, 'bestaetigeApp'])
        ->middleware('throttle:10,1')
        ->name('zwei-faktor.app.bestaetigen');
    Route::post('settings/zwei-faktor/email', [ZweiFaktorController::class, 'starteEmail'])->name('zwei-faktor.email');
    Route::post('settings/zwei-faktor/email/erneut', [ZweiFaktorController::class, 'erneutEmail'])->name('zwei-faktor.email.erneut');
    Route::post('settings/zwei-faktor/email/bestaetigen', [ZweiFaktorController::class, 'bestaetigeEmail'])
        ->middleware('throttle:10,1')
        ->name('zwei-faktor.email.bestaetigen');
    Route::delete('settings/zwei-faktor/einrichtung', [ZweiFaktorController::class, 'abbrechen'])->name('zwei-faktor.einrichtung.abbrechen');
    Route::post('settings/zwei-faktor/codes', [ZweiFaktorController::class, 'codesNeu'])->name('zwei-faktor.codes');
    Route::post('settings/zwei-faktor/hinweis', [ZweiFaktorController::class, 'hinweisAusblenden'])->name('zwei-faktor.hinweis');

    // Die Pixel-ID betrifft nicht die eigene Person, sondern die Praxis --
    // sie steht hier, weil sie eine Einstellung ist und kein Arbeitsbereich.
    Route::middleware('can:organization.manage')->group(function () {
        Route::get('settings/tracking', [TrackingController::class, 'edit'])->name('tracking.edit');
        Route::put('settings/tracking', [TrackingController::class, 'update'])->name('tracking.update');

        // Das Postfach der Praxis (WP-20b): Eingangsadresse, Absender und
        // -- optional -- die Zugangsdaten zum eigenen Mailserver.
        Route::get('settings/postfach', [PostfachController::class, 'edit'])->name('postfach.edit');
        Route::put('settings/postfach', [PostfachController::class, 'update'])->name('postfach.update');
        Route::post('settings/postfach/probe', [PostfachController::class, 'pruefen'])->name('postfach.pruefen');

        // WhatsApp (offen seit WP-20b): eintragen, in der Warteschlange pruefen.
        Route::get('settings/whatsapp', [WhatsAppController::class, 'edit'])->name('whatsapp.edit');
        Route::put('settings/whatsapp', [WhatsAppController::class, 'update'])->name('whatsapp.update');
        Route::post('settings/whatsapp/pruefen', [WhatsAppController::class, 'pruefen'])->name('whatsapp.pruefen');
    });

    // Das Erscheinungsbild der Buchungsseite (WP-07). Eigene Faehigkeit:
    // wer die Pixel-ID pflegt, entscheidet nicht ueber die Marke.
    Route::middleware('can:whitelabel.manage')->group(function () {
        Route::get('settings/erscheinungsbild', [ErscheinungsbildController::class, 'edit'])->name('erscheinungsbild.edit');
        Route::put('settings/erscheinungsbild', [ErscheinungsbildController::class, 'update'])->name('erscheinungsbild.update');
        Route::post('settings/erscheinungsbild/logo', [ErscheinungsbildController::class, 'logo'])->name('erscheinungsbild.logo');
        Route::delete('settings/erscheinungsbild/logo', [ErscheinungsbildController::class, 'logoEntfernen'])->name('erscheinungsbild.logo.entfernen');

        // Die Mails der Praxis (WP-36, P12): welche hinausgehen, und die
        // fuenf Terminmails als Vorlagen. **Nur die Mailarten der Praxis** --
        // die Mails an Konten gestaltet der Betreiber (WP-37).
        $terminmails = array_map(fn (Mailart $art): string => $art->value, Mailart::vorlagen(Versandweg::Praxis));

        Route::get('settings/mails', [MailvorlagenController::class, 'index'])->name('mailvorlagen.index');
        Route::put('settings/mails/signatur', [MailvorlagenController::class, 'signatur'])->name('mailvorlagen.signatur');
        Route::get('settings/mails/{mailart}', [MailvorlagenController::class, 'edit'])
            ->whereIn('mailart', $terminmails)->name('mailvorlagen.edit');
        Route::put('settings/mails/{mailart}', [MailvorlagenController::class, 'update'])
            ->whereIn('mailart', $terminmails)->name('mailvorlagen.update');
        Route::delete('settings/mails/{mailart}', [MailvorlagenController::class, 'destroy'])
            ->whereIn('mailart', $terminmails)->name('mailvorlagen.destroy');
        Route::post('settings/mails/{mailart}/vorschau', [MailvorlagenController::class, 'vorschau'])
            ->whereIn('mailart', $terminmails)->name('mailvorlagen.vorschau');
        Route::post('settings/mails/{mailart}/probe', [MailvorlagenController::class, 'probe'])
            ->whereIn('mailart', $terminmails)->middleware('throttle:6,1')->name('mailvorlagen.probe');
    });

    // Der Assistent (WP-23): Not-Aus und Konfidenzschwelle. Eigene
    // Faehigkeit -- wer den Posteingang bedient, entscheidet nicht darueber,
    // ob ein Assistent mitschreibt.
    Route::middleware('can:agent.manage')->group(function () {
        Route::get('settings/assistent', [AgentController::class, 'edit'])->name('agent.edit');
        Route::put('settings/assistent', [AgentController::class, 'update'])->name('agent.update');
    });

    // Impersonation (WP-05). Die Mandantenauswahl gehoert zu WP-34. Nur mit
    // Support-Zugriff -- Finanzen kommt nie in eine Praxis (WP-34a).
    Route::middleware('betreiber:support.zugriff')->group(function () {
        Route::post('impersonation', [ImpersonationController::class, 'store'])->name('impersonation.store');
        Route::delete('impersonation', [ImpersonationController::class, 'destroy'])->name('impersonation.destroy');
    });
    Route::post('impersonation/{session}/freigeben', [ImpersonationController::class, 'approve'])
        ->middleware('can:impersonation.approve')
        ->name('impersonation.approve');
});
