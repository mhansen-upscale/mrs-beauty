# Datenmodell

> **Rekonstruktion — inzwischen überholt.** `docs/entscheidungen.md` liegt
> mittlerweile in verbindlicher Fassung vor und legt in den Abschnitten
> Architektur und Datenmodell (A1–A14, D1–D14) mehr fest, als hier steht.
> Die Briefings verweisen zudem auf **Abschnitt 0** (Konsequenzen von MySQL 8)
> und **Abschnitt 11** (Notizen, Anhänge, Einwilligungen). Abschnitt 0 steht
> seit WP-03, Abschnitt 11 seit dem 26.09.2026 — beide aus dem tatsächlichen
> Schema geschrieben, nicht erfunden.
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
| `subscriptions` | WP-06 | Abgleich mit Stripe: Zustand, Periode, aufgestockte Mengen. **Keine Nutzungstabelle** — der Verbrauch wird aus `messages`, `agent_runs` und `waitlist_offers` gerechnet (B7) |
| `brandings` | WP-07 | eine Zeile je Mandant: Markenfarbe, Impressum- und Datenschutz-Adresse; das Logo hängt als Anhang daran. Gilt **nur** für die Buchungsseite |

`organizations.settings` ist der Ort für alles, was je Mandant abweichen darf.
Der jeweilige Standardwert steht in `config/mrs.php`, nicht im Code.

---

## 2 · Praxis und Katalog

| Tabelle | Belegte Felder | Fundstelle |
|---|---|---|
| `locations` | Zeitzone ▲ | WP-08, Warteliste K3 |
| `practitioners` | `avatar_path` | WP-08, Warteliste K4 |
| `treatments` | `avg_revenue_cents`, Name, Preis, `all_practitioners` | Attribution, Agent Schritt 7 |
| `treatment_practitioner` | wer beherrscht welche Behandlung | Buchungsseite |
| `appointment_types` | Dauer, Rüstzeiten ▲, `lead_time` ▲ | WP-09, Warteliste K2 |

`treatments.avg_revenue_cents` ist ausdrücklich eine **Schätzung**, kein
abgerechneter Umsatz, und wird im Produkt auch so bezeichnet.

Der Katalog ist die einzige Quelle für Behandlungsnamen und Preise. Der Agent
löst `treatment_id` **nur gegen den Katalog** auf und übernimmt nie Freitext
(`docs/fachlogik/agent.md`, Schritt 3).

**Wer eine Behandlung macht, steht an der Behandlung.** „Wer macht Botox?"
ist eine Frage an den Katalog, nicht an einen Terminzuschnitt. Die Terminart
darf **verengen** — Erstgespräch nur bei der Ärztin —, ohne eigene Freigabe
erbt sie die der Behandlung. Aufgelöst in
`AppointmentType::freigegebeneBehandler()`, an einer Stelle und nur dort.

Ein leerer Pivot wäre zweideutig: „alle" oder „noch nicht gepflegt"? Deshalb
trägt die Behandlung `all_practitioners`. Leer und `false` heißt niemand —
und eine Terminart ohne Behandler wird auf der Buchungsseite gar nicht erst
angeboten, denn buchbar wäre sie ohnehin nicht.

`practitioners.avatar_path` liegt **unverschlüsselt auf einer öffentlichen
Platte** — dieselbe Abwägung wie beim Namen: die Praxis veröffentlicht das
Portrait selbst auf ihrer Buchungsseite. Regel 3 schützt die Daten der
Patienten, nicht das, was die Praxis von sich aus zeigt.

---

## 3 · Termine und Verfügbarkeit

