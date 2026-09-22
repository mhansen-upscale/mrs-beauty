# Fachlogik: Attribution und ROI

Gehört zu WP-32. Verbindlich.

## Aufgabe

Die Kette von der Werbeanzeige bis zum Umsatz lückenlos abbilden:

```
Anzeige → Klick → Besucher → Lead → Termin → erschienen → Behandlung → Umsatz
```

Das ist die Zahl, die das Abo rechtfertigt. Kein bestehender Anbieter schließt diesen Kreis, weil Werbung, Kommunikation und Termine überall in getrennten Systemen liegen.

## Erfassung

**Besucher-ID.** Ein First-Party-Cookie, gesetzt von der Buchungsseite und vom Website-Snippet des Kunden. Laufzeit 180 Tage. Zufällige ID, kein Personenbezug.

Bei jedem Seitenaufruf wird ein `attribution_touch` geschrieben:

| Feld | Quelle |
|---|---|
| `visitor_id` | Cookie |
| `click_id` | `fbclid` aus der URL |
| `utm_source/medium/campaign/content/term` | URL |
| `campaign_external_id`, `adset_external_id`, `ad_external_id` | aufgelöst über `fbclid` beim nächsten Insights-Sync, sonst aus UTM |
| `landing_url`, `referrer` | Browser |
| `occurred_at` | Serverzeit |

**Verknüpfung.** Entsteht aus dem Besucher ein Lead (Buchung oder Chatanfrage über einen Link mit Besucher-ID), werden alle Touches dieser `visitor_id` rückwirkend mit `contact_id` und `lead_id` verknüpft.

**Einfrieren.** Beim Anlegen eines Termins wird `attribution_snapshot` als JSON gesetzt: Kampagnenname, Anzeigengruppe, Anzeige, UTM, Quelle, Zeitpunkt. Kampagnen werden umbenannt, pausiert und gelöscht. Ohne Snapshot ist die Auswertung nach einem halben Jahr wertlos.

**Manuelle Quelle.** Beim Anlegen eines Termins durch das Team ist die Quelle Pflichtfeld. Ein Teil der Anzeigen-Leads ruft an oder kommt vorbei. Ohne dieses Feld fehlen diese Buchungen und der ROAS sieht schlechter aus, als er ist.

## Modelle

**Nicht im Schema festschreiben.** Alle Touches werden gespeichert, das Modell wird zur Abfragezeit berechnet.

| Modell | Regel |
|---|---|
| First Touch | erster Touch der Kette |
| Last Touch | letzter Touch vor der Lead-Entstehung |
| Last Non-Direct | letzter Touch mit erkennbarer Quelle, Direktaufrufe übersprungen |
| Linear | gleichmäßige Verteilung über alle Touches |

**Standardanzeige: Last Non-Direct.** Umschaltbar im Dashboard.

**Rückblickfenster:** 28 Tage nach Klick, konfigurierbar. Der Entscheidungsweg bei ästhetischen Eingriffen ist lang. Mit einem kürzeren Fenster wird systematisch zu wenig zugeordnet, und der Kunde hält seine Werbung für schlechter als sie ist. Das gehört als Hinweis ins Dashboard.

## Kennzahlen

Einheitliche Definitionen. Abweichende Auslegung in Berichten ist der schnellste Weg, Vertrauen in die Zahlen zu verlieren.

| Kennzahl | Definition |
|---|---|
| Leads | Anzahl `leads` mit zugeordneter Kampagne im Zeitraum |
| Beratungen gebucht | Termine mit Status `pending`, `confirmed`, `attended` |
| Erschienen | Termine mit Status `attended` |
| Show-Rate | Erschienen ÷ gebucht |
| No-Show-Quote | `no_show` ÷ (erschienen + `no_show`) |
| Abschlüsse | Leads mit Status `won` |
| Zugeordneter Umsatz | Summe `treatments.avg_revenue_cents` der gewonnenen Leads |
| Cost per Lead | Ausgaben ÷ Leads |
| Cost per Consult | Ausgaben ÷ gebuchte Beratungen |
| CAC | Ausgaben ÷ Abschlüsse |
| ROAS | Zugeordneter Umsatz ÷ Ausgaben |
| Speed-to-Lead | Median `leads.first_response_seconds` |

