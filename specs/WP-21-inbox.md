# WP-21 · Inbox-Oberfläche

## Ziel
Die Praxis sieht, was hereinkommt, und antwortet darauf — an einer Stelle,
über beide Kanäle, ohne vorher zu wissen, welcher es war.

## Vorher lesen
- `specs/WP-19-kanal-infrastruktur.md` und `specs/WP-20-kanaele.md` — die
  Strecke und die beiden Kanäle
- `docs/integrationen/meta.md`, Abschnitt **WhatsApp** — Service-Fenster,
  Templates, Kosten
- `docs/integrationen/email.md`
- `docs/konventionen.md` — Oberfläche, Bauteile, Navigation
- `docs/entscheidungen.md` — **P8** keine Volltextsuche, **D1** Kontakte statt
  Patienten, **D5** Kanalidentitäten, **C7** Aufbewahrung
- `CLAUDE.md`, Regeln 1, 3, 4 und **5**

## Voraussetzungen
WP-16, WP-17, WP-18, WP-19, WP-20.

## Was diese Oberfläche schwierig macht

**Regel 5 wird hier sichtbar.** Bis jetzt war eine Nachricht eine Zeichenkette
in einer Spalte. Ab jetzt wird sie **angezeigt** — und damit stellt sich zum
ersten Mal die Frage, ob sie dabei etwas tun kann. Die Antwort ist nein: der
Inhalt wird als Text gesetzt, nie als Auszeichnung. Kein `v-html`, nirgends,
und ein Test hält das fest.

**Das Service-Fenster ist eine Kostenanzeige.** Ab dem 01.10.2026 kostet jede
WhatsApp-Nachricht außerhalb der 24 Stunden Geld, und zwar je nach Kategorie
unterschiedlich viel. Wer das erst beim Absenden erfährt, hat es schon
ausgegeben. Die Inbox zeigt vorher an, was eine Antwort jetzt bedeutet.

**Suche nur über Metadaten** (P8). Inhalte sind verschlüsselt und kennen kein
LIKE. Das gehört sichtbar in die Oberfläche, sonst hält der Empfang die Suche
für kaputt — dieselbe Entscheidung wie bei den Kontakten in WP-16.

**Gelesen heißt gesehen, nicht beantwortet.** Eine Praxis mit drei Personen am
Empfang braucht keinen ungelesen-Zähler je Person: sie braucht zu wissen, ob
*jemand* die Nachricht schon gesehen hat. Der Stand hängt deshalb an der
Konversation und nicht am Benutzer.

## Schritte

1. `conversations.last_read_at` — der Gelesen-Stand der Praxis.
2. `Message::attachments()` — die Dateien einer Nachricht.
3. `Posteingang` — die Liste: Filter, Zählung, Sortierung.
4. `InboxController` — Liste, Verlauf, Antwort, Template, Schließen, Zuordnen.
5. `inbox/Index.vue` — zwei Spalten, mobil nacheinander.
6. Navigation: **Posteingang** unter Betrieb, vor Anfragen.

## Abnahmekriterien

**Liste**

1. Die Liste zeigt nur Konversationen des eigenen Mandanten.
2. Sortiert nach letzter Aktivität, neueste zuerst.
3. Ungelesen ist, was nach dem letzten Lesen hereinkam.
4. Der Filter nach Kanal greift.
5. Der Filter nach Zustand (offen, geschlossen) greift.
6. Die Suche findet über den Namen des Kontakts, nicht über Inhalte (P8).
7. Wer `inbox.view` nicht hat, kommt nicht hinein.

**Verlauf**

8. Der Verlauf zeigt ein- und ausgehende Nachrichten in zeitlicher Folge.
9. Öffnen setzt den Gelesen-Stand.
10. Anhänge erscheinen erst, wenn die Virenprüfung sie freigegeben hat.
11. Der Verlauf einer fremden Konversation ist nicht erreichbar.

**Antworten**

12. Eine Antwort wird eingereiht, nie im Anfragezyklus gesendet (Regel 4).
13. Wer nur `inbox.view` hat, kann nicht antworten.
14. Bei offenem Fenster geht Freitext.
15. Bei geschlossenem Fenster zeigt die Oberfläche, dass ein Template nötig
    ist — samt Kategorie und damit der Kostenfolge.
16. Ein Template lässt sich mit Variablen abschicken.
17. Für E-Mail gibt es weder Fenster noch Template.

**Kontakt und Anfrage**

18. Eine Konversation ohne Kontakt lässt sich einem zuordnen.
19. Das Zuordnen trägt den Kontakt an der Kanalidentität nach.
20. Die zugeordnete Person erscheint mit Name und offener Anfrage.

