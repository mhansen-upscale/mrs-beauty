<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Enums\MessageCostCategory;
use App\Enums\MessageStatus;
use App\Jobs\NachrichtSenden;
use App\Kanaele\Konversationen;
use App\Kanaele\Nachrichtenversand;
use App\Models\ChannelIdentity;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\travelTo;

use Symfony\Component\Mime\Email;
use Tests\Feature\Kanaele\MailAufbau;

/*
|--------------------------------------------------------------------------
| WP-20b -- Antworten per E-Mail
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
});

/** Ein Gespraech mit einer eingegangenen Mail. */
function mailgespraech(?string $kennung = 'abc-1@example.test', ?string $betreff = 'Frage zum Termin'): Conversation
{
    $identitaet = ChannelIdentity::create([
        'channel' => ChannelType::Email,
        'external_id' => 'annika@example.test',
    ]);

    $konversation = app(Konversationen::class)->fuer($identitaet);

    if ($kennung !== null) {
        app(Konversationen::class)->nimmAuf($konversation, $kennung, 'Guten Tag', null, null, $betreff);
    }

    return $konversation->fresh() ?? $konversation;
}

/** Die zuletzt hinausgegangene Mail. */
function letzteMail(): Email
{
    $mails = [];

    Event::assertDispatched(MessageSent::class, function (MessageSent $ereignis) use (&$mails): bool {
        $mails[] = $ereignis->message;

        return true;
    });

    $letzte = end($mails);

    return $letzte instanceof Email ? $letzte : throw new RuntimeException('Keine Mail versendet.');
}

it('schickt die Antwort an den Absender, im Namen der Praxis', function (): void {
    // **Absender ist die Praxis, Rueckweg sind wir**: sonst landet die
    // Antwort im Postfach der Praxis und nicht in der Inbox.
    Event::fake([MessageSent::class]);
    new MailAufbau;

    app(Nachrichtenversand::class)->stelleEin(mailgespraech(), 'Gern, nächsten Dienstag um 10 Uhr.');

    $mail = letzteMail();

    expect($mail->getTo()[0]->getAddress())->toBe('annika@example.test')
        ->and($mail->getFrom()[0]->getAddress())->toBe(MailAufbau::ABSENDER)
        ->and($mail->getFrom()[0]->getName())->toBe('Demo-Praxis')
        ->and($mail->getReplyTo()[0]->getAddress())->toBe(MailAufbau::EINGANG)
        ->and($mail->getTextBody())->toBe('Gern, nächsten Dienstag um 10 Uhr.');
});

it('haengt die Antwort in den Faden', function (): void {
    // Ohne In-Reply-To erscheint jede Antwort als neue Mail: der Empfaenger
    // haette drei Gespraeche im Postfach und wir eines.
    Event::fake([MessageSent::class]);
    new MailAufbau;

    app(Nachrichtenversand::class)->stelleEin(mailgespraech(), 'Antwort');

    $koepfe = letzteMail()->getHeaders();

    expect($koepfe->get('In-Reply-To')?->getBodyAsString())->toContain('abc-1@example.test')
        ->and($koepfe->get('References')?->getBodyAsString())->toContain('abc-1@example.test');
});

it('haelt die eigene Kennung fest, damit die Antwort darauf wiedergefunden wird', function (): void {
    Event::fake([MessageSent::class]);
    new MailAufbau;

    app(Nachrichtenversand::class)->stelleEin(mailgespraech(), 'Antwort');

    $nachricht = Message::query()->where('direction', 'outbound')->firstOrFail();
    $kopf = letzteMail()->getHeaders()->get('Message-ID')?->getBodyAsString();

    expect($nachricht->external_id)->not->toBeNull()
        ->and($kopf)->toContain((string) $nachricht->external_id);
});

it('antwortet mit Re: auf den Betreff des Gespraechs', function (): void {
    Event::fake([MessageSent::class]);
    new MailAufbau;

    app(Nachrichtenversand::class)->stelleEin(mailgespraech(), 'Antwort');

    expect(letzteMail()->getSubject())->toBe('Re: Frage zum Termin');
});

it('verdoppelt ein vorhandenes Re: nicht', function (): void {
    Event::fake([MessageSent::class]);
    new MailAufbau;

    app(Nachrichtenversand::class)->stelleEin(mailgespraech(betreff: 'Re: Frage zum Termin'), 'Antwort');

    expect(letzteMail()->getSubject())->toBe('Re: Frage zum Termin');
});

it('faellt ohne Betreff auf einen Vorgabetext zurueck', function (): void {
    // Eine Mail ohne Betreff landet in manchen Postfaechern im Spam.
    Event::fake([MessageSent::class]);
    new MailAufbau;

    app(Nachrichtenversand::class)->stelleEin(mailgespraech(betreff: null), 'Antwort');

    expect(letzteMail()->getSubject())->toBe((string) config('mrs.channels.email.default_subject'));
});

it('kostet nichts, und das ist keine Schaetzung', function (): void {
    // Anders als bei WhatsApp: eine Mail hat keinen Preis je Nachricht.
    Mail::fake();
    new MailAufbau;

    app(Nachrichtenversand::class)->stelleEin(mailgespraech(), 'Antwort');

    $nachricht = Message::query()->where('direction', 'outbound')->firstOrFail();

    expect($nachricht->status)->toBe(MessageStatus::Sent)
        ->and($nachricht->cost_category)->toBe(MessageCostCategory::None);
});

it('sendet ohne Service-Fenster und ohne Einwilligung', function (): void {
    // Der Unterschied zu WhatsApp, ausgeschrieben: hier gibt es kein Fenster,
    // das zugehen koennte, und kein Opt-in, das Meta verlangt.
    Mail::fake();
    new MailAufbau;

    $konversation = mailgespraech();

    expect($konversation->fensterOffen())->toBeFalse();

    app(Nachrichtenversand::class)->stelleEin($konversation, 'Antwort');

    expect(Message::query()->where('direction', 'outbound')->firstOrFail()->status)
        ->toBe(MessageStatus::Sent);
});

it('sendet nicht an eine Kennung, die keine Adresse ist', function (): void {
    Mail::fake();
    new MailAufbau;

    $identitaet = ChannelIdentity::create([
        'channel' => ChannelType::Email,
        'external_id' => 'keine-adresse',
    ]);

    app(Nachrichtenversand::class)->stelleEin(app(Konversationen::class)->fuer($identitaet), 'Antwort');

    expect(Message::query()->where('direction', 'outbound')->firstOrFail()->failure)
        ->toBe('invalid_recipient');

    Mail::assertNothingSent();
});

it('reiht den Versand ein, statt ihn im Anfragezyklus zu erledigen', function (): void {
    // Regel 4, unveraendert fuer diesen Kanal.
    Queue::fake();
    Mail::fake();
    new MailAufbau;

    app(Nachrichtenversand::class)->stelleEin(mailgespraech(), 'Antwort');

    Queue::assertPushed(NachrichtSenden::class);
    Mail::assertNothingSent();
});
