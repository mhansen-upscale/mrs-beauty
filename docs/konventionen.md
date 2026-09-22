# Konventionen

> **Rekonstruktion.** Dieses Dokument fehlte, wird aber von
> `docs/entscheidungen.md` (S3) referenziert. Es hält die Entscheidungen fest,
> die in WP-02 bis WP-04 ohnehin getroffen werden mussten. **Zu prüfen.**

## Farben

**Verbindlich: `docs/design/farben.md`.** Zwei Systeme, nicht eines — der
Admin-Bereich trägt unsere Marke (Petrol), die Buchungsseite die der Praxis.
Die Semantikfarben sind in beiden gesperrt.

**Keine festen Farbwerte im Code**, weder als Hex noch als Tailwind-Palette
(`bg-blue-500`). Alles läuft über die Tokens in `resources/css/app.css`.
Durchgesetzt durch `tests/Feature/Design/FarbenTest.php`. Ausgenommen ist ein
Farbwert, den eine Praxis selbst wählt und der in der Datenbank steht
(`appointment_types.color`) — das ist ein Datum, keine Gestaltung.

**Farbe trägt nie allein Bedeutung.** Jedes Statusabzeichen, jede Warnung und
jede Ampel bekommt zusätzlich ein Symbol und Text.

**Die Kalenderfarbe gehört dem Behandler**, nicht der Terminart: die Fläche im
Kalender gehört ihm. Der Terminstatus läuft deshalb über Rahmenstil und
Symbol. Acht Töne, danach wird die Reihe wiederholt.

**Kein Dunkelmodus.** Whitelabel und Dunkelmodus verdoppeln die
Kontrastprobleme. Die Tokens sind so aufgebaut, dass er nachrüstbar bleibt.

## Oberfläche

**shadcn-vue, strikt** (Entscheidung S3). Keine selbst erfundenen Komponenten.
Wer ein Bauteil braucht, das es noch nicht gibt, holt es aus shadcn-vue — er
baut es nicht nach.

**Die CLI ist inzwischen auf `reka-ui` gewechselt, das Projekt steht auf
`radix-vue` v1.** `npx shadcn-vue add …` schlägt deshalb fehl. Ein neues
Bauteil wird aus der shadcn-vue-Fassung für `radix-vue` übernommen und in
`resources/js/components/ui/<name>/` abgelegt, im Stil der vorhandenen: eine
Datei je Teil, `index.ts` als Sammelstelle, `cn()` für die Klassen,
`useForwardProps`/`useForwardPropsEmits` für die Weitergabe. So entstand
`ui/select` in WP-04.

Zwei Primitiv-Bibliotheken nebeneinander sind keine Option.

**Deutsch, ohne Übersetzungsschicht.** Entscheidung P7 sagt: nur Deutsch.
Unsere Texte stehen direkt in der Komponente. Die Locale-Spalten im Schema
bleiben, damit später keine Datenrückfüllung nötig ist — eine
`lang`-Datei-Infrastruktur für unsere eigenen Texte wäre Arbeit ohne
Gegenwert.

**Was Laravel selbst erzeugt, steht dagegen in `lang/de`.** Das ist kein
Widerspruch: Validierungsmeldungen, Anmeldefehler und die Mails der
Anmeldestrecke lassen sich nicht in eine Komponente schreiben. Dort liegen
ausschließlich Framework-Texte, keine fachlichen — fachliche Meldungen stehen
in `messages()` des jeweiligen `FormRequest`, dort, wo auch die Regel steht.

`lang/de/validation.php` enthält auch die **Feldnamen**. Ohne sie steht in
jeder Meldung der Spaltenname: „avg revenue cents ist erforderlich."

**Die beiden Mails der Anmeldestrecke werden ersetzt, nicht übersetzt**
(`AppServiceProvider::configureMails()`). Laravel setzt sie aus Satzteilen
zusammen; eine Übersetzungsdatei greift bei ihnen nur halb. Außerdem sind sie
für viele Praxen der erste Kontakt und sollen nach dem Produkt klingen.

Durchgesetzt durch `tests/Feature/Auth/DeutschTest.php`.

