<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Eine Mail des Produkts an ein Konto -- ueber den Versand der Plattform
 * (A15, B23).
 *
 * Ihr Kanal ist App\Benachrichtigung\Versand\PlattformMailkanal. Eine
 * Terminmail ist nie eine Plattformmail, eine Plattformmail nie eine
 * Praxismail.
 */
interface Plattformmail {}
