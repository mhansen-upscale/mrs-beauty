# Mrs. Beauty

Werbung, Kommunikation und Termine für Praxen für ästhetische Behandlungen in
einem System — mit einer durchgehenden Kette von der Anzeige bis zum Umsatz
und einer HWG-Prüfung vor jeder Veröffentlichung.

## Wo was steht

| Pfad | Inhalt |
|---|---|
| `CLAUDE.md` | Sechs Arbeitsregeln. Stehen über jeder Abwägung. |
| `specs/README.md` | Index der 34 Arbeitspakete. Eine Session, ein Paket. |
| `specs/WP-*.md` | Briefing je Paket |
| `docs/fachlogik/` | Verbindliche Spezifikationen: Verfügbarkeit, Agent, Warteliste, Attribution |
| `docs/integrationen/` | Leitfäden zu Meta und Kalendersync |
| `docs/produkt.md` | Produktbild, Differenzierungsmerkmal |
| `docs/datenmodell.md` | Entitäten und ihre belegten Felder |
| `docs/entscheidungen.md` | Nummerierte Festlegungen (S…, A…, D…, P…, G…, C…, B…) |
| `docs/konventionen.md` | Benennung, Oberfläche, Tests, Statik |
| `config/mrs.php` | Fachliche Konstanten mit Fundstelle |

**Vor dem Beginn eines Pakets ist der Abschnitt „Vorher lesen" des Briefings
verbindlich** — nicht als Empfehlung, sondern als Kontextgrenze.

## Stack

| | |
|---|---|
| Laufzeit | Laradock — nginx, php-fpm 8.4, workspace |
| Laravel | 12 |
| Frontend | Inertia 2, Vue 3.5, TypeScript, Tailwind 3, Vite 6 |
| Datenbank | MySQL 8.4 (Laradock-Service `mysql`) |
| Queue, Cache, Session | Redis über phpredis (Laradock-Service `redis`), eigene Datenbanknummern |
| Betrieb | Warteschlange von Laravel Cloud, Pulse, Sentry (`docs/betrieb.md`) |
| Tests | Pest, **gegen MySQL**, nicht gegen SQLite |
| Statik | PHPStan/Larastan, **Stufe 8** (Entscheidung S10) |
| Format | Pint (PHP), Prettier + ESLint (Frontend) |

## Einrichten

Das Projekt läuft vollständig in **Laradock**. Auf dem Host wird nichts
installiert — kein PHP, kein Node, kein MySQL.

Voraussetzung: das Laradock-Verzeichnis liegt neben diesem Projekt
(`APP_CODE_PATH_HOST=../`), sodass das Projekt im Container unter
`/var/www/mh-mrs-beauty` erscheint.

**1 · Dienste starten**

```bash
cd ../laradock && docker compose up -d nginx mysql redis mailhog workspace
```

**2 · Hostnamen eintragen** (einmalig, braucht Administratorrechte):

```bash
echo "127.0.0.1 mrs-beauty.test" | sudo tee -a /etc/hosts
```

**3 · Ab hier alles im Workspace-Container.** Eine Sitzung öffnen:

```bash
docker compose exec --workdir /var/www/mh-mrs-beauty workspace bash
```

```bash
composer install && npm ci
cp .env.example .env && php artisan key:generate
```

**4 · Datenbanken anlegen** — eine für die Entwicklung, eine für Tests:

