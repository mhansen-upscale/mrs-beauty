<?php

declare(strict_types=1);

use App\Benachrichtigung\Versand\Plattformversand;
use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Middleware\ApplyImpersonation;
use App\Kanaele\Email\Postfach;
use App\Models\ChannelConnection;
use App\Models\ImpersonationSession;
use App\Models\Organization;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Testfall-Basis
|--------------------------------------------------------------------------
|
| Feature-Tests laufen gegen eine echte MySQL-Datenbank (siehe phpunit.xml),
| nicht gegen SQLite im Speicher. Sperrverhalten, generierte Spalten und
| JSON-Abfragen bildet SQLite anders ab -- ein gruener Test waere dort
| genau bei den Stellen wertlos, an denen es darauf ankommt.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Mandanten
|--------------------------------------------------------------------------
*/

/** Legt eine Organisation samt Schluesselsatz an. */
function organisation(string $name = 'Praxis'): Organization
{
    return Organization::factory()->create(['name' => $name]);
}

/** Legt eine Organisation an und setzt sie als geltenden Mandanten. */
function alsMandant(?Organization $organization = null): Organization
{
    $organization ??= organisation();

    app(TenantContext::class)->set($organization);

    return $organization;
}

/**
 * Ein laufendes, bezahltes Abo fuer den geltenden Mandanten.
 *
 * Fuer Tests, die weit in die Zukunft springen: seit WP-34c sperrt das Ende
 * der Testphase (B18), und eine Praxis, die Monate nach ihrem Anlegen noch
 * arbeitet, zahlt.
 */
function bezahltesAbo(): void
{
    $abo = new Subscription;
    $abo->status = SubscriptionStatus::Active;
    $abo->stripe_subscription_id = 'sub_test';
    $abo->save();
}

/**
 * Eine neue, geltende Paketfassung -- der Ersatz fuer `config()->set()` auf
 * `mrs.billing.*` (WP-06b).
 *
 * **Am Paket vorbei**: ohne Auftrag, ohne Protokoll, sofort geltend. Mit
 * `$fuerAlle` zeigen auch die bestehenden Abos darauf -- so wirkte vorher die
 * geaenderte Konfiguration.
 *
 * @param  array<string, int|string|null>  $werte  Spalten von plan_versions
 */
function neuesPaket(array $werte = [], bool $fuerAlle = true): PlanVersion
{
    $vorige = PlanVersion::query()->whereNotNull('activated_at')->orderByDesc('number')->firstOrFail();

    $fassung = new PlanVersion;
    $fassung->forceFill([
        ...collect($vorige->getAttributes())->except(['id', 'number', 'created_at', 'updated_at', 'activated_at'])->all(),
        'reason' => 'Test',
        ...$werte,
        'number' => ((int) PlanVersion::query()->max('number')) + 1,
        'stripe_state' => PlanVersion::BEREIT,
        'activated_at' => CarbonImmutable::now(),
    ]);
    $fassung->save();

    if ($fuerAlle) {
        DB::table('subscriptions')->update(['plan_version_id' => $fassung->getKey()]);
    }

    return $fassung;
}

/** Vergisst den Mandanten. */
function ohneMandant(): void
{
    app(TenantContext::class)->forget();
}

/*
|--------------------------------------------------------------------------
| Impersonation
|--------------------------------------------------------------------------
*/

/**
 * Was ImpersonationController::store in die Sitzung legt (WP-34a).
 *
 * **Sitzungskennung und Praxis**, nicht nur die Kennung: ApplyImpersonation
 * sucht die Sitzung damit mandantengebunden statt ueber die Grenze -- und
 * schreibt deshalb keinen Querzugriff je Seitenaufruf mehr.
 *
 * @return array<string, string>
 */
function impersonationSitzung(ImpersonationSession $sitzung): array
{
    $praxis = Organization::query()->whereKey($sitzung->organization_id)->firstOrFail();

    return [
        ApplyImpersonation::SESSION_KEY => (string) $sitzung->uuid,
        ApplyImpersonation::ORGANISATION_KEY => (string) $praxis->uuid,
    ];
}

