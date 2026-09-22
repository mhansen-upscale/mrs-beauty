# Datenmodell

> **Rekonstruktion — inzwischen überholt.** `docs/entscheidungen.md` liegt
> mittlerweile in verbindlicher Fassung vor und legt in den Abschnitten
> Architektur und Datenmodell (A1–A14, D1–D14) mehr fest, als hier steht.
> Die Briefings verweisen zudem auf **Abschnitt 0** (Konsequenzen von MySQL 8)
> und **Abschnitt 11** (Notizen, Anhänge, Einwilligungen), die hier fehlen.
> Dieses Dokument ist damit ein Platzhalter bis zur echten Fassung.
>
> **Rekonstruktion.** Dieses Dokument fehlte im Repository, wird aber von
> `specs/WP-30-hwg-compliance.md` (Abschnitt 9) referenziert. Es ist hier
> **nicht erfunden**, sondern aus den Tabellen- und Feldnamen zusammengetragen,
> die die vorhandenen Spezifikationen bereits namentlich festlegen. Namen aus
> den Spezifikationen stehen in `code`. Ergänzungen, die zum Verständnis nötig
> waren, sind mit ▲ markiert und vor dem jeweiligen Paket zu bestätigen.

## 0 · Konsequenzen von MySQL 8

> Geschrieben im Zuge von WP-03. Hält fest, wie die Entscheidungen A4, A5, A6,
> A9, A10 und A11 konkret umgesetzt sind — und warum sie überhaupt nötig
> wurden. **Von dir zu prüfen.**

MySQL 8 ist gesetzt (Entscheidung S5). Es fehlen ihm vier Dinge, die
PostgreSQL mitbringt, und für jedes davon steht hier der Ersatz.

### 0.1 Keine Row Level Security → `TenantModel`

PostgreSQL könnte die Mandantentrennung in der Datenbank erzwingen. MySQL
nicht. Der einzige strukturelle Schutz ist deshalb die Anwendung
(Entscheidung A3):

- `TenantModel` als Basisklasse mit einem Global Scope.
- **Ohne Mandantenkontext wirft jede Abfrage.** Ein leeres Ergebnis wäre die
  gefährlichere Voreinstellung — es sieht aus wie „keine Daten" und wird als
  Fachfehler gesucht.
- Ein Architektur-Test prüft, dass jedes Modell mit `organization_id` von
  `TenantModel` erbt und jede solche Tabelle den zusammengesetzten
  Fremdschlüssel trägt.

Die Grenze dieses Schutzes gehört dazu: Er greift auf Eloquent. Ein
`DB::table(...)` umgeht ihn vollständig, und kein Test sieht das.

### 0.2 Keine Exclusion Constraints → `appointment_slots`

PostgreSQL könnte Überschneidungen von Zeiträumen auf Datenbankebene
ausschließen. MySQL nicht. Ersatz ist die Materialisierung in einem
5-Minuten-Raster mit einem Unique-Index je Ressource und Startzeit
(Entscheidung A9, ausführlich in `docs/fachlogik/verfuegbarkeit.md`).

Rüstzeiten gehören zur belegten Strecke, nicht zur angezeigten Terminzeit.

### 0.3 Keine partiellen Indizes → generierte Spalten

PostgreSQL kennt `CREATE UNIQUE INDEX … WHERE status = 'pending'`. MySQL
nicht. Ersatz ist eine generierte Spalte, die außerhalb des betroffenen
Zustands `NULL` ist (Entscheidung A10) — `NULL` wird von einem Unique-Index
nicht verglichen:

```sql
offer_guard VARCHAR(64) GENERATED ALWAYS AS (
    CASE WHEN status = 'pending' THEN CONCAT(waitlist_entry_id) ELSE NULL END
) STORED,
UNIQUE KEY (offer_guard)
```

Das ist die Bedingung K9 der Warteliste — kein offenes zweites Angebot je
Eintrag — als Datenbankregel statt als Prüfung im Code.

**Eine Falle, die in WP-03 zugeschnappt ist:** MySQL verbietet `ON DELETE
CASCADE` auf einer Spalte, von der eine **STORED** generierte Spalte abhängt.
Die Wächterspalte ist deshalb `VIRTUAL`. Ein Sekundärindex auf einer
VIRTUAL-Spalte ist erlaubt, die Umstellung kostet also nichts außer Rechenzeit
beim Lesen. Wer die Spalte aus Gewohnheit `STORED` anlegt, bekommt ein
`SQLSTATE[HY000] 1215 Cannot add foreign key constraint`, dessen Text nichts
mit der Ursache zu tun hat.