```bash
mysql -h mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS mrs_beauty CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS mrs_beauty_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

```bash
php artisan migrate
```

**5 · nginx** kennt die Seite bereits über
`laradock/nginx/sites/mrs-beauty.conf`. Nach einer Änderung daran:

```bash
docker compose exec nginx nginx -s reload
```

## Entwickeln

Im Workspace-Container:

```bash
npm run dev
```

```bash
php artisan queue:listen --queue=realtime,default,sync,maintenance --tries=1
```

```bash
php artisan schedule:work
```

Lokal genügt **ein** Arbeiter über alle vier Warteschlangen, in der
Reihenfolge ihres Vorrangs: `realtime` für eingehende Webhooks und
Agent-Läufe, `default` für alles Übrige, `sync` für Kalender- und
Meta-Abgleich, `maintenance` für Aufbewahrung, Aggregation und
Bilderzeugung. Im Betrieb läuft je Warteschlange ein eigener Arbeiter mit
eigenem Profil (`docs/betrieb.md`) — Horizon ist seit dem 22.09.2026 nicht
mehr im Projekt. Der Scheduler stößt die geplanten Läufe an (Erinnerungen,
Warteliste, Abgleiche, Abrechnung des Service-Fensters).

| Dienst | Adresse vom Host aus |
|---|---|
| Anwendung | http://mrs-beauty.test |
| Pulse | http://mrs-beauty.test/pulse |
| Vite-Dev-Server | http://localhost:5173 |
| Mailhog | http://localhost:8025 |
| MySQL | 127.0.0.1:3306 (`root` / `root`) |
| Redis | 127.0.0.1:6379 (Passwort `secret_redis`) |

Der Browser lädt die Anwendung über nginx unter `mrs-beauty.test`, die Assets
im Entwicklungsbetrieb dagegen vom Dev-Server unter `localhost:5173`. Das sind
zwei verschiedene Origins — `vite.config.ts` gibt genau `http://mrs-beauty.test`
per CORS frei und setzt den HMR-Host auf `localhost`.

**Dateiüberwachung läuft über Polling.** Änderungen, die in PhpStorm auf dem
Host gespeichert werden, erreichen den Container sonst nicht zuverlässig.

## Qualitätstor

Vollständig, so wie es die CI fährt — im Workspace-Container:

```bash
composer check
```

`composer test` ruft `vendor/bin/pest` **ohne Argumente** auf, genau wie die
CI: alle Suiten aus `phpunit.xml`, also `tests/Feature` und `tests/Parallel`.

Einzeln:

```bash
composer test
```

```bash
composer analyse
```

```bash
composer lint
```

Frontend:

```bash
npm run format:check && npx eslint . && npx vue-tsc --noEmit
```

**`npm` läuft im Container**, nicht auf dem Host: `node_modules` enthält
Linux-Binärdateien (Rollup, esbuild), die unter macOS fehlen.

```bash
npm run build
```

Der Build prüft sich danach selbst: `scripts/pruefe-build.mjs` vergleicht die
Seiten unter `resources/js/pages` mit dem Manifest. Der Glob der Seiten hat
über den Bind-Mount schon zweimal leer aufgelöst — der Build meldet dann
Erfolg, ist auffällig schnell, und im Browser steht „Page not found:
./pages/auth/Login.vue" auf einer schwarzen Seite. Hilft meistens: noch einmal
bauen.

Vom Host aus in einem Rutsch, ohne Sitzung im Container:

```bash
docker compose -f ../laradock/docker-compose.yml exec --workdir /var/www/mh-mrs-beauty workspace composer check
```

## Mandantentrennung

Jede Tabelle mit Nutzdaten trägt eine `organization_id`, jedes zugehörige
Modell erbt von `TenantModel`, und ein Architektur-Test setzt beides durch.
MySQL bietet keine Row Level Security — diese Kombination ist der **einzige**
strukturelle Schutz.

**Ohne gesetzten Mandanten wirft jede Abfrage**, sie liefert nicht etwa ein
leeres Ergebnis. Wer mandantenübergreifend lesen muss, schreibt das hin:

```bash
php artisan tinker
```

```php
app(App\Tenancy\TenantContext::class)->acrossTenants('Warum ich quer lese', fn () => /* ... */);
```

Der Ausstieg gilt nur innerhalb des Aufrufs und endet auch bei einer Ausnahme.
**Die Begründung ist Pflicht** und landet im Protokoll — ohne sie wirft der
Aufruf.

Personenbezogene Felder liegen feldverschlüsselt, mit einem eigenen Schlüssel
je Organisation (Envelope Encryption). Suchbar sind sie nur über blinde
Indizes und nur auf Gleichheit. Einzelheiten in `docs/datenmodell.md`,
Abschnitt 0.

