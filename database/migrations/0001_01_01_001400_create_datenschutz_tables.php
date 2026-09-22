<?php

declare(strict_types=1);

use App\Support\Schema\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notizen -- verschluesselt, mit Urheber.
        //
        // Polymorph und damit **ohne** zusammengesetzten Fremdschluessel: die
        // uebrigen Tabellen dieses Projekts verweisen mit (id, organization_id)
        // aufeinander (Entscheidung A2), ein polymorpher Verweis kann das
        // nicht. Der Mandantenbezug steht trotzdem in der Zeile, und der
        // globale Scope greift -- was fehlt, ist die Zusicherung der
        // Datenbank, dass beide Seiten zum selben Mandanten gehoeren. Wer hier
        // eine Beziehung setzt, tut das ueber die Modelle.
        Schema::create('notes', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('notable_type', 64);
            $table->binary('notable_id', 16, true);

            TenantSchema::reference($table, 'author_user_id', 'users', nullable: true);

            // Der Grund, warum es diese Tabelle gibt und kein Freitextfeld am
            // Termin: hier steht die Zweckbindung daneben, und die Frist auch.
            $table->binary('body');

            $table->datetimes();

            $table->index(['notable_type', 'notable_id'], 'notiz_bezug_idx');
        });

        Schema::create('tags', function (Blueprint $table): void {
            TenantSchema::base($table);

            // **Nicht verschluesselt, und das ist eine Entscheidung.** Ein
            // Schlagwort ist eine Ordnungskategorie der Praxis, keine Aussage
            // ueber eine Person -- es muss sortierbar und zaehlbar bleiben.
            // Wer ein Schlagwort "Botox-Stammkundin" anlegt, hat ein
            // organisatorisches Problem, kein technisches.
            $table->string('name', 60);
            $table->datetimes();

            $table->unique(['organization_id', 'name'], 'schlagwort_unique');
        });

        Schema::create('taggables', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'tag_id', 'tags', cascadeOnDelete: true);

            $table->string('taggable_type', 64);
            $table->binary('taggable_id', 16, true);

            $table->datetimes();

            $table->unique(['tag_id', 'taggable_type', 'taggable_id'], 'schlagwort_bezug_unique');
            $table->index(['taggable_type', 'taggable_id'], 'schlagwort_traeger_idx');
        });

        // Anhaenge.
        //
        // Die Datei liegt **verschluesselt** ausserhalb der Datenbank, der
        // Datensatz haelt nur den Weg dorthin. Beim Loeschen muss beides weg
        // -- sonst bleiben Fotos auf dem Speicher liegen, waehrend der
        // Datensatz verschwunden ist.
        Schema::create('attachments', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('attachable_type', 64);
            $table->binary('attachable_id', 16, true);

            TenantSchema::reference($table, 'uploaded_by_user_id', 'users', nullable: true);

            $table->string('context', 32);

            // Der Dateiname traegt regelmaessig einen Personenbezug
            // ("befund-mueller.pdf") und liegt deshalb verschluesselt.
            $table->binary('original_name');

            $table->string('path', 191);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->binary('checksum', 32, true);

            // Virenpruefung: null heisst "noch nicht geprueft". Ein Anhang
            // ohne Befund wird nicht ausgeliefert.
            $table->datetime('scanned_at')->nullable();
            $table->string('scan_result', 32)->nullable();

            $table->datetime('expires_at')->nullable();
            $table->datetimes();

            $table->index(['attachable_type', 'attachable_id'], 'anhang_bezug_idx');
            $table->index(['organization_id', 'expires_at'], 'anhang_ablauf_idx');
        });

        // **Entscheidung C6 als Zusage der Datenbank, nicht als Vorsatz.**
        // Ein Chat-Anhang ohne Ablaufdatum ist nicht speicherbar -- auch nicht
        // ueber einen Seeder, eine Migration oder einen vergessenen Pfad.
        DB::statement(
            'ALTER TABLE attachments ADD CONSTRAINT anhang_chat_braucht_ablauf '
            ."CHECK (context <> 'chat' OR expires_at IS NOT NULL)"
        );

        // Einwilligungen -- an der Kanalidentitaet, nicht an der Person
        // (Entscheidung D8). Ein WhatsApp-Opt-in haengt an einer Rufnummer.
        Schema::create('consents', function (Blueprint $table): void {
            TenantSchema::base($table);

            TenantSchema::reference($table, 'channel_identity_id', 'channel_identities', cascadeOnDelete: true);

            $table->string('type', 32);
            $table->string('action', 16);

            // **Nicht der Verweis auf einen Text, sondern der Text selbst.**
            // Die Einwilligungserklaerung wird ueberarbeitet; wer nur die
            // Version speichert, kann spaeter nicht mehr zeigen, wozu jemand
            // tatsaechlich zugestimmt hat. Die Beweislast liegt beim
            // Verantwortlichen.
            $table->string('text_version', 32);
            $table->binary('text_snapshot');

            // Umstaende der Erteilung. Personenbezogen, also verschluesselt.
            $table->binary('ip_address')->nullable();
            $table->binary('user_agent')->nullable();

            $table->datetime('occurred_at');
            $table->datetimes();

            // Die Auswertung aus D9 laeuft genau darueber: je Identitaet und
            // Typ der juengste Eintrag.
            $table->index(['channel_identity_id', 'type', 'occurred_at'], 'einwilligung_stand_idx');
        });

        // Aufbewahrungsfristen je Mandant. Die Standardwerte stehen im Enum
        // (Entscheidung C7), abweichen darf jede Praxis.
        Schema::create('retention_policies', function (Blueprint $table): void {
            TenantSchema::base($table);

            $table->string('subject', 48);
            $table->unsignedInteger('retention_days');
            $table->string('action', 16);
            $table->boolean('is_active')->default(true);
            $table->datetimes();

            $table->unique(['organization_id', 'subject'], 'aufbewahrung_unique');
        });

        // Betroffenenrechte: Auskunft, Berichtigung, Loeschung.
        Schema::create('data_subject_requests', function (Blueprint $table): void {
            TenantSchema::base($table);

            // **Ohne Fremdschluessel, mit Absicht.** Nach einer Loeschung gibt
            // es den Kontakt nicht mehr; der Vorgang muss trotzdem bleiben --
            // er ist der Nachweis, dass geloescht wurde.
            $table->binary('contact_id', 16, true);

            TenantSchema::reference($table, 'requested_by_user_id', 'users', nullable: true);

            $table->string('type', 32);
            $table->string('status', 32)->default('open');

            // Was getan wurde, in Zahlen. **Keine Personendaten** -- sonst
            // waere der Nachweis der Loeschung selbst eine Kopie der
            // geloeschten Daten (Entscheidung C5, sinngemaess).
            $table->json('result')->nullable();

            $table->datetime('completed_at')->nullable();
            $table->datetimes();

            $table->index(['organization_id', 'status'], 'betroffenenrecht_offen_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_subject_requests');
        Schema::dropIfExists('retention_policies');
        Schema::dropIfExists('consents');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('notes');
    }
};
