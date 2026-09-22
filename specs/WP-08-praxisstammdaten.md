# WP-08 · Praxisstammdaten

## Ziel
Wann und wo gearbeitet wird, steht vollständig und widerspruchsfrei im System —
weil WP-10 nichts anderes hat, worauf es sich stützen kann.

## Vorher lesen
- **`docs/fachlogik/verfuegbarkeit.md`** — V1 Arbeitszeitfenster, V2 Abwesenheit, V3 Schließzeit, Abschnitt Zeit
- `docs/entscheidungen.md` — **A8** Zeitzone an `locations.timezone`; **D11** Praxisgruppe als ein Mandant mit mehreren Standorten; **A11** Status als `VARCHAR` plus PHP-Enum
- `docs/fachlogik/warteliste.md` — K3 und K4, die auf Standort und Behandler verweisen

## Voraussetzungen
WP-03, WP-04

## Dieses Paket ist die Grundlage von WP-10

Der größte Risikoposten des Projekts beantwortet eine einzige Frage: *Welche
Slots kann diese Person für diese Terminart an diesem Standort bei diesem
Behandler buchen?* Drei der elf Bedingungen dieser Antwort — V1, V2, V3 —
kommen aus diesem Paket. Was hier ungenau ist, wird dort zu einem Termin über
einer Abwesenheit.

## Zeit ist der schwierige Teil

**Die Zeitzone hängt am Standort** (Entscheidung A8), nicht an der
Organisation. Eine Praxisgruppe ist **ein** Mandant mit mehreren Standorten
(D11), und die können in verschiedenen Zonen liegen.

Daraus folgt eine Zweiteilung, die konsequent durchzuhalten ist:

| Art | Speicherung | Auswertung |
|---|---|---|
| Wiederkehrende Arbeitszeit | Wochentag plus **Ortszeit** (`TIME`) | in der Zone des Standorts |
| Abwesenheit, Schließzeit | absoluter Zeitpunkt, **UTC** (`DATETIME`) | wie gespeichert |

Eine Arbeitszeit „montags 9 bis 17 Uhr" ist keine Zeitspanne, sondern eine
Regel. Sie in UTC zu speichern wäre falsch: nach der Zeitumstellung stünde sie
eine Stunde daneben.

## Schritte

1. `locations`: Name, Anschrift, **Zeitzone**, Kontaktdaten, aktiv.
2. `practitioners`: Name, Titel, optionale Verbindung zu einem Benutzerkonto,
   aktiv.
3. `practitioner_location` als Pivot — wer arbeitet wo.
4. `working_hours`: je Behandler, Standort und Wochentag ein oder **mehrere**
   Zeitfenster. Die Mittagspause ist die Lücke zwischen zwei Fenstern, kein
   eigener Datensatz.
5. `absences`: je Behandler ein Zeitraum mit Grund (Urlaub, Krankheit,
   Fortbildung, sonstiges).
6. `location_closures`: je Standort ein Zeitraum mit Grund (Feiertag,
   Betriebsferien, Umbau, sonstiges).
7. Oberfläche für alles davon, unter Einstellungen.
8. `Weekday` als Enum mit **ISO-Nummerierung** (1 = Montag). Die
   Wochentagsmaske der Warteliste (K6) muss sich später darauf beziehen
   können.

## Abnahmekriterien

**Mandantengrenze**

1. Standorte und Behandler einer fremden Organisation sind weder sichtbar noch
   änderbar.
2. Ein Behandler lässt sich nicht einem Standort einer fremden Organisation
   zuordnen — **auf Datenbankebene**.
3. Ein Benutzerkonto einer fremden Organisation lässt sich nicht mit einem
   Behandler verbinden.

**Zeitzone**

4. Ein Standort ohne Zeitzone ist nicht speicherbar.
5. Eine unbekannte Zeitzone ist nicht speicherbar.
6. Zwei Standorte dürfen verschiedene Zeitzonen haben.
7. Eine Arbeitszeit wird in der Zone **ihres Standorts** ausgewertet, nicht in
   der des Servers.

**Arbeitszeiten**

8. Ein Behandler kann an einem Wochentag mehrere Fenster haben.
9. Zwei Fenster desselben Behandlers am selben Tag und Standort dürfen sich
   nicht überschneiden.
10. Ein Fenster, das endet, bevor es beginnt, ist nicht speicherbar.
11. Eine Arbeitszeit an einem Standort, an dem der Behandler nicht arbeitet,
    ist nicht speicherbar.

**Abwesenheiten und Schließzeiten**

12. Ein Zeitraum, der endet, bevor er beginnt, ist nicht speicherbar.
13. Die Abfrage „arbeitet dieser Behandler zu diesem Zeitpunkt?" berücksichtigt
    Arbeitszeit, Abwesenheit und Schließzeit gemeinsam.
