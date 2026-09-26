# WP-11 · Terminverwaltung intern

## Ziel
Das Praxisteam legt Termine an, verschiebt sie, sagt sie ab und pflegt den
Status — und kein einziger dieser Wege kann zwei Termine auf denselben Slot
legen, auch nicht der, der die Verfügbarkeit ausdrücklich übersteuert.

## Vorher lesen
- **`docs/fachlogik/verfuegbarkeit.md`**, Abschnitt „Schnittstelle nach außen":
  die interne Terminverwaltung „sieht auch nicht öffentliche Terminarten, darf
  übersteuern". Was das genau heißt, entscheidet dieses Paket.
- `docs/entscheidungen.md` — **A11** Status als `VARCHAR` plus PHP-Enum;
  **A12** kein Soft Delete auf Kontakten; **D1** `contacts`, nicht `patients`;
  **D2** Behandlungswunsch als `treatment_id`; **P1** keine
  Behandlungsdokumentation; **P2** keine Anzahlungen und Stornogebühren
- `CLAUDE.md`, Regeln 1 und 3
- `specs/WP-10-verfuegbarkeits-engine.md`, Abschnitt „Nicht in diesem Paket"

## Voraussetzungen
WP-08, WP-09, WP-10

## Die eine Entscheidung dieses Pakets

Übersteuern klingt nach einem Schalter, der alles abschaltet. Das wäre falsch.
Die elf Bedingungen aus `docs/fachlogik/verfuegbarkeit.md` zerfallen in zwei
Gruppen, die nichts miteinander zu tun haben:

| | Bedingung | Aussage | übersteuerbar |
|---|---|---|---|
| V1 | Arbeitszeit | *soll* angeboten werden | **ja** |
| V2 | Abwesenheit | *soll* angeboten werden | **ja** |
| V3 | Schließzeit | *soll* angeboten werden | **ja** |
| V4 | externer Blocker | ist **vergeben** | nein |
| V5 | Termin liegt darauf | ist **vergeben** | nein |
| V6 | gültiger Hold | ist **vergeben** | nein |
| V7 | Behandlerfreigabe | *soll* angeboten werden | **ja** |
| V8 | Standortangebot | *soll* angeboten werden | **ja** |
| V9 | Vorlaufzeit | *soll* angeboten werden | **ja** |
| V10 | Buchungshorizont | *soll* angeboten werden | **ja** |
| V11 | volle Dauer frei | ist **vergeben** | nein |

**Übersteuern heißt „ich weiß, dass das nicht angeboten wird, ich mache es
trotzdem" — nicht „ich buche über jemanden drüber".** Die Empfangskraft, die
der Stammkundin um 18:30 noch einen Termin gibt, obwohl um 18:00 Feierabend
ist, tut etwas Richtiges. Die Empfangskraft, die einen Termin auf eine bereits
belegte Zeit legt, tut etwas, das sich hinterher niemand erklären kann.

### Eine Folge davon, die nicht auf der Hand liegt

Außerhalb der Arbeitszeit gibt es **gar keine Slot-Zeilen** — WP-10
materialisiert nur, wo jemand arbeitet. Wer übersteuert, muss sie also
anlegen. Und zwar über `insertOrIgnore`, damit der Unique-Index
`(practitioner_id, starts_at)` weiterhin der Schiedsrichter bleibt und nicht
eine Prüfung im PHP-Code, die zwei gleichzeitige Übersteuerungen nicht sieht.

## Verschieben ist dieselbe Zeile

Nicht absagen und neu anlegen. Erinnerungen (WP-13), Kalendersync (WP-14) und
die Attribution (WP-32) hängen an der Identität des Termins; eine Absage plus
Neuanlage zählt in der Auswertung zweimal „gebucht" und einmal „abgesagt",
obwohl nichts davon stattgefunden hat.

Alte und neue Strecke werden **in einer Transaktion** gesperrt, in fester
Reihenfolge. Sie dürfen sich überlappen — wer einen Termin um zehn Minuten
schiebt, überlappt fast vollständig. Für **diesen** Termin sind seine eigenen
Zeilen frei, für jeden anderen nicht.

## Status

```
pending ──▶ confirmed ──▶ attended ⇄ no_show
   │   │         │
   │   └─────────┴──▶ cancelled        (endgültig)
   └──▶ attended / no_show
```

- **`attended` und `no_show` erst, wenn der Termin begonnen hat.** „Erschienen"
  für einen Termin in der nächsten Woche ist keine Aussage, sondern ein
  Vertipper.
