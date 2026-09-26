# WP-07 · Whitelabel

## Ziel
Die Buchungsseite trägt die Marke der Praxis — und bleibt dabei lesbar,
rechtssicher und unverwechselbar in ihren Statusfarben.

## Vorher lesen
- **`docs/design/farben.md` vollständig.** Verbindlich; der Abschnitt
  *Validierung bei der Eingabe* ist der Auftrag.
- `docs/konventionen.md`, Abschnitt Frontend
- `app/Support/Markenstil.php` — der Erzeuger steht schon, samt der Sperre
  auf drei Variablen
- `docs/datenmodell.md`, Abschnitt 1

## Voraussetzungen
WP-03, WP-12 (Buchungsseite), WP-18 (Anhänge für das Logo).

## Zwei Systeme, nicht eines

`docs/design/farben.md` sagt es in einem Satz: **den Admin-Bereich anfassbar
zu machen ist der Fehler, der Whitelabel-Produkte kaputt macht.**

| | Admin-Bereich | Buchungsseite |
|---|---|---|
| Wessen Marke | **unsere** | **die der Praxis** |
| Primärfarbe | Petrol, fest | aus `brandings` |
| Semantik | fest | fest |

Dieses Paket rührt den Admin-Bereich nicht an. Was es tut, tut es auf der
Buchungsseite — und dort nur an Schaltflächen, Fokusrahmen und Akzenten,
niemals großflächig.

## Der Erzeuger steht, das Tor fehlt

`App\Support\Markenstil` gibt seit WP-12 genau drei Variablen aus und kann
gar nichts anderes ausgeben. Er **dunkelt ab, bis Weiß lesbar ist**, und
fällt bei Unsinn auf die Produktfarbe zurück — damit eine Buchungsseite nicht
wegen eines Tippfehlers im Farbwert gar nicht mehr rendert.

Was fehlt, ist die andere Seite: **die Prüfung bei der Eingabe.** Der
Erzeuger darf nachsichtig sein, weil er im Ausliefern steht. Das Formular
darf es nicht — sonst trägt jemand Neongelb ein, sieht ein dunkles Oliv und
hält das Produkt für kaputt.

`docs/design/farben.md` nennt drei Regeln, und alle drei verhalten sich
verschieden:

1. **Zu heller Wert → Hinweis, nicht Ablehnung.** Die Farbe wird abgedunkelt,
   und die Praxis erfährt es — mit beiden Werten nebeneinander.
2. **Farbton nahe einer Semantikfarbe → Warnung.** Die Farbe wird verwendet;
   Rot bleibt Rot, Grün bleibt Grün. Eine Praxis mit roter Marke soll wissen,
   dass ihre Fehlermeldungen weiterhin rot sind.
3. **Extremwert → Ablehnung.** Neongelb und Reinweiß werden **nicht
   stillschweigend korrigiert**. Wer sie einträgt, bekommt sie nicht.

## Die Buchungsseite ist eine öffentliche Website

Und damit impressumspflichtig. Bis hierher trug sie weder Impressum noch
Datenschutzerklärung — eine Praxis, die für Werbung wirbt, darf nicht
diejenige sein, die deswegen abgemahnt wird.

**Beides kommt von der Praxis**, nicht von uns: es sind ihre Seiten, unter
ihrer Domain. Das Produkt nimmt zwei Adressen entgegen, zeigt sie im Fuß der
Buchungsseite und **weist sichtbar darauf hin, solange sie fehlen**.

Kein hartes Sperren: eine Buchungsseite, die wegen eines fehlenden Links gar
nicht mehr erreichbar ist, nimmt der Praxis Termine weg, statt ihr zu helfen.
Der Hinweis steht deshalb im Produkt — dort, wo ihn jemand sieht, der ihn
beheben kann.

## Schritte

1. `brandings`, eine Zeile je Mandant: Primärfarbe, Logo, Impressum-Adresse,
   Datenschutz-Adresse.
2. `App\Whitelabel\Farbpruefung` — die drei Regeln aus `farben.md`, an einer
   Stelle.
3. Logo über die vorhandene Anhangsablage. **Die Virenprüfung ist hier keine
   Schranke** — siehe unten.
4. Seite *Erscheinungsbild* hinter `whitelabel.manage`, mit Vorschau.
5. Buchungsseite: Logo im Kopf, Rechtslinks im Fuß.
6. Der vorhandene Wert aus `organizations.settings` zieht um.

## Abnahmekriterien

**Farbe**

1. Eine gültige, dunkle Farbe wird unverändert übernommen.
2. Eine zu helle Farbe wird abgedunkelt und die Praxis darauf hingewiesen —
   mit beiden Werten.