14. Ein Zeitpunkt in der Sommerzeitlücke wird korrekt behandelt.
15. Ein Zeitpunkt in der doppelten Winterzeitstunde wird korrekt behandelt.

**Löschen**

16. Ein Standort mit Behandlern oder Arbeitszeiten wird deaktiviert, nicht
    gelöscht.

## Stand

Alle 16 Abnahmekriterien sind als Tests umgesetzt und laufen:

| Datei | Deckt ab |
|---|---|
| `tests/Feature/Stammdaten/ZeitzonenTest.php` | 4–7, 13–15 |
| `tests/Feature/Stammdaten/StammdatenTest.php` | 1–3, 8–12, 16 |

Die eine fachliche Abfrage heißt `Practitioner::arbeitetAm($zeitpunkt, $standort)`
und fasst V1, V2 und V3 zusammen. **WP-10 setzt darauf auf, statt die drei
Bedingungen erneut zu formulieren.**

Ein Punkt, der beim Bauen klarer wurde: Die Umrechnung geht von UTC in die
Ortszeit des Standorts, und diese Richtung ist **immer** eindeutig — auch am
Umstellungstag hat jeder UTC-Zeitpunkt genau eine Ortszeit. Die andere
Richtung, Ortszeit nach UTC, ist es nicht. Genau dort liegt die eigentliche
Arbeit von WP-10, und dieses Paket nimmt sie ihm nicht ab.

## Was das Bauen zutage gefördert hat

**Der Pivot ist kein anonymes Verbindungstabellchen.** `practitioner_location`
trägt eine `organization_id`, braucht also Schlüssel, zusammengesetzten
Fremdschlüssel und Global Scope wie jede andere Mandantentabelle. Als
`PractitionerLocation extends Pivot` mit `BelongsToTenant` gelöst und über
`->using()` eingebunden — sonst legte `attach()` eine Zeile ohne `id` an.

Der Architektur-Test hat das sofort gemeldet und war dabei selbst zu eng: er
verlangte `extends TenantModel`. Ein Pivot muss aber von `Pivot` erben. Die
Regel prüft jetzt die Substanz — **bindet das Modell `BelongsToTenant` ein?**
—, nicht die Basisklasse. Der Schutz kommt vom Trait, nicht vom Klassennamen.

**Strenge Modelle haben ein N+1 abgefangen**, bevor es eines wurde: die
Behandlerliste las `$behandler->user` und `$zeit->location` nach. In Produktion
wären das zwei Abfragen je Zeile gewesen.

**`RohbytesTest` hat sieben binäre Fremdschlüssel gefunden**, die sonst
`json_encode()` gebrochen hätten — dieselbe Klasse von Fehler wie in WP-04, nur
diesmal vor dem ersten Aufruf erwischt.

## Nicht in diesem Paket

Leistungskatalog und Terminarten (WP-09). Welche Behandlung ein Behandler
anbietet, entsteht dort — V7 und V8 der Verfügbarkeit gehören nicht hierher.

Die Verfügbarkeits-Engine selbst (WP-10). Hier entstehen die Eingangsgrößen,
nicht die Berechnung. Eine einzige Abfragemethode gibt es trotzdem schon, damit
die Testfälle 13 bis 15 etwas haben, woran sie sich festmachen können.

Kalendersync (WP-14, WP-15) und die daraus entstehenden externen Blocker.

Öffnungszeiten des Standorts als eigene Größe. Ein Standort ist geöffnet, wenn
dort jemand arbeitet — eine zweite, davon unabhängige Öffnungszeit wäre eine
Quelle für Widersprüche, die niemand auflösen kann.

## Fallstricke

- **Eine wiederkehrende Arbeitszeit ist keine Zeitspanne.** Wer sie in UTC
  speichert, hat nach der Zeitumstellung eine Praxis, die eine Stunde zu früh
  oder zu spät öffnet. Wochentag plus Ortszeit, ausgewertet in der Zone des
  Standorts.
- **Die Sommerzeitlücke gibt es wirklich.** Am Umstellungssonntag existiert
  02:30 Uhr nicht. Eine Arbeitszeit, die dort beginnt, darf nicht in einen
  falschen Zeitpunkt umgerechnet werden.
- **Die doppelte Winterzeitstunde auch.** 02:30 Uhr gibt es zweimal, mit
  verschiedenen UTC-Zeitpunkten. Beide sind gültige Arbeitszeit.
- **Überschneidende Fenster sind kein Schönheitsfehler.** WP-10
  materialisiert daraus Slots; zwei überlappende Fenster ergeben doppelte
  Slot-Zeilen und damit einen Unique-Verstoß — an einer Stelle, an der niemand
  nach der Ursache sucht.
- **Ein gelöschter Standort nimmt Termine mit.** Deshalb deaktivieren statt
  löschen, solange etwas daran hängt.
