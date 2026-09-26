# WP-12 · Öffentliche Buchungsseite

## Ziel
Eine Interessentin bucht in zwei Minuten einen Termin, ohne anzurufen — und
die Seite trägt dabei die Marke der Praxis, nicht unsere.

## Vorher lesen
- **`docs/fachlogik/verfuegbarkeit.md`**, Abschnitt „Schnittstelle nach außen":
  „nur öffentlich buchbare Terminarten, Rasterung auf Anzeigeschritte"
- **`docs/design/farben.md`**, Abschnitte „Zwei Systeme" und „Mandantenfarbe
  auf der Buchungsseite"
- `docs/entscheidungen.md` — **D2** Behandlungswunsch als `treatment_id`, nie
  als Freitext; **P1** keine Behandlungsdokumentation; **P2** keine
  Anzahlungen; **C6** Anhänge mit Ablaufdatum; **A1**, **A3**
  Mandantentrennung
- `CLAUDE.md`, Regeln 1 und 3

## Voraussetzungen
WP-09, WP-10, WP-11

## Die eine Entscheidung dieses Pakets

**Die Seite ist öffentlich und setzt trotzdem einen Mandanten.** Das ist der
gefährlichste Satz in diesem Projekt.

Jede bisherige Mandantenauflösung hing an einem angemeldeten Benutzer
(`ResolveTenant`). Hier gibt es keinen. Der Mandant kommt aus dem Slug in der
URL — und **nur** von dort. Kein Feld des Formulars, kein Header, kein
Cookie, keine Eingabe der Besucherin darf ihn beeinflussen oder verschieben.

Daraus folgen drei Regeln, die der Test erzwingt:

1. Die Auflösung läuft in **einem** Middleware, das nur den Routenparameter
   liest. Ein zweiter Weg wäre ein zweiter Angriffspunkt.
2. Jede ID, die von außen hereinkommt — Terminart, Standort, Behandler,
   Reservierung —, wird **innerhalb** des aufgelösten Mandanten gesucht. Der
   Global Scope tut das ohnehin; der Test weist nach, dass er es tut.
3. Eine gesperrte Organisation (`suspended_at`) hat keine Buchungsseite.

## Was die Seite zeigt — und was nicht

| | |
|---|---|
| Terminart, Dauer | **ja** |
| Standort, Anschrift | **ja** |
| Behandler | **ja**, wenn die Praxis mehrere hat |
| Preis, Preisspanne | **nein**, bis WP-30 |
| Behandlungsbeschreibung | **nein**, bis WP-30 |
| Freitextfeld für das Anliegen | **nein**, dauerhaft |

**Kein Preis und keine Behandlungsbeschreibung, bis die HWG-Prüfung steht.**
Der Katalog trägt beides (WP-09), und WP-09 hat die Entscheidung ausdrücklich
hierher verschoben — „was davon öffentlich gezeigt wird, entscheidet WP-12
zusammen mit WP-30". WP-30 gibt es noch nicht. Eine ungeprüfte Preis- oder
Wirkaussage auf der Werbeseite einer ästhetischen Praxis ist genau die
Haftung, gegen die dieses Produkt antritt.

**Kein Freitextfeld, und zwar dauerhaft.** Ein „Ihr Anliegen"-Feld auf einer
öffentlichen Seite füllt sich innerhalb von Tagen mit Sätzen wie „seit der
Unterspritzung habe ich Taubheit an der Stirn". Das sind Gesundheitsdaten nach
Art. 9 DSGVO, abgegeben, bevor irgendjemand eingewilligt hat, und sie landen
in einem Feld, das niemand als solches behandelt. Der Behandlungswunsch läuft
über die **Terminart** (Entscheidung D2). Freitext gehört in die Inbox
(WP-21), wo er verschlüsselt liegt und der Agent ihn gegen Regel 5 und die
Eskalationsregeln prüft.

## Der Slot wird gehalten, bevor das Formular erscheint

Reihenfolge: Zeit wählen → **Hold** → Formular → buchen.

Andersherum füllt die Interessentin zwei Minuten lang Felder aus und bekommt
danach „inzwischen vergeben". Das ist der Abbruchgrund Nummer eins bei
Online-Buchungen.

Der Hold läuft 10 Minuten (`config/mrs.php`, `booking.hold_ttl_minutes`). Die
Seite zeigt die verbleibende Zeit an. **Die Reservierung steht in der Sitzung,
nicht im Formular** — eine Hold-ID, die der Browser zurückschickt, ist eine
fremde Reservierung, die jemand übernehmen kann.

## Anzeigeraster

Die Engine rechnet in 5-Minuten-Schritten (Entscheidung A9). Eine Liste mit
„09:00, 09:05, 09:10 …" ist keine Auswahl, sondern eine Zumutung. Die
öffentliche Seite zeigt nur Startzeiten auf dem **Anzeigeraster**
(`booking.display_step_minutes`, Standard 15) — gemessen an der **angezeigten**
Startzeit in der Ortszeit des Standorts, nicht an der belegten.

