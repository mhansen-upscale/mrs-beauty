<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\Anfrage;
use App\Agent\Antwort;
use App\Agent\ModellNichtErreichbar;
use App\Agent\Sprachmodell;

/**
 * Ein Sprachmodell, das antwortet, was der Test will.
 *
 * **Und das ist der Punkt der Schnittstelle.** Ein Test gegen ein echtes
 * Modell prueft nicht dieses Produkt, sondern dessen Tagesform -- und er
 * kostet bei jedem Lauf Geld.
 *
 * Das Modell haelt fest, **was** es bekommen hat: daran laesst sich pruefen,
 * dass Anweisung und Daten getrennt bleiben (Regel 5).
 */
final class Testmodell implements Sprachmodell
{
    /** @var list<Anfrage> */
    public array $anfragen = [];

    /** @var list<string> */
    private array $antworten = [];

    public ?string $fehler = null;

    public bool $istAngebunden = true;

    /**
     * @param  list<string>  $antworten  In dieser Reihenfolge, eine je Aufruf.
     */
    public function __construct(array $antworten = [])
    {
        $this->antworten = $antworten;
    }

    public function frage(Anfrage $anfrage): Antwort
    {
        $this->anfragen[] = $anfrage;

        if (is_string($this->fehler)) {
            throw new ModellNichtErreichbar($this->fehler);
        }

        $inhalt = array_shift($this->antworten) ?? '';

        return new Antwort($inhalt, 'test-modell', eingabeTokens: 120, ausgabeTokens: 40);
    }

    public function angebunden(): bool
    {
        return $this->istAngebunden;
    }

    /**
     * Legt weitere Antworten nach -- fuer Dialoge ueber mehrere Nachrichten.
     *
     * @param  list<string>  $antworten
     */
    public function antworten(array $antworten): void
    {
        $this->antworten = $antworten;
    }

    /** Die Einordnung, wie das Modell sie liefern wuerde. */
    public static function einordnung(
        string $absicht = 'general_question',
        float $sicherheit = 0.9,
        ?string $behandlung = null,
        ?string $standort = null,
        ?string $zeitwunsch = null,
        ?string $name = null,
        ?string $behandler = null,
    ): string {
        return (string) json_encode([
            'absicht' => $absicht,
            'sicherheit' => $sicherheit,
            'behandlung' => $behandlung,
            'standort' => $standort,
            'behandler' => $behandler,
            'zeitwunsch' => $zeitwunsch,
            'name' => $name,
        ]);
    }
}
