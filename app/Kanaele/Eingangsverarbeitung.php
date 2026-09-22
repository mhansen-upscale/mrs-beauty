<?php

declare(strict_types=1);

namespace App\Kanaele;

use App\Datenschutz\Anhangspeicher;
use App\Enums\AttachmentContext;
use App\Enums\ChannelType;
use App\Jobs\NachrichtEinordnen;
use App\Models\ChannelIdentity;
use App\Models\ChannelRawEvent;
use App\Models\Message;
use App\Support\Uuid;
use App\Warteliste\Angebotsantwort;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Macht aus einem Rohereignis Konversationen und Nachrichten.
 *
 * Der Teil, der fuer alle Kanaele gleich ist: Identitaet finden oder anlegen,
 * Konversation finden oder anlegen, Nachricht deduplizieren, Service-Fenster
 * neu setzen. Was sich unterscheidet -- wie man die Nachrichten aus der
 * Nutzlast liest --, steht beim Kanal (WP-20).
 */
final class Eingangsverarbeitung
{
    public function __construct(
        private readonly Kanaleingaenge $eingaenge,
        private readonly Konversationen $konversationen,
        private readonly Rohereignisse $rohereignisse,
        private readonly Rueckmeldungen $rueckmeldungen,
        private readonly Anhangspeicher $anhaenge,
        private readonly Angebotsantwort $angebote,
    ) {}

    /**
     * @return int Wie viele Nachrichten neu waren
     */
    public function verarbeite(ChannelRawEvent $ereignis): int
    {
        $leser = $this->eingaenge->fuer($ereignis->channel);

        if (! $leser instanceof Kanaleingang) {
            // Kein Leser: das Ereignis bleibt liegen und laesst sich erneut
            // einspielen, sobald es einen gibt. Nicht als Fehlschlag zaehlen
            // -- es ist keiner.
            $this->rohereignisse->vermerkeFehlschlag($ereignis, 'no_reader');

            return 0;
        }

        $neu = 0;
        $eintrag = $ereignis->inhalt();

        foreach ($leser->lies($eintrag) as $eingang) {
            $neu += $this->nimmAuf($ereignis->channel, $eingang) ? 1 : 0;
        }

        // Statusrueckmeldungen kommen im selben Ereignis wie Nachrichten --
        // und sind keine. Sie erzeugen keinen Verlaufseintrag und oeffnen
        // kein Service-Fenster; sie tragen nach, wie weit etwas gekommen ist
        // und was es gekostet hat.
        if ($leser instanceof Rueckmeldungsleser) {
            foreach ($leser->liesRueckmeldungen($eintrag) as $meldung) {
                $this->rueckmeldungen->trageNach($meldung);
            }
        }

        $this->rohereignisse->vermerkeErfolg($ereignis);

        return $neu;
    }

    /**
     * Eine einzelne Nachricht -- oder nichts, wenn es sie schon gibt.
     *
     * Meta liefert doppelt, im Normalbetrieb. Ohne Deduplizierung antwortet
     * der Agent zweimal auf dieselbe Nachricht.
     */
    private function nimmAuf(ChannelType $kanal, Eingangsnachricht $eingang): bool
    {
        $identitaet = $this->identitaet($kanal, $eingang);
        $konversation = $this->konversationen->fuer($identitaet);

        $nachricht = $this->konversationen->nimmAuf(
            $konversation,
            $eingang->externeId,
            $eingang->inhalt,
            $eingang->medientyp,
            $eingang->zeitpunkt ?? CarbonImmutable::now(),
            $eingang->betreff,
        );

        if (! $nachricht instanceof Message) {
            return false;
        }

        $this->legeAnhaengeAb($nachricht, $eingang);

        // **Ein offenes Wartelistenangebot geht der Einordnung vor** (WP-25).
        // Wer auf "Es ist ein Termin frei geworden" mit "Ja" antwortet, meint
        // dieses Angebot -- und nicht eine neue Terminanfrage, die der Agent
        // erst klassifizieren muesste.
        if ($this->angebote->pruefe($nachricht)) {
            return true;
        }

        // **Der Agent liest mit** (WP-22). Auf der Queue, nicht hier: ein
        // Sprachmodell, das nicht antwortet, darf die Zustellung nicht
        // aufhalten -- quittiert ist sie laengst (Regel 4).
        NachrichtEinordnen::dispatch(
            (string) $nachricht->uuid,
            Uuid::toString($nachricht->organization_id),
        );

        return true;
    }

    /**
     * Legt mitgeschickte Dateien ab.
     *
     * **Ueber den Anhangspeicher aus WP-18**, nicht daneben: dort haengen die
     * Virenpruefung und das Pflicht-Ablaufdatum fuer Chat-Anhaenge
     * (Entscheidung C6). Ein ungefragt zugesandtes Foto soll nicht dauerhaft
     * liegen, und ausgeliefert wird es erst nach der Pruefung.
     */
    private function legeAnhaengeAb(Message $nachricht, Eingangsnachricht $eingang): void
    {
        foreach ($eingang->anhaenge as $datei) {
            $this->anhaenge->lege(
                $nachricht,
                $datei['inhalt'],
                $datei['name'],
                AttachmentContext::Chat,
            );
        }
    }

    /**
     * Die Kanalidentitaet des Absenders -- oder eine neue.
     *
     * **Ohne Kontakt**, wenn es noch keinen gibt: die erste Nachricht kommt
     * an, bevor jemand weiss, wer da schreibt (Entscheidung D5). Wer hier
     * einen Kontakt erzwingt, erzeugt Karteileichen oder falsche Personen.
     */
    private function identitaet(ChannelType $kanal, Eingangsnachricht $eingang): ChannelIdentity
    {
        $vorhanden = ChannelIdentity::query()->mitKennung($kanal, $eingang->absender)->first();

        if ($vorhanden instanceof ChannelIdentity) {
            $vorhanden->last_seen_at = $eingang->zeitpunkt ?? CarbonImmutable::now();
            $vorhanden->save();

            return $vorhanden;
        }

        try {
            $identitaet = new ChannelIdentity;
            $identitaet->channel = $kanal;
            $identitaet->external_id = $eingang->absender;
            $identitaet->display_name = $eingang->anzeigename;
            $identitaet->last_seen_at = $eingang->zeitpunkt ?? CarbonImmutable::now();
            $identitaet->save();

            return $identitaet;
        } catch (QueryException $ausnahme) {
            // Zwei Zustellungen gleichzeitig, dieselbe neue Person: der Index
            // entscheidet, nicht die Abfrage davor.
            if (! str_contains($ausnahme->getMessage(), 'kanalidentitaet_unique')) {
                throw $ausnahme;
            }

            return ChannelIdentity::query()->mitKennung($kanal, $eingang->absender)->firstOrFail();
        }
    }
}
