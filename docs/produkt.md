# Produkt

> **Rekonstruktion, teilweise gefüllt.** Dieses Dokument fehlte im
> Repository. „Das Differenzierungsmerkmal" ist aus den Spezifikationen
> rekonstruiert, **Preismodell** und **Onboarding** sind seit dem 26.09.2026
> festgelegt (Entscheidungen B13 bis B15). Zielkunde und Abgrenzung sind
> weiterhin **vom Produktverantwortlichen zu füllen**.

## Wofür das Produkt da ist

Eine Praxis für ästhetische Behandlungen führt Werbung, Kommunikation und
Termine heute in getrennten Systemen. Das Produkt führt sie zusammen und
schließt damit eine Kette, die sonst niemand schließt:

```
Anzeige → Klick → Besucher → Lead → Termin → erschienen → Behandlung → Umsatz
```

**Das ist die Zahl, die das Abo rechtfertigt** (`docs/fachlogik/attribution.md`).

## Das Differenzierungsmerkmal

Werbung für ästhetische Eingriffe ist in Deutschland enger reguliert als fast
jede andere Werbung. Zwei Ebenen greifen gleichzeitig:

**Meta** behandelt kosmetische Verfahren als eingeschränkte Kategorie. Zu
erwarten sind Auflagen bei Alters-Targeting und Standort sowie Ablehnungen bei
Vorher-Nachher-Darstellungen.

**Deutsches Recht ist strenger.** Der BGH hat mit Urteil vom 31.07.2025
(I ZR 170/24) Vorher-Nachher-Bilder auch für minimalinvasive Eingriffe
verboten — Botox und Hyaluron eingeschlossen, nicht nur klassische
Operationen. Verstöße können mit bis zu 50.000 Euro geahndet werden.

Daraus folgen drei Dinge, die das Produkt von jedem Mitbewerber trennen:

1. **Die Oberfläche erzwingt die Anforderungen, statt die API-Ablehnung
   anzuzeigen.** Ein Kunde, der eine Kampagne baut und beim Speichern eine
   Meta-Fehlermeldung bekommt, hält das Produkt für kaputt.
2. **Die HWG-Prüfung läuft vor jeder Übermittlung an Meta**, nicht danach
   (WP-30).
3. **Eine Bibliothek rechtssicherer Alternativformate** gehört dazu: die
   Prüfung sagt nicht nur, was nicht geht, sondern was stattdessen geht —
   Arzt-Vorstellung, Ablauf-Erklärung, Räumlichkeiten, Preis- und
   Risikotransparenz.

**Das Produkt ist eine Prüfhilfe, keine Rechtsberatung.** Formulierung und
Haltung müssen das durchgängig widerspiegeln, sonst entsteht eine Haftung,
die niemand tragen will.

## Was der Agent ist und was nicht

Eine Empfangskraft, keine medizinische Fachkraft. Er nimmt Anfragen entgegen,
beantwortet organisatorische Fragen, bucht Termine, bietet die Warteliste an,
wenn nichts frei ist, und sagt einen Termin ab oder verschiebt ihn — Letzteres
nur für eine bekannte Person mit genau einem anstehenden Termin und nach
ausdrücklicher Bestätigung (G12). Alles darüber hinaus geht an Menschen. Siehe
`docs/fachlogik/agent.md`.

## Preismodell

*Festgelegt am 26.09.2026 (B15), auf Wunsch des Betreibers: „hochpreisig
reingehen". Netto, zuzüglich Umsatzsteuer, die Stripe Tax rechnet.*

**Eine Stufe je Praxis** (B10). Wer bucht, bekommt alles — Werbung, Posteingang,
Assistent, Buchungsseite, Warteliste, Auswertung. Stufen, die Funktionen
sperren, machen aus jedem Verkaufsgespräch eine Funktionsdiskussion.

| | Preis | Stripe | Konfiguration |
|---|---|---|---|
| **Abo** | **790 € im Monat**, monatlich kündbar | `STRIPE_PRICE_ID` | `mrs.billing.prices.base_cents` |
| **Einrichtung** | **1.490 € einmalig**, mit der ersten Rechnung | `STRIPE_SETUP_PRICE_ID` (leer = keine) | `mrs.billing.prices.setup_cents` |
| **Aufstockung** | **59 € je Block** — 250 Templates *oder* 600 Assistenzläufe | `STRIPE_TOPUP_PRICE_ID` | `mrs.billing.prices.topup_cents` |
| **Anzeigenbild** | **2 € je Bild**, einzeln (B13) | `STRIPE_IMAGE_PRICE_ID` | `mrs.billing.image_price_cents` |
| **Antwort im Service-Fenster** | **vorerst 0 €** (B14) | Sammelposten am Monatsersten | `WHATSAPP_SERVICEFENSTER_CENT` |

