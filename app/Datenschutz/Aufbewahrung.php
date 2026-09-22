<?php

declare(strict_types=1);

namespace App\Datenschutz;

use App\Enums\ConversationStatus;
use App\Enums\InsightLevel;
use App\Enums\LeadStatus;
use App\Enums\RetentionSubject;
use App\Models\AdInsight;
use App\Models\Attachment;
use App\Models\AttributionTouch;
use App\Models\AuditLog;
use App\Models\ChannelRawEvent;
use App\Models\ContactMerge;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\RetentionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Setzt die Aufbewahrungsfristen durch (Entscheidung C7).
 *
 * **Der Vorschaumodus ist nicht optional.** Ein Lauf, der beim ersten
 * scharfen Durchgang zu viel loescht, ist nicht rueckholbar -- und die
 * Fristen sind je Mandant konfigurierbar, das heisst: jemand hat sie von Hand
 * eingetragen. Wer die Zahlen vorher nicht sehen kann, schaltet blind scharf.
 *
 * Beide Modi laufen durch dieselbe Abfrage. Eine Vorschau, die anders zaehlt
 * als der Ernstfall, ist keine.
 */
final class Aufbewahrung
{
    public function __construct(private readonly Anhangspeicher $anhaenge) {}

    /**
     * Legt die Standardfristen an, wenn eine Praxis noch keine hat.
     *
     * Ohne Argument: gearbeitet wird im geltenden Mandanten, wie ueberall.
     */
    public function richteEin(): void
    {
        foreach (RetentionSubject::cases() as $gegenstand) {
            RetentionPolicy::query()->firstOrCreate(
                ['subject' => $gegenstand->value],
                [
                    'retention_days' => $gegenstand->standardfrist(),
                    'action' => $gegenstand->aktion(),
                    'is_active' => true,
                ],
            );
        }
    }

    public function lauf(bool $vorschau, ?CarbonImmutable $jetzt = null): Aufbewahrungsergebnis
    {
        $jetzt ??= CarbonImmutable::now();
        $ergebnis = new Aufbewahrungsergebnis($vorschau);

        foreach (RetentionPolicy::query()->where('is_active', true)->get() as $regel) {
            $ergebnis->zaehle($regel->subject, match ($regel->subject) {
                RetentionSubject::LeadWithoutAppointment => $this->anfragenOhneTermin($regel->stichtag($jetzt), $vorschau),
                RetentionSubject::ChatAttachment => $this->chatAnhaenge($regel->stichtag($jetzt), $jetzt, $vorschau),
                RetentionSubject::MergeSnapshot => $this->sicherungsstaende($jetzt, $vorschau),
                RetentionSubject::AuditLog => $this->protokoll($regel->stichtag($jetzt), $vorschau),
                RetentionSubject::RawEvent => $this->rohereignisse($regel->stichtag($jetzt), $vorschau),
                RetentionSubject::AdInsightDetail => $this->werbezahlen($regel->stichtag($jetzt), $vorschau),
                RetentionSubject::AttributionTouch => $this->beruehrungen($regel->stichtag($jetzt), $vorschau),
                RetentionSubject::Conversation => $this->konversationen($regel->stichtag($jetzt), $jetzt, $vorschau),
            });
        }

        return $ergebnis;
    }

    /**
     * Anfragen, aus denen nie ein Termin wurde.
     *
     * "Ohne Termin" heisst: der Vorgang hat die Stufe "Termin" nie erreicht.
     * Ein gewonnener oder terminierter Lead bleibt -- er traegt die
     * Kennzahlen des Zeitraums, und geloescht waere die Auswertung
     * rueckwirkend falsch.
     */
    private function anfragenOhneTermin(CarbonImmutable $stichtag, bool $vorschau): int
    {
        $abfrage = Lead::query()
            ->whereIn('status', [LeadStatus::New->value, LeadStatus::Contacted->value, LeadStatus::Lost->value])
            ->where('created_at', '<=', $stichtag);

        return $vorschau ? $abfrage->count() : $abfrage->delete();
    }

    /**
     * Chat-Anhaenge.
     *
     * Zwei Wege zum selben Ziel: das Ablaufdatum am Anhang (Entscheidung C6,
     * Pflicht) und die Frist der Praxis. Der frueheste gewinnt -- wer die
     * Frist verkuerzt, will nicht auf die alten Ablaufdaten warten.
     *
     * **Datei und Datensatz.** Nur die Zeile zu entfernen liesse das Foto auf
     * dem Speicher liegen.
     */
    private function chatAnhaenge(CarbonImmutable $stichtag, CarbonImmutable $jetzt, bool $vorschau): int
    {
        $abfrage = Attachment::query()
            ->where('context', 'chat')
            ->where(function ($gruppe) use ($stichtag, $jetzt): void {
                $gruppe->where('expires_at', '<=', $jetzt)->orWhere('created_at', '<=', $stichtag);
            });

        if ($vorschau) {
            return $abfrage->count();
        }

        $anzahl = 0;

        foreach ($abfrage->get() as $anhang) {
            $this->anhaenge->entferne($anhang);
            $anzahl++;
        }

        return $anzahl;
    }

