<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Auth\EmailVerificationRequest;

/**
 * Wie die Fassung des Frameworks, nur vergleicht sie die **kanonische** UUID
 * statt des Primaerschluessels.
 *
 * Der Primaerschluessel liegt als BINARY(16) vor (Entscheidung A4) und laesst
 * sich nicht in eine URL schreiben. Der zugehoerige Aufbau der URL steht in
 * AppServiceProvider::configureEmailVerification().
 */
final class VerifyEmailRequest extends EmailVerificationRequest
{
    public function authorize(): bool
    {
        $benutzer = $this->user();

        if (! $benutzer instanceof User) {
            return false;
        }

        if (! hash_equals((string) $benutzer->uuid, (string) $this->route('id'))) {
            return false;
        }

        return hash_equals(
            sha1($benutzer->getEmailForVerification()),
            (string) $this->route('hash')
        );
    }
}
