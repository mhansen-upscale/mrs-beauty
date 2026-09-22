<?php

declare(strict_types=1);

use App\Abrechnung\Kontingente;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Enums\Role;
use App\Kanaele\Konversationen;
use App\Models\ChannelIdentity;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\travelTo;

use Tests\Feature\Kanaele\WhatsAppAufbau;

/*
|--------------------------------------------------------------------------
| WP-34 -- das Backoffice des Betreibers
|--------------------------------------------------------------------------
|
| Regel 1: "Ein Zugriff ueber Mandantengrenzen hinweg ist nur im
| Super-Admin-Backoffice (WP-34) moeglich, dort protokolliert und sichtbar."
|
| **Die Linie:** Zustaende und Zahlen ja, Inhalte nie. Wer in eine Praxis
| hineinsehen muss, geht ueber die Impersonation aus WP-05.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

function betreiber(): User
{
    return User::factory()->create([
        'name' => 'Support',
        'email' => 'support@mrs-beauty.test',
        'organization_id' => null,
        'role' => null,
        'is_super_admin' => true,
    ]);
}

/* Zugang ------------------------------------------------------------------- */

it('laesst nur den Betreiber hinein', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();

    // Auch die Inhaberin nicht -- und auch nicht fuer die eigene Praxis.
    actingAs($inhaberin)->get(route('backoffice.index'))->assertForbidden();
    actingAs($inhaberin)
        ->get(route('backoffice.show', ['organisation' => $organisation->uuid]))
        ->assertForbidden();

    ohneMandant();

    actingAs(betreiber())->get(route('backoffice.index'))->assertOk();
});

it('zeigt dem Betreiber alle Praxen, obwohl er zu keiner gehoert', function (): void {
    alsMandant(Organization::factory()->create(['name' => 'Praxis Nord']));
    alsMandant(Organization::factory()->create(['name' => 'Praxis Süd']));

    ohneMandant();

    actingAs(betreiber())
        ->get(route('backoffice.index'))
        ->assertInertia(fn ($seite) => $seite
            ->component('backoffice/Index')
            ->has('mandanten', 2)
            ->where('installation.mandanten', 2)
        );
});

/* Die Linie ---------------------------------------------------------------- */

it('zeigt Zahlen, aber keine Inhalte', function (): void {
    // **Der Test, der die Zusage des Produkts festhaelt.** Eine Praxis, die
    // die Nachrichten ihrer Patientinnen ueber uns fuehrt, muss sich darauf
    // verlassen koennen, dass "der Anbieter kann alles lesen" nicht stimmt.
    $organisation = alsMandant(organisation('Demo-Praxis'));

    Contact::create(['first_name' => 'Annika', 'last_name' => 'Rosenkohl', 'email' => 'annika@example.test']);

    $aufbau = new WhatsAppAufbau($organisation);

    $identitaet = ChannelIdentity::create([
        'channel' => ChannelType::WhatsApp,
        'external_id' => '4915112345678',
    ]);

    $gespraech = app(Konversationen::class)->fuer($identitaet);
    app(Konversationen::class)->nimmAuf($gespraech, 'wamid-1', 'Mein Geheimnis lautet Rosenkohl');

    ohneMandant();

    $antwort = actingAs(betreiber())
        ->get(route('backoffice.show', ['organisation' => $organisation->uuid]))
        ->assertOk();

    $inhalt = $antwort->getContent();

    expect($inhalt)->not->toContain('Rosenkohl');
    expect($inhalt)->not->toContain('annika@example.test');
    expect($inhalt)->not->toContain('4915112345678');

    // Gezaehlt wird trotzdem.
    $antwort->assertInertia(fn ($seite) => $seite
        ->where('mandant.kontakte', 1)
        ->where('mandant.verbrauch.nachrichten', 0)
    );
});

it('zeigt Stoerungen einer Praxis', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $aufbau = new WhatsAppAufbau($organisation);

    $aufbau->verbindung->meldeAusfall(ConnectionStatus::Expired, 'token_invalid');

    ohneMandant();

    actingAs(betreiber())
        ->get(route('backoffice.show', ['organisation' => $organisation->uuid]))
        ->assertInertia(fn ($seite) => $seite
            ->has('mandant.stoerungen.kanaele', 1)
            ->where('mandant.stoerungen.kanaele.0.grund', 'token_invalid')
        );
});

