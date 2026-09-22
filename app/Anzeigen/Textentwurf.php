<?php

declare(strict_types=1);

namespace App\Anzeigen;

use App\Agent\Anfrage;
use App\Agent\ModellNichtErreichbar;
use App\Agent\Sprachmodell;
use App\Marke\Markenprofil;

/**
 * Erzeugt Anzeigentexte aus dem Brand Guide.
 *
 * **Der Brand Guide ist ein Datenblock** (Regel 5). Anweisung, Rolle und
 * Aufgabe stammen aus dem Produkt; was die Praxis eingetragen hat, steht im
 * abgegrenzten Block. Text wird kopiert -- was in einer Agenturmail stand,
 * steht dann im Brand Guide, und "Ignoriere deine Anweisungen" ist dort so
 * wenig eine Anweisung wie in einer WhatsApp-Nachricht.
 *
 * Die Trennung liegt in `App\Agent\Anfrage` und ist damit eine Eigenschaft
 * der Datenstruktur, nicht eine Frage der Sorgfalt hier.
 */
final class Textentwurf
{
    /**
     * **Die Anweisung steht hier und nirgends sonst.**
     *
     * Was sie nicht erlaubt, entsteht nicht -- und die HWG-Pruefung dahinter
     * ist die zweite Verteidigungslinie, nicht die erste.
     */
    private const AUFTRAG = <<<'TEXT'
        Du schreibst Entwuerfe fuer Anzeigen einer Praxis fuer aesthetische Behandlungen in Deutschland.

        Halte dich an folgende Vorgaben:
        - Deutsch, in der angegebenen Ansprache und Tonalitaet.
        - Nenne die beworbene Leistung ruhig beim Namen. Das ist erlaubt.
        - **Keine Erfolgsversprechen, keine Garantien, keine Aussagen ueber Schmerz- oder Risikofreiheit.**
        - **Keine Vorher-Nachher-Darstellung, kein Hinweis auf Behandlungsergebnisse.**
        - Keine Empfehlungen, Dankschreiben oder Patientenstimmen.
        - Keine Superlative ohne Beleg, keine Angst erzeugenden Formulierungen.
        - Bei einem Eingriff: Hinweis auf Risiken und ein Beratungsgespraech.

        Antworte ausschliesslich mit JSON in dieser Form:
        {"varianten":[{"ueberschrift":"...","text":"...","beschreibung":"...","handlungsaufruf":"..."}]}

        Genau drei Varianten.
        TEXT;

    public function __construct(
        private readonly Sprachmodell $modell,
        private readonly Markenprofil $profil,
    ) {}

    /**
     * @return list<Entwurf>
     *
     * @throws ModellNichtErreichbar
     */
    public function entwuerfe(): array
    {
        $antwort = $this->modell->frage(new Anfrage(
            // Die Laengen stehen in der Konfiguration, damit Auftrag und
            // Eingabemaske nicht auseinanderlaufen.
            anweisung: self::AUFTRAG."\n".sprintf(
                'Ueberschrift hoechstens %d Zeichen, Text hoechstens %d.',
                (int) config('mrs.ads.text.headline_max'),
                (int) config('mrs.ads.text.body_max'),
            ),
            daten: (string) json_encode($this->profil->alsDatenblock(), JSON_UNESCAPED_UNICODE),
            modell: (string) config('mrs.agent.model'),
            hoechstenTokens: 1500,
        ));

        return $this->ausAntwort($antwort->inhalt);
    }

    /**
     * @return list<Entwurf>
     */
    private function ausAntwort(string $roh): array
    {
        $daten = json_decode($this->nurJson($roh), true);
        $varianten = is_array($daten) ? ($daten['varianten'] ?? null) : null;

        if (! is_array($varianten)) {
            return [];
        }

        $entwuerfe = [];

        foreach ($varianten as $variante) {
            $ueberschrift = $this->text(data_get($variante, 'ueberschrift'));
            $text = $this->text(data_get($variante, 'text'));

            // Ein Entwurf ohne Ueberschrift oder Text ist keiner. Lieber
            // zwei brauchbare als drei, von denen einer leer ist.
            if ($ueberschrift === null || $text === null) {
                continue;
            }

            $entwuerfe[] = new Entwurf(
                ueberschrift: $ueberschrift,
                text: $text,
                beschreibung: $this->text(data_get($variante, 'beschreibung')),
                handlungsaufruf: $this->text(data_get($variante, 'handlungsaufruf')),
            );
        }

        return $entwuerfe;
    }

    /**
     * Schneidet heraus, was zwischen der ersten und der letzten geschweiften
     * Klammer steht.
     *
     * Modelle stellen gern einen Satz voran. Der Aufrufer soll daran nicht
     * scheitern -- derselbe Umgang wie im Einordner aus WP-22.
     */
    private function nurJson(string $roh): string
    {
        $anfang = mb_strpos($roh, '{');
        $ende = mb_strrpos($roh, '}');

        return $anfang === false || $ende === false
            ? '{}'
            : mb_substr($roh, $anfang, $ende - $anfang + 1);
    }

    private function text(mixed $wert): ?string
    {
        if (! is_string($wert)) {
            return null;
        }

        $wert = trim($wert);

        return $wert === '' ? null : $wert;
    }
}
