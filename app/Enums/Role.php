<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Die Rolle einer Person in ihrer Organisation.
 *
 * Als VARCHAR in users.role gespeichert, nicht als MySQL-ENUM
 * (Entscheidung A11): eine Statuserweiterung waere sonst ein ALTER TABLE.
 *
 * **Abgeleitet, zu pruefen** -- siehe specs/WP-04-benutzer-rollen-einladungen.md.
 */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Reception = 'reception';
    case Practitioner = 'practitioner';
    case Marketing = 'marketing';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Inhaberin',
            self::Admin => 'Verwaltung',
            self::Reception => 'Empfang',
            self::Practitioner => 'Behandlerin',
            self::Marketing => 'Marketing',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Alles, einschließlich Abo und Erscheinungsbild.',
            self::Admin => 'Stammdaten, Katalog, Team und Einstellungen.',
            self::Reception => 'Inbox, Termine, Warteliste und Kontakte.',
            self::Practitioner => 'Der eigene Kalender und die eigenen Termine.',
            self::Marketing => 'Kampagnen, Anzeigen, Auswertung und Brand Guide.',
        };
    }

    /**
     * @return list<Ability>
     */
    public function abilities(): array
    {
        return match ($this) {
            // Die Inhaberin hat alles. Ausdruecklich ueber Ability::cases(),
            // damit ein neuer Fall hier nicht vergessen werden kann.
            self::Owner => Ability::cases(),

            self::Admin => [
                Ability::ManageOrganization,
                Ability::ManageTeam,
                Ability::ViewAuditLog,
                Ability::ManageMasterData,
                Ability::ManageCatalog,
                Ability::ManageAppointments,
                Ability::ViewOwnCalendar,
                Ability::ManageWaitlist,
                Ability::ViewInbox,
                Ability::ReplyInbox,
                Ability::ManageContacts,
                Ability::ManageAgent,
                Ability::ViewInsights,
            ],

            self::Reception => [
                Ability::ManageAppointments,
                Ability::ViewOwnCalendar,
                Ability::ManageWaitlist,
                Ability::ViewInbox,
                Ability::ReplyInbox,
                Ability::ManageContacts,
            ],

            self::Practitioner => [
                Ability::ViewOwnCalendar,
                Ability::ViewInbox,
            ],

            self::Marketing => [
                Ability::ManageCampaigns,
                Ability::ViewInsights,
                Ability::ManageBrandGuide,
            ],
        };
    }

    public function allows(Ability $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }

    /**
     * Rollen, die beim Einladen und beim Aendern angeboten werden.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return self::cases();
    }
}
