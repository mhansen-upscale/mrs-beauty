# WP-06 · Abo & Abrechnung

## Ziel
Die Praxis sieht, was sie verbraucht hat, und zahlt dafür — ohne dass jemand
eine Tabelle führt.

## Vorher lesen
- `docs/entscheidungen.md` — **B7** Erfassung beim Versand, **B8** WhatsApp
  nicht unbegrenzt, **B9** bis **B12** (dieses Paket), **G11** Kontingent des
  Assistenten
- `docs/integrationen/meta.md`, Abschnitt **WhatsApp / Kostenmodell**
- `CLAUDE.md`, Regeln 1, 3 und 4

## Voraussetzungen
WP-20 bis WP-25 — alles, was Geld kostet, muss zuerst gezählt werden.

## Die Nutzung wird abgeleitet, nicht zweitgeführt

**Keine `usage_records`-Tabelle.** Was Geld kostet, steht schon irgendwo:

| Was | Wo | Seit |
|---|---|---|
| kostenpflichtige Nachricht | `messages.cost_category` | WP-19/20a |
| Assistenzlauf | `agent_runs.cost_tenth_cents` | WP-22 |
| Wartelistenangebot | `waitlist_offers` | WP-25 |

Eine zweite Zeile je Vorgang wäre ein zweiter Ort für dieselbe Zahl — und
zwei Orte gehen irgendwann auseinander. Dann glaubt niemand mehr einem von
beiden, am wenigsten die Praxis, die eine Rechnung dazu bekommt.

