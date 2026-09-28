<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Models\ComplianceCheck;
use App\Models\MailTemplate;
use App\Models\Treatment;
use App\Models\WhatsAppTemplate;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Die beiden Pruefgegenstaende, die bis zum 26.09.2026 fehlten (WP-30):
 * **die Buchungsseite** und **das WhatsApp-Template**.
 *
 * **Die Buchungsseite ist Publikumswerbung.** Beschreibung und Preis einer
 * Behandlung erscheinen dort nur, wenn die Pruefung sie freigibt -- gruen oder
 * rot mit begruendeter Uebersteuerung (C3), wie eine Anzeige. Gelb allein
 * genuegt nicht. Das war die Bedingung aus WP-12: "Preis und
 * Behandlungsbeschreibung bleiben aus, bis WP-30 sie pruefen kann."
 *
 * **Ein Template ist schon bei Meta genehmigt.** Die Ampel ist dort ein
 * Hinweis im Posteingang, keine Sperre -- Meta prueft keine HWG-Fragen, und
 * wer ein Marketing-Template verschickt, soll vorher sehen, was auffaellt.
 *
 * **Die Mailvorlage** (WP-36) pruefen wir wie das Template: als Hinweis.
 *
 * **Kein Regelwerk, keine Freigabe** -- dieselbe Richtung wie in Pruefung.
 */
final class Veroeffentlichungspruefung
{
    public function __construct(private readonly Pruefung $pruefung) {}

    /** Prueft, was die Buchungsseite zu dieser Behandlung zeigen wuerde. */
    public function behandlung(Treatment $behandlung): ?ComplianceCheck
    {
        $text = $behandlung->oeffentlicherText();

        if ($text === '') {
            return null;
        }

        return $this->halteFest($behandlung, new Pruefgegenstand(text: $text, modell: $behandlung));
    }

    public function template(WhatsAppTemplate $template): ?ComplianceCheck
    {
        $text = trim((string) $template->body);

        if ($text === '') {
            return null;
        }

        return $this->halteFest($template, new Pruefgegenstand(text: $text, modell: $template));
    }

    /**
     * Die Mailvorlage einer Praxis (WP-36, P12) -- **ein Hinweis, keine
     * Sperre**, wie beim WhatsApp-Template. Eine Terminmail ist keine
     * Publikumswerbung; wer in die Einleitung trotzdem ein Heilversprechen
     * schreibt, soll es vorher sehen.
     */
    public function mailvorlage(MailTemplate $vorlage): ?ComplianceCheck
    {
        $text = $vorlage->pruefbarerText();

        if ($text === '') {
            return null;
        }

        return $this->halteFest($vorlage, new Pruefgegenstand(text: $text, modell: $vorlage));
    }

    /**
     * Darf die Buchungsseite Beschreibung und Preis zeigen?
     *
     * **Die Pruefung muss dem jetzigen Text gelten.** Wer ihn am Katalog
     * vorbei aendert, bekommt kein Gruen geschenkt, das einem anderen Text
     * galt.
     */
    public function freigegeben(Treatment $behandlung): bool
    {
        $text = $behandlung->oeffentlicherText();

        if ($text === '') {
            return false;
        }

        $pruefung = $behandlung->pruefung()->first();

        return $pruefung instanceof ComplianceCheck
            && $pruefung->content_hash === Pruefung::fingerabdruck(new Pruefgegenstand($text))
            && $pruefung->gibtFrei();
    }

    private function halteFest(Model $gegenstand, Pruefgegenstand $inhalt): ?ComplianceCheck
    {
        try {
            return $this->pruefung->pruefeUndHalteFest($gegenstand, $inhalt);
        } catch (RuntimeException) {
            // Kein geltendes Regelwerk: dann gibt es auch keine Freigabe.
            return null;
        }
    }
}
