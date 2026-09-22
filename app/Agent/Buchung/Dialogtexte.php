<?php

declare(strict_types=1);

namespace App\Agent\Buchung;

use App\Models\Location;
use App\Models\Treatment;
use App\Verfuegbarkeit\Slotvorschlag;

/**
 * Die Saetze des Buchungsdialogs -- **aus dem Produkt, nicht aus dem Modell**.
 *
 * Das ist die tragende Entscheidung von WP-24. Ein Modell, das hier frei
 * formuliert, kann einen Termin zusagen, den es nicht gibt, einen Preis
 * nennen, den niemand hinterlegt hat, oder eine Behandlung erfinden. Die
 * Nachpruefung aus WP-23 finge das ab -- aber jede abgefangene Antwort ist
 * ein abgebrochener Dialog, und ein Automat, der zu zwei Dritteln eskaliert,
 * ist keiner.
 *
 * Was der Agent im Buchungsdialog sagt, hat also ein Mensch geschrieben --
 * nur einmal, hier, statt jedes Mal neu.
 */
final class Dialogtexte
{
    /**
     * @param  array<int, Treatment>  $behandlungen
     */
    public function behandlungFragen(array $behandlungen): string
    {
        $liste = implode("\n", array_map(
            fn (Treatment $behandlung): string => '· '.$behandlung->name,
            $behandlungen,
        ));

        return "Gern vereinbaren wir einen Termin. Worum soll es gehen?\n\n".$liste;
    }

    /**
     * @param  array<int, Location>  $standorte
     */
    public function standortFragen(array $standorte): string
    {
        $liste = implode("\n", array_map(
            fn (Location $standort): string => '· '.$standort->name.($standort->city === null ? '' : ', '.$standort->city),
            $standorte,
        ));

        return "An welchem Standort passt es Ihnen?\n\n".$liste;
    }

    /**
     * @param  array<int, Slotvorschlag>  $vorschlaege
     */
    public function slotsVorschlagen(array $vorschlaege): string
    {
        $liste = [];

        foreach ($vorschlaege as $stelle => $vorschlag) {
            $liste[] = ($stelle + 1).'. '.$this->zeitpunkt($vorschlag);
        }

        return "Diese Termine sind frei:\n\n".implode("\n", $liste)
            ."\n\nPasst einer davon? Antworten Sie gern mit der Nummer.";
    }

    public function keineSlots(): string
    {
        // **Keine Sackgasse** (Schritt 6): nicht abbrechen, sondern anbieten.
        // Das Wartelistenangebot kommt mit WP-25; bis dahin uebernimmt ein
        // Mensch, und das sagen wir auch.
        return 'Im gewünschten Zeitraum ist gerade nichts frei. Jemand aus dem Team meldet sich bei Ihnen '
            .'und findet einen Termin für Sie.';
    }

    public function nameFragen(): string
    {
        return 'Wie ist Ihr Name? Dann trage ich den Termin ein.';
    }

    public function einwilligungFragen(): string
    {
        return 'Dürfen wir Ihnen über diesen Kanal Terminnachrichten schicken — Bestätigung, Erinnerung, '
            .'Änderungen? Antworten Sie mit Ja, dann buche ich den Termin.';
    }

    public function bestaetigungFragen(Slotvorschlag $vorschlag, string $name): string
    {
        return 'Ich fasse zusammen: '.$vorschlag->art->name.' am '.$this->zeitpunkt($vorschlag)
            .' für '.$name.'. Soll ich den Termin so eintragen?';
    }

    public function gebucht(Slotvorschlag $vorschlag): string
    {
        return 'Der Termin steht: '.$vorschlag->art->name.' am '.$this->zeitpunkt($vorschlag)
            .'. Sie bekommen die Bestätigung gleich noch einmal schriftlich. Bis dahin!';
    }

    public function bereitsGebucht(Slotvorschlag $vorschlag): string
    {
        // Entscheidung G9: ein zweiter Versuch fuehrt zur Rueckfrage, nicht
        // zu einem zweiten Termin.
        return 'Sie haben bereits einen Termin am '.$this->zeitpunkt($vorschlag)
            .'. Soll der bestehen bleiben, oder möchten Sie ihn ändern? Ich gebe das an unser Team weiter.';
    }

    public function nichtVerstanden(): string
    {
        return 'Das habe ich nicht sicher verstanden — können Sie es noch einmal anders sagen?';
    }

    /** Die Ortszeit des Standorts, nicht die des Servers. */
    public function zeitpunkt(Slotvorschlag $vorschlag): string
    {
        $ortszeit = $vorschlag->ortszeit();

        $tage = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

        return $tage[$ortszeit->dayOfWeekIso - 1].', '.$ortszeit->format('d.m.').' um '.$ortszeit->format('H:i').' Uhr';
    }
}
