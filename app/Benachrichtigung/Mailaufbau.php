<?php

declare(strict_types=1);

namespace App\Benachrichtigung;

use App\Benachrichtigung\Vorlagen\Festblock;
use App\Benachrichtigung\Vorlagen\Mailinhalt;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Der eine Zusammenbau fuer jede Mail: Rahmen, Farbe, Text, fester Kern.
 *
 * **Die Vorlage schreibt davor und danach, nie hinein** (C17): Einleitung,
 * dann der feste Block mit Schaltflaeche, dann der Schluss. Zeilen vor der
 * Schaltflaeche landen in introLines, danach in outroLines -- wie bei
 * MailMessage immer.
 *
 * Das Theme `mrs` (resources/views/vendor/mail/html/themes/mrs.blade.php)
 * setzt die Farbe der Marke; CssToInlineStyles schreibt sie inline, weil
 * Mailprogramme kein <style> verlaesslich lesen.
 */
final class Mailaufbau
{
    public static function baue(Mailmarke $marke, Mailinhalt $inhalt, Festblock $block = new Festblock): MailMessage
    {
        $nachricht = (new MailMessage)
            ->markdown('mail.rahmen', ['marke' => $marke])
            ->theme('mrs')
            ->subject($inhalt->betreff);

        if ($inhalt->anrede !== '') {
            $nachricht->greeting($inhalt->anrede);
        }

        foreach ([...$inhalt->einleitung, ...$block->vorher] as $zeile) {
            $nachricht->line($zeile);
        }

        if ($block->schaltflaeche !== null && $block->ziel !== null) {
            $nachricht->action($block->schaltflaeche, $block->ziel);
        }

        foreach ([...$block->nachher, ...$inhalt->schluss] as $zeile) {
            $nachricht->line($zeile);
        }

        if ($inhalt->gruss !== '') {
            $nachricht->salutation($inhalt->gruss);
        }

        return $nachricht;
    }
}
