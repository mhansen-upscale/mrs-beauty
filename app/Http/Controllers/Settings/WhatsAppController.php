<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\Ability;
use App\Enums\ChannelType;
use App\Enums\ConnectionStatus;
use App\Http\Controllers\Controller;
use App\Jobs\WhatsAppPruefen;
use App\Models\ChannelConnection;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Die WhatsApp-Verbindung der Praxis (offen seit WP-20b).
 *
 * **Eingetragen wird, was Meta im Business Manager zeigt**: die Kennung des
 * WhatsApp-Business-Kontos (WABA), die Rufnummern-ID und das Token eines
 * Systembenutzers. Gelesen wird unter der WABA, gesendet unter der
 * Rufnummer -- zwei Kennungen, eine Verbindung (docs/integrationen/meta.md).
 *
 * **Gespeichert wird sofort, geprueft in der Warteschlange** (B2, Regel 4).
 * **Das Token geht nie zurueck an die Oberflaeche.**
 */
final class WhatsAppController extends Controller
{
    public function edit(TenantContext $mandant): Response
    {
        Gate::authorize(Ability::ManageOrganization->value);

        $verbindung = $this->verbindung();

        return Inertia::render('settings/WhatsApp', [
            'eingerichtet' => $verbindung instanceof ChannelConnection,
            'waba' => $verbindung?->external_id,
            'rufnummer' => $verbindung?->sender_id,
            'anzeigename' => $verbindung->display_name ?? $mandant->current()?->name,
            'tokenGesetzt' => is_string($verbindung?->access_token) && $verbindung->access_token !== '',
            'status' => $verbindung?->status->value,
            'statusLabel' => $verbindung?->status->label(),
            'letzterFehler' => $verbindung?->last_error,
            'geprueftAm' => $verbindung?->verified_at?->toIso8601String(),
        ]);
    }

    public function update(Request $request, TenantContext $mandant): RedirectResponse
    {
        Gate::authorize(Ability::ManageOrganization->value);

        $daten = $request->validate([
            'waba' => ['required', 'string', 'regex:/^\d{6,32}$/'],
            'rufnummer' => ['required', 'string', 'regex:/^\d{6,32}$/'],
            'anzeigename' => ['required', 'string', 'max:191'],
            'token' => ['nullable', 'string', 'max:1000'],
        ], [
            'waba.regex' => 'Die Kennung des WhatsApp-Business-Kontos besteht nur aus Ziffern.',
            'rufnummer.regex' => 'Die Rufnummern-ID besteht nur aus Ziffern — nicht die Telefonnummer selbst.',
        ]);

        $organisation = $mandant->current();

        if (! $organisation instanceof Organization) {
            return back();
        }

        $this->pruefeFrei((string) $daten['waba'], $mandant, $organisation);

        $verbindung = $this->verbindung() ?? new ChannelConnection;
        $verbindung->channel = ChannelType::WhatsApp;
        $verbindung->external_id = (string) $daten['waba'];
        $verbindung->sender_id = (string) $daten['rufnummer'];
        $verbindung->display_name = (string) $daten['anzeigename'];

        // Ein leeres Feld heisst "unveraendert", nicht "loeschen".
        if (is_string($daten['token'] ?? null) && $daten['token'] !== '') {
            $verbindung->access_token = (string) $daten['token'];
        }

        if (! is_string($verbindung->access_token) || $verbindung->access_token === '') {
            throw ValidationException::withMessages(['token' => 'Ohne Token lässt sich die Verbindung nicht prüfen.']);
        }

        // Geaenderte Angaben sind ungeprueft, bis Meta geantwortet hat. Bis
        // dahin sendebereit gefuehrt -- eine Pruefung, die gleich laeuft,
        // soll keine Nachricht verlieren.
        $verbindung->status = ConnectionStatus::Active;
        $verbindung->verified_at = null;
        $verbindung->save();

        WhatsAppPruefen::dispatch((string) $organisation->uuid);

        return back()->with('erfolg', 'Gespeichert. Die Verbindung wird jetzt geprüft.');
    }

    public function pruefen(TenantContext $mandant): RedirectResponse
    {
        Gate::authorize(Ability::ManageOrganization->value);

        $organisation = $mandant->current();

        if (! $organisation instanceof Organization || ! $this->verbindung() instanceof ChannelConnection) {
            return back()->withErrors(['waba' => 'Zuerst die Verbindung speichern.']);
        }

        WhatsAppPruefen::dispatch((string) $organisation->uuid);

        return back()->with('erfolg', 'Die Verbindung wird geprüft.');
    }

    /**
     * **Eine WABA gehoert einer Praxis.** Die Zustellung findet ihre Praxis
     * ueber diese Kennung; zweimal vergeben, landete die Post der einen bei
     * der anderen (Regel 1).
     */
    private function pruefeFrei(string $waba, TenantContext $mandant, Organization $praxis): void
    {
        $vergeben = $mandant->acrossTenants(
            'Pruefen, ob eine WhatsApp-Kennung schon einer anderen Praxis gehoert',
            fn (): bool => ChannelConnection::query()
                ->where('channel', ChannelType::WhatsApp->value)
                ->where('external_id', $waba)
                ->where('organization_id', '!=', $praxis->getKey())
                ->exists(),
        );

        if ($vergeben === true) {
            throw ValidationException::withMessages([
                'waba' => 'Dieses WhatsApp-Konto ist bereits mit einer anderen Praxis verbunden.',
            ]);
        }
    }

    private function verbindung(): ?ChannelConnection
    {
        return ChannelConnection::query()
            ->where('channel', ChannelType::WhatsApp->value)
            ->first();
    }
}
