<?php

declare(strict_types=1);

namespace App\Agent\Buchung;

use App\Models\AppointmentType;
use App\Models\Location;
use App\Models\Practitioner;
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

    /**
     * Uebergabe ohne Termin -- wenn die Warteliste abgelehnt wurde oder
     * keine Terminart feststeht.
     */
    public function keineSlots(): string
    {
        return 'Im gewünschten Zeitraum ist gerade nichts frei. Jemand aus dem Team meldet sich bei Ihnen '
            .'und findet einen Termin für Sie.';
    }

    /**
     * **Keine Sackgasse** (Schritt 6, Testfall 14): nicht abbrechen, sondern
     * die Warteliste anbieten.
     *
     * Die Frage ist zugleich die nach dem Kanal (K11): ein Eintrag ohne
     * Einwilligung bekaeme nie ein Angebot. Deshalb nennt sie, was hierueber
     * kommt, und nicht nur, dass es eine Liste gibt.
     */
    public function wartelisteAnbieten(AppointmentType $art): string
    {
        return 'In den nächsten Wochen ist für '.$art->name.' leider nichts frei. Soll ich Sie auf unsere '
            .'Warteliste setzen? Wird ein Termin frei, schicken wir Ihnen hier ein Angebot — Sie entscheiden '
            .'dann, ob er passt. Antworten Sie mit Ja, dann trage ich Sie ein.';
    }

    public function nameFuerWarteliste(): string
    {
        return 'Gern. Wie ist Ihr Name? Dann trage ich Sie ein.';
    }

    public function aufWarteliste(AppointmentType $art): string
    {
        return 'Sie stehen auf der Warteliste für '.$art->name.'. Sobald ein Termin frei wird, melden wir '
            .'uns hier bei Ihnen.';
    }

    /**
     * Wer genannt wurde, laesst sich keinem zuordnen -- also fragen, nicht
     * raten (Schritt 6, behandler_klaeren).
     *
     * @param  list<Practitioner>  $behandler
     */
    public function behandlerFragen(array $behandler): string
    {
        $namen = array_map(fn (Practitioner $person): string => '- '.$person->name(), $behandler);

        return "Wen meinen Sie? Bei uns behandeln:\n\n".implode("\n", $namen)
            ."\n\nSchreiben Sie gern den Namen — oder „egal“, dann schlage ich vor, was frei ist.";
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

    /* Ein Termin, der schon steht (offen seit WP-24) ------------------------- */

    public function absageFragen(Slotvorschlag $termin): string
    {
        return 'Soll ich Ihren Termin am '.$this->zeitpunkt($termin).' ('.$termin->art->name.') absagen? '
            .'Antworten Sie mit Ja, dann trage ich die Absage ein.';
    }

    public function abgesagt(Slotvorschlag $termin): string
    {
        return 'Ihr Termin am '.$this->zeitpunkt($termin).' ist abgesagt. Wenn Sie einen neuen möchten, '
            .'schreiben Sie uns einfach.';
    }

    public function bleibtBestehen(Slotvorschlag $termin): string
    {
        return 'Gut, Ihr Termin am '.$this->zeitpunkt($termin).' bleibt bestehen.';
    }

    /**
     * @param  list<Slotvorschlag>  $vorschlaege
     */
    public function verschiebenVorschlagen(Slotvorschlag $termin, array $vorschlaege): string
    {
        return 'Ihr Termin ist bisher am '.$this->zeitpunkt($termin).'. '.$this->slotsVorschlagen($vorschlaege);
    }

    public function verschiebenFragen(Slotvorschlag $alt, Slotvorschlag $neu): string
    {
        return 'Soll ich Ihren Termin vom '.$this->zeitpunkt($alt).' auf '.$this->zeitpunkt($neu)
            .' verschieben? Antworten Sie mit Ja, dann trage ich es ein.';
    }

    public function verschoben(Slotvorschlag $neu): string
    {
        return 'Ihr Termin ist verschoben: '.$neu->art->name.' am '.$this->zeitpunkt($neu)
            .'. Sie bekommen die Bestätigung gleich noch einmal schriftlich.';
    }

    /** Die Person ist nicht bekannt -- wer absagen will, muss es sein. */
    public function wenMeinenSie(): string
    {
        return 'Damit ich den richtigen Termin finde, übernimmt jemand aus dem Team und meldet sich gleich bei Ihnen.';
    }

    public function keinTerminGefunden(): string
    {
        return 'Ich finde gerade keinen anstehenden Termin. Jemand aus dem Team schaut nach und meldet sich bei Ihnen.';
    }

    /** Zwei Termine: welcher gemeint ist, klaert ein Mensch -- raten waere eine Absage am falschen Tag. */
    public function mehrereTermine(): string
    {
        return 'Sie haben mehrere Termine bei uns. Damit es der richtige ist, übernimmt jemand aus dem Team '
            .'und meldet sich gleich bei Ihnen.';
    }

    public function keineAlternative(): string
    {
        return 'In den nächsten Wochen finde ich keinen anderen freien Termin. Jemand aus dem Team meldet sich '
            .'bei Ihnen und findet einen.';
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
