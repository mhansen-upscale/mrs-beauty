# Design-System: Farben

Ergänzt `docs/konventionen.md`, Abschnitt Frontend. Verbindlich.

> **Umgesetzt.** Die Tokens stehen in `resources/css/app.css`, die
> Tailwind-Anbindung in `tailwind.config.js`. Die Regel „keine festen
> Farbwerte im Code" ist als Test hinterlegt:
> `tests/Feature/Design/FarbenTest.php`.
>
> **Die Validierung bei der Eingabe steht seit WP-07** in
> `App\Whitelabel\Farbpruefung`, die Schwellen in `config/mrs.php` →
> `whitelabel`. Der Erzeuger `App\Support\Markenstil` bleibt nachsichtig —
> er steht im Ausliefern, und eine Buchungsseite soll nicht wegen eines
> Tippfehlers im Farbwert weiß bleiben.

## Zwei Systeme, nicht eines

Das Produkt hat zwei Oberflächen mit unterschiedlichen Anforderungen. Sie zu vermischen ist der Fehler, der Whitelabel-Produkte kaputt macht.

| | Admin-Bereich | Buchungsseite |
|---|---|---|
| Wer sieht es | Praxisteam, täglich, stundenlang | Interessentinnen, einmal, zwei Minuten |
| Wessen Marke | **unsere** | **die der Praxis** |
| Primärfarbe | Petrol, fest | aus `brandings`, beliebig |
| Semantik | fest | fest |

Der Admin-Bereich ist ein Arbeitswerkzeug. Er darf und soll nach dem Produkt aussehen, nicht nach dem Kunden. Konfigurierbar bleiben dort nur Logo und Akzent für Kunden, die es ausdrücklich verlangen.

Die Buchungsseite trägt die Marke der Praxis. Sie ist deshalb ein **neutrales Gerüst**, das eine beliebige Primärfarbe aufnimmt, ohne auseinanderzufallen.

## Neutral — Canvas, Flächen, Text

Entspricht Tailwind `stone`. Minimal warm.

Der warme Einschlag ist eine bewusste Entscheidung: Ästhetische Praxen arbeiten überdurchschnittlich oft mit Blush, Creme, Roségold und Gold. Ein kühles Grau als Untergrund bekämpft diese Töne sichtbar, ein warmes trägt sie.

| Stufe | Hex | HSL | Verwendung |
|---|---|---|---|
| 50 | `#FAFAF9` | `60 9% 98%` | Canvas |
| 100 | `#F5F5F4` | `60 5% 96%` | gedämpfte Flächen, Zebra |
| 200 | `#E7E5E4` | `20 6% 90%` | Rahmen, Trenner |
| 300 | `#D6D3D1` | `24 6% 83%` | Eingabefeld-Rahmen |
| 400 | `#A8A29E` | `24 5% 64%` | Platzhalter, deaktiviert |
| 500 | `#78716C` | `25 5% 45%` | schwacher Text |
| 600 | `#57534E` | `33 5% 32%` | gedämpfter Text |
| 700 | `#44403C` | `30 6% 25%` | |
| 800 | `#292524` | `12 6% 15%` | |
| 900 | `#1C1917` | `24 10% 10%` | Vordergrund |

## Petrol — Produktfarbe, nur Admin

| Stufe | Hex | HSL |
|---|---|---|
| 50 | `#ECF5F4` | `173 31% 94%` |
| 100 | `#D0E6E4` | `175 31% 86%` |
| 200 | `#A3CCC9` | `176 29% 72%` |
| 300 | `#6FADA9` | `176 27% 56%` |
| 400 | `#45908C` | `177 35% 42%` |
| 500 | `#2A7471` | `178 47% 31%` |
| **600** | **`#1F5D5B`** | **`178 50% 24%`** | ← `--primary` |
| 700 | `#184A48` | `178 51% 19%` |
| 800 | `#123836` | `177 51% 15%` |
| 900 | `#0C2524` | `178 51% 10%` |

**Warum 600 und nicht 500 als Primärfarbe:** Weiß auf 500 erreicht 5,48:1, auf 600 sind es 7,57:1. Der Unterschied ist bei kleinen Schaltflächenbeschriftungen und bei Personal, das acht Stunden auf den Bildschirm sieht, spürbar.

**Warum Petrol:** Praxissoftware ist fast durchgängig blau, Endkunden-Beauty-Apps sind rosa. Beides wäre falsch. Petrol liegt nah genug an medizinischem Vertrauen und weit genug von beidem entfernt. Es steht außerdem ruhig neben Gold und Blush, den beiden Farben, die in dieser Branche am häufigsten als Markenfarbe auftauchen.

## Semantik — gesperrt

