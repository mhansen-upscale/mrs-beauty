<?php

declare(strict_types=1);

namespace App\Kanaele;

use App\Enums\ChannelType;
use App\Models\ChannelConnection;
use App\Models\ChannelRawEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * Rohereignisse aufnehmen und wieder einspielen.
 *
 * Schritt 3 und 4 des Ablaufs aus docs/integrationen/meta.md: speichern, dann
 * ueber die externe Kennung deduplizieren.
 *
 * **Meta liefert doppelt, im Normalbetrieb.** Die Zusage "genau einmal" ist
 * deshalb ein Unique-Index und keine Abfrage davor -- zwei gleichzeitige
 * Zustellungen wuerden eine Pruefung beide bestehen.
 */
final class Rohereignisse
{
    /**
     * Nimmt eine Zustellung auf.
     *
     * Gibt null zurueck, wenn es sie schon gibt. Das ist kein Fehler,
     * sondern der haeufigste Fall bei einer Wiederholung.
     */
    public function nimmAuf(
        ChannelConnection $verbindung,
        string $externeId,
        string $nutzlast,
    ): ?ChannelRawEvent {
        try {
            $ereignis = new ChannelRawEvent;
            $ereignis->channel = $verbindung->channel;
            $ereignis->external_id = $externeId;
            $ereignis->payload = $nutzlast;
            $ereignis->save();

            return $ereignis;
        } catch (QueryException $ausnahme) {
            // Genau dafuer ist der Index da.
            if (str_contains($ausnahme->getMessage(), 'rohereignis_unique')) {
                return null;
            }

            throw $ausnahme;
        }
    }

    public function vermerkeErfolg(ChannelRawEvent $ereignis): void
    {
        $ereignis->processed_at = CarbonImmutable::now();
        $ereignis->failure = null;
        $ereignis->attempts = $ereignis->attempts + 1;
        $ereignis->save();
    }

    /**
     * Haelt einen Fehlschlag fest -- ohne Klartext.
     *
     * Ein Kurzgrund, niemals eine Fehlermeldung des Anbieters: die traegt bei
     * Nachrichtenkanaelen regelmaessig Inhalte mit sich (Entscheidung C5,
     * sinngemaess).
     */
    public function vermerkeFehlschlag(ChannelRawEvent $ereignis, string $kurzgrund): void
    {
        $ereignis->failure = $kurzgrund;
        $ereignis->attempts = $ereignis->attempts + 1;
        $ereignis->save();
    }

    /**
     * Was erneut eingespielt werden kann.
     *
     * Der einzige Zweck dieser Tabelle. Nach 14 Tagen raeumt die Aufbewahrung
     * auf -- bis dahin laesst sich eine misslungene Verarbeitung wiederholen,
     * ohne den Anbieter um eine erneute Zustellung zu bitten.
     *
     * @return Collection<int, ChannelRawEvent>
     */
    public function offene(?ChannelType $kanal = null, int $hoechstens = 100)
    {
        return ChannelRawEvent::query()
            ->offen()
            ->when($kanal instanceof ChannelType, fn ($abfrage) => $abfrage->where('channel', $kanal?->value))
            ->orderBy('created_at')
            ->limit($hoechstens)
            ->get();
    }
}
