<?php

declare(strict_types=1);

namespace App\ZweiFaktor;

/** Was aus einem Code per E-Mail geworden ist (WP-35). */
enum Versand
{
    case Gesendet;

    /** Zu frueh nach dem letzten oder zu viele in dieser Stunde. */
    case Gedrosselt;

    /** Die Mail liess sich nicht einreihen. Die Person bekommt einen Hinweis, keinen Fehler 500. */
    case Gescheitert;
}
