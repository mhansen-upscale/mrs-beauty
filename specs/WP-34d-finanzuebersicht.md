# WP-34d · Finanzübersicht

> Nachtrag zu WP-34, zugleich dessen offener Punkt **Historie**. Der
> Betreiber sieht heute, wie viele Praxen er hat und wie es ihnen geht, aber
> nicht, was sie ihm einbringen und was sie ihn kosten. Eine Praxis, deren
> Agent jeden Monat für 300 € Modellkosten verursacht, fällt erst auf, wenn
> jemand die Rechnung von Anthropic liest.

## Ziel
Der Betreiber sieht Einnahmen, Kosten und Ergebnis je Monat und je Praxis
als Hochrechnung, die sagt, dass sie eine ist.

## Vorher lesen
- `docs/entscheidungen.md`: **B19** (dieses Paket), **B11** gezählt wird in
  Zehntel-Cent, **B13**, **B14**, **B15** Preismodell, **G10** und **G11**
  Sprachmodell und Kontingent
- `CLAUDE.md`, **Regel 1**
- `specs/WP-06-abo-abrechnung.md`: *Die Nutzung wird abgeleitet, nicht
  zweitgeführt* und *Zwei Orte für dieselbe Zahl*
- `specs/WP-34-backoffice.md`: *Zahlen je Mandant, nie je Person*
- `specs/WP-34c-abo-eingriffe-testphase.md`: Zugangslage, `activated_at`,
  `discount_ends_at`
- `docs/produkt.md`, *Preismodell*, dazu `docs/integrationen/meta.md`,
  *WhatsApp / Kostenmodell*
- Vor der Oberfläche den Skill **`dataviz`** laden

## Voraussetzungen
WP-34a, WP-34c. Außerdem **WP-31b** (Anzeigenformate): Das Paket zählt
Bildsätze und Formatdateien, und die gibt es erst dort (`AdSuggestionImage`).

## Die Linie, an der alles hängt

**Eine Hochrechnung, keine Buchhaltung. Das sagt die Seite auch** (B19).

Einnahmen sind *Preis × Zustand*. Kosten sind *Menge × Satz*. Beides kommt
aus Zahlen, die im Produkt schon stehen: Preise in `mrs.billing.prices`,
Zustände am Abo, Mengen in den Fachtabellen. Rechnungen, Gutschriften,
Erstattungen und Steuern liegen bei Stripe und bleiben dort (WP-06).

Eine Hochrechnung darf danebenliegen. Sie darf aber nicht **doppelt**
zählen, und sie darf nicht verschweigen, was sie nicht weiß. Fehlen die
Fixkosten, steht „nicht hinterlegt" da, keine Null. Fehlt ein Monat, steht
„keine Daten" da, keine Null.

**Zahlen je Mandant, nie je Person.** Die Übersicht kennt Praxen, keine
Patientinnen, keine Behandler, keine Kontakte.

## Was gerechnet wird

**Einnahmen je Praxis und Monat** (netto, Cent):

| Posten | Rechnung | Quelle |
|---|---|---|
| Grundpreis | `base_cents`, wenn die Zugangslage abrechenbar ist: aktiv oder Zahlung offen, nicht pausiert, kein Gratismonat | `subscriptions`, WP-34c |
| Einrichtung | `setup_cents` im Monat von `activated_at`, genau einmal | `subscriptions.activated_at` |
| Aufstockungen | Summe `betrag_cent` im Monat der Zahlung | **neu:** `top_ups` |
| Bilder | Summe `betrag_cent` der gekauften Bilder im Monat der Zahlung | `top_ups` |
| Service-Fenster | Summe `messages.charge_tenth_cents`, nur mit `stripe_subscription_id` | wie `Servicefensterabrechnung` |

**Variable Kosten je Praxis und Monat:**

