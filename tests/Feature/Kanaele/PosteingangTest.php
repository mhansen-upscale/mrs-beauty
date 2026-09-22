<?php

declare(strict_types=1);

use App\Enums\AgentAction;
use App\Enums\AgentIntent;
use App\Enums\AgentMode;
use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageCostCategory;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Enums\TemplateStatus;
use App\Jobs\NachrichtSenden;
use App\Kanaele\Konversationen;
use App\Kanaele\Nachrichtenversand;
use App\Models\AgentRun;
use App\Models\ChannelIdentity;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

use Tests\Feature\Kanaele\WhatsAppAufbau;

/*
|--------------------------------------------------------------------------
| WP-21 -- der Posteingang
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/**
 * Ein Gespraech mit einer eingegangenen Nachricht.
 */
function eingang(
    ChannelType $kanal = ChannelType::WhatsApp,
    string $kennung = '4915112345678',
    string $text = 'Guten Tag, haben Sie morgen etwas frei?',
    ?CarbonImmutable $wann = null,
): Conversation {
    $identitaet = ChannelIdentity::create([
        'channel' => $kanal,
        'external_id' => $kennung,
        'display_name' => 'Annika Müller',
    ]);

    $gespraech = app(Konversationen::class)->fuer($identitaet);

    app(Konversationen::class)->nimmAuf(
        $gespraech,
        'ext-'.bin2hex(random_bytes(4)),
        $text,
        null,
        $wann ?? CarbonImmutable::now(),
    );

    return $gespraech->fresh() ?? $gespraech;
}

function empfang(): User
{
    $organisation = alsMandant(Organization::factory()->create(['name' => 'Demo-Praxis', 'slug' => 'demo-praxis']));

    return User::factory()->fuer($organisation, Role::Reception)->create();
}

/* Liste -------------------------------------------------------------------- */

it('zeigt nur Gespraeche des eigenen Mandanten', function (): void {
    $benutzer = empfang();
    eingang();

    $fremde = Organization::factory()->create(['name' => 'Fremde Praxis']);
    alsMandant($fremde);
    eingang(kennung: '4915199999999');

    alsMandant($benutzer->organization ?? throw new RuntimeException);

    actingAs($benutzer)
        ->get(route('inbox.index'))
        ->assertInertia(fn ($seite) => $seite
            ->component('inbox/Index')
            ->has('conversations', 1)
            ->where('conversations.0.name', 'Annika Müller')
        );
});

it('sortiert nach letzter Aktivitaet, neueste zuerst', function (): void {
    $benutzer = empfang();

    eingang(kennung: '4915111111111', wann: CarbonImmutable::now()->subHours(3));
    eingang(kennung: '4915122222222', wann: CarbonImmutable::now()->subMinutes(5));

    actingAs($benutzer)
        ->get(route('inbox.index'))
        ->assertInertia(fn ($seite) => $seite->where('conversations.0.name', 'Annika Müller')
            ->where('conversations.0.uuid', fn (string $uuid): bool => Conversation::query()
                ->whereUuid($uuid)
                ->firstOrFail()
                ->channelIdentity
                ->external_id === '4915122222222')
        );
});

it('zaehlt als ungelesen, was nach dem letzten Lesen hereinkam', function (): void {
    $benutzer = empfang();
    $gespraech = eingang();

    actingAs($benutzer);

    // Die Liste allein liest nichts: eine Zahl, die von allein kleiner wird,
    // sagt nichts mehr aus.
    get(route('inbox.index'))
        ->assertInertia(fn ($seite) => $seite->where('unread', 1)->where('conversations.0.ungelesen', true));

    expect($gespraech->fresh()?->ungelesen())->toBeTrue();

    // Ausgewaehlt heisst gesehen.
    get(route('inbox.index', ['gespraech' => $gespraech->uuid]))->assertOk();

    expect($gespraech->fresh()?->ungelesen())->toBeFalse();

    // Eine Minute spaeter: die Spalten zaehlen Sekunden, und ein Lesen im
    // selben Augenblick wie die Nachricht gilt als gelesen.
    travelTo(CarbonImmutable::now()->addMinute());

    app(Konversationen::class)->nimmAuf($gespraech->fresh() ?? $gespraech, 'ext-neu', 'Und übermorgen?');

    expect($gespraech->fresh()?->ungelesen())->toBeTrue();
});

