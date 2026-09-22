<?php

declare(strict_types=1);

use App\Enums\CancellationReason;
use App\Enums\MessageDirection;
use App\Enums\Role;
use App\Enums\WaitlistOfferStatus;
use App\Enums\WaitlistStatus;
use App\Enums\WaitlistTrigger;
use App\Jobs\LueckeFuellen;
use App\Kanaele\Konversationen;
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Message;
use App\Models\SlotHold;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOffer;
use App\Tenancy\TenantContext;
use App\Termine\Terminplaner;
use App\Warteliste\Angebotsantwort;
use App\Warteliste\Vergabelauf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

use Tests\Feature\Warteliste\Wartelistenaufbau;

/*
|--------------------------------------------------------------------------
| WP-25 -- Warteliste und Lückenfüllung
|--------------------------------------------------------------------------
|
| Die Testfaelle 1 bis 14 aus docs/fachlogik/warteliste.md.
|
| "Ein Rundruf erzeugt mehrere Zusagen fuer einen Slot, von denen nur eine
| gewinnt. Die anderen bekommen eine Absage auf ein Angebot, das ihnen
| geschickt wurde. Das ist schlechter als gar kein Angebot."
|
*/

beforeEach(function (): void {
    // Zwei Tage vor dem Slot: genug Vorlauf fuer die meisten, zu wenig fuer
    // den mit 48 Stunden Vorlaufbedarf.
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    Queue::fake();
});

/* Auswahl ------------------------------------------------------------------ */

it('bietet einen frei gewordenen Slot genau einem an, nicht allen', function (): void {
    // Testfall 1.
    $aufbau = new Wartelistenaufbau;

    $erster = $aufbau->wartender('Ahrens', prioritaet: 5);
    $zweiter = $aufbau->wartender('Bruns');

    $angebot = angebot(app(Vergabelauf::class)->biete($aufbau->slot()));

    expect(WaitlistOffer::query()->count())->toBe(1)
        ->and($angebot->waitlist_entry_id)->toBe($erster->getKey())
        ->and($erster->fresh()?->status)->toBe(WaitlistStatus::Offered)
        ->and($zweiter->fresh()?->status)->toBe(WaitlistStatus::Active);
});

it('haelt den Slot, solange das Angebot offen ist', function (): void {
    // Ohne Hold buchte waehrend des Angebotszeitraums jemand ueber die
    // Buchungsseite denselben Slot -- und die Interessentin bekaeme auf ihre
    // Zusage eine Fehlermeldung.
    $aufbau = new Wartelistenaufbau;
    $aufbau->wartender();

    $angebot = angebot(app(Vergabelauf::class)->biete($aufbau->slot()));

    expect(SlotHold::query()->gueltig()->count())->toBe(1)
        ->and($angebot->slot_hold_id)->not->toBeNull();
});

it('uebergeht einen Kandidaten, dessen Vorlauf nicht reicht', function (): void {
    // Testfall 3: **die Bedingung, an der die Warteliste steht und faellt.**
    // Der Slot liegt in gut 25 Stunden.
    $aufbau = new Wartelistenaufbau;

    $aufbau->wartender('Ahrens', vorlaufStunden: 48, prioritaet: 9);
    $passend = $aufbau->wartender('Bruns', vorlaufStunden: 2);

    $angebot = angebot(app(Vergabelauf::class)->biete($aufbau->slot()));

    expect($angebot->waitlist_entry_id)->toBe($passend->getKey());
});

it('uebergeht einen Kandidaten ausserhalb seines Zeitfensters', function (): void {
    // Testfall 4.
    $aufbau = new Wartelistenaufbau;

    $aufbau->wartender('Ahrens', prioritaet: 9, zeitfenster: [['von' => '14:00', 'bis' => '18:00']]);
    $passend = $aufbau->wartender('Bruns', zeitfenster: [['von' => '08:00', 'bis' => '12:00']]);

    // Der Slot liegt um 09:00 Ortszeit.
    $angebot = angebot(app(Vergabelauf::class)->biete($aufbau->slot()));

    expect($angebot->waitlist_entry_id)->toBe($passend->getKey());
});

it('uebergeht einen Kandidaten ausserhalb seiner Wochentage', function (): void {
    // Testfall 5: der Slot liegt an einem Mittwoch, Bit 2.
    $aufbau = new Wartelistenaufbau;

    // Nur Montag und Dienstag.
    $aufbau->wartender('Ahrens', prioritaet: 9, wochentage: 0b0000011);
    $passend = $aufbau->wartender('Bruns', wochentage: 0b0000100);

    $angebot = angebot(app(Vergabelauf::class)->biete($aufbau->slot()));

    expect($angebot->waitlist_entry_id)->toBe($passend->getKey());
});

