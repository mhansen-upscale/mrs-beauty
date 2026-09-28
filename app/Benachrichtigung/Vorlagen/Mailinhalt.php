<?php

declare(strict_types=1);

namespace App\Benachrichtigung\Vorlagen;

/**
 * Eine Mail mit gesetzten Platzhaltern -- fertige Zeilen, bereit fuer den
 * Rahmen.
 *
 * **Nur Zeichenketten.** Die Nutzlast einer Mail traegt kein Modell (B21);
 * was hier steht, ist in der Warteschlange genauso gueltig wie im Anfragezyklus.
 */
final class Mailinhalt
{
    /**
     * @param  list<string>  $einleitung  Markdown, je Absatz eine Zeile
     * @param  list<string>  $schluss  Markdown, je Absatz eine Zeile
     */
    public function __construct(
        public readonly string $betreff,
        public readonly string $anrede,
        public readonly array $einleitung,
        public readonly array $schluss,
        public readonly string $gruss,
    ) {}
}