it('filtert nach Kanal', function (): void {
    $benutzer = empfang();

    eingang();
    eingang(kanal: ChannelType::Email, kennung: 'annika@example.test');

    actingAs($benutzer)
        ->get(route('inbox.index', ['kanal' => 'email']))
        ->assertInertia(fn ($seite) => $seite->has('conversations', 1)->where('conversations.0.channel', 'email'));
});

it('filtert nach Zustand', function (): void {
    $benutzer = empfang();
    $gespraech = eingang();

    app(Konversationen::class)->schliesse($gespraech);

    actingAs($benutzer);

    get(route('inbox.index'))->assertInertia(fn ($seite) => $seite->has('conversations', 0));
    get(route('inbox.index', ['zustand' => 'closed']))->assertInertia(fn ($seite) => $seite->has('conversations', 1));
});

it('sucht ueber den Namen des Kontakts, nicht ueber Inhalte', function (): void {
    // Entscheidung P8: Inhalte sind verschluesselt und kennen kein LIKE.
    $benutzer = empfang();

    $kontakt = Contact::create(['first_name' => 'Annika', 'last_name' => 'Mueller']);
    $gespraech = eingang();
    $gespraech->contact_id = $kontakt->getKey();
    $gespraech->save();

    eingang(kennung: '4915133333333', text: 'Mueller ist mein Nachbar');

    actingAs($benutzer);

    get(route('inbox.index', ['suche' => 'Mueller']))
        ->assertInertia(fn ($seite) => $seite->has('conversations', 1)->where('searchField', 'last_name'));

    // Der Inhalt "Mueller" im zweiten Gespraech findet nichts.
    get(route('inbox.index', ['suche' => 'Nachbar']))
        ->assertInertia(fn ($seite) => $seite->has('conversations', 0));
});

it('laesst niemanden ohne inbox.view hinein', function (): void {
    $organisation = alsMandant(Organization::factory()->create(['slug' => 'demo-praxis']));
    $marketing = User::factory()->fuer($organisation, Role::Marketing)->create();

    actingAs($marketing)->get(route('inbox.index'))->assertForbidden();
});

/* Verlauf ------------------------------------------------------------------ */

it('zeigt den Verlauf in zeitlicher Folge', function (): void {
    $benutzer = empfang();
    $gespraech = eingang();

    Queue::fake();

    app(Nachrichtenversand::class)->stelleEin($gespraech, 'Gern, um 10 Uhr.');

    actingAs($benutzer)
        ->get(route('inbox.index', ['gespraech' => $gespraech->uuid]))
        ->assertInertia(fn ($seite) => $seite
            ->has('conversation.messages', 2)
            ->where('conversation.messages.0.eingehend', true)
            ->where('conversation.messages.0.inhalt', 'Guten Tag, haben Sie morgen etwas frei?')
            ->where('conversation.messages.1.eingehend', false)
        );
});

it('kommt nicht an den Verlauf einer fremden Praxis', function (): void {
    $benutzer = empfang();

    $fremde = Organization::factory()->create(['name' => 'Fremde Praxis']);
    alsMandant($fremde);
    $fremdes = eingang(kennung: '4915199999999');

    alsMandant($benutzer->organization ?? throw new RuntimeException);

    // Der globale Scope laesst die fremde Zeile gar nicht erst finden --
    // der Verlauf bleibt leer statt fremd zu sein (Regel 1).
    actingAs($benutzer)
        ->get(route('inbox.index', ['gespraech' => $fremdes->uuid]))
        ->assertInertia(fn ($seite) => $seite->where('conversation', null));
});

/* Antworten ---------------------------------------------------------------- */

it('reiht eine Antwort ein, statt sie im Anfragezyklus zu senden', function (): void {
    Queue::fake();
    $benutzer = empfang();
    $gespraech = eingang();

    actingAs($benutzer)
        ->post(route('inbox.reply', ['conversation' => $gespraech->uuid]), ['body' => 'Gern, um 10 Uhr.'])
        ->assertSessionHasNoErrors();

    $nachricht = Message::query()->where('direction', 'outbound')->firstOrFail();

    expect($nachricht->body)->toBe('Gern, um 10 Uhr.')
        ->and($nachricht->status)->toBe(MessageStatus::Queued);

    Queue::assertPushed(NachrichtSenden::class);
});