## Die Markenfarbe

Der Kunde gibt **einen** Hex-Wert an, die Abstufung wird abgeleitet — über
OKLCH, nicht über HSL: Farbton und Chroma bleiben, nur die Helligkeit
wandert. Eine HSL-Ableitung bleicht gesättigte Töne in den mittleren Stufen
aus.

**Der Erzeuger gibt ausschließlich `--primary`, `--primary-foreground` und
`--ring` aus.** Die Semantikfarben sind nicht überschreibbar, und das wird
hier erzwungen und nicht durch Konvention: wäre die Markenfarbe einer Praxis
grün, würde ein grünes „bestanden" in der HWG-Ampel mehrdeutig.

Erreicht Weiß auf der abgeleiteten Farbe keine 4,5:1, wird sie **abgedunkelt**,
bis sie es tut. Eine Schaltfläche, die niemand lesen kann, ist schlimmer als
eine, die nicht ganz der Marke entspricht.

## Schritte

1. Middleware `ResolvePublicTenant` — Mandant aus dem Slug, sonst 404.
2. `Markenstil` — Hex nach OKLCH, Helligkeit auf Kontrast korrigiert, nur die
   drei erlaubten Variablen.
3. `OeffentlicheVerfuegbarkeit` — nur öffentliche, aktive Terminarten,
   Rasterung auf Anzeigeschritte, nach Tagen gruppiert.
4. Reservieren, freigeben, buchen — Hold in der Sitzung.
5. `appointments.consent_accepted_at` — der Zeitpunkt der Einwilligung.
6. Oberfläche: eigenes Layout, nicht das der Verwaltung.
7. Bestätigungsseite.
8. Drosselung und Honigtopf gegen automatisierte Buchungen.

## Abnahmekriterien

**Mandantengrenze**

1. Ein unbekannter Slug ergibt 404.
2. Eine gesperrte Organisation ergibt 404.
3. Die Seite zeigt ausschließlich Daten dieser Organisation.
4. Eine Terminart einer **fremden** Organisation lässt sich nicht buchen,
   auch nicht mit gültiger UUID.
5. Ein Hold einer fremden Sitzung lässt sich nicht einlösen.

**Sichtbarkeit**

6. Nicht öffentliche Terminarten erscheinen nicht.
7. Inaktive Terminarten erscheinen nicht.
8. Inaktive Standorte erscheinen nicht.
9. Preise und Behandlungsbeschreibungen erscheinen nirgends.

**Anzeigeraster**

10. Angeboten werden nur Startzeiten auf dem Anzeigeraster.
11. Das Raster misst die **angezeigte** Startzeit, nicht die belegte.

**Reservieren und buchen**

12. Das Formular erscheint erst, wenn der Slot gehalten wird.
13. Ein Hold macht den Slot für andere unsichtbar.
14. Die Buchung erzeugt einen Termin im Status `pending` mit Kanal `public`.
15. Die Buchung behält die Zeilen des Holds.
16. Ein abgelaufener Hold lässt sich nicht einlösen.
17. Die Reservierung lässt sich freigeben.
18. Ohne Einwilligung keine Buchung.
19. Der Zeitpunkt der Einwilligung wird festgehalten.
20. Ein bestehender Kontakt wird über die E-Mail-Adresse wiedererkannt.

**Markenfarbe**

21. Ohne Markenfarbe steht die Produktfarbe.
22. Eine Markenfarbe überschreibt `--primary` und `--ring`.
23. Eine Markenfarbe überschreibt **keine** Semantikfarbe.
24. Weiß auf der abgeleiteten Farbe erreicht mindestens 4,5:1 — auch bei
    Neongelb.
25. Der Farbton bleibt erhalten.

**Missbrauch**

26. Der Honigtopf fängt eine automatisierte Buchung ab.
27. Die Buchung ist gedrosselt.

## Nicht in diesem Paket

**Attribution** (WP-32). Die Spezifikation sieht ein First-Party-Cookie
`mrs_vid` vor, gesetzt von der Buchungsseite. Es entsteht hier **nicht**: ein
Cookie zur Werbeerfolgsmessung ist nicht technisch notwendig und braucht eine
Einwilligung, und das Einwilligungskonzept gehört zu WP-18. Ein Cookie ohne
Bannerkonzept wäre ein Datenschutzverstoß mit Ansage.

**Whitelabel** (WP-07): eigene Domain, Logo, Validierung der Markenfarbe bei
der Eingabe. Hier entsteht der **Erzeuger** der Stile, weil die Seite sonst
nicht rendern kann; die Eingabemaske und die drei Prüfregeln kommen dort.

**HWG-Prüfung** (WP-30) und damit Preis- und Behandlungsanzeige.

