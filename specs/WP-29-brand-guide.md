# WP-29 · Brand Guide

## Ziel
Ein Ort, an dem steht, wie diese Praxis klingt und womit sie wirbt — damit
WP-31 nicht jede Woche eine neue Praxis erfindet.

## Vorher lesen
- `docs/datenmodell.md`, Abschnitt 9 — `banned_terms` gehört hierher
- `specs/WP-30-hwg-compliance.md` — `brand_violation` prüft gegen diese Liste
- `docs/entscheidungen.md` — **C9** Grenze zwischen Angebot und Person,
  **C10** erzeugte Bilder liegen bei uns, **C6** Anhänge mit Ablaufdatum,
  **P7** nur Deutsch
- `CLAUDE.md`, Regeln 3 und 5
- `docs/produkt.md`, *Das Differenzierungsmerkmal* — die Bibliothek
  rechtssicherer Alternativformate beginnt hier

## Voraussetzungen
WP-03, WP-18 (Anhänge, Virenprüfung), WP-33 (Virenprüfung im Betrieb).

## Die Linie, an der alles hängt

**Kein Referenzmaterial mit Patientinnen.**

Eine Praxis, die gefragt wird „womit wollen Sie werben", lädt Vorher-Nachher-
Bilder hoch. Das ist der wahrscheinlichste Fall, nicht der Randfall — und er
ist gleich zweimal falsch: es sind Gesundheitsdaten einer dritten Person
(Artikel 9 DSGVO, ohne Einwilligung), und als Werbung sind sie seit dem
BGH-Urteil vom 31.07.2025 verboten, auch bei minimalinvasiven Eingriffen.

Was dieses Paket dagegen tun kann, ist begrenzt und soll auch so benannt
werden: **eine Erklärung beim Hochladen, im Wortlaut festgehalten, mit Person
und Zeitpunkt.** Automatisch prüfen kann es nichts — die Bildprüfung kommt mit
WP-30.

Eine Erklärung, deren Wortlaut nicht mitgespeichert wird, ist ein Häkchen
ohne Aussage. Deshalb wie bei den Einwilligungen in WP-18: `text_snapshot`,
`declared_by`, `declared_at`.

## Was der Brand Guide ist — und was nicht

**Er ist:** Tonalität, Ansprache, Zielgruppe, Positionierung, Claim,
bevorzugte und verbotene Begriffe, Referenzmaterial der Praxis.

**Er ist nicht:** Farben und Logo. Die gehören zum Whitelabel (WP-07) und
stehen schon in `App\Support\Markenstil` — mit einem guten Grund, der dort
nachzulesen ist.

**Er ist auch nicht:** das Praxiswissen des Assistenten (WP-22). Der
Assistent beantwortet Fragen am Empfang; der Brand Guide beschreibt, wie
geworben wird. Zwei Texte, zwei Zwecke — sie zusammenzulegen hieße, dass eine
Änderung am Werbeton die Antwort auf eine Terminfrage verändert.

## Verbotene Begriffe sind zwei Dinge

Die Liste speist zwei verschiedene Prüfungen, und das muss sie aushalten:

1. **Markenverstoß** — „günstig", wenn die Praxis sich als hochwertig
   positioniert. Eine Geschmacksfrage der Praxis, ein Hinweis.
2. **`brand_violation` aus WP-30** — derselbe Mechanismus, aber im
   Compliance-Regelwerk. Dort ist es ein Befund mit Fundstelle.

Ein verbotener Begriff trägt deshalb einen **Ersatzvorschlag** und eine
**Begründung**. „Nicht ‚schmerzfrei' schreiben" ist eine Anweisung; „statt
‚schmerzfrei' lieber ‚gut verträglich', weil § 3 HWG" ist eine, der jemand
folgen kann.

## Schritte

1. `brand_guides`, eine Zeile je Mandant: Tonalität, Ansprache, Zielgruppe,
   Positionierung, Claim, No-Go-Themen.
2. `brand_terms`: Art (bevorzugt/verboten), Begriff, Ersatz, Begründung.
3. `brand_references`: Titel, Art (Räume, Team, Ablauf, Beispielanzeige),
   Anhang, **Erklärung im Wortlaut** mit Person und Zeitpunkt.
