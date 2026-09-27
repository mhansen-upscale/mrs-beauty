<?php

declare(strict_types=1);

use App\Support\Schema\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Abo-Eingriffe und ein gehaerteter Webhook (WP-34c, B17)
|--------------------------------------------------------------------------
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            // **Eine Pause aendert Stripes Status nicht** -- das Abo bleibt
            // `active`. Die Sperre haengt deshalb hier, nicht am Status.
            $table->datetime('paused_at')->nullable()->after('canceled_at');
            $table->datetime('pause_resumes_at')->nullable()->after('paused_at');

            $table->boolean('cancel_at_period_end')->default(false)->after('pause_resumes_at');
            $table->datetime('cancel_at')->nullable()->after('cancel_at_period_end');

            // Bis wann ein Gutschein wirkt -- beim Gratismonat das Ende der
            // Periode, deren Rechnung er erlaesst.
            $table->datetime('discount_ends_at')->nullable()->after('cancel_at');

            // Der erste Wechsel auf `active` -- fuer die Einrichtung, die
            // genau einmal zaehlt (WP-34d).
            $table->datetime('activated_at')->nullable()->after('discount_ends_at');

            // Das juengste angewandte Ereignis. Stripe liefert in keiner
            // festen Reihenfolge; aelteres aendert danach nichts mehr.
            $table->datetime('stripe_event_at')->nullable()->after('activated_at');
        });

        // **Der Auftrag mit seinem sichtbaren Stand** (Regel 4) -- keine zweite
        // Zustandsfuehrung neben Stripe.
        Schema::create('subscription_changes', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('action', 32);
            $table->json('parameters')->nullable();
            $table->string('reason', 500);
            $table->string('status', 16);
            $table->string('error', 500)->nullable();
            $table->binary('requested_by_user_id', 16, true)->nullable();

            // Je Eingriff einer: eine Wiederholung nach verlorener Antwort
            // legt bei Stripe nichts zweimal an.
            $table->string('idempotency_key', 64)->unique();

            $table->datetime('completed_at')->nullable();
            $table->datetimes();

            $table->index(['organization_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        // **Jede Zustellung genau einmal** (WP-06 AK 15). Ohne Mandant: die
        // Zustellung kommt vor jeder Zuordnung an, und die Kennung ist
        // weltweit eindeutig.
        Schema::create('stripe_events', function (Blueprint $table): void {
            $table->string('id', 255)->primary();
            $table->string('type', 64);
            $table->datetime('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
        Schema::dropIfExists('subscription_changes');

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn([
                'paused_at', 'pause_resumes_at', 'cancel_at_period_end', 'cancel_at',
                'discount_ends_at', 'activated_at', 'stripe_event_at',
            ]);
        });
    }
};
