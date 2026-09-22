# WP-13 · Erinnerungen & Bestätigungen

## Ziel
Niemand vergisst einen Termin, und niemand bekommt eine Erinnerung an einen
Termin, den es nicht mehr gibt.

## Vorher lesen
- `docs/entscheidungen.md` — **A13** schreibende externe Aufrufe über eine
  Queue mit Idempotenzschlüssel; **B7** Nutzungserfassung beim Versand
  kostenpflichtiger Nachrichten; **B8** WhatsApp-Kosten nicht unbegrenzt im
  Abo; **C7** Aufbewahrung
- `CLAUDE.md`, Regeln 3 und 4
- `specs/WP-11-terminverwaltung-intern.md`, Abschnitt „Verschieben ist
  dieselbe Zeile"

## Voraussetzungen
WP-11, WP-12

## Nur E-Mail, und das ist eine Entscheidung

WhatsApp und SMS wären die wirksameren Kanäle — und beide kosten Geld je
Nachricht (Entscheidungen B7 und B8) und setzen die Meta-Anbindung voraus
(WP-19, WP-20). Vor der Kostenerfassung Nachrichten zu verschicken, für die
Meta abrechnet, ist der kürzeste Weg zu einer Abo-Marge, die niemand
nachrechnen kann.

Der Kanal steht deshalb als Enum im Schema. WP-20 ergänzt einen Fall, nicht
eine Tabelle.

## Genau einmal — als Zusage der Datenbank

Eine doppelte Erinnerung ist ärgerlich, eine doppelte Absagebestätigung ist
peinlich, und eine Erinnerung, die nach einem Deploy zweimal läuft, ist der
Normalfall, nicht die Ausnahme.

Deshalb ist „genau einmal" hier kein Vorsatz im Code, sondern derselbe
Mechanismus wie in WP-10: **ein Unique-Index** über
(`appointment_id`, `kind`), und der Versand **beansprucht** seine Zeile über
ein bedingtes `UPDATE … WHERE sent_at IS NULL`. Nur wer die Zeile bekommt,
verschickt. Zwei gleichzeitige Läufe erzeugen genau eine Mail.

## Fünf Anlässe

| Art | Auslöser | Wann |
|---|---|---|
| `request_received` | Buchung über die öffentliche Seite | sofort |
| `confirmation` | Termin wird bestätigt oder direkt bestätigt angelegt | sofort |
| `reminder` | — | `starts_at` minus Vorlauf |
| `rescheduled` | Termin verschoben | sofort |
| `cancellation` | Termin abgesagt | sofort |

Der Vorlauf steht in `config/mrs.php` (`reminders.hours_before`, Standard 24)
und ist je Mandant überschreibbar.

## Die Betreffzeile nennt keine Behandlung

„Erinnerung: Erstberatung Botox morgen um 9 Uhr" steht als Vorschau auf einem
Sperrbildschirm, den auch andere sehen. Der Betreff nennt deshalb nur den Tag:
**„Ihr Termin am 17. September"**. Im Text steht die Behandlung — dort ist sie
nötig, und dort hat sie der Empfänger selbst gewählt.

Das ist dieselbe Überlegung wie R2 beim Kalendersync (neutraler Titel), nur
eine Ebene weiter außen.

## Was beim Ändern passiert

**Verschieben setzt die Erinnerung zurück.** Die offene Zeile wird gelöscht
und neu geplant. Ohne das erinnert das System an die alte Zeit — oder gar
nicht, weil die Zeile schon als verschickt gilt.

**Absagen löscht die offene Erinnerung** und verschickt eine Absage. Eine
Erinnerung an einen abgesagten Termin ist der peinlichste Fehler dieser
Gattung.

**Nichts wird in die Vergangenheit verschickt.** Stand der Job (Ausfall,
Deploy), ist eine überfällige Erinnerung wertlos: sie geht nur raus, solange
der Termin noch bevorsteht.

## Ein Termin ohne Kontaktweg ist kein Fehler — aber auch kein Erfolg

Termine vom Empfang haben nicht immer eine E-Mail-Adresse. Die Zeile entsteht
dann trotzdem und wird als **fehlgeschlagen** mit dem Grund `no_channel`
abgelegt — sichtbar in der Terminansicht. Sonst verschwindet „diese Person
bekommt keine Erinnerung" lautlos, und das fällt erst auf, wenn jemand nicht
erscheint.

## Schritte

1. `appointment_notifications`: Art, Kanal, geplanter Zeitpunkt, Versand,
   Fehlschlag. Unique über (`appointment_id`, `kind`).
2. `NotificationKind` und `NotificationChannel` als Enums (Entscheidung A11).
3. `Terminbenachrichtigungen` — plant, verschiebt, sagt ab.
4. `Terminnachricht` — eine Benachrichtigung, fünf Texte.
5. `TerminnachrichtVersenden` als Job auf der Queue `default` (Regel 4).
6. `mrs:erinnerungen-versenden`, alle fünf Minuten.
7. Anbindung an `Terminplaner`: buchen, einlösen, verschieben, absagen,
   bestätigen.
8. Sichtbar in der Terminansicht: was wurde verschickt, was steht aus.

## Abnahmekriterien

**Planen**