4. `AttachmentContext::BrandReference` — ohne Ablaufdatum (C6 gilt für
   Chat-Anhänge), aber mit Virenprüfung wie jeder Anhang.
5. `App\Marke\Markenprofil` — der Brand Guide als **Datenblock** für WP-31.
   Nicht als Anweisung: Regel 5 gilt auch für Text, den die Praxis selbst
   eingetragen hat, denn er kann von irgendwoher kopiert sein.
6. `App\Marke\Reifegrad` — was noch fehlt, damit ein Vorschlag mehr wird als
   eine Allerweltsanzeige.
7. Oberfläche *Marke* hinter `brandguide.manage`.

## Abnahmekriterien

**Brand Guide**

1. Eine Praxis ohne Brand Guide bekommt beim ersten Aufruf einen leeren, kein
   Fehlerbild.
2. Es gibt genau einen Brand Guide je Mandant — ein zweiter ist nicht
   anzulegen.
3. Tonalität und Ansprache sind aus einer festen Liste, nicht Freitext.
4. Zwei Mandanten sehen ausschließlich ihren eigenen.

**Begriffe**

5. Ein verbotener Begriff lässt sich mit Ersatz und Begründung anlegen.
6. Derselbe Begriff kann nicht zweimal in derselben Art stehen.
7. Die Liste der verbotenen Begriffe ist über eine Schnittstelle abrufbar,
   die WP-30 nutzen kann.
8. Ein Text wird gegen die Liste geprüft und meldet den Treffer samt Ersatz.
9. Die Prüfung achtet nicht auf Groß- und Kleinschreibung und nicht auf
   Wortgrenzen mitten im Wort.

**Referenzmaterial**

10. Ein Bild lässt sich **nicht** ohne die Erklärung hochladen.
11. Der Wortlaut der Erklärung wird mitgespeichert, samt Person und
    Zeitpunkt.
12. Eine Datei, die die Virenprüfung nicht freigibt, wird nicht ausgeliefert.
13. Referenzmaterial trägt **kein** Ablaufdatum — es ist kein Chat-Anhang.
14. Löschen entfernt Datensatz und Datei.

**Profil**

15. Das Profil enthält alles Eingetragene und nichts Erfundenes: eine leere
    Praxis ergibt ein leeres Profil, keinen Platzhaltertext.
16. Der Reifegrad benennt, was fehlt.
17. Kein Feld des Profils wird als Anweisung ausgegeben — es ist ein
    Datenblock (Regel 5).

## Nicht in diesem Paket

- **Anzeigenvorschläge.** WP-31. Hier entsteht nur, woraus sie gemacht
  werden.
- **Die HWG-Prüfung.** WP-30. Der Brand Guide liefert ihr die
  `banned_terms`, mehr nicht.
- **Bilderzeugung.** WP-31, über kie.ai (C10).
- **Farben und Logo.** WP-07.
- **Automatische Erkennung von Vorher-Nachher-Bildern.** WP-30. Hier steht
  die Erklärung, nicht die Prüfung — und das wird in der Oberfläche auch so
  gesagt.

## Fallstricke

- **Ein Häkchen ohne Wortlaut ist keine Erklärung.** Wer später fragt, was
  die Praxis zugesichert hat, braucht den Text von damals, nicht den von
  heute.
- **Freitextfelder, die in einen Prompt fließen**, sind Daten. Auch die der
  eigenen Praxis: Text wird kopiert, und was in einer Werbeagentur-Mail
  stand, steht dann im Brand Guide.
- **Ein Reifegrad, der immer „vollständig" sagt**, ist wertlos. Er muss an
  den Feldern hängen, die WP-31 wirklich braucht.
- **Verbotene Begriffe ohne Ersatz** erzeugen Vorschläge, die dieselbe
  Aussage nur umständlicher machen.

## Stand

Die 17 Abnahmekriterien laufen: `tests/Feature/Marke/MarkeTest.php`
(**20 Tests**). Gesamtstand 886 Tests, 3133 Zusicherungen. PHPStan Stufe 8
sauber, `vue-tsc` sauber, 35 Seiten im Manifest.

