<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Jede Mail, die das System verschickt -- das Register (WP-36, WP-37).
 *
 * **Auch die festen.** Die Uebersicht in den Einstellungen und im Backoffice
 * beantwortet die Frage "welche Mails gehen hinaus?" aus dieser Liste; eine
 * Mail, die hier fehlt, gibt es fuer niemanden. tests/Feature/Mailvorlagen/
 * RegisterTest.php haelt jede Notification gegen sie.
 *
 * Wer eine Vorlage bearbeitet, folgt aus dem Versandweg: Die Praxis gestaltet
 * ihre Mails an Patientinnen, der Betreiber die an Konten (P12).
 *
 * Der Wert steht in der Adresse des Editors und in der Spalte `template`.
 */
enum Mailart: string
{
    // Postfach der Praxis, gestaltbar
    case Eingangsbestaetigung = 'eingangsbestaetigung';
    case Terminbestaetigung = 'terminbestaetigung';
    case Erinnerung = 'erinnerung';
    case Verschiebung = 'verschiebung';
    case Absage = 'absage';

    // Postfach der Praxis, fest
    case Posteingangsantwort = 'posteingangsantwort';
    case Wartelistenangebot = 'wartelistenangebot';
    case Postfachprobe = 'postfachprobe';

    // Versand der Plattform, gestaltbar
    case EmailBestaetigen = 'email-bestaetigen';
    case PasswortZuruecksetzen = 'passwort-zuruecksetzen';
    case Einladung = 'einladung';
    case Agentenalarm = 'agentenalarm';
    case Anmeldecode = 'anmeldecode';
    case Einrichtungscode = 'einrichtungscode';

    // Versand der Plattform, fest
    case Versandprobe = 'versandprobe';

    public function versandweg(): Versandweg
    {
        return match ($this) {
            self::Eingangsbestaetigung,
            self::Terminbestaetigung,
            self::Erinnerung,
            self::Verschiebung,
            self::Absage,
            self::Posteingangsantwort,
            self::Wartelistenangebot,
            self::Postfachprobe => Versandweg::Praxis,
            default => Versandweg::Plattform,
        };
    }

    /** Laesst sich die Mail gestalten -- oder ist ihr Text Produkt? */
    public function istVorlage(): bool
    {
        return ! in_array($this, [self::Posteingangsantwort, self::Wartelistenangebot, self::Postfachprobe, self::Versandprobe], true);
    }

