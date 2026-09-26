# WP-33 · Job- und Betriebsinfrastruktur

## Ziel
Das Produkt läuft auch dann, wenn niemand hinsieht — und wenn es das nicht
tut, sieht man es.

## Vorher lesen
- `CLAUDE.md`, **Regel 4** — „Stille Ausfälle sind der Normalfall und keine
  Ausnahme: jede Verbindung wird überwacht, ein Ausfall erzeugt einen Hinweis
  **im Produkt**, nicht nur im Log."
- `docs/entscheidungen.md` — **S7** Laravel Cloud, **S8** Redis, **S9**
  Horizon/Pulse/Sentry, **A13** Queue mit Idempotenzschlüssel, **C6**
  Chat-Anhänge mit Frist
- `specs/WP-18-notizen-consent-retention.md`, Abschnitt Virenprüfung

## Voraussetzungen
Alles, was Aufträge erzeugt: WP-13 bis WP-25.

## Warum jetzt und nicht „abschließend nach WP-32"

`specs/README.md` sagt „wächst mit". Gewachsen ist inzwischen genug: elf
Aufträge, vier Warteschlangen, sechs geplante Läufe — und zwei Lücken, die
mit M2 scharf geworden sind.

**Die erste ist die Virenprüfung.** Seit WP-20b nimmt das Produkt Anhänge von
Fremden entgegen. Die Vorgabe aus WP-18 gibt jede Datei frei; ihr eigener
Kommentar behauptet, sie vermerke `unscanned`, tatsächlich schreibt sie
`clean`. Das ist genau die stille Lücke, vor der Regel 4 warnt: niemand sieht,
dass niemand hingesehen hat.

**Die zweite ist der Betrieb selbst.** Ein fehlgeschlagener Auftrag landet in
`failed_jobs` und wird dort von niemandem gelesen. Ein Rohereignis ohne Leser
bleibt liegen. Ein Scheduler, der nicht läuft, fällt erst auf, wenn eine
Praxis fragt, warum keine Erinnerungen ankommen.

## Schritte

1. **Virenprüfung, ehrlich**: `unscanned` heißt ungeprüft und wird nicht
   ausgeliefert; ein angebundener Prüfer entscheidet, sonst niemand.
2. `ClamAv` als Prüfer, über einen Transport, der sich im Test ersetzen lässt.
3. **Betriebsübersicht im Produkt**: fehlgeschlagene Aufträge, liegengebliebene
   Rohereignisse, gestörte Verbindungen, Stand der geplanten Läufe.
4. `mrs:betrieb` — dieselben Zahlen für die Konsole und für eine Überwachung
   von außen.
5. Architekturtest: jeder Auftrag nennt seine Warteschlange, seine Versuche
   und läuft nach dem Commit.
6. `docs/betrieb.md` — was auf Laravel Cloud laufen muss, damit das Produkt
   arbeitet.

## Abnahmekriterien

**Virenprüfung**

1. Ohne angebundenen Prüfer wird ein Anhang **nicht** ausgeliefert.
2. Der Vermerk lautet dann `unscanned`, nicht `clean`.
3. Ein angebundener Prüfer gibt eine saubere Datei frei.
4. Ein Fund sperrt die Datei und hält den Namen des Fundes fest.
5. Ein nicht erreichbarer Prüfer gibt **nicht** frei.
6. Die Prüfung läuft nicht im Anfragezyklus (Regel 4).

**Betriebsübersicht**

7. Fehlgeschlagene Aufträge sind im Produkt sichtbar, nicht nur im Log.
8. Liegengebliebene Rohereignisse sind sichtbar.
9. Gestörte Kanal- und Kalenderverbindungen sind sichtbar.
10. Ein Scheduler, der seit Stunden nicht lief, ist sichtbar.
11. Die Übersicht ist mandantengetrennt, wo die Daten es sind.
12. `mrs:betrieb` liefert dieselben Zahlen und einen Exit-Code, den eine
    Überwachung lesen kann.

**Aufträge**

13. Jeder Auftrag nennt eine Warteschlange.
14. Jeder Auftrag, der von einer Transaktion abhängt, läuft nach dem Commit.
15. Jeder Auftrag begrenzt seine Versuche.

## Nicht in diesem Paket

- **Das Super-Admin-Backoffice** (WP-34). Die Übersicht hier gehört der
  Praxis, nicht dem Betreiber.
