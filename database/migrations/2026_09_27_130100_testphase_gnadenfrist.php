<?php

declare(strict_types=1);

use App\Support\Uuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Gnadenfrist fuer die Testphase (WP-34c, B18)
|--------------------------------------------------------------------------
|
| Bis hier wurde das Ende der Testphase nicht durchgesetzt. Wer seit mehr als
| `trial_days` ohne Abo arbeitet -- also womoeglich jede Pilotpraxis --, waere
| mit dem Ausrollen ueber Nacht gesperrt.
|
| **Jede Praxis bekommt eine Abo-Zeile**, und jede abgelaufene Testphase ohne
| Stripe-Abo endet fruehestens `trial_gnadenfrist_tage` nach heute. Was
| bezahlt ist, bleibt unberuehrt.
|
| Am Modell vorbei: eine Migration laeuft ohne Mandanten, und die Zeilen
| gehoeren jeweils einer anderen Praxis.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        $jetzt = CarbonImmutable::now();
        $gnadenfrist = $jetzt->addDays((int) config('mrs.billing.trial_gnadenfrist_tage'));
        $testphase = (int) config('mrs.billing.trial_days');

        // Das regulaere Ende -- oder die Gnadenfrist, wenn es schon vorbei ist.
        // Eine Testphase, die ohnehin noch laeuft, bleibt, wie sie ist.
        $ende = function (string $angelegt, ?string $bisher) use ($jetzt, $gnadenfrist, $testphase): CarbonImmutable {
            $regulaer = $bisher !== null
                ? CarbonImmutable::parse($bisher)
                : CarbonImmutable::parse($angelegt)->addDays($testphase);

            return $regulaer->lessThanOrEqualTo($jetzt) ? $gnadenfrist : $regulaer;
        };

        $angelegt = DB::table('organizations')->pluck('created_at', 'id');

        foreach ($angelegt as $praxis => $zeitpunkt) {
            $zeile = DB::table('subscriptions')->where('organization_id', $praxis)->first();

            if ($zeile === null) {
                DB::table('subscriptions')->insert([
                    'id' => Uuid::generate(),
                    'organization_id' => $praxis,
                    'status' => 'trialing',
                    'trial_ends_at' => $ende((string) $zeitpunkt, null),
                    'created_at' => $jetzt,
                    'updated_at' => $jetzt,
                ]);

                continue;
            }

            if ($zeile->status !== 'trialing' || $zeile->stripe_subscription_id !== null) {
                continue;
            }

            DB::table('subscriptions')->where('id', $zeile->id)->update([
                'trial_ends_at' => $ende((string) $zeitpunkt, $zeile->trial_ends_at === null ? null : (string) $zeile->trial_ends_at),
                'updated_at' => $jetzt,
            ]);
        }
    }

    /**
     * Nichts zurueckzudrehen: eine verlaengerte Testphase wieder zu kuerzen,
     * sperrte genau die Praxen aus, die diese Migration schuetzen soll.
     */
    public function down(): void {}
};