- **`cancelled` ist endgültig.** Die Absage hat die Slots freigegeben; sie
  können längst vergeben sein. Ein Wiederbeleben wäre eine Buchung ohne
  Verfügbarkeitsprüfung. Wer den Termin doch will, bucht ihn neu.
- `attended` und `no_show` sind gegeneinander korrigierbar. Beides wird von
  Hand gesetzt, und beides wird verwechselt.

## Kontakte im Mindestumfang

`contacts` entsteht hier so weit, wie ein Termin es verlangt — genau wie
`appointments` in WP-10 entstanden ist. **WP-16 besitzt die Tabelle.**

Vorname, Nachname, E-Mail, Telefon. Alles feldverschlüsselt (Regel 3), mit
blinden Indizes auf E-Mail und Nachname (Entscheidung A6), weil der Empfang
beim Anlegen eines Termins nach einer bestehenden Person suchen muss.

**Die Suche kann nur exakt sein.** Das ist keine Nachlässigkeit, sondern die
Folge von P8 und der Feldverschlüsselung: über ein verschlüsseltes Feld gibt
es kein `LIKE`. „Mül" findet nichts, „Müller" findet alle Müllers.

**Die Telefonnummer bekommt keinen blinden Index.** Sie bräuchte vorher eine
E.164-Normalisierung — „+49 170 1234567" und „01701234567" ergäben sonst zwei
verschiedene Hashes, und die Suche träfe still nie. Diese Normalisierung
gehört zu WP-16, wo sie auch für das Zusammenführen (D6) gebraucht wird.

## Schritte

1. `contacts` im Mindestumfang, verschlüsselt, mit blinden Indizes.
2. `appointments` erweitern: `contact_id`, `booked_via`, `cancelled_at`,
   `cancellation_reason`, `is_override`.
3. `Slotbelegung` — die eine Stelle, die Slot-Zeilen einem Termin zuweist.
   Sperrt, prüft, gibt frei und belegt in **einer** Transaktion.
4. `Terminplaner` — buchen, verschieben, absagen, Status setzen.
5. `Statusautomat` — die erlaubten Übergänge als Tabelle, nicht als `if`-Kette
   an vier Aufrufstellen.
6. `Kontaktsuche` — exakt über die blinden Indizes.
7. Oberfläche: Tagesansicht je Standort mit Spalten je Behandler, Anlegen,
   Verschieben, Absagen, Status.
8. Sichtbarkeit: `appointments.manage` sieht und ändert alles,
   `calendar.own.view` sieht den eigenen Kalender und ändert nichts.

## Abnahmekriterien

**Buchen**

1. Ein Termin auf einem freien Vorschlag belegt genau die Zeilen der belegten
   Strecke.
2. Die angezeigte Zeit ist ohne Rüstzeit, die belegte mit.
3. Ein Termin auf einer bereits belegten Strecke schlägt fehl.
4. Ein Termin auf einer Strecke mit gültigem Hold schlägt fehl.
5. Ein Termin auf einer Strecke mit abgelaufenem Hold gelingt.
6. Eine inaktive Terminart ist nicht buchbar.
7. Der Termin trägt den Buchungskanal.

**Übersteuern**

8. Ein Termin außerhalb der Arbeitszeit ist ohne Übersteuern nicht buchbar.
9. Mit Übersteuern ist er buchbar und legt die fehlenden Slot-Zeilen an.
10. Übersteuern setzt sich über Abwesenheit, Schließzeit, Vorlaufzeit und
    Buchungshorizont hinweg.
11. **Übersteuern setzt sich nicht über eine Belegung hinweg.**
12. Übersteuern setzt sich nicht über einen gültigen Hold hinweg.
13. Ein Behandler, der an diesem Standort nicht arbeitet, ist auch mit
    Übersteuern nicht buchbar.
14. Der Termin ist als übersteuert erkennbar.

**Verschieben**

15. Der Termin behält seine Identität.
16. Die alten Zeilen sind frei, die neuen belegt.
17. Eine überlappende Verschiebung gelingt.
18. Eine Verschiebung auf eine belegte Strecke schlägt fehl und **lässt den
    Termin unverändert**.
19. Eine Verschiebung zu einem anderen Behandler gelingt.
20. Ein abgesagter Termin lässt sich nicht verschieben.

**Absagen und Status**

21. Eine Absage gibt die Slots frei.
22. Eine Absage hält Zeitpunkt und Grund fest.
23. Ein abgesagter Termin lässt sich nicht wiederbeleben.
24. `attended` vor Terminbeginn ist nicht möglich.
25. `attended` nach Terminbeginn ist möglich.
26. `attended` und `no_show` behalten die Slots.
27. `attended` und `no_show` sind gegeneinander korrigierbar.
28. Jeder Statuswechsel steht im Protokoll.

