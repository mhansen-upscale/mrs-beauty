<?php

declare(strict_types=1);

namespace App\Http\Requests\Stammdaten;

use App\Enums\Ability;
use App\Enums\Weekday;
use App\Models\Location;
use App\Models\Practitioner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class WorkingHourRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Ability::ManageMasterData->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'location' => ['required', 'string', 'uuid'],
            'weekday' => ['required', Rule::enum(Weekday::class)],
            'starts_at' => ['required', 'date_format:H:i'],

            // Ein Fenster, das endet bevor es beginnt, waere in WP-10 eine
            // leere Menge -- ohne dass irgendwo ein Fehler entstuende.
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $behandler = $this->route('practitioner');

            if (! $behandler instanceof Practitioner) {
                return;
            }

            $standort = Location::query()->whereUuid((string) $this->input('location'))->first();

            if (! $standort instanceof Location) {
                $validator->errors()->add('location', 'Diesen Standort gibt es nicht.');

                return;
            }

            // Kriterium 11: eine Arbeitszeit an einem Standort, an dem der
            // Behandler gar nicht arbeitet, ergibt keinen Sinn.
            if (! $behandler->locations()->whereKey($standort->getKey())->exists()) {
                $validator->errors()->add('location', 'Dieser Behandler arbeitet nicht an diesem Standort.');

                return;
            }

            // Kriterium 9: ueberschneidende Fenster ergeben in WP-10 doppelte
            // Slot-Zeilen und damit einen Unique-Verstoss an einer Stelle, an
            // der niemand nach der Ursache sucht.
            $beginn = $this->uhrzeit('starts_at');
            $ende = $this->uhrzeit('ends_at');

            //
            // **Ueber alle Standorte hinweg**, nicht nur ueber diesen. Ein
            // Mensch kann nicht an zwei Orten gleichzeitig sein -- und WP-10
            // materialisiert je Behandler und Zeitpunkt genau eine Zeile.
            // Zwei Standorte mit ueberlappenden Fenstern liefen dort in den
            // Unique-Index, an einer Stelle, an der niemand nach der Ursache
            // sucht.
            $ueberschneidung = $behandler->workingHours()
                ->where('weekday', (int) $this->input('weekday'))
                ->where('starts_at', '<', $ende)
                ->where('ends_at', '>', $beginn)
                ->first();

            if ($ueberschneidung !== null) {
                $andererOrt = $ueberschneidung->location_id !== $standort->getKey();

                $validator->errors()->add(
                    'starts_at',
                    $andererOrt
                        ? 'Zu dieser Zeit arbeitet der Behandler bereits an einem anderen Standort.'
                        : 'Dieses Fenster ueberschneidet sich mit einem bestehenden.'
                );
            }
        });
    }

    public function uhrzeit(string $feld): string
    {
        return ((string) $this->input($feld)).':00';
    }
}
