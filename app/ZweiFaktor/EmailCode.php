<?php

declare(strict_types=1);

namespace App\ZweiFaktor;

use App\Models\User;
use App\Notifications\Anmeldecode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Der Code per E-Mail (WP-35).
 *
 * **Im Cache, nicht in einer Tabelle**, und dort nur als Hash. Der Code lebt
 * zehn Minuten; eine Tabelle braeuchte dafuer einen Aufraeumjob und eine
 * Frist nach C7. Verliert der Cache ihn, drueckt die Person "Erneut senden"
 * -- dasselbe Muster wie die Support-PIN (C15).
 *
 * **Ein neuer Code ersetzt den alten**, und der Zweck steht im Schluessel:
 * ein Einrichtungscode oeffnet keine Anmeldung.
 */
final class EmailCode
{
    /**
     * Schickt einen neuen Code an die Kontoadresse.
     *
     * Gedrosselt je Person, nicht je Zweck: beide gehen in dasselbe Postfach.
     */
    public function sende(User $person, Zweck $zweck): Versand
    {
        if ($this->erneutMoeglichIn($person) > 0) {
            return Versand::Gedrosselt;
        }

        RateLimiter::hit($this->abstandsschluessel($person), (int) config('mrs.zwei_faktor.email_erneut_nach_sekunden'));
        RateLimiter::hit($this->stundenschluessel($person), 3600);

        $stellen = (int) config('mrs.zwei_faktor.code_stellen');
        $code = str_pad((string) random_int(0, 10 ** $stellen - 1), $stellen, '0', STR_PAD_LEFT);
        $minuten = (int) config('mrs.zwei_faktor.email_code_gueltig_minuten');
        $bis = CarbonImmutable::now()->addMinutes($minuten);

        Cache::put($this->schluessel($person, $zweck), [
            'hash' => Hash::make($code),
            'versuche' => 0,
            'bis' => $bis->getTimestamp(),
        ], $bis);

        try {
            // Ueber die Warteschlange `realtime`, verschluesselt (Anmeldecode).
            // Scheitern kann hier nur das Einreihen -- dann soll die Person es
            // erfahren, statt auf eine Mail zu warten, die nie kommt.
            $person->notify(new Anmeldecode($code, $minuten, $zweck === Zweck::Einrichtung));
        } catch (Throwable $fehler) {
            Cache::forget($this->schluessel($person, $zweck));

            // Nur die Art des Fehlers: die Meldung nennt gern die
            // Empfaengeradresse.
            Log::warning('Anmeldecode konnte nicht versendet werden.', ['exception' => $fehler::class]);

            return Versand::Gescheitert;
        }

        return Versand::Gesendet;
    }

    /**
     * Prueft einen Code und verbraucht ihn, wenn er passt.
     *
     * Nach `max_versuche` falschen Eingaben ist er verbrannt -- das gilt auch
     * fuer die Einrichtung, die keine ausstehende Anmeldung zaehlt.
     */
    public function pruefe(User $person, Zweck $zweck, string $code): bool
    {
        $schluessel = $this->schluessel($person, $zweck);
        $code = preg_replace('/\s+/', '', $code) ?? '';

        // Unter Sperre: zwei gleichzeitige Anfragen mit demselben Code
        // kaemen sonst beide durch, bevor eine ihn verbraucht hat.
        $ergebnis = Cache::lock($schluessel.':sperre', 5)->get(function () use ($schluessel, $code): bool {
            $eintrag = Cache::get($schluessel);

            if (! is_array($eintrag) || ! is_string($eintrag['hash'] ?? null)) {
                return false;
            }

            if (preg_match('/^\d+$/', $code) === 1 && Hash::check($code, $eintrag['hash'])) {
                Cache::forget($schluessel);

                return true;
            }

            $eintrag['versuche'] = (int) ($eintrag['versuche'] ?? 0) + 1;

            if ($eintrag['versuche'] >= (int) config('mrs.zwei_faktor.max_versuche')) {
                Cache::forget($schluessel);
            } else {
                Cache::put($schluessel, $eintrag, CarbonImmutable::createFromTimestamp((int) $eintrag['bis']));
            }

            return false;
        });

        return $ergebnis === true;
    }

    /** Sekunden bis zum naechsten erlaubten Versand, 0 heisst: jetzt. */
    public function erneutMoeglichIn(User $person): int
    {
        $abstand = RateLimiter::tooManyAttempts($this->abstandsschluessel($person), 1)
            ? RateLimiter::availableIn($this->abstandsschluessel($person))
            : 0;

        $stunde = RateLimiter::tooManyAttempts($this->stundenschluessel($person), (int) config('mrs.zwei_faktor.email_sendungen_je_stunde'))
            ? RateLimiter::availableIn($this->stundenschluessel($person))
            : 0;

        return max($abstand, $stunde);
    }

    public function verwerfe(User $person, Zweck $zweck): void
    {
        Cache::forget($this->schluessel($person, $zweck));
    }

    private function schluessel(User $person, Zweck $zweck): string
    {
        return "zwei-faktor:code:{$zweck->value}:{$person->uuid}";
    }

    private function abstandsschluessel(User $person): string
    {
        return "zwei-faktor:abstand:{$person->uuid}";
    }

    private function stundenschluessel(User $person): string
    {
        return "zwei-faktor:sendungen:{$person->uuid}";
    }
}