**Kontakte**

29. Name, E-Mail und Telefon liegen verschlüsselt in der Datenbank.
30. Die Suche über die exakte E-Mail findet den Kontakt.
31. Die Suche findet ihn unabhängig von Groß- und Kleinschreibung.
32. Die Suche über eine Teilzeichenkette findet ihn **nicht**.
33. Ein Kontakt einer fremden Organisation ist nicht auffindbar.

**Zugang**

34. Ohne `appointments.manage` ist kein Termin änderbar.
35. Mit `calendar.own.view` sieht eine Behandlerin nur ihre eigenen Termine.

**Nebenläufigkeit**

36. Zwei gleichzeitige Buchungen auf dieselbe Strecke erzeugen genau einen
    Termin. Paralleler Test, nicht sequenziell.

## Nicht in diesem Paket

**Notizen zum Termin.** Ausdrücklich nicht, und nicht aus Zeitgründen: ein
Freitextfeld am Termin füllt sich innerhalb von Wochen mit
Behandlungsverläufen. Entscheidung P1 schließt Behandlungsdokumentation aus,
weil sonst § 630f BGB greift — mit Aufbewahrungspflicht, Einsichtsrecht und
Beweislastumkehr. Notizen mit Zweckbindung und Aufbewahrungsfrist gehören zu
WP-18.

Ebenfalls nicht: Stornogebühren und Anzahlungen (P2), Erinnerungen und
Bestätigungen (WP-13), die öffentliche Buchungsseite (WP-12), der
Kalendersync (WP-14, WP-15), der Attributionsschnappschuss (D13, WP-32) und
das Zusammenführen von Kontakten (D6, D7, WP-16).

`contacts` entsteht hier nur so weit, wie ein Termin es verlangt. Kanäle,
Einwilligungen und Leads gehören zu WP-16 bis WP-18.

## Fallstricke

- **Ein Schalter, der alle elf Bedingungen aufhebt, ist ein Bug.** V4, V5, V6
  und V11 sagen nicht „soll nicht", sondern „ist schon vergeben". Siehe oben.
- **Übersteuern braucht Zeilen, die es nicht gibt.** Wer nur prüft statt
  anzulegen, bekommt einen Termin ohne Slot-Belegung — unsichtbar für jede
  spätere Buchung, und damit genau die Doppelbuchung, die WP-10 ausschließen
  sollte.
- **`orWhere` bricht aus dem Mandanten-Scope aus.** Die Sperrabfrage beim
  Verschieben verknüpft zwei Bedingungen mit ODER. Ohne Klammerung steht der
  globale Scope daneben statt darüber, und die Abfrage sieht fremde Mandanten.
  Regel 1 hängt an einer Klammer.
- **Beim Verschieben zuerst freigeben, dann belegen.** In dieser Reihenfolge,
  auf bereits gesperrten Zeilen, in einer Transaktion. Andersherum löscht der
  Freigabeschritt die gerade gesetzte Belegung der Überlappung wieder.
- **Ein blinder Index ohne Normalisierung findet nichts.** „Müller" und
  „müller" sind zwei verschiedene HMACs. Die Normalisierung muss beim
  Schreiben und beim Suchen dieselbe sein, sonst trifft die Suche nie — und
  zwar still.
- **Eine Absage ist keine Löschung.** Der Termin bleibt, die Slots werden
  frei. Wer die Zeile löscht, verliert die No-Show-Quote und die Grundlage
  jeder Auswertung.

## Stand

Alle 36 Abnahmekriterien sind als Tests umgesetzt und laufen:

| Datei | Deckt ab |
|---|---|
| `tests/Feature/Termine/BuchenTest.php` | 1–7 |
| `tests/Feature/Termine/UebersteuernTest.php` | 8–14, dazu das Raster |
| `tests/Feature/Termine/VerschiebenTest.php` | 15–20 |
| `tests/Feature/Termine/StatusTest.php` | 21–28 |
| `tests/Feature/Termine/KontakteTest.php` | 29–33 |
| `tests/Feature/Termine/ZugangTest.php` | 34, 35 |
| `tests/Feature/Termine/OberflaecheTest.php` | Teilnachladen, Verschieben und Absagen über HTTP |
| `tests/Parallel/TerminNebenlaeufigkeitTest.php` | **36** |

