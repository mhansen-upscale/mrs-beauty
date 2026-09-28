<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Eine Mail, die ueber das Postfach einer Praxis hinausgeht (A15, B22).
 *
 * Ihr Kanal ist App\Benachrichtigung\Versand\PraxisMailkanal -- er nimmt nur
 * diese Art an und baut den Mailer der Praxis im Arbeiter, im
 * Mandantenkontext.
 */
interface Praxismail
{
    /** Die Praxis, deren Postfach verschickt -- kanonische UUID, keine Rohbytes (A4). */
    public function praxis(): string;
}
