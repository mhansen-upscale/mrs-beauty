<?php

declare(strict_types=1);

namespace App\Attribution\Meta;

use App\Models\Contact;
use App\Support\Telefonnummer;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Der Payload der Conversions API -- **abschliessend**.
 *
 * **Die eine Stelle, an der er entsteht.** Ein Payload, der beim Hinausgehen
 * zusammengebaut wird, entzieht sich dem Test; dieser hier wird gebaut,
 * geprueft und dann verschickt.
 *
 * Erlaubt sind drei Ereignisnamen und ein fest umrissener Satz Nutzerdaten.
 * Verboten sind `treatment_id`, Behandlungsname, Kategorie,
 * Katalogbezeichnung, `content_name`, `content_category`, `content_ids` und
 * jede Wertuebermittlung, aus der sich eine Behandlung ableiten liesse
 * (Regel 2, docs/fachlogik/attribution.md).
 *
 * **Es gibt kein custom_data.** Nicht weil gerade nichts hineingehoert,
 * sondern damit nie etwas hineinkommt: ein leeres Feld fuellt sich.
 */
final class Konversionsereignis
{
    /** Abschliessend. Was hier fehlt, geht nicht hinaus. */
    public const ERLAUBT = ['Lead', 'Schedule', 'Contact'];

    public function __construct(
        public readonly string $name,

        /**
         * **Dieselbe Kennung wie im Pixel.** Ohne sie zaehlt Meta doppelt --
         * Pixel und Server melden dasselbe Ereignis.
         */
        public readonly string $ereignisId,

        public readonly CarbonImmutable $zeitpunkt,
        public readonly ?Contact $kontakt = null,
        public readonly ?string $klickId = null,
        public readonly ?string $browserId = null,
        public readonly string $quelle = 'website',
    ) {
        if (! in_array($name, self::ERLAUBT, true)) {
            throw new InvalidArgumentException("Ereignis {$name} ist nicht erlaubt.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_name' => $this->name,
            'event_id' => $this->ereignisId,
            'event_time' => $this->zeitpunkt->getTimestamp(),
            'action_source' => $this->quelle,
            'user_data' => $this->nutzerdaten(),
        ];
    }

    /**
     * Gehasht, und **vorher normalisiert**.
     *
     * Hashen ohne Normalisieren ist kein Hashen: "Anna@Beispiel.DE " und
     * "anna@beispiel.de" ergeben verschiedene Werte, und Meta ordnet nichts
     * zu. Der Fehler faellt nie auf -- es kommt einfach keine Zuordnung
     * zustande.
     *
     * @return array<string, mixed>
     */
    private function nutzerdaten(): array
    {
        $daten = [];

        if ($this->kontakt instanceof Contact) {
            $mail = $this->kontakt->email;

            if (is_string($mail) && $mail !== '') {
                $daten['em'] = [hash('sha256', mb_strtolower(trim($mail)))];
            }

            $telefon = $this->kontakt->phone;

            if (is_string($telefon) && $telefon !== '') {
                // Ziffern, ohne fuehrende Nullen und ohne Plus -- Metas Form.
                $ziffern = ltrim((string) preg_replace('/\D/', '', Telefonnummer::e164($telefon) ?? $telefon), '0');

                if ($ziffern !== '') {
                    $daten['ph'] = [hash('sha256', $ziffern)];
                }
            }
        }

        if ($this->klickId !== null) {
            $daten['fbc'] = $this->klickId;
        }

        if ($this->browserId !== null) {
            $daten['fbp'] = $this->browserId;
        }

        return $daten;
    }
}
