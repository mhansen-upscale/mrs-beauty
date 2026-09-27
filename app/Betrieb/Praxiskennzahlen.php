<?php

declare(strict_types=1);

namespace App\Betrieb;

use App\Abrechnung\Kontingente;
use App\Enums\Ability;
use App\Enums\AppointmentStatus;
use App\Enums\BookingChannel;
use App\Enums\LeadStatus;
use App\Enums\WaitlistStatus;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Practitioner;
use App\Models\User;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;

/**
 * Die Zahlen auf dem Dashboard einer Praxis.
 *
 * **Zahlen, keine Inhalte** -- kein Name, keine Nachricht, kein Termin. Und
 * **jede Kennzahl sieht nur, wer die Sache dahinter sehen darf**: die
 * Behandlerin zaehlt ihre eigenen Termine, den Posteingang zaehlt nur, wer
 * ihn oeffnen darf, das Kontingent nur, wer das Abo verwaltet. Was jemand
 * nicht sehen darf, fehlt -- es steht nicht als Null da, denn eine Null
 * behauptet etwas.
 *
 * **Heute ist der Tag der Praxis, nicht des Servers.** Gespeichert wird
 * UTC, gerechnet in der Ortszeit des Standorts; Tage und Wochen werden dort
 * addiert, damit der Tag der Zeitumstellung 23 oder 25 Stunden hat.
 */
final class Praxiskennzahlen
{
    /**
     * Wie ein Termin zustande kam, ohne dass jemand am Empfang tippte.
     *
     * @var list<BookingChannel>
     */
    private const SELBST_GEBUCHT = [BookingChannel::Public, BookingChannel::Agent, BookingChannel::Waitlist];

    public function __construct(
        private readonly Kontingente $kontingente,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fuer(User $benutzer, ?CarbonImmutable $jetzt = null): array
    {
        $jetzt ??= CarbonImmutable::now();
        $heute = $jetzt->setTimezone($this->zone())->startOfDay();
        $montag = $heute->startOfWeek(CarbonInterface::MONDAY);

        $darf = fn (Ability $faehigkeit): bool => $benutzer->can($faehigkeit->value);

        return [
            'termine' => $this->termine($benutzer, $heute, $montag),

            'buchungen' => $darf(Ability::ManageAppointments)
                ? $this->buchungen($jetzt)
                : null,

            'posteingang' => $darf(Ability::ViewInbox)
                ? ['ungelesen' => $this->ungelesen()]
                : null,

            'anfragen' => $darf(Ability::ManageContacts)
                ? ['neu' => Lead::query()->where('status', LeadStatus::New->value)->count()]
                : null,

            'warteliste' => $darf(Ability::ManageWaitlist)
                ? ['aktiv' => WaitlistEntry::query()->where('status', WaitlistStatus::Active->value)->count()]
                : null,

            // Mengen, keine Cent (B11) -- und nur lesend: das Dashboard legt
            // kein Abo an.
            'kontingent' => $darf(Ability::ManageBilling)
                ? $this->kontingente->stand($jetzt)
                : null,
        ];
    }

    /**
     * Termine heute und in dieser Woche.
     *
     * Wer Termine verwaltet, zaehlt die der Praxis. Wer nur den eigenen
     * Kalender sieht, zaehlt die eigenen -- und ohne verknuepften Behandler
     * gibt es keinen eigenen Kalender und damit keine Zahl.
     *
     * @return array{heute: int, woche: int}|null
     */
    private function termine(User $benutzer, CarbonImmutable $heute, CarbonImmutable $montag): ?array
    {
        $eigener = null;

        if (! $benutzer->can(Ability::ManageAppointments->value)) {
            if (! $benutzer->can(Ability::ViewOwnCalendar->value)) {
                return null;
            }

            $eigener = Practitioner::query()->where('user_id', $benutzer->getKey())->first();

            if (! $eigener instanceof Practitioner) {
                return null;
            }
        }

        $zaehle = fn (CarbonImmutable $von, CarbonImmutable $bis): int => Appointment::query()
            ->aktiv()
            ->when($eigener instanceof Practitioner, fn (Builder $abfrage) => $abfrage->where('practitioner_id', $eigener?->getKey()))
            ->where('starts_at', '>=', $von->utc())
            ->where('starts_at', '<', $bis->utc())
            ->count();

        return [
            'heute' => $zaehle($heute, $heute->addDay()),
            'woche' => $zaehle($montag, $montag->addDays(7)),
        ];
    }

    /**
     * Wie gebucht wird und wer kommt -- ueber den Rueckblick.
     *
     * **Gebucht** zaehlt am Anlegen: was in den letzten Tagen entstanden ist,
     * gleich wann der Termin liegt. **Erschienen** zaehlt am Termin: was in
     * den letzten Tagen stattfand und einen Ausgang hat.
     *
     * @return array{tage: int, gebucht: int, selbstGebucht: int, erschienen: int, nichtErschienen: int}
     */
    private function buchungen(CarbonImmutable $jetzt): array
    {
        $tage = (int) config('mrs.dashboard.rueckblick_tage');
        $von = $jetzt->subDays($tage);

        $angelegt = fn (): Builder => Appointment::query()
            ->aktiv()
            ->where('created_at', '>=', $von);

        $stattgefunden = fn (AppointmentStatus $ausgang): int => Appointment::query()
            ->where('status', $ausgang->value)
            ->where('starts_at', '>=', $von)
            ->where('starts_at', '<', $jetzt)
            ->count();

        return [
            'tage' => $tage,
            'gebucht' => $angelegt()->count(),
            'selbstGebucht' => $angelegt()
                ->whereIn('booked_via', array_map(fn (BookingChannel $kanal): string => $kanal->value, self::SELBST_GEBUCHT))
                ->count(),
            'erschienen' => $stattgefunden(AppointmentStatus::Attended),
            'nichtErschienen' => $stattgefunden(AppointmentStatus::NoShow),
        ];
    }

    /**
     * Offene Gespraeche mit einer Nachricht, die noch niemand gelesen hat --
     * dieselbe Frage wie Conversation::ungelesen(), nur im SQL.
     */
    private function ungelesen(): int
    {
        return Conversation::query()
            ->offen()
            ->whereNotNull('last_inbound_at')
            ->where(fn (Builder $abfrage) => $abfrage
                ->whereNull('last_read_at')
                ->orWhereColumn('last_read_at', '<', 'last_inbound_at'))
            ->count();
    }

    /**
     * Die Ortszeit der Praxis: die des ersten aktiven Standorts, sonst die
     * Vorgabe der Installation.
     */
    private function zone(): DateTimeZone
    {
        $standort = Location::query()->where('is_active', true)->orderBy('name')->first();

        return $standort instanceof Location
            ? $standort->zone()
            : new DateTimeZone((string) config('mrs.business_timezone'));
    }
}
