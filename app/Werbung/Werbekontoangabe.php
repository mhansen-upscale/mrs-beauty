<?php

declare(strict_types=1);

namespace App\Werbung;

/**
 * Ein Werbekonto, wie Meta es anbietet -- noch nicht verbunden.
 *
 * Wer mehrere freigibt, waehlt eines aus. Das Raten waere hier besonders
 * teuer: die Kampagnen der falschen Praxis in der eigenen Auswertung faellt
 * spaet auf und ist schwer wieder herauszubekommen.
 */
final class Werbekontoangabe
{
    public function __construct(
        public readonly string $kennung,
        public readonly ?string $name,
        public readonly ?string $waehrung,
        public readonly ?string $zeitzone,
        public readonly ?string $business,
        public readonly bool $nutzbar,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kennung' => $this->kennung,
            'name' => $this->name,
            'waehrung' => $this->waehrung,
            'zeitzone' => $this->zeitzone,
            'business' => $this->business,
            'nutzbar' => $this->nutzbar,
        ];
    }
}
