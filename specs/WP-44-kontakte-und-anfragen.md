# WP-44 · Kontakte und Anfragen vereinfachen

> Das Video beginnt mit einem Fehler, den niemand für einen hielt. Eine
> Buchung über den Buchungslink taucht weder im Dashboard noch unter
> *Anfragen* auf, dort erst nach dem Umsortieren. Danach kommen die Fragen:
> Was tut dieser Knopf? Wie beantwortet man eine Anfrage? Ist eine
> Online-Buchung schon ein Termin, und bekommt die Person eine Bestätigung?
> Von „neue Nachricht" bis „Termin gebucht" berührt man heute drei
> Menüpunkte und vier Masken und sucht den Kontakt zweimal.

## Ziel
Neue Buchungen und Anfragen sind sofort sichtbar. Jeder Kontakt hat eine
eigene Seite mit allem, was zu ihm gehört. Aus dem Posteingang führt ein Klick
zu Kontakt und Termin.

## Vorher lesen
- `docs/feedback/2026-10-05-feedbackschleife-1.md`: Aussagen 2–5, Abschnitt „Ursachen"
- `specs/WP-16-kontakte-kanalidentitaeten.md`, `WP-17-leads-pipeline.md`, `WP-21-inbox.md`
- Entscheidungen **D3, D4, D6, P8, B22, C12**
- `app/Leads/Leadverwaltung.php`, `app/Http/Controllers/Leads/LeadController.php:35-110`

## Voraussetzungen
WP-40.

## Ursachen

- **Die Kachel zählt das Falsche.** Eine Online-Buchung legt die Anfrage gleich im Stand `scheduled` an (`Leadverwaltung::beiTermin`, Z. 95-112). „Neue Anfragen" zählt nur `new` (`app/Betrieb/Praxiskennzahlen.php:75`). Eine Buchung ist also nie „neu".
- **Die Liste sortiert die Neuen nach unten.** Sortiert wird nach Stand, darin die ältesten zuerst (`LeadController.php:46-50`). Für offene Anfragen ist das richtig, denn was am längsten liegt, gehört nach oben. Für gebuchte ist es falsch.
- **Der Knopf ohne Wirkung.** „Wir haben geantwortet" (`leads.respond`) vermerkt die erste Reaktion. Bei einer Online-Buchung steht sie schon fest, der Klick ändert also sichtbar nichts.
- **Der Ablauf steht nirgends.** Eine Online-Buchung ist ein fester Termin ohne Freigabe. Die Bestätigung geht nur über das Postfach der Praxis (B22); ohne Postfach bleibt sie als `no_mailer` stehen. An der Anfrage sieht man davon nichts.

## Die Linie, an der alles hängt

**Neu ist, was niemand angesehen hat, nicht ein Stand.** Die Praxis will
wissen, was seit dem letzten Blick hinzugekommen ist, ob Anfrage oder
Buchung. Der Stand der Anfrage beschreibt etwas anderes.

**Eine Person, eine Seite.** Termine, Anfragen, Gespräche, Warteliste,
Notizen und Einwilligungen liegen heute verstreut oder gar nicht sichtbar.
Die Kontaktseite führt sie zusammen. Später hängen dort die Akte (WP-47) und
die Rechnungen (WP-48).

**Die Tabellen bleiben getrennt** (D3), nur die Oberfläche wird eins.

## Schritte
1. Abnahmekriterien als Tests.
2. **„Neu seit Ihrem letzten Besuch":**
   - je Person ein Zeitpunkt `users.leads_seen_at`; eine Person gehört genau einer Praxis (`users.organization_id`)
   - die Anfragenseite setzt ihn beim Aufruf
   - neu ist, was danach angelegt wurde, unabhängig vom Stand
   - die Dashboard-Kachel heißt „Neue Anfragen und Buchungen" und zählt genau das; mit Link auf die gefilterte Liste
3. **Sortierung:**
   - offene Stände (`new`, `contacted`) zuerst, darin die ältesten
   - geschlossene Stände (`scheduled`, `won`, `lost`) darunter, darin die neuesten zuerst
   - Neue tragen eine Marke „neu"
4. **Zeile einer Anfrage, nach Stand:**
   - **Online gebucht:** „Termin am … bei …", Zustand der Bestätigungsmail (verschickt, wartet, fehlgeschlagen mit Grund, „kein Postfach hinterlegt" mit Link zu *Einstellungen → Postfach*)
   - **Offen:** „Antworten" (in den Posteingang, falls es ein Gespräch gibt), „Termin anlegen" (vorbefüllt), „Als beantwortet markieren" (nur solange keine Reaktion vermerkt ist)
   - Der Knopf ohne Wirkung entfällt.
5. **Kontaktseite `/kontakte/{contact}`** (`contacts.show`):
   - Kopf: Name, Kanäle, Schlagworte
   - Abschnitte: kommende Termine, vergangene Termine (mit erschienen / nicht erschienen / abgesagt), Anfragen mit Stand, Gespräche mit Link in den Posteingang, Warteliste, Notizen (anlegen und löschen wie im Posteingang), Einwilligungen (lesend, je Kanal, jüngste je Typ, D9)
   - Knopf „Datenauskunft" (`contacts.export`, gibt es; `organisation/Datenschutz.vue:170` verspricht ihn)
   - Die Kontaktliste verlinkt auf die Seite.
