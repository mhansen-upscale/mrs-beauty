# WP-43 · Behandler zuerst

> Bestandskundinnen kommen für Botox und Hyaluron alle paar Monate wieder, und
> meistens zur selben Ärztin. Nicht jeder Behandler bietet jede Behandlung an.
> Im Video fehlt auf der Buchungsseite deshalb die Frage „bei wem?". Intern
> fragt „Termin anlegen" zuerst die Terminart, der Behandler kommt erst mit
> der Uhrzeit (B4). Entscheidung **P15**: erst die Person, dann ihre
> Behandlungen, dann ihre Zeiten.

## Ziel
Intern und auf der Buchungsseite wählt man zuerst den Behandler oder „Keine
Vorliebe". Danach sieht man nur, was dieser Behandler anbietet, und nur
seine freien Zeiten.

## Vorher lesen
- `docs/fachlogik/verfuegbarkeit.md`: V7 (Behandler je Terminart) und V8 (Standort je Terminart)
- `specs/WP-11-terminverwaltung-intern.md`, `WP-12-oeffentliche-buchungsseite.md`
- `docs/fachlogik/agent.md:67-80`: der Assistent fragt nur auf Wunsch nach dem Behandler und **bleibt so** (P15)
- `docs/feedback/2026-10-05-feedbackschleife-1.md`: Aussage 14, Beobachtung B4

## Voraussetzungen
WP-40, wegen der Wörter. Sonst nichts, die Zuordnung steht schon im Schema.

## Was schon da ist

Die Zuordnung „wer kann was" gibt es in drei Schichten:
- `treatment_practitioner` mit `treatments.all_practitioners` (Vorgabe `true`)
- `appointment_type_practitioner` (V7), das eine Terminart weiter einschränkt
- `appointment_type_location` (V8) und `practitioner_location`

Aufgelöst wird an **einer** Stelle, `AppointmentType::freigegebeneBehandler()`
(`app/Models/AppointmentType.php:175`): Die Liste der Terminart gewinnt,
sonst gilt `Treatment::behandler()` (`app/Models/Treatment.php:182`).

Es fehlt die **Rückrichtung**. `Practitioner` kennt weder `treatments()` noch
`appointmentTypes()`. `Verfuegbarkeit::freieStartzeiten()` verlangt die
Terminart und nimmt den Behandler als Filter, was für „seine Zeiten" reicht.
Die Frage „was bietet er an" stellt bisher niemand.

## Die Linie, an der alles hängt

**Eine Regel, zwei Richtungen.** Die Rückwärtssuche wird nicht neu erfunden,
sondern aus derselben Auflösung abgeleitet. Sonst bietet die Buchungsseite
eines Tages eine Terminart an, für die `freieStartzeiten()` den Behandler
nicht freigibt — eine Sackgasse ohne Fehler.

**„Keine Vorliebe" ist der alte Weg.** Wer keine Person wählt, erlebt die
Buchung wie heute. Auch das Zusammenlegen gleicher Uhrzeiten über Behandler
hinweg bleibt erhalten (`OeffentlicheVerfuegbarkeit.php:67-78`).

## Schritte
1. Abnahmekriterien als Tests.
2. **Rückwärtssuche:** `Practitioner::angeboteneTerminarten(?Location $standort = null): Collection`
   - enthält aktive, für den Kanal freigegebene Terminarten, für die `freigegebeneBehandler()` den Behandler enthält und deren Standorte (V8) sich mit seinen (`practitioner_location`) überschneiden; mit `$standort` nur dieser
   - in SQL, nicht als Schleife über alle Terminarten; der Test unten sichert die Gleichheit
3. **Intern** (`components/termine/TerminAnlegen.vue`, `SlotWahl.vue`, `AppointmentController`):
   - Reihenfolge: Behandler → Terminart (gefiltert) → freie Zeit → Kontakt → Quelle
   - Erste Wahl „Egal wer, erste freie Zeit" führt in die heutige Reihenfolge
   - `vorschlaege` nimmt `practitioner` schon an (`AppointmentController.php:444-447`); die Oberfläche schickt ihn jetzt mit
   - die Terminarten kommen je Behandler als Teil-Neuladen (`only: ['terminarten']`)
   - „Verfügbarkeit übersteuern" bleibt unverändert
   - die Behandlerin mit `calendar.own.view` sieht sich selbst vorgewählt
