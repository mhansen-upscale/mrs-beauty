<?php

declare(strict_types=1);

namespace App\Providers;

use App\Agent\Anthropic\AnthropicModell;
use App\Agent\KeinSprachmodell;
use App\Agent\Sprachmodell;
use App\Anzeigen\Bildmodell;
use App\Anzeigen\KeinBildmodell;
use App\Anzeigen\KieAi\KieModell;
use App\Audit\AuditLogger;
use App\Audit\ImpersonationContext;
use App\Datenschutz\ClamAvPruefung;
use App\Datenschutz\ClamAvVerbindung;
use App\Datenschutz\KeineVirenpruefung;
use App\Datenschutz\Virenpruefung;
use App\Kanaele\Kanaleingaenge;
use App\Kanaele\Kanalversender;
use App\Models\User;
use App\Tenancy\KeyRing;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Beide halten Zustand fuer die Dauer einer Anfrage bzw. eines Jobs:
        // der aktuelle Mandant und die entpackten Schluessel. Als Singleton
        // gebunden, damit nicht zwei Aufrufstellen mit verschiedenen
        // Mandanten arbeiten.
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(KeyRing::class);

        // Ohne Singleton bekaeme jede Aufrufstelle eine eigene Instanz -- die
        // Maskierung liefe dann ins Leere, weil sie eine andere Sitzung
        // befragt als die, die gesetzt wurde.
        $this->app->singleton(ImpersonationContext::class);
        $this->app->singleton(AuditLogger::class);

        // Die Virenpruefung ist Infrastruktur: in der Entwicklung gibt es
        // keine, im Betrieb einen Dienst daneben (WP-33).
        //
        // **Ohne angebundenen Dienst wird nichts freigegeben.** Bis WP-33 gab
        // die Vorgabe jede Datei frei und vermerkte sie als geprueft -- eine
        // Luecke, die niemand bemerkt haette. Jetzt steht `unscanned` da, und
        // was so vermerkt ist, liefert das Produkt nicht aus.
        $this->app->bind(Virenpruefung::class, function (): Virenpruefung {
            $host = config('mrs.attachments.scanner.host');

            return is_string($host) && $host !== ''
                ? new ClamAvPruefung(new ClamAvVerbindung)
                : new KeineVirenpruefung;
        });

        // Als Singleton, sonst laeuft jede Registrierung eines Kanals ins
        // Leere: die naechste Aufrufstelle bekaeme ein leeres Register.
        $this->app->singleton(Kanaleingaenge::class);
        $this->app->singleton(Kanalversender::class);

        // Das Sprachmodell des Agenten (WP-22).
        //
        // **Ohne Schluessel keines** -- und das ist die Vorgabe, nicht der
        // Ausnahmefall. Ein Agent, der ohne angebundenes Modell etwas
        // vorschluege, haette es sich ausgedacht; er schlaegt deshalb nichts
        // vor und haelt das am Lauf fest.
        //
        // Umgekehrte Richtung als bei der Virenpruefung oben: dort gibt die
        // Vorgabe frei, damit die Funktion benutzbar bleibt.
        $this->app->bind(Sprachmodell::class, function (): Sprachmodell {
            $modell = new AnthropicModell;

            return $modell->angebunden() ? $modell : new KeinSprachmodell;
        });

        // Das Bildmodell fuer Anzeigenvorschlaege (WP-31).
        //
        // **Ohne Schluessel keines** -- dieselbe Richtung wie beim
        // Sprachmodell. Was das Produkt nicht erzeugen kann, erfindet es
        // nicht; die Oberflaeche sagt es, statt einen Fehler zu zeigen.
        $this->app->bind(Bildmodell::class, function (): Bildmodell {
            $modell = new KieModell;

            return $modell->angebunden() ? $modell : new KeinBildmodell;
        });
    }

    public function boot(): void
    {
        $this->registerTestFixtures();
        $this->configureEmailVerification();
        $this->configureMails();
        $this->configureDates();
        $this->configureModels();
        $this->configureCommands();
        $this->configurePasswords();
    }

    /**
     * Die Bestaetigungs-URL traegt die kanonische UUID.
     *
     * Der Primaerschluessel liegt als BINARY(16) vor (Entscheidung A4) und
     * laesst sich nicht in eine URL schreiben. Die Gegenprobe steht in
     * App\Http\Requests\Auth\VerifyEmailRequest.
     */
    private function configureEmailVerification(): void
    {
        VerifyEmail::createUrlUsing(function (User $benutzer): string {
            return URL::temporarySignedRoute(
                'verification.verify',
                CarbonImmutable::now()->addMinutes(
                    (int) config('auth.verification.expire', 60)
                ),
                [
                    'id' => $benutzer->uuid,
                    'hash' => sha1($benutzer->getEmailForVerification()),
                ]
            );
        });
    }

    /**
     * Deutsche Mails fuer die beiden Benachrichtigungen des Frameworks.
     *
     * Entscheidung P7: nur Deutsch. Die Vorlagen von Laravel sind englisch
     * und lassen sich nur so ersetzen -- eine Uebersetzungsdatei greift bei
     * ihnen nur teilweise, weil sie Satzteile zusammensetzen.
     *
     * Ausserdem sollen diese Mails nach dem Produkt klingen und nicht nach
     * dem Framework: sie sind fuer viele Praxen der erste Kontakt.
     */
    private function configureMails(): void
    {
        VerifyEmail::toMailUsing(function (User $benutzer, string $url): MailMessage {
            return (new MailMessage)
                ->subject('E-Mail-Adresse bestätigen')
                ->greeting('Willkommen bei '.config('app.name').'.')
                ->line('Bitte bestätigen Sie Ihre E-Mail-Adresse, dann ist Ihr Zugang vollständig.')
                ->action('E-Mail-Adresse bestätigen', $url)
                ->line('Der Link gilt '.(int) config('auth.verification.expire', 60).' Minuten.')
                ->line('Wenn Sie keinen Zugang angelegt haben, können Sie diese Nachricht ignorieren.')
                ->salutation('Viele Grüße von '.config('app.name'));
        });

        ResetPassword::toMailUsing(function (User $benutzer, string $merkmal): MailMessage {
            $minuten = (int) config('auth.passwords.users.expire', 60);

            return (new MailMessage)
                ->subject('Passwort zurücksetzen')
                ->line('Sie erhalten diese Nachricht, weil für Ihren Zugang ein neues Passwort angefordert wurde.')
                ->action('Neues Passwort vergeben', url(route('password.reset', [
                    'token' => $merkmal,
                    'email' => $benutzer->getEmailForPasswordReset(),
                ], false)))
                ->line('Der Link gilt '.$minuten.' Minuten.')
                ->line('Haben Sie das nicht angefordert, ist nichts zu tun — Ihr Passwort bleibt unverändert.')
                ->salutation('Viele Grüße von '.config('app.name'));
        });
    }

    /**
     * Tabellen, die es nur im Testlauf gibt.
     *
     * WP-03 baut die Mechanik der Mandantentrennung, nicht das Datenmodell.
     * Zum Nachweis braucht es trotzdem echte Tabellen -- sie liegen unter
     * tests/Fixtures und werden nur in der Umgebung 'testing' migriert.
     */
    private function registerTestFixtures(): void
    {
        if ($this->app->environment('testing')) {
            $this->loadMigrationsFrom(base_path('tests/Fixtures/migrations'));
        }
    }

    /**
     * Unveraenderliche Zeitobjekte.
     *
     * In einem Terminprodukt ist die haeufigste stille Fehlerquelle ein
     * Carbon-Objekt, das eine Methode wie addHours() an Ort und Stelle
     * veraendert und damit eine Slot-Grenze verschiebt, die an anderer
     * Stelle noch gebraucht wird. CarbonImmutable schliesst das aus.
     */
    private function configureDates(): void
    {
        Date::use(CarbonImmutable::class);
    }

    /**
     * Strenge Modelle.
     *
     * shouldBeStrict deckt drei Dinge ab, die sonst erst in Produktion
     * auffallen: nicht geladene Beziehungen (N+1), das stille Verschlucken
     * unbekannter Attribute und den Zugriff auf Attribute, die es nicht gibt.
     * Ausserhalb von Produktion als Fehler, damit es beim Entwickeln knallt.
     *
     * unguard() bleibt bewusst aus: ab WP-03 haengt an jedem Modell eine
     * organization_id, und ein Massenzuweisungsfehler auf diesem Feld waere
     * ein Mandantenleck, kein Schoenheitsfehler.
     */
    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
    }

    /**
     * Keine zerstoerenden Artisan-Befehle in Produktion.
     *
     * Verhindert migrate:fresh, db:wipe und Verwandte auf echten Daten.
     */
    private function configureCommands(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }

    private function configurePasswords(): void
    {
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->letters()->numbers()->symbols()->uncompromised()
            : Password::min(8));
    }
}
