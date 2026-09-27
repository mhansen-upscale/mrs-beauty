# WP-06b · Paketverwaltung

> Nachtrag zu WP-06. Das Paket (ein Name, vier Preise, drei enthaltene
> Kontingente, zwei Blockgrößen und die Testphase) steht heute als
> Konstanten in `config/mrs.php`. Die zugehörigen Preis-IDs stehen in
> `config/services.php`, dazu der Satz „Wer einen ändert, ändert beide". Eine
> Preisänderung heißt deshalb: Preis bei Stripe anlegen, ID in die Umgebung
> eintragen, Konfiguration ändern und ausrollen, und hoffen, dass beides
> übereinstimmt. Der Betreiber will das Paket im Backoffice pflegen.

## Ziel
Ein Super-Admin ändert im Backoffice Name, Preise und Kontingente des
Pakets. Stripe erhält den neuen Preis ohne Handarbeit, und wer ein Abo hat,
behält, was er abgeschlossen hat, außer der Betreiber entscheidet es für
diese Änderung anders.

## Vorher lesen
- `docs/entscheidungen.md`: **B20** (dieses Paket), **B10** eine Stufe,
  **B11**, **B13**, **B15** Preismodell, **B9** kein Cashier
- `CLAUDE.md`, **Regel 4**, dazu *Arbeitsweise* („Fachliche Konstanten stehen
  in `config/mrs.php`"). B20 nimmt das Paket davon aus, siehe unten.
- `specs/WP-06-abo-abrechnung.md`, vollständig
- `specs/WP-34a-betreiberrollen-anmeldung.md`: Fähigkeit
  `paket.verwalten`, `current_password`
- `specs/WP-34c-abo-eingriffe-testphase.md`: `stripe_events`, feste
  `Stripe-Version`, `Fehlereinordnung`-Aufträge. Dieses Paket baut darauf auf.
- Stripe: *Prices* (unveränderlich, `active=false` zum Archivieren),
  *Products*, *Change the price of a subscription*
  (`proration_behavior=none`). Vor dem Bau erneut lesen.

## Voraussetzungen
WP-06, WP-34a, WP-34c.

## Die Linie, an der alles hängt

**Ein Preis bei Stripe ist unveränderlich. Also ist es das Paket auch.**

Speichern ändert kein Paket, sondern legt eine **neue Fassung** an. Jede
Fassung hat ihre eigenen Preise bei Stripe, und jedes Abo zeigt auf die
Fassung, zu der es abgeschlossen wurde. „Das aktuelle Paket" ist die
jüngste Fassung, deren Preise bei Stripe angelegt sind. Nur sie sieht eine
neue Praxis in der Kasse.

Daraus folgt der Bestandsschutz, ohne dass jemand an ihn denken muss: Ein
Abo auf Fassung 3 rechnet mit den Preisen und Kontingenten von Fassung 3,
bis es ausdrücklich umgestellt wird (B20). **Ob umgestellt wird, entscheidet
der Betreiber je Änderung**:

| Beim Speichern gewählt | Neue Praxen | Bestehende Abos |
|---|---|---|
| **Nur Neuabschlüsse** (Vorgabe) | neue Fassung | bleiben auf ihrer Fassung |
| **Auch den Bestand umstellen** | neue Fassung | ab dem nächsten Abrechnungszeitraum auf der neuen Fassung, Kontingente ab dann |

**Eine Stufe bleibt eine Stufe** (B10). Es gibt genau ein Paket mit einer
Reihe von Fassungen, keine Auswahl in der Kasse.

**Das Paket ist Datenbestand, keine Konfiguration mehr** (B20). Die Werte in
`config/mrs.php` bleiben die Vorgabe der ersten Fassung und der Testumgebung.
Gelesen wird zur Laufzeit nur noch die Fassung.

## Was eine Fassung festhält

| Feld | Heute | Fundstelle |
|---|---|---|
| `name` | Stripe-Produktname | — |
| `base_cents`, `setup_cents`, `topup_cents`, `image_price_cents` | `mrs.billing.prices.*`, `mrs.billing.image_price_cents` | B13, B15 |
| `included_messages`, `included_agent_runs`, `included_images` | `mrs.billing.included.*` | B13, B15 |
| `topup_messages`, `topup_agent_runs` | `mrs.billing.topup.*` | WP-06 |
| `trial_days` | `mrs.billing.trial_days` | WP-06, B18 |
| `stripe_price_base`, `…_setup`, `…_topup`, `…_image` | `services.stripe.*_price_id` | B15 |

**Nicht in der Fassung:** der Preis für Antworten im Service-Fenster (B14,
weiter aus `WHATSAPP_SERVICEFENSTER_CENT`). Er gibt Metas Kosten weiter und ist
kein Paketpreis. Bilder werden weiter einzeln nachgekauft (`topup.images` = 1,
B13).

## Schritte

1. **Tabelle `plan_versions`** (Modell `PlanVersion`):
   - Sie ist **global**, ohne `organization_id`: Das Paket gehört dem
     Betreiber, nicht einer Praxis. Deshalb braucht sie keine Ausnahme im
     ArchitekturTest.
   - Die Felder aus der Tabelle oben, dazu `number` (fortlaufend, UNIQUE),
     `stripe_state` (`pending`, `ready`, `failed`), `stripe_error`,
     `migrate_existing` (bool), `reason`, `created_by_user_id`,
     `activated_at`.
   - **Append-only wie `audit_logs`**, per Trigger: Eine Fassung, an der
     schon abgerechnet wurde, darf sich nicht mehr ändern. Ausgenommen sind
     allein die Stripe-Felder, bis `ready` erreicht ist.
2. **Die erste Fassung** legt die Migration aus den heutigen Werten in
   `config/mrs.php` und `config/services.php` an, mit `ready`, wenn die
   Preis-IDs gesetzt sind. Jede bestehende Abo-Zeile zeigt auf sie.
3. **`subscriptions.plan_version_id`** (Fremdschlüssel). Neue Abos bekommen
   die aktuelle Fassung.
4. **`App\Abrechnung\Paket`**, die eine Stelle für Paketwerte:
   - `aktuell(): PlanVersion`, die jüngste Fassung mit `ready`.
   - `fuer(?Subscription): PlanVersion`, die Fassung des Abos, ohne Abo die
     aktuelle.
   - **Jeder heutige Leser von `mrs.billing.*` und
     `services.stripe.*_price_id` stellt um:**
     - `Kontingente` (enthalten, Testphase)
     - `AboController` (Preise, Blockgrößen, Kasse, Einrichtung)
     - `StripeWebhookController` (Aufstockung)
     - `Installationskennzahlen` (Hochrechnung)
     - `Servicefensterabrechnung`, soweit es Paketwerte liest
   - Ein Architekturtest verbietet danach `config('mrs.billing.prices` und
     die Preis-IDs in `app/`, außer in der Migration der ersten Fassung.
5. **Preise bei Stripe anlegen**, im Auftrag `PaketfassungAnlegen` (Regel 4,
   nicht im Anfragezyklus):
   - Je Preisart ein `POST /v1/prices` unter dem Produkt der vorigen Fassung.
     Das Produkt liest der Auftrag über den alten Preis, es gibt keine
     zweite Konfiguration dafür.
   - `Idempotency-Key` = Fassung + Preisart. Ein wiederholter Auftrag legt
     keinen zweiten Preis an.
   - Ein geänderter Name wird zum `POST /v1/products/{id}` mit `name`.
   - Erst wenn alle vier Preise stehen, gilt `ready` und `activated_at`.
     Vorher kassiert die Kasse weiter unter der vorigen Fassung.
   - Danach werden die Preise der vorigen Fassung archiviert (`active=false`).
     **Bestehende Abos rechnen weiter**: Das Archivieren sperrt einen Preis
     nur für neue Abschlüsse.
   - Scheitert der Auftrag endgültig, gilt `failed` mit Grund. Das steht auf
     der Paketseite und in `Betriebslage::fuerInstallation()`.
6. **Bestand umstellen** (nur bei `migrate_existing`), Auftrag
   `AboAufFassungUmstellen` je Abo nach `ready`:
   - `POST /v1/subscriptions/{id}` mit der Position auf dem neuen Preis und
     `proration_behavior=none`: Der laufende Zeitraum ist bezahlt, die
     nächste Rechnung kommt zum neuen Preis.
   - Idempotent je Abo und Fassung.
   - `plan_version_id` setzt **der Webhook**, sobald Stripe den neuen Preis
     am Abo meldet, nicht der Auftrag (WP-06 AK 13). Die Kontingente folgen
     ab der nächsten Periode (`neuePeriode()`), nicht mitten im Monat.
7. **Webhook:** `customer.subscription.created/updated` ordnet die Preis-ID
   der Position einer Fassung zu und setzt `plan_version_id`. Eine
   unbekannte Preis-ID ist ein Betriebshinweis, keine Vermutung.
8. **Seite `backoffice/Paket.vue`** (`paket.verwalten`, WP-34a):
   - Die aktuelle Fassung als Formular mit Euro statt Cent in der Eingabe.
   - Die Wahl „Nur Neuabschlüsse" oder „Auch den Bestand zum nächsten
     Zeitraum umstellen", dazu Pflichtbegründung und `current_password`.
   - Wer den Bestand umstellt, sieht vor dem Speichern, wie viele Abos es
     trifft.
   - Die Liste der Fassungen mit Nummer, Datum, Handelndem, Begründung,
     Stripe-Stand und der Zahl der Abos darauf.
   - Die Seitenleiste bekommt den Punkt „Paket", mit Eintrag in
     `useEinfuehrung.ts`.
9. **Abo-Seite der Praxis** (`settings/Abo.vue`): Preise und Kontingente der
   **eigenen** Fassung, nicht der aktuellen. Eine Praxis im Bestandsschutz
   sieht nicht die Preise, die sie nicht zahlt.
10. **Protokoll:** `plan.version_created`, `plan.version_ready`,
    `plan.version_failed`, `plan.migration_requested` ohne Organisation (die
    Aktion gehört dem Betreiber) und je umgestelltem Abo
    `subscription.plan_changed` beim Mandanten, über `vermerke()`.

## Abnahmekriterien

**Fassungen**

1. Speichern legt eine neue Fassung an. Die vorige bleibt unverändert.
2. Eine Fassung lässt sich weder über Eloquent noch direkt in der Datenbank
   ändern oder löschen, außer ihren Stripe-Feldern vor `ready`.
3. Die Migration legt Fassung 1 aus den bisherigen Werten an, und jedes
   bestehende Abo zeigt darauf.
4. Negative Preise, ein Grundpreis von null und leere Kontingente werden am
   Feld abgewiesen.

**Stripe**

5. Speichern ruft Stripe nicht im Anfragezyklus auf.
6. Der Auftrag legt vier Preise unter dem bisherigen Produkt an, jeweils mit
   Idempotenzschlüssel. Eine Wiederholung legt keinen fünften an.
7. Erst nach allen vier Preisen ist die Fassung `ready`. Bis dahin öffnet die
   Kasse mit den Preisen der vorigen Fassung.
8. Nach `ready` sind die vorigen Preise archiviert, und ein bestehendes Abo
   auf ihnen rechnet weiter.
9. Scheitert Stripe endgültig, steht die Fassung auf `failed` mit Grund, auf
   der Paketseite und in der Betriebslage. Die vorige Fassung bleibt
   aktuell.

**Bestand**

10. „Nur Neuabschlüsse": Ein bestehendes Abo behält Preis und Kontingente
    seiner Fassung. Eine neue Praxis bekommt die neue.
11. „Auch den Bestand": Jedes Abo wird bei Stripe mit
    `proration_behavior=none` umgestellt, genau einmal.
12. `plan_version_id` wechselt erst mit dem Webhook, die Kontingente mit der
    nächsten Periode.
13. Die Abo-Seite einer Praxis zeigt die Preise ihrer eigenen Fassung.

**Grenzen**

14. Nur `paket.verwalten` erreicht die Seite. Customer Success und Finanzen
    bekommen 403.
15. Ohne richtiges `current_password` und ohne Begründung entsteht keine
    Fassung.
16. Kein Leser in `app/` greift mehr auf `mrs.billing.prices`,
    `mrs.billing.included`, `mrs.billing.topup` oder die Preis-IDs in
    `services.stripe` zu.
17. Es gibt weiterhin genau ein Paket (B10). Die Kasse bietet keine Auswahl.

## Nicht in diesem Paket

- **Mehrere Pakete oder Stufen.** B10 bleibt, ausdrücklich bestätigt am
  27.09.2026.
- **Ein Stichtag in der Zukunft** („gilt ab 1. Januar"). Wer das will,
  speichert am 1. Januar.
- **Rabatte, Jahrespreis, Gutscheine.** Der Gratismonat ist WP-34c, der
  Jahrespreis ist in `docs/produkt.md` offen.
- **Die Ankündigung an bestehende Praxen.** Eine Preiserhöhung im Bestand
  verlangt je nach Vertrag eine Frist. Das ist eine Sache der AGB und des
  Betreibers, nicht des Produkts. Die Paketseite weist darauf hin.
- **Der Preis für Antworten im Service-Fenster** (B14, bleibt in der
  Umgebung).

## Fallstricke

- **Einen Stripe-Preis „ändern".** Das geht nicht, `unit_amount` ist
  unveränderlich. Wer es versucht, legt am Ende zwei Preise an und merkt
  sich den falschen.
- **Die Kasse auf eine Fassung zeigen lassen, deren Preise noch fehlen.** Die
  Praxis bekommt einen Fehler von Stripe statt einer Kasse. Deshalb gilt
  eine Fassung erst ab `ready`.
- **Alte Preise archivieren und glauben, der Bestand sei betroffen.** Er ist
  es nicht, und genau das ist der Bestandsschutz. Umgestellt wird nur über
  die Positionen der Abos.
- **Die Kontingente mitten im Monat wechseln.** Wer am 15. von 600 auf 400
  Assistenzläufe umstellt, sperrt einer Praxis, die schon 450 verbraucht
  hat, rückwirkend den Assistenten. Die neue Fassung wirkt ab der nächsten
  Periode.
- **Zwei Orte für dieselbe Zahl** (WP-06). Wer nach dem Umbau irgendwo noch
  `config('mrs.billing.prices…')` liest, zeigt der Praxis einen Preis, den
  Stripe nicht berechnet. Deshalb gibt es AK 16 als Architekturtest.
- **Die Hochrechnung aus WP-34d** muss den Grundpreis je Abo aus dessen
  Fassung nehmen, nicht aus der aktuellen. Sonst rechnet sie den
  Bestandsschutz weg.
- **Cent in der Eingabe.** Wer „790" in ein Cent-Feld tippt, verkauft das
  Abo für 7,90 €. Eingabe in Euro, gespeichert in Cent, und vor dem Speichern
  steht der Betrag noch einmal ausgeschrieben da.

## Stand

Die 17 Abnahmekriterien laufen, in **36 Tests**:

| Datei | Deckt ab |
|---|---|
| `tests/Feature/Backoffice/PaketverwaltungTest.php` (35, davon 8 als Datensatz) | 1, 2, 4–17, dazu Testbetrieb, Testphase, erster Abschluss, unbekannter Preis, MRR je Fassung |
| `tests/Parallel/PaketfassungMigrationTest.php` (1) | 3 |

AK 14 prüft zusätzlich die Rollen-Routen-Tabelle in
`BetreiberrollenTest` (`backoffice.paket`, `backoffice.paket.store`).
`composer check`, `npm run format:check`, `npx eslint .`,
`npx vue-tsc --noEmit` und `npm run build` sind grün.

Neu:
- **Modell** `PlanVersion`, append-only per Trigger
  (`plan_versions_unveraenderlich`, `plan_versions_kein_delete`) und
  zusätzlich in Eloquent.
- **Migration** `2026_09_27_140000_paketfassungen`: Tabelle, Trigger,
  `subscriptions.plan_version_id` und `pending_plan_version_id`, Fassung 1
  aus der Konfiguration.
- **Dienst** `App\Abrechnung\Paket` mit `aktuell()`, `fuer()`,
  `neueFassung()`, `giltAb()`, `stelleBestandUm()` und `wechsle()`.
- **Aufträge** `PaketfassungAnlegen` und `AboAufFassungUmstellen`.
- **Stripe**: `preis()`, `legeProduktAn()`, `benenneProdukt()`,
  `legePreisAn()`, `archivierePreis()`, `abo()` und `stelleAboUm()`.
- **Webhook** ordnet den Grundpreis der Position einer Fassung zu: beim
  ersten Abschluss sofort, sonst zur nächsten Periode.
- **Umgestellte Leser**: `Kontingente`, `Abozugang`,
  `Subscription::testphasenende()`, `AboController`,
  `StripeWebhookController`, `Mandantenuebersicht` und
  `Installationskennzahlen`, dort die MRR je Abo aus dessen Fassung.
  `Servicefensterabrechnung` liest keinen Paketwert und blieb, wie sie war.
- **Seite** `backoffice/Paket.vue`, dazu der Punkt „Paket" in der
  Seitenleiste und der Text in `useEinfuehrung.ts`.
- **Die Fassung im Blick**: auf der Abo-Seite der Praxis (Name und
  Grundpreis der eigenen Fassung) und im Abo-Kasten des Mandantenblatts.
- **Betriebslage** `gescheitertePaketfassungen` und `paketHinweise` (eine
  gescheiterte Umstellung oder ein unbekannter Preis), auch im Dashboard des
  Betreibers und in `mrs:betrieb`.
- **Protokoll** zusätzlich `subscription.plan_change_failed` und
  `subscription.price_unknown`.
- **Test-Helfer** `neuesPaket()` in `tests/Pest.php` ersetzt
  `config()->set('mrs.billing.…')` in fünf Testdateien.

## Was das Bauen zutage gefördert hat

**Testbetrieb ohne Stripe** (Wunsch vom 27.09.2026, wie in WP-34c).
- Ohne Schlüssel gilt eine neue Fassung **sofort**, ohne Preise bei
  Stripe. Sonst gälte sie nie, und das Backoffice ließe sich nicht
  ausprobieren.
- „Auch den Bestand" stellt dann ebenfalls sofort um: Es gibt keine
  Periode, die abzuwarten wäre.
- Die Seite sagt „Testbetrieb", das Protokoll trägt `ohne_stripe`.
- **Beim Anbinden von Stripe** hat die geltende Fassung keine Preise. Die
  Seite warnt dann („die Kasse öffnet nicht"), und eine Fassung darf
  ausnahmsweise **unverändert** gespeichert werden: Genau das legt Produkt
  und Preise an. Die Kasse sagt bis dahin „Für dieses Paket fehlt der Preis
  bei Stripe", statt Stripe mit einem leeren Preis zu rufen.

**Eine Testphase ist kein Abschluss** (zu bestätigen). Wird eine Fassung
gültig, wechseln Praxen ohne Stripe-Abo immer mit, auch bei „Nur
Neuabschlüsse". Bestandsschutz gilt für das, was eine Praxis abgeschlossen
hat. Wer noch testet, schließt ohnehin zur aktuellen Fassung ab. Sonst sähe
er bis dahin die Kontingente einer Fassung, die niemand mehr kaufen kann.
Eine bereits laufende Testphase wird dabei **nicht** verlängert oder
verkürzt, `trial_ends_at` bleibt. Im Testbetrieb heißt das: Jede Praxis ist
in der Testphase und wechselt mit. Den Bestandsschutz zeigen dort nur die
Tests.

**Das Spec sagte: „die Preise der vorigen Fassung archivieren". Das hätte
den Bestand ausgesperrt.** Stripes Kasse nimmt keinen archivierten Preis an.
Eine Praxis im Bestandsschutz kauft Aufstockungen und Bilder aber zum Preis
**ihrer** Fassung nach. Archiviert werden deshalb nur Grund- und
Einrichtungspreis, denn die braucht allein ein neuer Abschluss. Aufstockung
und Bild bleiben aktiv.

**Die Kasse fragt zwei Fassungen.** Ein Abschluss nimmt die aktuelle, eine
Aufstockung und ein Bild nehmen die eigene. Das entspricht AK 7 und AK 13
und ist zugleich der Grund für den vorigen Punkt.

**Eine Einrichtung von 0 € bekommt keinen Preis bei Stripe.**
`hatStripePreise()` verlangt dann keinen. Grundpreis, Aufstockung und Bild
verlangen mindestens 0,50 €: Darunter nimmt Stripes Kasse keine Zahlung an.

**Wiederholungen legen nichts doppelt an.** Jeder Preis wird einzeln
gespeichert, sobald Stripe ihn meldet. Der Idempotenzschlüssel ist
`{Fassung}-{Spalte}`. Eine Wiederholung macht beim fehlenden Preis weiter.
Die Umbenennung des Produkts läuft nur vor dem ersten Preis. Eine zweite
Fassung nimmt die Seite nicht an, solange eine bei Stripe angelegt wird.

**Eine unveränderte Fassung wird abgewiesen** („Es hat sich nichts
geändert"). Ausnahme ist der Fall oben: Stripe ist da, die Preise fehlen.

**Die Ziggy-Datei war seit dem ersten Commit veraltet.** Sie enthielt 22
Horizon-Routen und `appearance`, die es beide nicht mehr gibt. Neu erzeugt,
mit Prettier formatiert.

**Rohbytes:** `plan_version_id` und `pending_plan_version_id` sind binär
und stehen deshalb in `Subscription::$hidden` (`RohbytesTest`).

## Offen

- **Bestätigen:** Die Testphase wechselt immer mit (oben).
- **Einen gescheiterten Umstellungsauftrag erneut anstoßen.** Heute steht
  er im Protokoll der Praxis und in der Betriebslage. Ein Knopf „erneut"
  fehlt, wie bei den Abo-Eingriffen (WP-34c).
- **Die Ankündigung an den Bestand** vor einer Preiserhöhung ist Sache der
  AGB. Die Seite weist darauf hin, das Produkt versendet nichts.
