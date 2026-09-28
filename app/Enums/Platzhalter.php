<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Was eine Vorlage in geschweiften Klammern nennen darf.
 *
 * **Kein Platzhalter traegt Nachrichteninhalt, Code oder Link** (C17). Was
 * eine Mail nicht tragen darf, laesst sich so gar nicht erst hineinschreiben.
 * Welcher Platzhalter wo erlaubt ist, sagt die Mailart.
 */
enum Platzhalter: string
{
    case Vorname = 'vorname';
    case Nachname = 'nachname';
    case Name = 'name';
    case Praxis = 'praxis';
    case Tag = 'tag';
    case Datum = 'datum';
    case Uhrzeit = 'uhrzeit';
    case Standort = 'standort';
    case Behandler = 'behandler';
    case Behandlung = 'behandlung';
    case Produkt = 'produkt';
    case Rolle = 'rolle';
    case Frist = 'frist';
    case Minuten = 'minuten';
    case Grund = 'grund';

    public function label(): string
    {
        return match ($this) {
            self::Vorname => 'Vorname',
            self::Nachname => 'Nachname',
            self::Name => 'Name',
            self::Praxis => 'Name der Praxis',
            self::Tag => 'Tag (17. September)',
            self::Datum => 'Datum (17.09.2027)',
            self::Uhrzeit => 'Uhrzeit',
            self::Standort => 'Standort',
            self::Behandler => 'Behandler/in',
            self::Behandlung => 'Terminart',
            self::Produkt => 'Name des Produkts',
            self::Rolle => 'Rolle im Team',
            self::Frist => 'Gültig bis',
            self::Minuten => 'Gültigkeit in Minuten',
            self::Grund => 'Grund der Übergabe',
        };
    }

    /** Fuer Vorschau und Probemail -- erkennbar ein Beispiel. */
    public function beispiel(): string
    {
        return match ($this) {
            self::Vorname => 'Erika',
            self::Nachname => 'Musterfrau',
            self::Name => 'Erika Musterfrau',
            self::Praxis => 'Praxis am Markt',
            self::Tag => '17. September',
            self::Datum => '17.09.2027',
            self::Uhrzeit => '10:00',
            self::Standort => 'Standort Mitte',
            self::Behandler => 'Dr. Anna Beispiel',
            self::Behandlung => 'Erstberatung',
            self::Produkt => (string) config('app.name'),
            self::Rolle => 'Empfang',
            self::Frist => '01.10.2027, 18:00',
            self::Minuten => '60',
            self::Grund => 'Beschwerde',
        };
    }

    public function schreibweise(): string
    {
        return '{'.$this->value.'}';
    }
}
