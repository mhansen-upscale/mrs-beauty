<?php

declare(strict_types=1);

namespace App\Warteliste;

use App\Verfuegbarkeit\Slotvorschlag;

/**
 * Die Saetze der Warteliste -- **aus dem Produkt**, wie beim Buchungsdialog.
 *
 * Ein Angebot ausserhalb des Service-Fensters geht als WhatsApp-Template
 * hinaus, und ein Template ist ohnehin fester Text: **formuliert so, dass
 * Meta es als `utility` einordnet und nicht als `marketing`** -- Utility ist
 * deutlich guenstiger. Ob die Einordnung gelingt, entscheidet Meta anhand der
 * Formulierung; das ist ein Versuch-und-Irrtum-Vorgang bei der Einreichung
 * (docs/fachlogik/warteliste.md, Abschnitt Kosten).
 *
 * Deshalb: sachlich, terminbezogen, ohne Werbung. Kein "Sichern Sie sich",
 * kein Ausrufezeichen, kein Rabatt.
 */
final class Angebotstexte
{
    public function angebot(Slotvorschlag $slot, string $praxisname): string
    {
        return 'Es ist ein Termin frei geworden: '.$this->zeitpunkt($slot).' bei '.$praxisname.'. '
            .'Möchten Sie ihn haben? Antworten Sie mit Ja, dann gehört er Ihnen. '
            .'Das Angebot gilt '.(int) config('mrs.waitlist.offer_ttl_minutes', 30).' Minuten.';
    }

    public function angenommen(Slotvorschlag $slot): string
    {
        return 'Der Termin gehört Ihnen: '.$this->zeitpunkt($slot).'. Sie bekommen die Bestätigung gleich schriftlich.';
    }

    public function zuSpaet(): string
    {
        // Grenzfall aus der Spezifikation: klare Meldung, kein Fehler -- und
        // der Eintrag bleibt aktiv.
        return 'Der Termin ist inzwischen vergeben. Sie bleiben auf der Warteliste, '
            .'und wir melden uns beim nächsten freien Termin.';
    }

    public function geprueftWird(Slotvorschlag $slot): string
    {
        // Ausloeser 3: der Slot ist noch belegt. Eine Zusage darf hier nichts
        // aufloesen -- das entscheidet ein Mensch.
        return 'Danke! Der Termin am '.$this->zeitpunkt($slot).' ist noch nicht ganz sicher frei. '
            .'Wir klären das und melden uns gleich bei Ihnen.';
    }

    public function dochVergeben(Slotvorschlag $slot): string
    {
        // Ausloeser 3, zweiter Ausgang: der bestehende Termin bleibt. Die
        // Person wartet weiter -- das muss sie erfahren, sonst haelt sie die
        // Warteliste fuer erledigt.
        return 'Der Termin am '.$this->zeitpunkt($slot).' bleibt vergeben — das tut uns leid. '
            .'Sie bleiben auf der Warteliste, und wir melden uns beim nächsten freien Termin.';
    }

    private function zeitpunkt(Slotvorschlag $slot): string
    {
        $ortszeit = $slot->ortszeit();

        $tage = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

        return $tage[$ortszeit->dayOfWeekIso - 1].', '.$ortszeit->format('d.m.').' um '.$ortszeit->format('H:i').' Uhr';
    }
}
