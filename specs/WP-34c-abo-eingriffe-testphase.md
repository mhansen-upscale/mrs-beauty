# WP-34c · Abo-Eingriffe & Testphase

> Nachtrag zu WP-06 und WP-34. Das Backoffice sieht das Abo einer Praxis,
> kann aber nichts daran tun. Wer eine Praxis für drei Monate Umbau
> pausieren, eine Kündigung zurücknehmen oder als Kulanz einen Monat
> schenken will, muss heute ins Stripe-Dashboard, und das Produkt erfährt
> davon nur zufällig richtig. Dazu kommt: Das Ende der Testphase steht auf
> der Abo-Seite, wirkt aber nirgends.

## Ziel
Der Betreiber greift aus dem Backoffice in das Abo einer Praxis ein, und
das Produkt setzt jeden Zugangszustand an einer Stelle durch, auch das Ende
der Testphase.

## Vorher lesen
- `docs/entscheidungen.md`: **B17** und **B18** (dieses Paket), **B9** kein
  Cashier, **B12** eine Antwort wird nie gesperrt, **B14**
- `CLAUDE.md`, **Regel 4**
- `specs/WP-06-abo-abrechnung.md`, vollständig, besonders AK 11–15 und
  „Mahnwesen und Sperre bei dauerhaftem Zahlungsausfall"
- `specs/WP-34a-betreiberrollen-anmeldung.md`: Fähigkeiten `abo.sehen`,
  `abo.eingreifen`, `testphase.verlaengern`, `current_password`
- Stripe: *Pause payment collection*, *Cancel subscriptions*, *Coupons*,
  *Webhooks: event ordering* und die **Änderungen der API-Version
  `2025-03-31.basil`**. Vor dem Bau erneut lesen.

## Voraussetzungen
WP-06, WP-34a.

## Die Linie, an der alles hängt

**Der Betreiber beauftragt, Stripe entscheidet, der Webhook berichtet.**

Das Backoffice schreibt keinen Abo-Zustand. Es legt einen Auftrag an, der
Auftrag spricht mit Stripe, und was daraus wird, meldet der Webhook, wie
jede andere Änderung auch (WP-06 AK 13). Ein Zustand, den das Backoffice
„schon mal" setzt, weicht ab, sobald Stripe anders antwortet oder jemand im
Portal etwas tut.

Die einzige Ausnahme ist die **Testphase ohne Stripe-Abo**. Sie lebt nur bei
uns, und ihre Verlängerung wirkt sofort.

## Zwei Arten von Sperre, zu bestätigen

| | Abo-Sperre | Betreiber-Sperre (WP-34, WP-34a) |
|---|---|---|
| Auslöser | unbezahlt, **pausiert** (B17), **Testphase abgelaufen** (B18), gekündigt nach Periodenende | `suspended_at` |
| Anmelden | ja, landet auf der Abo-Seite bzw. einer Sperrseite | nein |
| Erinnerungen an gebuchte Termine | **laufen weiter** (WP-06) | ausgesetzt |
| Eingehende Nachrichten | werden gespeichert | werden gespeichert |
| Agent | **läuft nicht** | läuft nicht |
| Buchungsseite | „Online-Buchung derzeit nicht möglich", mit den Kontaktdaten der Praxis | nicht erreichbar |
| Backoffice, Impersonation beenden | offen | offen |

Die Zeilen *Agent* und *Buchungsseite* bei der Abo-Sperre sind in dieser
Session **ergänzt, nicht beauftragt**. Eine Praxis, die nicht hineinkommt,
sieht weder die Termine, die der Agent bucht, noch die Buchungen über ihre
Seite. **Vor dem Bau bestätigen.**

## Die Eingriffe

