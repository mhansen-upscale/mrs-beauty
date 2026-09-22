<?php

declare(strict_types=1);

namespace App\Backoffice;

use App\Abrechnung\Nutzungsuebersicht;
use App\Enums\ConnectionStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AdAccount;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Models\ChannelConnection;
use App\Models\ChannelRawEvent;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Was der Betreiber ueber seine Praxen sehen darf.
 *
 * **Zustaende und Zahlen. Inhalte nie.** Kein Kontaktname, kein
 * Nachrichtentext, kein Termin -- auch nicht maskiert. Wer wirklich in eine
 * Praxis hineinsehen muss, geht ueber die Impersonation aus WP-05: mit
 * Begruendung, mit Freigabe durch die Praxis, im Protokoll und sichtbar fuer
 * beide Seiten.
 *
 * Das ist keine technische Huerde, sondern die Zusage des Produkts. Eine
 * Praxis, die die WhatsApp-Nachrichten ihrer Patientinnen ueber uns fuehrt,
 * muss sich darauf verlassen koennen, dass "der Anbieter kann alles lesen"
 * nicht stimmt.
 *
 * **Zahlen je Mandant, nie je Person.** "Drei Nachrichten heute" ist bei
 * einer Praxis mit einer Patientin eine Aussage ueber diese Patientin.
 */
final class Mandantenuebersicht
{
    public function __construct(
        private readonly TenantContext $mandant,
        private readonly Nutzungsuebersicht $nutzung,
    ) {}

    /**
     * Alle Praxen mit ihren Kennzahlen.
     *
     * @return list<array<string, mixed>>
     */
    public function liste(string $suche = '', ?CarbonImmutable $jetzt = null): array
    {
        $jetzt ??= CarbonImmutable::now();
        $begriff = trim($suche);

        /** @var list<array<string, mixed>> */
        return $this->mandant->acrossTenants(
            'Backoffice zeigt dem Betreiber die Liste seiner Mandanten (WP-34)',
            function () use ($begriff, $jetzt): array {
                $praxen = Organization::query()
                    ->when($begriff !== '', fn ($abfrage) => $abfrage
                        ->where('name', 'like', '%'.$begriff.'%')
                        ->orWhere('slug', 'like', '%'.$begriff.'%'))
                    ->orderBy('name')
                    ->limit(200)
                    ->get();

                return $praxen
                    ->map(fn (Organization $praxis): array => $this->zeile($praxis, $jetzt))
                    ->values()
                    ->all();
            },
        );
    }

    /**
     * Das Blatt einer einzelnen Praxis.
     *
     * @return array<string, mixed>
     */
    public function blatt(Organization $praxis, ?CarbonImmutable $jetzt = null): array
    {
        $jetzt ??= CarbonImmutable::now();

        /** @var array<string, mixed> */
        return $this->mandant->runAs($praxis, function () use ($praxis, $jetzt): array {
            $nutzung = $this->nutzung->fuerMonat($jetzt);

            return array_merge($this->zeile($praxis, $jetzt, imMandanten: true), [
                'verbrauch' => [
                    'nachrichten' => $nutzung['nachrichten'],
                    'kostenpflichtig' => $nutzung['kostenpflichtigeNachrichten'],
                    'agentenlaeufe' => $nutzung['agentenlaeufe'],
                    'angebote' => $nutzung['angebote'],
                ],

                // Betriebslage je Mandant (WP-33) -- Zustaende, keine Inhalte.
                'stoerungen' => [
                    'kanaele' => ChannelConnection::query()
                        ->whereIn('status', [
                            ConnectionStatus::Expired->value,
                            ConnectionStatus::Degraded->value,
                            ConnectionStatus::Suspended->value,
                        ])
                        ->get()
                        ->map(fn (ChannelConnection $verbindung): array => [
                            'kanal' => $verbindung->channel->label(),
                            'status' => $verbindung->status->label(),
                            'grund' => $verbindung->last_error,
                        ])
                        ->values()
                        ->all(),

                    'kalender' => CalendarConnection::query()->where('status', '!=', 'active')->count(),

                    // Zahl, kein Inhalt -- wie alles hier.
                    'werbung' => AdAccount::query()
                        ->whereNull('disconnected_at')
                        ->whereIn('status', [
                            ConnectionStatus::Expired->value,
                            ConnectionStatus::Degraded->value,
                            ConnectionStatus::Suspended->value,
                        ])
                        ->count(),

                    'ereignisse' => ChannelRawEvent::query()->offen()->count(),
                ],
            ]);
        });
    }

    /**
     * Eine Zeile der Liste.
     *
     * @return array<string, mixed>
     */
    private function zeile(Organization $praxis, CarbonImmutable $jetzt, bool $imMandanten = false): array
    {
        $zaehlen = function () use ($praxis, $jetzt): array {
            $abo = Subscription::query()->where('organization_id', $praxis->getKey())->first();
            $zustand = $abo instanceof Subscription ? $abo->status : SubscriptionStatus::Trialing;

            return [
                'benutzer' => User::query()->derOrganisation((string) $praxis->getKey())->whereNull('deactivated_at')->count(),
                'kontakte' => Contact::query()->where('organization_id', $praxis->getKey())->count(),
                'termine30' => Appointment::query()
                    ->where('organization_id', $praxis->getKey())
                    ->where('starts_at', '>=', $jetzt->subDays(30))
                    ->count(),
                'abo' => $zustand->value,
                'aboLabel' => $zustand->label(),
                'periodeEndet' => $abo?->period_ends_at?->toIso8601String(),
            ];
        };

        // Die Zaehlungen laufen ueber die Mandantengrenze -- ausser wir sind
        // bereits im Mandanten.
        $zahlen = $imMandanten
            ? $zaehlen()
            : $this->mandant->acrossTenants('Backoffice zaehlt Kennzahlen eines Mandanten (WP-34)', $zaehlen);

        return array_merge([
            'uuid' => $praxis->uuid,
            'name' => $praxis->name,
            'slug' => $praxis->slug,
            'gesperrt' => $praxis->suspended_at !== null,
            'gesperrtSeit' => $praxis->suspended_at?->toIso8601String(),
            'angelegt' => $praxis->getAttribute('created_at')?->toIso8601String(),
        ], $zahlen);
    }
}
