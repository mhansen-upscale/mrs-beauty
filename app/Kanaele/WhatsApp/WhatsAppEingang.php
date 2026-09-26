<?php

declare(strict_types=1);

namespace App\Kanaele\WhatsApp;

use App\Enums\MessageCostCategory;
use App\Enums\MessageStatus;
use App\Kanaele\Eingangsnachricht;
use App\Kanaele\Kanaleingang;
use App\Kanaele\Rueckmeldung;
use App\Kanaele\Rueckmeldungsleser;
use Carbon\CarbonImmutable;

/**
 * Liest, was die WhatsApp Cloud API zustellt.
 *
 * Die Nutzlast liegt unter `changes[].value`: `messages[]` fuer Eingehendes,
 * `statuses[]` fuer Rueckmeldungen zu Ausgehendem, `contacts[]` fuer den
 * Anzeigenamen. Beides kann in derselben Zustellung stehen.
 *
 * **Der Zeitstempel zaehlt Sekunden.** Messenger liefert im selben Feldnamen
 * Millisekunden -- drei Groessenordnungen Unterschied, und eine Nachricht von
 * heute landete damit im Jahr 57000.
 *
 * **Nicht jede Nachricht ist Text.** Wer nur `text.body` liest, bekommt fuer
 * Bilder, Reaktionen und Schaltflaechenantworten leere Eintraege im Verlauf --
 * der Leitfaden nennt das ausdruecklich als Fehlerquelle.
 */
final class WhatsAppEingang implements Kanaleingang, Rueckmeldungsleser
{
    /**
     * @param  array<string, mixed>  $eintrag
     * @return list<Eingangsnachricht>
     */
    public function lies(array $eintrag): array
    {
        $nachrichten = [];

        foreach ($this->werte($eintrag) as $wert) {
            $namen = $this->namen($wert);

            foreach ((array) data_get($wert, 'messages', []) as $roh) {
                if (! is_array($roh)) {
                    continue;
                }

                $nachricht = $this->nachricht($roh, $namen);

                if ($nachricht instanceof Eingangsnachricht) {
                    $nachrichten[] = $nachricht;
                }
            }
        }

        return $nachrichten;
    }

    /**
     * @param  array<string, mixed>  $eintrag
     * @return list<Rueckmeldung>
     */
    public function liesRueckmeldungen(array $eintrag): array
    {
        $meldungen = [];

        foreach ($this->werte($eintrag) as $wert) {
            foreach ((array) data_get($wert, 'statuses', []) as $roh) {
                if (! is_array($roh)) {
                    continue;
                }

                $meldung = $this->rueckmeldung($roh);

                if ($meldung instanceof Rueckmeldung) {
                    $meldungen[] = $meldung;
                }
            }
        }

        return $meldungen;
    }

    /**
     * Die `value`-Bloecke der Zustellung.
     *
     * Ein Eintrag kann mehrere `changes` tragen, und nicht jede davon meint
     * Nachrichten: `message_template_status_update` kommt ueber dieselbe
     * Strecke, wenn Meta ein Template beurteilt hat.
     *
     * @param  array<string, mixed>  $eintrag
     * @return list<array<string, mixed>>
     */
    private function werte(array $eintrag): array
    {
        $werte = [];

        foreach ((array) data_get($eintrag, 'changes', []) as $aenderung) {
            if (data_get($aenderung, 'field') !== 'messages') {
                continue;
            }

            $wert = data_get($aenderung, 'value');

            if (is_array($wert)) {
                $werte[] = $wert;
            }
        }

        return $werte;
    }

    /**
     * Rufnummer zu Anzeigename.
     *
     * Der Name kommt aus dem WhatsApp-Profil und nicht aus dem
     * Nachrichtentext -- was jemand schreibt, ist kein Name (Regel 5).
     *
     * @param  array<string, mixed>  $wert
     * @return array<string, string>
     */
    private function namen(array $wert): array
    {
        $namen = [];

        foreach ((array) data_get($wert, 'contacts', []) as $kontakt) {
            $nummer = data_get($kontakt, 'wa_id');
            $name = data_get($kontakt, 'profile.name');

            if (is_string($nummer) && is_string($name) && $name !== '') {
                $namen[$nummer] = $name;
            }
        }

        return $namen;
    }

    /**
     * @param  array<string, mixed>  $roh
     * @param  array<string, string>  $namen
     */
    private function nachricht(array $roh, array $namen): ?Eingangsnachricht
    {
        $kennung = data_get($roh, 'id');
        $absender = data_get($roh, 'from');
        $art = data_get($roh, 'type');

        if (! is_string($kennung) || ! is_string($absender) || ! is_string($art)) {
            return null;
        }

        // Ein Systemereignis -- jemand hat seine Rufnummer gewechselt -- ist
        // keine Nachricht und gehoert nicht in den Verlauf.
        if ($art === 'system') {
            return null;
        }

        [$inhalt, $medientyp] = $this->inhalt($roh, $art);

        $mitDatei = in_array($art, ['image', 'video', 'audio', 'document', 'sticker'], true);

        return new Eingangsnachricht(
            externeId: $kennung,
            absender: $absender,
            inhalt: $inhalt,
            medientyp: $medientyp,
            zeitpunkt: $this->zeitpunkt(data_get($roh, 'timestamp')),
            anzeigename: $namen[$absender] ?? null,
            medienKennung: $mitDatei ? $this->text($roh, $art.'.id') : null,
            dateiname: $mitDatei ? $this->text($roh, $art.'.filename') : null,
        );
    }

