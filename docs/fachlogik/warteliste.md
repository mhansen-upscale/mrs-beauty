# Fachlogik: Warteliste und Lückenfüllung

Gehört zu WP-25. Verbindlich.

## Aufgabe

Wird ein Termin frei, soll die Lücke ohne Zutun des Praxisteams wieder gefüllt werden. Ohne Rundruf, ohne Doppelvergabe, ohne den Kanal mit Angeboten zu verbrennen.

## Auslöser

Ein Vergabelauf startet bei:

1. **Absage** eines Termins (durch Kontakt oder Praxis)
2. **Verschiebung** eines Termins, der alte Slot wird frei
3. **Keine Reaktion** auf die Terminerinnerung (`reminder_response = no_response`) — der Slot gilt als wackelig und wird **parallel** angeboten, ohne den bestehenden Termin anzutasten
4. **Manuelle Freigabe** durch das Team

Bei Auslöser 3 wird kein Slot-Hold gesetzt, weil der Slot noch belegt ist. Nimmt jemand an, wird der wackelige Termin erst nach Rückfrage beim Team aufgelöst. Das ist ausdrücklich kein automatischer Vorgang.

## Auswahl der Kandidaten

Ein Wartelisteneintrag kommt in Frage, wenn **alle** Bedingungen gelten:

| # | Bedingung |
|---|---|
| K1 | `status = 'active'` und `expires_at` in der Zukunft |
| K2 | `appointment_type_id` stimmt exakt überein |
| K3 | `all_locations = 1` **oder** der Standort des Slots steht in `waitlist_entry_location` |
| K4 | `practitioner_id` ist `NULL` **oder** stimmt mit dem Behandler des Slots überein |
| K5 | Das lokale Datum des Slots liegt in `[earliest_date, latest_date]` |
| K6 | Das Bit des lokalen Wochentags ist in `weekday_mask` gesetzt |
| K7 | Die lokale Startzeit fällt in eines der `time_windows` |
| K8 | `slot_start − now >= min_notice_hours` |
| K9 | Für diesen Eintrag ist **kein** anderes Angebot offen (`status = 'pending'`) |
| K10 | Die Anzahl der Angebote an diesen Kontakt im laufenden Kalendermonat liegt unter `organizations.settings.waitlist_max_offers_per_contact_per_month` |
| K11 | Es liegt eine gültige Einwilligung für den bevorzugten Kanal vor |
| K12 | Diesem Eintrag wurde dieser Slot noch nie angeboten |

**K8 ist die Bedingung, an der die Warteliste steht und fällt.** Manche Interessentinnen können in zwei Stunden da sein, andere brauchen zwei Tage Vorlauf. Ohne dieses Feld verschickt das System überwiegend Angebote, die niemand annehmen kann, und jeder Fehlversuch kostet bei WhatsApp echtes Geld.

**K9 verhindert**, dass jemand gleichzeitig zwei Slots angeboten bekommt und beide annimmt.

## Rangfolge

1. `priority` absteigend (manuell erhöhbar durch das Team)
2. `created_at` aufsteigend, wer länger wartet, kommt zuerst
3. `id` aufsteigend als Gleichstandsregel

Deterministisch. Keine Zufallsauswahl, keine Gewichtung nach Behandlungswert. Letzteres wäre naheliegend, ist aber gegenüber der Interessentin nicht vermittelbar und gehört nicht in ein Produkt, das Praxen im Außenverhältnis vertreten.

## Gestaffelte Vergabe

Nicht alle gleichzeitig anschreiben. Der Ablauf je Runde:

```
runde = 1
solange runde <= max_runden (Standard 5):
    kandidat = nächster nach Rangfolge, der K1..K12 erfüllt
    wenn kein kandidat:  abbrechen, Slot bleibt offen
    wenn slot_start − now < kandidat.min_notice_hours:  abbrechen

    slot_hold anlegen (TTL = angebots_ttl, Standard 30 Minuten)
        → belegt appointment_slots-Zeilen, blockiert gegen jede parallele Buchung
    waitlist_offer anlegen (status = pending, expires_at = now + TTL)
    Nachricht über bevorzugten Kanal senden, cost_micros erfassen
    offers_sent_count erhöhen, last_offered_at setzen
    Eintrag auf status = 'offered' setzen

    warten auf Antwort bis expires_at

    bei Annahme:
        Hold in Termin umwandeln (dieselben Slot-Zeilen, slot_hold_id → appointment_id)
        Eintrag auf status = 'booked'
        alle anderen offenen Angebote für diesen Slot auf 'superseded'
        fertig

    bei Ablehnung oder Ablauf:
        Hold samt Slot-Zeilen freigeben
        Angebot auf 'declined' bzw. 'expired'
        Eintrag zurück auf status = 'active'
        runde erhöhen
```