| Posten | Rechnung | Quelle |
|---|---|---|
| Sprachmodell, Agent | `agent_runs.cost_tenth_cents` × `usd_eur` | vorhanden |
| Sprachmodell, Anzeigentexte | `model_calls.cost_tenth_cents` × `usd_eur` | **neu**, heute verworfen |
| WhatsApp | Anzahl je `MessageCostCategory` × Satz der Kategorie | `messages.cost_category` |
| Bilder | Anzahl **Formatdateien** × `bild_format_zehntel_cent` | `AdSuggestionImage` |
| Zahlungsverkehr | Einnahmen × `stripe_prozent` + `stripe_fix_cent` je Rechnung | gerechnet |

**Rohertrag** = Einnahmen − variable Kosten. **Ergebnis** = Rohertrag −
Fixkosten. Die Fixkosten sind ein Wert aus der Umgebung, nicht je Praxis.

## Schritte

1. **Konfiguration `mrs.backoffice.kosten`**, jeder Wert mit Fundstelle und
   Stand:
   - `usd_eur`: fester Kurs. `mrs.agent.model_pricing` rechnet in
     Zehntel-**US**-Cent.
   - `whatsapp_zehntel_cent` je Kategorie: **Metas** Kosten für uns, nicht
     der Preis an die Praxis. Fundstelle `docs/integrationen/meta.md`.
   - `bild_format_zehntel_cent`: kie.ai rechnet je erzeugter Datei ab, also
     je Format, nicht je Satz.
   - `stripe_prozent`, `stripe_fix_cent`.
   - `fixkosten_cent_monat` aus `BETRIEB_FIXKOSTEN_CENT`, Vorgabe 0,
     angezeigt als „nicht hinterlegt".
2. **Kostenlücke Anzeigentexte schließen.** `App\Anzeigen\Textentwurf` ruft
   das Sprachmodell auf und verwirft den Verbrauch.
   - Die neue Tabelle `model_calls` (TenantModel `ModelCall`) hat die
     Spalten `purpose`, `model`, `input_tokens`, `output_tokens`,
     `cost_tenth_cents`.
   - **Eine Zeile je Aufruf, nicht je Vorschlag.** Ein Aufruf erzeugt bis zu
     drei `ad_suggestions` (`Vorschlagslauf`), und einer, dessen Antwort
     nicht zu lesen war, erzeugt keinen und hat trotzdem gekostet.
   - Bepreist wird über `App\Agent\Verbrauch::kostenZehntelCent()`, wie
     beim Agenten. Keine zweite Preistabelle.
3. **Aufstockungen mit Zeitpunkt.** `StripeWebhookController::nachKasse()`
   schreibt zusätzlich eine Zeile in `top_ups` (TenantModel `TopUp`) mit
   `article`, `quantity`, `amount_cents`, `paid_at` (aus `event.created`)
   und der Stripe-Kennung der Kasse, UNIQUE.
   - Aus `extra_*` lässt sich kein Monat rechnen: Diese Spalten werden an
     der Grenze der Stripe-Periode zurückgesetzt, nicht am Monatsende, und
     `extra_images` gar nicht.
   - `amount_cents` kommt aus `amount_total` der Kasse, nicht aus Menge ×
     Konfiguration.
4. **`App\Backoffice\Finanzuebersicht`:**
   - `monat(CarbonImmutable $monat)` liefert die Praxiszeilen und die
     Summe, `verlauf(int $monate)` die Zeitreihe.
   - **In genau einem `acrossTenants()`** mit Begründung, mit **gruppierten
     Abfragen** je `organization_id`, eine Abfrage je Quelle und nicht je
     Praxis.
   - **Die Methoden von `Nutzungsuebersicht` werden nicht in einer Schleife
     wiederverwendet.** Sie zählen im laufenden Mandanten. Innerhalb von
     `acrossTenants()` lieferten sie die Summe der ganzen Installation, und
     je Praxis in `runAs()` wären es N Abfragen je Quelle.
   - Die gemeinsamen Bedingungen (was zählt als kostenpflichtig, was als
     Bildsatz) wandern in Scopes an den Modellen, damit Abo-Seite und
     Finanzübersicht nicht auseinanderlaufen (WP-06, *Zwei Orte für
     dieselbe Zahl*).
   - Rückgabe als Wertobjekte `Finanzmonat` und `Praxisergebnis` mit
     `toArray()`, nach dem Muster von `Kennzahlensatz`.