    /**
     * Inhalt und Medientyp je Nachrichtenart.
     *
     * **Der Standort kommt ohne Koordinaten durch** (Regel 3): wo sich jemand
     * gerade aufhaelt, braucht eine Terminvereinbarung nicht, und was nicht
     * gebraucht wird, wird nicht gespeichert.
     *
     * @param  array<string, mixed>  $roh
     * @return array{0: string|null, 1: string|null}
     */
    private function inhalt(array $roh, string $art): array
    {
        return match ($art) {
            'text' => [$this->text($roh, 'text.body'), null],

            // Die Bildunterschrift ist der Inhalt, der Mime-Typ das Medium.
            // Die Datei selbst holt ein eigener Auftrag ueber den
            // Media-Endpunkt (MedienHolen) -- hier kommt nur ihre Kennung an.
            'image', 'video', 'audio', 'document', 'sticker' => [
                $this->text($roh, $art.'.caption'),
                $this->text($roh, $art.'.mime_type') ?? $art,
            ],

            // Ohne diesen Zweig erschiene eine Reaktion als leere Nachricht.
            'reaction' => [$this->text($roh, 'reaction.emoji'), 'reaction'],

            'button' => [$this->text($roh, 'button.text'), 'button'],

            'interactive' => [
                $this->text($roh, 'interactive.button_reply.title')
                    ?? $this->text($roh, 'interactive.list_reply.title'),
                'interactive',
            ],

            'location' => [null, 'location'],
            'contacts' => [null, 'contacts'],
            'order' => [null, 'order'],

            // Was wir nicht kennen, bekommt trotzdem einen Eintrag: eine
            // Luecke im Verlauf ist schlimmer als ein Eintrag ohne Inhalt.
            default => [null, $art],
        };
    }

    /**
     * @param  array<string, mixed>  $roh
     */
    private function text(array $roh, string $pfad): ?string
    {
        $wert = data_get($roh, $pfad);

        return is_string($wert) && $wert !== '' ? $wert : null;
    }

    /**
     * @param  array<string, mixed>  $roh
     */
    private function rueckmeldung(array $roh): ?Rueckmeldung
    {
        $kennung = data_get($roh, 'id');
        $zustand = data_get($roh, 'status');

        if (! is_string($kennung) || ! is_string($zustand)) {
            return null;
        }

        $status = match ($zustand) {
            'sent' => MessageStatus::Sent,
            'delivered' => MessageStatus::Delivered,
            'read' => MessageStatus::Read,
            'failed' => MessageStatus::Failed,
            default => null,
        };

        if (! $status instanceof MessageStatus) {
            return null;
        }

        return new Rueckmeldung(
            externeId: $kennung,
            status: $status,
            kategorie: $this->kategorie($roh),
            zeitpunkt: $this->zeitpunkt(data_get($roh, 'timestamp')),
            kurzgrund: $status === MessageStatus::Failed ? $this->kurzgrund($roh) : null,
        );
    }

    /**
     * Die Kostenkategorie -- **nur, wenn sie dasteht**.
     *
     * Keine Angabe heisst unbekannt und nicht kostenlos. `none` waere hier
     * die Schaetzung, die der Leitfaden verbietet, und zwar die teuerste.
     *
     * @param  array<string, mixed>  $roh
     */
    private function kategorie(array $roh): ?MessageCostCategory
    {
        $wert = data_get($roh, 'pricing.category');

        if (! is_string($wert)) {
            return null;
        }

        return match ($wert) {
            'service' => MessageCostCategory::Service,
            'utility' => MessageCostCategory::Utility,
            'marketing' => MessageCostCategory::Marketing,
            'authentication' => MessageCostCategory::Authentication,

            // Meta hat die Namen der Kategorien schon einmal geaendert. Ein
            // unbekannter Wert ist eine Luecke im Wissen, keine Nullkosten.
            default => null,
        };
    }

    /**
     * Ein Kurzgrund, niemals der Klartext des Anbieters: der traegt bei
     * Nachrichtenkanaelen regelmaessig Inhalte mit sich.
     *
     * @param  array<string, mixed>  $roh
     */
    private function kurzgrund(array $roh): string
    {
        $code = data_get($roh, 'errors.0.code');

        return is_numeric($code) ? 'wa_'.((int) $code) : 'rejected';
    }

    /**
     * **Sekunden, nicht Millisekunden.** WhatsApp liefert einen
     * Unix-Zeitstempel als Zeichenkette; Messenger benutzt denselben
     * Feldnamen fuer Millisekunden.
     */
    private function zeitpunkt(mixed $wert): ?CarbonImmutable
    {
        return is_numeric($wert) ? CarbonImmutable::createFromTimestamp((int) $wert, 'UTC') : null;
    }
}
