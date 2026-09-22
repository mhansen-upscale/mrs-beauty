<?php

declare(strict_types=1);

namespace App\Kanaele;

/**
 * Was ein Kanal koennen muss, um Eingehendes zu lesen.
 *
 * **Hier steht keine Umsetzung** -- die vier Kanaele sind WP-20. Ihre
 * Nutzlasten haben wenig gemein: WhatsApp verschachtelt unter
 * `changes[].value.messages[]`, Messenger unter `messaging[]`, Instagram
 * bringt Story-Antworten und Reaktionen als eigene Ereignistypen, die sonst
 * als leere Nachrichten erscheinen (docs/integrationen/meta.md).
 *
 * Was sie teilen -- Empfang, Signatur, Rohereignis, Deduplizierung,
 * Konversation, Service-Fenster -- steht in diesem Paket und gibt es genau
 * einmal.
 */
interface Kanaleingang
{
    /**
     * Liest aus einem Eintrag der Zustellung die enthaltenen Nachrichten.
     *
     * @param  array<string, mixed>  $eintrag
     * @return list<Eingangsnachricht>
     */
    public function lies(array $eintrag): array;
}
