<?php

declare(strict_types=1);

use App\Support\Schema\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Das Abo einer Praxis (WP-06).
        //
        // **Was hier steht, ist der Abgleich mit Stripe** -- nicht die
        // Nutzung. Die wird aus den Fachtabellen gerechnet (messages,
        // agent_runs, waitlist_offers); eine zweite Erfassung waere ein
        // zweiter Ort fuer dieselbe Zahl.
        Schema::create('subscriptions', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('status', 16)->default('trialing');

            // Die Kennungen des Anbieters. Nicht personenbezogen, aber der
            // Schluessel zu einer Zahlungsbeziehung -- deshalb verborgen.
            $table->string('stripe_customer_id', 64)->nullable();
            $table->string('stripe_subscription_id', 64)->nullable();

            // Die Abrechnungsperiode, wie Stripe sie fuehrt.
            $table->datetime('period_starts_at')->nullable();
            $table->datetime('period_ends_at')->nullable();
            $table->datetime('trial_ends_at')->nullable();
            $table->datetime('canceled_at')->nullable();

            // Aufgestockte Mengen je Periode, in Einheiten -- Nachrichten und
            // Assistenzlaeufe getrennt, damit eine Praxis nicht das eine
            // kauft und das andere verbraucht.
            $table->unsignedInteger('extra_messages')->default(0);
            $table->unsignedInteger('extra_agent_runs')->default(0);

            $table->datetimes();

            $table->unique(['organization_id'], 'abo_je_mandant_unique');
            $table->index(['stripe_subscription_id'], 'abo_stripe_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
