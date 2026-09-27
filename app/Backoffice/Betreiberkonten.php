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
 */
final class Betreiberkonten
{
    public function __construct(private readonly AuditLogger $protokoll) {}

    /**
     * @throws RuntimeException Wenn die Adresse einem Praxiskonto gehoert
     */
    public function lege(string $email, string $name, OperatorRole $rolle): User
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

        if ($neu) {
            Password::sendResetLink(['email' => $email]);
        }

        return $konto;
    }
}