**Zustand**

21. Schließen setzt `closed_at`; eine neue eingehende Nachricht öffnet wieder.
22. Der Zustand des Kanals (unterbrochen, eingeschränkt) ist sichtbar.

**Regel 5**

23. Kein `v-html` in der gesamten Oberfläche — durchgesetzt durch einen Test.
24. Eine Nachricht mit Auszeichnung erscheint als Text, nicht als Auszeichnung.

## Nicht in diesem Paket

- **Der Agent** (WP-22 bis WP-24). `agent_mode` wird angezeigt, nicht bedient.
- **Zuweisung an eine Person.** Eine Praxis dieser Größe teilt sich einen
  Posteingang; eine Zuweisung ohne Bedarf ist ein Feld, das niemand pflegt.
- **Notizen an der Konversation** (WP-18 hängt sie an Kontakt und Anfrage).
- **Medien herunterladen bei WhatsApp** — offen aus WP-20a.
- **Kostenanzeige in Euro** (WP-06). Gezeigt wird die Kategorie, nicht der
  Preis.

## Fallstricke

- **`v-html` ist der kürzeste Weg zu einer Nachricht, die etwas tut.**
- **Das Fenster steht in UTC.** Die Restzeit gehört in die Ortszeit des
  Standorts gerechnet, nicht in die des Browsers.
- **Ein Anhang ohne Befund wird nicht ausgeliefert** (WP-18).
- **Die Liste darf keine Inhalte durchsuchen** — es ginge technisch nicht und
  sähe nur so aus, als wäre die Suche kaputt.

## Stand

Alle 24 Abnahmekriterien laufen: `tests/Feature/Kanaele/PosteingangTest.php`
(**24 Tests**) und `tests/Feature/Design/RegelFuenfTest.php` (**3**).
Gesamtstand 695.

Neu: `conversations.last_read_at`, `Message::attachments()`,
`App\Kanaele\Posteingang`, `InboxController` unter `/posteingang`, die Seite
`inbox/Index.vue`, der Menüpunkt **Posteingang** unter Betrieb und ein
`Textarea`-Bauteil in der shadcn-Form. Der Seeder legt zwei Gespräche an —
ein leerer Posteingang sagt nichts darüber, ob er funktioniert.

## Was das Bauen zutage gefördert hat

**Regel 5 hatte schon eine Lücke, bevor die Inbox sie aufmachte.** Der
Architekturtest fand `v-html` im Dashboard: der QR-Code des Buchungslinks
wurde als Auszeichnung eingesetzt. Serverseitig erzeugt und damit harmlos —
aber die einzige Stelle im Produkt, an der aus einer Zeichenkette
Auszeichnung wird, und damit eine Ausnahme, die jede spätere Diskussion
gewinnt. Der QR-Code kommt jetzt als Datenadresse in einem `<img>`, in dem
ein SVG nichts ausführen kann. Die Ausnahme ist weg, der Test kennt keine
Allowlist.

**Automatisch aufgeschlagen ist nicht ausgewählt.** Der erste Entwurf öffnete
das erste Gespräch und markierte es gelesen. Zwei Fehler in einem: der
Ungelesen-Zähler wurde von allein kleiner, und auf dem Telefon führte der
Zurück-Knopf wieder ins selbe Gespräch — die Liste bekam man nie zu sehen.
Jetzt unterscheidet `ausgewaehlt` beides.

**Der Zeitpunkt einer Nachricht ist der ihrer Ankunft**, nicht der ihrer
Zeile. Ein erneut eingespieltes Rohereignis (WP-19) entsteht heute und trägt
trotzdem den Zeitpunkt von damals — der Verlauf zeigt jetzt `delivered_at`
bzw. `sent_at`.

**Der Profilname war nirgends zu sehen.** Die Liste zeigte für Unbekannte die
Rufnummer, obwohl WhatsApp und die Mail einen Namen mitliefern. Jetzt:
Kontakt, sonst Profilname, sonst Kennung.

## Offen

**Zuweisung an eine Person** — bewusst draußen, siehe oben. Sollte sich eine
Praxis melden, die sie braucht, ist es eine Spalte und ein Filter.

**Anhänge herunterladen.** Sie werden aufgelistet; eine Route, die sie
ausliefert, gehört zusammen mit der Vorschau in ein eigenes Stück — samt der
Frage, wer sie sehen darf.

**Nachladen älterer Nachrichten.** Der Verlauf zeigt die letzten 200. Für ein
Gespräch, das länger läuft, braucht es eine Seitenschaltung.

**Der Agent** (WP-22 bis WP-24). `agent_mode` wird angezeigt, nicht bedient.