**Ausgeblendet ist nicht geschützt.** Die Oberfläche blendet über
`abilities` aus, was jemand nicht darf. Das ist Bequemlichkeit. Die
Zugangskontrolle steht in den Gates und in den Controllern, und beides wird
getestet.

**Jedes Formular, das nicht die ganze Seite ist, läuft über
`FormularDialog`.** Aufklappbare Abschnitte innerhalb einer Liste haben sich
nicht bewährt: die Zeilen springen, die Felder liegen je nach Spaltenbreite
woanders, und bei zwei offenen Abschnitten weiß niemand mehr, welches
Formular er gerade ausfüllt.

**Jede Liste ist eine `DataTable`** — mit Suche, Sortierung und Blättern,
nicht als `<ul>` mit Zeilen. Bewusst ohne TanStack Table: die Tabellen dieses
Produkts zeigen Stammdaten in zweistelliger Zeilenzahl, serverseitig bereits
mandantengefiltert.

**Jeder Button trägt ein Symbol.** Zeilenaktionen sind über `AktionsButton`
nur Symbol — mit Tooltip und `aria-label`, denn ein Symbol allein ist keine
Beschriftung.

**Arbeitsbereiche gehören in die Hauptnavigation, nicht hinter ein Zahnrad.**
Standorte, Behandler, Behandlungen, Terminarten, Team und Protokoll sind
Arbeit und stehen dort.

Unter Einstellungen steht, was die eigene Person betrifft — Profil und
Passwort —, und **technische Einrichtung der Praxis**, die man einmal macht
und dann vergisst: die Pixel-ID und das Postfach. Die Grenze ist nicht
„eigene Person gegen Praxis", sondern **Arbeit gegen Einrichtung**: was
jemand täglich anfasst, gehört in die Hauptnavigation.

Deshalb steht **Einstellungen nicht in der Seitenleiste**: sie hängt am
Benutzermenü, wo jeder sie sucht, der sein Profil meint. In der Leiste stünde
sie als gleichrangiger Punkt neben der Arbeit und zöge Klicks auf sich, die
ihr nicht gelten.

**Der aktive Menüpunkt hebt sich ab.** Grau auf Grau ist kein Zustand,
sondern ein Verdacht. Er trägt die Markenfarbe, eine kräftigere Schrift und
einen Balken an der linken Kante — dreifach, damit es auch bei schwachem
Kontrast, in Graustufen und für farbenblinde Augen erkennbar bleibt.

**Was der Agent nicht sagen darf, entsteht gar nicht erst.** Kein Entwurf,
den jemand wegwerfen müsste: was im Eingabefeld steht, wird irgendwann
abgeschickt. Die harte Weiche läuft deshalb **vor** der Textgenerierung, die
Nachprüfung **vor** der Anzeige (`docs/fachlogik/agent.md`, Schritte 4 und 7).

**Fremder Inhalt wird als Text gesetzt, nie als Auszeichnung.** Kein
`v-html`, kein `innerHTML` — auch nicht für etwas, das der Server selbst
erzeugt hat. Die Ausnahme wäre sonst der Präzedenzfall, auf den sich die
nächste beruft. Ein SVG gehört in ein `<img>` mit Datenadresse; dort kann es
nichts ausführen. Durchgesetzt durch `tests/Feature/Design/RegelFuenfTest.php`
(Regel 5).

**Eine Checkbox wird über `checked` gebunden, nicht über `model-value`.**
radix-vue v1 heißt so. Ein `:model-value` fällt als gewöhnliches Attribut
durch: das Häkchen erscheint, lässt sich anklicken — und der gebundene Wert
ändert sich nie, ohne jede Meldung. Durchgesetzt durch
`tests/Feature/Design/BauteileTest.php`.

**Eine Komponente mit mehreren Wurzelknoten reicht keine Attribute durch.**
Vue verwirft ein `@click` am Aufrufort dann stillschweigend. Wer eine
Komponente um einen Provider wickelt, deklariert das Ereignis ausdrücklich —
siehe `AktionsButton`.

