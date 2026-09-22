# WP-14 · Kalendersync Google

## Ziel
Der Kalender eines Behandlers und der Terminkalender des Produkts zeigen
dieselbe belegte Zeit — ohne dass einer von beiden erfährt, was im anderen
steht.

## Vorher lesen
- `docs/integrationen/kalender.md` — **R1** Eigenmarkierung, **R2**
  Datensparsamkeit in beide Richtungen, **R3** Konflikte, **R4** stille
  Ausfälle
- `docs/fachlogik/verfuegbarkeit.md`, Abschnitt „Externe Blocker" sowie die
  Testfälle **18, 19, 20** — 19 und 20 wurden in WP-10 ausdrücklich hierher
  verschoben
- `docs/entscheidungen.md` — **B4** `privacy_mode = busy_only` als Standard,
  **B5** ausgehende Events eigenmarkieren, **B6** Konfliktregel, **A13**
  schreibende externe Aufrufe über Queue mit Idempotenzschlüssel, **A14**
  Webhooks
- `CLAUDE.md`, Regeln 3 und 4

## Voraussetzungen
WP-10, WP-11, WP-13

## Die eine Regel, an der Kalenderintegrationen scheitern

Ein Termin wird nach Google geschrieben. Google meldet ihn als Event zurück.
Das System liest ihn als externen Blocker und blockiert damit den Slot, auf
dem der Termin liegt. Beim nächsten Lauf ist der Slot belegt, der Termin
steht darüber, und niemand versteht, warum der Kalender dichtmacht.

Deshalb ist die **Eigenmarkierung** (R1, B5) kein Detail, sondern das Erste,
was gebaut wird: jedes ausgehende Event trägt in
`extendedProperties.private` die Organisation und den Termin. Beim Rücksync
wird ein so markiertes Event übersprungen — nicht „meistens", sondern als
erste Prüfung, vor allem anderen.

`docs/integrationen/kalender.md` nennt das „den häufigsten Fehler bei
Kalenderintegrationen". Der zugehörige Testfall (19) steht seit WP-10 offen.

## Was ein Kalender über eine Praxis verrät

Der Kalender eines Arztes liegt oft auf dem Privathandy und ist manchmal mit
dem Team geteilt. Ein Event „Frau Berger — Lippenaufbau, 14:00" auf einem
Sperrbildschirm ist ein Gesundheitsdatum in der Öffentlichkeit.

Beide Richtungen sind deshalb sparsam, und zwar strukturell:

**Ausgehend** trägt ein Event den Titel aus
`config('mrs.calendar.outgoing_event_title')` — „Beratung". Kein Kontaktname,
keine Behandlung, kein Hinweis auf die Terminart.

**Eingehend** wird ausschließlich der Zeitraum übernommen. Die Tabelle
`external_calendar_blocks` hat **keine Titelspalte**, und das ist die
Umsetzung von R2: was es nicht gibt, kann niemand später „nur zur Anzeige"
befüllen.

### `privacy_mode` überstimmt Regel 3 nicht

Entscheidung B4 setzt `busy_only` als Standard und impliziert damit einen
zweiten Modus. Regel 3 ist aber nicht verhandelbar: ausgehende Einträge
tragen keinen Kontaktnamen und keine Behandlung — Punkt.

`details` fügt deshalb **keinen Inhalt hinzu, sondern einen Weg zum Inhalt**:
die Beschreibung des Events enthält einen Link in die Terminansicht. Wer
wissen will, wer kommt, meldet sich an. Der Kalender bleibt neutral, auch
wenn das Handy auf dem Tresen liegt.

## Wer gewinnt

R3 und B6 sind eindeutig, und die Umsetzung hängt an der Spaltentrennung aus
WP-10:

| | Quelle | gewinnt |
|---|---|---|
| Blocker | externer Kalender | `external_block_id` wird gesetzt |
| Termin | System | `appointment_id` bleibt, **kein** Blocker darüber |

