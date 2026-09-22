<?php

declare(strict_types=1);

namespace App\Anzeigen;

use RuntimeException;

/**
 * Der Anbieter hat kein Bild geliefert.
 *
 * Kein Fehler des Produkts: die Praxis sieht einen Hinweis, und der Entwurf
 * bleibt, wie er war.
 */
final class BildNichtErzeugt extends RuntimeException {}