### 0.4 Kein `ENUM` mit erweiterbaren Werten → `VARCHAR` plus PHP-Enum

Ein MySQL-`ENUM` zu erweitern heißt `ALTER TABLE` auf einer wachsenden
Tabelle. Status liegt deshalb als `VARCHAR` in der Datenbank und als
PHP-Enum im Code (Entscheidung A11). Die Datenbank prüft den Wert nicht, das
Enum tut es.

### 0.5 Schlüssel: UUID v7 als `BINARY(16)`

Entscheidung A4. Ein UUID als `CHAR(36)` mit `utf8mb4` kostet bis zu 144 Byte
je Indexeintrag, binär 16. Bei einem zusammengesetzten Fremdschlüssel
`(id, organization_id)` steht das zweimal in jedem Sekundärindex.

Version 7 ist zeitsortiert. Das ist bei InnoDB kein Schönheitsmerkmal: der
Primärschlüssel ist der Clustered Index, und zufällig verteilte Schlüssel
zerlegen ihn beim Einfügen.

**Die Konsequenz für Eloquent ist unbequem und deshalb hier festgehalten.**
Würde `id` auf die kanonische Zeichenkette gecastet, verglichen alle
Beziehungen eine 36-Zeichen-Zeichenkette mit 16 Bytes — Eloquent wendet Casts
auf Attribute an, nicht auf Query-Bindings. Beziehungen, Eager Loading und
`whereIn` fänden nichts, ohne einen Fehler zu werfen.

Deshalb:

| | |
|---|---|
| `id` | bleibt intern **binär**. Eloquent reicht die Bytes durch, Beziehungen funktionieren ohne Sonderbehandlung. |
| `uuid` | Accessor mit der kanonischen Form. Das ist die Form für Oberfläche, Protokoll und URL. |
| Route-Binding | über `uuid`, nicht über `id`. |
| Serialisierung | `id` ist verborgen, `uuid` wird angehängt. |

Der Preis: `$model->id` und `$model->getKey()` liefern Rohbytes. Wer sie in
ein Log schreibt, schreibt Unsinn hinein. Dafür gibt es `uuid`.

### 0.6 Verschlüsselung: Envelope Encryption

Entscheidung A5 und A6.

```
APP_KEY  (Key Encryption Key, in der Umgebung)
   └── umschließt  encryption_keys.wrapped_dek        je Organisation
   └── umschließt  encryption_keys.wrapped_index_key  je Organisation
                        └── verschlüsselt  Feldinhalte
                        └── erzeugt        blinde Indizes
```

- **Ein DEK je Organisation.** Kündigt ein Mandant, wird sein Schlüssel
  widerrufen, und seine Daten sind unlesbar — ohne jede Zeile anzufassen.
- **AES-256-GCM**, eigener Nonce je Schreibvorgang. Derselbe Klartext ergibt
  deshalb zweimal verschiedene Chiffrate. Das ist beabsichtigt und der Grund,
  warum ein verschlüsseltes Feld nicht durchsuchbar ist.
- **Blinde Indizes** schließen diese Lücke für exakte Vergleiche:
  `HMAC-SHA-256` über den normalisierten Wert, mit dem Indexschlüssel der
  Organisation. Eigener Schlüssel je Organisation, damit dieselbe E-Mail-
  Adresse in zwei Praxen nicht denselben Index ergibt.
- Blinde Indizes taugen nur für Gleichheit. Präfix-, Teil- und Volltextsuche
  sind damit ausgeschlossen — das ist Entscheidung P8, nicht ein Versäumnis.

**Ein verschlüsseltes Feld ist bei jedem `save()` „schmutzig".** Weil der
Nonce jedes Mal neu gezogen wird, unterscheidet sich das Chiffrat auch dann,
wenn der Klartext gleich blieb. Eloquent schreibt deshalb ein UPDATE, wo sonst
keins nötig wäre. Das ist der Preis des Verfahrens und kein Defekt.

**Krypto-Löschung wirkt nur unter einer Betriebsbedingung.** Liegt
`encryption_keys` in derselben Sicherung wie die übrigen Daten, stellt eine
Rücksicherung den Schlüssel mit wieder her. Die Tabelle braucht eine eigene
Aufbewahrungsregel. Das ist keine Frage des Codes und gehört geklärt, bevor
der erste Kunde kündigt.

