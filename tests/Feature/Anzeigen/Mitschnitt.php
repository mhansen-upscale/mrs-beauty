<?php

declare(strict_types=1);

namespace Tests\Feature\Anzeigen;

use App\Agent\Anfrage;

/**
 * Was im Test nach aussen ging.
 *
 * Eine Ablage statt einer Closure-Variablen: Pest teilt Hilfsfunktionen ueber
 * alle Dateien, und eine statische Eigenschaft laesst sich aus einer
 * anonymen Klasse heraus setzen.
 */
final class Mitschnitt
{
    public static ?Anfrage $letzteAnfrage = null;

    /**
     * Die Bildauftraege des letzten Laufs, je Format (WP-31b).
     *
     * @var array<string, string>
     */
    public static array $bildauftraege = [];
}
