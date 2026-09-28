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
        // Die Vorlagen einer Praxis fuer ihre Terminmails (WP-36, P12).
        //
        // **Ueberschreibungen, keine Kopien** (D15): eine Zeile gibt es nur,
        // wenn die Praxis etwas anders will. Ohne Zeile gilt der Standard aus
        // App\Benachrichtigung\Vorlagen\Standardtexte -- auch mit seinen
        // spaeteren Verbesserungen. Zuruecksetzen loescht die Zeile.
        //
        // Unverschluesselt: Der Text einer Vorlage ist Werbe- und
        // Empfangstext der Praxis, kein Personendatum. Die Werte, die in die
        // Platzhalter kommen, stehen nie hier.
        Schema::create('mail_templates', function (Blueprint $table): void {
            TenantSchema::base($table);

            // App\Enums\Mailart -- nur die gestaltbaren des Praxiswegs.
            $table->string('template', 40);

            $table->string('subject', 200);
            $table->string('greeting', 200)->default('');
            $table->text('intro');
            $table->text('outro');
            $table->string('salutation', 300)->default('');

            $table->datetimes();

            $table->unique(['organization_id', 'template'], 'mailvorlage_unique');
        });

        // Die Signatur unter jeder Mail der Praxis. Beim Erscheinungsbild,
        // weil sie dazugehoert wie Logo und Farbe -- **eine** Farbe, nicht
        // eine zweite Spalte fuer die Mail (siehe die Anlage von brandings).
        Schema::table('brandings', function (Blueprint $table): void {
            $table->text('mail_signature')->nullable()->after('privacy_url');
        });
    }

    public function down(): void
    {
        Schema::table('brandings', function (Blueprint $table): void {
            $table->dropColumn('mail_signature');
        });

        Schema::dropIfExists('mail_templates');
    }
};
