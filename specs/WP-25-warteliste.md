# WP-25 · Warteliste & Lückenfüllung

## Ziel
Wird ein Termin frei, füllt sich die Lücke ohne Zutun des Teams — ohne
Rundruf, ohne Doppelvergabe, ohne den Kanal zu verbrennen.

## Vorher lesen
- **`docs/fachlogik/warteliste.md` — vollständig.** Verbindlich, nicht
  illustrativ.
- `docs/fachlogik/verfuegbarkeit.md` — Slots, Holds, Übersteuern
- `docs/datenmodell.md`, Abschnitte 0.3 und 6 — generierte Spalte statt
  partiellem Index
- `docs/entscheidungen.md` — **P5**, **B7**, **B8**, **D12**, **A10**
- `CLAUDE.md`, Regeln 3 und 4

## Voraussetzungen
WP-10 bis WP-13, WP-18 (Einwilligungen), WP-20 (Versand).

## Warum gestaffelt und nicht als Rundruf

Ein Rundruf erzeugt mehrere Zusagen für einen Slot, von denen nur eine
gewinnt. Die anderen bekommen eine Absage auf ein Angebot, das ihnen
geschickt wurde — **das ist schlechter als gar kein Angebot**. Die Staffelung
wirkt persönlich und kostet weniger: jedes Angebot außerhalb des
24-Stunden-Fensters ist ein kostenpflichtiges WhatsApp-Template.

Deshalb hängt an jedem Angebot ein **Slot-Hold**. Ohne ihn buchte während des
Angebotszeitraums jemand über die Buchungsseite denselben Slot, und die
Interessentin bekäme auf ihre Zusage eine Fehlermeldung.

## K8 ist die Bedingung, an der alles hängt

`min_notice_hours`. Manche können in zwei Stunden da sein, andere brauchen
zwei Tage. Ohne dieses Feld verschickt das System überwiegend Angebote, die
niemand annehmen kann — und jeder Fehlversuch kostet bei WhatsApp Geld.

## K9 als Datenbankregel, nicht als Prüfung im Code

„Kein zweites offenes Angebot je Eintrag" ist eine generierte Spalte mit
Unique-Index (Entscheidung A10, `docs/datenmodell.md` 0.3). Eine Prüfung im
Code bestünden zwei gleichzeitige Vergabeläufe beide.

## Schritte

1. `waitlist_entries`, `waitlist_entry_location`, `waitlist_offers`.
2. `Kandidatensuche` — K1 bis K12, in einer Abfrage, wo es geht.
3. `Vergabelauf` — die gestaffelte Runde samt Hold und Angebot.
4. `Angebotsantwort` — Annahme, Ablehnung, Ablauf, Annahme nach Ablauf.
5. Auslöser: Absage, Verschiebung, ausbleibende Reaktion, manuelle Freigabe.
6. `mrs:wartelisten-angebote` — abgelaufene Angebote aufräumen, nächste Runde.
7. Oberfläche: Einträge, Rang, Kennzahlen.

## Abnahmekriterien

Die Testfälle 1 bis 14 aus `docs/fachlogik/warteliste.md`:

1. Absage löst **genau ein** Angebot aus, keinen Rundruf.
2. Zwei Annahmen auf denselben Slot sind ausgeschlossen (parallel geprüft).
3. `min_notice_hours = 48` bekommt keinen Slot in drei Stunden.
4. Außerhalb der `time_windows`: kein Angebot.
5. Außerhalb der `weekday_mask`: kein Angebot.
6. `all_locations = 0` ohne passenden Pivot: kein Angebot.
7. Erreichte Monatsgrenze schließt aus.
8. Ohne Kanaleinwilligung: kein Angebot.
9. Abgelaufenes Angebot gibt den Slot frei, der nächste Kandidat bekommt eines.
10. Annahme nach Ablauf: verständliche Meldung, kein Fehler.
11. Nie zwei offene Angebote je Eintrag.
12. Ein abgelehnter Slot wird demselben Eintrag nie erneut angeboten.
13. Nach `max_rounds` bleibt der Slot offen, es geht nichts mehr raus.
14. `cost_micros` stammt aus der Antwort des Anbieters, nicht aus einer
    Schätzung.

Dazu:

15. Eine Verschiebung gibt den alten Slot in die Vergabe.
16. Eine ausbleibende Reaktion auf die Erinnerung bietet **parallel** an,
    ohne den bestehenden Termin anzutasten und **ohne Hold**.
17. Die Annahme eines parallelen Angebots löst den wackeligen Termin **nicht**
    automatisch auf, sondern fragt beim Team nach.
18. Die Kennzahlen stehen im Produkt.

## Nicht in diesem Paket

- **Automatisches Auflösen eines wackeligen Termins.** Ausdrücklich kein
  automatischer Vorgang (Auslöser 3).
