<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Die Rollen im Team des Betreibers (WP-34a, Entscheidung C14).
 *
 * **Abgeleitet, zu bestaetigen** -- wie der Rollenkatalog der Praxen in
 * WP-04. Die Rollen selbst sind entschieden, die Zuordnung der Faehigkeiten
 * stammt aus dem Gespraech vom 27.09.2026.
 *
 * Gespeichert in `users.operator_role` als VARCHAR (Entscheidung A11). Ein
 * Konto mit Betreiberrolle hat weder Organisation noch Praxisrolle -- das
 * setzt ein Trigger in der Datenbank durch, nicht die Disziplin am
 * Aufrufort.
 */
enum OperatorRole: string
{
    case SuperAdmin = 'super_admin';
    case CustomerSuccess = 'customer_success';
    case Finanzen = 'finanzen';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super-Admin',
            self::CustomerSuccess => 'Customer Success',
            self::Finanzen => 'Finanzen',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Alles, einschließlich Sperren, Abo-Eingriffen, Paket, Mailversand und Betreiberkonten.',
            self::CustomerSuccess => 'Praxen und Abos sehen, Kontingent gutschreiben, Testphase verlängern, mit Freigabe in eine Praxis sehen.',
            self::Finanzen => 'Praxen, Abos, Umsatz und Kosten sehen. Nie in eine Praxis.',
        };
    }

    /**
     * @return list<OperatorAbility>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::SuperAdmin => OperatorAbility::cases(),

            self::CustomerSuccess => [
                OperatorAbility::MandantenSehen,
                OperatorAbility::AboSehen,
                OperatorAbility::KontingentGutschreiben,
                OperatorAbility::TestphaseVerlaengern,
                OperatorAbility::SupportZugriff,
            ],

            // **Finanzen kommt nie in eine Praxis.** Wer Umsaetze auswertet,
            // braucht keinen Blick in einen Posteingang.
            self::Finanzen => [
                OperatorAbility::MandantenSehen,
                OperatorAbility::AboSehen,
                OperatorAbility::FinanzenSehen,
            ],
        };
    }

    public function allows(OperatorAbility $faehigkeit): bool
    {
        return in_array($faehigkeit, $this->abilities(), true);
    }
}
