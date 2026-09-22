<?php

declare(strict_types=1);

namespace App\Marke;

use App\Enums\BrandTermKind;
use App\Models\BrandGuide;
use App\Models\BrandReference;
use App\Models\BrandTerm;

/**
 * Der Brand Guide als Datenblock -- die Form, in der WP-31 ihn bekommt.
 *
 * **Datenblock, nicht Anweisung** (Regel 5). Anders als das Praxiswissen des
 * Assistenten, das aus dem Produkt stammt und als Anweisung in den Prompt
 * geht, ist hier jedes Feld von Hand eingetragen -- und Text wird kopiert.
 * Was in einer Agenturmail stand, steht dann im Brand Guide, und "Ignoriere
 * deine Anweisungen" ist dort so wenig eine Anweisung wie in einer
 * WhatsApp-Nachricht.
 *
 * **Nichts wird ergaenzt.** Eine leere Praxis ergibt ein leeres Profil, kein
 * Platzhalterprofil. Ein duenner Vorschlag ist ehrlicher als ein voller aus
 * Erfundenem -- und der Reifegrad sagt, woran es liegt.
 */
final class Markenprofil
{
    public function __construct(private readonly Reifegrad $reifegrad) {}

    public function guide(): ?BrandGuide
    {
        return BrandGuide::query()->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function alsDatenblock(): array
    {
        $guide = $this->guide();

        return [
            'ton' => $guide?->tone?->value,
            'tonBeschreibung' => $guide?->tone?->beschreibung(),
            'ansprache' => $guide?->address_form?->value,
            'zielgruppe' => $this->text($guide?->audience),
            'positionierung' => $this->text($guide?->positioning),
            'claim' => $this->text($guide?->claim),
            'tabuthemen' => $this->text($guide?->no_go_topics),
            'bevorzugteBegriffe' => $this->begriffe(BrandTermKind::Bevorzugt),
            'verboteneBegriffe' => $this->begriffe(BrandTermKind::Verboten),
            'referenzen' => $this->referenzen(),
        ];
    }

    /**
     * @return array{anteil: int, fehlt: list<string>}
     */
    public function reifegrad(): array
    {
        return $this->reifegrad->fuer($this->guide());
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function begriffe(BrandTermKind $art): array
    {
        /** @var list<array<string, string|null>> */
        return BrandTerm::query()
            ->art($art)
            ->orderBy('term')
            ->get()
            ->map(fn (BrandTerm $begriff): array => [
                'begriff' => $begriff->term,
                'ersatz' => $begriff->replacement,
                'begruendung' => $begriff->reason,
            ])
            ->values()
            ->all();
    }

    /**
     * Was es an Material gibt -- als Beschreibung, nicht als Datei.
     *
     * Die Bilder selbst gehen erst in WP-31 an ein Modell, und dann ueber die
     * Ablage mit ihrer Freigabe (WP-33), nicht ueber diese Liste.
     *
     * @return list<array<string, string>>
     */
    private function referenzen(): array
    {
        /** @var list<array<string, string>> */
        return BrandReference::query()
            ->orderBy('created_at')
            ->get()
            ->map(fn (BrandReference $referenz): array => [
                'art' => $referenz->kind->value,
                'artText' => $referenz->kind->label(),
                'titel' => $referenz->title,
            ])
            ->values()
            ->all();
    }

    private function text(?string $wert): ?string
    {
        $wert = $wert === null ? null : trim($wert);

        return $wert === '' ? null : $wert;
    }
}
