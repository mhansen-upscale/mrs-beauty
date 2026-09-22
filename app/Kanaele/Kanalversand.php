<?php

declare(strict_types=1);

namespace App\Kanaele;

use App\Models\ChannelConnection;
use App\Models\Message;

/**
 * Was ein Kanal koennen muss, um zu senden.
 *
 * **Hier steht keine Umsetzung** -- die vier Kanaele sind WP-20. Dieses Paket
 * baut die Strecke, durch die sie laufen: Warteschlange,
 * Idempotenzschluessel, Fehlerklassen, Service-Fenster. Die Schnittstelle
 * ist der Punkt, an dem beides zusammenkommt.
 *
 * Anders als beim Kalendersync steht sie hier **vor** den Umsetzungen, und
 * das hat einen Grund: die vier Kanaele teilen sich einen Endpunkt und eine
 * Deduplizierung, die es vorher geben muss. Was sie **nicht** teilen -- Form
 * der Nutzlast, Templates, Kostenmodell -- steht nicht in dieser
 * Schnittstelle.
 */
interface Kanalversand
{
    /**
     * Schickt die Nachricht und meldet, was der Anbieter geantwortet hat.
     *
     * Wirft Kanalfehler, wenn der Anbieter ablehnt -- die Einordnung
     * uebernimmt Fehlereinordnung.
     */
    public function sende(ChannelConnection $verbindung, Message $nachricht): Versandergebnis;
}
