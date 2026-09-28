<?php

declare(strict_types=1);

namespace App\Oeffentlich;

/**
 * Titel, Beschreibung und Adresse einer oeffentlichen Seite -- fuer das erste
 * HTML (WP-38).
 *
 * **Ohne SSR entsteht der Seiteninhalt im Browser.** Was eine Suchmaschine
 * oder eine Linkvorschau ohne Skript liest, steht deshalb im Kopf der
 * Blade-Vorlage, nicht in `<Head>`.
 *
 * **Aus `app.url`, nicht aus der Anfrage.** Eine Vorschauumgebung mit eigener
 * Adresse soll sich nicht als die kanonische ausgeben.
 */
final class Seitenmeta
{
    /**
     * @return array{titel: string, beschreibung: string, kanonisch: string, bild: string}
     */
    public static function fuer(string $titel, string $beschreibung, string $pfad): array
    {
        $basis = rtrim((string) config('app.url'), '/');

        return [
            // Wie der Titel im Browser (resources/js/app.ts), sonst springt
            // der Reiter beim Laden.
            'titel' => $titel.' · '.config('app.name'),
            'beschreibung' => $beschreibung,
            'kanonisch' => $basis.'/'.ltrim($pfad, '/'),
            'bild' => $basis.'/icon-512.png',
        ];
    }
}
