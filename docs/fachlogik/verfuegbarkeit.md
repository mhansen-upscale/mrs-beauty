# Fachlogik: Verfügbarkeits-Engine

Gehört zu WP-10. Verbindlich, sobald geprüft.

> **Rekonstruktion.** Dieses Dokument fehlte im Repository, wird aber von
> `specs/README.md` als verbindliche Spezifikation des größten Risikopostens
> geführt. Der Inhalt ist aus den Festlegungen abgeleitet, die die vorhandenen
> Spezifikationen erzwingen — insbesondere aus `docs/fachlogik/warteliste.md`
> (Slot-Holds belegen `appointment_slots`-Zeilen und blockieren jede parallele
> Buchung), `docs/fachlogik/agent.md` (Hold mit TTL im Buchungsdialog) und
> `docs/integrationen/kalender.md` (externe Blocker, Eigenmarkierung).
> **Abgeleitete Stellen sind mit ▲ markiert und vor WP-10 zu bestätigen.**

## Aufgabe

Zu jedem Zeitpunkt beantworten: *Welche Slots kann diese Person für diese
Terminart an diesem Standort bei diesem Behandler buchen?* — und die Antwort
so geben, dass zwei gleichzeitige Buchungsversuche niemals denselben Slot
vergeben.

Das ist der Teil des Systems, an dem ein Fehler nicht als Fehlermeldung
auffällt, sondern als zwei Personen im Wartezimmer zur selben Uhrzeit.

## Grundentscheidung: materialisierte Slots

Verfügbarkeit wird **nicht** bei jeder Anfrage aus Regeln berechnet, sondern
in `appointment_slots` als Zeilen vorgehalten. Eine Zeile ist ein
Zeitabschnitt fester Länge (Raster, Standard 5 Minuten ▲) für genau eine
Ressource.

Diese Entscheidung ist durch `docs/fachlogik/warteliste.md` vorgegeben: der
Slot-Hold „belegt `appointment_slots`-Zeilen, blockiert gegen jede parallele
Buchung". Eine rein berechnete Verfügbarkeit kann das nicht leisten, weil es
nichts gibt, worauf eine Datenbanksperre greifen könnte.

**Folge:** Ein Termin über 45 Minuten belegt neun Zeilen. Belegung ist eine
Fremdschlüsselbeziehung auf `appointment_id` bzw. `slot_hold_id`, beide
`NULL`, solange die Zeile frei ist.

**Eindeutigkeit.** Ein zusammengesetzter Unique-Index über
(`practitioner_id`, `starts_at`) macht Doppelvergabe zu einem Datenbankfehler
statt zu einem Fachfehler. Die zweite parallele Buchung schlägt fehl, statt zu
gewinnen. ▲

## Eingangsgrößen

Ein Slot ist buchbar, wenn **alle** Bedingungen gelten:

| # | Bedingung | Quelle |
|---|---|---|
| V1 | Liegt im Arbeitszeitfenster des Behandlers | Praxisstammdaten, WP-08 |
| V2 | Liegt nicht in einer Abwesenheit (Urlaub, Krankheit, Pause) | WP-08 |
| V3 | Liegt nicht in einer Schließzeit des Standorts | WP-08 |
| V4 | Wird nicht von einem externen Kalenderblocker überdeckt | WP-14, WP-15 |
| V5 | Ist nicht durch `appointment_id` belegt | — |
| V6 | Ist nicht durch einen **gültigen** `slot_hold_id` belegt | — |
| V7 | Der Behandler ist für die Terminart freigegeben | WP-09 |
| V8 | Der Standort bietet die Terminart an | WP-09 |
| V9 | `starts_at − now >= lead_time` der Terminart | WP-09 ▲ |
| V10 | `starts_at <= now + booking_horizon` der Organisation | ▲ |
| V11 | Die volle Dauer der Terminart ist lückenlos frei, inklusive Rüstzeit | WP-09 ▲ |

**V11 ist die Bedingung, die am häufigsten übersehen wird.** Es genügt nicht,
dass der Startslot frei ist. Vor- und Nachbereitungszeit (`buffer_before`,
`buffer_after`) gehören zur belegten Strecke, sind aber **nicht** Teil der
Zeit, die dem Kontakt angezeigt wird. ▲

## Zeit

Gespeichert wird in UTC. Jede fachliche Auswertung — Wochentag, Tagesgrenze,
Zeitfenster, Arbeitszeit — läuft in der Ortszeit des **Standorts**, nicht in
der des Servers und nicht in der des Anfragenden.

Drei Fälle, die eine eigene Testklasse verdienen:

- **Umstellung auf Sommerzeit.** Die Stunde 02:00–03:00 existiert nicht. Slots
  in dieser Stunde dürfen nicht erzeugt werden.
- **Umstellung auf Winterzeit.** Die Stunde 02:00–03:00 existiert zweimal.
  Zwei Slots mit derselben Ortszeit und unterschiedlicher UTC-Zeit sind
  korrekt und müssen unterscheidbar bleiben.
- **Standorte in verschiedenen Zonen** innerhalb eines Mandanten. Die
  Wochentagsmaske der Warteliste (K6) wird je Standort ausgewertet.

## Erzeugung und Pflege

Slots werden von einem wiederkehrenden Job im Voraus erzeugt, Standard
90 Tage ▲, und rollierend nachgezogen. Der Job ist idempotent: ein zweiter
Lauf über denselben Zeitraum erzeugt keine Duplikate und löscht keine belegten
Zeilen.

