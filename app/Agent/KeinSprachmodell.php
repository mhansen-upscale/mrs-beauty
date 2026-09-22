<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Die Vorgabe: **kein Modell angebunden**.
 *
 * Dieselbe Ueberlegung wie bei der Virenpruefung aus WP-18, nur mit
 * umgekehrtem Vorzeichen. Dort gibt die Vorgabe frei, damit die Funktion
 * benutzbar bleibt; hier tut sie **nichts**, denn ein Agent, der ohne
 * angebundenes Modell etwas vorschluege, haette es sich ausgedacht.
 *
 * Wer keinen Schluessel hinterlegt hat, bekommt keine Vorschlaege -- und
 * sieht das am Lauf, statt es zu erraten.
 */
final class KeinSprachmodell implements Sprachmodell
{
    public function frage(Anfrage $anfrage): Antwort
    {
        throw new ModellNichtErreichbar('no_model');
    }

    public function angebunden(): bool
    {
        return false;
    }
}
