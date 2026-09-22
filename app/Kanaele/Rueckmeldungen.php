<?php

declare(strict_types=1);

namespace App\Kanaele;

use App\Enums\MessageCostCategory;
use App\Enums\MessageStatus;
use App\Models\Message;
use Carbon\CarbonImmutable;

/**
 * Traegt Statusrueckmeldungen an den Nachrichten nach.
 *
 * **Der Zustand geht nur vorwaerts.** Meta liefert doppelt und nicht in der
 * Reihenfolge, in der die Dinge geschehen sind: ein `delivered`, das nach
 * einem `read` eintrifft, ist eine verspaetete Wiederholung und keine neue
 * Erkenntnis. Wer es trotzdem schreibt, laesst gelesene Nachrichten wieder
 * ungelesen werden.
 */
final class Rueckmeldungen
{
    /**
     * Die Kette des Versands. Hoeher heisst weiter.
     *
     * `failed` steht absichtlich oben: ein Fehlschlag nach einer
     * Zustellbestaetigung ist die juengere Aussage und die, die zaehlt.
     */
    private const RANG = [
        MessageStatus::Queued->value => 0,
        MessageStatus::Sent->value => 1,
        MessageStatus::Delivered->value => 2,
        MessageStatus::Read->value => 3,
        MessageStatus::Failed->value => 4,
    ];

    /**
     * @return bool Ob sich etwas geaendert hat
     */
    public function trageNach(Rueckmeldung $meldung): bool
    {
        $nachricht = Message::query()->where('external_id', $meldung->externeId)->first();

        if (! $nachricht instanceof Message) {
            // Eine Rueckmeldung zu etwas, das wir nicht kennen: kein Fehler.
            // Sie kommt fuer eine geloeschte Konversation oder fuer eine
            // Nachricht, deren Versand wir nie festgehalten haben.
            return false;
        }

        $geaendert = $this->setzeZustand($nachricht, $meldung);
        $geaendert = $this->setzeKategorie($nachricht, $meldung) || $geaendert;

        if ($geaendert) {
            $nachricht->save();
        }

        return $geaendert;
    }

    private function setzeZustand(Message $nachricht, Rueckmeldung $meldung): bool
    {
        $bisher = self::RANG[$nachricht->status->value];
        $neu = self::RANG[$meldung->status->value];

        if ($neu <= $bisher) {
            return false;
        }

        $nachricht->status = $meldung->status;

        if ($meldung->status === MessageStatus::Failed) {
            // Ein Kurzgrund, nie die Meldung des Anbieters: die traegt bei
            // Nachrichtenkanaelen regelmaessig Inhalte mit sich.
            $nachricht->failure = $meldung->kurzgrund ?? 'rejected';
        }

        if ($meldung->status === MessageStatus::Delivered || $meldung->status === MessageStatus::Read) {
            $nachricht->delivered_at ??= $meldung->zeitpunkt ?? CarbonImmutable::now();
        }

        return true;
    }

    /**
     * Die Kategorie wird gesetzt, wenn der Anbieter eine nennt -- und nur
     * dann. Keine Nennung heisst unbekannt, nicht kostenlos.
     */
    private function setzeKategorie(Message $nachricht, Rueckmeldung $meldung): bool
    {
        if (! $meldung->kategorie instanceof MessageCostCategory) {
            return false;
        }

        if ($nachricht->cost_category === $meldung->kategorie) {
            return false;
        }

        $nachricht->cost_category = $meldung->kategorie;

        return true;
    }
}
