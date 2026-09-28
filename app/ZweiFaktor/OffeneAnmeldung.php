<?php

declare(strict_types=1);

namespace App\ZweiFaktor;

use App\Enums\Anmeldeeingang;
use App\Models\User;
use LogicException;

/**
 * Eine Anmeldung nach dem Passwort, die auf den Code wartet (WP-35).
 *
 * `gilt()` ist mehr als "noch nicht abgelaufen": Die Person gibt es noch, ihr
 * Passwort und ihr zweiter Faktor sind dieselben wie beim Passwortschritt,
 * sie ist nicht deaktiviert und ihre Praxis nicht gesperrt.
 */
final readonly class OffeneAnmeldung
{
    public function __construct(
        public Anmeldeeingang $eingang,
        public bool $merken,
        public int $fehlversuche,
        private ?User $person,
        private bool $gueltig,
    ) {}

    public function gilt(): bool
    {
        return $this->gueltig && $this->person instanceof User;
    }

    public function person(): User
    {
        if (! $this->gilt() || ! $this->person instanceof User) {
            throw new LogicException('Eine ungueltige Anmeldung hat keine Person.');
        }

        return $this->person;
    }
}
