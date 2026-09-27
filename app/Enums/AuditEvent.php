<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Was protokolliert wird.
 *
 * Abgeschlossene Aufzaehlung, kein Freitext: ein Protokoll, dessen
 * Ereignisnamen sich je Aufrufstelle unterscheiden, laesst sich nicht
 * auswerten -- und genau das braucht man, wenn es darauf ankommt.
 */
enum AuditEvent: string
{
    // Datensaetze
    case Created = 'record.created';
    case Updated = 'record.updated';
    case Deleted = 'record.deleted';

    // Mandantengrenze
    case CrossTenantAccess = 'tenant.cross_access';

    // Team (WP-04)
    case RoleChanged = 'member.role_changed';
    case MemberDeactivated = 'member.deactivated';
    case MemberReactivated = 'member.reactivated';
    case InvitationSent = 'invitation.sent';
    case InvitationAccepted = 'invitation.accepted';
    case InvitationRevoked = 'invitation.revoked';

    // Impersonation (WP-05)
    case ImpersonationStarted = 'impersonation.started';
    case ImpersonationApproved = 'impersonation.approved';
    case ImpersonationEnded = 'impersonation.ended';
    case ImpersonationExpired = 'impersonation.expired';

    // Schluessel (WP-03)
    /* Backoffice des Betreibers (WP-34) -- jede Handlung ueber die
       Mandantengrenze hinweg steht im Protokoll (Regel 1). */

    case TenantSuspended = 'tenant.suspended';

    case TenantUnsuspended = 'tenant.unsuspended';

    case TenantCredited = 'tenant.credited';

    /* Betreiberkonten (WP-34a). Ohne Organisation: sie gehoeren dem
       Betreiber, nicht einer Praxis. Ein Fehlversuch nennt das Konto, wenn es
       eines gibt -- nie die eingetippte Adresse (C5). */
    case OperatorLoggedIn = 'operator.logged_in';
    case OperatorLoginFailed = 'operator.login_failed';
    case OperatorLoggedOutIdle = 'operator.logged_out_idle';
    case OperatorCreated = 'operator.created';
    case OperatorRoleChanged = 'operator.role_changed';
    case OperatorDeactivated = 'operator.deactivated';
    case OperatorReactivated = 'operator.reactivated';

    /* Abo-Eingriffe (WP-34c, B17) -- beim Mandanten, damit die Praxis
       nachlesen kann, was mit ihrem Abo geschehen ist. */
    case SubscriptionChangeRequested = 'subscription.change_requested';
    case SubscriptionChangeFailed = 'subscription.change_failed';
    case SubscriptionTrialExtended = 'subscription.trial_extended';

    /* Das Paket in Fassungen (WP-06b, B20). Die Fassung gehoert dem
       Betreiber -- ohne Organisation. Der Wechsel eines Abos auf eine
       Fassung steht beim Mandanten. */
    case PlanVersionCreated = 'plan.version_created';
    case PlanVersionReady = 'plan.version_ready';
    case PlanVersionFailed = 'plan.version_failed';
    case PlanMigrationRequested = 'plan.migration_requested';
    case SubscriptionPlanChanged = 'subscription.plan_changed';
    case SubscriptionPlanChangeFailed = 'subscription.plan_change_failed';
    case SubscriptionPriceUnknown = 'subscription.price_unknown';

    case EncryptionKeyIssued = 'encryption_key.issued';
    case EncryptionKeyRevoked = 'encryption_key.revoked';

    /* Anhaenge (offen seit WP-21). Ein Foto aus dem Chat ist ein
       Gesundheitsdatum nach Artikel 9 DSGVO -- wer es oeffnet, steht hier. */
    case AttachmentOpened = 'attachment.opened';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Datensatz angelegt',
            self::Updated => 'Datensatz geändert',
            self::Deleted => 'Datensatz gelöscht',
            self::CrossTenantAccess => 'Zugriff quer zu den Mandanten',
            self::RoleChanged => 'Rolle geändert',
            self::MemberDeactivated => 'Zugang deaktiviert',
            self::MemberReactivated => 'Zugang reaktiviert',
            self::InvitationSent => 'Einladung verschickt',
            self::InvitationAccepted => 'Einladung angenommen',
            self::InvitationRevoked => 'Einladung zurückgenommen',
            self::ImpersonationStarted => 'Impersonation gestartet',
            self::ImpersonationApproved => 'Vollzugriff freigegeben',
            self::ImpersonationEnded => 'Impersonation beendet',
            self::ImpersonationExpired => 'Impersonation abgelaufen',
            self::TenantSuspended => 'Mandant gesperrt',
            self::TenantUnsuspended => 'Mandant entsperrt',
            self::TenantCredited => 'Kontingent gutgeschrieben',
            self::OperatorLoggedIn => 'Betreiber angemeldet',
            self::OperatorLoginFailed => 'Anmeldung als Betreiber fehlgeschlagen',
            self::OperatorLoggedOutIdle => 'Betreiber nach Leerlauf abgemeldet',
            self::OperatorCreated => 'Betreiberkonto angelegt',
            self::OperatorRoleChanged => 'Betreiberrolle geändert',
            self::OperatorDeactivated => 'Betreiberkonto deaktiviert',
            self::OperatorReactivated => 'Betreiberkonto reaktiviert',
            self::SubscriptionChangeRequested => 'Abo-Eingriff beauftragt',
            self::SubscriptionChangeFailed => 'Abo-Eingriff gescheitert',
            self::SubscriptionTrialExtended => 'Testphase verlängert',
            self::PlanVersionCreated => 'Paketfassung angelegt',
            self::PlanVersionReady => 'Paketfassung gilt',
            self::PlanVersionFailed => 'Paketfassung gescheitert',
            self::PlanMigrationRequested => 'Bestand auf neue Paketfassung umgestellt',
            self::SubscriptionPlanChanged => 'Abo auf neue Paketfassung gewechselt',
            self::SubscriptionPlanChangeFailed => 'Umstellung auf neue Paketfassung gescheitert',
            self::SubscriptionPriceUnknown => 'Stripe meldet einen Preis ohne Paketfassung',
            self::EncryptionKeyIssued => 'Schlüsselsatz angelegt',
            self::EncryptionKeyRevoked => 'Schlüssel widerrufen',
            self::AttachmentOpened => 'Anhang geöffnet',
        };
    }
}
