<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Die Teile einer Mail, die eine Vorlage aendern darf (Entscheidung P12).
 *
 * Alles andere -- Eckdaten, Schaltflaeche, Link, Frist, Code -- ist fester
 * Kern und steht in keinem Feld (C17). Der Wert ist der Spaltenname in
 * `mail_templates` und `platform_mail_templates`.
 */
enum Mailfeld: string
{
    case Betreff = 'subject';
    case Anrede = 'greeting';
    case Einleitung = 'intro';
    case Schluss = 'outro';
    case Gruss = 'salutation';

    public function label(): string
    {
        return match ($this) {
            self::Betreff => 'Betreff',
            self::Anrede => 'Anrede',
            self::Einleitung => 'Einleitung',
            self::Schluss => 'Schluss',
            self::Gruss => 'Gruß',
        };
    }

    /** Einzeilig: Betreff, Anrede und Gruss stehen jeweils in einer Zeile. */
    public function istEinzeilig(): bool
    {
        return $this !== self::Einleitung && $this !== self::Schluss;
    }

    /** Die Obergrenze aus config/mrs.php. */
    public function hoechstlaenge(): int
    {
        return (int) config('mrs.mail.laenge.'.($this->istEinzeilig() ? $this->value : 'text'));
    }
}
