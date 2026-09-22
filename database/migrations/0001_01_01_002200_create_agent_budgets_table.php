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
        // **Das Kontingent je Mandant und Monat** (Entscheidung G11).
        //
        // Der Verbrauch steht **nicht** hier: er ist die Summe ueber
        // `agent_runs.cost_tenth_cents` desselben Zeitraums. Zwei Orte fuer
        // dieselbe Zahl gehen irgendwann auseinander, und dann glaubt niemand
        // mehr einem von beiden.
        //
        // Hier steht nur, was zur Verfuegung steht: das Enthaltene und das
        // Nachgekaufte.
        Schema::create('agent_budgets', function (Blueprint $table): void {
            TenantSchema::base($table);

            // 'YYYY-MM'. Ein Monat ist die Einheit, in der abgerechnet wird.
            $table->string('period', 7);

            $table->unsignedInteger('included_tenth_cents');
            $table->unsignedInteger('extra_tenth_cents')->default(0);

            $table->datetimes();

            $table->unique(['organization_id', 'period'], 'agentenkontingent_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_budgets');
    }
};
