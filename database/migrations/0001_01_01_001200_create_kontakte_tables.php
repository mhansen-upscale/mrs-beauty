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
        // WP-11 hat `contacts` im Mindestumfang angelegt und die Telefonnummer
        // **ausdruecklich** ohne blinden Index gelassen: ohne E.164 ergeben
        // "+49 170 1234567" und "01701234567" zwei Hashes, und die Suche
        // traefe still nie. Die Normalisierung steht jetzt.
        Schema::table('contacts', function (Blueprint $table): void {
            $table->binary('phone_bidx', 32, true)->nullable()->index()->after('last_name_bidx');
        });

        // Entscheidung D5: die Bruecke zwischen Kanaelen.
        //
        // Eine Person schreibt ueber Instagram, ruft spaeter an und bucht ueber
        // die Website -- drei Kennungen, ein Mensch.
        Schema::create('channel_identities', function (Blueprint $table): void {
            TenantSchema::base($table);

            // **Bewusst nullable.** Die erste Nachricht kommt an, bevor jemand
            // weiss, wer da schreibt. Ein erzwungener Bezug erzeugte an dieser
            // Stelle Karteileichen -- oder falsche Kontakte.
            TenantSchema::reference($table, 'contact_id', 'contacts', nullable: true, cascadeOnDelete: true);

            $table->string('channel', 32);

            // Die Kennung identifiziert einen Menschen bei einem Anbieter und
            // ist damit personenbezogen (Regel 3): verschluesselt, mit blindem
            // Index fuer den Gleichheitsvergleich.
            $table->binary('external_id');
            $table->binary('external_id_bidx', 32, true);

            // Was der Anbieter als Anzeigenamen liefert. Auch personenbezogen.
            $table->binary('display_name')->nullable();

            $table->datetime('last_seen_at')->nullable();
            $table->datetimes();

            // **Eindeutig ist das Tripel aus Organisation, Kanal und Kennung.**
            // Meta vergibt Nutzerkennungen je Seite unterschiedlich; dieselbe
            // Kennung in zwei Organisationen sind zwei Identitaeten (D10).
            $table->unique(['organization_id', 'channel', 'external_id_bidx'], 'kanalidentitaet_unique');

            $table->index(['contact_id', 'channel'], 'kanalidentitaet_kontakt_idx');
        });

        // Entscheidung D7: umkehrbar heisst mit Snapshot.
        Schema::create('contact_merges', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'winner_contact_id', 'contacts', cascadeOnDelete: true);

            // **Ohne Fremdschluessel, mit Absicht.** Der Verlierer ist nach dem
            // Zusammenfuehren geloescht -- echt, ohne Soft Delete (A12). Seine
            // Kennung bleibt als Spur, damit der Vorgang nachvollziehbar ist.
            $table->binary('loser_contact_id', 16, true);

            TenantSchema::reference($table, 'merged_by_user_id', 'users', nullable: true);

            // Die Felder des Verlierers und was verschoben wurde. Enthaelt
            // selbst Personendaten, liegt deshalb verschluesselt und befristet.
            $table->binary('snapshot')->nullable();
            $table->datetime('snapshot_expires_at');

            $table->datetime('reverted_at')->nullable();
            $table->datetimes();

            $table->index(['organization_id', 'snapshot_expires_at'], 'zusammenfuehrung_ablauf_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_merges');
        Schema::dropIfExists('channel_identities');

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex(['phone_bidx']);
            $table->dropColumn('phone_bidx');
        });
    }
};
