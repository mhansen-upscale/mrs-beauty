<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wovon ein Referenzbild etwas zeigt.
 *
 * **Die Liste enthaelt bewusst keine Behandlungsergebnisse.** Sie ist die
 * Bibliothek rechtssicherer Alternativformate aus docs/produkt.md in Form
 * einer Auswahl: was hier waehlbar ist, ist das, womit geworben werden darf.
 */
enum BrandReferenceKind: string
{
    case Raeume = 'raeume';

    case Team = 'team';

    case Ablauf = 'ablauf';

    case Beispielanzeige = 'beispielanzeige';

    public function label(): string
    {
        return match ($this) {
            self::Raeume => 'Räumlichkeiten',
            self::Team => 'Team und Behandler',
            self::Ablauf => 'Ablauf und Beratung',
            self::Beispielanzeige => 'Beispielanzeige',
        };
    }

    public function hinweis(): string
    {
        return match ($this) {
            self::Raeume => 'Empfang, Behandlungsraum, Wartebereich.',
            self::Team => 'Porträts und Aufnahmen des Teams — mit deren Einverständnis.',
            self::Ablauf => 'Beratungsgespräch, Vorbereitung, Geräte.',
            self::Beispielanzeige => 'Eine Anzeige, die Ihnen gefällt — als Vorbild für Stil und Aufbau.',
        };
    }
}