---

## Querschnittsregeln

**Mandantenbezug.** Jede Tabelle mit Nutzdaten trägt `organization_id`,
erzwungen durch einen globalen Scope (Regel 1 in `CLAUDE.md`, WP-03). Die
Ausnahme ist begründet und steht unten bei der jeweiligen Tabelle.

**Verschlüsselung.** Felder mit Personenbezug liegen feldverschlüsselt
(Regel 3). Das betrifft insbesondere Namen, Kontaktwege, Nachrichteninhalte,
Notizen und Anhänge. Verschlüsselte Felder sind nicht durchsuchbar — wo eine
Suche nötig ist, tritt ein blinder Index daneben. ▲

**Zeit.** Alle Zeitstempel in UTC. Fachliche Auswertung in Ortszeit des
Standorts (`docs/fachlogik/verfuegbarkeit.md`, Abschnitt Zeit).

**Geld.** Beträge als Ganzzahl in Cent (`*_cents`), Kosten von Nachrichten in
Mikroeinheiten (`cost_micros`), wie von der API geliefert.

---

## 1 · Fundament

| Tabelle | Zweck | Belegte Felder |
|---|---|---|
| `organizations` | Mandant | `settings` (JSON), darin u. a. `waitlist_max_offers_per_contact_per_month` |
| `users` | Benutzer | — |
| Rollen, Einladungen | WP-04 | — |
| Audit-Log | WP-05, jeder lesende und schreibende Zugriff quer zum Mandanten | — |
| Abo, Abrechnung | WP-06 | — |
| Whitelabel | WP-07 | — |

`organizations.settings` ist der Ort für alles, was je Mandant abweichen darf.
Der jeweilige Standardwert steht in `config/mrs.php`, nicht im Code.

---

## 2 · Praxis und Katalog

| Tabelle | Belegte Felder | Fundstelle |
|---|---|---|
| `locations` | Zeitzone ▲ | WP-08, Warteliste K3 |
| `practitioners` | — | WP-08, Warteliste K4 |
| `treatments` | `avg_revenue_cents`, Name, Preis | Attribution, Agent Schritt 7 |
| `appointment_types` | Dauer, Rüstzeiten ▲, `lead_time` ▲ | WP-09, Warteliste K2 |

`treatments.avg_revenue_cents` ist ausdrücklich eine **Schätzung**, kein
abgerechneter Umsatz, und wird im Produkt auch so bezeichnet.

Der Katalog ist die einzige Quelle für Behandlungsnamen und Preise. Der Agent
löst `treatment_id` **nur gegen den Katalog** auf und übernimmt nie Freitext
(`docs/fachlogik/agent.md`, Schritt 3).

---

## 3 · Termine und Verfügbarkeit

| Tabelle | Belegte Felder |
|---|---|
| `appointment_slots` | belegt durch `appointment_id` **oder** `slot_hold_id`, zusätzlich `external_block_id` ▲ |
| `slot_holds` | `expires_at`, wird zu `appointment_id` |
| `appointments` | Status `pending`, `confirmed`, `attended`, `no_show`; `attribution_snapshot` (JSON); `reminder_response` (u. a. `no_response`) |

Die Statusliste ist durch die Kennzahlendefinitionen in
`docs/fachlogik/attribution.md` festgelegt und nicht frei erweiterbar: „gebucht"
zählt `pending`, `confirmed` und `attended`, „erschienen" nur `attended`, die
No-Show-Quote rechnet `no_show` gegen erschienen.

`attribution_snapshot` wird beim Anlegen eingefroren und danach nie
verändert — auch nicht, wenn die Kampagne bei Meta umbenannt wird.

Beim Anlegen durch das Team ist die Quelle **Pflichtfeld**.

---

## 4 · Kontakte und Kommunikation

| Tabelle | Belegte Felder |
|---|---|
| `contacts` | — |
| `channel_identities` | bildet scoped IDs ab; dieselbe Person kann mehrere haben |
| `conversations` | `agent_mode` (`off`, `suggest`, `auto`), `agent_paused_until`, `service_window_expires_at` |
| `messages` | externe Nachrichten-ID für Deduplizierung |
| `consents` | `channel_identity_id`, `text_snapshot`, Zeitpunkt |
| `leads` | Status u. a. `won`; `first_response_seconds` |
| Notizen, Anhänge, Aufbewahrung | WP-18 |
| Rohereignisse | 14 Tage Aufbewahrung, Wiedereinspielung |

