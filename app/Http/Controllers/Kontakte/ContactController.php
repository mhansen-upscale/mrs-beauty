<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kontakte;

use App\Datenschutz\Auskunft;
use App\Datenschutz\Betroffenenrechte;
use App\Enums\Ability;
use App\Enums\ChannelType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kontakte\ChannelIdentityRequest;
use App\Http\Requests\Kontakte\ContactRequest;
use App\Kontakte\Kontaktsuche;
use App\Kontakte\Nichtzusammenfuehrbar;
use App\Kontakte\Zusammenfuehrung;
use App\Models\ChannelIdentity;
use App\Models\Contact;
use App\Models\ContactMerge;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kontakte: sehen, anlegen, Kanaele pflegen, zusammenfuehren.
 *
 * **Die Suche findet nur exakt** (Entscheidung P8). Das ist keine Schwaeche
 * der Umsetzung, sondern die Folge der Feldverschluesselung -- und es gehoert
 * sichtbar in die Oberflaeche, sonst haelt der Empfang die Suche fuer kaputt.
 */
final class ContactController extends Controller
{
    /** Wie viele Kontakte die Liste ohne Suche zeigt. */
    private const ZULETZT = 100;

    public function __construct(
        private readonly Kontaktsuche $suche,
        private readonly Zusammenfuehrung $zusammenfuehrung,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Ability::ManageContacts->value);

        $begriff = trim((string) $request->query('search', ''));

        return Inertia::render('kontakte/Index', [
            'search' => $begriff,
            'search_field' => $begriff === '' ? null : Kontaktsuche::feldFuer($begriff),

            'contacts' => $this->liste($begriff)
                ->map(fn (Contact $kontakt): array => $this->darstellung($kontakt))
                ->values(),

            'channels' => collect(ChannelType::cases())
                ->map(fn (ChannelType $kanal): array => [
                    'value' => $kanal->value,
                    'label' => $kanal->label(),
                ])
                ->values(),

            // Entscheidung D6: was kein hartes Signal hat, wird vorgeschlagen
            // und nicht getan.
            'suggestions' => collect($this->zusammenfuehrung->vorschlaege())
                ->map(fn (array $paar): array => [
                    'a' => $this->darstellung($paar[0]),
                    'b' => $this->darstellung($paar[1]),
                ])
                ->values(),

            'merges' => ContactMerge::query()
                ->with('winner')
                ->whereNull('reverted_at')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(fn (ContactMerge $vorgang): array => [
                    'uuid' => $vorgang->uuid,
                    'winner' => $vorgang->winner->name(),
                    'merged_at' => $vorgang->created_at?->toIso8601String(),
                    'expires_at' => $vorgang->snapshot_expires_at->toIso8601String(),
                    'revertable' => $vorgang->istUmkehrbar(),
                ])
                ->values(),
        ]);
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        Contact::create($request->validated());

        return back();
    }

    public function update(ContactRequest $request, Contact $contact): RedirectResponse
    {
        $contact->update($request->validated());

        return back();
    }

    /**
     * Echt loeschen, nicht markieren (Entscheidung A12).
     *
     * Ein Soft Delete auf `contacts` waere genau die Hintertuer, die eine
     * Loeschanfrage nach DSGVO unwirksam macht.
     *
     * Der Weg fuehrt ueber die Betroffenenrechte und nicht ueber delete():
     * an einer Person haengen Termine, Anfragen, Kanaele, Einwilligungen,
     * Notizen und Dateien. Ein Loeschen, das nur die Zeile entfernt, ist
     * keines -- und der Vorgang bleibt als Nachweis (WP-18).
     */
    public function destroy(Contact $contact, Betroffenenrechte $rechte, Request $request): RedirectResponse
    {
        Gate::authorize(Ability::ManageContacts->value);

        $rechte->loeschung($contact, $request->user());

        return back();
    }

    /**
     * Auskunft nach Artikel 15 -- als Datei.
     *
     * Der Export wird **nicht** gespeichert: eine aufbewahrte Auskunft waere
     * eine zweite Kopie aller Daten der Person. Der Vorgang haelt fest, dass
     * und wann sie erteilt wurde.
     */
    public function export(Contact $contact, Betroffenenrechte $rechte, Auskunft $auskunft, Request $request): StreamedResponse
    {
        Gate::authorize(Ability::ManageContacts->value);

        $daten = $auskunft->fuerKontakt($contact);
        $rechte->auskunft($contact, $request->user());

        $name = 'auskunft-'.$contact->uuid.'.json';

        return response()->streamDownload(
            function () use ($daten): void {
                echo (string) json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            },
            $name,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    public function storeIdentity(ChannelIdentityRequest $request, Contact $contact): RedirectResponse
    {
        $daten = $request->validated();

        $contact->channelIdentities()->create([
            'channel' => ChannelType::from((string) $daten['channel']),
            'external_id' => (string) $daten['external_id'],
            'display_name' => $daten['display_name'] ?? null,
        ]);

        return back();
    }

    public function destroyIdentity(Contact $contact, ChannelIdentity $identity): RedirectResponse
    {
        Gate::authorize(Ability::ManageContacts->value);

        $identity->delete();

        return back();
    }

    public function merge(Request $request, Contact $contact): RedirectResponse
    {
        Gate::authorize(Ability::ManageContacts->value);

        $daten = $request->validate([
            'loser' => ['required', 'uuid'],
        ]);

        $verlierer = Contact::query()->whereUuid((string) $daten['loser'])->firstOrFail();

        try {
            $this->zusammenfuehrung->fuehreZusammen($contact, $verlierer, $request->user());
        } catch (Nichtzusammenfuehrbar $ausnahme) {
            return back()->withErrors(['loser' => $ausnahme->getMessage()]);
        }

        return back();
    }

    public function revert(ContactMerge $merge): RedirectResponse
    {
        Gate::authorize(Ability::ManageContacts->value);

        try {
            $this->zusammenfuehrung->macheRueckgaengig($merge);
        } catch (Nichtzusammenfuehrbar $ausnahme) {
            return back()->withErrors(['merge' => $ausnahme->getMessage()]);
        }

        return back();
    }

    /**
     * @return Collection<int, Contact>
     */
    private function liste(string $begriff): Collection
    {
        if ($begriff !== '') {
            /** @var Collection<int, Contact> */
            return Contact::query()
                ->whereKey($this->suche->suche($begriff, 50)->modelKeys())
                ->with('channelIdentities')
                ->get();
        }

        /** @var Collection<int, Contact> */
        return Contact::query()
            ->with('channelIdentities')
            ->orderByDesc('created_at')
            ->limit(self::ZULETZT)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function darstellung(Contact $kontakt): array
    {
        return [
            'uuid' => $kontakt->uuid,
            'first_name' => $kontakt->first_name,
            'last_name' => $kontakt->last_name,
            'name' => $kontakt->name(),
            'email' => $kontakt->email,
            'phone' => $kontakt->phone,
            'phone_display' => $kontakt->telefonAnzeige(),
            'created_at' => $kontakt->created_at?->toIso8601String(),
            'identities' => $kontakt->relationLoaded('channelIdentities')
                ? $kontakt->channelIdentities
                    ->map(fn (ChannelIdentity $identitaet): array => [
                        'uuid' => $identitaet->uuid,
                        'channel' => $identitaet->channel->value,
                        'channel_label' => $identitaet->channel->label(),
                        'external_id' => $identitaet->kennungAnzeige(),
                        'display_name' => $identitaet->display_name,
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }
}