Das ist auch **B7** („Erfassung beim Versand, nicht nachgelagert"): die
Erfassung *ist* die Nachrichtenzeile, und die entsteht beim Versand.

## Was begrenzt wird — und was nie

**Entscheidung B12.** Eine Antwort im offenen Service-Fenster kostet nichts
und wird **nie** gesperrt. Was Geld kostet — ein Template außerhalb des
Fensters, ein Assistenzlauf — zählt gegen das Kontingent und ist nach dessen
Ende gesperrt, bis jemand aufstockt.

Eine Praxis darf nie daran gehindert werden, einer Patientin zu antworten.
Das ist keine Kulanz, sondern der Unterschied zwischen einem Werkzeug und
einer Falle.

## Schritte

1. `subscriptions` — je Praxis eine, mit der Kennung bei Stripe.
2. `Nutzungsuebersicht` — der Monat, aus den vorhandenen Tabellen gerechnet.
3. `Kontingente` — was enthalten ist, was aufgestockt wurde, was bleibt.
4. `Stripeclient` — Kunde, Checkout, Portal; dünn, wie die anderen Clients.
5. Webhook `POST /webhooks/stripe`, Signatur zeitkonstant geprüft.
6. *Einstellungen → Abo*: Stand, Verbrauch, Rechnungen, Kündigung.
7. Sperre für kostenpflichtigen Versand bei leerem Kontingent.

## Abnahmekriterien

**Nutzung**

1. Eine kostenpflichtige Nachricht zählt, eine kostenlose nicht.
2. Ein Assistenzlauf zählt mit seinen Kosten.
3. Ein Wartelistenangebot zählt.
4. Gezählt wird je Kalendermonat und je Mandant.
5. Die Zahlen stammen aus den Fachtabellen, nicht aus einer zweiten Erfassung.

**Kontingent**

6. Innerhalb des Kontingents geht alles hinaus.
7. Ist es leer, wird ein **kostenpflichtiger** Versand gesperrt.
8. Ist es leer, geht eine Antwort im offenen Fenster **trotzdem** hinaus.
9. Eine Aufstockung gibt den Versand wieder frei.
10. Die Sperre ist im Produkt sichtbar, nicht nur im Log.

**Abo**

11. Ohne Abo ist die Praxis in der Probezeit, nicht gesperrt.
12. Eine gekündigte Praxis behält Lesezugriff bis zum Periodenende.
13. Der Zustand kommt aus dem Webhook, nicht aus einer Vermutung.
14. Eine Zustellung mit falscher Signatur wird verworfen.
15. Dieselbe Zustellung zweimal ändert nichts zweimal.

**Anzeige**

16. Die Praxis sieht Mengen, nicht Cent (B11).
17. Sie sieht, was enthalten ist und was sie aufgestockt hat.
18. Sie kommt von dort zu ihren Rechnungen.

## Nicht in diesem Paket

- **Mehrere Stufen** (B10).
- **Einzelposten je Nachricht auf der Rechnung** (B11).
- **Mahnwesen.** Stripe schickt die Erinnerungen; was bei dauerhaftem
  Zahlungsausfall geschieht, ist eine Betreiberentscheidung und gehört zu
  WP-34.
- **Umsatzsteuer-Logik.** Stripe Tax rechnet, wir stellen die Daten.

## Fallstricke

- **Zwei Orte für dieselbe Zahl.** Die Rechnung muss aus denselben Zeilen
  kommen wie die Anzeige im Produkt.
- **Eine Sperre, die eine Antwort verhindert**, macht aus dem Produkt eine
  Falle (B12).
- **Ein Webhook ohne Signaturprüfung** lässt jeden das Abo verlängern.

## Stand

Die 18 Abnahmekriterien laufen: `tests/Feature/Abrechnung/AbrechnungTest.php`
(**16 Tests**), dazu die B12-Probe im WhatsApp-Versand. Gesamtstand 816.

Neu: `subscriptions`, `SubscriptionStatus`, `App\Abrechnung`
(`Nutzungsuebersicht`, `Kontingente`, `Stripe\Stripeclient`,
`Stripe\Stripesignatur`), `AboController`, `StripeWebhookController` unter
`POST /webhooks/stripe` und die Seite *Einstellungen → Abo*.

## Was das Bauen zutage gefördert hat

**Zwei Kontingente für dieselbe Sache.** WP-23 hatte dem Assistenten eine
eigene Tabelle gegeben (`agent_budgets`, in Zehntel-Cent), WP-06 beantwortet
dieselbe Frage aus dem Abo — in Läufen, wie die Praxis sie sieht. Zwei Orte
für dieselbe Zahl, genau das Muster, das die Nutzungsübersicht vermeidet.

Aufgelöst: `App\Agent\Kontingent` ist jetzt eine **Sicht** auf
`App\Abrechnung\Kontingente`, `agent_budgets` ist weg, und aufgestockt wird
an einer Stelle — beim Abo, gegen Bezahlung, statt auf Knopfdruck.

**Die Aufstockung wird erst nach der Zahlung gebucht.** Der erste Entwurf
schrieb sie beim Öffnen der Kasse gut — das hätte Kontingent an jeden
verschenkt, der die Kasse wieder schließt. Jetzt meldet der Webhook
`checkout.session.completed` mit `payment_status = paid`, und erst dann zählt
es.

**Eine neue Periode räumt Aufgestocktes ab.** Sonst wächst das Kontingent
still von Monat zu Monat: wer im Januar 250 dazukauft, hätte sie im Februar
noch.

**Die Sperre trifft nie eine Antwort.** Entscheidung B12 ist im Code genau
eine Stelle: die Prüfung sitzt im Zweig *außerhalb des Service-Fensters*, wo
ein Template nötig ist — der Zweig, der Geld kostet. Der Test schickt bei
leerem Kontingent erst ein Template (gesperrt, `quota_exhausted`) und dann
eine Antwort im offenen Fenster derselben Praxis (geht hinaus).

## Offen

**Die Preise selbst.** `STRIPE_PRICE_ID`, `STRIPE_TOPUP_PRICE_ID` und
`STRIPE_IMAGE_PRICE_ID` zeigen auf Produkte, die im Stripe-Konto angelegt
werden müssen. **Ein Preis steht seit dem 20.09.2026 fest:** **30**
Anzeigenbilder sind enthalten, jedes weitere kostet 2 Euro. (Am selben Tag
von 15 auf 30 angehoben: gezählt wird jede erzeugte Datei, und die zweite
Fassung einer Grafik ist der Normalfall — das Bildmodell verschreibt sich
bei deutscher Schrift.) Grundpreis und Blockpreise stehen
in `docs/produkt.md` weiterhin unter „Zu füllen".

**Mahnwesen und Sperre bei dauerhaftem Zahlungsausfall.** ~~Offen.~~
**Entschieden am 20.09.2026: nach der letzten Mahnung wird der Zugang
gesperrt.**

Umgesetzt als `SubscriptionStatus::Unpaid` — Stripes eigener Zustand, wenn
alle Einzugsversuche gescheitert sind — plus `EnsureAboGilt`. `past_due`
sperrt weiterhin nicht: wer beim ersten fehlgeschlagenen Einzug abschaltet,
verliert einen Kunden wegen einer abgelaufenen Karte.

Drei Wege bleiben offen, jeder aus einem eigenen Grund: **das Abo selbst**
(eine Sperre, aus der man nicht herauskommt, ohne hineinzukommen, ist eine
Falle), **das Abmelden**, und **der Betreiber** (WP-34 — er sperrt und
entsperrt, sein Zugang hängt nicht am Abo einer Praxis).

Was weiterläuft, ohne dass jemand hineinkommt: die **Erinnerungen an bereits
gebuchte Termine**. Eine Patientin, die einen Termin hat, soll ihn nicht
verpassen, weil die Praxis eine Rechnung nicht bezahlt hat.

**Die Rechnung selbst** liegt bei Stripe. Das Produkt zeigt Mengen und führt
ins Portal — bewusst keine eigene Rechnungsdarstellung.
