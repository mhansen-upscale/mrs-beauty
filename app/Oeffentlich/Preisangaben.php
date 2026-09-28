<?php

declare(strict_types=1);

namespace App\Oeffentlich;

use App\Models\PlanVersion;

/**
 * Die Preise der Startseite -- aus der geltenden Paketfassung (WP-38, B20).
 *
 * **Kein Rueckfall auf die Konfiguration.** Die Werte in `mrs.billing` sind
 * nur noch Startwerte der ersten Fassung; was gilt, steht im Backoffice unter
 * Paket. Ohne geltende Fassung gibt es keine Preise, keine erfundenen.
 *
 * Die Namen folgen der Abo-Seite der Praxis (AboController::edit), damit
 * dieselbe Zahl nicht an zwei Stellen zwei Namen traegt.
 */
final readonly class Preisangaben
{
    private function __construct(private PlanVersion $fassung) {}

    public static function aus(?PlanVersion $fassung): ?self
    {
        return $fassung instanceof PlanVersion ? new self($fassung) : null;
    }

    /**
     * @return array{
     *     paket: string,
     *     grundpreisCent: int,
     *     einrichtungCent: int,
     *     testphaseTage: int,
     *     enthalten: array{nachrichten: int, assistenzlaeufe: int, bilder: int},
     *     aufstockung: array{preisCent: int, nachrichten: int, assistenzlaeufe: int},
     *     bildpreisCent: int,
     * }
     */
    public function alsArray(): array
    {
        return [
            'paket' => $this->fassung->name,
            'grundpreisCent' => $this->fassung->base_cents,
            'einrichtungCent' => $this->fassung->setup_cents,
            'testphaseTage' => $this->fassung->trial_days,
            'enthalten' => [
                'nachrichten' => $this->fassung->included_messages,
                'assistenzlaeufe' => $this->fassung->included_agent_runs,
                'bilder' => $this->fassung->included_images,
            ],
            'aufstockung' => [
                'preisCent' => $this->fassung->topup_cents,
                'nachrichten' => $this->fassung->topup_messages,
                'assistenzlaeufe' => $this->fassung->topup_agent_runs,
            ],
            'bildpreisCent' => $this->fassung->image_price_cents,
        ];
    }
}
