# WP-10 · Verfügbarkeits-Engine

## Ziel
Zwei gleichzeitige Buchungsversuche vergeben niemals denselben Slot — und die
Zeitumstellung verschiebt keinen einzigen Termin.

## Vorher lesen
- **`docs/fachlogik/verfuegbarkeit.md`, vollständig.** Das ist die verbindliche
  Spezifikation dieses Pakets, einschließlich der 22 Testfälle.
- `docs/entscheidungen.md` — **A9** Doppelbuchungsschutz über
  `appointment_slots`, 5-Minuten-Raster, Puffer eingeschlossen; **A10**
  bedingte Eindeutigkeit; **A7**, **A8** Zeit
- `docs/datenmodell.md`, Abschnitt 0.2

## Voraussetzungen
WP-08, WP-09

## Was schon da ist

Sieben der elf Bedingungen liegen als fertige Abfragen vor:

| | Woher |
|---|---|
| V1, V2, V3 | `Practitioner::arbeitetAm()` (WP-08) |
| V7, V8 | `AppointmentType::wirdAngebotenVon()` (WP-09) |
| V9 | `AppointmentType::istBuchbarAm()` (WP-09) |
| V11 | `AppointmentType::belegteDauer()` (WP-09) |

Hier entstehen **V4, V5, V6 und V10** — externe Blocker, Belegung durch Termin,
Belegung durch gültigen Hold, Buchungshorizont. Alle vier beruhen auf den
Slot-Zeilen selbst.

## Die Richtung der Zeitrechnung ist die Entscheidung

WP-08 hält fest: UTC nach Ortszeit ist immer eindeutig, Ortszeit nach UTC
nicht. Die naheliegende Bauweise — „nimm montags 9 Uhr und rechne es in UTC
um" — läuft genau in die Zeitumstellung.

**Deshalb rechnet die Erzeugung andersherum.** Sie schreitet den Tag in
UTC ab und fragt bei jedem Schritt: *Welche Ortszeit ist das, und liegt die in
einem Arbeitszeitfenster?*

Beide Umstellungsfälle lösen sich damit von selbst:

| Fall | Was passiert |
|---|---|
| Sommerzeitlücke | Kein UTC-Zeitpunkt bildet auf die fehlende Stunde ab, also entstehen dort keine Slots. |
| Doppelte Winterzeitstunde | Zwei UTC-Zeitpunkte bilden auf dieselbe Ortszeit ab, beide erzeugen einen Slot. Beide sind gültige Arbeitszeit. |

Kein Sonderfall, keine Ausnahmebehandlung. Das ist der Grund, diese Richtung
zu wählen.

## Schritte

1. `appointment_slots`: eine Zeile je Behandler und 5-Minuten-Schritt.
   Belegung über `appointment_id`, `slot_hold_id` oder `external_block_id`.
2. **Unique-Index `(practitioner_id, starts_at)`** — Doppelvergabe wird zum
   Datenbankfehler statt zum Fachfehler.
3. `appointments` im Mindestumfang, damit der Fremdschlüssel steht. **WP-11
   besitzt diese Tabelle**, hier entsteht nur, was der Slot braucht.
4. `slot_holds` mit Ablaufzeit und Zweck.
5. Erzeugungsjob: schreitet UTC ab, prüft Ortszeit gegen die Fenster,
   **idempotent**.
6. Abfrage freier Startzeiten: zusammenhängende freie Strecke über die volle
   belegte Dauer, dann V2, V3, V9, V10.
7. Hold anlegen, freigeben, in einen Termin umwandeln — **dieselben Zeilen**,
   in einer Transaktion.
8. Sperrung über `SELECT … FOR UPDATE` in aufsteigender `starts_at`-Reihenfolge.

## Abnahmekriterien

**Die 22 Testfälle aus `docs/fachlogik/verfuegbarkeit.md`.** Sie sind die
Abnahme, nicht eine Auswahl daraus.

Besonders:

- **Testfall 16 ist parallel zu fahren, nicht sequenziell.** Ein sequenzieller
  Test bestätigt nur, dass die zweite Buchung nach der ersten scheitert — das
  tut sie auch ohne jede Sperre.
- Testfall 9 und 10: Sommerzeitlücke und doppelte Winterzeitstunde.
- Testfall 13: ein abgelaufener Hold gibt den Slot **sofort** frei, auch ohne
  Aufräumjob.
- Testfall 21: der Erzeugungsjob ist idempotent.

## Stand

