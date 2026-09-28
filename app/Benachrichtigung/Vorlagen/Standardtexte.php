<?php

declare(strict_types=1);

namespace App\Benachrichtigung\Vorlagen;

use App\Enums\Mailart;

/**
 * Die Texte, die gelten, solange niemand etwas anderes will (Entscheidung
 * D15).
 *
 * **Produkttext, deshalb im Code** (docs/konventionen.md): Die Datenbank haelt
 * nur Ueberschreibungen. Eine Verbesserung hier erreicht jede Praxis, die
 * ihre Vorlage nicht angefasst hat.
 *
 * **Woertlich die bisherigen.** Bis zum 28.09.2026 standen diese Saetze in
 * Terminnachricht, AppServiceProvider::configureMails(), TeamInvitation,
 * Agentenalarm und Anmeldecode. Einzige Aenderung: die Einladung gruesst mit
 * Umlaut.
 */
final class Standardtexte
{
    public static function fuer(Mailart $art): Mailtext
    {
        return match ($art) {
            Mailart::Eingangsbestaetigung => new Mailtext(
                betreff: 'Ihr Termin am {tag}',
                anrede: 'Guten Tag,',
                einleitung: "vielen Dank für Ihre Anfrage. Wir haben sie erhalten und melden uns, sobald der Termin bestätigt ist.\n\nIhre Anfrage:",
                schluss: '',
                gruss: 'Viele Grüße, {praxis}',
            ),
            Mailart::Terminbestaetigung => new Mailtext(
                betreff: 'Ihr Termin am {tag}',
                anrede: 'Guten Tag,',
                einleitung: 'Ihr Termin ist bestätigt.',
                schluss: 'Sollten Sie den Termin nicht wahrnehmen können, sagen Sie uns bitte rechtzeitig Bescheid.',
                gruss: 'Viele Grüße, {praxis}',
            ),
            Mailart::Erinnerung => new Mailtext(
                betreff: 'Ihr Termin am {tag}',
                anrede: 'Guten Tag,',
                einleitung: 'wir möchten Sie an Ihren Termin erinnern.',
                schluss: 'Sollten Sie den Termin nicht wahrnehmen können, sagen Sie uns bitte rechtzeitig Bescheid.',
                gruss: 'Viele Grüße, {praxis}',
            ),
            Mailart::Verschiebung => new Mailtext(
                betreff: 'Neuer Termin am {tag}',
                anrede: 'Guten Tag,',
                einleitung: 'Ihr Termin wurde verschoben. Er findet jetzt zu dieser Zeit statt:',
                schluss: 'Sollten Sie den Termin nicht wahrnehmen können, sagen Sie uns bitte rechtzeitig Bescheid.',
                gruss: 'Viele Grüße, {praxis}',
            ),
            Mailart::Absage => new Mailtext(
                betreff: 'Ihr Termin am {tag} entfällt',
                anrede: 'Guten Tag,',
                einleitung: 'Ihr Termin wurde abgesagt. Es handelt sich um diesen Termin:',
                schluss: 'Wenn Sie einen neuen Termin möchten, melden Sie sich gerne bei uns.',
                gruss: 'Viele Grüße, {praxis}',
            ),
            Mailart::EmailBestaetigen => new Mailtext(
                betreff: 'E-Mail-Adresse bestätigen',
                anrede: 'Willkommen bei {produkt}.',
                einleitung: 'Bitte bestätigen Sie Ihre E-Mail-Adresse, dann ist Ihr Zugang vollständig.',
                schluss: 'Wenn Sie keinen Zugang angelegt haben, können Sie diese Nachricht ignorieren.',
                gruss: 'Viele Grüße von {produkt}',
            ),
            Mailart::PasswortZuruecksetzen => new Mailtext(
                betreff: 'Passwort zurücksetzen',
                anrede: 'Guten Tag!',
                einleitung: 'Sie erhalten diese Nachricht, weil für Ihren Zugang ein neues Passwort angefordert wurde.',
                schluss: 'Haben Sie das nicht angefordert, ist nichts zu tun — Ihr Passwort bleibt unverändert.',
                gruss: 'Viele Grüße von {produkt}',
            ),
            Mailart::Einladung => new Mailtext(
                betreff: 'Einladung zu {praxis}',
                anrede: 'Hallo,',
                einleitung: 'Sie wurden zu {praxis} eingeladen.',
                schluss: '',
                gruss: 'Viele Grüße',
            ),
            Mailart::Agentenalarm => new Mailtext(
                betreff: 'Bitte im Posteingang nachsehen',
                anrede: 'Es liegt eine Nachricht vor, die jemand lesen sollte.',
                einleitung: '',
                schluss: 'Der Assistent hält sich aus diesem Gespräch heraus, bis jemand ihn wieder hereinlässt.',
                gruss: 'Viele Grüße von {praxis}',
            ),
            Mailart::Anmeldecode => new Mailtext(
                betreff: 'Ihr Anmeldecode',
                anrede: 'Guten Tag,',
                einleitung: 'mit diesem Code schließen Sie Ihre Anmeldung ab:',
                schluss: '',
                gruss: 'Viele Grüße von {produkt}',
            ),
            Mailart::Einrichtungscode => new Mailtext(
                betreff: 'Code zur Einrichtung des zweiten Faktors',
                anrede: 'Guten Tag,',
                einleitung: 'mit diesem Code schalten Sie den zweiten Faktor per E-Mail ein:',
                schluss: '',
                gruss: 'Viele Grüße von {produkt}',
            ),

            // Feste Mails: ihr Text steht dort, wo sie entstehen.
            Mailart::Posteingangsantwort,
            Mailart::Wartelistenangebot,
            Mailart::Postfachprobe,
            Mailart::Versandprobe => new Mailtext(betreff: $art->label()),
        };
    }
}
