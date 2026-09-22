<?php

declare(strict_types=1);

namespace App\Termine;

use RuntimeException;

/**
 * Der Zeitpunkt wird nicht angeboten.
 *
 * Abzugrenzen von SlotNichtVerfuegbar aus WP-10: **das** heisst "schon
 * vergeben", **dies** heisst "wird nicht angeboten". Der Unterschied ist der
 * ganze Punkt des Uebersteuerns -- die Gruende hier lassen sich
 * ueberstimmen, eine Belegung nicht.
 */
final class NichtBuchbar extends RuntimeException
{
    public static function ausserhalbDesAngebots(): self
    {
        return new self(
            'Diese Terminart wird von diesem Behandler an diesem Standort nicht angeboten.'
        );
    }

    public static function abwesendOderGeschlossen(): self
    {
        return new self(
            'In diesem Zeitraum ist der Behandler abwesend oder der Standort geschlossen.'
        );
    }

    public static function innerhalbDerVorlaufzeit(int $stunden): self
    {
        return new self(
            "Diese Terminart braucht {$stunden} Stunden Vorlauf."
        );
    }

    public static function jenseitsDesHorizonts(int $tage): self
    {
        return new self(
            "So weit im Voraus wird nicht gebucht: der Horizont liegt bei {$tage} Tagen."
        );
    }

    public static function keineArbeitszeit(): self
    {
        return new self(
            'Zu dieser Zeit arbeitet der Behandler nicht an diesem Standort.'
        );
    }
}
