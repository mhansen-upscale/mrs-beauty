<?php

declare(strict_types=1);

use App\Datenschutz\Aufbewahrung;
use App\Models\DemoRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

use function Pest\Laravel\travelTo;

/*
|--------------------------------------------------------------------------
| WP-38, Abnahmekriterium 24 -- die Frist der Demo-Anfragen
|--------------------------------------------------------------------------
|
| Regel 3 und C19: Fristen je Datenart, geloescht wird von Hand. Die
| Anfragen gehoeren keiner Praxis und laufen deshalb neben dem Protokoll ohne
| Mandanten -- mit derselben Vorschau als Vorgabe; scharf nur mit --scharf.
|
*/

beforeEach(function (): void {
    travelTo(CarbonImmutable::parse('2027-01-12 08:00:00', 'UTC'));
    config(['mrs.oeffentlich.demoanfragen.aufbewahrung_monate' => 12]);
});

/** Eine Anfrage, die vor so vielen Monaten einging. */
function demoanfrageVor(int $monate, string $praxis): DemoRequest
{
    return DemoRequest::factory()->create([
        'practice_name' => $praxis,
        'created_at' => CarbonImmutable::now()->subMonths($monate)->subDay(),
    ]);
}

it('loescht Demo-Anfragen nach der Frist und behaelt juengere', function (): void {
    demoanfrageVor(13, 'Alte Praxis');
    $jung = demoanfrageVor(11, 'Junge Praxis');

    expect(Artisan::call('mrs:aufbewahrung', ['--scharf' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Demo-Anfragen');

    expect(DemoRequest::query()->pluck('practice_name')->all())->toBe(['Junge Praxis'])
        ->and(DemoRequest::query()->sole()->is($jung))->toBeTrue();
});

it('zaehlt Demo-Anfragen in der Vorschau, ohne zu loeschen', function (): void {
    demoanfrageVor(13, 'Alte Praxis');
    demoanfrageVor(14, 'Noch aeltere Praxis');

    expect(app(Aufbewahrung::class)->demoanfragen(vorschau: true))->toBe(2);

    expect(Artisan::call('mrs:aufbewahrung'))->toBe(0)
        ->and(Artisan::output())->toContain('Demo-Anfragen');

    expect(DemoRequest::query()->count())->toBe(2);
});

it('raeumt Demo-Anfragen nicht auf, wenn nur eine Organisation gemeint ist', function (): void {
    $praxis = organisation('Demo-Praxis');
    demoanfrageVor(13, 'Alte Praxis');

    expect(Artisan::call('mrs:aufbewahrung', ['--scharf' => true, '--organisation' => (string) $praxis->uuid]))->toBe(0);

    expect(DemoRequest::query()->count())->toBe(1);
});

it('richtet sich nach der Frist aus der Konfiguration', function (): void {
    config(['mrs.oeffentlich.demoanfragen.aufbewahrung_monate' => 3]);
    demoanfrageVor(4, 'Alte Praxis');
    demoanfrageVor(2, 'Junge Praxis');

    expect(app(Aufbewahrung::class)->demoanfragen(vorschau: false))->toBe(1)
        ->and(DemoRequest::query()->pluck('practice_name')->all())->toBe(['Junge Praxis']);
});
