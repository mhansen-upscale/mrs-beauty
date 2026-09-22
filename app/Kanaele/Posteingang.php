<?php

declare(strict_types=1);

namespace App\Kanaele;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Kontakte\Kontaktsuche;
use App\Models\Contact;
use App\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Die Liste des Posteingangs.
 *
 * **Gesucht wird ueber Metadaten, nie ueber Inhalte** (Entscheidung P8).
 * Nachrichten sind feldverschluesselt und kennen kein LIKE -- die Suche
 * findet deshalb Personen, nicht Saetze. Das gehoert sichtbar in die
 * Oberflaeche, sonst haelt der Empfang sie fuer kaputt.
 */
final class Posteingang
{
    /** Wie viele Gespraeche eine Seite zeigt. */
    public const SEITE = 50;

    public function __construct(private readonly Kontaktsuche $kontakte) {}

    /**
     * @return Collection<int, Conversation>
     */
    public function liste(
        ?ChannelType $kanal = null,
        ?ConversationStatus $zustand = ConversationStatus::Open,
        bool $nurUngelesen = false,
        string $suchbegriff = '',
    ): Collection {
        $abfrage = Conversation::query()
            ->with(['channelIdentity', 'contact'])
            ->when($kanal instanceof ChannelType, fn (Builder $q) => $q->where('channel', $kanal?->value))
            ->when($zustand instanceof ConversationStatus, fn (Builder $q) => $q->where('status', $zustand?->value));

        $begriff = trim($suchbegriff);

        if ($begriff !== '') {
            $abfrage->whereIn('contact_id', $this->kontaktschluessel($begriff));
        }

        // Nach letzter Aktivitaet: was zuletzt hereinkam, steht oben. Ein
        // Gespraech, in dem wir zuletzt geschrieben haben, ruecken wir nicht
        // nach vorn -- oben gehoert, was auf Antwort wartet.
        $gespraeche = $abfrage
            ->orderByRaw('COALESCE(last_inbound_at, created_at) DESC')
            ->limit(self::SEITE)
            ->get();

        return $nurUngelesen
            ? $gespraeche->filter(fn (Conversation $gespraech): bool => $gespraech->ungelesen())->values()
            : $gespraeche;
    }

    /** Wie viele Gespraeche auf Antwort warten. */
    public function ungelesen(): int
    {
        return Conversation::query()
            ->offen()
            ->whereNotNull('last_inbound_at')
            // Die Klammer ist nicht kosmetisch: ein ODER auf oberster Ebene
            // haengt sich an die ganze Bedingung und zaehlte fremde Zeilen mit.
            ->where(fn (Builder $q) => $q
                ->whereNull('last_read_at')
                ->orWhereColumn('last_inbound_at', '>', 'last_read_at'))
            ->count();
    }

    /** Gesehen -- nicht beantwortet. */
    public function markiereGelesen(Conversation $gespraech, ?CarbonImmutable $jetzt = null): void
    {
        $gespraech->last_read_at = $jetzt ?? CarbonImmutable::now();
        $gespraech->save();
    }

    /**
     * Die Schluessel der Kontakte, auf die der Begriff passt.
     *
     * Ueber die blinden Indizes aus WP-16: **exakt**, nicht unscharf. Ein
     * verschluesseltes Feld laesst sich nur gleich oder ungleich vergleichen.
     *
     * @return list<string>
     */
    private function kontaktschluessel(string $begriff): array
    {
        /** @var list<string> */
        return $this->kontakte->suche($begriff, 100)
            ->map(fn (Contact $kontakt): string => (string) $kontakt->getKey())
            ->values()
            ->all();
    }
}
