<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Antworten im Service-Fenster werden gezaehlt und einzeln bepreist
 * (Entscheidung B14, 26.09.2026).
 *
 * **Der Preis steht an der Nachricht**, nicht in einer zweiten Tabelle (B7):
 * er wird festgehalten, sobald Meta die Kategorie meldet, und zwar mit dem
 * Wert, der in diesem Moment gilt. Wer den Preis erhoeht, erhoeht ihn ab
 * dann -- nicht rueckwirkend fuer einen Monat, der schon gelaufen ist.
 *
 * **Am Abo steht nur, welcher Monat schon auf einer Rechnung ist.** Stripes
 * Idempotenzschluessel gilt 24 Stunden; ein Lauf am naechsten Tag braeuchte
 * sonst einen zweiten Weg, um nicht doppelt abzurechnen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            // In Zehntel-Cent, wie `agent_runs.cost_tenth_cents` (B11). Leer
            // heisst: nicht einzeln berechnet -- ein Template zaehlt gegen das
            // Kontingent, eine E-Mail kostet nichts.
            $table->unsignedInteger('charge_tenth_cents')->nullable()->after('cost_category');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('service_window_billed_period', 7)->nullable()->after('extra_agent_runs');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('charge_tenth_cents');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('service_window_billed_period');
        });
    }
};
