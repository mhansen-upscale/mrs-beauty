<?php

declare(strict_types=1);

use App\Support\Schema\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Finanzuebersicht des Betreibers (WP-34d, B19)
|--------------------------------------------------------------------------
|
| Eine Hochrechnung, keine Buchhaltung. Neu ist nur, was im Produkt bisher
| fehlte: der Zeitpunkt einer Aufstockung, die verworfenen Kosten der
| Anzeigentexte und ein Monatsabschluss, weil das Abo nur seinen
| Jetzt-Zustand kennt.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        // **Aufstockungen mit Zahlungszeitpunkt.** Aus `extra_*` laesst sich
        // kein Monat rechnen: die Spalten setzt die Stripe-Periode zurueck,
        // nicht das Monatsende.
        Schema::create('top_ups', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('article', 32);
            $table->unsignedInteger('quantity');

            // Netto, aus der Kasse: `amount_total` abzueglich Steuer.
            $table->unsignedInteger('amount_cents');
            $table->datetime('paid_at');

            // Die Kasse ist der Schluessel: Stripe meldet sie unter Umstaenden
            // mit zwei Ereignissen (Karte und SEPA).
            $table->string('stripe_checkout_id', 255)->unique();

            $table->datetimes();
            $table->index(['organization_id', 'paid_at'], 'aufstockung_monat_idx');
        });

        // **Die Kostenluecke der Anzeigentexte.** Eine Zeile je Aufruf, nicht
        // je Vorschlag -- auch ein Aufruf ohne lesbare Antwort hat gekostet.
        Schema::create('model_calls', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('purpose', 32);
            $table->string('model', 64);
            $table->unsignedInteger('input_tokens');
            $table->unsignedInteger('output_tokens');

            // Zehntel-US-Cent, wie agent_runs.cost_tenth_cents.
            $table->unsignedInteger('cost_tenth_cents');

            $table->datetimes();
            $table->index(['organization_id', 'created_at'], 'modellaufruf_monat_idx');
        });

        // **Der Monatsabschluss** friert den Vormonat je Praxis ein. Betraege
        // in Euro-Cent, netto; was ohne Satz nicht zu rechnen war, steht in
        // `fehlende_saetze`, nicht als Null im Betrag.
        Schema::create('monthly_closings', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->char('month', 7);
            $table->string('zugang', 16);

            $table->unsignedInteger('grundpreis_cents');
            $table->unsignedInteger('einrichtung_cents');
            $table->unsignedInteger('aufstockungen_cents');
            $table->unsignedInteger('bilder_cents');
            $table->unsignedInteger('servicefenster_cents');

            $table->unsignedInteger('sprachmodell_agent_cents');
            $table->unsignedInteger('sprachmodell_anzeigen_cents');
            $table->unsignedInteger('whatsapp_cents');
            $table->unsignedInteger('bildkosten_cents');
            $table->unsignedInteger('zahlungsverkehr_cents');

            $table->json('fehlende_saetze')->nullable();
            $table->datetime('closed_at');
            $table->datetimes();

            $table->unique(['organization_id', 'month'], 'abschluss_monat_unique');
        });

        // Die gruppierten Monatsabfragen je Praxis. Bisher gab es auf
        // `messages` nur den Verlauf je Konversation.
        Schema::table('messages', function (Blueprint $table): void {
            $table->index(['organization_id', 'created_at'], 'nachricht_abrechnung_idx');
        });

        // **`activated_at` nachtragen.** Die Spalte kam mit WP-34c ohne
        // Rueckfuellung. Ein Abo, das schon lief, bekaeme sie sonst beim
        // naechsten Webhook -- und die Einrichtung zaehlte in diesem Monat.
        // Der Zeitpunkt der Zeile ist eine Schaetzung, aber eine, die nie in
        // einen Monat mit Abschluss faellt.
        DB::table('subscriptions')
            ->whereNull('activated_at')
            ->whereNotNull('stripe_subscription_id')
            ->where('stripe_subscription_id', '!=', '')
            ->where('status', '!=', 'trialing')
            ->update(['activated_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('nachricht_abrechnung_idx');
        });

        Schema::dropIfExists('monthly_closings');
        Schema::dropIfExists('model_calls');
        Schema::dropIfExists('top_ups');
    }
};