it('laesst einen Behandler mitlesen, aber nicht antworten', function (): void {
    // Eine Nachricht an eine Patientin ist nicht zurueckzuholen.
    $organisation = alsMandant(Organization::factory()->create(['slug' => 'demo-praxis']));
    $behandler = User::factory()->fuer($organisation, Role::Practitioner)->create();
    $gespraech = eingang();

    actingAs($behandler);

    get(route('inbox.index'))->assertOk()
        ->assertInertia(fn ($seite) => $seite->where('darfAntworten', false));

    post(route('inbox.reply', ['conversation' => $gespraech->uuid]), ['body' => 'Hallo'])->assertForbidden();
});

it('zeigt bei offenem Fenster kein Template an', function (): void {
    $benutzer = empfang();
    $gespraech = eingang();

    actingAs($benutzer)
        ->get(route('inbox.index', ['gespraech' => $gespraech->uuid]))
        ->assertInertia(fn ($seite) => $seite
            ->where('conversation.fenster.gilt', true)
            ->where('conversation.fenster.offen', true)
            ->where('conversation.fenster.brauchtTemplate', false)
            ->has('conversation.templates', 0)
        );
});

it('zeigt bei geschlossenem Fenster die Templates samt Kostenfolge', function (): void {
    // **Die Kostenanzeige, bevor jemand tippt** (B7, B8): wer erst beim
    // Absenden erfaehrt, dass es kostet, hat es schon ausgegeben.
    $benutzer = empfang();
    $gespraech = eingang(wann: CarbonImmutable::now()->subHours(30));

    $template = new WhatsAppTemplate;
    $template->name = 'terminerinnerung';
    $template->language = 'de';
    $template->category = MessageCostCategory::Utility;
    $template->status = TemplateStatus::Approved;
    $template->body = 'Guten Tag {{1}}.';
    $template->variables = 1;
    $template->save();

    actingAs($benutzer)
        ->get(route('inbox.index', ['gespraech' => $gespraech->uuid]))
        ->assertInertia(fn ($seite) => $seite
            ->where('conversation.fenster.offen', false)
            ->where('conversation.fenster.brauchtTemplate', true)
            ->has('conversation.templates', 1)
            ->where('conversation.templates.0.kategorie', 'Utility')
            ->where('conversation.templates.0.kostet', true)
            ->where('conversation.templates.0.variablen', 1)
        );
});

it('schickt ein Template mit Variablen ab', function (): void {
    Queue::fake();
    $benutzer = empfang();
    $gespraech = eingang(wann: CarbonImmutable::now()->subHours(30));

    $template = new WhatsAppTemplate;
    $template->name = 'terminerinnerung';
    $template->language = 'de';
    $template->category = MessageCostCategory::Utility;
    $template->status = TemplateStatus::Approved;
    $template->body = 'Guten Tag {{1}}.';
    $template->variables = 1;
    $template->save();

    actingAs($benutzer)
        ->post(route('inbox.template', ['conversation' => $gespraech->uuid]), [
            'template' => (string) $template->uuid,
            'werte' => ['Annika Müller'],
        ])
        ->assertSessionHasNoErrors();

    $nachricht = Message::query()->where('direction', 'outbound')->firstOrFail();

    expect($nachricht->body)->toBe('Guten Tag Annika Müller.')
        ->and($nachricht->templatewerte())->toBe(['Annika Müller']);
});

it('weist ein nicht genehmigtes Template ab', function (): void {
    Queue::fake();
    $benutzer = empfang();
    $gespraech = eingang(wann: CarbonImmutable::now()->subHours(30));

    $template = new WhatsAppTemplate;
    $template->name = 'werbung';
    $template->language = 'de';
    $template->category = MessageCostCategory::Marketing;
    $template->status = TemplateStatus::Rejected;
    $template->save();

    actingAs($benutzer)
        ->post(route('inbox.template', ['conversation' => $gespraech->uuid]), ['template' => (string) $template->uuid])
        ->assertSessionHasErrors('template');

    expect(Message::query()->where('direction', 'outbound')->count())->toBe(0);
});

it('kennt bei E-Mail weder Fenster noch Template', function (): void {
    $benutzer = empfang();
    $gespraech = eingang(kanal: ChannelType::Email, kennung: 'annika@example.test');

    actingAs($benutzer)
        ->get(route('inbox.index', ['gespraech' => $gespraech->uuid]))
        ->assertInertia(fn ($seite) => $seite
            ->where('conversation.fenster.gilt', false)
            ->where('conversation.fenster.brauchtTemplate', false)
        );
});

