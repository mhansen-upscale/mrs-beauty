<?php

declare(strict_types=1);

namespace Tests\Feature\Werbung;

use App\Enums\ConnectionStatus;
use App\Enums\Role;
use App\Models\AdAccount;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Eine Praxis mit verbundenem Werbekonto.
 *
 * Die Kennung traegt Metas Praefix: `act_…`. Wer sie ohne einsetzt, bekommt
 * von der Graph-API eine 400, die nach allem Moeglichen aussieht, nur nicht
 * nach einem fehlenden Praefix.
 */
final class Werbeaufbau
{
    public readonly Organization $organisation;

    public readonly AdAccount $konto;

    public const KONTO = 'act_1234567890';

    public function __construct(?Organization $organisation = null, ?CarbonImmutable $tokenAblauf = null)
    {
        $this->organisation = alsMandant($organisation);

        $konto = new AdAccount;
        $konto->external_id = self::KONTO;
        $konto->name = 'Praxis Werbung';
        $konto->currency = 'EUR';
        $konto->timezone = 'Europe/Berlin';
        $konto->business_external_id = '998877';

        // Ein verbundenes Konto hat eine Seite -- ohne sie entsteht fuer
        // Ziele wie OUTCOME_LEADS keine brauchbare Anzeigengruppe. Wer das
        // Gegenteil pruefen will, setzt sie im Testfall auf null.
        $konto->page_external_id = '778899';
        $konto->status = ConnectionStatus::Active;
        $konto->access_token = 'systembenutzer-token';
        $konto->token_expires_at = $tokenAblauf;
        $konto->connected_at = CarbonImmutable::now();
        $konto->save();

        $this->konto = $konto;
    }

    /**
     * Wer in dieser Praxis Werbung verwaltet.
     *
     * Als Methode und nicht als globale Testfunktion: Pest teilt
     * Hilfsfunktionen ueber alle Dateien, und zwei Dateien mit demselben
     * Namen lassen den Lauf platzen -- derselbe Fallstrick wie bei
     * `inhaberin` in WP-06.
     */
    public static function leitung(Organization $organisation): User
    {
        return User::factory()->fuer($organisation, Role::Owner)->create();
    }

    /**
     * Eine Seite der Graph-API.
     *
     * @param  list<array<string, mixed>>  $zeilen
     * @return array<string, mixed>
     */
    public static function seite(array $zeilen, ?string $weiter = null): array
    {
        $antwort = ['data' => $zeilen];

        if ($weiter !== null) {
            $antwort['paging'] = ['next' => $weiter];
        }

        return $antwort;
    }

    /**
     * @param  array<string, mixed>  $zusatz
     * @return array<string, mixed>
     */
    public static function kampagne(string $kennung, string $name, array $zusatz = []): array
    {
        return array_merge([
            'id' => $kennung,
            'name' => $name,
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'objective' => 'OUTCOME_LEADS',
            'daily_budget' => '2500',
            'start_time' => '2026-09-01T08:00:00+0200',
        ], $zusatz);
    }

    /**
     * @param  array<string, mixed>  $zusatz
     * @return array<string, mixed>
     */
    public static function gruppe(string $kennung, string $name, string $kampagne, array $zusatz = []): array
    {
        return array_merge([
            'id' => $kennung,
            'name' => $name,
            'campaign_id' => $kampagne,
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'optimization_goal' => 'LEAD_GENERATION',
        ], $zusatz);
    }

    /**
     * @param  array<string, mixed>  $zusatz
     * @return array<string, mixed>
     */
    public static function anzeige(string $kennung, string $name, string $gruppe, array $zusatz = []): array
    {
        return array_merge([
            'id' => $kennung,
            'name' => $name,
            'adset_id' => $gruppe,
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'creative' => ['id' => 'creative-'.$kennung],
        ], $zusatz);
    }

    /**
     * Metas Fehlerform.
     *
     * @return array<string, mixed>
     */
    public static function fehler(int $code, int $unterCode = 0, string $meldung = 'Fehler'): array
    {
        return ['error' => [
            'code' => $code,
            'error_subcode' => $unterCode,
            'message' => $meldung,
            'type' => 'OAuthException',
        ]];
    }
}