Ein externer Blocker über einem bestehenden Termin sagt nichts aus, was das
System auflösen könnte — er entfernt den Termin nicht und er verschiebt ihn
nicht. Die Zeile bleibt, wie sie ist. Das Gleiche gilt umgekehrt: ein extern
gelöschtes ausgehendes Event löscht keinen Termin, sondern wird beim nächsten
Ausgangslauf neu geschrieben (Testfall 20).

**Ein gehaltener Slot bekommt keinen Blocker.** Ein Hold ist eine Buchung im
Entstehen; beide Spalten gleichzeitig zu setzen bräche die Zusage der
Tabelle, dass genau eine der drei Spalten gefüllt ist. Der Blocker greift,
sobald der Hold abgelaufen ist — das dauert höchstens zehn Minuten.

## Deltas, und was passiert, wenn sie verfallen

Google liefert Änderungen über ein `syncToken`. Läuft es ab, antwortet die
API mit **`410 Gone`** — und das ist kein Fehler, sondern ein vorgesehener
Zustand: das Token wird verworfen und ein Vollabgleich über das
Buchungsfenster läuft an. Wer `410` als Ausfall behandelt, hat einen Sync,
der nach ein paar Wochen Ruhe stillsteht.

Drei Fälle, die eigene Tests haben, weil sie sich nicht aus dem Normalfall
ergeben:

- **Ganztägige Events** kommen als `date` statt `dateTime`, ohne Zone. Sie
  werden in der Zeitzone **des Kalenders** ausgewertet, die beim Verbinden
  einmal abgefragt und gespeichert wird. Zeitzonen werden nicht angenommen.
- **Abgesagte Events** kommen mit `status: cancelled` und ohne Zeiten. Ihr
  Blocker verschwindet.
- **Als „frei" markierte Events** (`transparency: transparent`) sind keine
  Blocker. Wer sich einen Geburtstag einträgt, ist trotzdem in der Praxis.

## Stille Ausfälle (R4)

Ein Abonnement läuft nach 30 Tagen ab, ein Refresh-Token wird im
Google-Konto entzogen, ein Nutzer entfernt den Kalender. Nichts davon
erzeugt eine Fehlermeldung — es hört einfach auf.

Ein Sync, der still steht, bedeutet: **Termine werden über belegte Zeiten
gebucht.** Deshalb

- erneuert ein wiederkehrender Job die Abonnements `renew_before_expiry_hours`
  **vor** Ablauf, nicht kurz davor,
- läuft zusätzlich ein nächtlicher Vollabgleich als Netz unter dem Webhook,
- wechselt eine Verbindung bei entzogenem Zugang auf `expired`,
- und steht dieser Zustand **im Produkt**: auf der Seite „Kalender" und als
  Warnung in der Navigation. Nicht nur im Log.

## Der Webhook quittiert, bevor er denkt

A14: Signatur prüfen, sofort quittieren, asynchron verarbeiten, über die
externe ID deduplizieren. Google erwartet innerhalb weniger Sekunden eine
Antwort und wiederholt sonst — eine Zustellung, die einen vollen Abgleich
abwartet, erzeugt genau die Last, die sie melden soll.

Der Kanal trägt ein von uns erzeugtes Token. Es wird bei jeder Zustellung
zeitkonstant verglichen; ohne Treffer passiert nichts. Die Kanalkennung ist
mandantenübergreifend eindeutig — der Webhook kommt ohne Anmeldung an und
muss den Mandanten erst finden. Er tut das über `acrossTenants()` mit
Begründung und arbeitet danach ausschließlich im gefundenen Mandanten.

## Schritte

1. `calendar_connections`, `external_calendar_blocks`, `calendar_event_links`.
2. `CalendarProvider`, `CalendarConnectionStatus`, `CalendarPrivacyMode`.
3. `Eigenmarkierung` — R1, in beide Richtungen.
4. `GoogleZugang` (OAuth, Token-Erneuerung) und `GoogleKalender` (Events,
   Watch-Kanäle).