    /**
     * Sicherungsstaende von Zusammenfuehrungen.
     *
     * Geloescht wird der Snapshot, **nicht der Vorgang**: dass zwei Kontakte
     * zusammengefuehrt wurden, bleibt sichtbar -- nur umkehrbar ist es nicht
     * mehr. Die Frist steht am Datensatz, weil sie dort beim Anlegen
     * festgelegt wurde (WP-16).
     */
    private function sicherungsstaende(CarbonImmutable $jetzt, bool $vorschau): int
    {
        $abfrage = ContactMerge::query()
            ->whereNotNull('snapshot')
            ->where('snapshot_expires_at', '<=', $jetzt);

        if ($vorschau) {
            return $abfrage->count();
        }

        $anzahl = 0;

        foreach ($abfrage->get() as $vorgang) {
            $vorgang->snapshot = null;
            $vorgang->save();
            $anzahl++;
        }

        return $anzahl;
    }

    /**
     * Rohereignisse.
     *
     * Der Wiedervorlagestapel der Kanaele: er existiert, damit eine
     * fehlgeschlagene Verarbeitung erneut eingespielt werden kann. Nach der
     * Frist ist das ohnehin sinnlos -- und die Nutzlast enthaelt
     * Nachrichtentexte.
     */
    private function rohereignisse(CarbonImmutable $stichtag, bool $vorschau): int
    {
        $abfrage = ChannelRawEvent::query()->where('created_at', '<=', $stichtag);

        return $vorschau ? $abfrage->count() : $abfrage->delete();
    }

    /**
     * Beruehrungen, aus denen nie eine Anfrage wurde (WP-32a).
     *
     * **Nur die ohne Kontakt.** Verknuepfte bleiben: Entscheidung P10 --
     * `attribution_touches` werden nicht mit aggregiert, sonst reisst die
     * Verbindung zwischen Umsatz und Kampagne.
     */
    private function beruehrungen(CarbonImmutable $stichtag, bool $vorschau): int
    {
        $abfrage = AttributionTouch::query()
            ->whereNull('contact_id')
            ->where('occurred_at', '<=', $stichtag);

        return $vorschau ? $abfrage->count() : $abfrage->delete();
    }

    /**
     * Werbezahlen unterhalb der Kampagnenebene (Entscheidung P9).
     *
     * **Der Stichtag ist ein Datum, kein Zeitstempel.** Insights-Tage laufen
     * in der Zeitzone des Werbekontos; ein Vergleich auf created_at wuerde
     * Zeilen loeschen, die nachtraeglich geschrieben wurden, und Zeilen
     * stehen lassen, die alt sind.
     *
     * Die Kampagnenebene bleibt -- sonst waere jede Jahresauswertung leer.
     */
    private function werbezahlen(CarbonImmutable $stichtag, bool $vorschau): int
    {
        $abfrage = AdInsight::query()
            ->where('level', '!=', InsightLevel::Campaign->value)
            ->whereDate('stat_date', '<=', $stichtag->toDateString());

        return $vorschau ? $abfrage->count() : $abfrage->delete();
    }

    /**
     * Geschlossene Konversationen -- **anonymisiert, nicht geloescht**
     * (Entscheidung C7).
     *
     * Die Zeile traegt die Kennzahlen des Zeitraums: wie viele Gespraeche
     * gefuehrt wurden, wie schnell geantwortet wurde. Geloescht waere die
     * Auswertung rueckwirkend falsch. Weg muss der **Inhalt**.
     *
     * Was bleibt, ist die Kanalidentitaet: sie kann zu einer neueren
     * Konversation gehoeren, die noch laeuft. Wer die Person ganz entfernen
     * will, loescht sie -- dann faellt die Konversation mit.
     */
    private function konversationen(CarbonImmutable $stichtag, CarbonImmutable $jetzt, bool $vorschau): int
    {
        $abfrage = Conversation::query()
            ->where('status', ConversationStatus::Closed->value)
            ->whereNull('anonymized_at')
            ->where('closed_at', '<=', $stichtag);

        if ($vorschau) {
            return $abfrage->count();
        }

        $anzahl = 0;

        foreach ($abfrage->get() as $konversation) {
            Message::query()
                ->where('conversation_id', $konversation->getKey())
                ->update(['body' => null, 'media_type' => null]);

            $konversation->contact_id = null;
            $konversation->anonymized_at = $jetzt;
            $konversation->save();

            $anzahl++;
        }

        return $anzahl;
    }

    /**
     * Das Protokoll.
     *
     * `audit_logs` ist append-only: ein Trigger verhindert jedes DELETE --
     * ausser diesem. WP-05 hat die Tuer vorgesehen und verschlossen gelassen;
     * hier wird sie fuer die Dauer des Laufs geoeffnet und wieder zugezogen.
     */
    private function protokoll(CarbonImmutable $stichtag, bool $vorschau): int
    {
        // Das Protokoll fuehrt keinen created_at, sondern occurred_at: es
        // haelt fest, **wann etwas geschah**, nicht wann die Zeile entstand.
        $abfrage = AuditLog::query()->where('occurred_at', '<=', $stichtag);

        if ($vorschau) {
            return $abfrage->count();
        }

        DB::statement('SET @mrs_audit_retention = 1');

        try {
            return $abfrage->delete();
        } finally {
            DB::statement('SET @mrs_audit_retention = NULL');
        }
    }
}