**Enthalten je Monat:** 250 WhatsApp-Templates außerhalb des Service-Fensters,
600 Assistenzläufe, 30 Anzeigenbilder, beliebig viele Antworten im offenen
Fenster, beliebig viele Zugänge und Standorte. 30 Tage Testphase ohne Abo.

**Die Zahlen im Code nennen den Preis nur.** Abgerechnet wird bei Stripe unter
den Preis-IDs; wer einen Preis ändert, ändert ihn an beiden Stellen.

### Warum so

- **Ein zusätzlicher Termin im Monat trägt das Abo.** Eine Behandlung mit
  Botulinum oder Hyaluron liegt in der Zielgruppe typischerweise im
  mittleren dreistelligen Bereich; das ROI-Dashboard (WP-32b) zeigt der Praxis
  genau diese Rechnung. *Annahme, mit echten Kundendaten zu prüfen.*
- **Der Vergleich ist nicht ein Buchungssystem, sondern die Summe:** Agentur
  für Anzeigen, Buchungssystem, Empfangszeit für WhatsApp — und das Risiko einer
  HWG-Abmahnung, gegen das die Prüfung antritt.
- **Die Einrichtung ist echte Arbeit** und wird deshalb bezahlt: Meta-
  Verifizierung begleiten, WhatsApp-Rufnummer anbinden, Katalog mit
  Umsatzschätzungen (D14), Brand Guide, Kalender. Eine Praxis, die dafür zahlt,
  nutzt das Produkt auch.
- **Aufstockung mit Marge, nicht zum Selbstkostenpreis.** Ein Marketing-
  Template nach Deutschland kostet bei Meta über 0,12 USD
  (`integrationen/meta.md`), ein Assistenzlauf rund 1,2 US-Cent
  (`config/mrs.php`, `agent.monthly_budget_tenth_cents`). 250 Templates liegen
  damit bei unter 30 €, 600 Läufe bei unter 10 € — der Block zu 59 € deckt auch
  die teurere Kategorie.

### Was offen bleibt

- Ein **Jahrespreis** (etwa zwei Monate geschenkt) ist bei Stripe als zweiter
  Preis schnell angelegt, im Produkt aber noch nicht wählbar.
- Ob Meta ab dem 01.10.2026 Antworten im Service-Fenster berechnet, zeigt die
  erste Rechnung. Dann wird `WHATSAPP_SERVICEFENSTER_CENT` gesetzt — der Preis
  gilt ab dann, nicht rückwirkend (B14).

## Onboarding einer neuen Praxis

In dieser Reihenfolge, weil jeder Schritt auf dem vorigen aufsetzt. Das
Produkt führt beim ersten Anmelden mit einer kurzen Einführung durch das Menü.

1. **Zugang** — Einladung durch uns oder die Inhaberin (WP-04). Rollen:
   Inhaberin, Verwaltung, Empfang, Behandlerin, Marketing.
2. **Praxis** — Standorte mit Zeitzone und Anschrift, Behandlerinnen mit
   Arbeitszeiten (WP-08). Ohne Arbeitszeiten gibt es keine Slots.
3. **Katalog** — Behandlungen mit **Umsatzschätzung** (D14, sonst kein ROAS)
   und Terminarten mit Dauer und Rüstzeiten (WP-09). Beschreibung und Preis
   erscheinen auf der Buchungsseite erst nach der HWG-Prüfung (C11).
4. **Erscheinungsbild** — Logo, Markenfarbe, Impressum und Datenschutz (WP-07).
   Ohne die beiden Rechtslinks ist die Buchungsseite nicht vollständig.
5. **Kalender** — Google oder Microsoft je Behandlerin (WP-14, WP-15).
6. **Posteingang** — E-Mail-Weiterleitung auf die Eingangsadresse, eigenes
   Postfach für den Versand (WP-20b); **WhatsApp** mit WABA-ID, Rufnummern-ID
   und Systembenutzer-Token unter *Einstellungen → WhatsApp*.
7. **Assistent** — beginnt im Modus `suggest` (G2). `auto` erst, wenn die
   Praxis die Vorschläge eine Weile gelesen hat.
8. **Werbekonto** — Meta-Werbekonto verbinden (WP-26), Brand Guide ausfüllen
   (WP-29), erste Anzeigenvorschläge am folgenden Montag (WP-31).
9. **Abo** — vor Ende der Testphase unter *Einstellungen → Abo*.

## Zu füllen

- **Zielkunde und Marktabgrenzung.** Gesetzt ist bisher nur: Praxen für
  ästhetische Behandlungen in Deutschland, hochpreisig positioniert (B15).
  Offen: Größe (Einzelpraxis bis Kette), Arztpraxis oder Institut, Region.
- **Abgrenzung gegenüber bestehenden Praxisverwaltungssystemen.** Das Produkt
  führt keine Patientenakte, keine Abrechnung nach GOÄ und keine Dokumentation
  von Behandlungen — es endet am Termin. Wie es neben einem PVS steht (Export,
  Schnittstelle, doppelte Terminführung), ist nicht entschieden.
