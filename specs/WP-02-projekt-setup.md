# WP-02 · Projekt-Setup

## Ziel
Ein Gerüst, auf dem WP-03 ohne Nacharbeit aufsetzen kann, mit einem
Qualitätstor, das von Anfang an rot werden kann.

## Vorher lesen
- `specs/README.md`
- `CLAUDE.md`

## Voraussetzungen
Keine. Läuft parallel zu WP-00 (Meta App Review), das außerhalb des
Repositories startet.

## Getroffene Entscheidungen

| Entscheidung | Begründung |
|---|---|
| Laravel 12 auf PHP 8.4 | — |
| Inertia 2 + Vue 3 + TypeScript | volle Kontrolle über die Oberfläche; die Buchungsseite (WP-12) und die Inbox (WP-21) sind keine CRUD-Masken |
| MySQL 8.4 | Laradock und CI fahren dieselbe Fassung |
| Redis für Queue und Cache | WP-33 braucht getrennte Queues (`realtime`, `default`, `sync`, `maintenance`) mit eigenen Worker-Profilen; der Datenbanktreiber trägt das nicht |
| **Tests gegen MySQL, nicht gegen SQLite** | Sperrverhalten, zusammengesetzte Unique-Indizes und JSON-Abfragen sind der Kern der Abnahme von WP-10 und WP-25 |
| `CarbonImmutable` als Standard | ein in-place verändertes Zeitobjekt verschiebt in einem Terminprodukt stillschweigend eine Slot-Grenze |
| `Model::shouldBeStrict()` außerhalb Produktion | N+1, verschluckte Attribute und Zugriffe auf nicht vorhandene Felder fallen beim Entwickeln auf, nicht in Produktion |
| **Kein `Model::unguard()`** | ab WP-03 hängt an jedem Modell eine `organization_id`; ein Massenzuweisungsfehler darauf ist ein Mandantenleck |
| `DB::prohibitDestructiveCommands()` in Produktion | — |
| PHPStan **Stufe 8** (Entscheidung S10) | in der CI erzwungen |
| Pint mit `declare_strict_types`, `strict_comparison`, `strict_param` | — |
| **Betrieb vollständig in Laradock** | nginx, php-fpm 8.4, MySQL 8.4, Redis, Mailhog und der Workspace laufen dort bereits; ein zweiter Satz eigener Container hätte dieselben Dienste doppelt gefahren und um dieselben Ports gestritten |
| Hostnamen `mrs-beauty.test`, Site-Konfiguration in `laradock/nginx/sites/mrs-beauty.conf` | folgt der Konvention der übrigen Projekte dort |
| Vite im Workspace-Container, Port 5173 | `WORKSPACE_VITE_PORT` ist in Laradock bereits veröffentlicht |
| `phpredis` statt `predis` | in workspace und php-fpm vorhanden |
| Eigenes Redis-Präfix und eigene Datenbanknummern (3 und 4) | Laradocks Redis wird mit allen anderen Projekten geteilt |
| Horizon, Pulse, Sentry (Entscheidung S9) | Horizon bildet die vier Queues aus WP-33 mit eigenen Profilen ab |
| Session auf Redis (Entscheidung S8) | — |
| `DATETIME` statt `TIMESTAMP` (Entscheidung A7) | 2038-Grenze und implizite Zeitzonenkonvertierung; durchgesetzt durch einen Schematest, nicht nur dokumentiert |
| Fachliche Konstanten in `config/mrs.php`, mit Fundstelle | verhindert, dass ein Schwellwert aus einer Spezifikation als Magic Number in einer Klasse landet |

## Schritte

1. Laravel 12 mit Vue-Starter-Kit, Git-Repository angelegt.
2. `.env.example` um alle Verbindungen ergänzt, die spätere Pakete brauchen
   (Meta, Google, Microsoft, Agent) — leer, aber benannt, damit niemand sie
   später erfindet.
3. `config/mrs.php` mit den fachlichen Konstanten aus den Spezifikationen.
4. `AppServiceProvider` gehärtet (siehe Tabelle oben).
5. Testlauf auf MySQL umgestellt, Beispieltests entfernt.
6. PHPStan, Pint, Prettier, ESLint und `vue-tsc` eingerichtet und
   durchlaufen lassen.
7. CI: zwei Workflows, einer für Tests und Statik gegen MySQL-8.4- und
   Redis-Services — dieselbe MySQL-Fassung wie in Laradock —, einer für
   Format und Lint — **prüfend, nicht korrigierend**, damit die CI rot wird
   statt still in den Branch zu schreiben.
8. Fehlende Basisdokumente rekonstruiert, jede Annahme markiert.
9. Auf Laradock umgebaut: Site-Konfiguration, Servicenamen in `.env`,
   Vite im Workspace-Container, Redis mit Passwort, Präfix und eigenen
   Datenbanknummern.
10. `docs/entscheidungen.md` nachgezogen, sobald die verbindliche Fassung
    vorlag, und die daraus folgenden Punkte umgesetzt (S8, S9, A7, C7, D4).
11. PHPStan von Stufe 6 auf **Stufe 8** gezogen und die 25 Befunde abgearbeitet
    (Entscheidung S10).

## Abnahmekriterien

- [x] `composer check` läuft durch: Pint, PHPStan, Pest.
- [x] `npm run format:check`, `npx eslint .`, `npx vue-tsc --noEmit` laufen fehlerfrei.
- [x] `npm run build` erzeugt Assets.
- [x] Der Testlauf spricht MySQL an, nicht SQLite.
- [x] Beide CI-Workflows bilden denselben Ablauf ab wie lokal.
- [x] `http://mrs-beauty.test` wird von Laradocks nginx ausgeliefert.
- [x] Der Vite-Dev-Server im Container ist vom Host erreichbar und gibt per
      CORS ausschließlich `http://mrs-beauty.test` frei.