| Datei | Deckt ab |
|---|---|
| `tests/Feature/Verfuegbarkeit/ErzeugungTest.php` | 9, 10, 11, 21, 22 und die Grundlagen |
| `tests/Feature/Verfuegbarkeit/AbfrageTest.php` | 1–8, 12, 18 |
| `tests/Feature/Verfuegbarkeit/HoldTest.php` | 12–15 |
| `tests/Parallel/NebenlaeufigkeitTest.php` | **16, 17** |

> **Nachtrag 26.09.2026.** 19 und 20 stehen seit WP-14:
> `tests/Feature/Kalender/RueckabgleichTest.php` und `AusgangsabgleichTest.php`.

Offen bleiben **19 und 20** — eigenmarkierte Events und extern gelöschte
Termine. Beide setzen den Kalendersync voraus und gehören zu WP-14.

`php artisan mrs:slots-erzeugen` zieht die Slots rollierend nach und räumt
abgelaufene Holds auf, nachts um 03:15. Für die Demo-Praxis: 4140 Slots für
30 Tage in 0,6 Sekunden.

## Testfall 16 wird wirklich parallel gefahren

Die Spezifikation besteht darauf, und sie hat recht: ein sequenzieller Test
bestätigt nur, dass die zweite Buchung nach der ersten scheitert — das tut sie
auch ohne jede Sperre.

Dafür gibt es jetzt eine **eigene Testsuite ohne `RefreshDatabase`**
(`tests/Parallel`, eigener Eintrag in `phpunit.xml`) und eine zweite
Datenbankverbindung `mysql_zweit`. Der Grund ist der gleiche wie das Problem
selbst: `RefreshDatabase` umschließt jeden Test mit einer Transaktion, und
eine zweite Sitzung sieht davon nichts. Ein Test, der das nicht trennt, prüft
eine Sperre gegen sich selbst — und die hält immer.

Der Ablauf: Sitzung A sperrt die Slot-Zeilen und hält die Transaktion offen,
Sitzung B versucht denselben Zugriff und läuft in ein Sperrzeitlimit. **Ohne
`FOR UPDATE` wäre hier kein Fehler entstanden und beide hätten denselben Slot
vergeben.** Nach dem Rollback gelingt der zweite Versuch.

Testfall 17 prüft dasselbe mit überlappenden Strecken und stellt sicher, dass
die Meldung ein Zeitlimit ist und **kein Deadlock** — das ist der Nachweis,
dass in fester Reihenfolge gesperrt wird.

## Ein Nachtrag zu WP-08

Die Überschneidungsprüfung der Arbeitszeiten galt bisher nur je Standort. Ein
Behandler konnte montags 9 bis 12 in Hamburg **und** 10 bis 14 in Bremen
eingetragen werden. WP-10 materialisiert je Behandler und Zeitpunkt genau eine
Zeile — das wäre in den Unique-Index gelaufen, mit einer Meldung, die auf den
Erzeugungsjob zeigt statt auf die Stammdaten.

Die Prüfung greift jetzt über alle Standorte und sagt das auch so: „Zu dieser
Zeit arbeitet der Behandler bereits an einem anderen Standort."

## Nicht in diesem Paket

Terminverwaltung, Oberfläche, Terminstatus (WP-11). `appointments` entsteht
hier nur so weit, wie der Fremdschlüssel es verlangt.

Öffentliche Buchungsseite (WP-12), Erinnerungen (WP-13), Kalendersync
(WP-14, WP-15). `external_block_id` entsteht hier als Spalte, gefüllt wird sie
dort.

Die Warteliste (WP-25). Sie fragt keine Slots ab, sondern prüft einen
konkreten freigewordenen Slot — sie benutzt aber die Holds von hier.

## Fallstricke

- **Ortszeit nach UTC ist die falsche Richtung.** Siehe oben. Wer sie wählt,
  baut sich zwei Sonderfälle ein, die nur zweimal im Jahr auftreten und dann
  einen Tag lang falsche Termine erzeugen.
- **Ein abgelaufener Hold ist sofort abgelaufen.** Jede Abfrage vergleicht
  gegen die Uhr. Ein Aufräumjob gibt nur Zeilen frei, er entscheidet nichts.
- **Die Umwandlung gibt nichts frei.** Hold zu Termin heißt: dieselben Zeilen,
  `slot_hold_id` raus, `appointment_id` rein, eine Transaktion. Wer erst
  freigibt und dann belegt, öffnet ein Fenster, in dem jemand dazwischenkommt.
- **Sperren in fester Reihenfolge.** Zwei Buchungen mit überlappenden Strecken
  erzeugen sonst einen Deadlock.
- **Ein Behandler kann nicht an zwei Orten gleichzeitig sein.** Der
  Unique-Index sieht das; die Prüfung in WP-08 tat es bis hierher nur je
  Standort.