- **Alarmierung nach außen** (Sentry/Pager). Die Anbindung steht in der
  Konfiguration; wer wann geweckt wird, ist eine Betriebsentscheidung.
- **Backups.** Gehören zur Plattform und in `docs/betrieb.md`, nicht in den
  Code.

## Fallstricke

- **Eine Vorgabe, die alles freigibt, ist schlimmer als eine, die alles
  sperrt** — solange niemand merkt, dass sie greift.
- **`failed_jobs` wächst still.** Eine Zahl ohne Anzeige ist keine
  Überwachung.
- **Ein Auftrag ohne `afterCommit` findet seine Zeile nicht**, wenn ein
  schneller Arbeiter vor dem Commit zugreift. Das Projekt hat
  `after_commit = false` — also muss es jeder Auftrag selbst sagen.

## Stand

Die 15 Abnahmekriterien laufen: `tests/Feature/Betrieb/BetriebslageTest.php`
(**8 Tests**) und `tests/Feature/Betrieb/AuftragsdisziplinTest.php`
(**5 Tests**). Gesamtstand 811.

Neu: `Scanergebnis`, `Scanverbindung`, `ClamAvVerbindung`, `ClamAvPruefung`,
`App\Betrieb\Betriebslage`, der Befehl `mrs:betrieb`, die Störungsanzeige auf
dem Dashboard und `docs/betrieb.md`.

## Was das Bauen zutage gefördert hat

**Die Virenprüfung log.** Ihr Kommentar sagte, sie vermerke `unscanned`, damit
jeder sehe, dass niemand hingesehen hat — geschrieben hat sie `clean`, und
`istFreigegeben()` lieferte daraufhin jede Datei aus. Seit WP-20b nimmt das
Produkt Anhänge von Fremden entgegen; die Lücke war damit scharf. Jetzt gilt:
**nur `clean` gibt frei**, alles andere nicht, und ein nicht erreichbarer
Prüfer gibt gar nichts frei.

Der Preis ist Unbequemlichkeit in der Entwicklung: ohne `CLAMAV_HOST` werden
Anhänge aufgenommen, aber nicht angezeigt. Das ist der Zustand, den man sehen
soll.

**Der Architekturtest hat sich selbst korrigiert.** Zuerst verlangte er von
jedem Auftrag ein eigenes `$tries` — und zeigte fünf an, die keines haben.
Nachgesehen: die Zahl der Versuche gehört zum Supervisor
(`config/horizon.php`), nicht zum Auftrag, und steht dort seit WP-02 mit
Begründung. Die Regel war falsch, nicht der Code. An ihre Stelle trat eine,
die wirklich etwas prüft: **jede benutzte Warteschlange braucht einen
Supervisor** — wer `->onQueue('berichte')` schreibt und keinen Arbeiter dafür
hat, bekommt einen Auftrag, der nie läuft.

Zwei weitere Regeln blieben und mussten Vererbung lernen: die
Kalenderaufträge erben Warteschlange und `afterCommit` von einer gemeinsamen
Basisklasse.

**Der Entwicklungs-Worker hörte nur auf eine Warteschlange.** `composer dev`
startete `queue:listen --tries=1` ohne Angabe — also nur `default`. Alles auf
`realtime` (eingehende Webhooks, Agentenläufe, Kanalversand) lief lokal
**nie**. Aufgefallen ist es beim Schreiben von `docs/betrieb.md`, nicht im
Betrieb — dort ist Horizon zuständig und hat alle vier.

## Offen

> **Nachtrag 26.09.2026.** **Sicherung und Wiederherstellung regelt Laravel
> Cloud** (`docs/betrieb.md`). **Die CI wäre beim ersten Lauf gescheitert**:
> `phpunit.xml` nannte eine Suite `tests/Unit`, die es nicht gab —
> `vendor/bin/pest` brach mit Exit 2 ab —, und ESLint fand zwei Fehler in
> `BuchungLayout.vue`. Beides behoben; die ganze Kette läuft lokal grün.
> Gelaufen ist die CI selbst weiterhin nicht.

**Backups und Wiederherstellung** gehören zur Plattform und stehen in
`docs/betrieb.md` als Prüfliste, nicht als Code.

**Alarmierung nach außen.** `mrs:betrieb` liefert den Exit-Code; wer wann
geweckt wird, ist eine Betriebsentscheidung.

**Die CI ist nie gelaufen.** Die beiden Workflows stehen seit WP-02 und
spiegeln genau die Kette, die hier lokal grün ist — ausgeführt hat sie noch
niemand.
