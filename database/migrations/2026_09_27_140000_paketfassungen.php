<?php

declare(strict_types=1);

use App\Support\Uuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Das Paket in Fassungen (WP-06b, Entscheidung B20)
|--------------------------------------------------------------------------
|
| **Ein Preis bei Stripe ist unveraenderlich. Also ist es das Paket auch.**
| Speichern legt eine neue Fassung an; jedes Abo zeigt auf die Fassung seines
| Abschlusses (Bestandsschutz).
|
| **Global, ohne organization_id:** das Paket gehoert dem Betreiber, nicht
| einer Praxis.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_versions', function (Blueprint $table): void {
            $table->binary('id', 16, true)->primary();
            $table->unsignedInteger('number')->unique();
            $table->string('name');

            // Netto, in Cent (B15).
            $table->unsignedInteger('base_cents');
            $table->unsignedInteger('setup_cents');
            $table->unsignedInteger('topup_cents');
            $table->unsignedInteger('image_price_cents');

            // Was ein Monat enthaelt und was ein Block bringt.
            $table->unsignedInteger('included_messages');
            $table->unsignedInteger('included_agent_runs');
            $table->unsignedInteger('included_images');
            $table->unsignedInteger('topup_messages');
            $table->unsignedInteger('topup_agent_runs');

            $table->unsignedSmallInteger('trial_days');

            // Bei Stripe -- leer im Testbetrieb ohne Schluessel.
            $table->string('stripe_product_id')->nullable();
            $table->string('stripe_price_base')->nullable()->index();
            $table->string('stripe_price_setup')->nullable();
            $table->string('stripe_price_topup')->nullable();
            $table->string('stripe_price_image')->nullable();
            $table->string('stripe_state', 16);
            $table->string('stripe_error', 500)->nullable();

            // Stellt diese Fassung auch den Bestand um -- entschieden je
            // Aenderung, nicht einmal fuer immer (B20).
            $table->boolean('migrate_existing')->default(false);

            $table->string('reason', 500);
            $table->binary('created_by_user_id', 16, true)->nullable();

            // Ab hier gilt sie fuer neue Abschluesse -- erst, wenn Stripe alle
            // Preise hat.
            $table->datetime('activated_at')->nullable();
            $table->datetimes();
        });

        $this->legeAppendOnlyAn();

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->binary('plan_version_id', 16, true)->nullable()->after('status');
            $table->foreign('plan_version_id')->references('id')->on('plan_versions');

            // Eine Umstellung wirkt zur naechsten Periode, nicht mitten im
            // Monat -- bis dahin wartet sie hier.
            $table->binary('pending_plan_version_id', 16, true)->nullable()->after('plan_version_id');
            $table->foreign('pending_plan_version_id')->references('id')->on('plan_versions');
        });

        $this->legeErsteFassungAn();
    }

    /**
     * **Eine Fassung, an der schon abgerechnet wurde, aendert sich nicht.**
     * Wie `audit_logs`: die Sperre gehoert in die Datenbank.
     *
     * Solange eine Fassung nicht gilt (`activated_at IS NULL`), darf der
     * Auftrag ihre Stripe-Felder nachtragen -- sonst nichts.
     */
    private function legeAppendOnlyAn(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER plan_versions_unveraenderlich
            BEFORE UPDATE ON plan_versions
            FOR EACH ROW
            BEGIN
                IF OLD.activated_at IS NOT NULL
                    OR NEW.number <> OLD.number
                    OR NEW.name <> OLD.name
                    OR NEW.base_cents <> OLD.base_cents
                    OR NEW.setup_cents <> OLD.setup_cents
                    OR NEW.topup_cents <> OLD.topup_cents
                    OR NEW.image_price_cents <> OLD.image_price_cents
                    OR NEW.included_messages <> OLD.included_messages
                    OR NEW.included_agent_runs <> OLD.included_agent_runs
                    OR NEW.included_images <> OLD.included_images
                    OR NEW.topup_messages <> OLD.topup_messages
                    OR NEW.topup_agent_runs <> OLD.topup_agent_runs
                    OR NEW.trial_days <> OLD.trial_days
                    OR NEW.migrate_existing <> OLD.migrate_existing
                    OR NEW.reason <> OLD.reason THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'plan_versions ist unveraenderlich: neue Fassung anlegen (WP-06b, B20).';
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER plan_versions_kein_delete
            BEFORE DELETE ON plan_versions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'plan_versions ist unveraenderlich: eine Fassung wird nicht geloescht (WP-06b, B20).';
            END
        SQL);
    }

    /**
     * Fassung 1 aus dem, was bis hier galt: `config/mrs.php` und die
     * Preis-IDs in `config/services.php`. **Die einzige Stelle**, an der diese
     * Werte noch gelesen werden (WP-06b AK 16).
     */
    private function legeErsteFassungAn(): void
    {
        $jetzt = CarbonImmutable::now();
        $id = Uuid::generate();

        DB::table('plan_versions')->insert([
            'id' => $id,
            'number' => 1,
            'name' => (string) config('app.name', 'Mrs. Beauty'),
            'base_cents' => (int) config('mrs.billing.prices.base_cents'),
            'setup_cents' => (int) config('mrs.billing.prices.setup_cents'),
            'topup_cents' => (int) config('mrs.billing.prices.topup_cents'),
            'image_price_cents' => (int) config('mrs.billing.image_price_cents'),
            'included_messages' => (int) config('mrs.billing.included.messages'),
            'included_agent_runs' => (int) config('mrs.billing.included.agent_runs'),
            'included_images' => (int) config('mrs.billing.included.images'),
            'topup_messages' => (int) config('mrs.billing.topup.messages'),
            'topup_agent_runs' => (int) config('mrs.billing.topup.agent_runs'),
            'trial_days' => (int) config('mrs.billing.trial_days'),
            'stripe_price_base' => $this->preis('services.stripe.price_id'),
            'stripe_price_setup' => $this->preis('services.stripe.setup_price_id'),
            'stripe_price_topup' => $this->preis('services.stripe.topup_price_id'),
            'stripe_price_image' => $this->preis('services.stripe.image_price_id'),
            'stripe_state' => 'ready',
            'migrate_existing' => false,
            'reason' => 'Fassung 1 aus der bisherigen Konfiguration (WP-06b)',
            'activated_at' => $jetzt,
            'created_at' => $jetzt,
            'updated_at' => $jetzt,
        ]);

        DB::table('subscriptions')->update(['plan_version_id' => $id]);
    }

    private function preis(string $schluessel): ?string
    {
        $wert = config($schluessel);

        return is_string($wert) && $wert !== '' ? $wert : null;
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['plan_version_id']);
            $table->dropForeign(['pending_plan_version_id']);
            $table->dropColumn(['plan_version_id', 'pending_plan_version_id']);
        });

        DB::unprepared('DROP TRIGGER IF EXISTS plan_versions_unveraenderlich');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_versions_kein_delete');

        Schema::dropIfExists('plan_versions');
    }
};
