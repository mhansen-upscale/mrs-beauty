<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Abrechnung\Paket;
use App\Abrechnung\Stripe\Stripeclient;
use App\Models\PlanVersion;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Richtet ein Stripe-Konto fuer das Produkt ein -- **nur noch Schluessel
 * eintragen** (28.09.2026, WP-06).
 *
 * Bis hier entstanden Gutschein, Webhook-Endpunkt und Kundenportal von Hand
 * im Dashboard, je Umgebung. Der Endpunkt musste auf genau der API-Version
 * stehen, die der Client festnagelt (WP-34c) -- eine Version, die das
 * Dashboard nicht unbedingt anbietet, die API aber schon.
 *
 * **Wiederholbar.** Jeder Schritt findet zuerst, was es schon gibt; ein
 * zweiter Lauf legt nichts an. Das Webhook-Geheimnis nennt Stripe nur beim
 * Anlegen -- dieser Befehl deshalb auch.
 *
 * **Kein Anfragezyklus** (Regel 4): ein Betriebsbefehl, von Hand gestartet.
 * Der Schluessel erscheint in keiner Ausgabe.
 */
final class StripeEinrichten extends Command
{
    protected $signature = 'mrs:stripe-einrichten
        {--force : Im Live-Konto ohne Rueckfrage schreiben}
        {--webhook-neu : Den Endpunkt neu anlegen, etwa bei falscher API-Version oder verlorenem Geheimnis}';

    protected $description = 'Legt Webhook-Endpunkt, Gutschein und Kundenportal bei Stripe an und prueft den Rest';

    /** @var list<array{0: string, 1: string}> */
    private array $zeilen = [];

    private bool $gescheitert = false;

    private ?string $geheimnis = null;

