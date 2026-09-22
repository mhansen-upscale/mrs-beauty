<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wie Meta ein Template beurteilt hat.
 *
 * **Genehmigt wird dort, gelesen wird hier.** Die Einreichung ist ein
 * Versuch-und-Irrtum-Vorgang (docs/integrationen/meta.md, Abschnitt
 * WhatsApp); das Produkt bildet den Zustand ab und faellt kein eigenes
 * Urteil.
 */
enum TemplateStatus: string
{
    case Approved = 'approved';

    case Pending = 'pending';

    case Rejected = 'rejected';

    /** Von Meta vorruebergehend gesperrt, etwa nach schlechter Bewertung. */
    case Paused = 'paused';

    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Genehmigt',
            self::Pending => 'In Prüfung',
            self::Rejected => 'Abgelehnt',
            self::Paused => 'Pausiert',
            self::Disabled => 'Deaktiviert',
        };
    }

    /** Nur ein genehmigtes Template nimmt WhatsApp an. */
    public function darfSenden(): bool
    {
        return $this === self::Approved;
    }

    /** Was Meta in `status` schreibt, in Grossbuchstaben. */
    public static function ausAntwort(string $wert): self
    {
        return match (mb_strtoupper($wert)) {
            'APPROVED' => self::Approved,
            'PENDING', 'IN_APPEAL', 'PENDING_DELETION' => self::Pending,
            'REJECTED' => self::Rejected,
            'PAUSED' => self::Paused,
            default => self::Disabled,
        };
    }
}