5. `Rueckabgleich` — Delta, Vollabgleich, Eventklassen.
6. `Blockerabgleich` — Blocker auf Slots, R3.
7. `Terminkalender` — die ausgehende Seite, angebunden an `Terminplaner`.
8. Jobs auf der Queue `sync`: schreiben, entfernen, rückabgleichen, erneuern.
9. Webhook und OAuth-Rückkehr.
10. `mrs:kalender-abos-erneuern` stündlich, `mrs:kalender-abgleichen` nachts.
11. Seite „Kalender" mit Status je Behandler, Warnung in der Navigation.
12. `.ics` an Bestätigung, Verschiebung und Absage (aus WP-13 hierher).

## Abnahmekriterien

**Verbindung**

1. Der Rückkehrweg legt eine Verbindung an; Zugang und Aktualisierungs-
   schlüssel liegen verschlüsselt.
2. Ein manipulierter `state` verbindet nichts.
3. Ein Behandler hat je Anbieter höchstens eine Verbindung.
4. Trennen beendet das Abonnement, entfernt die Blocker und gibt die Slots
   frei.

**Eigenmarkierung (R1, B5)**

5. Ein ausgehendes Event trägt Organisation und Termin in
   `extendedProperties.private`.
6. **Ein eigenmarkiertes Event erzeugt keinen Blocker** (Testfall 19).
7. Ein fremdes Event erzeugt einen Blocker (Testfall 18).

**Datensparsamkeit (R2, Regel 3)**

8. Der Titel des ausgehenden Events nennt weder Kontakt noch Behandlung.
9. Der Originaltitel eines eingehenden Events steht in **keiner** Spalte
   irgendeiner Tabelle.
10. `privacy_mode = details` fügt einen Link hinzu, keinen Inhalt.

**Konflikte (R3, B6)**

11. Ein externer Blocker macht eine freie Zeit unbuchbar.
12. Ein externer Blocker über einem Termin lässt den Termin unberührt.
13. **Ein extern gelöschtes Event lässt den Termin bestehen** (Testfall 20)
    und wird neu geschrieben.
14. Ein gehaltener Slot bekommt keinen Blocker.

**Deltas**

15. Ein Delta-Lauf verarbeitet nur die gelieferten Änderungen und merkt sich
    das neue Token.
16. `410 Gone` löst einen Vollabgleich aus und ist kein Fehler.
17. Ein extern abgesagtes Event entfernt seinen Blocker.
18. Ein ganztägiges Event wird in der Zeitzone des Kalenders ausgewertet.
19. Ein Event mit eigener Zeitzone wird nicht in der Serverzeitzone gelesen.
20. Ein als „frei" markiertes Event erzeugt keinen Blocker.

**Webhook (A14)**

21. Der Webhook quittiert sofort und verarbeitet asynchron.
22. Ein falsches Kanal-Token löst nichts aus.
23. Dieselbe Nachricht zweimal erzeugt einen Lauf, nicht zwei.
24. Der Zustand `sync` löst keinen Abgleich aus.
25. Der Webhook findet die Verbindung mandantenübergreifend und arbeitet im
    Mandanten der Verbindung.

**Ausgehend (Regel 4, A13)**

26. Buchen schreibt nichts im Anfragezyklus.
27. Zweimal derselbe Auftrag erzeugt ein Event, nicht zwei.
28. Verschieben aktualisiert dasselbe Event.
29. Absagen entfernt das Event.

**Ausfall (R4)**

30. Ein Abonnement wird vor Ablauf erneuert.
31. Ein entzogener Zugang setzt die Verbindung auf `expired`.
32. Der Ausfall ist im Produkt sichtbar.

**Einladung**

33. Bestätigung, Verschiebung und Absage tragen eine Kalenderdatei.
34. Der Titel der Kalenderdatei nennt keine Behandlung.

## Nicht in diesem Paket

