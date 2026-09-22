<?php

declare(strict_types=1);

namespace App\Werbung;

use App\Models\Treatment;

/**
 * Traegt ein aus Meta gelesener Name eine Katalogbezeichnung?
 *
 * **Warum das ueberhaupt auffaellt.** Kampagnennamen sind Werbe-Metadaten,
 * liegen bei Meta offen und frieren ab WP-32 als attribution_snapshot am
 * Termin ein (D13). Eine Kampagne "Botox Herbst" setzt damit einen
 * Behandlungsnamen unmittelbar neben einen Kontakt.
 *
 * Bei uns loest das die Verschluesselung des Feldes. An Metas Seite loest sie
 * nichts -- deshalb dieser Hinweis. Der Name bleibt trotzdem stehen: er
 * gehoert der Praxis, und fremde Namen aendern wir nicht (Entscheidung C9).
 */
final class Namenspruefung
{
    /** @var list<string>|null */
    private ?array $katalog = null;

    public function traegtKatalogbezeichnung(?string $name): bool
    {
        return $this->treffer($name) !== null;
    }

    /**
     * Welche Bezeichnung getroffen hat -- fuer den Hinweis in der Oberflaeche.
     */
    public function treffer(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        foreach ($this->katalog() as $bezeichnung) {
            if (mb_stripos($name, $bezeichnung) !== false) {
                return $bezeichnung;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function katalog(): array
    {
        if ($this->katalog !== null) {
            return $this->katalog;
        }

        // Sehr kurze Bezeichnungen bleiben draussen: ein Katalogeintrag "PRP"
        // traefe sonst jeden Namen, in dem die drei Buchstaben zufaellig
        // vorkommen, und ein Hinweis, der immer erscheint, wird nicht gelesen.
        return $this->katalog = array_values(array_filter(
            Treatment::aktiveNamen(),
            fn (string $name): bool => mb_strlen($name) >= 4,
        ));
    }
}
