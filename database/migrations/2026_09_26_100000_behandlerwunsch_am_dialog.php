<?php

declare(strict_types=1);

use App\Support\Schema\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Behandlerwunsch im Buchungsdialog (docs/fachlogik/agent.md, Schritt 6:
 * `behandler_klaeren`, "nur wenn der Kontakt danach fragt").
 *
 * **Eine eigene Spalte, nicht `practitioner_id`.** Die haelt fest, bei wem der
 * gehaltene Slot liegt -- sie wird beim Waehlen gesetzt. Der Wunsch ist etwas
 * anderes: er schraenkt die Vorschlaege ein. Beides in einer Spalte haette
 * nach einem abgelaufenen Hold jede Auswahl in einen Wunsch verwandelt, den
 * niemand geaeussert hat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_dialogs', function (Blueprint $table): void {
            TenantSchema::reference($table, 'requested_practitioner_id', 'practitioners', nullable: true);
        });
    }

    public function down(): void
    {
        Schema::table('agent_dialogs', function (Blueprint $table): void {
            $table->dropForeign('agent_dialogs_requested_practitioner_id_fk');
            $table->dropColumn('requested_practitioner_id');
        });
    }
};
