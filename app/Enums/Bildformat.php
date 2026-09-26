<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Die Formate einer Anzeigengrafik (WP-31b, Entscheidung C13).
 *
 * **Jede Anzeige in jedem Format.** Meta spielt dieselbe Anzeige im Feed, in
 * Stories und in der rechten Spalte aus; ein Quadrat erscheint in der Story
 * als Streifen in der Bildmitte. Welche Platzierung welches Format bekommt,
 * steht in `mrs.ads.formate`, wie das Bildmodell es heisst, in
 * `services.kie.formate`.
 *
 * Die Reihenfolge ist die der Oberflaeche und des Hochladens. **Das Quadrat
 * steht vorn**: es ist das Auffangformat und das Bild auf der Kachel.
 */
enum Bildformat: string
{
    case Quadrat = '1x1';

    case Hochformat = '4x5';

    case Story = '9x16';

    public function label(): string
    {
        return match ($this) {
            self::Quadrat => 'Quadratisch',
            self::Hochformat => 'Hochformat',
            self::Story => 'Stories',
        };
    }

    /** Das Seitenverhaeltnis, wie Menschen und Meta es schreiben. */
    public function seitenverhaeltnis(): string
    {
        return str_replace('x', ':', $this->value);
    }

    /** "Stories (9:16)" -- fuer Meldungen, die sagen, welches fehlt. */
    public function beschreibung(): string
    {
        return $this->label().' ('.$this->seitenverhaeltnis().')';
    }

    /** Wo Meta dieses Format zeigt -- in Worten der Praxis. */
    public function einsatz(): string
    {
        return match ($this) {
            self::Quadrat => 'Rechte Spalte, Marketplace, Suche und alles Übrige',
            self::Hochformat => 'Feed auf Facebook und Instagram',
            self::Story => 'Stories auf Facebook, Instagram und im Messenger',
        };
    }
}
