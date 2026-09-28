<?php

declare(strict_types=1);

namespace App\Benachrichtigung\Vorlagen;

use App\Enums\Mailart;
use App\Enums\Versandweg;
use App\Models\MailTemplate;
use App\Models\PlatformMailTemplate;
use LogicException;

/**
 * Welcher Text gilt: die Ueberschreibung, sonst der Standard (D15).
 *
 * **Die Praxis im Mandantenkontext**, bevor die Mail in die Warteschlange
 * geht (B21): MailTemplate ist ein TenantModel, und ein Arbeiter hat keinen
 * Mandanten. **Der Betreiber zur Versandzeit** -- seine Tabelle ist global,
 * und eine geaenderte Vorlage wirkt so ab der naechsten Mail.
 */
final class Mailvorlagen
{
    public function fuerPraxis(Mailart $art): Mailtext
    {
        $this->pruefeWeg($art, Versandweg::Praxis);

        $vorlage = MailTemplate::query()->where('template', $art->value)->first();

        return $vorlage instanceof MailTemplate ? $vorlage->text() : Standardtexte::fuer($art);
    }

    public function fuerPlattform(Mailart $art): Mailtext
    {
        $this->pruefeWeg($art, Versandweg::Plattform);

        $vorlage = PlatformMailTemplate::query()->where('template', $art->value)->first();

        return $vorlage instanceof PlatformMailTemplate ? $vorlage->text() : Standardtexte::fuer($art);
    }

    /**
     * Setzt eine Terminmail -- mit einem Betreff, der keine Behandlung nennt.
     *
     * **Beim Erzeugen noch einmal geprueft** (C17): Ein Katalog waechst, und
     * ein Betreff, der beim Speichern sauber war, kann heute eine Behandlung
     * nennen. Dann gilt der Standardbetreff -- der nennt nie eine.
     *
     * @param  array<string, string>  $werte
     */
    public function setzeTerminmail(Mailart $art, Mailtext $text, array $werte): Mailinhalt
    {
        $inhalt = Textbaustein::setze($text, $werte);

        if ((new Betreffpruefung)->treffer($inhalt->betreff) === null) {
            return $inhalt;
        }

        return Textbaustein::setze($text->mitBetreff(Standardtexte::fuer($art)->betreff), $werte);
    }

    private function pruefeWeg(Mailart $art, Versandweg $weg): void
    {
        if ($art->versandweg() !== $weg || ! $art->istVorlage()) {
            throw new LogicException("{$art->value} ist keine Vorlage des Wegs {$weg->value}.");
        }
    }
}
