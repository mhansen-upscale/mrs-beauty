# WP-24 · Agent: Automatische Buchung

## Ziel
Der Agent führt ein Gespräch bis zum Termin — und `auto` wird freigeschaltet.

## Vorher lesen
- **`docs/fachlogik/agent.md` — Schritt 6 vollständig**, dazu Schritte 4, 5, 7
  und die Abschnitte Kennzeichnung und Not-Aus
- `docs/fachlogik/verfuegbarkeit.md` — Slots, Holds, Übersteuern
- `specs/WP-22-agent-klassifikation.md` und `specs/WP-23-agent-guardrails.md`
- `docs/entscheidungen.md` — **G1** bis **G11**, besonders **G9**
- `CLAUDE.md`, Regeln 4, 5 und 6

## Voraussetzungen
WP-10 bis WP-13, WP-21, WP-22, WP-23. Ohne die Guardrails aus WP-23 wird
dieses Paket nicht begonnen.

## Die eine Entscheidung, die alles andere trägt

**Der Dialog formuliert selbst. Das Modell ordnet nur ein.**

Die Fragen des Buchungsdialogs — „Für welche Behandlung?", „Passt Ihnen einer
dieser Termine?" — stammen aus dem Produkt, nicht aus dem Sprachmodell. Das
Modell tut in diesem Paket genau das, was es seit WP-22 tut: es erkennt
Absicht und Entitäten. Formuliert wird mit Textbausteinen, gefüllt aus
Katalog und Verfügbarkeit.

Das ist kein Sparzwang, sondern die Konsequenz aus Regel 5 und Entscheidung
G5. Ein Modell, das im Buchungsdialog frei formuliert, kann einen Termin
zusagen, den es nicht gibt, einen Preis nennen, den niemand hinterlegt hat,
oder eine Behandlung erfinden. Die Nachprüfung aus WP-23 finge das ab — aber
jede abgefangene Antwort ist ein abgebrochener Dialog. Ein Automat, der zu
zwei Dritteln eskaliert, ist keiner.

Damit gilt: **was der Agent im Buchungsdialog sagt, hat ein Mensch
geschrieben** — nur eben einmal, im Produkt, statt jedes Mal neu.

## Der Automat

```
start
 → treatment_klaeren      (falls treatment_id fehlt)
 → standort_klaeren       (nur bei mehreren Standorten)
 → behandler_klaeren      (nur wenn der Kontakt danach fragt)
 → slots_vorschlagen
 → daten_erheben          (Name, fehlender Kontaktweg)
 → einwilligung
 → bestaetigen
 → gebucht
```

Regeln aus der Spezifikation, jede einzeln geprüft:

- **Höchstens drei Klärungsversuche je Zustand.** Danach Eskalation. Ein
  Agent, der viermal dasselbe fragt, ist schlimmer als kein Agent.
- **Slot-Hold ab `slots_vorschlagen`**, TTL 15 Minuten. Läuft er ab, wird neu
  vorgeschlagen — kein Fehler, ein neuer Vorschlag.
- **Idempotenz über einen Vorgangsschlüssel je Konversation** (G9). Je
  Konversation ist genau eine Buchung in Arbeit; ein zweiter Versuch bei
  bestehendem offenen Termin führt zur **Rückfrage**, nicht zu einem zweiten
  Termin.
- **Keine Sackgasse.** Gibt es keinen passenden Slot, wird nicht abgebrochen.
- **Meinungsänderung** mitten im Ablauf setzt den Automaten zurück und gibt
  den Hold frei.

## `auto` wird freigeschaltet — und was das heißt

Ab diesem Paket kann eine Nachricht das Haus verlassen, ohne dass ein Mensch
sie gelesen hat. Alles aus WP-23 gilt dabei unverändert und **zuerst**:
harte Weiche, Konfidenz, Not-Aus, Kontingent, Nachprüfung.

Zwei Dinge werden jetzt erst wirksam, die dort schon gebaut sind:

- **Die Kennzeichnung** (G6, EU AI Act) bei der ersten automatischen Antwort
  je Konversation.
- **Der Abbruch nach fünf automatischen Antworten** in Folge (Schritt 5).

`suggest` bleibt die Vorgabe für neue Mandanten (G2).

## Schritte

1. `agent_dialogs` — ein Vorgang je Konversation, mit Zustand und Zähler.
2. `BookingState` und `Buchungsdialog` — der Automat.
3. `Dialogtexte` — die Bausteine, aus dem Produkt.
4. Anbindung an `Agentenlauf`: Buchungsabsicht führt in den Automaten.
5. Versand im Modus `auto`, mit Kennzeichnung.
6. `auto` in Controller und Oberfläche freischalten, Vorgabemodus je Mandant.

## Abnahmekriterien

Testfälle 11 bis 16 aus `docs/fachlogik/agent.md`:

11. Vollständiger Dialog bis zur Buchung, Termin liegt korrekt im Kalender.
12. Meinungsänderung mitten im Ablauf gibt den Hold frei und setzt zurück.
13. Zwei Buchungsversuche in derselben Konversation erzeugen einen Termin und
    eine Rückfrage.
14. Kein passender Slot → kein Abbruch, sondern ein Angebot.
15. Drei erfolglose Klärungsversuche → Eskalation.
16. Abgelaufener Hold während des Dialogs → neue Vorschläge, kein Fehler.

Dazu:

17. Im Modus `auto` geht die Antwort hinaus; im Modus `suggest` nicht.
18. Die erste automatische Antwort trägt die Kennzeichnung, die zweite nicht.
19. Fünf automatische Antworten in Folge führen zur Eskalation.
20. Die harte Weiche greift auch im laufenden Dialog — ein
    Komplikationssignal beendet ihn.
