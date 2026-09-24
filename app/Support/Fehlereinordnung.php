<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ConnectionStatus;

/**
 * Wie mit einem Fehlschlag umzugehen ist: wiederholen oder nicht, und was mit
 * der Verbindung geschieht.
 *
 * **Der Traeger ist allgemein, die Tabelle ist Metas.** Bis WP-20b hiess die
 * Klasse Metafehler -- und der E-Mail-Kanal, der nie mit Meta spricht, musste
 * trotzdem einen "Metafehler" werfen. Das war der erste Befund der
 * Gegenprobe: ein Name, der einen Anbieter in eine Schnittstelle traegt, in
 * die er nicht gehoert. Die Zuordnung der Antwortcodes steht weiterhin unter
 * ausMetaAntwort(), denn die ist wirklich Metas.
 *
 * **Und der Ort ist allgemein**, seit WP-26: sie lag unter App\Kanaele,
 * obwohl ein Werbekonto kein Kanal ist. Dieselbe Bewegung wie beim
 * Verbindungszustand.
 *
 * **Der teuerste Fehler waere, alles zu wiederholen.** Ein ungueltiges Token
 * wird beim zwanzigsten Versuch nicht gueltiger, und die Wiederholungen
 * verdecken, dass jemand die Verbindung erneuern muss. Dieselbe Ueberlegung
 * wie R4 beim Kalendersync.
 */
final class Fehlereinordnung
{
    public function __construct(
        public readonly string $kurzgrund,
        public readonly bool $wiederholen,
        public readonly ?ConnectionStatus $zustand,
        public readonly ?string $klartext = null,
    ) {}

    /**
     * Was am Werbekonto steht, wenn die Verbindung gestoert ist.
     *
     * **Metas Satz, wenn es einen gibt** -- die Oberflaeche kennt nur eine
     * Handvoll Kurzgruende und zeigt alles andere unveraendert an. Ein
     * `action_required` im Hinweiskasten waere ein Code fuer die Praxis.
     */
    public function grund(): string
    {
        return $this->klartext ?? $this->kurzgrund;
    }

    /**
     * Ordnet eine **Meta**-Antwort ein.
     *
     * Die Zuordnung folgt der Tabelle des Leitfadens. Meta liefert den
     * eigentlichen Grund in `error.code`; der HTTP-Status allein genuegt
     * nicht, weil dieselbe 400 fuer ein abgelaufenes Token und fuer einen
     * fachlichen Fehler steht.
     *
     * @param  array<string, mixed>  $antwort
     */
    public static function ausMetaAntwort(int $status, array $antwort): self
    {
        $code = (int) (data_get($antwort, 'error.code') ?? 0);
        $unterCode = (int) (data_get($antwort, 'error.error_subcode') ?? 0);
        // **`message` ist fuer Entwickler, `error_user_msg` fuer Menschen.**
        // Bei fachlichen Ablehnungen steht in `message` oft nur "Invalid
        // parameter"; der Satz, der sagt, was zu tun ist, steht daneben.
        // Der Subcode kommt mit, weil er der Schluessel zu Metas Doku ist --
        // ohne ihn sucht man in einer generischen Meldung nach der Ursache.
        $meldung = self::klartextAus($antwort);

        // Rate Limit: zurueckweichen und erneut versuchen.
        if ($status === 429 || in_array($code, [4, 17, 32, 613], true)) {
            return new self('rate_limit', wiederholen: true, zustand: null);
        }

        // **Meta verlangt eine Handlung der Person, der das Konto gehoert**
        // -- eine Sicherheitspruefung etwa. Das loest niemand ausser ihr:
        // nicht wiederholen, und die Verbindung als gestoert fuehren, damit
        // der Hinweis im Produkt steht (Regel 4) und nicht an einer
        // einzelnen Anzeige haengt.
        //
        // Gesehen am 24.09.2026, mit HTTP 200 und `error` im Rumpf:
        // "This request requires the user to take a pending action".
        if ($code === 31) {
            return new self('action_required', wiederholen: false, zustand: ConnectionStatus::Degraded, klartext: $meldung);
        }

        // Token ungueltig oder abgelaufen -- keine Wiederholung.
        if ($status === 401 || $code === 190) {
            return new self('token_invalid', wiederholen: false, zustand: ConnectionStatus::Expired);
        }

        // Berechtigung fehlt -- keine Wiederholung. Unterscheidet sich von
        // einem abgelaufenen Token: hier hilft kein neues, sondern nur eine
        // Freigabe.
        if ($code === 200 || $code === 10 || ($code === 3 && $unterCode === 0)) {
            return new self('permission_missing', wiederholen: false, zustand: ConnectionStatus::Degraded);
        }

        // Konto gesperrt: alle Schreibvorgaenge anhalten.
        if (in_array($code, [368, 1609005], true)) {
            return new self('suspended', wiederholen: false, zustand: ConnectionStatus::Suspended);
        }

        // Voruebergehend.
        if ($status >= 500 || $code === 2 || $code === 1) {
            return new self('temporary', wiederholen: true, zustand: null);
        }

        // Fachlich: dem Nutzer im Klartext anzeigen, nicht wiederholen.
        return new self('rejected', wiederholen: false, zustand: null, klartext: $meldung);
    }

    /**
     * Metas Antwort als ein Satz, den jemand lesen kann.
     *
     * @param  array<string, mixed>  $antwort
     */
    private static function klartextAus(array $antwort): ?string
    {
        $fuerMenschen = data_get($antwort, 'error.error_user_msg');
        $fuerEntwickler = data_get($antwort, 'error.message');

        $satz = is_string($fuerMenschen) && $fuerMenschen !== ''
            ? $fuerMenschen
            : (is_string($fuerEntwickler) ? $fuerEntwickler : null);

        // **Kein Code im Text.** Er geht an die Praxis, und die kann nur
        // beheben, was sie lesen kann. Der Subcode gehoert ins Protokoll --
        // Graphschreiber und Graphleser halten die ganze Antwort fest.
        return $satz;
    }
}