| Eingriff | Wer | Wo | Wirkung |
|---|---|---|---|
| Pausieren (optional mit Enddatum) | `abo.eingreifen` | Stripe `pause_collection[behavior]=void` | keine Rechnung; **Zugang gesperrt wie unbezahlt** (B17) |
| Fortsetzen | `abo.eingreifen` | Stripe `pause_collection=` | Zugang offen |
| Kündigen zum Periodenende | `abo.eingreifen` | Stripe `cancel_at_period_end=true` | offen bis Periodenende |
| Kündigung zurücknehmen | `abo.eingreifen` | Stripe `cancel_at_period_end=false` | wie vorher |
| Sofort kündigen | `abo.eingreifen` | Stripe `DELETE /subscriptions/{id}` | gesperrt nach dem Webhook |
| Gratismonat | `abo.eingreifen` | Stripe-Gutschein 100 %, `duration=once` | nächste Rechnung 0 € |
| Testphase verlängern (+N Tage) | `testphase.verlaengern` | nur lokal | sofort offen |

## Schritte

1. **Die Zugangslage an einer Stelle.** `Subscription::zugang(?jetzt):
   SubscriptionAccess` (Enum) mit den Fällen `Open`, `Trial`,
   `TrialExpired`, `Paused`, `Unpaid`, `Canceled`, jeweils mit
   `sperrtZugang()`, `darfNutzen()`, `label()` und `hinweis()`.
   - Es fragen **nur noch dort** nach: `EnsureAboGilt`,
     `Kontingente::darfKostenpflichtigSenden()` (heute
     `status->darfNutzen()`) und `App\Agent\Agentenlauf` mit neuem
     Überspringgrund. Der prüft heute nur `erschoepft()`, deshalb läuft der
     Agent einer unbezahlten Praxis weiter und kostet.
   - Ebenso `AnzeigenVorschlagen` und die Buchungsseite
     (`ResolvePublicTenant` bzw. `PublicBookingController`).
   - `SubscriptionStatus::darfNutzen()` und `sperrtZugang()` bleiben als
     Bausteine, werden aber außerhalb von `zugang()` nicht mehr gefragt.
