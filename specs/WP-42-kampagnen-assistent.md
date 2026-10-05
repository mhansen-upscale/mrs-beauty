# WP-42 · Kampagnen-Assistent

> Im Video heißt es, die Werbung sei „noch zu technisch" und „vielleicht
> überfordernd"; die Praxis soll sich nicht fühlen, als säße sie im
> Ads-Manager. Heute führt der Weg zur laufenden Anzeige über zwei
> Menüpunkte und elf Schritte. Unterwegs fallen Begriffe wie Anzeigengruppe,
> Seiten-ID und `PAUSED`, und die Reihenfolge ist Kampagne, dann Anzeige,
> dann zurück zur Kampagne. Gewünscht ist: „Was suchst du?", ein paar Fragen,
> „klick, klick, klick", fertig.

## Ziel
Ein Assistent unter *Werbung*, der in fünf Schritten aus „Ich will mehr
Patientinnen an Standort X" eine laufende Kampagne mit Anzeige macht. Die
Praxis sieht dabei kein Meta-Fachwort.

## Vorher lesen
- `docs/feedback/2026-10-05-feedbackschleife-1.md`: Aussagen 20 und 21, Beobachtung B5
- `specs/WP-41-kampagnenstrecke-reparieren.md`; es muss stehen
- `specs/WP-27-kampagnenverwaltung.md`, `WP-27b-anzeigen-schalten.md`, `WP-31-anzeigenvorschlaege.md`, `WP-31b-anzeigenformate.md`
- CLAUDE.md, **Regeln 2 und 4**; Entscheidungen **C3, C9, C13, B2, B13**
- `resources/js/pages/buchung/Index.vue:82-109, 410-480`: das nächstgelegene Muster für Schritte

## Voraussetzungen
WP-40 (Wortliste) und WP-41 (die Strecke trägt).

## Die Linie, an der alles hängt

**Erst die Frage, dann die Technik.** Der Einstieg fragt, was die Praxis
will, nicht welches Kampagnenziel. In diesem Paket gibt es einen Pfad:
**„Neue Patientinnen"**. Der zweite, **„Neue Mitarbeitende"**, steht schon als
Kachel da und wird mit WP-49 freigeschaltet (P16); bis dahin ist er sichtbar,
aber deaktiviert, mit dem Satz „Kommt bald", nicht ausgeblendet.

**Alles, was sich ableiten lässt, wird nicht gefragt.**

| Heute gefragt | Im Assistenten |
|---|---|
| Ziel | entfällt: „Neue Patientinnen" heißt „Besuche auf der Buchungsseite" (WP-41) |
| Name Kampagne, Name Anzeigengruppe | entfällt: erzeugt (`Kampagnenname`), mit Merkmal; eigener Name nur unter „Anpassen" |
| Tagesbudget | Monatsbetrag; Tagesbudget = Monat / 30, aufgerundet auf volle Euro, mindestens `mrs.ads.min_daily_budget` |
| Beginn, Ende | „ab heute, 4 Wochen" vorgewählt; Alternativen 2 Wochen, 8 Wochen, ohne Ende |
| Standort | entfällt bei einem Standort |
| Umkreis, Alter, Geschlecht | 20 km, 18–65, alle; unter „Anpassen" eingeklappt |
| Kampagne zuerst, dann Anzeige | eine Strecke; Kampagne, Gruppe und Anzeige entstehen zusammen |

**Einrichtung ist kein Schritt.** Werbekonto, Facebook-Seite und eine Stadt
am Standort sind Voraussetzungen, keine Fragen. Fehlt eines, zeigt der
Assistent vor Schritt 1, was fehlt, mit Knopf zur Stelle. „Seiten-ID"
verschwindet als Freitext: Die Seite wird beim Verbinden des Werbekontos aus
den Seiten gewählt, die die Login-Konfiguration freigibt. Das Lesen läuft im
Auftrag und wird zwischengespeichert, nicht im Request (Regel 4, B2). Erst
wenn das nicht geht, bleibt die Ziffer als Rückfall.

