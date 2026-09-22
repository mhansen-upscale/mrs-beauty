<?php

declare(strict_types=1);

namespace App\Werbung\Verwaltung;

use App\Support\Fehlereinordnung;
use App\Werbung\Werbefehler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * **Die einzige Stelle im Produkt, die bei Meta schreibt.**
 *
 * WP-26 sichert zu, dass unter `app/Werbung` kein schreibender Graph-Aufruf
 * existiert. Diese Datei bricht das -- und steht deshalb namentlich in der
 * Ausnahmeliste des Tests. Eine Ausnahmeliste mit einem Eintrag ist eine
 * Regel; eine ohne Liste ist keine.
 *
 * **Schreiben wird nicht blind wiederholt.** Beim Lesen ist eine Wiederholung
 * harmlos. Beim Anlegen kann der erste Versuch geglueckt sein und nur die
 * Antwort verlorengegangen -- die Wiederholung legte dann eine zweite
 * Kampagne mit zweitem Budget an. Der Auftrag sieht deshalb erst nach
 * (ueber das Merkmal im Namen) und schickt danach.
 */
final class Graphschreiber
{
    /**
     * Legt einen Knoten an und gibt Metas Kennung zurueck.
     *
     * @param  array<string, mixed>  $daten
     */
    public function lege(string $token, string $pfad, array $daten): string
    {
        $antwort = $this->sende($token, $pfad, $daten);

        $kennung = data_get($antwort, 'id');

        if (! is_string($kennung) || $kennung === '') {
            // Angelegt, aber ohne Kennung: der naechste Lauf findet den
            // Knoten ueber das Merkmal wieder. Nicht wiederholen.
            throw new Werbefehler(new Fehlereinordnung('no_id', wiederholen: false, zustand: null));
        }

        return $kennung;
    }

    /**
     * Legt einen Knoten an und gibt die ganze Antwort zurueck.
     *
     * Fuer `/adimages`: dort steht keine `id`, sondern eine Abbildung
     * Dateiname auf Hash. Eine eigene Methode statt eines Schalters an
     * `lege()` -- der Aufrufer weiss, welche Form er erwartet.
     *
     * @param  array<string, mixed>  $daten
     * @return array<string, mixed>
     */
    public function legeRoh(string $token, string $pfad, array $daten): array
    {
        return $this->sende($token, $pfad, $daten);
    }

    /**
     * Aendert einen bestehenden Knoten.
     *
     * Metas Marketing-API kennt kein PATCH: geaendert wird mit POST auf die
     * Kennung.
     *
     * @param  array<string, mixed>  $daten
     */
    public function aendere(string $token, string $kennung, array $daten): void
    {
        $this->sende($token, $kennung, $daten);
    }

    /**
     * @param  array<string, mixed>  $daten
     * @return array<string, mixed>
     */
    private function sende(string $token, string $pfad, array $daten): array
    {
        $adresse = rtrim((string) config('mrs.meta.graph_url'), '/')
            .'/'.(string) config('mrs.meta.api_version')
            .'/'.ltrim($pfad, '/');

        try {
            $antwort = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->asForm()
                ->post($adresse, $daten);
        } catch (ConnectionException) {
            // Die Verbindung brach ab -- ob Meta den Auftrag bekommen hat,
            // wissen wir nicht. Wiederholen ist erlaubt, weil der Auftrag
            // vorher nachsieht.
            throw new Werbefehler(new Fehlereinordnung('unreachable', wiederholen: true, zustand: null));
        }

        if ($antwort->failed()) {
            throw new Werbefehler(
                Fehlereinordnung::ausMetaAntwort($antwort->status(), (array) $antwort->json())
            );
        }

        $daten = $antwort->json();

        return is_array($daten) ? $daten : [];
    }
}
