<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wofuer jemand sein Einverstaendnis gegeben hat.
 *
 * **Zweckgebunden.** Eine Einwilligung gilt fuer das, wofuer sie erteilt
 * wurde -- wer einer Terminerinnerung zustimmt, hat keiner Werbung
 * zugestimmt. Ein einziges "Einverstanden" waere im Sinne der DSGVO keines.
 */
enum ConsentType: string
{
    /** Terminbestaetigungen, Erinnerungen, Absagen. */
    case ServiceMessages = 'service_messages';

    case Marketing = 'marketing';

    /**
     * Nachrichten ueber WhatsApp.
     *
     * Eigener Typ und nicht bloss ein Kanal: Meta verlangt ein Opt-in, und es
     * haengt an einer Rufnummer, nicht an einer Person (Entscheidung D8).
     */
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::ServiceMessages => 'Terminnachrichten',
            self::Marketing => 'Werbung',
            self::WhatsApp => 'WhatsApp',
        };
    }
}
