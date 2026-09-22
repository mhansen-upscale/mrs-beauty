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
        // **Zwei Orte fuer dieselbe Zahl, aufgeloest** (WP-06).
        //
        // WP-23 fuehrte das Kontingent des Assistenten in Zehntel-Cent in
        // einer eigenen Tabelle. WP-06 beantwortet dieselbe Frage aus dem Abo
        // und den Fachtabellen -- in Laeufen, wie die Praxis sie sieht
        // (Entscheidung B11).
        //
        // Geblieben waere sonst: zwei Zahlen fuer denselben Verbrauch, die
        // auseinandergehen, sobald eine von beiden einen Fall anders zaehlt.
        Schema::dropIfExists('agent_budgets');
    }

    public function down(): void
    {
        Schema::create('agent_budgets', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('period', 7);
            $table->unsignedInteger('included_tenth_cents');
            $table->unsignedInteger('extra_tenth_cents')->default(0);
            $table->datetimes();

            $table->unique(['organization_id', 'period'], 'agentenkontingent_unique');
        });
    }
};
