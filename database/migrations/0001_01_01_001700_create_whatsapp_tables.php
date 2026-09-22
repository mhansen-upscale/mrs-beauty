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
        // Templates werden bei Meta genehmigt und hier nur gelesen.
        //
        // **Je Sprache getrennt** (docs/integrationen/meta.md): derselbe Name
        // kann auf Deutsch genehmigt und auf Englisch abgelehnt sein. Der
        // Unique-Index bildet das ab, nicht der Name allein.
        Schema::create('whatsapp_templates', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('name', 120);
            $table->string('language', 16);

            // Utility statt Marketing senkt die Kosten deutlich und haengt
            // allein von der Formulierung ab. Was es geworden ist, sagt Meta
            // -- wir raten es nicht.
            $table->string('category', 32);
            $table->string('status', 24);

            // Der genehmigte Rumpf mit {{1}}-Platzhaltern. Kein
            // Personenbezug: er ist die Schablone, nicht die Nachricht.
            $table->text('body')->nullable();
            $table->unsignedTinyInteger('variables')->default(0);

            $table->datetime('synced_at')->nullable();
            $table->datetimes();

            $table->unique(['organization_id', 'name', 'language'], 'whatsapp_template_unique');
        });

        Schema::table('messages', function (Blueprint $table): void {
            TenantSchema::reference($table, 'template_id', 'whatsapp_templates', nullable: true);

            // **Verschluesselt** (Regel 3): in den Variablen stehen Name und
            // Uhrzeit einer Person. Der Rumpf des Templates ist harmlos, das
            // Eingesetzte ist es nicht.
            $table->binary('template_variables')->nullable()->after('media_type');
        });

        Schema::table('channel_connections', function (Blueprint $table): void {
            // **Zwei Kennungen, eine Verbindung.** Die Zustellung traegt die
            // WABA-Kennung (entry[].id), gesendet wird unter der
            // Rufnummern-ID. Bei Seiten und Instagram-Konten sind beide
            // dieselbe; dann bleibt die Spalte leer.
            $table->string('sender_id', 191)->nullable()->after('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('channel_connections', function (Blueprint $table): void {
            $table->dropColumn('sender_id');
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropForeign('messages_template_id_fk');
            $table->dropColumn(['template_id', 'template_variables']);
        });

        Schema::dropIfExists('whatsapp_templates');
    }
};
