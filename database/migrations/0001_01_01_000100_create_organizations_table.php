<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Die Mandantenwurzel. Traegt selbst keine organization_id --
        // sie ist die Organisation.
        Schema::create('organizations', function (Blueprint $table): void {
            $table->binary('id', 16, true)->primary();

            $table->string('name');
            $table->string('slug')->unique();

            // Alles, was je Mandant abweichen darf. Die Standardwerte stehen
            // in config/mrs.php, nicht im Code.
            $table->json('settings')->nullable();

            $table->datetime('suspended_at')->nullable();
            $table->datetimes();

            // Der zusammengesetzte Fremdschluessel der Kindtabellen verweist
            // auf (id, organization_id). Bei der Organisation selbst ist das
            // die id in beiden Rollen, deshalb genuegt der Primaerschluessel.
        });

        // Envelope Encryption (Entscheidung A5). Getrennte Tabelle, weil die
        // Krypto-Loeschung nur wirkt, wenn diese Zeilen eine eigene
        // Aufbewahrungsregel bekommen -- siehe docs/datenmodell.md, 0.6.
        Schema::create('encryption_keys', function (Blueprint $table): void {
            $table->binary('id', 16, true)->primary();
            $table->binary('organization_id', 16, true);

            // Mit dem APP_KEY umschlossen, nie im Klartext.
            $table->text('wrapped_dek');
            $table->text('wrapped_index_key');

            // Widerruf statt Loeschen: die Zeile bleibt als Nachweis stehen,
            // der Inhalt wird unbrauchbar gemacht.
            $table->datetime('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();

            $table->datetimes();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();

            // Je Organisation genau ein gueltiger Schluesselsatz. Die
            // generierte Spalte ist ausserhalb des gueltigen Zustands NULL und
            // wird vom Unique-Index dann nicht verglichen (Entscheidung A10).
            //
            // VIRTUAL, nicht STORED: MySQL verbietet ON DELETE CASCADE auf
            // einer Spalte, von der eine STORED generierte Spalte abhaengt.
            // Ein Sekundaerindex auf einer VIRTUAL-Spalte ist erlaubt, also
            // kostet die Umstellung nichts ausser Rechenzeit beim Lesen.
            $table->binary('active_guard', 16, true)
                ->nullable()
                ->virtualAs('CASE WHEN revoked_at IS NULL THEN organization_id END');

            $table->unique('active_guard');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encryption_keys');
        Schema::dropIfExists('organizations');
    }
};
