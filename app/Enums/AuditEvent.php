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
    case ImpersonationEndedByTenant = 'impersonation.ended_by_tenant';

    /* Freigabe per Einmal-PIN (WP-34b, C15). **Nie mit der PIN** -- weder
       der erzeugten noch der eingetippten (C5). */
    case SupportPinCreated = 'support_pin.created';
    case SupportPinRevoked = 'support_pin.revoked';
    case SupportPinFailed = 'support_pin.failed';
    case SupportPinBurned = 'support_pin.burned';
    case SupportPinRedeemed = 'support_pin.redeemed';

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
    case OperatorPasswordSet = 'operator.password_set';

    /* Endgueltig (Nachtrag 28.09.2026). Der Eintrag ist zugleich das, was
       vom Konto bleibt: das Betreiberprotokoll findet ueber ihn die Handlungen
       einer Person, die es in users nicht mehr gibt. */
    case OperatorDeleted = 'operator.deleted';

    /* Der zweite Faktor (WP-35, C16). Bei Praxispersonen im Protokoll der
       Praxis, bei Betreibern ohne Organisation. Im Kontext hoechstens das
       Verfahren oder die Zahl der uebrigen Codes -- nie das Geheimnis, nie
       ein Code (C5). Ein einzelner falscher Code steht nicht hier, erst die
       verworfene Anmeldung; beim Betreiber zaehlt jeder als
       OperatorLoginFailed. */
    case TwoFactorEnabled = 'two_factor.enabled';
    case TwoFactorDisabled = 'two_factor.disabled';
    case TwoFactorReset = 'two_factor.reset';
    case TwoFactorRecoveryCodesRenewed = 'two_factor.recovery_codes_renewed';
    case TwoFactorRecoveryCodeUsed = 'two_factor.recovery_code_used';
    case TwoFactorChallengeLocked = 'two_factor.challenge_locked';

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

    /* Der Versand der Plattform und die Produktmails (WP-37, B23). Global --
       ohne Organisation. Im Kontext nur Feldnamen und Mailart, **nie ein
       Wert**: weder Zugangsdaten noch Text (C5, C17). Die Vorlagen einer
       Praxis protokolliert MailTemplate selbst (Auditable). */
    case PlatformMailSettingsChanged = 'mail.platform_settings_changed';
    case PlatformMailLogoChanged = 'mail.platform_logo_changed';
    case PlatformMailTestRequested = 'mail.platform_test_requested';
    case PlatformMailTemplateChanged = 'mail.platform_template_changed';
    case PlatformMailTemplateReset = 'mail.platform_template_reset';

    /* Die Demo-Anfragen der Startseite (WP-38). Global -- ohne Organisation.
       Im Kontext nur der Status, **nie eine Angabe der Anfrage** (C5). */
    case DemoRequestStatusChanged = 'demo_request.status_changed';
    case DemoRequestDeleted = 'demo_request.deleted';

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
            self::ImpersonationEndedByTenant => 'Support-Zugriff von der Praxis beendet',
            self::SupportPinCreated => 'Einmal-PIN für den Support erzeugt',
            self::SupportPinRevoked => 'Einmal-PIN widerrufen',
            self::SupportPinFailed => 'Einmal-PIN falsch eingegeben',
            self::SupportPinBurned => 'Einmal-PIN nach Fehlversuchen gesperrt',
            self::SupportPinRedeemed => 'Einmal-PIN eingelöst',
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
            self::OperatorPasswordSet => 'Betreiberpasswort auf der Konsole gesetzt',
            self::OperatorDeleted => 'Betreiberkonto gelöscht',
            self::TwoFactorEnabled => 'Zweiter Faktor eingeschaltet',
            self::TwoFactorDisabled => 'Zweiter Faktor abgeschaltet',
            self::TwoFactorReset => 'Zweiter Faktor zurückgesetzt',
            self::TwoFactorRecoveryCodesRenewed => 'Neue Wiederherstellungscodes erzeugt',
            self::TwoFactorRecoveryCodeUsed => 'Wiederherstellungscode eingelöst',
            self::TwoFactorChallengeLocked => 'Anmeldung nach falschen Codes verworfen',
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
            self::PlatformMailSettingsChanged => 'Plattformversand geändert',
            self::PlatformMailLogoChanged => 'Logo der Produktmails geändert',
            self::PlatformMailTestRequested => 'Probemail des Plattformversands angefordert',
            self::PlatformMailTemplateChanged => 'Vorlage einer Produktmail geändert',
            self::PlatformMailTemplateReset => 'Vorlage einer Produktmail zurückgesetzt',
            self::DemoRequestStatusChanged => 'Status einer Demo-Anfrage geändert',
            self::DemoRequestDeleted => 'Demo-Anfrage gelöscht',
            self::EncryptionKeyIssued => 'Schlüsselsatz angelegt',
            self::EncryptionKeyRevoked => 'Schlüssel widerrufen',
            self::AttachmentOpened => 'Anhang geöffnet',
        };
    }
}