## Zugang

Der übliche Weg ins Produkt ist die **Einladung**: `Einstellungen → Team`.
Offene Selbstregistrierung ist standardmäßig aus und legt, wenn eingeschaltet,
Organisation und Inhaberin gemeinsam an:

```bash
REGISTRATION_SELF_SERVICE=true
```

Rollen und ihre Fähigkeiten stehen in `app/Enums/Role.php` und
`app/Enums/Ability.php` — fest verdrahtet, kein Rechteeditor.

Demodaten für die Entwicklung:

```bash
php artisan migrate:fresh --seed
```

Danach `inhaberin@demo.test` / `passwort`, dazu je ein Zugang pro Rolle und
ein Super-Admin `support@mrs-beauty.test`.

## Protokoll und Impersonation

`audit_logs` ist **append-only** — zwei Datenbank-Trigger weisen `UPDATE` und
`DELETE` ab, auch an Eloquent vorbei. Der Aufbewahrungsjob aus WP-18 löscht
über eine benannte Sitzungsvariable, sonst niemand.

**Im Protokoll stehen keine Klartext-Personendaten.** Es hält fest, wer wann
was an welchem Datensatz getan hat, und nennt die geänderten Felder — nicht
ihre Werte. Ein Modell darf einzelne Felder als unbedenklich erklären
(`auditableValues()`); die Vorgabe ist, nichts mitzuschreiben.

Wer an der Mandantentrennung vorbei lesen muss, tut das über den benannten
Kanal — er verlangt eine Begründung und protokolliert sich selbst:

```php
app(App\Tenancy\TenantContext::class)->acrossTenants('Wartungsfall 4711', fn () => /* ... */);
```

`withoutGlobalScope()` ist im Anwendungscode untersagt und wird von einem Test
abgewiesen.

**Impersonation ist standardmäßig maskiert.** Ein Super-Admin sieht die Praxis
wie eine Inhaberin, personenbezogene Werte sind ersetzt. Vollzugriff gibt es
nur nach Freigabe durch eine Inhaberin des betroffenen Mandanten, befristet und
protokolliert — und **der Support kann sich nicht selbst freigeben**.

Demozugang: `support@mrs-beauty.test` / `passwort`. Die Oberfläche zur
Mandantenauswahl gehört zu WP-34.

## Praxisstammdaten

`Einstellungen → Standorte` und `→ Behandler`. Drei der elf Bedingungen der
Verfügbarkeit (WP-10) entstehen hier: Arbeitszeitfenster, Abwesenheit,
Schließzeit.

**Die Zeitzone hängt am Standort** (Entscheidung A8), nicht an der
Organisation — eine Praxisgruppe ist ein Mandant mit mehreren Standorten, und
die können in verschiedenen Zonen liegen. Daraus folgt eine Zweiteilung:

| Art | Speicherung |
|---|---|
| Wiederkehrende Arbeitszeit | Wochentag plus **Ortszeit**, ausgewertet in der Zone des Standorts |
| Abwesenheit, Schließzeit | absoluter Zeitpunkt in **UTC** |

Eine Arbeitszeit „montags 9 bis 17 Uhr" ist keine Zeitspanne, sondern eine
Regel. In UTC gespeichert stünde sie nach der Zeitumstellung eine Stunde
daneben.

Die fachliche Abfrage heißt `Practitioner::arbeitetAm($zeitpunkt, $standort)`
und fasst alle drei Bedingungen zusammen.

## Leistungskatalog

`Einstellungen → Behandlungen` und `→ Terminarten`.

| | |
|---|---|
| **Behandlung** | Was die Praxis anbietet. Trägt Preis und `avg_revenue_cents`. |
| **Terminart** | Was gebucht wird. Trägt Dauer, Rüstzeit und Vorlauf. |