4. **Öffentlich** (`pages/buchung/Index.vue`, `PublicBookingController`, `app/Buchung/OeffentlicheVerfuegbarkeit.php`):
   - neuer erster Schritt **„Bei wem möchten Sie Ihren Termin?"** mit Karten (Foto und Vorstellung aus WP-12) und „Keine Vorliebe"
   - bei nur einem buchbaren Behandler entfällt der Schritt
   - danach „Welche Leistung?" mit den Terminarten des Behandlers, dann „Wo?" mit den Standorten, an denen beide zusammenkommen, dann die Zeiten
   - `OeffentlicheVerfuegbarkeit::tage()` bekommt `?Practitioner $behandler`; mit Behandler wird nichts zusammengelegt
5. **Link je Behandler:**
   - `/buchen/{praxis}?bei={practitioner_uuid}` springt mit vorgewähltem Behandler in Schritt 2, für Visitenkarte, Instagram oder Recall-Nachricht
   - ein ungültiger, inaktiver oder nicht buchbarer Wert führt still auf Schritt 1, ohne Fehlerseite
6. **Stammdaten:** `stammdaten/Behandler.vue` zeigt je Behandler „Bietet an: …" aus derselben Rückwärtssuche, zum Lesen. Gepflegt wird weiter an Behandlung und Terminart.

## Abnahmekriterien

**Die Rückwärtssuche**

1. Über einen Datensatz mit drei Behandlern, zwei Standorten, Behandlungen mit und ohne `all_practitioners` und Terminarten mit und ohne eigene Liste gilt für **jedes** Paar (Behandler, Terminart): Die Terminart ist in `angeboteneTerminarten()` genau dann enthalten, wenn der Behandler in `freigegebeneBehandler()` steht und beide einen gemeinsamen Standort haben.
2. Ein Behandler ohne Standort bietet nichts an; ein inaktiver Behandler erscheint auf keiner Seite.
3. Eine Terminart, deren einziger Standort deaktiviert ist, wird nicht angeboten.

**Intern**

4. Wird Behandler A gewählt, enthält die Liste der Terminarten nur, was A anbietet. Die vorgeschlagenen Zeiten stammen nur von A.
5. „Egal wer" liefert dieselben Vorschläge wie heute, mit demselben Datensatz verglichen.
6. Eine Behandlerin mit `calendar.own.view` ist sich selbst vorgewählt und kann keinen anderen Behandler wählen.

**Öffentlich**

7. Bei zwei buchbaren Behandlern ist „Bei wem?" der erste Schritt; bei einem entfällt er.
8. Mit Behandler A zeigt „Welche Leistung?" nur As Terminarten und „Wo?" nur Standorte, an denen A arbeitet **und** die die Terminart anbieten.
9. Mit Behandler A enthalten die Tage nur As Zeiten. Ist A um 10:00 frei und B auch, erscheint 10:00 einmal, und die Reservierung hält A.
10. „Keine Vorliebe" verhält sich wie heute. „Bietet jede Uhrzeit nur einmal an" (`BuchungsseiteTest`) gilt dort weiter.
11. `?bei=` mit As UUID beginnt bei „Welche Leistung?" mit A. Mit einer fremden, inaktiven oder unbekannten UUID beginnt die Seite bei Schritt 1, mit Status 200.
12. Eine Reservierung für A, deren Zeit inzwischen weg ist, führt wie heute zurück zur Zeitwahl, und zwar bei A.

**Sonst**

13. `stammdaten/Behandler.vue` zeigt bei jedem Behandler die Terminarten aus Kriterium 1.
14. Der Assistent bucht unverändert (`BuchungsdialogTest` grün).

## Nicht in diesem Paket
- Der Assistent fragt nicht nach dem Behandler (P15).
- Pflege „wer kann was" am Behandler; sie bleibt an Behandlung und Terminart.
- Räume (WP-45).

## Fallstricke
- **In die URL gehört nur der Behandler.** `?bei=` trägt eine UUID, nie eine Terminart oder Behandlung als Name (Regel 2, D2). Der Pixel bekommt weder das eine noch das andere (`PixelTest`).
- **Zusammenlegen nur ohne Vorliebe.** Wer bei A bucht und B bekommt, hat genau das erlebt, was das Feedback beklagt.
- **Tests, die die Reihenfolge festhalten,** ändern sich mit: `Termine/OberflaecheTest.php:58, 133` (ohne Terminart keine Vorschläge, gilt nun „ohne Terminart **und** ohne Behandler"), `Buchung/BuchungsseiteTest.php` (`teilAbrufTage`) und `BehandlervorstellungTest.php`. Eine Änderung ist nur richtig, wenn das Kriterium oben sie verlangt.