/* Handlungen --------------------------------------------------------------- */

it('sperrt eine Praxis mit Begruendung und Protokolleintrag', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    ohneMandant();

    actingAs(betreiber())
        ->post(route('backoffice.sperren', ['organisation' => $organisation->uuid]), [
            'grund' => 'Zahlungsausfall nach dritter Mahnung',
        ])
        ->assertSessionHasNoErrors();

    expect($organisation->fresh()?->suspended_at)->not->toBeNull();

    // **Beim Mandanten protokolliert**: die Praxis soll nachlesen koennen,
    // was mit ihr geschehen ist.
    $eintrag = DB::table('audit_logs')->where('event', AuditEvent::TenantSuspended->value)->first();

    expect($eintrag)->not->toBeNull()
        ->and((string) $eintrag?->reason)->toContain('Zahlungsausfall')
        ->and($eintrag?->organization_id)->not->toBeNull();
});

it('verlangt eine Begruendung', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    ohneMandant();

    actingAs(betreiber());

    from(route('backoffice.index'))
        ->post(route('backoffice.sperren', ['organisation' => $organisation->uuid]), ['grund' => ''])
        ->assertSessionHasErrors('grund');

    expect($organisation->fresh()?->suspended_at)->toBeNull();
});

it('entsperrt wieder', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $organisation->suspended_at = CarbonImmutable::now();
    $organisation->save();

    ohneMandant();

    actingAs(betreiber())
        ->post(route('backoffice.entsperren', ['organisation' => $organisation->uuid]), [
            'grund' => 'Zahlung eingegangen',
        ])
        ->assertSessionHasNoErrors();

    expect($organisation->fresh()?->suspended_at)->toBeNull()
        ->and(DB::table('audit_logs')->where('event', AuditEvent::TenantUnsuspended->value)->count())->toBe(1);
});

it('schreibt Kontingent gut -- beim richtigen Mandanten', function (): void {
    $eine = alsMandant(Organization::factory()->create(['name' => 'Praxis Nord']));
    $andere = alsMandant(Organization::factory()->create(['name' => 'Praxis Süd']));

    ohneMandant();

    actingAs(betreiber())
        ->post(route('backoffice.gutschrift', ['organisation' => $eine->uuid]), [
            'art' => 'nachrichten',
            'menge' => 100,
            'grund' => 'Störung der Warteliste vom 12. bis 14. Januar',
        ])
        ->assertSessionHasNoErrors();

    alsMandant($eine);
    expect(app(Kontingente::class)->abo()->extra_messages)->toBe(100);

    alsMandant($andere);
    expect(Subscription::query()->first()?->extra_messages)->toBeNull();
});

it('haelt die Gutschrift im Protokoll fest', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));

    ohneMandant();

    actingAs(betreiber())->post(route('backoffice.gutschrift', ['organisation' => $organisation->uuid]), [
        'art' => 'agentenlaeufe',
        'menge' => 200,
        'grund' => 'Ausfall des Assistenten',
    ]);

    $eintrag = DB::table('audit_logs')->where('event', AuditEvent::TenantCredited->value)->first();

    expect((string) $eintrag?->reason)->toContain('Ausfall des Assistenten')
        ->and((string) $eintrag?->reason)->toContain('200');
});

it('haelt einen Protokolleintrag zu einer Organisation ueberhaupt fest', function (): void {
    // **Die Gegenprobe zum Fund aus WP-34.** Bis dahin scheiterte jeder
    // Eintrag, dessen Gegenstand eine Organisation war: Model::shouldBeStrict
    // wirft beim Zugriff auf ein Feld, das es nicht gibt, und der
    // Protokollierer verschluckte die Ausnahme ins Log. Ein Protokoll, das
    // stillschweigend nichts schreibt, ist keines.
    $organisation = alsMandant(organisation('Demo-Praxis'));

    $eintrag = app(AuditLogger::class)->record(
        ereignis: AuditEvent::TenantSuspended,
        gegenstand: $organisation,
        begruendung: 'Gegenprobe',
    );

    expect($eintrag)->not->toBeNull()
        ->and($eintrag?->reason)->toBe('Gegenprobe');
});