**Rüstzeit belegt, sie zeigt nicht.** Dem Kontakt wird die Dauer angezeigt,
der Kalender belegt Rüstzeit davor plus Dauer plus Rüstzeit danach. Wer beides
vermischt, verschiebt jede Terminanzeige.

**Der Katalog ist auch eine Sperrliste.** `Treatment::aktiveNamen()` hat zwei
Aufrufer mit entgegengesetzter Absicht: der Agent prüft damit, was er sagen
darf (WP-22), und die Attribution prüft damit, was **nie** an Meta gehen darf
(WP-32, Regel 2). Wer den Katalog erweitert, erweitert beides.

`avg_revenue_cents` ist Pflicht (Entscheidung D14) und ausdrücklich eine
**Schätzung**, kein abgerechneter Umsatz.

## Verfügbarkeit

MySQL kennt keine Exclusion Constraints. Der Ersatz ist die Materialisierung:
`appointment_slots` hält eine Zeile je Behandler und 5-Minuten-Schritt, und ein
Unique-Index auf `(practitioner_id, starts_at)` macht Doppelvergabe zu einem
Datenbankfehler statt zu einem Fachfehler, den niemand bemerkt.

```bash
php artisan mrs:slots-erzeugen
```

Läuft nächtlich um 03:15, ist idempotent und entfernt nur **freie** Slots,
deren Arbeitszeit gestrichen wurde.

**Die Erzeugung schreitet UTC ab, nicht Ortszeit.** Das ist die zentrale
Entscheidung: UTC nach Ortszeit ist immer eindeutig, die Gegenrichtung nicht.
Damit lösen sich beide Zeitumstellungen ohne Sonderfall — in der
Sommerzeitlücke entstehen keine Slots, in der doppelten Winterzeitstunde
entstehen sie zweimal.

**Ein abgelaufener Hold ist sofort abgelaufen.** Jede Abfrage vergleicht gegen
die Uhr; der Aufräumjob gibt nur Zeilen frei und entscheidet nichts.

Die Nebenläufigkeitstests liegen in `tests/Parallel` und laufen **ohne**
`RefreshDatabase` — siehe unten.

## Termine

Die erste Arbeitsoberfläche des Produkts liegt unter `/termine`: Tagesansicht
je Standort, Spalten je Behandler, anlegen, verschieben, absagen, Status
pflegen.

**Übersteuern hebt nicht alles auf.** Die elf Bedingungen der Verfügbarkeit
zerfallen in zwei Gruppen, und nur eine davon ist übersteuerbar:

| Gruppe | Bedingungen | Aussage | übersteuerbar |
|---|---|---|---|
| Angebot | V1, V2, V3, V7, V8, V9, V10 | *soll* angeboten werden | ja |
| Belegung | V4, V5, V6, V11 | ist **schon vergeben** | nein |

Die Empfangskraft, die der Stammkundin um 18:30 noch einen Termin gibt, obwohl
um 18:00 Feierabend ist, tut etwas Richtiges. Eine belegte Zeit doppelt zu
vergeben nicht — dafür gibt es keinen Schalter. Außerhalb der Arbeitszeit
existieren gar keine Slot-Zeilen; die Übersteuerung legt sie über
`insertOrIgnore` an, damit der Unique-Index Schiedsrichter bleibt.

**Verschieben ist dieselbe Zeile.** Nicht absagen und neu anlegen:
Erinnerungen, Kalendersync und Attribution hängen an der Identität des
Termins. Alte und neue Strecke dürfen sich überlappen — für diesen Termin sind
seine eigenen Zeilen frei, für jeden anderen nicht.

**Eine Absage gibt die Zeit frei und ist endgültig**, „Erschienen" und „Nicht
erschienen" behalten ihre Slots. Ein abgesagter Termin bleibt in der Tabelle;
ohne ihn gäbe es keine Absage- und keine No-Show-Quote.

**Kontakte liegen verschlüsselt**, gesucht wird über blinde Indizes und nur
exakt: „Mül" findet nichts, „Müller" findet alle Müllers. Das ist Entscheidung
P8, kein Versäumnis, und steht als Hinweis in der Oberfläche.

