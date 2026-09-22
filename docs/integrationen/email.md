# Integration: E-Mail

Gilt für WP-20b und WP-21. Der einzige Kanal ohne Meta.

## Zwei Adressen

| | |
|---|---|
| `channel_connections.external_id` | die **Eingangsadresse bei uns**, z. B. `demo-praxis@inbound.mrs-beauty.de` |
| `channel_connections.sender_id` | die **Adresse der Praxis**, unter der geantwortet wird |

Eine eingehende Mail findet ihren Mandanten über die Eingangsadresse und nur
darüber — sie kommt ohne Anmeldung an, wie eine Meta-Zustellung auch.

Ausgehend ist `From` die Praxis und `Reply-To` unsere Eingangsadresse. Ohne
das landet die Antwort im Postfach der Praxis statt in der Inbox, und die
Hälfte des Gesprächs fehlt.

## Wie die Post hereinkommt

Die Praxis richtet in ihrem eigenen Postfach eine **Weiterleitung** auf die
Eingangsadresse ein. Kein MX-Wechsel, keine Zugangsdaten bei uns — beides
wäre eine Hürde, an der eine Einführung scheitert, und Postfachpasswörter
eines Kunden zu speichern widerspricht demselben Gedanken wie das
Systembenutzer-Token bei Meta.

Der Eingangsdienst legt die Mail per `POST /webhooks/mail` ab:

- **Rumpf:** die Mail selbst, RFC 5322, kein Anbieter-JSON.
- **Ausweis:** Kopfzeile `X-Mrs-Token`, zeitkonstant verglichen gegen
  `MAIL_INBOUND_TOKEN`. Ist das Geheimnis leer, kommt niemand durch — eine
  nicht eingerichtete Umgebung ist keine offene Tür.
- **Antwort:** 200, sobald die Mail liegt. Verarbeitet wird auf der Queue
  `realtime`.

**Delivered-To vor To.** Bei einer Weiterleitung steht in `To` die
ursprüngliche Adresse der Praxis, nicht unsere. Wer nur `To` liest, findet
den Mandanten nicht.

## Der Faden

Dedupliziert wird über die **Message-ID**, gespeichert ohne spitze Klammern —
eine Form, sonst zeigen dieselbe Mail zweimal zwei Kennungen.

Jede ausgehende Mail trägt eine eigene Message-ID im Namensraum der
Eingangsadresse und ein `In-Reply-To` auf die letzte eingehende. Ohne das
erscheint jede Antwort beim Empfänger als neue Mail: wer dreimal schreibt,
hat drei Gespräche im Postfach und wir eines.

Der Betreff ist der des Gesprächs mit vorangestelltem `Re:`, ohne es zu
verdoppeln. Eine Mail ohne Betreff landet in manchen Postfächern im Spam.

## Was aufgehoben wird

Absender, Anzeigename, Betreff, Text, Message-ID, Dateien. **Sonst nichts**
(Regel 3): eine Mail trägt zwanzig Kopfzeilen und die Wegmarken jedes Relays,
und nichts davon braucht eine Terminvereinbarung. Der Rest bleibt im
Rohereignis und ist nach 14 Tagen weg.

**Der Text, nicht das HTML.** Was ankommt, wird nirgends gerendert — ein
Mailrumpf ist die älteste Stelle für eingebettete Skripte und externe Bilder.
Gibt es nur eine HTML-Fassung, wird sie in Text verwandelt.

Betreff und Inhalt liegen verschlüsselt. „Frage zu meiner Unterspritzung" ist
ein gewöhnlicher Betreff — und ein Gesundheitsdatum.

**Anhänge** gehen über den Anhangspeicher aus WP-18: Virenprüfung und
Pflicht-Ablaufdatum für Chat-Anhänge (Entscheidung C6). Eingebettete Bilder
(`inline`) bleiben draußen — Signaturlogos würden den Speicher füllen, ohne
dass sie je jemand ansieht. Was größer ist als
`mrs.channels.email.max_attachment_bytes`, bleibt im Rohereignis.

## Was es hier nicht gibt

**Kein Service-Fenster.** Das ist ein Begriff von Meta. Eine Mail darf
jederzeit beantwortet werden; `service_window_expires_at` bleibt bei diesem
Kanal leer.

**Keine Kosten je Nachricht.** `cost_category` ist `none` — und das ist
ausnahmsweise keine Schätzung, sondern eine Aussage.

**Keine Statusrückmeldungen.** Eine Mail meldet nicht, dass sie gelesen
wurde. Ein Unzustellbarkeitsbericht kommt als neue Mail und ist eine.

**Kein Opt-in nach Metas Regeln.** Was für Werbung per Mail gilt, steht im
UWG und nicht bei Meta; die Einwilligungen aus WP-18 bilden es ab.

## Zustellbarkeit: zwei Wege

Wir senden im Namen der Praxis. Ob das ankommt, hängt daran, wer die Mail
tatsächlich verschickt.

**Eigenes Postfach** (empfohlen). Die Praxis hinterlegt unter *Einstellungen
→ Postfach* Server, Port, Verschlüsselung, Benutzername und Passwort. Die
Antwort geht dann denselben Weg wie jede andere Mail dieser Praxis — SPF und
DKIM stimmen von selbst, ohne dass jemand DNS anfassen muss. Zugangsdaten
liegen verschlüsselt an der Kanalverbindung (Regel 3); empfohlen wird ein
**App-Passwort** des Anbieters, kein Hauptpasswort.

**Versand über die Plattform** (Rückfall). Ohne hinterlegte Zugangsdaten
verschicken wir, mit der Adresse der Praxis im Absender. Das besteht die
Prüfungen empfangender Postfächer nur, wenn `SPF` und `DKIM` der Domain das
zulassen — **Betriebsarbeit je Kunde**. Der Rückfall ist trotzdem kein
Notbehelf: er hält eine Praxis arbeitsfähig, die gerade erst anfängt.

`ChannelConnection::hatEigenesPostfach()` entscheidet, `App\Kanaele\Email\
Postfach` baut den Mailer je Verbindung. Ein benannter Mailer in der
Konfiguration käme nicht in Frage: die Zugangsdaten gehören einem Mandanten,
nicht einer Datei, die alle teilen.

## Die Probemail

*Einstellungen → Postfach → Probemail senden* reiht `PostfachPruefen` ein —
**auf der Queue** (Regel 4): ein Mailserver, der nicht antwortet, würde sonst
die Einstellungsseite hängen lassen. Das Ergebnis steht danach an der
Verbindung: `verified_at`, oder `status = expired` mit `last_error =
smtp_failed`.

**Ohne Klartext des Servers.** Eine SMTP-Fehlermeldung trägt regelmäßig die
Adresse des Empfängers mit sich.

Ein Postfach, das nie geprüft wurde, sähe im Produkt sonst genauso aus wie
eines, das nicht funktioniert — und das ist der Unterschied, auf den es
ankommt.
