<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Was jemand im Produkt tun darf.
 *
 * Bewusst eine abgeschlossene Aufzaehlung und keine Tabelle: eine Praxis mit
 * fuenf Personen braucht keinen Rechteeditor, und ein solcher Editor ist der
 * zuverlaessigste Weg, versehentlich zu viel zu vergeben.
 *
 * Jeder Fall wird in App\Providers\AuthServiceProvider als Gate registriert.
 * Ein neuer Fall ohne Zuordnung zu einer Rolle laesst einen Test fehlschlagen
 * -- vergessene Rechte sollen auffallen, nicht still wirken.
 */
enum Ability: string
{
    // Organisation und Betrieb
    case ManageBilling = 'billing.manage';
    case ManageOrganization = 'organization.manage';
    case ManageWhitelabel = 'whitelabel.manage';
    case ManageTeam = 'team.manage';
    case ViewAuditLog = 'audit.view';
    case ApproveImpersonation = 'impersonation.approve';

    // Stammdaten und Katalog
    case ManageMasterData = 'masterdata.manage';
    case ManageCatalog = 'catalog.manage';

    // Termine
    case ManageAppointments = 'appointments.manage';
    case ViewOwnCalendar = 'calendar.own.view';
    case ManageWaitlist = 'waitlist.manage';

    // Kommunikation
    case ViewInbox = 'inbox.view';
    case ReplyInbox = 'inbox.reply';
    case ManageContacts = 'contacts.manage';
    case ManageAgent = 'agent.manage';

    // Wachstum
    case ManageCampaigns = 'campaigns.manage';
    case ViewInsights = 'insights.view';
    case ManageBrandGuide = 'brandguide.manage';

    public function label(): string
    {
        return match ($this) {
            self::ManageBilling => 'Abo und Abrechnung verwalten',
            self::ManageOrganization => 'Organisationseinstellungen verwalten',
            self::ManageWhitelabel => 'Erscheinungsbild verwalten',
            self::ManageTeam => 'Team verwalten',
            self::ViewAuditLog => 'Protokoll einsehen',
            self::ApproveImpersonation => 'Vollzugriff des Supports freigeben',
            self::ManageMasterData => 'Praxisstammdaten verwalten',
            self::ManageCatalog => 'Leistungskatalog verwalten',
            self::ManageAppointments => 'Termine verwalten',
            self::ViewOwnCalendar => 'Eigenen Kalender sehen',
            self::ManageWaitlist => 'Warteliste verwalten',
            self::ViewInbox => 'Inbox sehen',
            self::ReplyInbox => 'In der Inbox antworten',
            self::ManageContacts => 'Kontakte verwalten',
            self::ManageAgent => 'Agent einstellen',
            self::ManageCampaigns => 'Kampagnen verwalten',
            self::ViewInsights => 'Auswertung sehen',
            self::ManageBrandGuide => 'Brand Guide verwalten',
        };
    }
}