Neu: `brand_guides`, `brand_terms`, `brand_references` samt Modellen, die
Aufzählungen `BrandTone`, `BrandAddress`, `BrandTermKind`,
`BrandReferenceKind`, `AttachmentContext::BrandReference`, `App\Marke`
(`Markenprofil`, `Begriffspruefung`, `Begriffstreffer`, `Reifegrad`,
`Referenzablage`), `routes/marke.php` und die Seite *Marke* mit eigenem
Menüpunkt hinter `brandguide.manage`.

Im Browser durchgespielt: Ton und Ansprache gewählt, Zielgruppe,
Positionierung und Claim eingetragen — der Reifegrad ging von 0 % auf 80 %
und benannte als Rest „Referenzmaterial". Ein vermiedener Begriff
(„schmerzfrei → gut verträglich, § 3 HWG") ließ sich über den Dialog anlegen
und erscheint in der Liste. **Das Hochladen selbst ist nur durch Tests
belegt** — die Browser-Steuerung dieser Sitzung kann keine Datei anhängen.

**Nachgearbeitet:** Die erste Fassung hatte den Speichern-Knopf mitten auf
der Seite — zwischen Tonalität und Wortwahl, und damit sah er aus, als
gehörte er zu allem darunter. Jetzt drei abgesetzte Blöcke und eine Leiste,
die erst erscheint, wenn etwas geändert wurde: *Nicht gespeicherte
Änderungen · Verwerfen · Speichern*, danach kurz *Gespeichert.*

## Was das Bauen zutage gefördert hat

**`every()` auf einer leeren Menge ist wahr.** Eine Referenz ohne Datei — ein
Hochladen, das nach dem Speichern abbricht — galt damit als *geprüft*. Genau
die Richtung, in die ein Fehler nicht zeigen darf: ein Häkchen „virengeprüft"
an etwas, das nie geprüft wurde. Eigener Testfall.

**Ein Häkchen ohne Wortlaut ist keine Erklärung.** Der Text steht deshalb in
`config/mrs.php` und wird **mit jeder Referenz kopiert**, nicht referenziert.
Eine spätere Änderung gilt ab dann, nicht rückwirkend — wer in zwei Jahren
fragt, was die Praxis zugesichert hat, bekommt den Satz von damals. Dasselbe
Vorgehen wie bei den Einwilligungen in WP-18.

**Wortgrenzen sind kein Detail.** Ein verbotenes „rein" traf ohne sie jedes
„Reinigung" und jedes „hereinkommen". Ein Hinweis, der ständig erscheint,
wird abgeschaltet — und dann greift auch der, der stimmt, nicht mehr.

**Leere Auswahlfelder kommen als leerer String.** `nullable` allein lässt ihn
durch, und die Enum-Regel scheitert daran. Der Fehler sieht aus wie „ungültige
Tonalität" und heißt „keine Tonalität".

**`sticky bottom` klebt nur, solange der umgebende Block sichtbar ist.** Die
Leiste stand zuerst als eigenes Element unter dem Inhalt — ihr umgebender
Block war damit so hoch wie sie selbst, und sie klebte erst, wenn man ohnehin
ganz unten war. Sie gehört **in** den hohen Inhaltsbereich.

**Die Grenze zum Praxiswissen ist eine echte.** Der Brand Guide beschreibt,
wie geworben wird; `App\Agent\Praxiswissen` beschreibt, was der Empfang weiß.
Zusammengelegt hätte eine Änderung am Werbeton die Antwort auf eine
Terminfrage verändert — und das Praxiswissen geht als **Anweisung** in den
Prompt, der Brand Guide als **Datenblock** (Regel 5): er ist von Hand
eingetragen, und Text wird kopiert.

## Offen

- **Die Bildprüfung.** WP-30. Bis dahin ist die Erklärung alles, was zwischen
  dem Produkt und einem Vorher-Nachher-Bild steht — und die Oberfläche sagt
  das auch so.
- **Eine Vorschau des Referenzmaterials.** Die Bilder liegen verschlüsselt
  und brauchen eine eigene Ausgaberoute; dieselbe, die dem Posteingang für
  Anhänge noch fehlt.
- **Mehrsprachigkeit.** Entscheidung P7: nur Deutsch. Ein zweisprachiger
  Brand Guide wäre zwei Brand Guides.
