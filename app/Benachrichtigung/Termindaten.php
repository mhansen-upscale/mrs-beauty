<?php

declare(strict_types=1);

namespace App\Benachrichtigung;

use App\Benachrichtigung\Vorlagen\Textbaustein;
use App\Enums\NotificationKind;
use App\Enums\Platzhalter;
use App\Kalender\Termineinladung;
use App\Models\Appointment;
use App\Models\Location;
use App\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Was eine Terminmail ueber ihren Termin weiss -- fertige Zeichenketten.
 *
 * **Gebaut im Mandantenkontext** (B21): Appointment ist ein TenantModel, und
 * ein Arbeiter hat keinen Mandanten. Fuer Vorschau und Probemail gibt es
 * denselben Satz mit Beispielwerten.
 */
final class Termindaten
{
    /**
     * @param  array<string, string>  $werte  Platzhalter => Wert, roh
     */
    public function __construct(
        public readonly array $werte,
        public readonly string $zeitzeile,
        public readonly string $leistungzeile,
        public readonly string $ortzeile,
        public readonly ?string $antwortAn,
        public readonly ?string $kalender,
    ) {}

    public static function aus(Appointment $termin, NotificationKind $art, string $praxisname): self
    {
        $standort = $termin->location;
        $beginn = $standort->ortszeit($termin->starts_at);
        $ende = $standort->ortszeit($termin->ends_at);
        $kontakt = $termin->contact;

        $werte = [
            Platzhalter::Vorname->value => $kontakt->first_name,
            Platzhalter::Nachname->value => $kontakt->last_name,
            Platzhalter::Name->value => $kontakt->name(),
            Platzhalter::Praxis->value => $praxisname,
            Platzhalter::Tag->value => $beginn->translatedFormat('j. F'),
            Platzhalter::Datum->value => $beginn->format('d.m.Y'),
            Platzhalter::Uhrzeit->value => $beginn->format('H:i'),
            Platzhalter::Standort->value => $standort->name,
            Platzhalter::Behandler->value => $termin->practitioner->name(),
            Platzhalter::Behandlung->value => $termin->appointmentType->name,
        ];

        return new self(
            werte: $werte,
            zeitzeile: self::zeitzeile($beginn, $ende),
            leistungzeile: Textbaustein::maskiere($termin->appointmentType->name.' bei '.$termin->practitioner->name()),
            ortzeile: Textbaustein::maskiere(self::anschrift($standort)),
            // Wer auf eine Terminerinnerung antwortet, will die Praxis
            // erreichen, nicht uns.
            antwortAn: is_string($standort->email) && $standort->email !== '' ? $standort->email : null,
            // Bestaetigung, Verschiebung und Absage tragen eine Kalenderdatei
            // (WP-14). Dieselbe UID ueber alle drei -- nur so ersetzt die
            // Verschiebung den Eintrag und die Absage entfernt ihn.
            kalender: Termineinladung::gehoertDazu($art) ? Termineinladung::fuer($termin, $praxisname, $art) : null,
        );
    }

    /**
     * Ein Termin, den es nicht gibt -- fuer Vorschau und Probemail.
     *
     * **Mit dem ersten Standort der Praxis**, wenn es einen gibt: die Vorschau
     * soll aussehen wie die echte Mail. Name, Terminart und Behandler/in sind
     * erkennbar Beispiele.
     */
    public static function beispiel(Organization $praxis): self
    {
        $standort = Location::query()->orderBy('name')->first();

        $beginn = CarbonImmutable::now((string) config('mrs.business_timezone'))->addWeekday()->setTime(10, 0);
        $ende = $beginn->addMinutes(30);

        $werte = [];

        foreach (Platzhalter::cases() as $platzhalter) {
            $werte[$platzhalter->value] = $platzhalter->beispiel();
        }

        $werte[Platzhalter::Praxis->value] = $praxis->name;
        $werte[Platzhalter::Tag->value] = $beginn->translatedFormat('j. F');
        $werte[Platzhalter::Datum->value] = $beginn->format('d.m.Y');

        if ($standort instanceof Location) {
            $werte[Platzhalter::Standort->value] = $standort->name;
        }

        return new self(
            werte: $werte,
            zeitzeile: self::zeitzeile($beginn, $ende),
            leistungzeile: Textbaustein::maskiere(Platzhalter::Behandlung->beispiel().' bei '.Platzhalter::Behandler->beispiel()),
            ortzeile: Textbaustein::maskiere($standort instanceof Location ? self::anschrift($standort) : Platzhalter::Standort->beispiel()),
            antwortAn: $standort instanceof Location && is_string($standort->email) && $standort->email !== '' ? $standort->email : null,
            kalender: null,
        );
    }

    private static function zeitzeile(CarbonImmutable $beginn, CarbonImmutable $ende): string
    {
        return '**'.$beginn->translatedFormat('l, j. F Y').', '.$beginn->format('H:i').'–'.$ende->format('H:i').' Uhr**';
    }

    private static function anschrift(Location $standort): string
    {
        $zeile = array_filter([$standort->street, trim(($standort->postal_code ?? '').' '.($standort->city ?? ''))]);

        return $zeile === [] ? $standort->name : $standort->name.', '.implode(', ', $zeile);
    }
}
