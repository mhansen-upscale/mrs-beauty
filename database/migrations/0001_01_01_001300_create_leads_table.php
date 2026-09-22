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
        // Entscheidung D3: die Anfrage ist eine eigene Einheit, nicht ein
        // Zustand am Kontakt. Dieselbe Person fragt im Maerz nach Botox, bucht
        // nicht, meldet sich im Oktober wegen Hyaluron und bucht dann -- zwei
        // Anfragen mit zwei Quellen und zwei Ergebnissen.
        Schema::create('leads', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'contact_id', 'contacts', cascadeOnDelete: true);

            // **Entscheidung D2: eine Kennung, kein Satz.** Ein Freitextfeld
            // fuellt sich binnen Wochen mit Angaben nach Artikel 9 DSGVO, und
            // die landen dann in Logs, Kalendertiteln und Meta-Payloads
            // (Regel 2). Diese Tabelle hat deshalb **keine** Freitextspalte --
            // auch nicht "nur fuer Notizen". Die gehoeren zu WP-18, mit
            // Zweckbindung und Frist.
            //
            // Nullable, weil ein Lead ohne Wunsch der Normalfall ist: die
            // erste Nachricht lautet oft nur "Was kostet das?".
            TenantSchema::reference($table, 'treatment_id', 'treatments', nullable: true);

            $table->string('status', 32)->default('new');
            $table->string('source', 32);
            $table->string('lost_reason', 32)->nullable();

            // Speed-to-Lead: Median ueber diese Spalte
            // (docs/fachlogik/attribution.md). Nur die **erste** Reaktion --
            // eine Kennzahl, die sich durch Nacharbeit schoenen laesst, ist
            // keine.
            $table->datetime('first_responded_at')->nullable();
            $table->unsignedInteger('first_response_seconds')->nullable();

            // Traegt Entscheidung D4: nach dieser Frist ohne Aktivitaet
            // entsteht ein neuer Lead statt eines Anhaengsels.
            $table->datetime('last_activity_at');
            $table->datetime('closed_at')->nullable();

            $table->datetimes();

            // Die Abfrage aus D4 laeuft genau darueber: offene Leads dieses
            // Kontakts, nach letzter Aktivitaet.
            $table->index(['contact_id', 'status', 'last_activity_at'], 'lead_offen_idx');

            // Der Trichter zaehlt je Organisation und Zeitraum.
            $table->index(['organization_id', 'status', 'created_at'], 'lead_trichter_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