| Tabelle | Belegte Felder |
|---|---|
| `appointment_slots` | belegt durch `appointment_id` **oder** `slot_hold_id` **oder** `external_block_id` — genau eine der drei |
| `slot_holds` | `expires_at`, wird zu `appointment_id` |
| `appointments` | Status `pending`, `confirmed`, `attended`, `no_show`; `attribution_snapshot` (JSON); `reminder_response` (u. a. `no_response`) |
| `appointment_notifications` | Art und Kanal, `scheduled_for`, `sent_at`, `failed_at`; eindeutig über (`appointment_id`, `kind`) — WP-13 |
| `calendar_connections` | je Behandler und Anbieter eine; Token verschlüsselt, `sync_token`, Watch-Kanal, `status` — WP-14 |
| `external_calendar_blocks` | **ohne Titelspalte** (R2): nur `starts_at`, `ends_at`, `is_all_day` und die externe Kennung — WP-14 |
| `calendar_event_links` | die Spur eines Termins im externen Kalender; zugleich der Idempotenzschlüssel (A13) — WP-14 |

Die Statusliste ist durch die Kennzahlendefinitionen in
`docs/fachlogik/attribution.md` festgelegt und nicht frei erweiterbar: „gebucht"
zählt `pending`, `confirmed` und `attended`, „erschienen" nur `attended`, die
No-Show-Quote rechnet `no_show` gegen erschienen.

`attribution_snapshot` wird beim Anlegen eingefroren und danach nie
verändert — auch nicht, wenn die Kampagne bei Meta umbenannt wird.

Beim Anlegen durch das Team ist die Quelle **Pflichtfeld**.

**Genau eine der drei Belegungsspalten** von `appointment_slots` ist gefüllt.
Die Trennung ist keine Buchhaltung, sondern die Konfliktregel R3 aus
`docs/integrationen/kalender.md`: der externe Kalender gewinnt bei Blockern,
das System gewinnt bei Terminen. Ein Blocker greift deshalb nur auf freie
Zeilen — über einem Termin entsteht keiner, über einem gültigen Hold auch
nicht.

`external_calendar_blocks` hat **keine** Spalte für den Originaltitel, und das
ist die Umsetzung von R2, nicht ein vergessenes Feld: was es nicht gibt, kann
niemand später „nur zur Anzeige" befüllen.

---

## 4 · Kontakte und Kommunikation

| Tabelle | Belegte Felder |
|---|---|
| `contacts` | vier verschlüsselte Felder; blinde Indizes auf E-Mail, Nachname und Telefonnummer (**E.164**, WP-16) |
| `channel_identities` | bildet scoped IDs ab; dieselbe Person kann mehrere haben. Kennung verschlüsselt mit blindem Index; `contact_id` **nullable**; eindeutig über (Organisation, Kanal, Kennung) — WP-16 |
| `contact_merges` | verschlüsselter Snapshot mit Ablaufdatum, umkehrbar (D7) — WP-16 |
| `channel_connections` | Systembenutzer-Token je Mandant, verschlüsselt; Zustand nach der Fehlertabelle — WP-19. `sender_id`: die Kennung, unter der gesendet wird — WP-20a. `smtp_*` (Benutzername und Passwort verschlüsselt) und `verified_at`: das eigene Postfach einer Praxis — WP-20b |
| `channel_raw_events` | verschlüsselt, 14 Tage, wiedereinspielbar — kein Protokoll, ein Wiedervorlagestapel — WP-19. Hieß bis WP-20b `meta_raw_events`; der erste Kanal ohne Meta hat den Namen gerade gerückt |
| `conversations` | `agent_mode` (`off`, `suggest`, `auto`), `agent_paused_until`, `service_window_expires_at`, `anonymized_at` — WP-19. `last_read_at`: der Gelesen-Stand **der Praxis**, nicht einer Person — WP-21 |
| `messages` | externe Nachrichten-ID für Deduplizierung (Unique-Index); Inhalt verschlüsselt; `cost_category` **aus der API-Antwort**, nie geschätzt — WP-19. `template_id` und `template_variables` (verschlüsselt) — WP-20a. `subject` (verschlüsselt): nur E-Mail hat einen — WP-20b |
| `whatsapp_templates` | bei Meta genehmigt, hier nur gelesen; eindeutig über (Organisation, Name, **Sprache**) — WP-20a |
| `agent_runs` | ein Durchlauf je Nachricht: Absicht, Konfidenz, Aktion, Eskalationsgrund, Token, Kosten; Entitäten und Vorschlag verschlüsselt — WP-22 |
| `agent_budgets` | Kontingent je Mandant und Monat; der Verbrauch steht **nicht** hier, sondern wird aus `agent_runs` summiert — WP-23 |
| `agent_dialogs` | ein Buchungsvorgang je Konversation (G9); Zustand, Versuchszähler, Hold; Name und angebotene Zeiten verschlüsselt — WP-24 |
| `consents` | `channel_identity_id`, `text_snapshot`, Zeitpunkt |
| `leads` | Status `new`/`contacted`/`scheduled`/`won`/`lost`; Herkunft; `first_response_seconds`; `last_activity_at` trägt D4. **Ohne Freitext** (D2) — WP-17 |
| `notes` | polymorph auf Kontakt, Termin, Anfrage; Inhalt verschlüsselt — WP-18 |
| `tags`, `taggables` | Ordnungskategorien der Praxis, **unverschlüsselt**, weil zähl- und sortierbar — WP-18 |
| `attachments` | Datei verschlüsselt außerhalb der Datenbank; `CHECK`: Chat-Anhang ohne `expires_at` ist nicht speicherbar (C6) — WP-18 |
| `consents` | an der Kanalidentität (D8); Wortlaut der Erklärung als Snapshot, nicht nur die Version — WP-18 |
| `retention_policies` | Fristen je Mandant, Liste der Gegenstände fest (C7) — WP-18 |
| `data_subject_requests` | überlebt den Kontakt; hält Zahlen, keine Daten — WP-18 |
| Rohereignisse | 14 Tage Aufbewahrung, Wiedereinspielung |

