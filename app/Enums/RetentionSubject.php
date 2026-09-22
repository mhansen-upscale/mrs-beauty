<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Was die Aufbewahrung betrifft (Entscheidung C7).
 *
 * Die Fristen sind je Mandant konfigurierbar, die **Liste** ist es nicht: was
 * hier fehlt, wird nie geloescht, und das faellt erst auf, wenn jemand
 * danach fragt.
 */
enum RetentionSubject: string
{
    case LeadWithoutAppointment = 'lead_without_appointment';

    case ChatAttachment = 'chat_attachment';

    case Conversation = 'conversation';

    case AuditLog = 'audit_log';

    case MergeSnapshot = 'merge_snapshot';

    /**
     * Rohereignisse der Kanaele (WP-19).
     *
     * **Nicht je Mandant verlaengerbar gedacht**: sie sind ein
     * Wiedervorlagestapel, kein Archiv, und enthalten Nachrichtentexte.
     * docs/integrationen/meta.md nennt 14 Tage.
     */
    case RawEvent = 'raw_event';

    /**
     * Werbekennzahlen unterhalb der Kampagnenebene (Entscheidung P9).
     *
     * **Kein Datenschutzgrund, ein Nutzengrund**: die Anzeigenebene traegt
     * die Optimierung, und die ist nach einem Jahr keine mehr. Die
     * Kampagnenebene bleibt, sonst waere jede Jahresauswertung leer.
     *
     * Sie steht trotzdem hier: was nicht in dieser Liste steht, wird nie
     * geloescht, und das faellt erst auf, wenn die Tabelle gross ist.
     */
    case AdInsightDetail = 'ad_insight_detail';

    /**
     * Beruehrungen ohne Verknuepfung (WP-32a).
     *
     * **Nur die ohne Kontakt.** Ein Touch, aus dem nie ein Lead wurde, ist
     * eine Besucherzeile ohne Zweck. Verknuepfte bleiben -- Entscheidung P10:
     * `attribution_touches` werden nicht mit aggregiert, sonst reisst die
     * Verbindung zwischen Umsatz und Kampagne.
     */
    case AttributionTouch = 'attribution_touch';

    public function label(): string
    {
        return match ($this) {
            self::LeadWithoutAppointment => 'Anfragen ohne Termin',
            self::ChatAttachment => 'Chat-Anhänge',
            self::Conversation => 'Geschlossene Konversationen',
            self::AuditLog => 'Protokoll',
            self::MergeSnapshot => 'Sicherungsstände von Zusammenführungen',
            self::RawEvent => 'Rohereignisse der Kanäle',
            self::AdInsightDetail => 'Werbezahlen unterhalb der Kampagne',
            self::AttributionTouch => 'Besuche ohne Anfrage',
        };
    }

    public function aktion(): RetentionAction
    {
        return match ($this) {
            // Eine geschlossene Konversation traegt die Kennzahlen des
            // Zeitraums. Geloescht waere die Auswertung rueckwirkend falsch.
            self::Conversation => RetentionAction::Anonymize,
            default => RetentionAction::Delete,
        };
    }

    /** Standardfrist in Tagen (Entscheidung C7). */
    public function standardfrist(): int
    {
        return match ($this) {
            self::LeadWithoutAppointment => 365,
            self::ChatAttachment => 90,
            self::Conversation => 730,
            self::AuditLog => 1095,
            self::MergeSnapshot => 30,
            self::RawEvent => 14,
            self::AdInsightDetail => 365,
            self::AttributionTouch => 180,
        };
    }
}