## Die fünf Schritte

1. **Was möchten Sie erreichen?** Zwei Kacheln: „Neue Patientinnen" und „Neue Mitarbeitende" (deaktiviert bis WP-49).
2. **Was bewerben Sie?**
   - Wahl aus den freigegebenen Wochenvorschlägen (WP-31), oder „Eigenen Text schreiben"
   - optional eine Behandlung, die **nur den Text** steuert (C9)
   - sie wird nie Teil eines Namens, eines Ziels oder eines Pixel-Parameters (Regel 2)
3. **Ihr Bild.**
   - „Von der KI gestalten lassen" (wie heute, drei Formate, zählt als eine Grafik, B13)
   - ab WP-50 auch „Eigenes Foto verwenden"
   - der HWG-Befund in einem Satz; die Bildbestätigung aus WP-41 sitzt hier
   - das Erzeugen dauert 1–3 Minuten; der Assistent wartet sichtbar (`useNachladen`) und lässt sich verlassen und wieder aufnehmen
4. **Wo und wie viel?** Standort, Monatsbetrag, Laufzeit; „Anpassen" für Umkreis, Alter, Geschlecht und eigene Namen.
5. **Prüfen und starten.**
   - Zusammenfassung in Sätzen, zum Beispiel: „Ihre Anzeige läuft ab heute vier Wochen im Umkreis von 20 km um Hamburg-Eimsbüttel, etwa 300 € im Monat." (zu „etwa" siehe Fallstricke)
   - ein Knopf „Starten" und ein Knopf „Als Entwurf speichern"

## Schritte (Umsetzung)
1. Abnahmekriterien als Tests.
2. **Entwurf des Assistenten** als eigener Datensatz (`campaign_drafts`, Mandant, verschlüsselt, wo Text drin ist). So überlebt Schritt 3 das Verlassen der Seite. Ein Entwurf ohne Start verfällt nach `mrs.ads.entwurf_tage` (Vorschlag 30), durchgesetzt von Hand wie jede Frist (C19).
3. **`Werbestrecke`** (`app/Werbung/Verwaltung/`): nimmt den Entwurf und legt lokal an:
   - Kampagne, Gruppe und Anzeige als `Pending`/`PAUSED`
   - eine Kette `Bus::chain([KampagneUebertragen, AnzeigeUebertragen, StreckeStarten])`
   - `StreckeStarten` schaltet nach WP-41 von oben nach unten
4. **FormRequest `WerbestreckeRequest`:**
   - bindet `Kampagnenplan::regeln()` **unverändert** ein; der Test prüft dessen Schlüssel exakt (`KampagnenverwaltungTest.php:100-114`)
   - dazu die Felder für Entwurf und Monatsbetrag
   - `Namenspruefung` am Feld wie bisher
5. **Oberfläche:**
   - `pages/werbung/Assistent.vue` mit dem Stepper von shadcn-vue: über die CLI nach `components/ui/stepper` (S3), `radix-vue` 1.9.11 bringt das Primitiv
   - der Einstieg „Neue Werbung" ist der erste Knopf auf `/werbung`
6. **`/werbung` wird „Laufende Werbung":**
   - Zustand in Worten, Zahlen nach WP-40 (ausgegeben, so oft gesehen, Klicks, Anfragen)
   - CTR, CPC und CPM unter „Details"
   - fremde Kampagnen stehen in einem eigenen Abschnitt „Bei Meta angelegt" (C9: gekennzeichnet, nicht umbenannt)
   - „Kampagne anlegen" im alten Dialog wandert unter „Für Fortgeschrittene"
7. Tourtext für den neuen Einstieg (`useEinfuehrung.ts`, `BauteileTest`).