**Zusammenführung von `channel_identities` nur bei sicherem Signal.** Meta
vergibt Nutzerkennungen je Seite unterschiedlich; eine Zusammenführung auf
Verdacht führt zwei Personen zusammen.

**Der Bezug einer Identität auf einen Kontakt ist nullable, mit Absicht.** Die
erste Nachricht kommt an, bevor jemand weiß, wer da schreibt; ein erzwungener
Bezug erzeugte an dieser Stelle Karteileichen oder falsche Kontakte.

**Ein neuer blinder Index macht den Altbestand unauffindbar**, bis
`mrs:blindindex-nachtragen` gelaufen ist. Die Migration legt nur die Spalte
an.

**Polymorphe Bezüge tragen keinen zusammengesetzten Fremdschlüssel.** Notizen,
Schlagworte und Anhänge hängen an Kontakt, Termin oder Anfrage; die Zusage aus
Entscheidung A2 lässt sich dabei nicht stellen. Der globale Scope greift, die
Datenbank sichert es nicht zusätzlich ab — das ist der Preis für Polymorphie.

**Ein Lead ist nicht der Kontakt** (D3). Dieselbe Person fragt im März nach
Botox und im Oktober nach Hyaluron — zwei Vorgänge mit zwei Quellen und zwei
Ergebnissen. Wer sie am Kontakt festmacht, kann hinterher nicht mehr sagen,
welche Anzeige die Buchung gebracht hat.

**Gewonnen heißt erschienen, nicht gebucht.** Die Kennzahl „Abschlüsse" zählt
`leads.status = won`, und ein Termin, den niemand wahrnimmt, darf kein
Abschluss sein — sonst misst der ROAS Absichten statt Umsatz.

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
| `waitlist_offers` | `status` (`pending`, `accepted`, `declined`, `expired`, `superseded`), `expires_at`, `cost_micros`, `trigger`; K9 über die generierte Spalte `offer_guard` — WP-25 |

`min_notice_hours` ist das Feld, an dem die Warteliste steht und fällt (K8).
`cost_micros` stammt aus der API-Antwort, nie aus einer Schätzung — und
bleibt bis WP-06 leer: WhatsApp meldet die **Kategorie**, nicht den Betrag.

