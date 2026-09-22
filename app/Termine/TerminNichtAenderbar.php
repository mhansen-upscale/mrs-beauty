<?php

declare(strict_types=1);

namespace App\Termine;

use App\Enums\AppointmentStatus;
use RuntimeException;

/**
 * Der Termin ist in seinem jetzigen Zustand nicht so aenderbar.
 */
final class TerminNichtAenderbar extends RuntimeException
{
    public static function statuswechsel(AppointmentStatus $von, AppointmentStatus $nach): self
    {
        return new self(
            "Ein Termin im Status „{$von->label()}“ kann nicht auf "
            ."„{$nach->label()}“ gesetzt werden."
        );
    }

    /**
     * Ein abgesagter Termin bleibt abgesagt.
     *
     * Die Absage hat die Slots freigegeben; sie koennen laengst vergeben
     * sein. Ein Wiederbeleben waere eine Buchung ohne
     * Verfuegbarkeitspruefung -- also genau die Doppelbuchung, die WP-10
     * ausschliesst.
     */
    public static function abgesagt(): self
    {
        return new self(
            'Dieser Termin wurde abgesagt und lässt sich nicht wiederbeleben. '
            .'Die Zeit ist wieder frei und möglicherweise schon vergeben – '
            .'bitte neu buchen.'
        );
    }

    public static function nichtMehrVerschiebbar(AppointmentStatus $status): self
    {
        return new self(
            "Ein Termin im Status „{$status->label()}“ lässt sich nicht mehr verschieben."
        );
    }

    public static function nochNichtStattgefunden(): self
    {
        return new self(
            '„Erschienen“ und „Nicht erschienen“ lassen sich erst setzen, wenn '
            .'der Termin begonnen hat.'
        );
    }

    public static function absageBrauchtGrund(): self
    {
        return new self('Eine Absage wird über sageAb() eingetragen und braucht einen Grund.');
    }
}
