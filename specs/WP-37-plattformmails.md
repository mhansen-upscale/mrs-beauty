# WP-37 · Plattformmails und Versand des Betreibers

> Nachtrag zu WP-34a und WP-35. Die Mails an Konten — Adresse bestätigen,
> Passwort, Einladung, Alarm, Anmeldecode — tragen Laravels Standardrahmen
> mit „Mrs. Beauty" in Kopf und Fuß, ihre Texte stehen im Code, und der
> Mailserver kommt aus `.env`. Wer den Anbieter wechseln oder einen Satz
> ändern will, braucht ein Deployment.

## Ziel
Der Betreiber hinterlegt im Backoffice den Mailserver für die Produktmails,
prüft ihn mit einer Probemail und gestaltet Texte und Aussehen dieser Mails.
`.env` bleibt Rückfall.

## Vorher lesen
- `docs/entscheidungen.md`: **B23**, **A15**, **C17**, **D15** (dieses
  Paket), **B21**, **C5**, **C14**, **C16**
- `specs/WP-36-mails-der-praxis.md` — Register, Prüfung, Rahmen
- `specs/WP-34a-betreiberrollen-anmeldung.md`, Rollentabelle und
  Passwort vor jeder Handlung
- `specs/WP-35-zwei-faktor.md`, E-Mail-Code

## Voraussetzungen
WP-34a, WP-35, **WP-36**.

## Die Linie, an der alles hängt

**Ein Mailserver gilt erst, wenn eine Mail durchging.** Wer sich beim
Hostnamen vertippt, sperrt sonst jeden aus, der auf einen Anmeldecode oder
einen Passwortlink wartet. Deshalb:

- **Gespeichert ist nicht geprüft.** Bis die Probemail über genau diese
  Fassung der Zugangsdaten gelaufen ist, gilt `.env`. Jede Änderung an Server
  oder Zugangsdaten macht ihn wieder ungeprüft.
- **Scheitert der geprüfte Server später, geht die Mail über `.env`**, und
  die Störung steht im Backoffice.
- **Der Absender gehört zum Server.** Mit dem hinterlegten die hinterlegte
  Adresse, mit `.env` die aus `MAIL_FROM_*` — sonst scheitert SPF.
- **Das Passwort geht nie zurück**, an keine Oberfläche, in kein Protokoll.
- **Eine Änderung wirkt ab der nächsten Mail**, ohne Neustart der Arbeiter:
  gelesen wird je Versand.

Für die Texte gilt dieselbe Linie wie in WP-36: **Links, Codes, Fristen und
der Alarmsatz sind Kern, kein Text** (C17).

## Schritte

1. **Tabellen** `platform_mail_settings` (genau eine Zeile) und
   `platform_mail_templates` — global, ohne `organization_id`.
2. **Versand:** `Plattformversand` liest die Einstellung je Versand und baut
   den Mailer; `PlattformMailkanal` für jede `Plattformmail`, mit Rückfall
   auf `.env` bei einem Transportfehler.
3. **Mails:** `Plattformmails` baut Bestätigung, Passwort, Einladung, Alarm,
   Anmelde- und Einrichtungscode aus Vorlage, festem Block und Rahmen. Die
   Rückrufe in `configureMails()` bleiben und delegieren dorthin.
4. **Probe:** `PlattformversandPruefen` schickt nur über den hinterlegten
   Server und vermerkt die geprüfte Fassung.
5. **Fähigkeit** `versand.verwalten`, nur Super-Admin; jede Route in der
   Rollentabelle.
6. **Oberfläche:** *Backoffice → Versand* (Server, Absender, Aussehen,
   Probe) und *Backoffice → E-Mails* (Übersicht, Editor wie WP-36).
7. **Lage:** die Störung in `Betriebslage::fuerInstallation()` und
   `mrs:betrieb`.

## Abnahmekriterien

**Recht**

1. Nur wer `versand.verwalten` hat (Super-Admin), kommt an Versand und
   E-Mails; jede Route steht in `betreiberrouten()`.
2. Speichern und Zurücksetzen verlangen das eigene Passwort (C14).

**Texte**

3. Schaltflächen, Links und Fristen der Bestätigung, des Passworts und der
   Einladung stehen in jeder Vorlage.
4. **Der Code steht nie im Betreff**, und der Betreff der Code-Mails nimmt
   keinen Platzhalter an.