## Abnahmekriterien
1. Ein Assistentenlauf „Neue Patientinnen" mit einem Wochenvorschlag und KI-Grafik legt genau eine Kampagne, eine Gruppe und eine Anzeige an, alle `Pending`, und stellt genau eine Kette in die Warteschlange. An Meta geht im Request nichts.
2. Zweimal „Starten" mit demselben Entwurf legt nichts doppelt an. Der zweite Aufruf meldet, dass die Werbung schon angelegt ist.
3. Die Kette läuft in der Reihenfolge Kampagne → Anzeige → Starten. Scheitert die Anzeige endgültig, bleibt die Kampagne pausiert und die Übersicht zeigt den Grund in Worten.
4. Ein Monatsbetrag von 300 € ergibt ein Tagesbudget von 10 €, 100 € ergeben 4 €. Ein Betrag unter 30 × `min_daily_budget` (heute 30 €) ergibt das Mindestbudget, mit dem Hinweis, dass der Monat dann mehr kostet.
5. „Ab heute, 4 Wochen" ergibt Beginn heute und Ende in 28 Tagen, jeweils in der Zeitzone des Standorts, und beides geht an Meta (WP-41).
6. Bei einem Standort fragt der Assistent keinen Standort. Bei zwei Standorten ist keiner vorgewählt.
7. Ohne Facebook-Seite, ohne Werbekonto oder ohne Stadt am Standort zeigt der Assistent vor Schritt 1, was fehlt, und für jeden Punkt einen Knopf zur richtigen Seite. Wer die Seite nicht bearbeiten darf, bekommt keinen Knopf, sondern den Hinweis, wen er fragen soll.
8. Eine gewählte Behandlung erscheint im Anzeigentext und in keinem Payload außer dem Creative-Text (Prüfung gegen `Treatment::aktiveNamen()`).
9. Eine Grafik ohne Freigabe (Gelb ohne Bestätigung, Rot) lässt Schritt 5 nicht zu.
10. Ein Entwurf übersteht das Verlassen der Seite während der Grafikerzeugung. Beim Zurückkehren steht der Assistent auf Schritt 3, mit fertiger Grafik.
11. Die Kachel „Neue Mitarbeitende" ist sichtbar, deaktiviert und führt nirgendwohin, bis WP-49 sie freigibt.
12. Auf `/werbung` und im Assistenten steht kein Wort aus `wortwahl.verboten` (WP-40).
13. Die bestehenden Tests in `KampagnenverwaltungTest`, `AnzeigenschaltungTest`, `PixelTest` und `NurLesendTest` laufen unverändert grün.

## Nicht in diesem Paket
- Recruiting (WP-49) und eigenes Bildmaterial (WP-50); beide hängen sich in die Schritte 1 und 3 ein.
- Mehrere Anzeigen je Kampagne aus dem Assistenten, A/B-Tests, Budgetverteilung über Standorte.
- Ein Bezug zur Auslastung („freie Kapazität nächste Woche"); kommt als Hinweis mit WP-46.

## Fallstricke
- **`Kampagnenplan::regeln()` nicht erweitern.** Der Test prüft die Schlüssel exakt. Neue Felder gehören in den neuen FormRequest.
- **Die Anzeige wartet auf ihre Gruppe.** `AnzeigeUebertragen` versucht „Anzeigengruppe steht noch nicht bei Meta" nur dreimal und scheitert dann endgültig. In der Kette ist die Gruppe beim Start der Anzeige schon da; ein Neustart von außen darf die Kette nicht überholen.
- **Monatsbetrag ist ein Versprechen, das Meta nicht kennt.** Meta arbeitet mit Tagesbudgets und darf einen Tag um bis zu 75 % überziehen, im Wochenmittel nicht. Der Satz in Schritt 5 heißt deshalb „etwa", nicht „höchstens", oder die Strecke setzt ein Laufzeitbudget (`lifetime_budget`). Vor dem Bau festlegen und in WP-41, Schritt 1 mitmessen.
- **Einen Ads-Manager im Kleinen bauen ist die Versuchung.** Jedes Feld, das „Anpassen" hinzukommt, braucht einen Grund aus dem Feedback.