- **Gewichtung nach Behandlungswert.** Naheliegend, gegenüber der
  Interessentin nicht vermittelbar — und deshalb nicht im Produkt.
- **Der Agent als Weg auf die Warteliste** (WP-24 lässt die Lücke; ein
  Mensch trägt heute ein).

## Fallstricke

- **MySQL verbietet `ON DELETE CASCADE` auf einer Spalte, von der eine
  STORED generierte Spalte abhängt** (`docs/datenmodell.md`, 0.3). Beim
  Angebot hängt der Wächter am Eintrag — und Kontakte müssen löschbar
  bleiben (WP-18).
- **Ohne Hold keine Vergabe.** Außer bei Auslöser 3, wo der Slot noch belegt
  ist — dort ist das Fehlen des Holds die Aussage.
- **Jeder Fehlversuch kostet Geld.** K8, K10 und K12 sind keine Feinheiten.

## Stand

Die Testfälle 1 bis 14 aus `docs/fachlogik/warteliste.md` laufen, dazu die
Auslöser und die Oberfläche: `tests/Feature/Warteliste/WartelisteTest.php` —
**25 Tests**. Gesamtstand 798.

Neu: `waitlist_entries`, `waitlist_entry_location`, `waitlist_offers`, die
Enums `WaitlistStatus`, `WaitlistOfferStatus`, `WaitlistTrigger`,
`App\Warteliste` (`Kandidatensuche`, `Vergabelauf`, `Angebotsantwort`,
`Angebotstexte`, `Lueckenmelder`, `Wartelistenkennzahlen`), der Auftrag
`LueckeFuellen`, der Lauf `mrs:warteliste-aufraeumen` (alle fünf Minuten) und
die Seite **Warteliste** samt Kennzahlen.

## Was das Bauen zutage gefördert hat

**Der Wächter für K9 durfte nicht am Fremdschlüssel hängen.** MySQL verbietet
`ON DELETE CASCADE` auf einer Spalte, von der eine STORED generierte Spalte
abhängt — und der Fremdschlüssel muss kaskadieren, damit ein gelöschter
Kontakt (WP-18) seine Einträge und deren Angebote mitnimmt. Der Wächter hängt
deshalb an einer Kopie des Schlüssels (`entry_key`), die die Anwendung setzt.
Ohne diesen Umweg hätte die Löschanfrage einer Patientin an einem
Wartelistenangebot scheitern können.

**Die Angebotstexte sind Templates, und Templates sind Kostenstellen.** Ein
Angebot außerhalb des 24-Stunden-Fensters wird von Meta als `utility` oder
`marketing` eingeordnet — je nach Formulierung, mit deutlichem Preisunterschied.
`Angebotstexte` ist deshalb sachlich und terminbezogen: kein „Sichern Sie
sich", kein Ausrufezeichen, kein Rabatt.

**Ein offenes Angebot geht der Einordnung vor.** Wer auf „Es ist ein Termin
frei geworden" mit „Ja" antwortet, meint dieses Angebot — nicht eine neue
Terminanfrage, die der Agent erst klassifizieren müsste. Die Prüfung sitzt
deshalb **vor** dem Agentenauftrag in der Eingangsverarbeitung. Gedeutet wird
mit derselben Wortliste wie im Buchungsdialog, ohne Modell.

**Eine Nachricht, die weder ja noch nein sagt, geht ihren gewöhnlichen Weg.**
Sonst verschluckt die Warteliste die Rückfrage „wie lange dauert das denn?"
und niemand antwortet darauf.

## Offen

> **Nachtrag 26.09.2026.** `cost_micros` wird gefüllt, aus dem Preis der
> Angebotsnachricht (B14). **Der wackelige Termin** hat seine Aufgabe im
> Produkt — auf der Warteliste und im Dashboard, mit „an sie geben" oder
> „bisherigen behalten" (`App\Warteliste\Klaerung`, `KlaerungTest.php`).
> **Der Agent trägt ein**, wenn nichts frei ist (G13).

**`cost_micros` bleibt leer.** Die Spalte steht, die Quelle fehlt: WhatsApp
meldet in der Statusrückmeldung die **Kategorie**, nicht den Betrag. Für Euro
und Cent braucht es die Preisliste je Land und Kategorie — das gehört zu
WP-06 und wird dort aus derselben Kategorie gerechnet, die WP-20a schon
festhält.

**Der wackelige Termin wird nicht aufgelöst.** Nimmt jemand ein paralleles
Angebot an, sagt das Produkt „wir klären das" — und ein Mensch entscheidet.
Was fehlt, ist die Aufgabe im Produkt, die ihn daran erinnert.

**Der Agent trägt niemanden ein.** Findet er keinen Slot, übergibt er an
einen Menschen (WP-24). Aus dem Dialog heraus auf die Warteliste zu kommen,
wäre der nächste Schritt — und ein kleiner, denn beide Teile stehen.
