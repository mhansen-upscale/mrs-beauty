<?php

declare(strict_types=1);

namespace App\Kanaele;

use Carbon\CarbonImmutable;

/**
 * Eine eingehende Nachricht, auf das reduziert, was jeder Kanal liefert.
 *
 * Die Form der Nutzlast unterscheidet sich je Kanal erheblich -- WhatsApp
 * verschachtelt anders als Messenger, Instagram kennt eigene Ereignistypen
 * fuer Story-Antworten und Reaktionen. Was **alle** liefern, steht hier; das
 * Lesen bleibt beim Kanal (WP-20).
 */
final class Eingangsnachricht
{
    public function __construct(
        public readonly string $externeId,
        public readonly string $absender,
        public readonly ?string $inhalt,
        public readonly ?string $medientyp = null,
        public readonly ?CarbonImmutable $zeitpunkt = null,
        public readonly ?string $anzeigename = null,

        /**
         * Nur E-Mail hat einen. Die Nachrichtenkanaele kennen kein
         * Betrefffeld -- deshalb steht er hier und nicht im Inhalt.
         */
        public readonly ?string $betreff = null,

        /**
         * Dateien, die mitkamen: Name und Inhalt.
         *
         * **Nicht der Pfad, sondern der Inhalt** -- gespeichert wird erst in
         * der Verarbeitung, ueber den Anhangspeicher aus WP-18, damit die
         * Virenpruefung und die Frist aus Entscheidung C6 greifen.
         *
         * @var list<array{name: string, inhalt: string}>
         */
        public readonly array $anhaenge = [],
    ) {}
}