**Microsoft Graph** (WP-15). `docs/integrationen/kalender.md` ist an dieser
Stelle ausdrücklich: *„Erst beide umsetzen, dann abstrahieren."* Es entsteht
hier deshalb **kein** gemeinsames Interface, kein `CalendarProvider`-Contract
und keine Fabrik. Die Google-Klassen heißen Google und liegen unter
`App\Kalender\Google`. WP-15 baut daneben, WP-15 räumt danach zusammen.

**Wiederkehrende Termine mit abweichenden Einzelinstanzen.** Die Abfrage läuft
mit `singleEvents=true`, Google löst die Serie also selbst auf. Die eigene
Testklasse, die `docs/integrationen/kalender.md` dafür verlangt, gehört zu
WP-15, wenn beide Anbieter dieselbe Auflösung liefern müssen.

**Schreiben in fremde Kalender außer dem verbundenen** — ein Behandler, ein
Kalender.

**Ein Behandler mit beiden Anbietern gleichzeitig.** Das Schema lässt es zu
(eindeutig ist `(practitioner_id, provider)`), der Test dafür braucht WP-15.

## Fallstricke

- **Ohne Eigenmarkierung blockiert das System seine eigenen Termine.** Erste
  Prüfung im Rücksync, nicht die letzte.
- **`410 Gone` ist kein Fehler.** Wer es als solchen behandelt, hat einen
  Sync, der irgendwann stehenbleibt.
- **Zeitzonen niemals annehmen.** Ganztägig heißt „ohne Zone", nicht „UTC".
- **Ein stiller Ausfall ist der Normalfall**, kein Sonderfall.
- **Rohbytes gehören in keine Job-Nutzlast** (WP-13) und in keine
  `state`-Angabe einer Weiterleitung.
- **Der Webhook kommt ohne Mandanten an.** Er darf ihn suchen, aber nicht
  ohne Begründung im Protokoll.

## Stand

Alle 34 Abnahmekriterien sind als Tests umgesetzt und laufen:
`tests/Feature/Kalender/` (51 Tests) — darunter die Testfälle **19** und **20**
aus `docs/fachlogik/verfuegbarkeit.md`, die WP-10 hierher verschoben hat.

Gegenstelle ist `Tests\Feature\Kalender\Googleattrappe`: kein Mitschnitt und
keine feste Antwortliste, sondern eine kleine Gegenstelle, die sich merkt, was
geschrieben wurde, und sich in die Zustände versetzen lässt, auf die es
ankommt — verfallenes Delta-Token, entzogener Zugang, extern gelöschtes Event.

`mrs:kalender-abos-erneuern` läuft stündlich, `mrs:kalender-abgleichen`
nachts um 03:45 — nach der Slot-Erzeugung, damit die Blocker in Zeilen laufen,
die es schon gibt. Beide stellen nur ein; gearbeitet wird auf der Queue `sync`.

Die Seite „Kalender" steht in der Hauptnavigation unter *Praxis*. Eine
unterbrochene Verbindung erzeugt dort ein Warnband und daneben ein Zeichen am
Menüpunkt — die Warnung steht in **jeder** Antwort (`calendar_alert`), nicht
nur auf ihrer eigenen Seite.

## Was das Bauen zutage gefördert hat

**`Http::retry()` hätte den Sync stillgelegt.** Ohne `when`-Rückruf wiederholt
Laravel jede nicht erfolgreiche Antwort — auch `410 Gone`, das keine Störung
ist, sondern die Aussage „dein Delta-Token gilt nicht mehr". Der zweite
Versuch kam durch, die Ausnahme entstand nie, der Vollabgleich blieb aus. Der
Test, der auf **zwei** Abrufe besteht, hat es gefunden; ohne ihn hätte das
Paket eine Integration abgeliefert, die nach ein paar Wochen Ruhe stillsteht
und dabei nichts meldet. Genau die Fehlerklasse, vor der R4 warnt.