21. Der Termin trägt `booked_via = agent` und den Zeitpunkt der Einwilligung.
22. Ohne Einwilligung entsteht kein Termin.
23. Ein laufender Dialog überlebt einen Not-Aus nicht.

## Nicht in diesem Paket

- **Die Warteliste** (WP-25). Ohne passenden Slot bietet der Agent an, sich zu
  melden; das Wartelistenangebot kommt dort.
- **Verschieben und Absagen durch den Agenten.** `reschedule_request` und
  `cancel_request` gehen weiterhin an einen Menschen — sie betreffen einen
  Termin, der schon steht.
- **Freie Formulierung im Dialog.** Siehe oben.

## Fallstricke

- **Ein Hold ohne Freigabe blockiert einen echten Termin.** Jede Rückkehr aus
  dem Dialog gibt ihn frei — auch die über eine Eskalation.
- **Zwei Buchungen aus einer Konversation** sind der Vertrauenskiller (G9).
  Der Vorgangsschlüssel entscheidet, nicht die Reihenfolge der Nachrichten.
- **Der Dialog ist kein Grund, die Guardrails zu umgehen.** Er läuft hinter
  ihnen, nicht neben ihnen.

## Stand

Die Testfälle 11 bis 16 aus `docs/fachlogik/agent.md` laufen, dazu der
automatische Versand: `tests/Feature/Agent/BuchungsdialogTest.php` —
**11 Tests**. Gesamtstand 772.

Neu: `agent_dialogs`, das Enum `BookingState`, `App\Agent\Buchung`
(`Buchungsdialog`, `Dialogtexte`, `Antwortdeutung`, `Dialogantwort`), der
automatische Versand in `Agentenlauf`, der Vorgabemodus je Mandant — und
`auto` ist freigeschaltet.

## Was das Bauen zutage gefördert hat

**Die Kennzeichnung muss vor dem Vermerk des Laufs gebildet werden.** Sie
hängt daran, ob es schon eine automatische Antwort in dieser Konversation
gibt — wird der Lauf vorher als `answered` festgehalten, kennzeichnet sich
die erste Antwort selbst nicht mehr. Der Test prüft beide Nachrichten: die
erste trägt den Hinweis, die zweite nicht.

**Kurze Antworten deutet keine KI.** „Ja", „die zweite", „10:30" —
`Antwortdeutung` entscheidet das mit Wortlisten. Ein Aufruf dafür wäre eine
Stelle mehr, an der eine fremde Nachricht etwas auslösen könnte (Regel 5),
und im Zweifel gilt: **keine Zustimmung**. Das kostet eine Rückfrage; die
Alternative kostet einen Termin, den niemand wollte, oder eine Einwilligung,
die niemand gab.

**Jeder Ausgang gibt den Hold frei.** Übergabe, Not-Aus, Ablehnung der
Einwilligung, Meinungsänderung — jeder dieser Wege führt durch
`gibHoldFrei()`. Ein Hold, der liegenbleibt, blockiert einen echten Termin
für 15 Minuten, und niemand sieht, warum.

**Der Testfall „auto ist gesperrt" hat die Seite gewechselt.** Er stand seit
WP-22 als Sicherheitsleine da; jetzt prüft er das Gegenteil — `auto` lässt
sich einstellen, und `halbautomatisch` weiterhin nicht. Ein Merkmal
`autoGesperrt`, das immer `false` ist, ist danach Ballast und wurde entfernt.

## Nachgetragen: `suggest` heißt vorschlagen, nicht tun

Der erste Entwurf ließ den Automaten **immer** laufen und entschied erst
danach, ob der Satz hinausgeht. Damit hielt er im Vorschlagsmodus einen Slot,
vermerkte eine Einwilligung und **buchte einen Termin** — auf Sätze hin, die
niemand abgeschickt hatte. Aufgefallen ist es auf die Frage „bucht der
Assistent den Termin auch im System?", und die Antwort war: ja, leider auch
dann, wenn er nur vorschlagen sollte.

Jetzt trennt `$wirksam` Tun von Vorschlagen. Im Modus `suggest` läuft der
Dialog in einer Transaktion, die zurückgerollt wird — er rechnet aus, was er
**sagen würde**, und hinterlässt nichts. Umgesetzt als Rollback und nicht als
Abfrage an jeder Seiteneffektstelle: so entwischt auch ein Seiteneffekt
nicht, den jemand später ergänzt, ohne an diesen Modus zu denken.

Der Test dazu führt den vollständigen Dialog im Vorschlagsmodus und prüft,
dass danach **kein** Termin, **kein** Hold und **keine** Einwilligung
existiert.

## Offen

> **Nachtrag 26.09.2026.** Alle drei Punkte sind umgesetzt:
> **Warteliste** statt Übergabe, wenn nichts frei ist (G13, Testfall 14);
> **Absagen und Verschieben** unter vier Bedingungen (G12,
> `tests/Feature/Agent/TerminaenderungTest.php`); der **Behandlerwunsch** mit
> `behandler_klaeren` (`requested_practitioner_id`). Nebenbei behoben: die
> Rückfrage zu einem bestehenden Termin nannte die Zeit um die Rüstzeit
> verschoben.

**Die Warteliste** (WP-25). Ohne passenden Slot übergibt der Agent heute an
einen Menschen und sagt das auch — das Wartelistenangebot ersetzt diesen
einen Satz.

**Verschieben und Absagen.** Der Agent entwirft dazu eine Antwort, führt sie
aber nicht aus. Ein Termin, der schon steht, wird von einem Menschen
verschoben.

**Der Behandlerwunsch.** `behandler_klaeren` ist im Automaten vorgesehen und
wird übersprungen: die Einordnung kennt die Frage „geht das auch bei Frau
Dr. Sauer?" noch nicht als eigene Entität.