Ändert sich eine Arbeitszeit, werden freie Slots neu berechnet. **Belegte
Slots werden nie automatisch entfernt.** Entsteht durch eine Änderung ein
Termin außerhalb der Arbeitszeit, ist das ein Konflikt für das Team, kein Fall
für eine automatische Absage. ▲

## Holds

Ein Hold belegt dieselben Zeilen wie ein Termin und unterscheidet sich nur
durch eine Ablaufzeit.

| Auslöser | TTL | Fundstelle |
|---|---|---|
| Buchungsdialog des Agenten | 15 Minuten | `docs/fachlogik/agent.md`, Schritt 6 |
| Wartelistenangebot | 30 Minuten | `docs/fachlogik/warteliste.md` |
| Öffentliche Buchungsseite | 10 Minuten ▲ | WP-12 |

Ein abgelaufener Hold gilt **sofort** als abgelaufen, nicht erst, wenn ein
Aufräumjob ihn entfernt. Jede Abfrage prüft `expires_at > now`. Der Aufräumjob
gibt nur die Zeilen frei; er entscheidet nichts.

Die Umwandlung eines Holds in einen Termin behält dieselben Zeilen und setzt
`slot_hold_id → appointment_id` in einer Transaktion. Es wird nichts
freigegeben und neu belegt — in der Lücke dazwischen könnte jemand buchen.

## Nebenläufigkeit

Die Buchung läuft in einer Transaktion mit `SELECT … FOR UPDATE` über die
betroffenen Slot-Zeilen, in aufsteigender `starts_at`-Reihenfolge, um
Deadlocks zwischen zwei Buchungen mit überlappenden Strecken zu vermeiden. ▲

Der Unique-Index ist die zweite Sicherung. Wenn die Sperre versagt, gewinnt
die Datenbank, nicht der Zufall.

**Diese beiden Mechanismen sind gemeinsam zu testen, parallel, nicht
sequenziell.** Ein sequenzieller Test bestätigt nur, dass die zweite Buchung
nach der ersten scheitert — das tut sie auch ohne jede Sperre.

## Externe Blocker

Aus dem Kalendersync stammende Blocker (WP-14, WP-15) belegen Slots wie ein
Termin, aber über eine eigene Spalte `external_block_id`. Unterscheidung ist
nötig, weil R3 aus `docs/integrationen/kalender.md` gilt: der externe Kalender
gewinnt bei Blockern, das System gewinnt bei Terminen.

Eigenmarkierte Events (R1) werden beim Rücksync ignoriert und erzeugen
**keinen** Blocker. Ohne diese Regel blockiert das System seine eigenen
Termine.

## Schnittstelle nach außen

Die Engine liefert Slots, sie entscheidet nicht über Sichtbarkeit. Wer welche
Slots sehen darf, ist Sache der aufrufenden Stelle:

| Aufrufer | Besonderheit |
|---|---|
| Öffentliche Buchungsseite (WP-12) | nur öffentlich buchbare Terminarten, Rasterung auf Anzeigeschritte |
| Interne Terminverwaltung (WP-11) | sieht auch nicht öffentliche Terminarten, darf übersteuern |
| Agent (WP-24) | wie öffentliche Seite, zusätzlich Hold-Pflicht ab Vorschlag |
| Warteliste (WP-25) | fragt nicht nach Slots, sondern prüft einen konkreten freigewordenen Slot gegen K1..K12 |

## Testfälle

**Grundlagen**
1. Ein Slot außerhalb der Arbeitszeit erscheint nicht.
2. Ein Slot in einer Abwesenheit erscheint nicht.
3. Ein Slot in einer Schließzeit des Standorts erscheint nicht.
4. Eine Terminart über 45 Minuten wird nicht vorgeschlagen, wenn nur
   40 Minuten am Stück frei sind.
5. Rüstzeiten belegen Slots, erscheinen aber nicht als Terminzeit.
6. Ein Behandler ohne Freigabe für die Terminart erscheint nicht.
7. `lead_time` der Terminart schließt zu kurzfristige Slots aus.
8. Slots jenseits des Buchungshorizonts erscheinen nicht.

**Zeit**
9. Sommerzeitumstellung erzeugt keine Slots in der nicht existierenden Stunde.
10. Winterzeitumstellung erzeugt beide Durchläufe der doppelten Stunde.
11. Zwei Standorte in verschiedenen Zeitzonen werten Wochentag und Tagesgrenze
    je Standort aus.

**Holds**
12. Ein gültiger Hold macht den Slot für andere unsichtbar.
13. Ein abgelaufener Hold gibt den Slot sofort frei, auch ohne Aufräumjob.
14. Die Umwandlung eines Holds in einen Termin behält dieselben Zeilen.
15. Ein zweiter Hold auf dieselben Zeilen ist ausgeschlossen.

**Nebenläufigkeit**
16. **Zwei gleichzeitige Buchungen auf denselben Slot erzeugen genau einen
    Termin und einen verständlichen Fehler.** Paralleler Test, nicht
    sequenziell.
17. Zwei Buchungen mit überlappenden, aber nicht identischen Strecken
    erzeugen keinen Deadlock.

**Kalender**
18. Ein externer Blocker macht den Slot unbuchbar.
19. Ein eigenmarkiertes Event erzeugt keinen Blocker.
20. Ein extern gelöschter Termin bleibt im System bestehen (R3).

**Pflege**
21. Der Erzeugungsjob ist idempotent: zweimal laufen lassen ändert nichts.
22. Eine geänderte Arbeitszeit entfernt freie Slots, aber keine belegten.
