<?php

declare(strict_types=1);

namespace App\Attribution;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Das First-Party-Cookie, an dem ein Besucher wiedererkannt wird.
 *
 * **Ohne Einwilligung keines** (Paragraf 25 TTDSG). Ein
 * Wiedererkennungs-Cookie mit 180 Tagen Laufzeit ist nicht technisch
 * notwendig; die Einwilligung muss vorliegen, **bevor** es gesetzt wird,
 * nicht waehrend.
 *
 * Der Wert ist Zufall ohne Personenbezug -- bis ein Touch mit einem Kontakt
 * verknuepft wird. Ab da gilt fuer die Zeile, was fuer einen Kontakt gilt.
 *
 * `httpOnly`, weil ihn niemand im Browser braucht: gelesen wird er auf dem
 * Server, und ein Skript einer fremden Seite hat hier nichts zu suchen.
 */
final class Besucherkennung
{
    public function eingewilligt(Request $anfrage): bool
    {
        return $anfrage->cookie((string) config('mrs.attribution.consent_cookie')) === 'ja';
    }

    /**
     * Die Kennung dieses Besuchers -- oder null, wenn nicht eingewilligt.
     *
     * Legt sie an, wenn es noch keine gibt. Das Cookie wird an die Antwort
     * angehaengt (`Cookie::queue`), damit der Aufrufort keine Antwort
     * durchreichen muss.
     */
    public function kennung(Request $anfrage): ?string
    {
        if (! $this->eingewilligt($anfrage)) {
            return null;
        }

        $name = (string) config('mrs.attribution.visitor_cookie_name');
        $vorhanden = $anfrage->cookie($name);

        if (is_string($vorhanden) && $vorhanden !== '') {
            return $vorhanden;
        }

        $neu = (string) Str::uuid();

        Cookie::queue(Cookie::make(
            name: $name,
            value: $neu,
            minutes: (int) config('mrs.attribution.visitor_cookie_days') * 24 * 60,
            httpOnly: true,
            sameSite: 'lax',
        ));

        return $neu;
    }

    /**
     * Die Entscheidung festhalten.
     *
     * Eine Ablehnung wird ebenso gespeichert wie eine Zustimmung -- sonst
     * wird bei jedem Aufruf erneut gefragt, und das ist keine Entscheidung,
     * sondern Zermuerbung.
     */
    public function entscheide(bool $ja): void
    {
        Cookie::queue(Cookie::make(
            name: (string) config('mrs.attribution.consent_cookie'),
            value: $ja ? 'ja' : 'nein',
            minutes: (int) config('mrs.attribution.visitor_cookie_days') * 24 * 60,
            httpOnly: true,
            sameSite: 'lax',
        ));

        if (! $ja) {
            // Was schon liegt, geht weg. Eine Ablehnung, die das vorhandene
            // Cookie stehen laesst, ist keine.
            Cookie::queue(Cookie::forget((string) config('mrs.attribution.visitor_cookie_name')));
        }
    }
}