- **Seitenaktionen stehen in der Werkzeugzeile der Tabelle**
  (`<DataTable #werkzeuge>`), nicht neben der Überschrift. `Heading` ist
  **mehrwurzelig** — Überschrift *und* Trennlinie —, und in einer Flex-Zeile
  wird die Trennlinie zum zweiten Flex-Element und schiebt alles Weitere in
  die nächste Zeile. Vue sagt dazu nichts; man sieht es nur. Dieselbe Falle
  wie beim `AktionsButton`, dessen Wurzel ein `TooltipProvider` ist.
  Durchgesetzt von `tests/Feature/Design/BauteileTest.php`.

## Benennung

| | |
|---|---|
| Klassen, Dateien, Namensräume | **Englisch** — `TenantContext`, `KeyRing`, `Invitation` |
| Fachliche Methoden und Attribute | **Deutsch**, wo der Fachbegriff deutsch ist — `istLetzteInhaberin()`, `erzeugeMerkmal()`, `scopeOffen()` |
| Framework-Oberfläche | folgt Laravel — `handle()`, `boot()`, `casts()`, `rules()` |
| Kommentare | **Deutsch**, ohne Umlaute in PHP-Quelltext |
| Oberflächentexte | **Deutsch**, mit Umlauten |

**Oberflächentext ist auch das, was aus PHP kommt.** Die Rückgaben von
`label()` und `description()` in den Enums, die Meldungen der
`FormRequest::messages()` und jede Ausnahmemeldung, die an einem Formularfeld
landet, sind Text für Menschen — sie tragen Umlaute. Die Kommentarregel gilt
für Kommentare, nicht für Zeichenketten. In WP-11 war das in vier Enums
gebrochen (`Bestaetigt`, `Datensatz geaendert`, `Schluesselsatz angelegt`).

Die Begriffe der Spezifikationen sind deutsch — Mandant, Warteliste,
Behandler, Einwilligung. Sie zu übersetzen, nur damit der Code einsprachig
aussieht, verliert die Verbindung zur Spezifikation.

## Oberfläche und Server

- **Keine eigene API-Schicht für das eigene Frontend** (Entscheidung S2). Daten,
  die eine Seite nachlädt — eine Vorschlagsliste, eine Suche —, kommen als
  `Inertia::optional()`-Prop derselben Seite und werden über
  `router.reload({ only: [...] })` angefordert. Sie werden nur berechnet, wenn
  sie angefordert wurden; ein Test hält beides fest.
- **Ein Menüpunkt, der nur zu einer 403 führt, ist keiner.** Die Navigation
  blendet nach `abilities` aus. Das bleibt Bequemlichkeit: die
  Zugangskontrolle steht im Gate, im `can`-Middleware **und** im
  `FormRequest::authorize()`.
- **Eine Schaltfläche, die der Server gleich darauf ablehnt, ist eine Falle.**
  Wo es eine Zustandsregel gibt, liefert der Server die gerade möglichen
  Schritte mit — aus derselben Tabelle, aus der die Prüfung kommt
  (`Statusautomat::moeglichFuer()`).
- **`npm run build` läuft im Container** (die Abhängigkeiten enthalten
  Linux-Binärdateien) und prüft danach über `scripts/pruefe-build.mjs`, ob
  jede Inertia-Seite im Manifest steht. Der Glob der Seiten hat über den
  Bind-Mount schon zweimal leer aufgelöst — mit einem Build, der Erfolg meldet
  und im Browser eine schwarze Seite zeigt.

## Datenbank und Modelle

- **Jede Mandantentabelle** entsteht über `TenantSchema::base()`, jeder
  Verweis über `TenantSchema::reference()`. Beides ist in WP-03 begründet.
- **Status als `VARCHAR` plus PHP-Enum** (Entscheidung A11). Der gültige
  Wertebereich steht im Enum, nicht im Schema.
- **`id` ist binär, `uuid` ist lesbar.** Rohbytes gehören nicht in ein Log,
  nicht in eine URL, nicht in eine Job-Nutzlast und nicht in JSON. Binäre
  Spalten sind verborgen oder gecastet — durchgesetzt durch
  `tests/Feature/Schema/RohbytesTest.php`.
  **Der Test deckt Modelle ab, nicht Job-Nutzlasten.** Ein Job bekommt
  kanonische UUIDs und löst sie selbst auf; mit Rohbytes bricht
  `json_encode()` beim Einstellen, mit einer Meldung, die auf die Queue zeigt
  statt auf die Ursache (WP-13).