it('uebergeht einen Kandidaten ohne passenden Standort', function (): void {
    // Testfall 6.
    $aufbau = new Wartelistenaufbau;

    $ohne = $aufbau->wartender('Ahrens', prioritaet: 9, alleStandorte: false);
    $passend = $aufbau->wartender('Bruns');

    $angebot = angebot(app(Vergabelauf::class)->biete($aufbau->slot()));

    expect($angebot->waitlist_entry_id)->toBe($passend->getKey())
        ->and($ohne->fresh()?->status)->toBe(WaitlistStatus::Active);
});

it('uebergeht einen Kandidaten, der seine Monatsgrenze erreicht hat', function (): void {
    // Testfall 7: die Grenze schuetzt den Kanal und die Qualitaetsbewertung
    // der Rufnummer.
    $aufbau = new Wartelistenaufbau;

    $vielgefragt = $aufbau->wartender('Ahrens', prioritaet: 9);
    $passend = $aufbau->wartender('Bruns');

    foreach (range(1, (int) config('mrs.waitlist.max_offers_per_contact_per_month')) as $nummer) {
        $altes = new WaitlistOffer;
        $altes->waitlist_entry_id = $vielgefragt->getKey();
        $altes->entry_key = $vielgefragt->getKey();
        $altes->practitioner_id = $aufbau->praxis->behandler->getKey();
        $altes->location_id = $aufbau->praxis->standort->getKey();
        $altes->appointment_type_id = $aufbau->praxis->art->getKey();
        $altes->status = WaitlistOfferStatus::Declined;
        $altes->trigger = WaitlistTrigger::Cancellation;
        $altes->starts_at = CarbonImmutable::now()->subDays($nummer);
        $altes->ends_at = CarbonImmutable::now()->subDays($nummer)->addMinutes(30);
        $altes->blocked_from = CarbonImmutable::now()->subDays($nummer);
        $altes->blocked_until = CarbonImmutable::now()->subDays($nummer)->addMinutes(30);
        $altes->expires_at = CarbonImmutable::now()->subDays($nummer);
        $altes->save();
    }

    $angebot = angebot(app(Vergabelauf::class)->biete($aufbau->slot()));

    expect($angebot->waitlist_entry_id)->toBe($passend->getKey());
});

it('uebergeht einen Kandidaten ohne Einwilligung', function (): void {
    // Testfall 8: ein Angebot ist eine Ansprache von unserer Seite.
    $aufbau = new Wartelistenaufbau;

    $aufbau->wartender('Ahrens', prioritaet: 9, mitEinwilligung: false);
    $passend = $aufbau->wartender('Bruns');

    $angebot = angebot(app(Vergabelauf::class)->biete($aufbau->slot()));

    expect($angebot->waitlist_entry_id)->toBe($passend->getKey());
});

it('haelt die Rangfolge ein: Prioritaet, dann Wartezeit', function (): void {
    $aufbau = new Wartelistenaufbau;

    travelTo(CarbonImmutable::parse('2027-01-10 08:00:00', 'UTC'));
    $frueher = $aufbau->wartender('Ahrens');

    travelTo(CarbonImmutable::parse('2027-01-11 08:00:00', 'UTC'));
    $spaeter = $aufbau->wartender('Bruns');

    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));

    // Gleiche Prioritaet: wer laenger wartet, kommt zuerst.
    expect(app(Vergabelauf::class)->biete($aufbau->slot())?->waitlist_entry_id)->toBe($frueher->getKey());

    // Mit hoeherer Prioritaet sticht der spaetere -- geprueft an einem
    // zweiten Slot, denn der erste ist jetzt gehalten und dem ersten
    // Kandidaten angeboten.
    $spaeter->update(['priority' => 5]);

    expect(app(Vergabelauf::class)->biete($aufbau->slot('10:00'))?->waitlist_entry_id)
        ->toBe($spaeter->getKey());
});

/* Antworten ---------------------------------------------------------------- */

it('macht aus einer Annahme einen Termin', function (): void {
    $aufbau = new Wartelistenaufbau;
    $wartender = $aufbau->wartender();

    $angebot = angebot(app(Vergabelauf::class)->biete($aufbau->slot()));

    app(Angebotsantwort::class)->nimmAn($angebot, antwortnachricht($angebot));

    $termin = Appointment::query()->firstOrFail();

    expect($termin->booked_via->value)->toBe('waitlist')
        ->and($termin->starts_at->toIso8601String())->toBe($angebot->starts_at->toIso8601String())
        ->and($angebot->fresh()?->status)->toBe(WaitlistOfferStatus::Accepted)
        ->and($wartender->fresh()?->status)->toBe(WaitlistStatus::Booked)
        ->and(SlotHold::query()->gueltig()->count())->toBe(0);
});