- [x] Ein Job läuft über die Queue `realtime` durch Redis und wird verarbeitet.
- [x] Cache-, Session- und Queue-Schlüssel liegen unter eigenem Präfix in
      eigenen Redis-Datenbanken.
- [x] `/horizon` und `/pulse` antworten.
- [x] PHPStan **Stufe 8** ohne Befunde (Entscheidung S10).
- [x] Ein Benutzer ohne bestätigte E-Mail-Adresse kommt nicht auf das Dashboard.

## Nicht in diesem Paket

Mandantenfähigkeit, Verschlüsselung, Benutzer und Rollen. Das ist WP-03 und
WP-04. Der Starter-Kit-Login bleibt bis dahin unangetastet und wird in WP-04
ersetzt.

Queue-Betrieb im engeren Sinn — getrennte Queues, Worker-Profile,
Wiederholungsstrategien, Überwachung — gehört zu WP-33 und wächst mit.

## Was die Stufe 8 zutage gefördert hat

Der Sprung war nicht nur Typkosmetik. Drei Befunde waren echte Mängel:

1. **`User` deklarierte `MustVerifyEmail` nicht**, obwohl Routen, Controller,
   Tests und das `verified`-Middleware auf dem Dashboard vollständig vorhanden
   waren. Das Middleware lief wirkungslos durch, der `Registered`-Listener
   verschickte keine Bestätigungsmail, und `new Verified($user)` verletzte den
   Typvertrag des eigenen Ereignisses. Die Methoden waren über den Trait in
   `Illuminate\Foundation\Auth\User` die ganze Zeit da — es fehlte allein die
   Deklaration. Jetzt gesetzt, mit einem Test, der es festhält.
2. **`HandleInertiaRequests::share()` rief `parent::share()` zweimal auf** und
   spreizte das Ergebnis zusätzlich in ein `array_merge`. Beim Zitat fehlte der
   Rückfall: liefert `explode('-')` nur einen Teil, war `$author` null und
   `trim()` brach ab.
3. **`env()` kann `bool` liefern** — die Werte `true`, `false` und `null` werden
   beim Lesen der `.env` umgewandelt. An acht Stellen ging das Ergebnis
   ungeprüft in `explode()`, `Str::slug()` oder `parse_url()`. Ein
   `APP_NAME=false` wäre ein Laufzeitfehler gewesen, kein Analysebefund.

Der Rest waren 15 Stellen, an denen `$request->user()` als `User|null` gilt,
obwohl die Route hinter `auth` liegt. Durchgängig mit einer lokalen Variablen
und `assert($user instanceof User)` eingegrenzt — das grenzt nicht nur für die
Analyse ein, sondern prüft beim Entwickeln auch tatsächlich.

## Fallstricke

- **Der Starter-Kit-Code war gegen eine Inertia-2-Beta geschrieben.** Gegen
  die stabile Fassung brach der TypeScript-Lauf an vierzehn Stellen. Dabei
  kam ein echter Laufzeitfehler heraus: `NavMain` erwartete ein Feld `url`,
  während jeder Aufrufer `href` übergab — die Sidebar-Links waren leer. Wer
  `vue-tsc` aus der CI nimmt, um schneller anzufangen, verliert genau solche
  Funde.
- **`SharedData` muss Inertias `PageProps` erweitern**, sonst weist
  `usePage<SharedData>()` den Typ zurück.
- **Die Hostnamen in `.env` sind Docker-Servicenamen** (`mysql`, `redis`,
  `mailhog`) und lösen nur im Docker-Netz auf. Ein `php artisan` aus einem
  Terminal auf dem Host heraus scheitert an der Datenbankverbindung — das ist
  kein Fehler, sondern die Folge der Entscheidung. Alles läuft im
  Workspace-Container.
- **`node_modules` ist über den Bind-Mount geteilt.** Ein Teil der Pakete
  liefert plattformabhängige Binärdateien (esbuild, rollup). Ein `npm install`
  auf dem macOS-Host und ein Lauf im Linux-Container schließen sich
  gegenseitig aus. Installiert wird im Container.
- **Vite braucht `host: '0.0.0.0'` und Polling.** Ohne das erste lauscht der
  Dev-Server nur auf dem Loopback des Containers, ohne das zweite bemerkt er
  keine Änderung, die auf dem Host gespeichert wird.
- **Laradocks Redis verlangt ein Passwort** (`secret_redis`). Das fiel im
  Testlauf nicht auf, weil `phpunit.xml` Cache und Session auf `array` und die
  Queue auf `sync` setzt — Redis wird dort nie angefasst. Grüne Tests und eine
  laufende Anwendung sind hier zwei verschiedene Aussagen. Ein Aufruf der
  Seite gehört nach jeder Änderung an den Verbindungen dazu.
- **Ein geteiltes Redis ohne Präfix und ohne eigene Datenbanknummer** lässt
  Jobs dieses Projekts im Worker eines anderen landen.
- **`CACHE_PREFIX` bleibt leer.** Redis-Präfix und Cache-Präfix werden
  aneinandergehängt; sonst steht `mrs_beauty_` zweimal im Schlüssel.
- **Die veröffentlichte Pulse-Migration ist von der Analyse ausgenommen.**
  Fremder Code, den wir nur in das Repository kopiert haben. Für eigene
  Migrationen gilt die Ausnahme nicht.