5. **Der Alarm trägt nie Inhalt**: kein Platzhalter dafür, und der Satz
   „Der Inhalt steht nicht in dieser E-Mail" bleibt.
6. Unbekannte Platzhalter und Markup werden abgelehnt wie in WP-36.
7. Eine Vorlage gilt für alle Praxen; zurücksetzen stellt den Standard her.
8. Eine gespeicherte Vorlage wirkt ab der nächsten Mail, ohne Neustart.

**Aussehen**

9. Logo, Farbe und Fußtext des Betreibers stehen in den Produktmails; kein
   „All rights reserved".
10. Das Logo nimmt nur PNG und JPEG, wird mit `nosniff` ausgeliefert, und
    die Farbe durchläuft die Prüfung aus WP-07.

**Server**

11. Benutzername und Passwort liegen mit dem App-Schlüssel verschlüsselt; ein
    leeres Passwortfeld lässt das gespeicherte stehen; das Passwort geht nie
    an die Oberfläche.
12. **Bis zur bestandenen Probe gilt `.env`.**
13. Jede Änderung an Server oder Zugangsdaten macht ihn wieder ungeprüft.
14. Ein Arbeiter wechselt den Server zwischen zwei Aufträgen ohne Neustart.
15. Scheitert der hinterlegte Server, geht die Mail über `.env`, und die
    Störung steht an der Einstellung.
16. Eine Terminmail geht nie über den Plattformserver, eine Produktmail nie
    über das Postfach einer Praxis (A15).
17. Passwort und Benutzername stehen in keinem Protokoll, keiner Antwort und
    keiner Nutzlast.

**Vorschau und Probe**

18. Die Vorschau verschickt nichts; die Probemail geht an die angemeldete
    Person.

**Protokoll**

19. Änderungen stehen im Betreiberprotokoll, ohne Organisation, mit
    Feldnamen — nie mit Werten.

**Warteschlange**

20. Die Vorlage wird zur Versandzeit gelesen, im Arbeiter ohne Mandant.

**Deutsch**

21. Ohne Vorlage sind die Texte die bisherigen; die Einladung grüßt mit
    „Viele Grüße".

## Stand

Stand 28.09.2026: **alle 21 Abnahmekriterien stehen**, in
`tests/Feature/Plattformmails/` (`PlattformversandTest`,
`PlattformvorlagenTest`, zusammen 27 Tests), AK 1 in
`Backoffice/BetreiberrollenTest` (elf neue Routen in der Tabelle, nur
Super-Admin), AK 16 in `Mailvorlagen/RegisterTest` (jede Notification hat
genau einen Weg, jeder Kanal weist den anderen ab), AK 20 in
`Benachrichtigung/WarteschlangeTest`.

Die Störung des Plattformservers steht in `Betriebslage::fuerInstallation()`,
in `mrs:betrieb` (Exit-Code 1) und auf dem Betreiber-Dashboard, dort auch die
Zahl der Praxen ohne Postfach (WP-36).

## Was das Bauen zutage gefördert hat

- **Das Passwort-Mail-Gerüst des Frameworks** (`notifications::email`) lässt
  sich nicht einfärben; alle Mails laufen jetzt durch `mail.rahmen` mit dem
  Theme `mrs`. Die Rückrufe in `configureMails()` bleiben, damit auch
  `new VerifyEmail` deutsch bleibt.
- **Der Absender gehört zum Server.** Ein hinterlegter From über den
  `.env`-Mailer wäre an SPF gescheitert — deshalb wechselt er mit dem Weg.

## Nicht in diesem Paket
- **API-Anbieter** (Postmark, SES, Resend) — jeder bietet SMTP.
- **Mehrere Server oder eine Rangfolge** jenseits von „hinterlegt, sonst
  `.env`".
- **Vorlagen für die Probemails.**

## Fallstricke
- **`Mail::fake()` fängt `Mail::build()` nicht** — Tests tauschen
  `plattform_smtp` (`plattformAttrappe()`).
- **Ein rotierter App-Schlüssel ohne `APP_PREVIOUS_KEYS`** macht das
  Passwort unlesbar; das ist eine Störung mit Rückfall, kein Absturz.
- **`MailManager` speichert benannte Mailer zwischen** — ein
  Plattformmailer über `Mail::mailer('plattform')` hielte im Arbeiter die
  alten Zugangsdaten.
