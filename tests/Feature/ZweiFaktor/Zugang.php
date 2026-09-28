<?php

declare(strict_types=1);

namespace Tests\Feature\ZweiFaktor;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\Anmeldecode;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

/**
 * Was die Tests des zweiten Faktors gemeinsam brauchen (WP-35).
 *
 * Eine Klasse statt Funktionen in einer Testdatei: Pest laedt Funktionen
 * global, und zwei Dateien mit derselben Hilfsfunktion brechen den Lauf ab.
 */
final class Zugang
{
    /** Das Geheimnis der Factory-States -- wie es in der App des Telefons steht. */
    public const GEHEIMNIS = UserFactory::TESTGEHEIMNIS;

    /**
     * Der Code, den die App gerade zeigt -- oder einen Takt davor/danach.
     */
    public static function appCode(string $geheimnis = self::GEHEIMNIS, int $versatz = 0): string
    {
        $takt = (int) config('mrs.zwei_faktor.totp_takt_sekunden');
        $schritt = intdiv(CarbonImmutable::now()->getTimestamp(), $takt) + $versatz;

        return (new Google2FA)->oathTotp($geheimnis, $schritt);
    }

    /**
     * Der Code aus der letzten Anmeldecode-Mail an diese Person.
     *
     * Braucht Notification::fake() -- gelesen wird, was in der Mail steht,
     * nicht ein Feld der Nachricht: genau das bekommt die Person zu sehen.
     */
    public static function codeAusMail(User $person): string
    {
        $nachricht = Notification::sent($person, Anmeldecode::class)->last();

        if (! $nachricht instanceof Anmeldecode) {
            throw new RuntimeException('Keine Anmeldecode-Mail an diese Person.');
        }

        $text = implode("\n", $nachricht->toMail($person)->introLines);

        if (preg_match('/\b(\d{'.(int) config('mrs.zwei_faktor.code_stellen').'})\b/', $text, $treffer) !== 1) {
            throw new RuntimeException('Kein Code in der Mail.');
        }

        return $treffer[1];
    }

    /** Eine Inhaberin einer eigenen Praxis -- ohne offenen Mandanten danach. */
    public static function inhaberin(?callable $zustand = null, string $email = 'inhaberin@praxis.test'): User
    {
        $praxis = alsMandant(organisation('Demo-Praxis'));

        $fabrik = User::factory()->fuer($praxis, Role::Owner)->state(['einfuehrung_gesehen_at' => CarbonImmutable::now()]);

        if ($zustand !== null) {
            $fabrik = $zustand($fabrik);
        }

        $person = $fabrik->create(['email' => $email]);
        ohneMandant();

        return $person;
    }
}
