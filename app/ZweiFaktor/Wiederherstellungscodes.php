<?php

declare(strict_types=1);

namespace App\ZweiFaktor;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Die Codes fuer den Tag, an dem das Telefon weg ist (WP-35).
 *
 * **Gespeichert wird nur SHA-256**, wie beim Merkmal einer Einladung. Ein
 * Code hat 16 Zeichen aus 31 -- rund 79 Bit. Bei so viel Zufall braucht es
 * kein langsames Verfahren; bcrypt ueber acht Codes kostete bei jedem
 * Versuch eine halbe Sekunde, ohne etwas zu schuetzen.
 *
 * **Ohne verwechselbare Zeichen**: kein 0 und O, kein 1, I und L. Die Codes
 * werden vom Zettel abgetippt.
 */
final class Wiederherstellungscodes
{
    private const ZEICHEN = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LAENGE = 16;

    /**
     * Neue Codes -- die alten gelten danach nicht mehr.
     *
     * Speichert ohne das allgemeine Protokoll; den Eintrag schreibt, wer die
     * Codes erneuert (ZweiterFaktor), mit einem Ereignis, das etwas sagt.
     *
     * @return list<string> Die Codes im Klartext, genau dieses eine Mal
     */
    public function erzeuge(User $person): array
    {
        $codes = [];

        for ($i = 0; $i < (int) config('mrs.zwei_faktor.wiederherstellungscodes'); $i++) {
            $codes[] = $this->neuerCode();
        }

        $person->zwei_faktor_wiederherstellung = array_map(fn (string $code): string => self::hashe($code), $codes);
        $person->saveQuietly();

        return $codes;
    }

    /**
     * Loest einen Code ein, wenn er passt. Jeder wirkt einmal.
     *
     * **Unter Sperre gelesen**: Zwei Anmeldungen mit demselben Code loesen
     * ihn sonst beide ein, wenn beide die Liste vor dem Schreiben der anderen
     * gelesen haben.
     */
    public function loese(User $person, string $eingabe): bool
    {
        $hash = self::hashe($eingabe);

        $uebrig = DB::transaction(function () use ($person, $hash): ?array {
            $gesperrt = User::query()->whereKey($person->getKey())->lockForUpdate()->first();

            if (! $gesperrt instanceof User) {
                return null;
            }

            $hashes = $gesperrt->zwei_faktor_wiederherstellung ?? [];
            $treffer = null;

            foreach ($hashes as $stelle => $gespeichert) {
                if (hash_equals($gespeichert, $hash)) {
                    $treffer = $stelle;
                }
            }

            if ($treffer === null) {
                return null;
            }

            unset($hashes[$treffer]);

            $gesperrt->zwei_faktor_wiederherstellung = array_values($hashes);
            $gesperrt->saveQuietly();

            return $gesperrt->zwei_faktor_wiederherstellung;
        });

        if ($uebrig === null) {
            return false;
        }

        $person->zwei_faktor_wiederherstellung = $uebrig;
        $person->syncOriginalAttribute('zwei_faktor_wiederherstellung');

        return true;
    }

    public function uebrig(User $person): int
    {
        return count($person->zwei_faktor_wiederherstellung ?? []);
    }

    /**
     * Sieht die Eingabe aus wie ein Wiederherstellungscode und nicht wie ein
     * Code der App?
     */
    public static function siehtAusWie(string $eingabe): bool
    {
        return strlen(self::normalisiere($eingabe)) === self::LAENGE;
    }

    /** Gross, ohne Bindestriche und Leerzeichen -- so, wie es vom Zettel kommt. */
    private static function normalisiere(string $eingabe): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($eingabe)) ?? '';
    }

    private static function hashe(string $code): string
    {
        return hash('sha256', self::normalisiere($code));
    }

    private function neuerCode(): string
    {
        $zeichen = '';

        for ($i = 0; $i < self::LAENGE; $i++) {
            $zeichen .= self::ZEICHEN[random_int(0, strlen(self::ZEICHEN) - 1)];
        }

        return implode('-', str_split($zeichen, 4));
    }
}