| Rolle | Hex | HSL | Weiß darauf |
|---|---|---|---|
| success | `#15803D` | `142 72% 29%` | 5,02:1 |
| warning | `#B45309` | `26 90% 37%` | 5,02:1 |
| danger | `#B91C1C` | `0 74% 42%` | 6,47:1 |
| info | `#1D4ED8` | `224 76% 48%` | 6,70:1 |

**Diese vier Farben sind nicht themebar, auch nicht auf der Buchungsseite.** Wäre die Markenfarbe einer Praxis grün, würde ein grünes „bestanden" in der HWG-Ampel mehrdeutig. Bei einer Funktion, deren Fehleinschätzung bis zu 50.000 Euro kostet, ist das nicht verhandelbar.

**Farbe trägt nie allein Bedeutung.** Jede Ampel, jedes Statusabzeichen und jede Warnung braucht zusätzlich ein Symbol und Text. Das gilt für die HWG-Ampel und für Terminstatus gleichermaßen.

## Kalenderfarben

Acht Töne in annähernd gleicher Helligkeit, damit kein Behandler optisch dominiert.

`#3B6EA5` `#2A7471` `#7A5C9E` `#A85751` `#4E7A3F` `#9A6B2F` `#6B7280` `#8A4F6D`

Weiß erreicht auf allen mindestens 4,65:1.

Bewusst gedämpft und klar von der Semantik getrennt. Ein Behandler in Signalrot liest sich wie ein Fehlerzustand. Bei mehr als acht Behandlern wird die Reihe wiederholt, nicht erweitert — weitere unterscheidbare Töne in dieser Helligkeit gibt es nicht.

Terminstatus wird **nicht** über Farbe abgebildet, sondern über Rahmenstil und Symbol, weil die Fläche bereits dem Behandler gehört.

## Mandantenfarbe auf der Buchungsseite

Der Kunde gibt **einen** Hex-Wert an, nicht zehn. Die Abstufung wird daraus abgeleitet.

**Ableitung über OKLCH:** Farbton und Chroma beibehalten, Helligkeit variieren. Das erhält die Farbanmutung über die gesamte Reihe, anders als eine Ableitung über HSL, die bei gesättigten Tönen in den mittleren Stufen ausbleicht.

**Validierung bei der Eingabe** (gehört zu WP-07):

1. Weiß auf der abgeleiteten 600er-Stufe muss mindestens 4,5:1 erreichen. Wird der Wert verfehlt, wird die Stufe automatisch abgedunkelt und der Kunde darauf hingewiesen.
2. Liegt der Farbton im Bereich der Semantikfarben (Rot, Bernstein, Grün), erscheint eine Warnung: Die Markenfarbe wird verwendet, Statusfarben bleiben unverändert.
3. Extreme Werte (Neongelb, Reinweiß) werden abgelehnt, nicht stillschweigend korrigiert.

Auf der Buchungsseite bleibt der Untergrund immer neutral. Die Markenfarbe erscheint in Schaltflächen, Fokusrahmen, ausgewählten Slots und Akzenten, niemals großflächig.

## Dunkelmodus

**Vorerst nicht.** Whitelabel und Dunkelmodus verdoppeln die Kontrastprobleme: Jede Mandantenfarbe müsste gegen zwei Untergründe geprüft werden, und eine Farbe, die auf Weiß funktioniert, tut es auf Dunkel oft nicht.

Die Tokens sind so strukturiert, dass er später nachrüstbar ist. Die Buchungsseite bleibt dauerhaft hell, damit das Markenbild vorhersagbar bleibt.

## Umsetzung

Alles läuft über CSS-Variablen auf den shadcn-Tokens. **Keine festen Farbwerte im Code**, weder als Hex noch als Tailwind-Palette (`bg-blue-500`). Der Test aus WP-07 erzwingt das.

```css
:root {
  --background: 60 9% 98%;
  --foreground: 24 10% 10%;
  --muted: 60 5% 96%;
  --muted-foreground: 33 5% 32%;
  --border: 20 6% 90%;
  --input: 24 6% 83%;

  --primary: 178 50% 24%;
  --primary-foreground: 0 0% 100%;
  --ring: 178 47% 31%;

  --destructive: 0 74% 42%;
  --destructive-foreground: 0 0% 100%;
  --success: 142 72% 29%;
  --warning: 26 90% 37%;
  --info: 224 76% 48%;
}
```

Auf der Buchungsseite überschreibt eine mandantenspezifische Regel `--primary` und `--ring`. Alles andere bleibt. Die Semantikvariablen sind von der Überschreibung ausgenommen, das wird im Erzeuger der Branding-Stile erzwungen, nicht durch Konvention.

Prüfe beim Einrichten von shadcn-vue, ob die verwendete Version HSL oder OKLCH für ihre Tokens erwartet, und übernimm das Format konsistent.