2. **`EnsureAboGilt`:**
   - `OFFEN` bekommt dazu: `impersonation` und `impersonation/*`, damit ein
     Betreiber eine Sitzung in einer gesperrten Praxis beenden kann, die
     PIN-Routen aus WP-34b und `confirm-password`.
   - Rollen **ohne** `billing.manage` landen auf einer neuen Sperrseite
     („Das Abo dieser Praxis ruht. Bitte wenden Sie sich an Ihre
     Inhaberin."). Heute bekommen sie auf `abo.edit` einen 403.
   - Der Text unterscheidet die Zustände.
3. **Testphase durchsetzen** (B18):
   - `Kontingente::abo()` setzt `trial_ends_at` auf
     `organizations.created_at + mrs.billing.trial_days`, **nicht** auf
     `now()`. Heute entsteht die Zeile beim ersten Zugriff irgendwann
     später, und die Testphase beginnt damit zu einem zufälligen Zeitpunkt.
   - Gesperrt wird bei `Trial`, `trial_ends_at` in der Vergangenheit und
     ohne `stripe_subscription_id`.
   - **Ausroll-Migration:** Sie legt für jede Praxis ohne Abo-Zeile eine an
     und setzt jede bereits abgelaufene Testphase auf heute +
     `mrs.billing.trial_gnadenfrist_tage`. Niemand wird über Nacht
     ausgesperrt.
4. **Migration `subscriptions`:** `paused_at`, `pause_resumes_at`,
   `cancel_at_period_end` (bool), `cancel_at`, `discount_ends_at`,
   `activated_at` (erster Übergang nach `active`, für die Einrichtung in
   WP-34d) und `stripe_event_at`. Alles `DATETIME`, und alles, was Stripe
   meldet, in `auditableValues()`.
5. **Den Webhook härten.** WP-06 AK 15 („dieselbe Zustellung zweimal ändert
   nichts zweimal") gilt heute nur, weil die meisten Handler zufällig
   idempotent sind. `checkout.session.completed` schreibt eine Aufstockung
   zweimal gut.
   - Die neue Tabelle `stripe_events` (Modell `StripeEvent`) hält die
     Ereigniskennung UNIQUE und `received_at`. Sie hat **keine**
     `organization_id`, gilt deshalb im ArchitekturTest nicht als
     Mandantentabelle und braucht keine Ausnahme. Ein bekanntes Ereignis
     wird mit 200 verworfen.
   - **Reihenfolge:** Stripe garantiert keine. Ein Ereignis mit
     `created` < `subscriptions.stripe_event_at` ändert keinen Zustand.
     Zeitstempel wie `paused_at` kommen aus `event.created`, nicht aus
     `now()`.
   - `zustand()` liest zusätzlich `pause_collection` (mit `resumes_at`),
     `cancel_at_period_end`, `cancel_at` und das Ende des Rabatts.
     `SubscriptionStatus::ausStripe()` kennt `paused` (Stripes eigener
     Zustand nach einer Testphase ohne Zahlungsmittel). Heute wird daraus
     `Canceled`.
6. **`Stripe-Version` festnageln.** `Stripeclient::anfrage()` schickt den
   Header, und der Webhook-Endpunkt bei Stripe steht auf derselben Version.
   Ab `2025-03-31.basil` liegen `current_period_start/end` an den
   Positionen des Abos, nicht mehr am Abo. `zustand()` liest sie heute am
   Abo. Mit der Version des Kontos abgleichen, bevor etwas gebaut wird.
7. **Tabelle `subscription_changes`** (TenantModel `SubscriptionChange`):
   - Spalten: `subscription_id`, `action` (Enum `SubscriptionChangeAction`:
     `pause`, `resume`, `cancel_period_end`, `revoke_cancel`, `cancel_now`,
     `free_month`, `extend_trial`), `parameters` (json, ohne Personenbezug:
     `resumes_at`, `days`), `reason`, `status` (`pending`, `done`,
     `failed`), `error`, `requested_by_user_id`, `idempotency_key`,
     `completed_at`.
   - Das ist **der Auftrag mit seinem sichtbaren Stand** (Regel 4), keine
     zweite Zustandsführung. Der Zustand des Abos kommt weiter aus dem
     Webhook.
8. **`Stripeclient`:** `pausiere()`, `setzeFort()`,
   `kuendigeZumPeriodenende()`, `nimmKuendigungZurueck()`,
   `kuendigeSofort()` und `gewaehreGutschein()`, jeweils mit
   `Idempotency-Key` nach dem Muster von `rechnungsposten()`. Neuer Wert
   `services.stripe.free_month_coupon` (`STRIPE_FREE_MONTH_COUPON_ID`). Der
   Gutschein wird einmal im Stripe-Konto angelegt, wie die Preise.
9. **Auftrag `AboEingriffAusfuehren`** (Queue `default`, `tries` und
   `backoff` wie `ServicefensterAbrechnen`, unique je Eingriff):
   - Fehler werden nach `App\Support\Fehlereinordnung` behandelt.
     Wiederholbares wird wiederholt, Endgültiges wird `failed` mit Grund.
   - Ein gescheiterter Eingriff steht im Mandantenblatt und in
     `Betriebslage::fuerInstallation()`, **nicht nur im Log**.
   - `extend_trial` läuft ohne Auftrag, sofort: Es gibt kein Fremdsystem.
10. **Backoffice:**
    - Im Mandantenblatt ein Kasten „Abo":
      - Zugangslage, Periode, Testphase bis, pausiert bis, Kündigung zum,
        Gratismonat bis.
      - Link ins Stripe-Dashboard, `…/test/…`, solange der Schlüssel mit
        `sk_test_` beginnt.
      - Die Liste der Eingriffe mit Stand.
    - Dialoge (`FormularDialog.vue`) mit Pflichtbegründung und
      `current_password`, sichtbar je Fähigkeit.
    - Mandantenliste: Filter nach Zugangslage und „Testphase endet in
      weniger als `mrs.backoffice.testphase_warnung_tage` Tagen". Die
      Zählungen je Zeile werden auf **gruppierte Abfragen** umgestellt, heute
      sind es vier Abfragen je Praxis.
11. **Protokoll:** Jede Handlung über `vermerke()` beim Mandanten, mit den
    neuen `AuditEvent`-Fällen `subscription.change_requested`,
    `subscription.change_failed` und `subscription.trial_extended`.
12. **Konfiguration** `mrs.billing`: `trial_gnadenfrist_tage` (14),
    `trial_verlaengerung_max_tage` (30 je Eingriff). Dazu `mrs.backoffice`:
    `testphase_warnung_tage` (7). Fundstellen **B17**, **B18**.

## Abnahmekriterien

**Zugangslage**

1. Unbezahlt, pausiert, Testphase abgelaufen und gekündigt nach
   Periodenende sperren den Zugang. Aktiv, Zahlung offen und laufende
   Testphase sperren ihn nicht.
2. Bei gesperrtem Abo landet die Inhaberin auf der Abo-Seite, eine
   Empfangskraft auf der Sperrseite. Keine bekommt einen 403.
3. Bei gesperrtem Abo bleiben Abmelden, Backoffice und das Beenden einer
   Impersonation erreichbar.
4. Bei gesperrtem Abo gehen Erinnerungen an gebuchte Termine weiter hinaus.
5. Bei gesperrtem Abo läuft der Agent nicht. Die Nachricht ist gespeichert.
6. Bei gesperrtem Abo zeigt die Buchungsseite den Hinweis mit den
   Kontaktdaten und nimmt keine Buchung an.
7. Bei aktivem Abo und leerem Kontingent geht eine Antwort im
   Service-Fenster weiterhin hinaus (B12).

**Testphase**

8. Eine Praxis, deren `created_at` länger als `trial_days` zurückliegt, ohne
   Stripe-Abo, ist gesperrt, auch wenn ihre Abo-Zeile eben erst entstanden
   ist.
9. Customer Success verlängert um N Tage. Die Praxis ist sofort wieder
   offen, mit Protokolleintrag.
10. Mehr als `trial_verlaengerung_max_tage` in einem Eingriff werden
    abgewiesen.
11. Nach der Ausroll-Migration ist keine bestehende Praxis gesperrt, die es
    vorher nicht war.

**Eingriffe**

12. Pausieren legt einen Auftrag an und ruft Stripe **nicht** im
    Anfragezyklus. Der Aufruf trägt einen Idempotenzschlüssel.
13. Der Webhook mit gesetztem `pause_collection` sperrt, einer ohne
    `pause_collection` öffnet wieder.
14. Kündigen zum Periodenende lässt den Zugang bis dahin offen. Die
    Rücknahme hebt `cancel_at_period_end` auf.
15. Sofort kündigen sperrt nach `customer.subscription.deleted`.
16. Der Gratismonat setzt den Gutschein, und `discount_ends_at` steht nach
    dem Webhook.
17. Scheitert Stripe endgültig, steht der Eingriff als gescheitert mit Grund
    im Mandantenblatt und in der Betriebslage.
18. Customer Success kann nicht pausieren, kündigen oder einen Monat
    schenken. Finanzen kann gar nichts davon.
19. Ohne richtiges `current_password` entsteht kein Auftrag.
20. Jeder Eingriff steht mit Begründung und Namen im Protokoll der Praxis.

**Webhook**

21. Dasselbe Ereignis zweimal ändert nichts zweimal. Das gilt ausdrücklich
    auch für `checkout.session.completed` mit einer Aufstockung.
22. Ein älteres Ereignis, das nach einem neueren eintrifft, überschreibt den
    Zustand nicht.
23. `paused` von Stripe wird nicht zu `Canceled`.

## Nicht in diesem Paket

- **Zahlen, Einnahmen, Hochrechnung.** Das ist WP-34d.
- **Preise ändern, Rabatte in Prozent, Jahresabo.** Das Jahresabo steht in
  `docs/produkt.md` als offen.
- **Erstatten und Gutschriften auf Rechnungen.** Das bleibt im
  Stripe-Dashboard: Eine Erstattung ist eine Buchung, kein
  Zugangszustand.
- **Mahnwesen.** Das macht Stripe (WP-06).
- **Den Zustand eines Abos von Hand überschreiben.** Bewusst nicht, siehe
  die Linie.

## Fallstricke

- **`pause_collection` ändert den Status nicht.** Ein pausiertes Abo ist bei
  Stripe weiterhin `active`. Wer nur `status` liest, sieht keine Pause. Die
  Sperre hängt an `paused_at`, nicht an `SubscriptionStatus`.
- **Die Testphase beginnt nicht, wenn die Zeile entsteht.** `abo()` legt sie
  beim ersten Zugriff an, beim Agenten, beim Monatslauf oder auf der
  Abo-Seite. Mit `now()` bekäme jede Praxis eine Testphase, die irgendwann
  beginnt.
- **Ausrollen ohne Gnadenfrist** sperrt jede Praxis, die seit mehr als 30
  Tagen ohne Abo arbeitet, also womöglich alle Pilotpraxen.
- **Eine Sperre, aus der man nicht herauskommt, ohne hineinzukommen, ist
  eine Falle** (WP-06). Die Abo-Seite und die Kasse bleiben offen, auch
  für eine abgelaufene Testphase.
- **Ein Betreiber in einer gesperrten Praxis** wird heute auf `abo.edit`
  umgeleitet und bekommt dort einen 403, weil ihm `billing.manage` fehlt. Er
  kommt nicht einmal mehr hinaus.
- **Stripe liefert in keiner festen Reihenfolge.** Ein
  `customer.subscription.updated` von vor der Pause, das nach ihr ankommt,
  hebt sie sonst still wieder auf.
- **Die API-Version.** Ohne festen Header gilt die Version des Kontos. Beim
  Wechsel auf `basil` stehen `current_period_*` woanders, und jede neue
  Periode bleibt unbemerkt, also auch das Zurücksetzen der Aufstockungen.
- **Den Zustand im Backoffice vorwegnehmen.** „Pausiert" anzuzeigen, bevor
  der Webhook es bestätigt, heißt, eine Pause anzuzeigen, die es vielleicht
  nie gab. Angezeigt wird „beauftragt", bis Stripe es meldet.

## Stand

Die 23 Abnahmekriterien laufen, in **37 Tests**:

| Datei | Deckt ab |
|---|---|
| `tests/Feature/Abrechnung/ZugangslageTest.php` (19, davon 8 als Datensatz) | 1–10 |
| `tests/Parallel/TestphaseMigrationTest.php` (1) | 11 |
| `tests/Feature/Abrechnung/AboEingriffeTest.php` (12) | 12–20, dazu der Testbetrieb ohne Stripe |
| `tests/Feature/Abrechnung/StripeZustellungTest.php` (5) | 21–23, dazu Periode an den Positionen und `activated_at` |

AK 7 (Antwort im Fenster bei aktivem Abo) prüft weiterhin die B12-Probe in
`WhatsAppVersandTest`. Gesamtstand **1374 Tests, 5333 Zusicherungen**,
`composer check` grün.

Neu:
- **Enums** `SubscriptionAccess`, `SubscriptionChangeAction`,
  `SubscriptionChangeStatus`, `SubscriptionStatus::Paused` und
  `GuardrailHit::SubscriptionLocked`.
- **Modelle** `Subscription::zugang()` und `testphasenende()`,
  `SubscriptionChange`.
- **Dienste** `App\Abrechnung\Abozugang` (nur lesend) und
  `App\Abrechnung\Aboeingriffe`.
- **Auftrag** `AboEingriffAusfuehren`.
- **Stripe**: sechs Methoden an `Stripeclient`, der Header `Stripe-Version`
  und `Fehlereinordnung::ausStripeAntwort()`.
- **Webhook** mit `stripe_events`, Reihenfolge über `stripe_event_at`, Pause,
  Kündigung und Rabatt.
- **Migrationen** `2026_09_27_130000_abo_eingriffe` und
  `2026_09_27_130100_testphase_gnadenfrist`.
- **Seiten** `settings/AboGesperrt.vue`, `buchung/NichtVerfuegbar.vue` und
  der Abo-Kasten `components/AboKasten.vue` im Mandantenblatt.
- **Praxenliste**: Filter nach Zugangslage und „Testphase endet bald", die
  Zählungen gruppiert.

**Bestätigt durch den Bau:** Die beiden Zeilen „Agent" und „Buchungsseite" bei
der Abo-Sperre, oben als *zu bestätigen* markiert, sind so umgesetzt.

## Was das Bauen zutage gefördert hat

**Testbetrieb ohne Stripe** (Wunsch vom 27.09.2026: „dass jetzt nichts
failed, wenn wir Stripe noch nicht per API-Key dran haben").
- Ohne Schlüssel gibt es niemanden, der über den Webhook berichten könnte.
  Ein Eingriff wirkt dann **sofort lokal** und trägt `ohne_stripe`.
- Das Mandantenblatt zeigt „Testbetrieb" und markiert jeden solchen
  Eingriff mit „ohne Stripe".
- Mit Schlüssel läuft alles wie beschrieben: Auftrag, Stripe, Webhook.
- Die Kasse sagt weiterhin „Die Abrechnung ist nicht eingerichtet", statt zu
  scheitern.

**Der Webhook war nur zufällig idempotent.** Ein doppelt zugestelltes
`checkout.session.completed` schrieb eine Aufstockung zweimal gut. Jetzt
stehen Vermerk in `stripe_events` und Verarbeitung in **einer**
Transaktion. Scheitert die Verarbeitung, ist auch der Vermerk weg, und
Stripes Wiederholung kommt durch.

**Zwei vorhandene Tests reisten in eine gesperrte Zukunft.**
`AttributionTest` und `ErinnerungenTest` legten ihre Praxis an und sprangen
danach Monate vorwärts. Mit durchgesetzter Testphase ist sie dann zu Recht
gesperrt, nur geht es in beiden Tests nicht darum. Der eine legt die Praxis
jetzt zur Testzeit an, der andere gibt ihr mit `bezahltesAbo()` ein
bezahltes Abo.

**Die Sperre am Agenten sitzt im Not-Aus**, wie schon die Betreiber-Sperre
aus WP-34a: `Schutz::notAus()` fragt `Abozugang`, mit eigenem Grund
`subscription_locked`.

**Ein Stripe-Ausfall im Testbetrieb mit `sync`-Warteschlange** würde als
Fehler der Anfrage enden, weil der Auftrag wirft, damit die Warteschlange
wiederholt. In Produktion läuft die Warteschlange getrennt. Lokal tritt der
Fall nur mit gesetztem Schlüssel auf.

## Offen

- **Die API-Version des Webhook-Endpunkts bei Stripe** auf
  `2025-02-24.acacia` stellen, dieselbe wie `STRIPE_API_VERSION`.
- **Den Gutschein anlegen** (100 %, einmal) und als
  `STRIPE_FREE_MONTH_COUPON_ID` setzen.
- **Einen gescheiterten Eingriff erneut versuchen.** Heute legt man ihn neu
  an; ein Knopf „erneut" wäre bequemer.
