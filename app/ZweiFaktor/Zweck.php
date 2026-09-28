<?php

declare(strict_types=1);

namespace App\ZweiFaktor;

/**
 * Wofuer ein Code per E-Mail gilt (WP-35).
 *
 * **Ein Einrichtungscode oeffnet keine Anmeldung und umgekehrt.** Der Zweck
 * steht im Schluessel des Codes, nicht nur in der Mail.
 */
enum Zweck: string
{
    case Anmeldung = 'anmeldung';
    case Einrichtung = 'einrichtung';
}