Die Aufteilung im Code folgt der Tabelle oben: `Terminplaner::pruefeAngebot()`
prüft die übersteuerbare Gruppe und wird beim Übersteuern übersprungen,
`Slotbelegung` prüft die andere — auf gesperrten Zeilen, innerhalb der
Transaktion. Eine Prüfung davor wäre eine Aussage über die Vergangenheit.

Die Oberfläche liegt unter `/termine`: Tagesansicht je Standort mit Spalten je
Behandler, Anlegen mit Slot-Auswahl, Detailansicht mit Status, Verschieben und
Absagen. Der Demo-Seeder legt Kontakte und zwölf Termine an, damit die Ansicht
nicht leer startet.

## Was das Bauen zutage gefördert hat

**Der Termin entsteht vor seiner Belegung — und muss mit ihr fallen.** Die
Slot-Zeilen brauchen einen Schlüssel, auf den sie zeigen können, also wird
erst die Terminzeile geschrieben und dann belegt. Schlägt die Belegung fehl,
muss die Transaktion beides mitnehmen. Sonst steht im Kalender ein Termin, der
keine Zeit belegt — unsichtbar für jede Verfügbarkeitsprüfung und damit genau
die Doppelbuchung, die WP-10 ausschließt. Der Nebenläufigkeitstest prüft das
ausdrücklich mit.

**Eine Zeile behauptete zwei Dinge gleichzeitig.** Ein Slot mit abgelaufenem
Hold gilt als frei und ist buchbar — behielt aber seine `slot_hold_id`, als
der Termin sie belegte. Die Tabelle sagt in ihrem eigenen Kommentar, dass
genau eine der drei Belegungsspalten gesetzt ist. Die Zuweisung räumt den
toten Verweis jetzt mit ab.

**`wandleUm()` aus WP-10 brauchte einen Kontakt.** Ein Hold wird zu einem
Termin, und ein Termin gehört zu einem Menschen. Ohne diesen Pflichtparameter
hätte WP-12 und WP-24 später ein `contact_id` von Hand nachtragen müssen — an
einer Stelle, an der die Transaktion schon zu ist.

**Die Oberfläche bot Schaltflächen an, die der Server ablehnt.** „Erschienen"
stand auch an einem Termin in der nächsten Woche. Der Statusautomat beantwortet
jetzt nicht nur „ist dieser Wechsel erlaubt", sondern auch „welche sind es
gerade" — beides aus derselben Tabelle, damit die Antworten nicht auseinander
laufen können.

**Die Regel „Oberflächentexte mit Umlauten" war in den Enums gebrochen.**
`Bestaetigt`, `Datensatz geaendert`, `Schluesselsatz angelegt` — die
Kommentarregel („ohne Umlaute im PHP-Quelltext") war auf Text angewandt
worden, den Menschen lesen. Betroffen waren `AppointmentStatus`, `AuditEvent`,
`Role` und zwei Formularmeldungen. Ein mechanischer Test dafür fängt mehr
Falschtreffer als Fehler — „neue" enthält „ue" —, deshalb steht die Regel in
`docs/konventionen.md` und nicht in einer Prüfung.

**`npm run build` lieferte zweimal ein Bündel ohne eine einzige Seite.**
`import.meta.glob('./pages/**/*.vue')` löste im Container über den Bind-Mount
leer auf; der Build meldete Erfolg, war auffällig schnell und erzeugte genau
eine JS-Datei. Sichtbar wurde es erst im Browser als „Page not found:
./pages/auth/Login.vue" auf einer schwarzen Seite. `npm run build` prüft
seitdem über `scripts/pruefe-build.mjs` nach, ob jede Seite im Manifest steht.

## Offen

> **Nachtrag 26.09.2026.** Die **Wochenansicht** steht als Zeitraster —
> Montag bis Sonntag in Ortszeit des Standorts, Stunden aus den
> Arbeitszeiten, auf dem Telefon als Liste je Tag
> (`components/termine/Wochenraster.vue`, `tests/Feature/Termine/WochenansichtTest.php`).
> Die Kontaktsuche findet seit WP-16 auch Telefonnummern. Die Tagesansicht
> bleibt eine Liste je Behandler.

Die Tagesansicht ist eine Liste je Behandler, kein Zeitraster. Für eine Praxis
mit zwei Behandlern trägt das; ab vier Spalten will man eine Zeitachse sehen.
Das gehört zusammen mit der Wochenansicht in ein späteres Paket.

Die Kontaktsuche findet nur exakte E-Mail-Adressen und Nachnamen. Die
Telefonnummer fehlt, bis WP-16 die E.164-Normalisierung mitbringt.
