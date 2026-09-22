<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\Weekday;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Practitioner;
use App\Models\Treatment;
use App\Models\User;
use App\Tenancy\KeyRing;
use App\Tenancy\TenantContext;
use App\Termine\Kontaktsuche;
use App\Termine\Terminplaner;
use App\Verfuegbarkeit\SlotErzeuger;
use App\Verfuegbarkeit\Verfuegbarkeit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Demodaten fuer die Entwicklung. Nicht fuer Produktion gedacht.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $praxis = Organization::query()->firstOrCreate(
                ['slug' => 'demo-praxis'],
                [
                    'name' => 'Demo-Praxis',
                    // Die Markenfarbe der Buchungsseite (WP-12). WP-07 loest
                    // das ueber eine eigene Tabelle samt Pruefung bei der
                    // Eingabe; bis dahin genuegt ein Wert hier.
                    'settings' => ['branding' => ['primary_color' => '#C9A227']],
                ],
            );

            if (! $praxis->encryptionKey()->exists()) {
                app(KeyRing::class)->issue($praxis);
            }

            // Enums taugen nicht als Array-Schluessel, deshalb Paare.
            $rollen = [
                [Role::Owner, 'inhaberin@demo.test'],
                [Role::Admin, 'verwaltung@demo.test'],
                [Role::Reception, 'empfang@demo.test'],
                [Role::Practitioner, 'behandlerin@demo.test'],
                [Role::Marketing, 'marketing@demo.test'],
            ];

            foreach ($rollen as [$rolle, $email]) {
                $benutzer = User::query()->firstOrNew(['email' => $email]);
                $benutzer->organization_id = $praxis->getKey();
                $benutzer->role = $rolle;
                $benutzer->name = $rolle->label();
                $benutzer->password = Hash::make('passwort');
                $benutzer->email_verified_at = now();
                $benutzer->save();
            }

            // Praxisstammdaten (WP-08)
            app(TenantContext::class)->runAs($praxis, function (): void {
                $standort = Location::query()->firstOrCreate(
                    ['slug' => 'hauptstandort'],
                    [
                        'name' => 'Hauptstandort Hamburg',
                        'timezone' => 'Europe/Berlin',
                        'street' => 'Rothenbaumchaussee 12',
                        'postal_code' => '20148',
                        'city' => 'Hamburg',
                        'country' => 'DE',
                    ],
                );

                foreach ([['Dr. med.', 'Martina', 'Sauer'], ['', 'Jonas', 'Rieck']] as [$titel, $vorname, $nachname]) {
                    $behandler = Practitioner::query()->firstOrCreate(
                        ['first_name' => $vorname, 'last_name' => $nachname],
                        ['title' => $titel === '' ? null : $titel, 'is_active' => true],
                    );

                    $behandler->locations()->syncWithoutDetaching([$standort->getKey()]);

                    if ($behandler->workingHours()->exists()) {
                        continue;
                    }

                    // Montag bis Freitag, mit Mittagspause als Luecke.
                    foreach ([Weekday::Montag, Weekday::Dienstag, Weekday::Mittwoch, Weekday::Donnerstag, Weekday::Freitag] as $tag) {
                        foreach ([['08:30:00', '12:30:00'], ['13:30:00', '17:00:00']] as [$beginn, $ende]) {
                            $behandler->workingHours()->create([
                                'location_id' => $standort->getKey(),
                                'weekday' => $tag,
                                'starts_at' => $beginn,
                                'ends_at' => $ende,
                            ]);
                        }
                    }
                }
            });

            // Leistungskatalog und Terminarten (WP-09)
            app(TenantContext::class)->runAs($praxis, function (): void {
                $standort = Location::query()->firstOrFail();
                $behandler = Practitioner::query()->get();

                $katalog = [
                    ['Botox', 'botox', 29000, 49000, 39000, 'Faltenbehandlung', 30, 15],
                    ['Hyaluron', 'hyaluron', 35000, 69000, 49000, 'Faltenbehandlung', 45, 15],
                    ['Lidstraffung', 'lidstraffung', 180000, 320000, 245000, 'Chirurgie', 60, 48],
                ];

                foreach ($katalog as [$name, $slug, $von, $bis, $schnitt, $kategorie, $dauer, $vorlauf]) {
                    $behandlung = Treatment::query()->firstOrCreate(
                        ['slug' => $slug],
                        [
                            'name' => $name,
                            'category' => $kategorie,
                            'price_from_cents' => $von,
                            'price_to_cents' => $bis,
                            'avg_revenue_cents' => $schnitt,
                        ],
                    );

                    $art = AppointmentType::query()->firstOrCreate(
                        ['slug' => 'erstberatung-'.$slug],
                        [
                            'name' => 'Erstberatung '.$name,
                            'treatment_id' => $behandlung->getKey(),
                            'duration_minutes' => $dauer,
                            'buffer_before_minutes' => 5,
                            'buffer_after_minutes' => 10,
                            'lead_time_hours' => $vorlauf,
                        ],
                    );

                    $art->practitioners()->syncWithoutDetaching($behandler->pluck('id')->all());
                    $art->locations()->syncWithoutDetaching([$standort->getKey()]);
                }

                // Eine Terminart ohne Behandlungsbezug -- taucht in der
                // Auswertung mit null Euro auf, und das soll man sehen.
                $nachkontrolle = AppointmentType::query()->firstOrCreate(
                    ['slug' => 'nachkontrolle'],
                    ['name' => 'Nachkontrolle', 'duration_minutes' => 15, 'lead_time_hours' => 2],
                );

                $nachkontrolle->practitioners()->syncWithoutDetaching($behandler->pluck('id')->all());
                $nachkontrolle->locations()->syncWithoutDetaching([$standort->getKey()]);
            });

            // Kontakte und Termine (WP-11)
            app(TenantContext::class)->runAs($praxis, function (): void {
                $this->termine();
            });

            // Super-Admin (WP-05, Backoffice folgt in WP-34). Gehoert zu
            // keiner Organisation.
            $support = User::query()->firstOrNew(['email' => 'support@mrs-beauty.test']);
            $support->organization_id = null;
            $support->role = null;
            $support->is_super_admin = true;
            $support->name = 'Support';
            $support->password = Hash::make('passwort');
            $support->email_verified_at = now();
            $support->save();
        });

        $this->command->info('Demo-Praxis angelegt. Anmeldung: inhaberin@demo.test / passwort');
        $this->command->info('Super-Admin: support@mrs-beauty.test / passwort');
    }

    /**
     * Ein paar Kontakte und ein gefuellter Terminkalender.
     *
     * Erzeugt die Slots gleich mit -- ohne sie ist der Kalender leer, und
     * eine leere Terminverwaltung sagt nichts darueber, ob sie funktioniert.
     */
    private function termine(): void
    {
        if (Appointment::query()->exists()) {
            return;
        }

        app(SlotErzeuger::class)->erzeuge(
            CarbonImmutable::now()->startOfDay(),
            CarbonImmutable::now()->addDays(30)->endOfDay(),
        );

        $suche = app(Kontaktsuche::class);
        $planer = app(Terminplaner::class);
        $verfuegbarkeit = app(Verfuegbarkeit::class);

        $kontakte = [
            ['Annika', 'Mueller', 'annika.mueller@demo.test', '+49 170 1112223'],
            ['Bernd', 'Schuster', 'bernd.schuster@demo.test', '+49 171 4445556'],
            ['Clara', 'Weiss', 'clara.weiss@demo.test', '+49 172 7778889'],
            ['Dilara', 'Yilmaz', 'dilara.yilmaz@demo.test', '+49 173 1234567'],
        ];

        $angelegt = [];

        foreach ($kontakte as [$vorname, $nachname, $email, $telefon]) {
            $angelegt[] = $suche->findeOderLege([
                'first_name' => $vorname,
                'last_name' => $nachname,
                'email' => $email,
                'phone' => $telefon,
            ]);
        }

        $arten = AppointmentType::query()->aktiv()->orderBy('name')->get();
        $gebucht = 0;

        foreach ($arten as $index => $art) {
            $vorschlaege = $verfuegbarkeit->freieStartzeiten(
                art: $art,
                von: CarbonImmutable::now(),
                bis: CarbonImmutable::now()->addDays(14),
            );

            // Verstreut ueber die naechsten Tage, nicht alle am Stueck.
            foreach ([3, 40, 90] as $stelle) {
                $vorschlag = $vorschlaege[$stelle + $index * 7] ?? null;

                if ($vorschlag === null) {
                    continue;
                }

                try {
                    $planer->buche($vorschlag, $angelegt[$gebucht % count($angelegt)]);
                    $gebucht++;
                } catch (Throwable) {
                    // Eine belegte Strecke ist hier kein Fehler, sondern der
                    // Normalfall: die Vorschlagsliste stammt von vor der
                    // letzten Buchung.
                }
            }
        }

        $this->command->info("Demodaten: {$gebucht} Termine angelegt.");
    }
}
