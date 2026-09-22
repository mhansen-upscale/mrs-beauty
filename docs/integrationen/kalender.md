# Integration: Kalendersync

Gilt für WP-14 und WP-15.

## Grundregeln

**R1 — Eigenmarkierung.** Jedes ausgehende Event trägt eine Markierung mit `organization_id` und `appointment_id`. Beim Rücksync werden markierte Events ignoriert. Ohne diese Regel schreibt das System seinen eigenen Termin als externen Blocker zurück, blockiert damit den eigenen Slot und erzeugt eine Endlosschleife. Das ist der häufigste Fehler bei Kalenderintegrationen.

- Google: `extendedProperties.private`
- Microsoft: Open Extension

**R2 — Datensparsamkeit in beide Richtungen.** Ausgehend trägt ein Event einen neutralen Titel ("Beratung"), keinen Kontaktnamen, keine Behandlung. Eingehend wird ausschließlich der Zeitraum übernommen, der Originaltitel landet nirgends in der Datenbank. Der Kalender eines Arztes liegt oft auf dem Privathandy und ist manchmal mit dem Team geteilt.

**R3 — Konflikte.** Der externe Kalender gewinnt bei Blockern, das System gewinnt bei Terminen. Ein extern gelöschter Termin wird wieder angelegt, nicht im System gelöscht.

**R4 — Stille Ausfälle sind der Normalfall.** Abonnements laufen ab, Token werden entzogen, Sync-Token verfallen. Jede Verbindung wird überwacht, ein Ausfall erzeugt einen Hinweis im Produkt, nicht nur einen Log-Eintrag.

## Unterschiede

| | Google Calendar | Microsoft Graph |
|---|---|---|
| Benachrichtigung | Watch-Channel | Subscription |
| Höchstlaufzeit | 30 Tage | deutlich kürzer, in der Dokumentation prüfen |
| Deltas | Sync-Token | Delta-Token |
| Abgelaufener Token | `410 Gone` → Vollabgleich | eigener Fehlercode → Vollabgleich |
| Eigenmarkierung | `extendedProperties.private` | Open Extension |
| Zeitzone | im Event enthalten | im Event enthalten, andere Darstellung |
| Ganztägig | `date` statt `dateTime` | eigenes Kennzeichen, andere Endzeitsemantik |

**Erst beide umsetzen, dann abstrahieren.** Ein gemeinsames Interface vor der zweiten Umsetzung zu bauen führt zu einer Abstraktion, die auf keinen von beiden richtig passt.

## Erneuerung

Ein wiederkehrender Job erneuert Abonnements **deutlich** vor Ablauf, nicht kurz davor. Fällt der Job einmal aus, ist sonst der Sync tot.

Läuft ein Refresh-Token ab oder wird entzogen, wechselt die Verbindung auf `expired` und im Produkt erscheint eine Aufforderung zur Neuverbindung. Ein stiller Ausfall bedeutet, dass Termine über belegten Zeiten gebucht werden.

## Fallstricke

- **Vollabgleich nach verfallenem Sync-Token** muss vorgesehen sein, nicht als Fehler behandelt werden.
- **Wiederkehrende Termine** mit abweichenden Einzelinstanzen sind eine eigene Testklasse.
- **Ganztägige Events** werden von beiden Anbietern unterschiedlich dargestellt, insbesondere bei der Endzeit.
- **Zeitzonen niemals annehmen.** Für jedes eingehende Event die mitgelieferte Zeitzone auswerten.
- **Ein Behandler mit beiden Anbietern gleichzeitig** darf keine doppelten Blocker erzeugen.