Aufschlüsselbar nach Kampagne, Behandlung, Behandler, Standort und Zeitraum.

**No-Show-Quote je Kampagne** ist eine eigene Ansicht wert. Manche Anzeigenformate ziehen systematisch unernste Anfragen. Diese Erkenntnis bekommt eine Praxis nirgends sonst.

## Conversions API

Server-seitige Ereignisse an Meta, dedupliziert gegen den Pixel über `event_id`.

**Erlaubt:**

| Feld | Wert |
|---|---|
| `event_name` | ausschließlich `Lead`, `Schedule`, `Contact` |
| `event_id` | eigene ID, identisch mit dem Pixel-Ereignis |
| `user_data` | gehashte E-Mail, gehashte Telefonnummer, `fbc`, `fbp`, IP, User-Agent |
| `event_time` | Zeitpunkt |
| `action_source` | `website` bzw. `business_messaging` |

**Verboten, ausnahmslos:**

- `treatment_id`, Behandlungsname, Kategorie oder Katalogbezeichnung in **irgendeinem** Feld
- `custom_data.content_name`, `content_category`, `content_ids` mit Bezug zur Behandlung
- Wertübermittlung, die auf eine bestimmte Behandlung schließen lässt
- Custom Audiences aus Kontaktlisten (Entscheidung C8): Die Zugehörigkeit zu einer ästhetischen Praxis ist selbst ein Gesundheitsdatum

**Absicherung im Code.** Ein Test in `tests/Feature/Meta` lädt alle aktiven Katalognamen und prüft jeden ausgehenden Payload gegen diese Liste. Ein Treffer lässt den Test fehlschlagen. Das ist keine Empfehlung, sondern Regel 2 aus `CLAUDE.md` in ausführbarer Form.

## Bekannte Grenzen

Das gehört ins Dashboard, nicht nur in die Dokumentation. Ein Kunde, der eine Lücke selbst entdeckt, verliert das Vertrauen in alle Zahlen.

- **Anrufer und Laufkundschaft** werden nur erfasst, wenn das Team die Quelle einträgt.
- **Cookie-Ablehnung** verhindert die Besucherzuordnung. Der Lead entsteht trotzdem, nur ohne Kampagnenbezug.
- **Geräteübergreifende Wege** (Anzeige auf dem Handy gesehen, am Laptop gebucht) brechen die Kette.
- **`avg_revenue_cents` ist eine Schätzung**, kein abgerechneter Umsatz. Der Umsatz wird im Produkt ausdrücklich als "zugeordneter Schätzwert" bezeichnet.
- **Nach zwölf Monaten** gibt es keine Aufschlüsselung mehr nach Anzeigengruppe und Einzelanzeige (Entscheidung P9). Kampagnenebene bleibt.

## Testfälle

1. Klick mit `fbclid` → Buchung → Termin trägt die Kampagne im Snapshot.
2. Umbenennung der Kampagne bei Meta verändert den Snapshot eines bestehenden Termins nicht.
3. Mehrere Touches desselben Besuchers erzeugen bei First Touch und Last Touch unterschiedliche Zuordnungen.
4. Ein Direktaufruf als letzter Touch wird bei Last Non-Direct übersprungen.
5. Touch außerhalb des Rückblickfensters wird nicht zugeordnet.
6. Manuell angelegter Termin ohne Quelle ist nicht speicherbar.
7. **Kein ausgehender Meta-Payload enthält einen Katalognamen** — Test gegen alle aktiven Behandlungen. Geprüft werden Ereignisse, Pixel-Parameter, Zielgruppen sowie Kampagnen-, Anzeigengruppen- und Anzeigennamen; ausgenommen ist allein der Anzeigeninhalt selbst (Entscheidung C9).
8. `event_id` ist zwischen Pixel und Conversions API identisch.
9. Lead ohne Besucherzuordnung erscheint in der Auswertung als "Quelle unbekannt", nicht als Direktzugriff.
10. ROAS-Berechnung stimmt mit einer von Hand nachgerechneten Testkampagne überein.