it('laesst zwei Annahmen auf denselben Slot nicht zu', function (): void {
    // Testfall 2. Der Hold blockiert -- ein zweites Angebot fuer denselben
    // Slot kommt gar nicht erst zustande, solange das erste offen ist.
    $aufbau = new Wartelistenaufbau;
    $aufbau->wartender('Ahrens', prioritaet: 9);
    $aufbau->wartender('Bruns');

    $erstes = angebot(app(Vergabelauf::class)->biete($aufbau->slot()));
    $zweites = app(Vergabelauf::class)->biete($aufbau->slot());

    expect($zweites)->toBeNull()
        ->and(WaitlistOffer::query()->count())->toBe(1);
});

it('gibt bei Ablauf den Slot frei und bietet dem naechsten an', function (): void {
    // Testfall 9.
    $aufbau = new Wartelistenaufbau;
    $erster = $aufbau->wartender('Ahrens', prioritaet: 9);
    $zweiter = $aufbau->wartender('Bruns');

    $angebot = angebot(app(Vergabelauf::class)->biete($aufbau->slot()));

    travelTo(CarbonImmutable::now()->addMinutes((int) config('mrs.waitlist.offer_ttl_minutes') + 1));

    expect(Artisan::call('mrs:warteliste-aufraeumen'))->toBe(0);

    expect($angebot->fresh()?->status)->toBe(WaitlistOfferStatus::Expired)
        ->and($erster->fresh()?->status)->toBe(WaitlistStatus::Active)
        ->and($zweiter->fresh()?->status)->toBe(WaitlistStatus::Offered)
        ->and(WaitlistOffer::query()->offen()->count())->toBe(1);
});

it('meldet bei einer Annahme nach Ablauf verstaendlich zurueck', function (): void {
    // Testfall 10: klare Meldung, kein Fehler -- und der Eintrag bleibt aktiv.
    $aufbau = new Wartelistenaufbau;
    $wartender = $aufbau->wartender();

    $angebot = angebot(app(Vergabelauf::class)->biete($aufbau->slot()));

    travelTo(CarbonImmutable::now()->addMinutes((int) config('mrs.waitlist.offer_ttl_minutes') + 1));

    app(Angebotsantwort::class)->nimmAn($angebot, antwortnachricht($angebot));

    expect(Appointment::query()->count())->toBe(0)
        ->and($angebot->fresh()?->status)->toBe(WaitlistOfferStatus::Expired)
        ->and($wartender->fresh()?->status)->toBe(WaitlistStatus::Active);

    $antwort = Message::query()
        ->where('direction', MessageDirection::Outbound->value)
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->firstOrFail();

    expect($antwort->body)->toContain('inzwischen vergeben')
        ->and($antwort->body)->toContain('Warteliste');
});

it('gibt einem Eintrag nie zwei offene Angebote', function (): void {
    // Testfall 11: K9 als Datenbankregel. Zwei gleichzeitige Vergabelaeufe
    // bestuenden eine Pruefung im Code beide.
    $aufbau = new Wartelistenaufbau;
    $wartender = $aufbau->wartender();

    app(Vergabelauf::class)->biete($aufbau->slot('09:00'));

    $zweites = app(Vergabelauf::class)->biete($aufbau->slot('10:00'));

    expect($zweites)->toBeNull()
        ->and(WaitlistOffer::query()->offen()->count())->toBe(1);
});

it('bietet denselben Slot demselben Eintrag nie zweimal an', function (): void {
    // Testfall 12.
    $aufbau = new Wartelistenaufbau;
    $wartender = $aufbau->wartender();

    $erstes = angebot(app(Vergabelauf::class)->biete($aufbau->slot()));

    app(Angebotsantwort::class)->lehneAb($erstes);

    expect($wartender->fresh()?->status)->toBe(WaitlistStatus::Active);

    $zweites = app(Vergabelauf::class)->biete($aufbau->slot());

    expect($zweites)->toBeNull();
});