- **Zeiten als `DATETIME`, niemals `TIMESTAMP`** (Entscheidung A7),
  durchgesetzt durch `tests/Feature/Schema/ZeitspaltenTest.php`.
- **Bedingte Eindeutigkeit** über eine generierte Spalte, die außerhalb des
  Zustands `NULL` ist (Entscheidung A10). **`VIRTUAL`, nicht `STORED`**, sonst
  verbietet MySQL `ON DELETE CASCADE` auf der Basisspalte.

## Protokoll und Mandantengrenze

- **`orWhere` gehört geklammert.** Der globale Scope der Mandantentrennung
  hängt seine Bedingung an das Ende der WHERE-Liste. Ein ODER auf derselben
  Ebene macht daraus „(eigene Bedingung) ODER (andere UND Mandant)" — und die
  erste Hälfte sieht dann fremde Mandanten. Regel 1 hängt an einer Klammer,
  also steht jede ODER-Verknüpfung in einer eigenen Closure.
- **`withoutGlobalScope()` ist im Anwendungscode untersagt.** Der benannte
  Kanal heißt `TenantContext::acrossTenants()`, verlangt eine Begründung und
  protokolliert sich selbst. Durchgesetzt durch
  `tests/Feature/Audit/DeckungTest.php`.
- **Ein Löschlauf hat einen Vorschaumodus, und er ist die Vorgabe.** Ein Lauf,
  der beim ersten scharfen Durchgang zu viel löscht, ist nicht rückholbar.
  Vorschau und Ernstfall laufen durch dieselbe Abfrage — eine Vorschau, die
  anders zählt, ist keine.
- **Löschen heißt Datei und Datensatz.** Anhänge liegen außerhalb der
  Datenbank; nur die Zeile zu entfernen lässt das Foto liegen, während der
  Datensatz weg ist.
- **Eine Einwilligung hängt an der Kanalidentität, nicht an der Person**
  (D8) — und nach einer Zusammenführung gilt je Kanal der jüngste Eintrag,
  **nicht die Vereinigung** (D9). Deshalb gibt es keinen Weg, der zu einem
  Kontakt ein einzelnes Ja liefert.
- **Kennzahlen haben genau eine Definition**, und sie steht in
  `docs/fachlogik/attribution.md`. Abweichende Auslegung in Berichten ist der
  schnellste Weg, Vertrauen in die Zahlen zu verlieren — wer eine Zahl
  anzeigt, zeigt die dort definierte.
- **Eine Herkunft, die man nicht kennt, wird nicht geraten.** „Vom Empfang"
  ist kein Kanal, sondern die Abwesenheit einer Angabe. Eine erfundene Quelle
  ist schlechter als keine, weil sie in der Auswertung wie eine echte aussieht.
- **Ein neuer blinder Index braucht einen Nachtrag.** Die Migration legt nur
  die Spalte an; gefüllt wird sie vom `saving`-Haken, und der läuft für eine
  bestehende Zeile nie. Ohne `mrs:blindindex-nachtragen` ist der gesamte
  Altbestand über dieses Feld unauffindbar — ohne Fehler, ohne Meldung.
- **Was verglichen werden soll, wird vorher normalisiert.** Eine
  Telefonnummer geht nach E.164, bevor daraus ein Index wird; sonst sind
  „+49 170 1234567" und „01701234567" zwei Personen. Was sich nicht
  normalisieren lässt, wird gespeichert und **nicht** indiziert — und die
  Suche findet dann nichts statt irgendetwas.
- **Eine Suche über verschlüsselte Felder gehört auf den Server.** `DataTable`
  filtert die geladenen Zeilen per Teilstring und täuscht damit eine Suche
  vor, die es nicht gibt (Entscheidung P8).
- **Ins Protokoll gehören Feldnamen, keine Werte** (Entscheidung C5). Wer einen
  Wert aufnehmen will, erklärt das Feld über `auditableValues()` — und zwar nur
  für Felder ohne Personenbezug.
- **Verschlüsselt heißt personenbezogen.** Jedes Modell mit einem
  `Encrypted`-Cast benennt seine Felder über `HasPersonalData`, damit die
  Maskierung greift.
