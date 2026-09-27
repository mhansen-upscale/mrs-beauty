<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Was ein Betreiber im Backoffice tun darf (WP-34a, Entscheidung C14).
 *
 * **Getrennt von Ability.** Faehigkeiten einer Praxis haengen an einer Rolle
 * innerhalb dieser Praxis; der Betreiber gehoert zu keiner. Wer beides in
 * einer Aufzaehlung fuehrt, gibt irgendwann einer Empfangskraft das Sperren
 * fremder Praxen oder dem Betreiber den Posteingang.
 *
 * Die Zuordnung zu den Rollen steht in OperatorRole, nicht in der Datenbank:
 * eine Korrektur kostet eine Zeile.
 */
enum OperatorAbility: string
{
    case MandantenSehen = 'mandanten.sehen';
    case AboSehen = 'abo.sehen';
    case KontingentGutschreiben = 'kontingent.gutschreiben';
    case TestphaseVerlaengern = 'testphase.verlaengern';
    case SupportZugriff = 'support.zugriff';
    case MandantenSperren = 'mandanten.sperren';
    case AboEingreifen = 'abo.eingreifen';
    case PaketVerwalten = 'paket.verwalten';
    case FinanzenSehen = 'finanzen.sehen';
    case BetreiberVerwalten = 'betreiber.verwalten';
    case ProtokollSehen = 'protokoll.sehen';

    public function label(): string
    {
        return match ($this) {
            self::MandantenSehen => 'Praxen und ihre Betriebslage sehen',
            self::AboSehen => 'Abos und ihre Fristen sehen',
            self::KontingentGutschreiben => 'Kontingent gutschreiben',
            self::TestphaseVerlaengern => 'Testphase verlängern',
            self::SupportZugriff => 'In eine Praxis sehen (Impersonation)',
            self::MandantenSperren => 'Praxen sperren und entsperren',
            self::AboEingreifen => 'Abos pausieren, kündigen, Gratismonat',
            self::PaketVerwalten => 'Paket und Preise verwalten',
            self::FinanzenSehen => 'Umsatz und Kosten sehen',
            self::BetreiberVerwalten => 'Betreiberkonten verwalten',
            self::ProtokollSehen => 'Betreiberprotokoll sehen',
        };
    }
}