**Kein Notizfeld am Termin.** Ein Freitext dort füllt sich mit
Behandlungsverläufen; Entscheidung P1 schließt Behandlungsdokumentation aus,
weil sonst § 630f BGB greift. Notizen mit Zweckbindung gehören zu WP-18.

## Oberfläche

**Farben:** `docs/design/farben.md`, verbindlich. Zwei Systeme — der
Admin-Bereich trägt unsere Marke (Petrol, `--primary: 178 50% 24%`), die
Buchungsseite später die der Praxis. Die vier Semantikfarben sind in beiden
gesperrt: ein grünes „bestanden" in der HWG-Ampel wäre mehrdeutig, wenn die
Praxis eine grüne Markenfarbe hätte.

Keine festen Farbwerte im Code, weder als Hex noch als Tailwind-Palette.
Durchgesetzt durch `tests/Feature/Design/FarbenTest.php`.

Der neutrale Ton ist warm (Tailwind `stone`). Das ist eine Entscheidung, keine
Nachlässigkeit: ästhetische Praxen arbeiten oft mit Blush, Creme und
Roségold — ein kühles Grau bekämpft diese Töne sichtbar, ein warmes trägt sie.

**Kein Dunkelmodus**, vorerst. Whitelabel und Dunkelmodus verdoppeln die
Kontrastprobleme.

**Navigation:** Arbeitsbereiche stehen in der Hauptnavigation — Termine,
Standorte, Behandler, Behandlungen, Terminarten, Team, Protokoll. Unter
Einstellungen steht nur, was die eigene Person betrifft.

**Sprache: nur Deutsch** (Entscheidung P7). Unsere Texte stehen in den
Komponenten. Was Laravel erzeugt — Validierung, Anmeldefehler, Mails — liegt
in `lang/de`; die beiden Mails der Anmeldestrecke sind in
`AppServiceProvider::configureMails()` ersetzt statt übersetzt.
Durchgesetzt durch `tests/Feature/Auth/DeutschTest.php`.

**Listen sind `DataTable`** mit Suche, Sortierung und Blättern.
**Formulare sind Dialoge** (`FormularDialog`), keine aufklappenden Abschnitte.
**Zeilenaktionen sind Symbole** (`AktionsButton`) mit Tooltip und
`aria-label`.

## Öffentliche Buchungsseite

`/buchen/{praxis-slug}` — die einzigen Routen ohne Anmeldung.

**Der Mandant kommt aus dem Slug und nur von dort** (`ResolvePublicTenant`).
Jede ID, die von außen hereinkommt, wird innerhalb dieses Mandanten gesucht;
eine gültige UUID aus einer fremden Praxis findet nichts. Eine gesperrte
Organisation hat keine Buchungsseite.

**Erst halten, dann das Formular.** Andersherum füllt die Interessentin zwei
Minuten lang Felder aus und bekommt danach „inzwischen vergeben". Der Hold
läuft 10 Minuten, die Seite zeigt die Restzeit — und **die Reservierung steht
in der Sitzung, nicht im Formular**.

**Kein Preis, keine Behandlungsbeschreibung, kein Freitextfeld.** Die ersten
beiden warten auf die HWG-Prüfung (WP-30). Das dritte bleibt dauerhaft weg:
ein „Ihr Anliegen" auf einer öffentlichen Seite füllt sich mit
Gesundheitsdaten nach Art. 9 DSGVO, abgegeben, bevor jemand eingewilligt hat.
Der Behandlungswunsch läuft über die Terminart (Entscheidung D2).

**Die Markenfarbe wird über OKLCH abgeleitet** (`App\Support\Markenstil`):
Farbton und Chroma bleiben, nur die Helligkeit wandert — eine HSL-Ableitung
bleicht gesättigte Töne aus. Der Erzeuger gibt ausschließlich `--primary`,
`--primary-foreground` und `--ring` aus; die Semantikfarben sind gesperrt.
Erreicht Weiß darauf keine 4,5:1, wird abgedunkelt, bis es das tut — geprüft
am **gerundeten** Token, denn genau der landet im Browser.