5. **Monatsabschluss.** `mrs:monatsabschluss {--monat=}` friert am
   Monatsersten den Vormonat je Praxis in `monthly_closings` ein
   (TenantModel `MonthlyClosing`, UNIQUE `organization_id, month`,
   idempotent: ein zweiter Lauf ändert nichts).
   - Der Lauf kommt **nach** `mrs:servicefenster-abrechnen`, damit der
     Sammelposten schon feststeht.
   - Er läuft auch für gesperrte Praxen: Eine gesperrte Praxis kann im
     Vormonat noch gezahlt haben.
   - Der laufende Monat wird live gerechnet, vergangene Monate kommen aus
     den Abschlüssen. **Monate vor dem ersten Abschluss zeigen „keine
     Daten".** Rückwirkend lässt sich der Abo-Zustand vergangener Monate
     nicht rechnen, denn `subscriptions` kennt nur den Jetzt-Zustand.
6. **Seite `backoffice/Finanzen.vue`** (`finanzen.sehen`):
   - **Kennzahlen:** MRR (Grundpreis aller abrechenbaren Praxen), ARR, Zahl
     der zahlenden Praxen, in Testphase, Testphase abgelaufen, pausiert,
     gekündigt zum Periodenende, Kündigungen im Monat, Einnahmen, Kosten,
     Rohertrag, Marge, Ergebnis.
   - **Zwölf Monate:** Einnahmen und Kosten nebeneinander, das Ergebnis mit
     Nulllinie. Reine CSS-Balken wie in `werbung/Index.vue` können keinen
     negativen Wert zeigen. Nur Farbtokens (`FarbenTest`), Gestaltung nach
     `dataviz`.
   - **Tabelle je Praxis** (`DataTable.vue`): Name, Zugangslage, Einnahmen,
     Kosten, Deckungsbeitrag. Ein negativer Beitrag ist hervorgehoben und
     führt zum Mandantenblatt.
   - Die Hinweiszeile: „Hochrechnung aus Preisen und Nutzung — maßgeblich
     sind die Rechnungen bei Stripe."
7. **Mandantenblatt:** Ein Kasten „Wirtschaftlichkeit" mit den letzten drei
   Monaten, nur mit `finanzen.sehen`. Customer Success sieht das Blatt ohne
   ihn.
8. **Seitenleiste:** Der Punkt „Finanzen" erscheint nur mit
   `finanzen.sehen`, mit Eintrag in `useEinfuehrung.ts`.

## Abnahmekriterien

**Einnahmen**

1. Eine aktive Praxis bringt im Monat den Grundpreis aus
   `mrs.billing.prices.base_cents`.
2. Eine pausierte Praxis, eine im Gratismonat, eine in der Testphase und
   eine nach Periodenende gekündigte bringen keinen Grundpreis.
3. Eine Praxis mit offener Zahlung zählt mit, denn sie ist noch nicht
   verloren.
4. Die Einrichtung zählt genau einmal, im Monat von `activated_at`, auch
   wenn das Abo später pausiert und fortgesetzt wird.
5. Eine Aufstockung zählt im Monat ihrer Zahlung, mit dem gezahlten Betrag.
6. Dieselbe Kasse zweimal gemeldet ergibt eine Zeile in `top_ups`.
7. Der Service-Fenster-Betrag zählt nur bei laufendem Stripe-Abo.

**Kosten**

8. Die Modellkosten des Agenten werden aus Zehntel-US-Cent in Euro
   umgerechnet.
9. Ein Textentwurf mit drei Vorschlägen kostet einmal, einer ohne lesbare
   Antwort kostet trotzdem.
10. Ein Bildsatz mit drei Formaten kostet dreimal den Formatsatz.
11. WhatsApp wird je Kategorie bepreist. `none` kostet nichts.
12. **Nichts wird doppelt gezählt:** Ein Wartelistenangebot geht nicht
    zusätzlich zu seiner Nachricht ein (`waitlist_offers.cost_micros` stammt
    aus `charge_tenth_cents`), und der Preis an die Praxis wird nicht als
    Kosten gezählt.

