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
        // **Wer eine Behandlung beherrscht, steht an der Behandlung.**
        //
        // Bisher stand die Freigabe nur an der Terminart. Das ist die
        // buchbare Einheit, aber nicht die fachliche: "Wer macht Botox?" ist
        // eine Frage an den Katalog, nicht an einen Terminzuschnitt -- und
        // die oeffentliche Buchungsseite will sie beantworten koennen.
        //
        // Die Terminart kann weiterhin **verengen**; ohne eigene Freigabe
        // erbt sie die der Behandlung.
        Schema::table('treatments', function (Blueprint $table): void {
            // Der Normalfall in einer kleinen Praxis: alle koennen alles.
            // Ein leerer Pivot waere zweideutig -- "alle" oder "noch nicht
            // gepflegt"? Dieselbe Ueberlegung wie Entscheidung D12 bei der
            // Warteliste.
            $table->boolean('all_practitioners')->default(true)->after('is_active');
        });

        Schema::create('treatment_practitioner', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'treatment_id', 'treatments', cascadeOnDelete: true);
            TenantSchema::reference($table, 'practitioner_id', 'practitioners', cascadeOnDelete: true);

            $table->datetimes();

            $table->unique(['treatment_id', 'practitioner_id'], 'behandlung_behandler_unique');
        });

        Schema::table('practitioners', function (Blueprint $table): void {
            // **Unverschluesselt und auf einer oeffentlichen Platte**, und das
            // ist dieselbe Entscheidung wie beim Namen: die Praxis
            // veroeffentlicht das Bild selbst auf der Buchungsseite. Was
            // oeffentlich ist, muss nicht gegen den eigenen Betreiber
            // geschuetzt werden -- und ein Bild, das bei jedem Aufruf
            // entschluesselt werden muesste, waere auf einer oeffentlichen
            // Seite auch nicht zu bezahlen.
            $table->string('avatar_path', 191)->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('practitioners', function (Blueprint $table): void {
            $table->dropColumn('avatar_path');
        });

        Schema::dropIfExists('treatment_practitioner');

        Schema::table('treatments', function (Blueprint $table): void {
            $table->dropColumn('all_practitioners');
        });
    }
};
