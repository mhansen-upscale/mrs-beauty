<?php

declare(strict_types=1);

namespace App\Backoffice;

use App\Audit\AuditLogger;
use App\Audit\Impersonation;
use App\Enums\AuditEvent;
use App\Enums\OperatorRole;
use App\Models\ImpersonationSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
 *
 * **Geloescht wird nur hier** (Nachtrag 28.09.2026), nicht ueber das Profil:
 * dort fehlten der Protokolleintrag, das Ende der Impersonation und der
 * Schutz des letzten Super-Admins.
 */
final class Betreiberkonten
{
    public function __construct(
        private readonly AuditLogger $protokoll,
        private readonly Impersonation $impersonation,
    ) {}

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

    /**
     * Loescht ein Betreiberkonto endgueltig.
     *
     * Wer das darf, pruefen die Aufrufer -- nie das eigene Konto, nie den
     * letzten aktiven Super-Admin.
     *
     * **Das Protokoll bleibt.** Es haelt den Namen der Handelnden in
     * `actor_label` fest, und `audit_logs` kennt keinen Fremdschluessel auf
     * `users`. Der Eintrag `operator.deleted` nennt die Rolle, nicht Name oder
     * Adresse (C5); ueber ihn findet das Betreiberprotokoll die Handlungen
     * der Person weiter.
     */
    public function loesche(User $konto): void
    {
        DB::transaction(function () use ($konto): void {
            // Das Konto meldet sich nie mehr ab -- ohne das hier stuende in
            // der Praxis bis zum Ablauf "Support hat Zugriff".
            $sitzung = $this->impersonation->laufendeVon($konto);

            if ($sitzung instanceof ImpersonationSession) {
                $this->impersonation->end($sitzung, 'account_deleted');
            }

            $this->protokoll->record(
                ereignis: AuditEvent::OperatorDeleted,
                gegenstand: $konto,
                kontext: ['rolle' => $konto->betreiberRolle()?->value],
                ohneOrganisation: true,
            );

            // Ein offener Link aus der Mail gaelte sonst fuer ein Konto, das
            // spaeter jemand unter derselben Adresse neu anlegt.
            Password::deleteToken($konto);

            $konto->delete();
        });
    }
}