6. **Ein Menüpunkt:**
   - *Kontakte* mit zwei Ansichten, „Anfragen" (Vorgabe, mit Zähler der Neuen) und „Alle Kontakte"
   - `/anfragen` bleibt als Adresse und öffnet die erste Ansicht
   - `AppSidebar.vue:70-74` verliert einen Eintrag
7. **Posteingang** (`pages/inbox/Index.vue`, `InboxController`):
   - unbekannte Kennung: „Kontakt anlegen", vorbefüllt mit Telefon oder E-Mail der Kennung; danach ist das Gespräch zugeordnet, ohne zweiten Schritt
   - zugeordnetes Gespräch: „Termin anlegen" öffnet den Termindialog (WP-43) mit Kontakt und Quelle „Nachricht"
   - Die Marke „Anfrage: …" wird ein Link auf die Kontaktseite.
8. Texte nach WP-40, auch der Seitenkopf von *Kontakte*. Der Satz „Keine Patientenakte …" fällt mit WP-47 weg und bis dahin aus diesem Paket heraus.

## Abnahmekriterien
1. Nach einer Online-Buchung zeigt das Dashboard der Inhaberin „Neue Anfragen und Buchungen: 1".
2. Nach dem Aufruf der Anfragenseite ist die Kachel 0, bei einer anderen Person derselben Praxis weiter 1.
3. In der Liste steht die neue Buchung vor einer älteren Buchung und hinter allen offenen Anfragen. Unter den offenen steht die älteste oben.
4. Die Zeile einer Online-Buchung nennt Termin und Behandler. Ohne Postfach steht dort „Keine Bestätigung verschickt: kein Postfach hinterlegt" mit Link, für eine Person mit `organization.manage`, für andere ohne Link.
5. „Als beantwortet markieren" erscheint nur, solange `first_responded_at` leer ist, und füllt ihn (`Lead.php:85-92`).
6. `/kontakte/{contact}` zeigt kommende und vergangene Termine, Anfragen, Gespräche, Warteliste, Notizen und Einwilligungen des Kontakts. Ein Kontakt einer fremden Praxis ergibt 404 (Regel 1).
7. „Datenauskunft" auf der Kontaktseite liefert dieselbe Datei wie `contacts.export` heute.
8. Im Menü steht *Kontakte* einmal; `/anfragen` öffnet die Ansicht „Anfragen".
9. Aus einem Gespräch mit unbekannter WhatsApp-Kennung legt „Kontakt anlegen" einen Kontakt mit dieser Telefonnummer an und ordnet das Gespräch zu. Gibt es die Nummer schon, wird zugeordnet statt doppelt angelegt (D6).
10. „Termin anlegen" aus einem zugeordneten Gespräch öffnet den Termindialog mit diesem Kontakt und Quelle „Nachricht"; nach dem Speichern hängt der Termin am Kontakt, und die Anfrage folgt D4.
11. Empfang (`contacts.manage`) sieht Kontaktseite und Anfragen. Die Behandlerin ohne `contacts.manage` sieht beides nicht und hat keinen Menüpunkt dafür.

## Nicht in diesem Paket
- Akte und Rechnungen (WP-47, WP-48); die Kontaktseite bekommt nur den Platz dafür.
- Volltextsuche (P8). Die Suche bleibt exakt; ein fehlender Treffer nennt das, wie heute.
- Kanban-Ansicht (WP-17 schließt sie aus).

## Offen — vor dem Bau zu entscheiden
- **Anfrage schon mit der ersten Nachricht?** Heute entsteht eine Anfrage nur beim Termin oder über `POST /anfragen`, für das es keine Oberfläche gibt. „Neu" bleibt deshalb fast leer, und „Zeit bis zur ersten Antwort" misst den Posteingang kaum.
  Vorschlag: Eine Anfrage entsteht, sobald ein Gespräch einem Kontakt zugeordnet ist und dieser keine offene hat (D4). Die Behandlung bleibt leer, bis jemand sie setzt (D2: nie Freitext).
  Wirkung auf die Attribution: ohne Kampagnenbezug, also „Quelle unbekannt", wie WP-32b es vorsieht.

## Fallstricke
- **`leads_seen_at` ist je Person, nicht je Praxis.** Sonst sieht der Empfang nichts mehr, sobald die Inhaberin einmal hingeschaut hat.
- **Die Kontaktseite ist Art.-9-nah.** Termine mit Behandlungen sind Gesundheitsdaten. Die Seite verlangt `contacts.manage`, läuft im Mandanten, und unter maskierter Impersonation zeigt sie Namen maskiert wie die Liste (C4).
- **Betreiber sind `User` ohne Praxis** (C14). Für sie gibt es keinen Zeitpunkt und keine Kachel; die Impersonation setzt ihn nicht, sonst verschwindet bei der Praxis die Marke „neu".
