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
            self::EncryptionKeyIssued => 'Schlüsselsatz angelegt',
            self::EncryptionKeyRevoked => 'Schlüssel widerrufen',
            self::AttachmentOpened => 'Anhang geöffnet',
        };
    }
}