**Der Wächter für K9 hängt an `entry_key`, nicht am Fremdschlüssel** (WP-25).
MySQL verbietet `ON DELETE CASCADE` auf einer Spalte, von der eine STORED
generierte Spalte abhängt — und kaskadieren muss er, damit eine
Löschanfrage nach DSGVO nicht an einem Angebot scheitert.

---

## 7 · Werbung

| Tabelle | Besonderheit |
|---|---|
| `ad_accounts` | eines je Mandant; Systembenutzer-Token verschlüsselt, mit Ablaufüberwachung; Währung und Zeitzone des Kontos |
| `ad_campaigns`, `ad_sets`, `ads` | `external_id` je Ebene, **Name feldverschlüsselt**, Budgets als Ganzzahl in kleinster Einheit, `vanished_at` statt Löschung |
| dazu ab WP-27 | `sync_state`, `sync_error`, `client_token`, `managed_by_us`; an `ad_sets` die Zielgruppe: Standort, Umkreis, Alter, Geschlecht |
| dazu ab WP-31b | an `ads` `image_hashes` statt `image_hash`: je Format Metas Bildkennung **und der Anhang, zu dem sie gehört** — eine neue Grafik wird neu hochgeladen |
| `ad_suggestion_images` | je Grafik eines Anzeigenentwurfs Format (`1x1`, `4x5`, `9x16`) und Satz (`batch`); die Datei selbst ist ein Anhang am Entwurf. **Gezählt wird der Satz, nicht die Datei** (B13, C13) |
| `ad_insights` | Tageszeilen je Ebene: Ausgaben, Impressionen, Klicks, Link-Klicks, von Meta gemeldete Ergebnisse |
| Zustand | `ConnectionStatus`: `active`, `expired`, `degraded`, `suspended` — derselbe wie bei Kanal- und Kalenderverbindungen |

**Namen liegen verschlüsselt** (WP-26). Eine importierte Kampagne kann „Botox
Herbst" heißen; die Praxis hat sie so benannt, bevor sie uns kannte. Ab WP-32
friert dieser Name als `attribution_snapshot` am Termin ein (D13) — dann
stünde ein Behandlungsname in einem offenen Feld neben einem Kontakt. Der
Preis: sortiert und gesucht wird in PHP, nicht in SQL (wie P8).

**Was bei Meta verschwindet, wird markiert, nicht gelöscht.** Eine gelöschte
Kampagne bleibt in Auswertung und Attribution sichtbar; wer sie entfernt,
reißt die Verbindung zwischen einem Termin und der Anzeige, die ihn gebracht
hat.

**Kennzahlen sind Grundwerte, keine Quoten** (WP-28). CTR, CPC und CPM
liefert Meta mit — sie mitzuschreiben hieße zwei Zahlen für dieselbe Aussage
zu führen, und die weichen ab, sobald Meta rundet oder einen Tag nachträglich
korrigiert. Gerechnet wird in `App\Werbung\Kennzahlen`, an einer Stelle.

**Reichweite fehlt mit Absicht.** Sie zählt verschiedene Menschen und lässt
sich nicht über Tage addieren; eine nicht summierbare Zahl neben summierbaren
wird irgendwann summiert.

**Ein `stat_date`, kein Zeitstempel.** Insights-Tage laufen in der Zeitzone
des Werbekontos. Nach zwölf Monaten fallen die Ebenen unterhalb der Kampagne
weg (P9), durchgesetzt über die Aufbewahrung aus WP-18.

**Was die Praxis will und was bei Meta steht, sind zwei Dinge** (WP-27). Bis
WP-26 war die Tabelle ein Spiegel. Ab jetzt kann eine Zeile eine Änderung
tragen, die noch unterwegs ist — oder eine, die Meta abgelehnt hat. Wer das
nicht unterscheidet, zeigt eine Budgeterhöhung an, die nie ankam.

**`client_token` ersetzt den Idempotenzschlüssel**, den Metas Marketing-API
nicht hat. Er steht im Namen, den das Produkt erzeugt; ein Auftrag, dessen
Antwort verlorenging, findet seine Kampagne daran wieder, statt eine zweite
mit zweitem Budget anzulegen.