- **Maskierung sitzt am Attributzugriff, nicht an der Serialisierung.** Ein
  Controller, der sein Array von Hand baut, soll sie nicht umgehen können.
- **Der Mandant steht vor der Routenbindung.** Laravel sortiert die Middleware
  einer Route nach einer Prioritätsliste, in der `SubstituteBindings` steht;
  eigene Middleware, die nicht in der Liste steht, landet dadurch **dahinter**
  — gleich in welcher Reihenfolge sie in `bootstrap/app.php` notiert ist. Ein
  Route-Model-Binding auf ein Mandantenmodell lief damit ohne Mandanten.
  `EnsureUserIsActive`, `ResolveTenant` und `ApplyImpersonation` hängen
  deshalb über `prependToPriorityList()` ausdrücklich vor
  `SubstituteBindings`. Durchgesetzt durch
  `tests/Feature/Tenancy/RoutenbindungTest.php`.
- **Ein Test, der den Mandanten über HTTP prüft, vergisst ihn vorher.**
  `alsMandant()` setzt das Singleton für den ganzen Testlauf — eine Anfrage
  findet ihn dann schon vor und beweist nichts über die Middleware. Wer den
  echten Weg prüfen will, ruft `ohneMandant()`, bevor die Anfrage losgeht.

## Zeit

- **Absolute Zeitpunkte in UTC als `DATETIME`** (Entscheidung A7).
- **Wiederkehrende Regeln als Wochentag plus Ortszeit** — eine Arbeitszeit ist
  keine Zeitspanne. Ausgewertet wird in der Zone des Standorts
  (`locations.timezone`, Entscheidung A8), nie in der des Servers.
- **Wochentage nach ISO 8601**, 1 = Montag (`App\Enums\Weekday`). Die
  Wochentagsmaske der Warteliste bezieht sich auf dieselbe Zählung.
- **UTC nach Ortszeit ist immer eindeutig, Ortszeit nach UTC nicht.** Am
  Umstellungstag fehlt eine Stunde beziehungsweise gibt es eine doppelt. Wer in
  diese Richtung rechnet, muss beide Fälle behandeln.

## Fachliche Konstanten

Jeder Schwellwert, jede Frist und jede Obergrenze aus einer Spezifikation steht
in `config/mrs.php`, mit der Fundstelle als Kommentar. Keine Magic Numbers in
Klassen. Was je Mandant abweichen darf, liegt zusätzlich in
`organizations.settings`; der Wert in `config/mrs.php` ist dann der Standard.

## Tests

- **Abnahmekriterien sind Testfälle und werden vor der Implementierung
  geschrieben.** Die Listen in den Fachlogik-Spezifikationen sind der Auftrag,
  nicht eine Illustration.
- **Gegen MySQL**, nicht gegen SQLite.
- **Eine Regel, die durchgesetzt werden soll, bekommt einen Test**, keinen
  Absatz in einem Dokument. Beispiele: der Architektur-Test der
  Mandantentrennung, die Zeitspalten, die Rohbytes.
- **Ein Test, dessen Suche ins Leere greifen kann, prüft sich selbst.** Jeder
  Architektur-Test läuft einmal zusätzlich ohne Zulassungsliste und muss dann
  die bekannten Ausnahmen melden.
- **Testnamen auf Deutsch**, im Indikativ: „it laesst die letzte Inhaberin
  ihre Rolle nicht abgeben".
- **Erst quittieren, dann verarbeiten.** Wer eine Zustellung vor der Antwort
  verarbeitet, bekommt Wiederholungen — und muss sie dann trotzdem
  deduplizieren. Zweimal dieselbe Arbeit.
- **Ein Register für austauschbare Umsetzungen ist ein Singleton.** Sonst
  bekommt jede Aufrufstelle ein eigenes, leeres — und die Registrierung
  verpufft lautlos.
- **Eine Attrappe antwortet nur für ihren eigenen Host.** `Http::fake()`
  nimmt die erste Attrappe, die etwas liefert — eine mit Auffangzweig
  verschluckt damit die Anfragen aller anderen, und ein Test mit zwei
  Fremdsystemen läuft grün, ohne das zweite je zu berühren. Wer nicht
  zuständig ist, gibt `null` zurück.
