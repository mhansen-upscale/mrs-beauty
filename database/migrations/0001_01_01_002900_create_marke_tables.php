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
        // Wie diese Praxis klingt und womit sie wirbt.
        //
        // **Einer je Mandant.** Ein zweiter waere die Frage, welcher gilt --
        // und die beantwortet niemand, wenn sie erst beim Vorschlag auftaucht.
        //
        // Farben und Logo stehen **nicht** hier: die gehoeren zum Whitelabel
        // (WP-07) und zu App\Support\Markenstil, der die Semantikvariablen
        // sperrt. Das Praxiswissen des Assistenten (WP-22) steht ebenfalls
        // woanders -- sonst veraenderte eine Aenderung am Werbeton die
        // Antwort auf eine Terminfrage.
        Schema::create('brand_guides', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('tone', 32)->nullable();
            $table->string('address_form', 8)->nullable();

            $table->text('audience')->nullable();
            $table->text('positioning')->nullable();
            $table->string('claim', 191)->nullable();
            $table->text('no_go_topics')->nullable();

            $table->datetimes();

            $table->unique('organization_id', 'markenprofil_unique');
        });

        // Bevorzugte und verbotene Begriffe.
        //
        // Die verbotenen speisen zwei Pruefungen: den Markenhinweis und
        // `brand_violation` im HWG-Regelwerk (WP-30).
        Schema::create('brand_terms', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('kind', 16);
            $table->string('term', 120);

            // Ohne Ersatz entstehen Vorschlaege, die dieselbe Aussage nur
            // umstaendlicher machen.
            $table->string('replacement', 120)->nullable();
            $table->string('reason', 191)->nullable();

            $table->datetimes();

            $table->unique(['organization_id', 'kind', 'term'], 'markenbegriff_unique');
        });

        // Referenzmaterial der Praxis.
        //
        // **Die Erklaerung steht im Wortlaut dabei.** Eine Praxis, die
        // gefragt wird "womit wollen Sie werben", laedt Vorher-Nachher-Bilder
        // hoch -- das ist der wahrscheinlichste Fall, nicht der Randfall, und
        // er ist zweimal falsch: Gesundheitsdaten einer dritten Person und
        // seit BGH I ZR 170/24 verbotene Werbung.
        //
        // Automatisch pruefen kann dieses Paket nichts; die Bildpruefung
        // kommt mit WP-30. Was bleibt, ist der Nachweis, was zugesichert
        // wurde -- wie bei den Einwilligungen in WP-18 mit Wortlaut, Person
        // und Zeitpunkt.
        Schema::create('brand_references', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('kind', 32);
            $table->string('title', 191);
            $table->text('note')->nullable();

            $table->text('declaration_text');
            $table->binary('declared_by_user_id', 16, true)->nullable();
            $table->datetime('declared_at');

            $table->foreign('declared_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->datetimes();

            $table->index(['organization_id', 'kind'], 'markenreferenz_art_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_references');
        Schema::dropIfExists('brand_terms');
        Schema::dropIfExists('brand_guides');
    }
};