3. Ein Farbton nahe Rot, Bernstein, Grün oder Blau erzeugt eine Warnung, und
   die Farbe wird trotzdem übernommen.
4. Reinweiß wird abgelehnt.
5. Neongelb wird abgelehnt.
6. Eine unbrauchbare Eingabe (kein Hex) wird abgelehnt.
7. **Die Semantikfarben bleiben unberührt** — auch bei roter Marke ist eine
   Fehlermeldung rot.
8. Der Admin-Bereich bleibt Petrol, gleich was in `brandings` steht.

**Logo**

9. Ein Logo lässt sich hochladen und erscheint im Kopf der Buchungsseite.
10. Ohne Logo steht dort der Name der Praxis — kein leerer Platz.
11. Ein **beanstandetes** Logo wird nicht ausgeliefert; ein ungeprüftes
    schon.

**Recht**

12. Sind Impressum und Datenschutzerklärung hinterlegt, stehen sie im Fuß der
    Buchungsseite.
13. Fehlt eines, erscheint im Produkt ein Hinweis — die Buchungsseite bleibt
    erreichbar.
14. Es werden nur Adressen mit `https` angenommen.

**Regeln**

15. Zwei Mandanten sehen ausschließlich ihr eigenes Erscheinungsbild.
16. Keine feste Farbe im Oberflächencode — der vorhandene Test gilt weiter.

## Nicht in diesem Paket

- **Eine eigene Domain je Praxis.** Ein anderes Thema mitsamt Zertifikaten;
  die Buchungsseite bleibt unter unserem Slug erreichbar.
- **Weiße Etikettierung des Admin-Bereichs.** `farben.md` schließt das
  ausdrücklich aus.
- **Dunkelmodus.** `farben.md`: vorerst nicht, und der Grund steht dort.
- **Eigene Schriftarten.** Eine fremde Schrift auf einer fremden Domain ist
  ein Datenschutzthema, kein Gestaltungsthema.
- **Absenderadresse für E-Mails.** Steht seit WP-20b unter *Einstellungen →
  Postfach*.

## Fallstricke

- **Ein nachsichtiges Formular und ein nachsichtiger Erzeuger** ergeben
  zusammen niemanden, der Nein sagt.
- **Eine Markenfarbe im Bereich der Semantikfarben** macht die HWG-Ampel aus
  WP-30 mehrdeutig. Deshalb die Warnung — und deshalb ist die Sperre im
  Erzeuger nicht verhandelbar.
- **Ein Logo ist eine Datei von außen**, aber nicht dieselbe Art von außen wie
  ein Chat-Anhang. Wer beides gleich behandelt, macht die strengere Regel
  wertlos — siehe *Nachtrag*.
- **Der Wert in `organizations.settings` ist noch da.** Zwei Orte für
  dieselbe Farbe wären zwei Farben, sobald jemand einen davon ändert.

## Stand

Die 16 Abnahmekriterien laufen:
`tests/Feature/Whitelabel/ErscheinungsbildTest.php` (**19 Tests**).
Gesamtstand 966 Tests, 3439 Zusicherungen. PHPStan Stufe 8 sauber, `vue-tsc`
sauber, 37 Seiten im Manifest.

Neu: `brandings` samt Modell, `App\Whitelabel\{Farbpruefung, Farbbefund}`,
`ErscheinungsbildController`, die Seite *Einstellungen → Erscheinungsbild*,
Logo und Rechtslinks auf der Buchungsseite, und eine öffentliche Route für
das Logo — die erste, die einen verschlüsselten Anhang ausliefert, und zwar
nur, wenn die Virenprüfung ihn freigegeben hat.

`config/mrs.php` → `whitelabel`: die Schwellen aus `docs/design/farben.md`
mit Fundstelle.

Im Browser durchgespielt: `#8E4B6E` ergibt `--primary: 329 31% 43%` auf der
Buchungsseite, während `--destructive` unverändert bei `0 74% 42%` bleibt und
der Arbeitsbereich petrol. `#FFFFFF` wird abgewiesen, `#C9A227` angenommen —
mit zwei Hinweisen: 2,42:1 gegen Weiß und Nähe zu Bernstein.

## Was das Bauen zutage gefördert hat

**Elf Controller meldeten Erfolge, die nirgends erschienen.** Geteilt wurden
nur `flash.erfolg` und `flash.fehler`, gerendert wurden sie ausschließlich auf
der Kalenderseite — `->with('status', …)` aus WP-26, WP-27, WP-29 und WP-32
lief ins Leere. Aufgefallen, weil der Farbhinweis aus diesem Paket denselben
Weg nahm und ebenfalls spurlos blieb.

