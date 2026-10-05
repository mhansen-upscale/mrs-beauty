# WP-40 · Sprache für die Praxis

> Feedbackschleife 1 beginnt und endet mit demselben Satz: Das Produkt ist gut,
> aber das Wording muss die Zielgruppe verstehen. Im Video stolpert die Person
> über ROAS, über die Warteliste und über die Frage, ob hinter dem Posteingang
> eine KI steckt. Die Standbilder zeigen „Mandant", „Templates",
> „Assistenzläufe", ein rohes `PAUSED` und den Namen des Bildmodells. Es gibt
> rund 2.000 Oberflächentexte, fest im Code (P7), und keine Wortliste.

## Ziel
Eine verbindliche Wortliste „Fachwort → Praxiswort", die ganze Oberfläche der
Praxis danach umgeschrieben, und ein Test, der verhindert, dass ein Fachwort
zurückkommt.

## Vorher lesen
- `docs/feedback/2026-10-05-feedbackschleife-1.md`: Aussagen 1, 8, 9, 19, 22, Beobachtungen B2, B3, B5, B8
- `docs/konventionen.md`, **Benennung**: Oberflächentext ist auch das, was aus PHP kommt
- `docs/entscheidungen.md`: **P7** (nur Deutsch, ohne Übersetzungsschicht), **C18** (Prüfhilfe, kein Siegel), **C19** (kein „automatisch löschen")
- `tests/Feature/Design/BauteileTest.php` und `RegelFuenfTest.php` als Muster für einen Quelltexttest
- Kapitel 15 (Glossar) im Nutzerhandbuch, `docs/handbuch/Mrs-Beauty-Nutzerhandbuch.pdf`, S. 66–67

## Voraussetzungen
Keine. **Dieses Paket kommt zuerst**, weil WP-41 bis WP-50 ihre Texte schon
nach der Wortliste schreiben.

## Die Linie, an der alles hängt

**Die Spezifikation spricht Fach, die Oberfläche spricht Praxis.** In Code,
Kommentaren und Spezifikationen bleiben Mandant, Lead, Slot und Attribution
stehen (`konventionen.md`, letzter Absatz von **Benennung**). Auf dem
Bildschirm einer Praxis stehen sie nicht. Das Backoffice ist ausgenommen,
denn Betreiber lesen Technik.

**Ein Begriff, ein Wort.** Heute heißt dasselbe vier Mal anders:
Service-Fenster, Antwortfenster, 24-Stunden-Fenster und offenes Fenster.
Ebenso stehen Inbox und Posteingang, Agent und Assistent, Brand Guide und
Marke, Behandler und Behandlerin nebeneinander. Die Liste legt je Begriff ein
Wort fest.

**Erklären statt voraussetzen.** Drei Fragen im Video hatten eine Antwort im
Produkt, die niemand sah: ob eine KI antwortet, wann jemand auf die
Warteliste kommt, woher die Anzeigenbilder stammen. Jede dieser Seiten bekommt
einen Satz, der es sagt, als `description` des Seitenkopfs, nicht als
eigener Hinweiskasten (`BauteileTest`).

## Die Wortliste (Vorschlag, im Paket festzulegen)

| Heute | Künftig | Wo es heute steht |
|---|---|---|
| Lead, Pipeline | Anfrage, Stand der Anfragen | `pages/leads/Index.vue:124, 182` |
| Speed-to-Lead | Zeit bis zur ersten Antwort | `leads/Index.vue`, `auswertung/Index.vue` |
| Agent | Assistent | `Ability.php:65`, `katalog/Behandlungen.vue:167` |
| Assistenzlauf | Antwort des Assistenten | `Dashboard.vue:89`, `settings/Abo.vue` |
| Mindestsicherheit | wie sicher der Assistent sein muss | `settings/Agent.vue:114` |
| Template | Nachrichtenvorlage | `Dashboard.vue:74, 88`, `inbox/Index.vue:560` |
| Service-, Antwort-, 24-Stunden-, offenes Fenster | ein Wort, z. B. „Antwortzeitraum" | `inbox/Index.vue:557-560`, `settings/Abo.vue:159, 233`, `Dashboard.vue:74` |
| Kontingent, Aufstocken | im Abo enthalten, nachkaufen | `Dashboard.vue:252-257`, `settings/Abo.vue` |
| Brand Guide, Reifegrad | Marke, wie vollständig | `marke/Index.vue:134-236`, `Role.php:41` |
| Inbox | Posteingang | `settings/Postfach.vue:84-112`, `Ability.php:62-63` |
| Mandant | Praxis | `stammdaten/Standorte.vue:166`, `AuditEvent.php:132-151` |
| Slots | freie Zeiten | `stammdaten/Behandler.vue:487` |
| Rüstzeit | Vor- und Nachbereitung | `katalog/Terminarten.vue:142` |
| Vorlauf | frühestens buchbar | `katalog/Terminarten.vue` |
| Europe/Berlin | Zeitzone Berlin | `stammdaten/Standorte.vue`, `termine/Index.vue:196` |
| Kurzname (slug) | Adresse der Buchungsseite | `stammdaten/Standorte.vue:224` |
| Dashboard | Übersicht | `AppSidebar.vue:66` |
| ACTIVE / PAUSED | läuft / pausiert | `werbung/Index.vue:578` |
| Seiten-ID | Facebook-Seite (Auswahl statt Ziffern, siehe WP-42) | `werbung/Index.vue` |
| Impressionen, CTR, CPC, CPM | so oft gesehen, Klicks; der Rest unter „Details" | `werbung/Index.vue:496-521` |
| ROAS | Umsatz je Werbe-Euro | `auswertung/Index.vue:147-152` |
| CAC | Kosten je neuer Patientin | `auswertung/Index.vue` |
| Show-Rate | erschienen | `auswertung/Index.vue` |
| Attribution, Zuordnungsmodell | wie wir Anfragen der Werbung zuordnen | `AttributionModel.php` |
| Pixel, PageView, Events-Manager | bleibt auf der Tracking-Seite, mit Erklärsatz | `settings/Tracking.vue` |
| Token, Systembenutzer, Business Manager | bleibt in der WhatsApp-Einrichtung, mit Erklärsatz | `settings/WhatsApp.vue:52-82` |
| Impersonation | Zugriff durch den Support | `settings/Mailvorlage.vue:205` |
| Name des Bildmodells („GPT-Image 2.0") | entfällt; „von der KI gestaltet" | `anzeigen/Index.vue` |

**Patientin oder Kundin?** Das hängt an der Praxisart. Die Pilotpraxen sind
ärztlich geführt, dort heißt es „Patientin". Vorschlag: Für eine Praxis ohne
ärztliche Leitung bleibt es bei „Kontakt", bis WP-47 die Praxisart als
Einstellung bringt. Ein Wort je Praxis wäre eine Übersetzungsschicht (P7) und
ist nicht vorgesehen.

## Schritte
1. Wortliste festlegen: mit der Produktverantwortung durchgehen, dann als Abschnitt **„Wortwahl für die Praxis"** in `docs/konventionen.md`.
2. Verbotsliste in `config/mrs.php` unter `wortwahl.verboten`, je Eintrag mit Fundstelle. Sie enthält nur Wörter, die **nirgends** in der Praxisoberfläche stehen dürfen, zum Beispiel ROAS, CAC, Lead, Pipeline, Slot, Mandant, Template, Impersonation, `ACTIVE`, `PAUSED`.
3. Abnahmekriterien als Test schreiben (`tests/Feature/Design/WortwahlTest.php`); er scheitert zunächst.
4. Umschreiben, Wort für Wort nach Liste und nicht Datei für Datei. Dazu gehören auch Enum-`label()`/`description()`, Flash-Meldungen, `ValidationException::withMessages`, `FormRequest::messages()`, die Tourtexte in `useEinfuehrung.ts` und die Mailtexte in `Standardtexte`.
5. Drei Erklärsätze:
   - **Posteingang:** ob und wie der Assistent mitarbeitet, mit dem aktuellen Modus
   - **Warteliste:** wann jemand darauf kommt (G13, von Hand) und was bei einer frei werdenden Zeit geschieht (P5)
   - **Anzeigen:** woher Text und Bild stammen
6. Widersprüche im Produkt beheben:
   - Startseite: „mit Öffnungszeiten je Standort" (`oeffentlich/Startseite.vue:79`); Standorte haben keine (WP-08)
   - Kampagnen: „Den Namen vergeben wir" (`werbung/Index.vue:824-847`), überholt seit C9
   - Kampagnen: „lesen ausschließlich — angelegt oder geändert wird hier nichts" (`werbung/Index.vue:373-376`), überholt seit WP-27
   - `docs/integrationen/meta.md:198` entsprechend
7. Tests nachziehen, die Sätze wörtlich prüfen (zum Beispiel die fehlende Facebook-Seite in `AnzeigenschaltungTest`).

## Abnahmekriterien
1. Kein Wort aus `wortwahl.verboten` steht im `<template>` einer Vue-Datei unter `resources/js/pages` oder `resources/js/components`. Ausgenommen sind `pages/backoffice/**` und `DashboardBetreiber.vue`. Geprüft wird auf ganze Wörter, ohne Groß- und Kleinschreibung.
2. Dasselbe gilt für die Rückgaben von `label()` und `description()` aller Enums unter `app/Enums`. Ausgenommen sind Enums, die nur das Backoffice zeigt; sie stehen als Liste im Test.
3. Dasselbe gilt für Flash-Meldungen (`with('erfolg' | 'fehler' | …)`) und `withMessages` in `app/Http/Controllers`, außer `Backoffice/`.
4. Ein Eintrag in `wortwahl.verboten` ohne Fundstelle lässt den Test scheitern.
5. Für den WhatsApp-Antwortzeitraum steht in der Praxisoberfläche genau ein Begriff; die drei anderen Schreibweisen finden sich nicht mehr.
6. Posteingang, Warteliste und Anzeigen tragen in ihrem Seitenkopf eine `description`, die die Fragen 8, 9 und 22 aus dem Protokoll beantwortet. Der Posteingang nennt den Modus des Assistenten der Praxis (`off`, `suggest` oder `auto`) in Worten.
7. Die Startseite verspricht keine Öffnungszeiten je Standort.
8. `vendor/bin/pest` läuft grün, die ganze Kette aus CLAUDE.md eingeschlossen.

## Nicht in diesem Paket
- Neue Seiten oder neue Abläufe; Kampagnen-Assistent und Dashboard kommen in WP-42 und WP-46.
- Eine Übersetzungsschicht oder Wörter je Praxis (P7).
- Das Backoffice.
- Das Handbuch-PDF. Seine Quelle liegt nicht im Repository; das Glossar dort wird außerhalb nachgezogen (`specs/README.md`, „Außerhalb des Repositories").

## Fallstricke
- **Fachwörter in Bezeichnern sind kein Befund.** `leads/Index.vue` heißt weiter so, ebenso die Route `/anfragen`. Geprüft wird nur sichtbarer Text, also Template-Text und Zeichenketten in Attributen, keine Bezeichner und keine `import`-Pfade.
- **Ein Wort kann zu kurz sein.** „Lead" steckt nicht in „Leadverwaltung", wohl aber in Sätzen. Deshalb Wortgrenzen; der Test braucht eine Ausnahmeliste für Eigennamen (Meta-Ereignis „Lead" auf der Tracking-Seite, dort mit Erklärsatz).
- **Rechtsbegriffe bleiben.** „Einwilligung", „Impressum", „HWG" und „Datenschutz" sind keine Fachwörter, sondern das, was die Praxis rechtlich kennen muss.
- **C18 und C19 gelten weiter.** Kein „geprüft", kein Siegel, kein „löscht automatisch", auch nicht in einem vereinfachten Satz.
- **Mailtexte sind Standard, keine Kopie** (D15). Ändert sich ein Standardtext, sehen alle Praxen ohne Überschreibung sofort den neuen.