/*
|--------------------------------------------------------------------------
| Stripe
|--------------------------------------------------------------------------
*/

/**
 * Die Signatur einer Stripe-Zustellung (WP-06). Hier, weil mehrere Dateien
 * Zustellungen schicken -- globale Helfer duerfen nur einmal existieren.
 *
 * @param  array<string, mixed>  $daten
 * @return array<string, string>
 */
function stripekopf(array $daten, string $geheimnis = 'whsec_test'): array
{
    $rumpf = (string) json_encode($daten);
    $zeit = time();

    return ['Stripe-Signature' => 't='.$zeit.',v1='.hash_hmac('sha256', $zeit.'.'.$rumpf, $geheimnis)];
}

/*
|--------------------------------------------------------------------------
| Mailversand (WP-36, WP-37)
|--------------------------------------------------------------------------
|
| **Mail::fake() faengt Mail::build() nicht.** Postfach und Plattformversand
| bauen ihren Mailer je Versand; ohne Attrappe verbaende ein Test wirklich mit
| dem Mailserver aus der Zeile. Die Attrappe tauscht den Transport, nicht den
| Mailer -- Rahmen, Absender und Anhang laufen durch wie im Betrieb.
|
*/

/** Die Mails, die ueber das Postfach einer Praxis hinausgehen. */
function postfachAttrappe(): ArrayTransport
{
    $attrappe = new ArrayTransport;

    Mail::extend(Postfach::TRANSPORT, fn (): TransportInterface => $attrappe);

    return $attrappe;
}

/** Die Mails, die ueber den hinterlegten Server der Plattform hinausgehen. */
function plattformAttrappe(): ArrayTransport
{
    $attrappe = new ArrayTransport;

    Mail::extend(Plattformversand::TRANSPORT, fn (): TransportInterface => $attrappe);

    return $attrappe;
}

/**
 * Ein sendebereites Postfach fuer den geltenden Mandanten -- **immer mit
 * Attrappe** (B22).
 *
 * Seit WP-36 geht keine Mail an eine Patientin ohne eigenes Postfach hinaus.
 * Wer Terminmails, Posteingang oder Warteliste testet, braucht deshalb eines.
 */
function praxispostfach(string $absender = 'praxis@demo-praxis.de', ?ArrayTransport $attrappe = null): ChannelConnection
{
    if ($attrappe === null) {
        postfachAttrappe();
    } else {
        Mail::extend(Postfach::TRANSPORT, fn (): TransportInterface => $attrappe);
    }

    $verbindung = ChannelConnection::query()->where('channel', ChannelType::Email->value)->first() ?? new ChannelConnection;
    $verbindung->channel = ChannelType::Email;
    $verbindung->status = ConnectionStatus::Active;
    if (! $verbindung->exists) {
        $verbindung->external_id = 'praxis-'.bin2hex(random_bytes(3)).'@inbound.mrs-beauty.test';
        $verbindung->display_name = 'Demo-Praxis';
    }

    $verbindung->sender_id = $absender;
    $verbindung->smtp_host = 'smtp.demo-praxis.de';
    $verbindung->smtp_port = 587;
    $verbindung->smtp_encryption = 'tls';
    $verbindung->smtp_username = 'praxis@demo-praxis.de';
    $verbindung->smtp_password = 'app-passwort';
    $verbindung->save();

    return $verbindung;
}

/**
 * Die Mails einer Attrappe als Symfony-Nachrichten.
 *
 * @return list<Email>
 */
function versandteMails(ArrayTransport $attrappe): array
{
    return array_values(array_filter(
        array_map(fn (SentMessage $gesendet): mixed => $gesendet->getOriginalMessage(), $attrappe->messages()->all()),
        fn (mixed $nachricht): bool => $nachricht instanceof Email,
    ));
}
