<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_schickt_gaeste_zur_anmeldung(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_laesst_angemeldete_auf_das_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response->assertStatus(200);
    }

    /**
     * Das 'verified'-Middleware liegt seit jeher auf dieser Route, wirkte aber
     * nicht, weil User das Interface MustVerifyEmail nicht deklariert hatte.
     * Dieser Test haelt fest, dass es jetzt greift.
     */
    public function test_schickt_unbestaetigte_zur_mailbestaetigung(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);

        $response = $this->get('/dashboard');

        $response->assertRedirect(route('verification.notice'));
    }
}