/* Kontakt und Zustand ------------------------------------------------------ */

it('schlaegt einen Kontakt mit derselben Rufnummer vor', function (): void {
    // Entscheidung D6: nur bei hartem Signal, und vorschlagen statt tun.
    $benutzer = empfang();

    $kontakt = Contact::create(['first_name' => 'Annika', 'last_name' => 'Mueller', 'phone' => '+49 151 12345678']);
    $gespraech = eingang(kennung: '+4915112345678');

    actingAs($benutzer)
        ->get(route('inbox.index', ['gespraech' => $gespraech->uuid]))
        ->assertInertia(fn ($seite) => $seite->where('conversation.vorschlag.uuid', (string) $kontakt->uuid));
});

it('traegt die Zuordnung an der Kanalidentitaet nach', function (): void {
    $benutzer = empfang();
    $kontakt = Contact::create(['first_name' => 'Annika', 'last_name' => 'Mueller']);
    $gespraech = eingang();

    actingAs($benutzer)
        ->post(route('inbox.assign', ['conversation' => $gespraech->uuid]), ['contact' => (string) $kontakt->uuid])
        ->assertSessionHasNoErrors();

    $frisch = $gespraech->fresh();

    expect($frisch?->contact_id)->toBe($kontakt->getKey())
        ->and($frisch?->channelIdentity->contact_id)->toBe($kontakt->getKey());
});

it('schliesst ein Gespraech und oeffnet es bei neuer Post wieder', function (): void {
    $benutzer = empfang();
    $gespraech = eingang();

    actingAs($benutzer)->post(route('inbox.close', ['conversation' => $gespraech->uuid]))->assertSessionHasNoErrors();

    expect($gespraech->fresh()?->status)->toBe(ConversationStatus::Closed);

    app(Konversationen::class)->nimmAuf($gespraech->fresh() ?? $gespraech, 'ext-neu', 'Doch noch eine Frage');

    expect($gespraech->fresh()?->status)->toBe(ConversationStatus::Open);
});

it('zeigt eine gestoerte Verbindung im Produkt', function (): void {
    // Regel 4: ein Ausfall erzeugt einen Hinweis im Produkt, nicht nur im Log.
    $benutzer = empfang();
    $aufbau = new WhatsAppAufbau($benutzer->organization);

    $aufbau->verbindung->meldeAusfall(ConnectionStatus::Expired, 'token_invalid');

    actingAs($benutzer)
        ->get(route('inbox.index'))
        ->assertInertia(fn ($seite) => $seite
            ->where('connections.0.stoerung', true)
            ->where('connections.0.statusLabel', 'Unterbrochen')
        );
});

it('zeigt eine Nachricht mit Auszeichnung als Text', function (): void {
    // Regel 5: was hereinkommt, ist eine Zeichenkette. Der Test haelt fest,
    // dass sie **unveraendert** durchgereicht wird -- das Setzen als Text
    // macht die Oberflaeche, geprueft in tests/Feature/Design.
    $benutzer = empfang();
    $gespraech = eingang(text: '<script>alert(1)</script> Guten Tag');

    actingAs($benutzer)
        ->get(route('inbox.index', ['gespraech' => $gespraech->uuid]))
        ->assertInertia(fn ($seite) => $seite
            ->where('conversation.messages.0.inhalt', '<script>alert(1)</script> Guten Tag'));
});

it('zeigt an, wann eine Nachricht ankam, nicht wann die Zeile entstand', function (): void {
    // Ein erneut eingespieltes Rohereignis (WP-19) entsteht heute und traegt
    // trotzdem den Zeitpunkt von damals.
    $benutzer = empfang();
    $gespraech = eingang(wann: CarbonImmutable::now()->subHours(5));

    actingAs($benutzer)
        ->get(route('inbox.index', ['gespraech' => $gespraech->uuid]))
        ->assertInertia(fn ($seite) => $seite->where(
            'conversation.messages.0.zeitpunkt',
            CarbonImmutable::now()->subHours(5)->toIso8601String(),
        ));
});