- **Nebenläufigkeit gehört nach `tests/Parallel`**, ohne `RefreshDatabase`.
  Eine umschließende Testtransaktion macht eine zweite Datenbanksitzung blind;
  ein Sperrtest prüft dann nur gegen sich selbst. Diese Tests räumen selbst
  auf.

## Fremdsysteme

- **Schreibende Aufrufe laufen über eine Queue** (Regel 4, Entscheidung A13).
  Die Oberfläche bleibt bedienbar, wenn ein Fremdsystem ausfällt.
- **Job-Nutzlasten tragen kanonische UUIDs, keine Rohbytes.** `BINARY(16)`
  bricht `json_encode()` mit „Malformed UTF-8 characters", und die Meldung
  zeigt auf die Queue statt auf die Ursache.
- **Aufträge, die in einer Transaktion entstehen, laufen `afterCommit()`.**
  Die Queue-Verbindungen stehen projektweit auf `after_commit = false`; ein
  Arbeiter, der schneller ist als der Commit, findet den Datensatz nicht und
  tut lautlos nichts. Betrifft jeden Auftrag, den `Terminplaner` einstellt.
- **Wiederholt wird nur, was ein Ausfall ist.** `Http::retry()` wiederholt
  ohne `when`-Rückruf jede nicht erfolgreiche Antwort — auch eine, die eine
  fachliche Aussage trägt. Google meldet ein verfallenes Delta-Token mit
  `410`; ein zweiter Versuch verschluckt die Aussage, und der Sync steht
  danach still, ohne dass etwas fehlschlägt. Wiederholt werden
  Verbindungsfehler und 5xx, sonst nichts.
- **Eigene Markierungen tragen die Organisation, nicht nur das Produkt.** Ein
  Behandler kann für zwei Praxen arbeiten und denselben Kalender verbinden;
  das Event der einen ist für die andere echte belegte Zeit.
- **Zwei Anbieter erst umsetzen, dann abstrahieren**
  (`docs/integrationen/kalender.md`). Ein gemeinsames Interface vor der
  zweiten Umsetzung passt auf keine von beiden. Der Schnitt läuft danach **an
  der Nutzlast**, nicht an der Fachlogik — und was nicht in der Schnittstelle
  steht (Fehlercodes, Adressen, Laufzeiten), ist die eigentliche Aussage.
- **`data_get()` liest den Punkt als Pfad.** Bei Schlüsseln, die selbst einen
  enthalten — `@odata.deltaLink`, `@odata.nextLink` — sucht es ein Feld
  `deltaLink` unter `@odata`, findet nichts und wirft nichts. Dort direkt auf
  das Array zugreifen. Der Fehler erzeugt keinen Ausfall, sondern einen Sync,
  der stillschweigend bei jedem Lauf von vorn anfängt.
- **Ein Schutz, der an einer einzigen Abfrage hängt, ist keiner.** Die
  Eigenmarkierung ausgehender Kalendereinträge (R1) wird bei einem Anbieter
  gar nicht zurückgeliefert; der Abgleich gegen die selbst geschriebenen
  Kennungen ist deshalb die zweite Hälfte derselben Regel.

## Statik und Format

PHPStan Stufe 8, in der CI erzwungen (Entscheidung S10). Ein Befund wird
behoben, nicht nach `ignoreErrors` verschoben. Ausgenommen ist allein Code, den
ein Paket veröffentlicht hat und den wir nicht schreiben.

Pint mit `declare_strict_types`, `strict_comparison`, `strict_param`. Prettier
und ESLint für das Frontend, `vue-tsc` für die Typen.

## Was noch offen ist

- Das Dashboard zeigt noch die Platzhalter des Starter-Kits. Was dort steht,
  entscheidet sich mit WP-32.
- Ein Bauteil für Hinweise und Bestätigungen (Toast) fehlt. Bisher meldet die
  Oberfläche Fehler nur am Formularfeld. Die Kalenderseite (WP-14) zeigt
  `flash.erfolg` und `flash.fehler` als Band über der Tabelle — beides steht
  seitdem in jeder Inertia-Antwort und wartet auf ein gemeinsames Bauteil.
