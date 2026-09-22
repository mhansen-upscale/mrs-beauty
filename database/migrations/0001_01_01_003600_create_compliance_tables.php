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
        // **Die eine Tabelle ohne organization_id** (docs/datenmodell.md,
        // Abschnitt 9).
        //
        // Der Rechtsstand ist fuer alle Mandanten derselbe. Eine
        // mandantenbezogene Kopie wuerde bedeuten, dass ein Kunde mit
        // veraltetem Regelwerk weiterarbeitet -- und genau das ist der Fall,
        // in dem eine Pruefhilfe schadet, statt zu helfen.
        Schema::create('compliance_rulesets', function (Blueprint $table): void {
            $table->binary('id', 16, true)->primary();

            // Fortlaufend. Jedes Pruefergebnis haelt fest, gegen welche
            // Fassung es entstanden ist.
            $table->unsignedInteger('version');

            // Der Rechtsstand, den diese Fassung abbildet -- nicht der Tag,
            // an dem jemand sie eingetragen hat.
            $table->date('legal_as_of');

            $table->date('valid_from');
            $table->date('valid_until')->nullable();

            $table->text('changelog')->nullable();

            // **Ungeprueft, bis jemand mit Zulassung hingesehen hat.**
            //
            // specs/WP-30 nennt als Abnahmekriterium: "Ein Testsatz echter
            // Anzeigen der Branche wird korrekt klassifiziert, geprueft durch
            // einen Medizinrechtler." Solange das aussteht, sagt das Produkt
            // es -- eine Ampel, der jemand vertraut, ohne dass sie geprueft
            // ist, ist gefaehrlicher als gar keine.
            $table->string('reviewed_by', 191)->nullable();
            $table->date('reviewed_at')->nullable();

            $table->datetimes();

            $table->unique('version');
        });

        // Ein Pruefergebnis.
        //
        // Polymorph: derselbe Vorgang trifft Anzeigenvorschlaege, Creatives,
        // Behandlungsbeschreibungen, Templates und die Buchungsseite.
        Schema::create('compliance_checks', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('checkable_type', 191);
            $table->binary('checkable_id', 16, true);

            // **Rechtsstand, Regelwerksversion und Pruefdatum an jedem
            // Ergebnis**: ein Befund bleibt nach einer Regelwerksaenderung
            // mit seiner urspruenglichen Fassung nachvollziehbar.
            $table->unsignedInteger('ruleset_version');
            $table->date('legal_as_of');
            $table->datetime('checked_at');

            // 'green', 'yellow', 'red'.
            $table->string('result', 16);

            // Die Befunde als JSON: Code, Fundstelle, Textstelle,
            // Formulierungsvorschlag. Verschluesselt, weil die geprueften
            // Texte Behandlungsbezeichnungen tragen -- und weil sie an einem
            // Mandanten haengen.
            $table->binary('findings')->nullable();

            // **Ein Override ist nur mit Pflichtbegruendung moeglich und wird
            // protokolliert** (Entscheidung C3).
            $table->binary('override_reason')->nullable();
            $table->binary('overridden_by_user_id', 16, true)->nullable();
            $table->datetime('overridden_at')->nullable();

            $table->foreign('overridden_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->datetimes();

            $table->index(['organization_id', 'checkable_type', 'checkable_id'], 'pruefung_gegenstand_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_checks');
        Schema::dropIfExists('compliance_rulesets');
    }
};