it('hoert nach den vorgesehenen Runden auf', function (): void {
    // Testfall 13: wer weiter fragt, verbrennt den Kanal.
    $aufbau = new Wartelistenaufbau;

    $runden = (int) config('mrs.waitlist.max_rounds');

    foreach (range(1, $runden + 2) as $nummer) {
        $aufbau->wartender('Warte'.$nummer);
    }

    foreach (range(1, $runden) as $nummer) {
        $angebot = angebot(app(Vergabelauf::class)->biete($aufbau->slot()));

        app(Angebotsantwort::class)->lehneAb($angebot);
    }

    expect(app(Vergabelauf::class)->biete($aufbau->slot()))->toBeNull()
        ->and(WaitlistOffer::query()->count())->toBe($runden);
});

/* Ausloeser ---------------------------------------------------------------- */

it('bietet bei einem wackeligen Termin parallel an, ohne Hold', function (): void {
    // Ausloeser 3: der Slot ist noch belegt, der bestehende Termin bleibt
    // unangetastet.
    $aufbau = new Wartelistenaufbau;
    $aufbau->wartender();

    $angebot = angebot(app(Vergabelauf::class)->biete($aufbau->slot(), WaitlistTrigger::NoResponse));

    expect($angebot->slot_hold_id)->toBeNull()
        ->and(SlotHold::query()->count())->toBe(0);
});

it('loest einen wackeligen Termin nicht automatisch auf', function (): void {
    // "Das ist ausdruecklich kein automatischer Vorgang."
    $aufbau = new Wartelistenaufbau;
    $aufbau->wartender();

    $angebot = angebot(app(Vergabelauf::class)->biete($aufbau->slot(), WaitlistTrigger::NoResponse));

    app(Angebotsantwort::class)->nimmAn($angebot, antwortnachricht($angebot));

    expect(Appointment::query()->count())->toBe(0)
        ->and($angebot->fresh()?->status)->toBe(WaitlistOfferStatus::Accepted);

    $antwort = Message::query()
        ->where('direction', MessageDirection::Outbound->value)
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->firstOrFail();

    expect($antwort->body)->toContain('noch nicht ganz sicher frei');
});

/**
 * Das Angebot -- oder ein klarer Abbruch.
 *
 * `biete()` gibt null zurueck, wenn niemand in Frage kommt; das ist ein
 * gueltiger Ausgang und kein Fehler. Wo ein Test aber mit dem Angebot
 * weiterarbeitet, ist ein fehlendes einer.
 */
function angebot(?WaitlistOffer $angebot): WaitlistOffer
{
    return $angebot ?? throw new RuntimeException('Kein Angebot entstanden.');
}

/** Die eingehende Antwort der Person auf ein Angebot. */
function antwortnachricht(WaitlistOffer $angebot): Message
{
    $identitaet = $angebot->entry->contact->channelIdentities()->firstOrFail();
    $gespraech = app(Konversationen::class)->fuer($identitaet);

    return app(Konversationen::class)->nimmAuf(
        $gespraech,
        'ext-'.bin2hex(random_bytes(4)),
        'Ja, gern',
    ) ?? throw new RuntimeException('Keine Nachricht.');
}

it('gibt die Luecke einer Absage in die Vergabe', function (): void {
    // Der erste Ausloeser -- und der haeufigste.
    $aufbau = new Wartelistenaufbau;
    $aufbau->wartender();

    $vorschlag = $aufbau->slot();

    $termin = app(Terminplaner::class)->buche(
        $vorschlag,
        Contact::create(['first_name' => 'Bernd', 'last_name' => 'Schuster']),
    );

    app(Terminplaner::class)->sageAb($termin, CancellationReason::Contact);

    Queue::assertPushed(LueckeFuellen::class, fn (LueckeFuellen $auftrag): bool => $auftrag->queue === 'default');
});

it('gibt die alte Zeit einer Verschiebung in die Vergabe', function (): void {
    // Testfall 15 des Briefings: nicht die neue Zeit, die alte.
    $aufbau = new Wartelistenaufbau;
    $aufbau->wartender();

    $alt = $aufbau->slot('09:00');
    $neu = $aufbau->slot('11:00');

    $termin = app(Terminplaner::class)->buche(
        $alt,
        Contact::create(['first_name' => 'Bernd', 'last_name' => 'Schuster']),
    );

    app(Terminplaner::class)->verschiebe($termin, $neu);

    $auftraege = [];

    Queue::assertPushed(LueckeFuellen::class, function (LueckeFuellen $auftrag) use (&$auftraege): bool {
        $auftraege[] = $auftrag;

        return true;
    });

    // Den Auftrag selbst ausfuehren: erst dann zeigt sich, **welche** Zeit
    // angeboten wird -- und das ist der Punkt dieses Tests.
    $auftraege[0]->handle(app(TenantContext::class), app(Vergabelauf::class));

    expect(WaitlistOffer::query()->firstOrFail()->starts_at->toIso8601String())
        ->toBe($alt->startsAt->toIso8601String());
});

