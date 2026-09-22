<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Der öffentliche Buchungslink gehört ins Produkt
|--------------------------------------------------------------------------
|
| Bis WP-19 stand er nirgends: eine Praxis, die ihn auf ihre Website oder in
| die Instagram-Biografie setzen wollte, musste ihn raten. Verlinkt war er
| einzig auf der Bestätigungsseite der Buchungsstrecke selbst.
|
*/

it('zeigt der Praxis ihren Buchungslink samt QR-Code', function (): void {
    $organisation = alsMandant(organisation('Demo-Praxis'));
    $benutzer = User::factory()->fuer($organisation, Role::Reception)->create();

    actingAs($benutzer)
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite
            ->component('Dashboard')
            ->where('booking.slug', $organisation->slug)
            ->where('booking.url', route('buchung.zeigen', ['praxis' => $organisation->slug]))
            // Als Datenadresse, nicht als eingesetztes SVG (Regel 5).
            ->where('booking.qr', fn (string $qr): bool => str_starts_with($qr, 'data:image/svg+xml;base64,')
                && str_contains((string) base64_decode(mb_substr($qr, 26), true), '<svg'))
        );
});

it('fuehrt der Link auf die oeffentliche Buchungsseite', function (): void {
    // Die Gegenprobe: ein Link, der ins Leere zeigt, waere schlimmer als
    // keiner -- die Praxis gibt ihn weiter.
    $organisation = alsMandant(organisation('Demo-Praxis'));

    ohneMandant();

    get(route('buchung.zeigen', ['praxis' => $organisation->slug]))
        ->assertOk()
        ->assertInertia(fn ($seite) => $seite->component('buchung/Index'));
});

it('zeigt ohne Mandanten keinen Link', function (): void {
    // Ein Super-Admin hat keine eigene Praxis -- und damit keinen Link.
    $benutzer = User::factory()->create(['is_super_admin' => true, 'organization_id' => null]);

    actingAs($benutzer)
        ->get(route('dashboard'))
        ->assertInertia(fn ($seite) => $seite->where('booking', null));
});