**Die Zielgruppe ist Umkreis, Alter, Geschlecht — und nichts sonst.**
Interessen fehlen mit Absicht: „Botox" als Interesse wäre eine
Behandlungsbezeichnung Richtung Meta, in einem Feld, an das niemand denkt.

**Die Zielgruppendefinition wird nicht übernommen.** Sie enthält Interessen,
die im Umfeld einer ästhetischen Praxis gesundheitsnah sind, und keines der
Pakete braucht sie (Regel 3).

Das Werbekonto **gehört dem Kunden**. Zugriff über eine Partnerschaft im
Business Manager, nicht über eine Übertragung.

**Erzeugte Anzeigenbilder liegen bei uns** (Entscheidung C10). Generiert wird
bei kie.ai, danach heruntergeladen, in den eigenen Bucket geschrieben und beim
Anbieter gelöscht — dieselbe Ablagemechanik wie bei den Chat-Anhängen
(`App\Datenschutz\Anhangspeicher`, Platte aus der Konfiguration). Gespeichert
werden Datei, Erzeugungszeitpunkt, verwendetes Modell und der Auftragstext,
damit ein Bild Jahre später noch belegbar ist: für die HWG-Prüfung, für eine
Beanstandung, für den Kunden.

Anders als Chat-Anhänge haben sie **kein Pflicht-Ablaufdatum** (C6): sie
enthalten keine Patientendaten, und ein Beleg, der verfällt, ist keiner.

---

## 8 · Attribution

| Tabelle | Belegte Felder |
|---|---|
| `attribution_touches` | `visitor_id`, `click_id` (`fbclid`), `utm_source/medium/campaign/content/term`, `campaign_external_id`, `adset_external_id`, `ad_external_id`, **`landing_path`** (ohne Abfrageteil), **`referrer_host`** (ohne Seite), `occurred_at`, rückwirkend `contact_id` und `lead_id` |
| `appointments.attribution_snapshot` | verschlüsselt: der Stand zum Zeitpunkt der Buchung, als Kopie (D13) |
| `appointments.attribution_campaign_id` | **Klartext**, indiziert: der Schlüssel zum Gruppieren (WP-32b) |

**Das Modell wird nicht im Schema festgeschrieben.** Alle Touches werden
gespeichert, First/Last/Last-Non-Direct/Linear zur Abfragezeit berechnet.

`visitor_id` ist eine Zufalls-ID ohne Personenbezug, Cookie-Laufzeit 180 Tage
— **gesetzt erst nach der Einwilligung** (§ 25 TTDSG, WP-32a). Ohne sie gibt
es weder Cookie noch Touch, und das Meta-Pixel lädt ebenfalls nicht.

**Der Schlüssel darf offen liegen, die Aussage nicht** (WP-32b). Der Snapshot
ist verschlüsselt und damit nicht gruppierbar; die Kampagnenkennung steht im
Klartext daneben. Sie ist eine Ziffernfolge ohne Aussage — wer sie zu einem
Namen auflösen will, braucht `ad_campaigns`, und dort liegt der Name
verschlüsselt.

**Pfad statt Adresse, Host statt Verweis** (WP-32a). Der Abfrageteil einer
Adresse trägt, was jemand angehängt hat — im Zweifel eine Behandlung. Sobald
der Touch rückwirkend mit `contact_id` verknüpft ist, stünde sie
unverschlüsselt neben einem Kontakt (Regel 3). Eine Abweichung von
`docs/fachlogik/attribution.md`, die dort bestätigt gehört.

Nach zwölf Monaten entfällt die Aufschlüsselung nach Anzeigengruppe und
Einzelanzeige (Entscheidung P9); die Kampagnenebene bleibt.

---

## 9 · Compliance

