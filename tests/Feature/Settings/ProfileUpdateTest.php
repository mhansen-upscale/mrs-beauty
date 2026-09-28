<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_zeigt_die_profilseite(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/settings/profile');

        $response->assertOk();
    }

    public function test_aendert_name_und_mailadresse(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_laesst_die_mailbestaetigung_stehen_wenn_die_adresse_gleich_bleibt(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_loescht_das_eigene_konto(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/settings/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_verlangt_zum_loeschen_das_richtige_passwort(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/profile')
            ->delete('/settings/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->fresh());
    }

    /*
     * Die letzte Inhaberin muss bleiben (WP-04) -- auch gegen sich selbst.
     * Sonst sperrt sich die Praxis aus ihrem eigenen Produkt aus, und niemand
     * ausser dem Betreiber kommt wieder hinein.
     */

    public function test_laesst_die_letzte_inhaberin_ihr_konto_nicht_loeschen(): void
    {
        $praxis = alsMandant(organisation('Demo-Praxis'));
        $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();
        // Eine deaktivierte Inhaberin kommt nicht hinein -- sie zaehlt nicht.
        User::factory()->fuer($praxis, Role::Owner)->deaktiviert()->create();
        ohneMandant();

        $response = $this
            ->actingAs($inhaberin)
            ->from('/settings/profile')
            ->delete('/settings/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect('/settings/profile');

        $this->assertAuthenticatedAs($inhaberin);
        $this->assertNotNull($inhaberin->fresh());
    }

    public function test_loescht_eine_inhaberin_wenn_eine_weitere_bleibt(): void
    {
        $praxis = alsMandant(organisation('Demo-Praxis'));
        $inhaberin = User::factory()->fuer($praxis, Role::Owner)->create();
        User::factory()->fuer($praxis, Role::Owner)->create();
        ohneMandant();

        $response = $this
            ->actingAs($inhaberin)
            ->delete('/settings/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($inhaberin->fresh());
    }

    public function test_loescht_ein_mitglied_ohne_inhaberrolle(): void
    {
        $praxis = alsMandant(organisation('Demo-Praxis'));
        User::factory()->fuer($praxis, Role::Owner)->create();
        $empfang = User::factory()->fuer($praxis, Role::Reception)->create();
        ohneMandant();

        $response = $this
            ->actingAs($empfang)
            ->delete('/settings/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($empfang->fresh());
    }
}
