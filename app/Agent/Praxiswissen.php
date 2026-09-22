<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\Location;
use App\Models\Organization;
use App\Models\Treatment;

/**
 * Was der Agent ueber die Praxis wissen darf -- und mehr nicht.
 *
 * **Alles hier stammt aus dem Produkt**, keine Zeile davon aus einer
 * Nachricht (Regel 5). Es geht als Anweisung in den Prompt, waehrend der Text
 * der Person im Datenblock bleibt.
 *
 * Und es ist **abschliessend**: was nicht im Katalog steht, kennt der Agent
 * nicht. Der Katalog ist die einzige Quelle fuer Behandlungsnamen und Preise
 * (Entscheidungen D2, G5).
 */
final class Praxiswissen
{
    /**
     * Die Behandlungen, wie der Agent sie sehen darf.
     *
     * @return list<array{uuid: string, name: string, dauer: int|null, preis: string|null}>
     */
    public function behandlungen(): array
    {
        /** @var list<array{uuid: string, name: string, dauer: int|null, preis: string|null}> */
        return Treatment::query()
            ->aktiv()
            ->orderBy('name')
            ->get()
            ->map(fn (Treatment $behandlung): array => [
                'uuid' => (string) $behandlung->uuid,
                'name' => $behandlung->name,
                'dauer' => null,
                'preis' => $this->preis($behandlung),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{uuid: string, name: string, ort: string}>
     */
    public function standorte(): array
    {
        /** @var list<array{uuid: string, name: string, ort: string}> */
        return Location::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Location $standort): array => [
                'uuid' => (string) $standort->uuid,
                'name' => $standort->name,
                'ort' => (string) $standort->city,
            ])
            ->values()
            ->all();
    }

    /**
     * Der Preis als Text -- **so, wie er im Katalog steht**.
     *
     * Ohne Preis im Katalog steht hier nichts, und der Agent darf keinen
     * nennen. Eine Spanne bleibt eine Spanne: "ab 250 Euro" ist eine Aussage,
     * "250 Euro" waere eine andere.
     */
    private function preis(Treatment $behandlung): ?string
    {
        $von = $behandlung->price_from_cents;
        $bis = $behandlung->price_to_cents;

        if ($von === null && $bis === null) {
            return null;
        }

        if ($von !== null && $bis !== null && $von !== $bis) {
            return $this->euro($von).' bis '.$this->euro($bis);
        }

        return 'ab '.$this->euro($von ?? (int) $bis);
    }

    private function euro(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.').' Euro';
    }

    /** Der Name der Praxis, wie er in einer Antwort vorkommen darf. */
    public function praxisname(Organization $organisation): string
    {
        return $organisation->name;
    }
}