**Erinnerungen und Bestätigungsmails** (WP-13). Die Seite bestätigt im
Browser; eine Mail verschickt sie nicht.

**Einwilligungen als Modell** (D8, WP-18): je Kanalidentität, widerrufbar, mit
Historie. Hier steht nur der Zeitpunkt am Termin — das ist der Nachweis für
**diese** Buchung, nicht die Einwilligungsverwaltung.

Anzahlungen und Stornogebühren (P2). Warteliste (WP-25).

## Fallstricke

- **Der Mandant darf nur aus dem Slug kommen.** Jede zweite Quelle ist ein
  zweiter Angriffspunkt. Und jede ID aus dem Formular wird innerhalb des
  aufgelösten Mandanten gesucht, nie davor.
- **Die Hold-ID gehört in die Sitzung, nicht ins Formular.** Sonst löst
  jemand die Reservierung eines anderen ein.
- **Ein Freitextfeld ist keine Bequemlichkeit, sondern eine Art-9-Falle.**
  Siehe oben.
- **Das Anzeigeraster misst die angezeigte Zeit.** Wer auf `blocked_from`
  rastert, bietet bei einer Rüstzeit von 5 Minuten lauter krumme Uhrzeiten an.
- **HSL bleicht aus.** Die Ableitung der Markenfarbe läuft über OKLCH.
- **Weiß auf der Markenfarbe muss lesbar sein**, sonst hat die Praxis eine
  Buchungsseite mit unsichtbarer Schaltfläche — und merkt es nicht, weil sie
  sie selbst nie benutzt.

## Stand

Alle 27 Abnahmekriterien sind als Tests umgesetzt und laufen:

| Datei | Deckt ab |
|---|---|
| `tests/Feature/Buchung/BuchungsseiteTest.php` | 1–20, 26 und die Entdopplung |
| `tests/Feature/Buchung/MarkenstilTest.php` | 21–25 |

Kriterium 27 (Drosselung) steht als Middleware an der Route und wird nicht
getestet — ein Test, der zwanzigmal buchen muss, kostet mehr Laufzeit als er
wert ist.

Die Seite liegt unter `/buchen/{praxis}`. Auf den Demodaten: vier öffentliche
Terminarten, Zeiten auf dem 15-Minuten-Raster, Reservierung mit Restzeitanzeige,
Buchung als `pending` über den Kanal `public` mit Einwilligungszeitpunkt.

## Was das Bauen zutage gefördert hat

**Der Kontrast musste auf dem gerundeten Token geprüft werden, nicht auf der
gerechneten Farbe.** Gold (`#C9A227`) erreichte gerechnet 4,52:1 und als
Token — gerundet auf ganze Grad und ganze Prozent — nur 4,37:1. Der Test fand
das sofort; ohne ihn wäre eine Praxis mit der häufigsten Markenfarbe der
Branche unter der Schwelle gelandet. Die Ableitung dunkelt jetzt so lange ab,
bis **der Token** besteht.

**`:model-value` an einer Checkbox tut nichts.** Das Projekt steht auf
radix-vue v1; dessen `CheckboxRoot` heißt `checked` und `update:checked`. Ein
`:model-value` fällt als gewöhnliches Attribut durch: das Häkchen erscheint,
lässt sich anklicken — und der gebundene Wert ändert sich nie. Aufgefallen ist
es an der Einwilligung, die sichtbar gesetzt war und trotzdem abgelehnt wurde.

Betroffen waren außerdem **die Freigaben der Terminarten** (Behandler und
Standorte ließen sich nicht zuordnen), **die Standortzuordnung der Behandler**
und **das Übersteuern im internen Buchungsdialog** — alles aus dem
Kosmetik-Durchgang davor, alles ohne Fehlermeldung. `tests/Feature/Design/BauteileTest.php`
macht daraus einen Fehlschlag.

**Zwei Behandler zur selben Zeit sind kein zweites Angebot.** Die Engine
liefert jeden Zeitpunkt je Behandler; auf der öffentlichen Seite stand dadurch
„09:00, 09:00, 09:15, 09:15". Für die Interessentin ist das dieselbe Uhrzeit
doppelt. Die Liste ist jetzt je Uhrzeit entdoppelt; welcher Behandler es wird,
steht in der Reservierung.

## Offen

> **Nachtrag 26.09.2026.** Preis und Beschreibung erscheinen, sobald die
> HWG-Prüfung sie freigibt (C11, `tests/Feature/Compliance/VeroeffentlichungTest.php`).
> Logo und Markenfarbe kamen mit WP-07. Offen bleibt die eigene Domain.

Die Seite liegt unter unserer Domain. Eigene Domain, Logo und die Prüfung der
Markenfarbe bei der Eingabe kommen mit WP-07.

Preis und Behandlungsbeschreibung bleiben aus, bis WP-30 sie prüfen kann.
