<?php

declare(strict_types=1);

namespace App\Backoffice;

use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\OperatorRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Legt Konten fuer das Team des Betreibers an (WP-34a).
 *
 * Eine Stelle fuer Backoffice und Konsole: beide sollen dasselbe Konto
 * anlegen, mit demselben Protokolleintrag und demselben Passwortlink.
 *
 * **Niemand ausser der Person kennt das Passwort.** Angelegt wird mit einem
 * zufaelligen, das niemand je sieht; das eigentliche setzt die Person ueber
 * den Link, den sie per Mail bekommt. Der Link beweist zugleich die Adresse,
 * deshalb ist sie gleich bestaetigt -- die Routen verlangen `verified`.
 *
 * **Ohne Mail** (Staging, oder der letzte Super-Admin hat sein Passwort
 * verloren) setzt allein die Konsole ein Passwort: `neuesPasswort()`. Es
 * steht einmal in deren Ausgabe und sonst nirgends.
 */
final class Betreiberkonten
{
    public function __construct(private readonly AuditLogger $protokoll) {}

    /**
     * @throws RuntimeException Wenn die Adresse einem Praxiskonto gehoert
     */
    public function lege(string $email, string $name, OperatorRole $rolle, bool $linkSenden = true): User
    {
        $konto = User::query()->where('email', $email)->first();

        if ($konto instanceof User && ! $konto->istBetreiber()) {
            // Ein Konto gehoert entweder zu einer Praxis oder zum Betreiber --
            // nie zu beidem (Regel 1). Das haelt auch der Trigger fest; hier
            // steht es, damit die Meldung verstaendlich ist.
            throw new RuntimeException("Die Adresse {$email} gehört zu einer Praxis und kann kein Betreiberkonto werden.");
        }

        $neu = ! $konto instanceof User;

        $konto ??= new User;
        $konto->name = $name;
        $konto->email = $email;
        $konto->operator_role = $rolle;

        if ($neu) {
            $konto->password = Str::password(64);
            $konto->email_verified_at = CarbonImmutable::now();
        }

        $konto->save();

        $this->protokoll->record(
            ereignis: $neu ? AuditEvent::OperatorCreated : AuditEvent::OperatorRoleChanged,
            gegenstand: $konto,
            kontext: ['rolle' => $rolle->value],
            ohneOrganisation: true,
        );

        if ($neu && $linkSenden) {
            Password::sendResetLink(['email' => $email]);
        }

        return $konto;
    }

    /**
     * Setzt ein erzeugtes Passwort und gibt es zurueck -- **nur fuer die
     * Konsole**.
     *
     * Erzeugt, nicht uebergeben: ein Passwort auf der Befehlszeile stuende in
     * der Befehlshistorie der Shell oder von Laravel Cloud. Vierundzwanzig
     * Zeichen genuegen auch den Regeln fuer Produktion
     * (AppServiceProvider::configurePasswords). Ins Protokoll kommt, **dass**
     * es gesetzt wurde, nie das Passwort (C5).
     */
    public function neuesPasswort(User $konto): string
    {
        $passwort = Str::password(24);

        $konto->password = $passwort;
        $konto->save();

        $this->protokoll->record(
            ereignis: AuditEvent::OperatorPasswordSet,
            gegenstand: $konto,
            kontext: ['weg' => 'konsole'],
            ohneOrganisation: true,
        );

        return $passwort;
    }
}