| Tabelle | Besonderheit |
|---|---|
| `compliance_rulesets` | **global und versioniert, nicht mandantenbezogen**, mit Gültigkeitsdatum und Changelog |
| `compliance_checks` | polymorph auf Anzeigenvorschlag, Creative, Behandlungsbeschreibung, Template und Buchungsseite |
| `brand_guides` | einer je Mandant: Tonalität, Ansprache, Zielgruppe, Positionierung, Claim |
| `brand_terms` | bevorzugte und verbotene Begriffe, je mit Ersatz und Begründung — die Quelle für `brand_violation` |
| `brand_references` | Referenzmaterial mit **Erklärung im Wortlaut**, Person und Zeitpunkt |

**Umgesetzt in WP-30.** `reviewed_by` und `reviewed_at` kamen dazu: eine
Fassung, die kein Medizinrechtler durchgesehen hat, sagt das — im Produkt, an
jeder Ampel.

`compliance_rulesets` ist die **eine Tabelle ohne `organization_id`**. Der
Rechtsstand ist für alle Mandanten derselbe; eine mandantenbezogene Kopie
würde bedeuten, dass ein Kunde mit veraltetem Regelwerk weiterarbeitet.

Jedes Prüfergebnis trägt Rechtsstand, Regelwerksversion und Prüfdatum, damit
es nach einer Regelwerksänderung mit seiner ursprünglichen Version
nachvollziehbar bleibt.

Ein Override ist nur mit Pflichtbegründung möglich und wird protokolliert.

**Referenzmaterial trägt kein Ablaufdatum** (WP-29), anders als Chat-Anhänge
(C6): es ist kein ungefragt zugesandtes Foto, sondern Material, mit dem
geworben wird. Die Aufbewahrung greift auf den Kontext des Anhangs, nicht auf
alle Anhänge.

**Die Erklärung wird kopiert, nicht referenziert.** Wer in zwei Jahren fragt,
was eine Praxis beim Hochladen zugesichert hat, braucht den Satz von damals —
eine Änderung am Wortlaut gilt ab dann, nicht rückwirkend.

Startregelsatz: `before_after`, `missing_risk_notice`, `healing_promise`,
`fear_advertising`, `testimonial`, `risk_free_claims`, `superlatives`,
`brand_violation`.

**Seit dem 26.09.2026 auch Buchungsseite und Template** (C11). Jede Prüfung
trägt `content_hash` — den SHA-256 des geprüften Texts, als Rohbytes. Die
Buchungsseite zeigt Beschreibung und Preis nur, wenn die jüngste Prüfung
**diesem** Text gilt; der Zeitstempel des Datensatzes wäre das falsche Maß, er
springt auch bei einer geänderten Umsatzschätzung.

---

## 10 · Änderungen vom 26.09.2026

| Tabelle | Spalte | Wofür |
|---|---|---|
| `messages` | `charge_tenth_cents` | Was eine Antwort im Service-Fenster einzeln kostet, festgehalten beim Eintreffen der Kategorie (B14). Leer heißt: nicht einzeln berechnet |
| `subscriptions` | `service_window_billed_period` | Der letzte Monat (`Y-m`), dessen Antworten auf einer Rechnung stehen. Stripes Idempotenzschlüssel gilt 24 Stunden; das hier gilt immer |
| `agent_dialogs` | `requested_practitioner_id` | Der geäußerte Behandlerwunsch — schränkt die Vorschläge ein. **Nicht** `practitioner_id`: die hält fest, bei wem der gehaltene Slot liegt |
| `agent_dialogs` | `change_appointment_id` | Der Termin, der abgesagt oder verschoben werden soll (G12). **Nicht** `appointment_id`: die trägt G9 |
| `compliance_checks` | `content_hash` | Siehe Abschnitt 9 |

Zwei Tabellen haben dabei keine neue Spalte bekommen, obwohl es nahelag:

- **`waitlist_offers.cost_micros`** stand seit WP-25 und bleibt die Quelle der
  Wartelistenkosten. Gefüllt wird sie jetzt aus `charge_tenth_cents` der
  Angebotsnachricht; zugeordnet über den Idempotenzschlüssel `warteliste-…`.