**Zusammenführung von `channel_identities` nur bei sicherem Signal.** Meta
vergibt Nutzerkennungen je Seite unterschiedlich; eine Zusammenführung auf
Verdacht führt zwei Personen zusammen.

**Deduplizierung über die externe Nachrichten-ID ist keine Optimierung.** Meta
liefert Webhooks doppelt, im Normalbetrieb. Ohne Deduplizierung antwortet der
Agent zweimal.

---

## 5 · Agent

| Tabelle | Belegte Felder |
|---|---|
| `agent_runs` | Absicht, Entitäten, Konfidenz, Aktion, Eskalationsgrund, Vorschlagstext, Modell, Token, Kosten, Laufzeit, ausgelöste Guardrails |
| `guardrail_hits` | im Produkt einsehbar |

`agent_runs` ist nicht optional und nicht kürzbar. Ohne diese Protokollierung
lässt sich einem Arzt nicht erklären, warum sein Agent etwas geantwortet hat.

---

## 6 · Warteliste

| Tabelle | Belegte Felder |
|---|---|
| `waitlist_entries` | `status` (`active`, `offered`, `booked`, `expired`), `expires_at`, `appointment_type_id`, `all_locations`, `practitioner_id`, `earliest_date`, `latest_date`, `weekday_mask`, `time_windows`, `min_notice_hours`, `priority`, `created_at`, `offers_sent_count`, `last_offered_at` |
| `waitlist_entry_location` | Pivot, wenn `all_locations = 0` |
| `waitlist_offers` | `status` (`pending`, `declined`, `expired`, `superseded`, angenommen), `expires_at`, `cost_micros` |

`min_notice_hours` ist das Feld, an dem die Warteliste steht und fällt (K8).
`cost_micros` stammt aus der API-Antwort, nie aus einer Schätzung.

---

## 7 · Werbung

| Tabelle | Belegte Felder |
|---|---|
| Werbekonten | Systembenutzer-Token je Mandant, verschlüsselt, mit Ablaufüberwachung |
| Kampagnen, Anzeigengruppen, Anzeigen | `campaign_external_id`, `adset_external_id`, `ad_external_id` |
| Insights | Aggregation, WP-28 |
| Verbindungen | Status u. a. `expired`, `degraded` |

Das Werbekonto **gehört dem Kunden**. Zugriff über eine Partnerschaft im
Business Manager, nicht über eine Übertragung.

---

## 8 · Attribution

| Tabelle | Belegte Felder |
|---|---|
| `attribution_touches` | `visitor_id`, `click_id` (`fbclid`), `utm_source/medium/campaign/content/term`, `campaign_external_id`, `adset_external_id`, `ad_external_id`, `landing_url`, `referrer`, `occurred_at`, rückwirkend `contact_id` und `lead_id` |

**Das Modell wird nicht im Schema festgeschrieben.** Alle Touches werden
gespeichert, First/Last/Last-Non-Direct/Linear zur Abfragezeit berechnet.

`visitor_id` ist eine Zufalls-ID ohne Personenbezug, Cookie-Laufzeit 180 Tage.

Nach zwölf Monaten entfällt die Aufschlüsselung nach Anzeigengruppe und
Einzelanzeige (Entscheidung P9); die Kampagnenebene bleibt.

---

## 9 · Compliance

| Tabelle | Besonderheit |
|---|---|
| `compliance_rulesets` | **global und versioniert, nicht mandantenbezogen**, mit Gültigkeitsdatum und Changelog |
| `compliance_checks` | polymorph auf Anzeigenvorschlag, Creative, Behandlungsbeschreibung, Template und Buchungsseite |
| Brand Guide | `banned_terms` |

`compliance_rulesets` ist die **eine Tabelle ohne `organization_id`**. Der
Rechtsstand ist für alle Mandanten derselbe; eine mandantenbezogene Kopie
würde bedeuten, dass ein Kunde mit veraltetem Regelwerk weiterarbeitet.

Jedes Prüfergebnis trägt Rechtsstand, Regelwerksversion und Prüfdatum, damit
es nach einer Regelwerksänderung mit seiner ursprünglichen Version
nachvollziehbar bleibt.

Ein Override ist nur mit Pflichtbegründung möglich und wird protokolliert.

Startregelsatz: `before_after`, `missing_risk_notice`, `healing_promise`,
`fear_advertising`, `testimonial`, `risk_free_claims`, `superlatives`,
`brand_violation`.
