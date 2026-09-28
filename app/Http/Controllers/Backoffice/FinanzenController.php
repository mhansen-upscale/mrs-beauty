<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Backoffice\Finanzuebersicht;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Die Finanzuebersicht des Betreibers (WP-34d, `finanzen.sehen`).
 *
 * **Eine Hochrechnung, die sagt, dass sie eine ist** (B19): Die Seite nennt
 * fehlende Saetze und verweist fuer die tatsaechlichen Zahlen auf Stripe.
 * Ein Aufruf ist genau ein Querzugriff, mit Begruendung.
 */
final class FinanzenController extends Controller
{
    public function index(Finanzuebersicht $finanzen): Response
    {
        return Inertia::render('backoffice/Finanzen', $finanzen->bericht());
    }
}