it('schlaegt das erste Gespraech auf, waehlt es aber nicht aus', function (): void {
    // Mobil steht entweder die Liste oder das Gespraech. Waere das erste
    // schon ausgewaehlt, fuehrte der Zurueck-Knopf wieder dorthin -- die
    // Liste bekaeme auf einem Telefon niemand zu sehen.
    $benutzer = empfang();
    $gespraech = eingang();

    actingAs($benutzer);

    get(route('inbox.index'))
        ->assertInertia(fn ($seite) => $seite->where('ausgewaehlt', false)->has('conversation'));

    get(route('inbox.index', ['gespraech' => $gespraech->uuid]))
        ->assertInertia(fn ($seite) => $seite->where('ausgewaehlt', true));
});

/* Der Agent in der Inbox (WP-22) ------------------------------------------- */

it('zeigt Absicht, Sicherheit und Vorschlag im Gespraech', function (): void {
    $benutzer = empfang();
    $gespraech = eingang();
    $nachricht = Message::query()->firstOrFail();

    $lauf = new AgentRun;
    $lauf->conversation_id = $gespraech->getKey();
    $lauf->message_id = $nachricht->getKey();
    $lauf->action = AgentAction::Suggested;
    $lauf->intent = AgentIntent::GeneralQuestion;
    $lauf->confidence = 0.91;
    $lauf->suggestion = 'Guten Tag, wir haben montags bis freitags geöffnet.';
    $lauf->save();

    actingAs($benutzer)
        ->get(route('inbox.index', ['gespraech' => $gespraech->uuid]))
        ->assertInertia(fn ($seite) => $seite
            ->where('conversation.agent.absichtLabel', 'Allgemeine Frage')
            ->where('conversation.agent.sicherheit', 0.91)
            ->where('conversation.agent.vorschlag', 'Guten Tag, wir haben montags bis freitags geöffnet.')
        );
});

it('zeigt zu einer Uebergabe keinen Vorschlag', function (): void {
    $benutzer = empfang();
    $gespraech = eingang();
    $nachricht = Message::query()->firstOrFail();

    $lauf = new AgentRun;
    $lauf->conversation_id = $gespraech->getKey();
    $lauf->message_id = $nachricht->getKey();
    $lauf->action = AgentAction::Escalated;
    $lauf->intent = AgentIntent::MedicalQuestion;
    $lauf->escalation_reason = 'medical_question';
    $lauf->save();

    actingAs($benutzer)
        ->get(route('inbox.index', ['gespraech' => $gespraech->uuid]))
        ->assertInertia(fn ($seite) => $seite
            ->where('conversation.agent.vorschlag', null)
            ->where('conversation.agent.grund', 'medical_question')
        );
});

it('stellt den Modus des Agenten um', function (): void {
    $organisation = alsMandant(Organization::factory()->create(['slug' => 'demo-praxis']));
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    $gespraech = eingang();

    actingAs($inhaberin)
        ->post(route('inbox.agent', ['conversation' => $gespraech->uuid]), ['modus' => 'suggest'])
        ->assertSessionHasNoErrors();

    expect($gespraech->fresh()?->agent_mode)->toBe(AgentMode::Suggest);
});

it('laesst auto seit WP-24 einstellen -- und nichts daneben', function (): void {
    // Bis WP-23 war `auto` gesperrt; das war die Sicherheitsleine, solange
    // die Guardrails fehlten. Jetzt ist es waehlbar -- aber nur die drei
    // Werte, die es gibt: **im Controller geprueft, nicht nur in der
    // Oberflaeche.**
    $organisation = alsMandant(Organization::factory()->create(['slug' => 'demo-praxis']));
    $inhaberin = User::factory()->fuer($organisation, Role::Owner)->create();
    $gespraech = eingang();

    actingAs($inhaberin);

    post(route('inbox.agent', ['conversation' => $gespraech->uuid]), ['modus' => 'auto'])
        ->assertSessionHasNoErrors();

    expect($gespraech->fresh()?->agent_mode)->toBe(AgentMode::Auto);

    from(route('inbox.index'))
        ->post(route('inbox.agent', ['conversation' => $gespraech->uuid]), ['modus' => 'halbautomatisch'])
        ->assertSessionHasErrors('modus');

    expect($gespraech->fresh()?->agent_mode)->toBe(AgentMode::Auto);
});

it('laesst den Empfang den Agenten nicht umstellen', function (): void {
    $benutzer = empfang();
    $gespraech = eingang();

    actingAs($benutzer)
        ->post(route('inbox.agent', ['conversation' => $gespraech->uuid]), ['modus' => 'off'])
        ->assertForbidden();
});