Behoben an einer Stelle: `Rueckmeldung.vue` im `AppLayout`, drei Arten im
geteilten `flash`. Die dritte — `hinweise` — kam mit diesem Paket dazu, weil
eine abgedunkelte Markenfarbe weder Erfolg noch Fehler ist: etwas hat
geklappt, aber nicht ganz so, wie es eingegeben wurde.

**Der Erzeuger war nachsichtig, und niemand sagte Nein.** `Markenstil` fängt
seit WP-12 jeden Unsinn ab, damit die Buchungsseite rendert — das ist im
Ausliefern richtig und im Formular falsch. Ohne die Prüfung bei der Eingabe
hätte jemand Neongelb eingetragen, ein dunkles Oliv gesehen und das Produkt
für kaputt gehalten.

**Ein Kontrastwert trennt beide Extremfälle.** Neongelb erreicht 1,07 gegen
Weiß, Reinweiß 1,00 — beide überleben die nötige Abdunklung nicht als das,
was sie waren. Gold liegt mit 2,42 darüber und wird abgedunkelt, nicht
abgewiesen. Eine Schwelle statt zweier Sonderfälle.

**Die Toleranz für Semantik-Nähe musste größer werden.** Mit 15 Grad fiel ein
Praxisgrün bei 124 Grad durchs Raster, obwohl es sich genauso als „grün"
liest wie das Erfolgsgrün bei 142. Jetzt 25 Grad — Petrol (178) und Blau
(224) bleiben mit 46 Grad Abstand außerhalb, die Produktfarbe löst also
keinen Fehlalarm aus.

**Die Buchungsseite war impressumslos öffentlich.** Seit WP-12. Eine Praxis,
die für Werbung wirbt, darf nicht diejenige sein, die deswegen abgemahnt
wird. Jetzt zwei Adressen im Fuß — und solange sie fehlen, ein Hinweis im
Produkt, nicht eine gesperrte Seite: die nähme der Praxis Termine weg, statt
ihr zu helfen.

## Nachtrag: die Virenprüfung war das falsche Mittel

Die erste Fassung ließ ein Logo nur durch, wenn die Virenprüfung es
freigegeben hatte — dieselbe Regel wie für Chat-Anhänge. **Das war falsch, und
zwar in beide Richtungen.**

*Praktisch:* Ohne angebundenen ClamAV steht an jeder Datei `unscanned`. Die
strenge Regel hieß damit: in keiner Installation ohne Scanner erscheint jemals
ein Logo. Eine Funktion, die im Normalfall nicht funktioniert.

*Inhaltlich:* Die Regel aus WP-33 schützt vor dem, was **Fremde** ungefragt
schicken. Ein Logo lädt die Praxisinhaberin von ihrer eigenen Marke hoch,
angemeldet, mit `whitelabel.manage`. Beides gleich zu behandeln macht nicht
das Logo sicherer, sondern die strenge Regel beliebig.

*Und das eigentliche Risiko lag woanders:* erlaubt war `image/svg+xml`. Eine
SVG-Datei kann ein `<script>` enthalten, und ausgeliefert von unserer eigenen
Adresse wäre das ein Skript auf der öffentlichen Buchungsseite — gespeichertes
XSS im eigenen Ursprung. **Ein Virenscanner findet so etwas nicht.**

Jetzt gilt:

| | vorher | jetzt |
|---|---|---|
| Ungeprüft | blockiert | wird ausgeliefert |
| Beanstandet | blockiert | blockiert |
| SVG | erlaubt | abgelehnt |
| Ausgang | jeder Mime-Typ | nur die Allowlist, dazu `nosniff` |

Die Prüfung läuft weiter mit — sie blockiert nur nicht mehr, wenn sie
ausbleibt. Für Chat-Anhänge bleibt die strenge Regel unverändert: dort ist sie
richtig.

## Offen

> **Nachtrag 26.09.2026.** Die Logo-Route ist nicht mehr die einzige ihrer
> Art: Anhänge gehen über `anhang.zeigen` hinaus (C12). Das Mailgerüst trägt
> die Praxis in Kopf und Fuß (`resources/views/mail/praxis.blade.php`,
> `App\Benachrichtigung\Mailmarke`). Offen bleibt die eigene Domain je Praxis.

- **Eine eigene Domain je Praxis.** Ein anderes Thema mitsamt Zertifikaten.
- **Die Logo-Route ist die einzige ihrer Art.** Anhänge aus dem Posteingang
  haben weiterhin keinen Weg nach draußen (offen seit WP-21); das Muster
  steht jetzt aber.
- **Kein Zuschnitt, keine Vorschau des Logos in Originalgröße.** Was
  hochgeladen wird, erscheint in 32 Pixel Höhe — wer ein breites Logo hat,
  sieht es klein.
