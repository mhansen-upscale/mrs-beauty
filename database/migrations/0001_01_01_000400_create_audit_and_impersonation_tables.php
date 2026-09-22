<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Kein Teil von App\Enums\Role: eine Rolle gilt innerhalb einer
            // Organisation, ein Super-Admin gehoert zu keiner. Das Backoffice
            // dazu ist WP-34.
            $table->boolean('is_super_admin')->default(false)->after('role');
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->binary('id', 16, true)->primary();

            // Nullable, weil ein mandantenuebergreifender Vorgang zu keiner
            // einzelnen Organisation gehoert. Solche Zeilen sieht nur, wer
            // ausdruecklich quer zu den Mandanten liest.
            $table->binary('organization_id', 16, true)->nullable();

            $table->string('event', 64);

            // Wer. Nullable fuer Vorgaenge des Systems (Jobs, Konsole).
            $table->binary('actor_user_id', 16, true)->nullable();
            $table->string('actor_label')->nullable();

            // Woran. Typ und ID, nie der Inhalt.
            $table->string('subject_type')->nullable();
            $table->binary('subject_id', 16, true)->nullable();

            // Welche Felder sich geaendert haben -- nur die Namen
            // (Entscheidung C5).
            $table->json('changed_fields')->nullable();

            // Werte ausschliesslich fuer Felder, die das Modell ausdruecklich
            // als unbedenklich erklaert hat.
            $table->json('context')->nullable();

            $table->string('reason', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->binary('impersonation_session_id', 16, true)->nullable();

            $table->datetime('occurred_at');

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();

            $table->unique(['id', 'organization_id']);
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('occurred_at');
        });

        Schema::create('impersonation_sessions', function (Blueprint $table): void {
            $table->binary('id', 16, true)->primary();
            $table->binary('organization_id', 16, true);

            $table->binary('impersonator_user_id', 16, true);
            $table->binary('impersonated_user_id', 16, true)->nullable();

            // masked | full. VARCHAR plus PHP-Enum (Entscheidung A11).
            $table->string('mode', 16);
            $table->string('reason', 500);

            $table->datetime('started_at');
            $table->datetime('expires_at');

            // Vollzugriff gibt es nur mit Freigabe durch eine Inhaberin des
            // betroffenen Mandanten (Entscheidung C4).
            $table->datetime('approved_at')->nullable();
            $table->binary('approved_by_user_id', 16, true)->nullable();

            $table->datetime('ended_at')->nullable();
            $table->string('ended_reason', 64)->nullable();

            $table->datetimes();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();

            $table->unique(['id', 'organization_id']);
            $table->index(['organization_id', 'expires_at']);

            // Je Super-Admin hoechstens eine laufende Sitzung. VIRTUAL, weil
            // organization_id an einem ON DELETE CASCADE haengt.
            $table->binary('running_guard', 16, true)
                ->nullable()
                ->virtualAs('CASE WHEN ended_at IS NULL THEN impersonator_user_id END');

            $table->unique('running_guard');
        });

        $this->legeAppendOnlySperrenAn();
    }

    /**
     * Append-only auf Datenbankebene (Entscheidung C5).
     *
     * Eine Sperre im Modell schuetzt vor dem eigenen Code, nicht vor einem
     * Datenbankwerkzeug und nicht vor DB::table('audit_logs')->update(...).
     *
     * Die Ausnahme fuer das Loeschen ist noetig, weil Entscheidung C7 eine
     * Aufbewahrung von 36 Monaten vorsieht. Sie laeuft ueber eine
     * Sitzungsvariable, die der Aufbewahrungsjob (WP-18) ausdruecklich setzt
     * -- ein benannter Kanal statt eines offenen Scheunentors.
     */
    private function legeAppendOnlySperrenAn(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_kein_update
            BEFORE UPDATE ON audit_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'audit_logs ist append-only (Entscheidung C5).';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_kein_delete
            BEFORE DELETE ON audit_logs
            FOR EACH ROW
            BEGIN
                IF @mrs_audit_retention IS NULL OR @mrs_audit_retention <> 1 THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'audit_logs ist append-only. Loeschen nur ueber den Aufbewahrungsjob.';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_kein_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_kein_delete');

        Schema::dropIfExists('impersonation_sessions');
        Schema::dropIfExists('audit_logs');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_super_admin');
        });
    }
};