- **Der wackelige Termin** (Auslöser 3) braucht keinen eigenen Zustand: eine
  offene Klärung ist ein Angebot mit `trigger = no_response`,
  `status = accepted` und ohne `appointment_id`.

---

## 11 · Notizen, Anhänge, Einwilligungen

> Von WP-18 als Pflichtlektüre genannt, bis zum 26.09.2026 nicht vorhanden.
> Geschrieben aus `0001_01_01_001400_create_datenschutz_tables.php` und den
> Modellen; es beschreibt, was steht, nicht was sein sollte.

| Tabelle | Besonderheit |
|---|---|
| `notes` | polymorph (`notable_*`), Rumpf **verschlüsselt**, Verfasser als Verweis — kein Freitext im Klartext |
| `tags`, `taggables` | Schlagworte je Mandant, eindeutig im Namen; polymorph zuordenbar |
| `attachments` | polymorph (`attachable_*`), Datei **außerhalb der Datenbank, verschlüsselt unter dem Schlüssel der Organisation** (A6); Dateiname verschlüsselt, Typ aus dem Inhalt erkannt, `checksum` als SHA-256 |
| `consents` | an der **Kanalidentität**, nicht am Kontakt (D8); jede Erteilung und jeder Widerruf eine eigene Zeile mit `text_version` und **Wortlaut** (`text_snapshot`) |
| `retention_policies` | Fristen je Mandant und Gegenstand, mit Aktion (löschen, anonymisieren); Vorgaben aus C7 |
| `data_subject_requests` | Auskunft und Löschung nach DSGVO, mit Ergebnis und Abschlusszeitpunkt |

### Anhänge

**`context`** entscheidet über Frist und Weg:

| Kontext | Ablaufdatum | Woher |
|---|---|---|
| `chat` | **Pflicht** (C6), als CHECK in der Datenbank | ungefragt zugesandt — Gesundheitsdatum nach Art. 9 DSGVO |
| `document` | keines | vom Team hochgeladen |
| `brand_reference` | keines | Material der Marke (WP-29), nie Patientenmaterial |

**`scan_result`** ist `clean`, `infected` oder `unscanned`. **Nur `clean` gibt
frei** — auch ohne angebundenen Prüfer steht dann überall `unscanned`, und es
geht nichts hinaus. Die eine Ausnahme ist das Logo (WP-07, eigene Route): dort
blockiert nur ein Befund.

**Ausgeliefert wird über eine Route** (`anhang.zeigen`, C12). Wer ihn sehen darf,
hängt am Träger — Nachricht: Posteingang, Referenz: Brand Guide, Kontakt:
Kontakte. Bilder erscheinen in einer Sandbox, alles andere als Download. Das
Öffnen eines Chat-Anhangs steht im Protokoll (`attachment.opened`).

**Löschen heißt beides**, Datensatz und Datei (`Anhangspeicher::entferne`). Der
Datensatz allein ließe Fotos auf dem Speicher liegen.

### Einwilligungen

Drei Arten (`ConsentType`): `service_messages` (Terminnachrichten, Angebote
der Warteliste — K11), `marketing` und `whatsapp` (das Opt-in für Templates
außerhalb des Fensters). **Der Stand ist die jüngste Zeile je Art**, nicht ein
Schalter: ein Widerruf überschreibt nichts, er kommt dazu.

Der Wortlaut wird **kopiert**, nicht referenziert — wie bei der Erklärung zum
Referenzmaterial. Wer in zwei Jahren fragt, was jemand zugesagt hat, braucht
den Satz von damals.

### Aufbewahrung

Gegenstände (`RetentionSubject`): Lead ohne Termin, Chat-Anhang, Konversation
(**anonymisiert**, nicht gelöscht), Protokoll, Zusammenführungs-Snapshot,
Rohereignis, Werbezahlen je Anzeige, Attributionsberührung. Durchgesetzt von
`mrs:aufbewahrung` — nachts **als Vorschau**, scharf von Hand
(`docs/betrieb.md`).