**Summen und Historie**

13. Die Summe der Praxiszeilen ergibt die Gesamtzahl, in jeder Spalte.
14. Der Monatsabschluss ist idempotent. Ein zweiter Lauf für denselben Monat
    ändert keine Zeile.
15. Ein Monat vor dem ersten Abschluss erscheint als „keine Daten", nicht
    als 0.
16. Ohne hinterlegte Fixkosten steht „nicht hinterlegt" statt eines
    Ergebnisses.

**Grenzen**

17. Customer Success bekommt auf der Seite einen 403 und sieht im
    Mandantenblatt keinen Kasten „Wirtschaftlichkeit". Finanzen und
    Super-Admin sehen beides.
18. Ein Seitenaufruf schreibt **genau einen** `tenant.cross_access`-Eintrag.
19. Kein Kontaktname, keine E-Mail-Adresse und keine Telefonnummer kommt in
    der Antwort vor. Die Probe läuft wie in `BackofficeTest`.
20. Die Zahl der Abfragen je Seitenaufruf hängt nicht von der Zahl der
    Praxen ab.

## Nicht in diesem Paket

- **Stripe-Rechnungen, Zahlungseingänge, Erstattungen, Umsatzsteuer.** Das
  bleibt bei Stripe (B19). Wer die tatsächlichen Zahlen braucht, nimmt den
  Stripe-Export.
- **Fixkosten im Backoffice pflegen.** Vorerst ein Wert aus der Umgebung.
- **Kosten der Infrastruktur je Praxis** (Speicher, Rechenzeit).
  Verteilungsschlüssel ohne Messung sind Meinungen.
- **Prognosen, Kohorten, Churn-Modelle.** Erst Historie sammeln.
- **Ein Export als Datei.** Vielleicht später, dann über den Monatsabschluss.

## Fallstricke

- **Der Preis steht an der Fassung, nicht in der Konfiguration** (WP-06b,
  B20). Wer mit dem aktuellen Grundpreis rechnet, rechnet den Bestandsschutz
  weg: Eine Praxis auf Fassung 1 zahlt den Preis von Fassung 1.
- **Zwei Orte für dieselbe Zahl.** Zählt die Finanzübersicht Bilder oder
  kostenpflichtige Nachrichten anders als die Abo-Seite der Praxis, glaubt
  bald niemand mehr einer von beiden (WP-06). Die Bedingungen stehen
  deshalb in Scopes, nicht zweimal in Abfragen.
- **Preis ist nicht Kosten.** `messages.charge_tenth_cents` ist, was die
  Praxis zahlt. Was Meta uns berechnet, kommt aus dem Satz je Kategorie.
- **Bilder sind vorausbezahlt.** Sie sind nicht „über dem Kontingent ×
  2 €": Der Vorschlagslauf sperrt bei null, und jedes weitere Bild wird
  vorher gekauft. Die Einnahme ist der Kauf, nicht die Nutzung.
- **Ein Satz ist nicht eine Datei.** Die Praxis zahlt je Satz (B13), kie.ai
  rechnet je Format ab. Wer beides gleichsetzt, unterschätzt die Kosten um
  das Dreifache.
- **US-Cent sind keine Euro-Cent.** `model_pricing` rechnet in
  Zehntel-US-Cent. Ohne Umrechnung sieht die Marge jeden Monat anders aus,
  und niemand weiß, warum.
- **`Nutzungsuebersicht` im Querzugriff** zählt die ganze Installation und
  schreibt sie jeder Praxis zu.
- **Die Hochrechnung als Buchhaltung ausgeben.** Eine Zahl, die aussieht wie
  ein Umsatz, wird als Umsatz weitergegeben. Deshalb steht auf der Seite,
  was sie ist.
- **Eine Zahl kann verraten** (WP-34). Hier nur je Praxis und im Monat, nie
  je Tag und nie je Person.