1. Eine Buchung über die öffentliche Seite plant Eingangsbestätigung und
   Erinnerung.
2. Eine Buchung vom Empfang plant Bestätigung und Erinnerung.
3. Die Erinnerung ist auf `starts_at` minus Vorlauf geplant.
4. Der Vorlauf ist je Mandant überschreibbar.
5. Ein Termin ohne E-Mail-Adresse erzeugt eine fehlgeschlagene Zeile mit
   Grund, keine stille Lücke.

**Genau einmal**

6. Zwei Läufe des Versandbefehls erzeugen eine Nachricht, nicht zwei.
7. Eine zweite Zeile derselben Art ist auf **Datenbankebene** ausgeschlossen.

**Ändern**

8. Verschieben plant die Erinnerung neu.
9. Verschieben verschickt eine Benachrichtigung.
10. Absagen löscht die offene Erinnerung.
11. Absagen verschickt eine Absage.
12. Bestätigen verschickt eine Bestätigung.

**Versand**

13. Der Befehl verschickt fällige Erinnerungen.
14. Der Befehl verschickt keine Erinnerung für einen Termin in der
    Vergangenheit.
15. Der Befehl verschickt keine Erinnerung für einen abgesagten Termin.
16. Der Befehl verschickt nichts vor dem geplanten Zeitpunkt.
17. Der Versand läuft über die Queue, nicht im Anfragezyklus.

**Inhalt**

18. Die Betreffzeile nennt keine Behandlung.
19. Der Text nennt Datum, Uhrzeit, Standort und Behandler.
20. Die Erinnerung nennt die Behandlung im Text.

**Mandantengrenze**

21. Der Versandbefehl verlässt nie die Organisation eines Termins.

## Nicht in diesem Paket

**WhatsApp und SMS** (WP-19, WP-20) samt Nutzungserfassung (B7).

**Absage durch den Kontakt.** Ein Link „Termin absagen" in der Erinnerung
wäre nützlich und braucht einen signierten, zeitlich begrenzten Zugang plus
die Frage, wie kurzfristig eine Absage noch erlaubt ist. Das ist ein eigener
Vorgang, kein Anhängsel an eine Mail.

**Kalendereinladung (.ics)** im Anhang — gehört zu WP-14, wo die
Kalenderlogik entsteht.

**Erinnerungen an das Praxisteam** (Tagesübersicht am Morgen). Sinnvoll,
aber eine andere Zielgruppe mit anderer Frequenz.

Aufbewahrung und Löschung der Nachrichtenzeilen (C7, WP-18).

## Fallstricke

- **„Genau einmal" ist kein Vorsatz, sondern ein Index.** Ein Job, der
  zweimal läuft, ist der Normalfall.
- **Eine Erinnerung an einen abgesagten Termin** ist schlimmer als keine
  Erinnerung.
- **Eine überfällige Erinnerung ist wertlos.** Nach einem Ausfall nicht
  nachholen.
- **Der Betreff steht auf dem Sperrbildschirm.** Keine Behandlung darin.
- **Verschieben ohne Neuplanung** erinnert an die alte Zeit.
- **Kein Kontaktweg ist eine Information**, keine Ausnahme, die man
  verschluckt.

## Stand

Alle 21 Abnahmekriterien sind als Tests umgesetzt und laufen:
`tests/Feature/Benachrichtigung/ErinnerungenTest.php`.

`php artisan mrs:erinnerungen-versenden` läuft alle fünf Minuten, stellt nur
ein und verschickt nichts selbst. Auf den Demodaten: 12 geplante
Bestätigungen, 12 geplante Erinnerungen, davon 6 fällig — Betreff „Ihr Termin
am 17. September", im Text Datum, Uhrzeit, Behandlung, Behandler und
Anschrift.

In der Terminansicht steht unter „Nachrichten", was verschickt wurde, was
aussteht und was fehlgeschlagen ist.

## Was das Bauen zutage gefördert hat

**Rohbytes in der Job-Nutzlast.** Der erste Wurf gab die binäre
`organization_id` an den Job weiter — `json_encode()` bricht daran mit
„Malformed UTF-8 characters", und die Meldung zeigt auf die Queue statt auf
die Ursache. Dieselbe Fehlerklasse wie in WP-04 (Inertia-Antwort) und WP-08
(Fremdschlüssel), nur eine Schicht weiter: `HasBinaryUuid` warnt im
Klassenkommentar ausdrücklich vor „einer Job-Nutzlast", und ich bin trotzdem
hineingelaufen. `RohbytesTest` deckt Modelle ab, nicht Jobs — der
Regressionstest sitzt jetzt im Paket.

**Die Mail kommt von der Praxis, nicht von uns.** Der Absendername ist der der
Praxis, und eine Antwort geht an die Standort-Adresse, wenn eine hinterlegt
ist. Wer auf „sagen Sie uns bitte rechtzeitig Bescheid" antwortet, will die
Praxis erreichen. Die Absenderdomain samt SPF und DKIM gehört zu WP-07; das
Mailgerüst trägt bis dahin noch unseren Namen.

## Offen

Das Mailgerüst (Kopf und Fuß der Nachricht) trägt `config('app.name')`. Für
die Buchungsseite ist das gelöst, für die Mail nicht — sie gehört mit zum
Whitelabel (WP-07).
