<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Betreiberrollen (WP-34a, Entscheidung C14)
|--------------------------------------------------------------------------
|
| Aus dem Kennzeichen `is_super_admin` wird eine Rolle: Super-Admin, Customer
| Success oder Finanzen. Wer das Kennzeichen trug, ist danach Super-Admin
| und darf weiterhin alles.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // VARCHAR plus PHP-Enum (Entscheidung A11). Kein Teil von
            // App\Enums\Role: eine Praxisrolle gilt innerhalb einer Praxis,
            // der Betreiber gehoert zu keiner.
            $table->string('operator_role', 32)->nullable()->after('role');
            $table->index('operator_role');
        });

        DB::table('users')->where('is_super_admin', true)->update(['operator_role' => 'super_admin']);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_super_admin');
        });

        $this->legeBetreiberregelAn();
    }

    /**
     * **Ein Betreiber gehoert zu keiner Praxis** -- durchgesetzt in der
     * Datenbank, nicht am Aufrufort (Regel 1).
     *
     * **Ein Trigger, kein CHECK.** MySQL weist einen CHECK auf eine Spalte ab,
     * die an einer Fremdschluesselaktion haengt (Fehler 3823), und
     * `users.organization_id` hat `nullOnDelete`. Das Muster sind die Trigger
     * an `audit_logs`.
     */
    private function legeBetreiberregelAn(): void
    {
        foreach (['INSERT' => 'users_betreiber_ohne_praxis_insert', 'UPDATE' => 'users_betreiber_ohne_praxis_update'] as $vorgang => $name) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$name}
                BEFORE {$vorgang} ON users
                FOR EACH ROW
                BEGIN
                    IF NEW.operator_role IS NOT NULL AND (NEW.organization_id IS NOT NULL OR NEW.role IS NOT NULL) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Ein Betreiberkonto gehoert zu keiner Praxis und hat keine Praxisrolle (Regel 1, C14).';
                    END IF;
                END
            SQL);
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS users_betreiber_ohne_praxis_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS users_betreiber_ohne_praxis_update');

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_super_admin')->default(false)->after('role');
        });

        // Customer Success und Finanzen gab es vorher nicht -- zurueck bleibt
        // nur, wer alles durfte.
        DB::table('users')->where('operator_role', 'super_admin')->update(['is_super_admin' => true]);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['operator_role']);
            $table->dropColumn('operator_role');
        });
    }
};
