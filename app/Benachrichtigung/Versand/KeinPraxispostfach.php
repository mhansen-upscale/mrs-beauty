<?php

declare(strict_types=1);

namespace App\Benachrichtigung\Versand;

use RuntimeException;

/**
 * Die Praxis hat keinen Mailserver hinterlegt, der senden kann (B22).
 *
 * **Kein Rueckfall auf die Plattform.** Eine Mail an eine Patientin geht nur
 * ueber das Postfach der Praxis -- sonst bleibt sie als nicht zustellbar
 * stehen, sichtbar, statt unter fremdem Namen aus unserer Infrastruktur.
 */
final class KeinPraxispostfach extends RuntimeException
{
    public const GRUND = 'no_mailer';

    public function __construct()
    {
        parent::__construct('Die Praxis hat kein sendebereites Postfach hinterlegt.');
    }
}