## Erinnerungen und Bestätigungen

```bash
php artisan mrs:erinnerungen-versenden
```

Läuft alle fünf Minuten, **stellt nur ein** und verschickt nichts selbst — der
Versand läuft über die Queue `default` (Regel 4).

**„Genau einmal" ist ein Index, kein Vorsatz.** `appointment_notifications`
trägt einen Unique über (`appointment_id`, `kind`), und der Versand
beansprucht seine Zeile über ein bedingtes `UPDATE … WHERE sent_at IS NULL`.
Nur wer die Zeile bekommt, schickt. Ein Job, der zweimal läuft, ist nach einem
Deploy der Normalfall.

**Die Betreffzeile nennt keine Behandlung.** Sie steht als Vorschau auf einem
Sperrbildschirm, den auch andere sehen: „Ihr Termin am 17. September" statt
„Erinnerung: Erstberatung Botox". Im Text steht sie — dort ist sie nötig.

**Verschieben plant die Erinnerung neu, Absagen löscht sie.** Und nichts geht
in die Vergangenheit raus: stand der Job, ist eine überfällige Erinnerung
wertlos.

**Kein Kontaktweg ist eine Information.** Ein Termin ohne E-Mail-Adresse
erzeugt eine Zeile mit Grund `no_channel`, sichtbar in der Terminansicht —
sonst fällt erst auf, dass niemand erinnert wurde, wenn jemand nicht
erscheint.

Vorerst nur E-Mail: WhatsApp und SMS kosten Geld je Nachricht (B7, B8) und
setzen die Meta-Anbindung voraus (WP-19, WP-20).

## Warum Tests gegen MySQL laufen

Die Verfügbarkeits-Engine (WP-10) und die Warteliste (WP-25) verlassen sich
auf Sperrverhalten bei parallelen Buchungen, auf zusammengesetzte
Unique-Indizes und auf JSON-Abfragen. SQLite bildet das anders oder gar nicht
ab. Ein grüner Test auf SQLite wäre genau an den Stellen wertlos, an denen es
darauf ankommt — die Testfälle „zwei gleichzeitige Buchungen auf denselben
Slot" und „zwei Annahmen auf denselben Slot" sind der Kern der Abnahme.

Aus demselben Grund gibt es eine zweite Testsuite: **`tests/Parallel` läuft
ohne `RefreshDatabase`.** Dessen umschließende Transaktion macht eine zweite
Datenbanksitzung blind für alles, was die erste noch nicht festgeschrieben hat
— eine Sperre ließe sich damit nur gegen sich selbst prüfen, und die hält
immer. Diese Tests räumen selbst auf und brauchen die Verbindung
`mysql_zweit`.

```bash
./vendor/bin/pest --testsuite=Parallel
```

## Umgebungsvariablen mit fachlicher Wirkung

| Variable | Wirkung |
|---|---|
| `APP_BUSINESS_TIMEZONE` | Rückfall für Standorte ohne eigene Zeitzone. Gespeichert wird immer UTC. |
| `META_API_VERSION` | Festgenagelt, nie aus dem SDK. Halbjährliche Prüfung gehört zum Betrieb. |
| `AGENT_KILL_SWITCH` | Not-Aus auf Installationsebene, überstimmt jede Mandanteneinstellung. |
| `APP_KEY` | **Key Encryption Key.** Umschließt den Schlüssel jeder Organisation. Geht er verloren, sind alle verschlüsselten Felder aller Mandanten unlesbar. |
| `REDIS_PREFIX`, `REDIS_DB`, `REDIS_CACHE_DB` | Laradocks Redis wird mit allen anderen Projekten geteilt. Ohne eigenes Präfix und eigene Datenbanknummern laufen Cache- und Queue-Schlüssel ineinander. |

Alles Weitere steht in `config/mrs.php`, jeweils mit Fundstelle.