**Warum gestaffelt statt Rundruf:** Ein Rundruf erzeugt mehrere Zusagen für einen Slot, von denen nur eine gewinnt. Die anderen bekommen eine Absage auf ein Angebot, das ihnen geschickt wurde. Das ist schlechter als gar kein Angebot. Die Staffelung wirkt gegenüber der Interessentin persönlich und kostet weniger.

**Warum der Slot-Hold nötig ist:** Ohne ihn könnte während des Angebotszeitraums jemand über die Buchungsseite denselben Slot buchen. Die Interessentin nimmt an und bekommt eine Fehlermeldung.

## Grenzfälle

- **Annahme nach Ablauf:** Klare Meldung, dass der Termin inzwischen vergeben ist, plus Angebot, auf der Warteliste zu bleiben. Der Eintrag bleibt aktiv.
- **Zwei Slots gleichzeitig frei, selber Spitzenkandidat:** K9 lässt nur ein Angebot zu. Der zweite Slot geht an den nächsten in der Rangfolge.
- **Slot wird während eines laufenden Angebots anderweitig gebucht:** Kann nicht passieren, der Hold blockiert. Falls doch (manuelle Buchung durch das Team mit Übersteuerung), wird das Angebot auf `superseded` gesetzt und die Interessentin informiert.
- **Kontakt wird während eines offenen Angebots gelöscht:** Angebot auf `expired`, Hold freigeben.
- **Eintrag läuft während eines offenen Angebots ab:** Angebot bleibt gültig bis `expires_at`, der Eintrag wird erst danach auf `expired` gesetzt.

## Kosten

Jedes Angebot außerhalb des 24-Stunden-Service-Fensters ist ein kostenpflichtiges WhatsApp-Template. `cost_micros` wird je Angebot erfasst, Quelle ist die API-Antwort, keine Schätzung.

Formuliere das Template so, dass es von Meta als `utility` genehmigt wird, nicht als `marketing`. Utility ist günstiger. Ob die Einordnung gelingt, entscheidet Meta anhand der Formulierung, das ist ein Versuch-und-Irrtum-Vorgang bei der Template-Einreichung.

## Kennzahlen

Diese Auswertung ist das Verkaufsargument im Demo-Termin und gehört ins Produkt:

- verschickte Angebote
- Annahmequote
- gefüllte Lücken pro Monat
- Kosten der Angebote
- resultierender Wert (`treatments.avg_revenue_cents` der gefüllten Termine)
- durchschnittliche Zeit von der Absage bis zur Neubelegung

## Testfälle

1. Absage löst genau ein Angebot aus, keinen Rundruf.
2. Zwei Annahmen auf denselben Slot sind ausgeschlossen (paralleler Test).
3. Kandidat mit `min_notice_hours = 48` erhält kein Angebot für einen Slot in drei Stunden.
4. Kandidat außerhalb seines `time_windows` erhält kein Angebot.
5. Kandidat außerhalb seiner `weekday_mask` erhält kein Angebot.
6. `all_locations = 0` ohne passenden Pivot-Eintrag erhält kein Angebot.
7. Erreichte Monatsgrenze schließt den Kandidaten aus.
8. Kandidat ohne Kanaleinwilligung erhält kein Angebot.
9. Abgelaufenes Angebot gibt den Slot frei und der nächste Kandidat erhält eines.
10. Annahme nach Ablauf erzeugt eine verständliche Meldung, keinen Fehler.
11. Derselbe Kandidat erhält nie zwei offene Angebote gleichzeitig.
12. Ein Slot, der schon einmal angeboten und abgelehnt wurde, wird demselben Eintrag nicht erneut angeboten.
13. Nach `max_runden` ohne Annahme bleibt der Slot offen und es gehen keine weiteren Angebote raus.
14. `cost_micros` wird aus der API-Antwort übernommen, nicht geschätzt.