it('nimmt eine Antwort auf ein Angebot vor der Einordnung an', function (): void {
    // Wer auf "Es ist ein Termin frei geworden" mit "Ja" antwortet, meint
    // dieses Angebot -- und nicht eine neue Terminanfrage.
    $aufbau = new Wartelistenaufbau;
    $aufbau->wartender();

    $angebot = angebot(app(Vergabelauf::class)->biete($aufbau->slot()));

    $nachricht = antwortnachricht($angebot);

    expect(app(Angebotsantwort::class)->pruefe($nachricht))->toBeTrue()
        ->and(Appointment::query()->count())->toBe(1);
});

it('laesst eine Nachricht ohne Zustimmung ihren gewoehnlichen Weg gehen', function (): void {
    // Weder ja noch nein: das Angebot bleibt offen, und der Agent bekommt
    // die Nachricht wie jede andere.
    $aufbau = new Wartelistenaufbau;
    $aufbau->wartender();

    $angebot = angebot(app(Vergabelauf::class)->biete($aufbau->slot()));

    $identitaet = $angebot->entry->contact->channelIdentities()->firstOrFail();
    $gespraech = app(Konversationen::class)->fuer($identitaet);

    $nachricht = app(Konversationen::class)->nimmAuf(
        $gespraech,
        'ext-frage',
        'Wie lange dauert der Termin denn?',
    );

    expect(app(Angebotsantwort::class)->pruefe($nachricht ?? throw new RuntimeException))->toBeFalse()
        ->and($angebot->fresh()?->status)->toBe(WaitlistOfferStatus::Pending);
});

/* Im Produkt --------------------------------------------------------------- */

it('zeigt der Praxis die Warteliste samt Kennzahlen', function (): void {
    // "Diese Auswertung ist das Verkaufsargument im Demo-Termin und gehoert
    // ins Produkt."
    $aufbau = new Wartelistenaufbau;
    $wartender = $aufbau->wartender('Ahrens', vorlaufStunden: 6);

    $angebot = angebot(app(Vergabelauf::class)->biete($aufbau->slot()));
    app(Angebotsantwort::class)->nimmAn($angebot, antwortnachricht($angebot));

    $benutzer = User::factory()->fuer($aufbau->organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->get(route('waitlist.index'))
        ->assertInertia(fn ($seite) => $seite
            ->component('warteliste/Index')
            ->has('entries', 1)
            ->where('entries.0.vorlauf', 6)
            ->where('entries.0.erreichbar', true)
            ->where('metrics.angebote', 1)
            ->where('metrics.angenommen', 1)
            ->where('metrics.annahmequote', 1)
            ->where('grenze', (int) config('mrs.waitlist.max_offers_per_contact_per_month'))
        );
});

it('traegt jemanden auf die Warteliste ein', function (): void {
    $aufbau = new Wartelistenaufbau;
    $benutzer = User::factory()->fuer($aufbau->organisation, Role::Reception)->create();

    $kontakt = Contact::create(['first_name' => 'Clara', 'last_name' => 'Weiss']);

    actingAs($benutzer)
        ->post(route('waitlist.store'), [
            'contact' => (string) $kontakt->uuid,
            'appointment_type' => (string) $aufbau->praxis->art->uuid,
            'earliest_date' => '2027-01-13',
            'latest_date' => '2027-03-31',
            'weekday_mask' => 0b0011111,
            'time_windows' => [['von' => '09:00', 'bis' => '13:00']],
            'min_notice_hours' => 12,
            'expires_at' => '2027-06-30',
        ])
        ->assertSessionHasNoErrors();

    $eintrag = WaitlistEntry::query()->firstOrFail();

    expect($eintrag->min_notice_hours)->toBe(12)
        ->and($eintrag->weekday_mask)->toBe(31)
        ->and($eintrag->all_locations)->toBeTrue()
        ->and($eintrag->time_windows)->toEqual([['von' => '09:00', 'bis' => '13:00']]);
});

it('laesst niemanden ohne waitlist.manage an die Warteliste', function (): void {
    $aufbau = new Wartelistenaufbau;
    $marketing = User::factory()->fuer($aufbau->organisation, Role::Marketing)->create();

    actingAs($marketing)->get(route('waitlist.index'))->assertForbidden();
});