**Aufträge aus einer Transaktion brauchen `afterCommit()`.** Die
Queue-Verbindungen stehen projektweit auf `after_commit = false`, und der
`Terminplaner` stellt seine Aufträge innerhalb seiner Transaktion ein. Ein
Arbeiter, der schneller ist als der Commit, findet den Termin nicht und tut
lautlos nichts. Betrifft **auch** `TerminnachrichtVersenden` aus WP-13 — dort
nachgetragen, weil es dieselbe Zeile und derselbe Fehler ist.

**Die Eigenmarkierung muss die Organisation tragen.** Der erste Wurf prüfte nur
auf den Schlüssel. Ein Behandler, der für zwei Praxen arbeitet und denselben
Kalender verbindet, hätte damit die Termine der einen Praxis in der anderen
als „unser eigenes Event" übersprungen — und genau dort doppelt gebucht.

**Routenbindungen liefen seit WP-08 ohne Mandanten** — gefunden, als der
Verbinden-Link auf `/kalender` mit `TenantContextMissing` abbrach. Laravel
sortiert die Middleware einer Route nach einer Prioritätsliste, in der
`SubstituteBindings` steht; `ResolveTenant` steht nicht darin und lief
deshalb **danach**, egal wie `bootstrap/app.php` es notiert. Jede Route mit
gebundenem Mandantenmodell war betroffen, nicht nur die des Kalenders.

Die bestehenden Tests konnten das nicht sehen: `alsMandant()` setzt das
Singleton für den ganzen Testlauf, der Mandant stand dort also schon vor der
Anfrage. `tests/Feature/Tenancy/RoutenbindungTest.php` ruft deshalb
ausdrücklich `ohneMandant()` — und prüft zusätzlich die Reihenfolge selbst,
über alle Routen. Ohne den Fix fallen alle vier Tests.

**Der Rückkehrpfad stand längst fest.** `.env` führt seit WP-02
`GOOGLE_REDIRECT_URI="${APP_URL}/oauth/google/callback"` (und die Microsoft-
Entsprechung). Die Route heißt jetzt so, auch wenn sie damit aus der Reihe der
übrigen Kalenderrouten fällt: sie ist in der Google Cloud Console hinterlegt.

**Deduplizierung und `ShouldBeUnique` überlagern sich.** Der erste Test
verlangte, dass eine Zustellung mit anderer Nachrichtennummer einen zweiten
Lauf erzeugt. Das tut sie nicht — und das ist richtig so: zwei Zustellungen
innerhalb von zehn Minuten haben dieselbe Arbeit. Der Cache-Schutz sitzt vor
der Queue, der eindeutige Auftrag dahinter. Beide Ebenen stehen jetzt als
eigene Tests da, statt als ein Test, der die falsche Erwartung geprüft hätte.

**`migrate:fresh --env=testing` traf die Entwicklungsdatenbank.** Die
Datenbanknamen für Tests stehen in `phpunit.xml`, nicht in einer
`.env.testing` — `--env=testing` liest sie also nicht. Die Demodaten sind neu
eingespielt; die Testdatenbank heißt `mrs_beauty_test` und wird von Pest
selbst migriert.

## Offen

**Microsoft Graph (WP-15)** und **erst danach** die gemeinsame Abstraktion.

**Wiederkehrende Termine mit abweichenden Einzelinstanzen** laufen heute über
`singleEvents=true` — Google löst die Serie selbst auf. Die eigene Testklasse,
die `docs/integrationen/kalender.md` verlangt, gehört zu WP-15.

**Der Webhook protokolliert jede Zustellung.** Er findet seinen Mandanten über
`acrossTenants()`, und das schreibt einen Protokolleintrag — bei einer Praxis
mit regem Kalender mehrere am Tag. Das ist die richtige Richtung (der Weg an
der Mandantentrennung vorbei steht im Protokoll), aber die Menge gehört
beobachtet. Wenn sie stört, bekommt dieser eine Zugriff ein eigenes Ereignis
statt `cross_tenant_access`.

**Das Mailgerüst** trägt weiterhin `config('app.name')` statt der Praxis
(WP-07). Die Kalenderdatei im Anhang nennt die Praxis korrekt, Kopf und Fuß
der Mail noch nicht.