    public function handle(Stripeclient $stripe, Paket $paket): int
    {
        // Laravel haelt die Befehlsinstanz; ein zweiter Aufruf im selben
        // Prozess saehe sonst die Zeilen -- und das Geheimnis -- des ersten.
        $this->zeilen = [];
        $this->gescheitert = false;
        $this->geheimnis = null;

        if (! $stripe->angebunden()) {
            $this->error('STRIPE_SECRET ist nicht gesetzt. Ohne Schluessel bleibt Stripe aussen vor -- das ist der Testbetrieb (docs/betrieb.md).');

            return self::FAILURE;
        }

        $this->line('Stripe-Konto: '.($stripe->live() ? '<options=bold>Live</>' : 'Testmodus').', API-Version '.config('services.stripe.api_version'));

        if ($stripe->live() && ! $this->option('force')
            && ! $this->confirm('Das ist das Live-Konto. Jetzt bei Stripe anlegen?')) {
            $this->warn('Abgebrochen, nichts angelegt.');

            return self::FAILURE;
        }

        // **Jeder Schritt fuer sich**: lehnt Stripe den Gutschein ab, steht
        // der Webhook trotzdem.
        $schritte = [
            'Webhook' => fn () => $this->webhook($stripe),
            'Gutschein' => fn () => $this->gutschein($stripe),
            'Kundenportal' => fn () => $this->portal($stripe),
            'Stripe Tax' => fn () => $this->steuer($stripe),
        ];

        foreach ($schritte as $name => $schritt) {
            try {
                $schritt();
            } catch (ConnectionException) {
                $this->zeilen[] = [$name, 'Stripe ist nicht erreichbar -- spaeter erneut ausfuehren'];
                $this->gescheitert = true;
            } catch (RuntimeException $ablehnung) {
                $this->zeilen[] = [$name, $ablehnung->getMessage()];
                $this->gescheitert = true;
            }
        }

        $this->paketpreise($paket);

        $this->table(['Schritt', 'Stand'], $this->zeilen);

        if ($this->geheimnis !== null) {
            $this->newLine();
            $this->warn('Das Geheimnis zeigt Stripe nur dieses eine Mal. Jetzt in die Umgebung eintragen:');
            $this->line('STRIPE_WEBHOOK_SECRET='.$this->geheimnis);
        }

        $this->newLine();
        $this->line('<options=bold>Noch im Dashboard</>, was die API nicht regelt:');
        $this->line('- Geschaeftsdaten und Auszahlungskonto');
        $this->line('- Stripe Tax aktivieren, Registrierung Deutschland -- auch im Testmodus');
        $this->line('- SEPA-Lastschrift als Zahlungsart aktiv');
        $this->line('- Billing -> Revenue recovery: wenn alle Versuche scheitern, Abo als "unbezahlt" markieren, nicht kuendigen');

        return $this->gescheitert ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Der Endpunkt fuer die Zustellungen -- auf der festgenagelten Version,
     * mit genau den Ereignissen, die der Webhook liest.
     */
    private function webhook(Stripeclient $stripe): void
    {
        $adresse = route('stripe.webhook');

        if ($this->lokal($adresse)) {
            $this->zeilen[] = ['Webhook', 'lokal uebersprungen -- stripe listen --forward-to '.$adresse.' traegt die Zustellungen'];

            return;
        }

        /** @var list<string> $ereignisse */
        $ereignisse = array_values((array) config('mrs.billing.stripe_ereignisse'));
        $version = (string) config('services.stripe.api_version');

        $vorhandene = array_values(array_filter(
            (array) data_get($this->antwort($stripe->webhookEndpunkte()), 'data', []),
            fn (mixed $endpunkt): bool => data_get($endpunkt, 'url') === $adresse,
        ));

        if ($vorhandene !== [] && ! $this->option('webhook-neu')) {
            $endpunkt = $vorhandene[0];
            $seine = data_get($endpunkt, 'api_version');

            if ($seine !== $version) {
                $this->zeilen[] = ['Webhook', 'steht auf '.(is_string($seine) ? $seine : 'der Version des Kontos').' statt '.$version.' -- mit --webhook-neu neu anlegen'];
                $this->gescheitert = true;

                return;
            }

            $bisher = array_map(strval(...), (array) data_get($endpunkt, 'enabled_events', []));
            $soll = $ereignisse;
            sort($bisher);
            sort($soll);

            if ($bisher === $soll) {
                $this->zeilen[] = ['Webhook', 'vorhanden, unveraendert -- das Geheimnis gilt weiter'];

                return;
            }

            $this->antwort($stripe->aendereWebhook((string) data_get($endpunkt, 'id'), $ereignisse, $this->schluessel()));
            $this->zeilen[] = ['Webhook', 'vorhanden, Ereignisse abgeglichen -- das Geheimnis gilt weiter'];

            return;
        }

        // **Erst der neue, dann weg mit dem alten** -- dazwischen geht keine
        // Zustellung verloren, und die Dedupe (WP-34c) faengt die doppelte ab.
        $neu = $this->antwort($stripe->legeWebhookAn($adresse, $ereignisse, $this->schluessel()));

        foreach ($vorhandene as $alt) {
            $this->antwort($stripe->entferneWebhook((string) data_get($alt, 'id')));
        }

        $geheimnis = data_get($neu, 'secret');
        $this->geheimnis = is_string($geheimnis) ? $geheimnis : null;

        $this->zeilen[] = ['Webhook', ($vorhandene === [] ? 'angelegt' : 'neu angelegt, der alte entfernt').' auf '.$version.' -- Geheimnis siehe unten'];
    }

    private function gutschein(Stripeclient $stripe): void
    {
        $kennung = (string) config('services.stripe.free_month_coupon');
        $this->antwort($stripe->stelleGutscheinSicher($kennung, $this->schluessel()));
        $this->zeilen[] = ['Gutschein', $kennung.' (100 %, einmal) steht'];
    }

    private function portal(Stripeclient $stripe): void
    {
        $vorhanden = $stripe->eigenePortalkonfiguration();

        $this->antwort($stripe->speicherePortalkonfiguration($vorhanden, $this->schluessel()));
        $this->zeilen[] = ['Kundenportal', $vorhanden === null ? 'angelegt' : 'abgeglichen'];
    }

    /** Ohne Stripe Tax lehnt jede Kasse ab -- sie laesst die USt. berechnen. */
    private function steuer(Stripeclient $stripe): void
    {
        $antwort = $stripe->steuereinstellungen();

        if (! $antwort->successful()) {
            $this->zeilen[] = ['Stripe Tax', 'nicht pruefbar ('.$antwort->status().') -- im Dashboard nachsehen'];

            return;
        }

        $this->zeilen[] = data_get($antwort->json(), 'status') === 'active'
            ? ['Stripe Tax', 'aktiv']
            : ['Stripe Tax', '<fg=yellow>nicht aktiv -- ohne lehnt Stripe jede Kasse ab</>'];
    }

    /**
     * Die Preise legt die Paketfassung selbst an (WP-06b), aber nur eine neue:
     * `PaketfassungAnlegen` nimmt keine Fassung, die schon gilt.
     */
    private function paketpreise(Paket $paket): void
    {
        if (PlanVersion::query()->where('stripe_state', PlanVersion::AUSSTEHEND)->exists()) {
            $this->zeilen[] = ['Paketpreise', 'eine Fassung wird gerade bei Stripe angelegt'];

            return;
        }

        $this->zeilen[] = $paket->aktuell()->hatStripePreise()
            ? ['Paketpreise', 'vorhanden']
            : ['Paketpreise', '<fg=yellow>fehlen -- im Backoffice unter Paket die Fassung speichern, auch unveraendert</>'];
    }

    /**
     * Die Antwort -- oder der Schritt gilt als gescheitert, mit Stripes
     * Meldung.
     *
     * @return array<string, mixed>
     */
    private function antwort(Response $antwort): array
    {
        if ($antwort->successful()) {
            return (array) $antwort->json();
        }

        $meldung = data_get($antwort->json(), 'error.message');

        throw new RuntimeException('Stripe lehnt ab ('.$antwort->status().')'.(is_string($meldung) ? ' -- '.$meldung : ''));
    }

    /**
     * Je Aufruf ein eigener Schluessel: er schuetzt die Wiederholung nach
     * einem Ausfall innerhalb dieses Laufs. Einen zweiten Lauf schuetzt das
     * Wiederfinden, nicht der Schluessel.
     */
    private function schluessel(): string
    {
        return 'mrs-einrichten-'.bin2hex(random_bytes(12));
    }

    /** Was Stripe nicht erreichen kann, bekommt keinen Endpunkt. */
    private function lokal(string $adresse): bool
    {
        $host = (string) parse_url($adresse, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test');
    }
}