    /**
     * Die gestaltbaren Mails eines Wegs, in der Reihenfolge der Oberflaeche.
     *
     * @return list<self>
     */
    public static function vorlagen(Versandweg $weg): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $art): bool => $art->versandweg() === $weg && $art->istVorlage(),
        ));
    }

    public static function fuerTermin(NotificationKind $art): self
    {
        return match ($art) {
            NotificationKind::RequestReceived => self::Eingangsbestaetigung,
            NotificationKind::Confirmation => self::Terminbestaetigung,
            NotificationKind::Reminder => self::Erinnerung,
            NotificationKind::Rescheduled => self::Verschiebung,
            NotificationKind::Cancellation => self::Absage,
        };
    }

    public function termin(): ?NotificationKind
    {
        return match ($this) {
            self::Eingangsbestaetigung => NotificationKind::RequestReceived,
            self::Terminbestaetigung => NotificationKind::Confirmation,
            self::Erinnerung => NotificationKind::Reminder,
            self::Verschiebung => NotificationKind::Rescheduled,
            self::Absage => NotificationKind::Cancellation,
            default => null,
        };
    }

    public function istTerminmail(): bool
    {
        return $this->termin() instanceof NotificationKind;
    }

    public function label(): string
    {
        return match ($this) {
            self::Eingangsbestaetigung => 'Anfrage erhalten',
            self::Terminbestaetigung => 'Terminbestätigung',
            self::Erinnerung => 'Erinnerung',
            self::Verschiebung => 'Terminverschiebung',
            self::Absage => 'Absage',
            self::Posteingangsantwort => 'Antwort aus dem Posteingang',
            self::Wartelistenangebot => 'Angebot von der Warteliste',
            self::Postfachprobe => 'Probemail des Postfachs',
            self::EmailBestaetigen => 'E-Mail-Adresse bestätigen',
            self::PasswortZuruecksetzen => 'Passwort zurücksetzen',
            self::Einladung => 'Einladung ins Team',
            self::Agentenalarm => 'Übergabe durch den Assistenten',
            self::Anmeldecode => 'Anmeldecode',
            self::Einrichtungscode => 'Code zur Einrichtung des zweiten Faktors',
            self::Versandprobe => 'Probemail des Plattformversands',
        };
    }

    /** Wann sie hinausgeht und an wen. */
    public function beschreibung(): string
    {
        return match ($this) {
            self::Eingangsbestaetigung => 'An die Patientin, sobald sie über die Buchungsseite angefragt hat.',
            self::Terminbestaetigung => 'An die Patientin, sobald der Termin bestätigt ist.',
            self::Erinnerung => 'An die Patientin, vor dem Termin.',
            self::Verschiebung => 'An die Patientin, wenn der Termin verschoben wurde.',
            self::Absage => 'An die Patientin, wenn der Termin abgesagt wurde.',
            self::Posteingangsantwort => 'An die Patientin — der Text ist Ihre Antwort selbst.',
            self::Wartelistenangebot => 'An die Patientin, wenn ein Platz frei wird und sie Mail bevorzugt.',
            self::Postfachprobe => 'An Sie, wenn Sie das Postfach prüfen.',
            self::EmailBestaetigen => 'An jedes neue Konto, das sich selbst registriert.',
            self::PasswortZuruecksetzen => 'An jedes Konto, das ein neues Passwort anfordert, und an neue Betreiberkonten.',
            self::Einladung => 'An eingeladene Mitarbeitende einer Praxis.',
            self::Agentenalarm => 'An das Team einer Praxis, wenn der Assistent ein Gespräch übergibt — ohne den Inhalt.',
            self::Anmeldecode => 'An Konten mit zweitem Faktor per E-Mail, bei der Anmeldung.',
            self::Einrichtungscode => 'An Konten, die den zweiten Faktor per E-Mail einschalten.',
            self::Versandprobe => 'An Sie, wenn Sie den Plattformserver prüfen.',
        };
    }

    /**
     * Welche Platzhalter in welchem Feld stehen duerfen.
     *
     * **Der Betreff einer Terminmail kennt weder Behandlung noch Person**
     * (C17): er steht als Vorschau auf einem Sperrbildschirm. Die Code-Mails
     * haben im Betreff gar keinen Platzhalter.
     *
     * @return list<Platzhalter>
     */
    public function platzhalter(Mailfeld $feld): array
    {
        if (! $this->istVorlage()) {
            return [];
        }

        if ($this->istTerminmail()) {
            return match ($feld) {
                Mailfeld::Betreff => [Platzhalter::Praxis, Platzhalter::Tag, Platzhalter::Datum, Platzhalter::Uhrzeit],
                Mailfeld::Gruss => [Platzhalter::Praxis, Platzhalter::Standort, Platzhalter::Behandler],
                default => [
                    Platzhalter::Vorname, Platzhalter::Nachname, Platzhalter::Name, Platzhalter::Praxis,
                    Platzhalter::Tag, Platzhalter::Datum, Platzhalter::Uhrzeit, Platzhalter::Standort,
                    Platzhalter::Behandler, Platzhalter::Behandlung,
                ],
            };
        }

        return match ($this) {
            self::EmailBestaetigen, self::PasswortZuruecksetzen => match ($feld) {
                Mailfeld::Betreff, Mailfeld::Gruss => [Platzhalter::Produkt],
                default => [Platzhalter::Produkt, Platzhalter::Name, Platzhalter::Minuten],
            },
            self::Einladung => match ($feld) {
                Mailfeld::Betreff, Mailfeld::Gruss => [Platzhalter::Produkt, Platzhalter::Praxis],
                default => [Platzhalter::Produkt, Platzhalter::Praxis, Platzhalter::Rolle, Platzhalter::Frist],
            },
            self::Agentenalarm => match ($feld) {
                Mailfeld::Betreff => [Platzhalter::Praxis],
                Mailfeld::Gruss => [Platzhalter::Praxis, Platzhalter::Produkt],
                default => [Platzhalter::Praxis, Platzhalter::Grund],
            },
            self::Anmeldecode, self::Einrichtungscode => match ($feld) {
                Mailfeld::Betreff => [],
                Mailfeld::Gruss => [Platzhalter::Produkt],
                default => [Platzhalter::Produkt, Platzhalter::Name, Platzhalter::Minuten],
            },
            default => [],
        };
    }

    /**
     * Was das Produkt setzt und keine Vorlage aendert -- fuer die Oberflaeche.
     *
     * @return list<string>
     */
    public function festerKern(): array
    {
        if ($this->istTerminmail()) {
            $kern = ['Datum und Uhrzeit', 'Terminart und Behandler/in', 'Standort mit Anschrift'];

            return in_array($this, [self::Terminbestaetigung, self::Verschiebung, self::Absage], true)
                ? [...$kern, 'Kalenderdatei (.ics) im Anhang']
                : $kern;
        }

        return match ($this) {
            self::EmailBestaetigen => ['Schaltfläche mit dem Bestätigungslink', 'Gültigkeit des Links'],
            self::PasswortZuruecksetzen => ['Schaltfläche mit dem Link zum neuen Passwort', 'Gültigkeit des Links'],
            self::Einladung => ['Rolle im Team', 'Schaltfläche mit dem Einladungslink', 'Gültigkeit der Einladung'],
            self::Agentenalarm => ['Grund der Übergabe', 'Der Satz „Der Inhalt steht nicht in dieser E-Mail“', 'Schaltfläche zum Posteingang'],
            self::Anmeldecode, self::Einrichtungscode => ['Der Code', 'Gültigkeit des Codes', 'Der Rat, das Passwort zu ändern, wenn es nicht Sie waren'],
            default => [],
        };
    }
}
