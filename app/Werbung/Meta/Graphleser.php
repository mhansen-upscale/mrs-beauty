<?php

declare(strict_types=1);

namespace App\Werbung\Meta;

use App\Support\Fehlereinordnung;
use App\Werbung\Werbefehler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Die eine Stelle, die dieses Paket bei Meta liest.
 *
 * **Lesend, ausschliesslich.** Es gibt hier kein post(), kein delete() und
 * kein put(); ein Test unter tests/Feature/Werbung haelt das fest. Das ist
 * nicht nur Abgrenzung zu WP-27: `ads_management` ist die Berechtigung, die
 * im App Review abgelehnt wird, und wer sie hier braucht, haelt auch die
 * beiden lesenden auf.
 */
final class Graphleser
{
    /**
     * Alle Zeilen einer Sammlung, ueber alle Seiten.
     *
     * **Mit harter Obergrenze.** `paging.next` laeuft im Zweifel im Kreis --
     * Meta liefert dann dieselbe Seite erneut, und ein Abgleich, der nicht
     * endet, blockiert die Warteschlange fuer alle anderen Mandanten.
     *
     * @param  array<string, mixed>  $anfrage
     * @return list<array<string, mixed>>
     */
    public function sammle(string $token, string $pfad, array $anfrage = []): array
    {
        $anfrage['limit'] ??= (int) config('mrs.ads.page_size');

        $grundadresse = $this->basis().'/'.ltrim($pfad, '/');
        $grundanfrage = $anfrage;

        $adresse = $grundadresse;
        $grenze = max(1, (int) config('mrs.ads.max_pages'));
        $zeilen = [];
        $gesehen = [];

        for ($seite = 0; $seite < $grenze; $seite++) {
            $antwort = $this->hole($token, $adresse, $anfrage);

            $daten = data_get($antwort, 'data', []);
            $neue = 0;

            if (is_array($daten)) {
                foreach ($daten as $zeile) {
                    if (is_array($zeile)) {
                        $zeilen[] = $zeile;
                        $neue++;
                    }
                }
            }

            // **Die Folgeseite bauen wir selbst.** Metas `paging.next` zeigt
            // auf *seine* aktuelle Version statt auf die festgenagelte
            // (docs/integrationen/meta.md) und traegt doppelt kodierte
            // Parameter: `%255B%2522` statt `%5B%22`. Wer ihr folgt, bekommt
            // "Invalid parameter" -- und `/search` liefert sie **immer**,
            // auch wenn nichts folgt. Genau das liess am 23.09.2026 jede
            // Ortsaufloesung scheitern, obwohl die erste Seite das Ergebnis
            // schon hatte. Vom Cursor kommt deshalb nur der Cursor.
            $cursor = data_get($antwort, 'paging.cursors.after');

            if (is_string($cursor) && $cursor !== '') {
                if ($neue === 0 || isset($gesehen[$cursor])) {
                    return $zeilen;
                }

                $gesehen[$cursor] = true;

                $adresse = $grundadresse;
                $anfrage = $grundanfrage;
                $anfrage['after'] = $cursor;

                continue;
            }

            // Ohne Cursor bleibt nur Metas Adresse -- aeltere Kanten liefern
            // keinen. Sie traegt Token und Parameter bereits mit sich.
            $weiter = data_get($antwort, 'paging.next');

            if (! is_string($weiter) || $weiter === '' || isset($gesehen[$weiter])) {
                return $zeilen;
            }

            $gesehen[$weiter] = true;

            $adresse = $weiter;
            $anfrage = [];
        }

        return $zeilen;
    }

    /**
     * Ein einzelner Knoten.
     *
     * @param  array<string, mixed>  $anfrage
     * @return array<string, mixed>
     */
    public function knoten(string $token, string $pfad, array $anfrage = []): array
    {
        return $this->hole($token, $this->basis().'/'.ltrim($pfad, '/'), $anfrage);
    }

    /**
     * @param  array<string, mixed>  $anfrage
     * @return array<string, mixed>
     */
    private function hole(string $token, string $adresse, array $anfrage): array
    {
        try {
            $antwort = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->retry(2, 200, function (Throwable $ausnahme): bool {
                    if ($ausnahme instanceof ConnectionException) {
                        return true;
                    }

                    // 4xx nicht wiederholen: ein ungueltiges Token und eine
                    // fehlende Berechtigung werden durch Warten nicht besser.
                    return $ausnahme instanceof RequestException
                        && $ausnahme->response->serverError();
                }, throw: false)
                ->get($adresse, $anfrage);
        } catch (ConnectionException) {
            // **Auch nach zwei Wiederholungen kann die Verbindung stehen
            // bleiben.** `throw: false` bezieht sich auf die Antwort, nicht
            // auf einen Verbindungsabbruch -- der flog bis zum 24.09.2026
            // roh aus dem Auftrag heraus. Fuer den Auftrag war das kein
            // Werbefehler, also lief `vermerkeFehler` nie, und die Praxis
            // las am Ende "Die Ursache liegt bei uns" ueber einen Ausfall
            // bei Meta. Der Schreiber macht es seit je richtig.
            throw new Werbefehler(new Fehlereinordnung('unreachable', wiederholen: true, zustand: null));
        }

        if ($antwort->failed()) {
            $fehler = (array) $antwort->json();

            // **Die andere Haelfte des Paares.** Der Graphschreiber haelt
            // eine abgelehnte Antwort seit je vollstaendig fest, der Leser
            // gar nicht -- und ein gescheitertes `me/adaccounts` war deshalb
            // am 24.09.2026 nicht auseinanderzuhalten von einem abgelaufenen
            // Token. Ohne Code und Subcode ist weder Metas Doku noch Metas
            // Support zu durchsuchen.
            Log::warning('Meta hat einen Lesezugriff abgelehnt.', [
                'adresse' => $adresse,
                'status' => $antwort->status(),
                'code' => data_get($fehler, 'error.code'),
                'subcode' => data_get($fehler, 'error.error_subcode'),
                'meldung' => data_get($fehler, 'error.message'),
                'klartext' => data_get($fehler, 'error.error_user_msg'),
                'fbtrace_id' => data_get($fehler, 'error.fbtrace_id'),
            ]);

            throw new Werbefehler(Fehlereinordnung::ausMetaAntwort($antwort->status(), $fehler));
        }

        $daten = $antwort->json();

        return is_array($daten) ? $daten : [];
    }

    private function basis(): string
    {
        return rtrim((string) config('mrs.meta.graph_url'), '/')
            .'/'.(string) config('mrs.meta.api_version');
    }
}
