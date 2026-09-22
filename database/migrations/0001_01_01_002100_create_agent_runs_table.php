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
        // **Ein Durchlauf je Nachricht, mit allem, was er getan hat.**
        //
        // docs/fachlogik/agent.md, Abschnitt Protokollierung: "Ohne diese
        // Protokollierung laesst sich einem Arzt nicht erklaeren, warum sein
        // Agent etwas geantwortet hat."
        //
        // Das ist kein Log, sondern ein Nachweis -- und er gehoert deshalb in
        // die Datenbank des Mandanten und nicht in eine Datei auf dem Server.
        Schema::create('agent_runs', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'conversation_id', 'conversations', cascadeOnDelete: true);
            TenantSchema::reference($table, 'message_id', 'messages', nullable: true, cascadeOnDelete: true);

            $table->string('intent', 32)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('action', 16);
            $table->string('escalation_reason', 64)->nullable();

            // **Verschluesselt** (Regel 3). In den Entitaeten steht der Name
            // einer Person und ihr Zeitwunsch; im Vorschlag steht ein Text,
            // der an sie hinausgehen soll.
            $table->binary('entities')->nullable();
            $table->binary('suggestion')->nullable();

            // Schritt 4 und 7. Bis WP-23 leer -- die Spalte steht, damit die
            // Guardrails sie fuellen koennen, ohne dass eine Migration
            // nachgezogen werden muss.
            $table->json('guardrails')->nullable();

            $table->string('model', 64)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();

            // In Zehntel-Cent: ein Aufruf kostet regelmaessig weniger als
            // einen Cent, und eine Rechnung, die auf null rundet, sagt nichts.
            $table->unsignedInteger('cost_tenth_cents')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->string('failure', 64)->nullable();
            $table->datetimes();

            // **Genau ein Lauf je Nachricht.** Ein Auftrag, der nach einem
            // Deploy erneut laeuft, ist der Normalfall (Entscheidung A13) --
            // und ein zweiter Vorschlag zur selben Nachricht waere zweimal
            // dasselbe im Eingabefeld.
            $table->unique(['organization_id', 'message_id'], 'agentenlauf_nachricht_unique');

            $table->index(['organization_id', 'created_at'], 'agentenlauf_verlauf_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
