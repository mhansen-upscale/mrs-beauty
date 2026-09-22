<?php

declare(strict_types=1);

namespace App\Attribution\Meta;

use App\Models\Organization;
use App\Models\Treatment;
use App\Support\Fehlereinordnung;
use App\Werbung\Werbefehler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Schickt ein Ereignis an Metas Conversions API.
 *
 * **Die letzte Schranke vor Regel 2.** Vor dem Absenden laeuft der fertige
 * Payload gegen alle aktiven Katalognamen: findet sich einer darin, geht
 * nichts hinaus. Das ist keine Empfehlung, sondern Regel 2 aus `CLAUDE.md` in
 * ausfuehrbarer Form -- und sie steht im Produktionsweg, nicht nur im Test.
 *
 * Der Gedanke dahinter: ein Test schuetzt vor dem, woran jemand gedacht hat.
 * Diese Pruefung schuetzt auch vor dem Feld, das eine spaetere Fassung
 * hinzufuegt.
 */
final class Conversionsversand
{
    public function sende(Organization $praxis, Konversionsereignis $ereignis): void
    {
        $pixel = data_get($praxis->settings, 'tracking.meta_pixel_id');
        $token = (string) config('mrs.meta.capi_token');

        if (! is_string($pixel) || $pixel === '' || $token === '') {
            // Ohne Pixel oder ohne Zugang wird nicht gesendet -- und das ist
            // kein Fehler, sondern der Normalfall vor dem App Review.
            return;
        }

        $nutzlast = $ereignis->toArray();

        $this->pruefeGegenKatalog($nutzlast);

        $adresse = rtrim((string) config('mrs.meta.graph_url'), '/')
            .'/'.(string) config('mrs.meta.api_version')
            .'/'.$pixel.'/events';

        try {
            $antwort = Http::withToken($token)
                ->acceptJson()
                ->timeout(20)
                ->post($adresse, ['data' => [$nutzlast]]);
        } catch (ConnectionException) {
            throw new Werbefehler(new Fehlereinordnung('unreachable', wiederholen: true, zustand: null));
        }

        if ($antwort->failed()) {
            throw new Werbefehler(
                Fehlereinordnung::ausMetaAntwort($antwort->status(), (array) $antwort->json())
            );
        }
    }

    /**
     * **Kein Katalogname verlaesst das System Richtung Meta.**
     *
     * Geprueft wird der ganze Payload, nicht einzelne Felder: die Liste der
     * Felder aendert sich, die Regel nicht.
     *
     * @param  array<string, mixed>  $nutzlast
     */
    private function pruefeGegenKatalog(array $nutzlast): void
    {
        $text = (string) json_encode($nutzlast);

        foreach (Treatment::aktiveNamen() as $behandlung) {
            if (mb_stripos($text, $behandlung) !== false) {
                throw new RuntimeException(
                    'Regel 2: Der Payload enthaelt die Katalogbezeichnung "'.$behandlung.'".'
                );
            }
        }
    }
}
