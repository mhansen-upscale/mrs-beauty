# WP-30 · HWG-Compliance-Engine

## Ziel
Das Differenzierungsmerkmal des Produkts. Verdient entsprechende Sorgfalt.

## Vorher lesen
- `docs/produkt.md`, Abschnitt Das Differenzierungsmerkmal
- `docs/datenmodell.md`, Abschnitt 9
- `docs/entscheidungen.md` — **C1** HWG-Regelwerk global und versioniert; **C2** Bildverbot auch für minimalinvasive Eingriffe; **C3** Override mit Begründung und Protokoll

## Voraussetzungen
WP-29, WP-01 (juristische Prüfung)

## Schritte

1. `compliance_rulesets`: **global und versioniert**, nicht mandantenbezogen, mit Gültigkeitsdatum und Changelog.
2. `compliance_checks` polymorph auf Anzeigenvorschlag, Creative, Behandlungsbeschreibung, Template und Buchungsseite.
3. Startregelsatz:

| Code | Prüfung | Fundstelle |
|---|---|---|
| `before_after` | Vorher-Nachher-Darstellung in Bild oder Video | § 11 Abs. 1 S. 3 Nr. 1 HWG, BGH I ZR 170/24 v. 31.07.2025 |
| `missing_risk_notice` | Pflichthinweis auf Risiken fehlt | § 11 Abs. 1 S. 3 Nr. 2 HWG |
| `healing_promise` | Erfolgsversprechen, Garantien | § 3 HWG |
| `fear_advertising` | Angst erzeugende Darstellung | § 11 Abs. 1 Nr. 7 HWG |
| `testimonial` | Werbung mit Dankschreiben, Empfehlungen | § 11 Abs. 1 Nr. 11 HWG |
| `risk_free_claims` | "schmerzfrei", "risikolos", "ohne Ausfallzeit" | § 3 HWG |
| `superlatives` | unbelegte Superlative, Spitzenstellung | UWG |
| `brand_violation` | Verstoß gegen `banned_terms` | Brand Guide |

4. **Bildprüfung** auf Vorher-Nachher-Kompositionen: geteilte Bilder, Pfeile, Beschriftungen "vorher"/"nachher", Bildpaare.
5. Ampeldarstellung vor der Veröffentlichung, mit Begründung, Fundstelle und Formulierungsvorschlag.
6. Override mit Pflichtbegründung und Protokollierung.
7. Rechtsstand, Regelwerksversion und Prüfdatum an jedem Ergebnis.
8. Bibliothek rechtssicherer Alternativformate: Arzt-Vorstellung, Ablauf-Erklärung, Räumlichkeiten, Preis- und Risikotransparenz.

## Abnahmekriterien
- Ein Testsatz echter Anzeigen der Branche wird korrekt klassifiziert, **geprüft durch einen Medizinrechtler**.
- Eine Botox-Anzeige mit Vorher-Nachher-Bild wird abgelehnt, nicht nur eine OP-Anzeige.
- Ein Override ohne Begründung ist nicht möglich.
- Ein Prüfergebnis bleibt nach einer Regelwerksänderung mit seiner ursprünglichen Version nachvollziehbar.
- Kein Vorschlag erreicht die Veröffentlichung ohne vorherige Prüfung.

## Fallstricke
- **Das Bildverbot gilt seit dem BGH-Urteil vom 31.07.2025 auch für minimalinvasive Eingriffe wie Botox und Hyaluron.** Die Regel darf nicht auf klassische Operationen beschränkt sein. Verstöße können mit bis zu 50.000 Euro geahndet werden, das gehört als Hinweis in die Oberfläche.
- **Das Produkt ist eine Prüfhilfe, keine Rechtsberatung.** Formulierung und Haltung müssen das durchgängig widerspiegeln, sonst entsteht eine Haftung, die niemand tragen will.
- Das Regelwerk muss gepflegt werden. Wer das übernimmt und in welchem Rhythmus, gehört geklärt, bevor der erste Kunde darauf vertraut.

## Stand

Die Abnahmekriterien laufen, **bis auf das erste**:
`tests/Feature/Compliance/HwgTest.php` (**22 Tests**). Gesamtstand 988 Tests,
3513 Zusicherungen. PHPStan Stufe 8 sauber, `vue-tsc` sauber, 38 Seiten.

Neu: `compliance_rulesets` (**die eine Tabelle ohne `organization_id`**),
`compliance_checks`, `Ampel`, `ComplianceCode`, `App\Compliance` mit acht
Regelklassen, `Pruefung`, `Pruefergebnis`, `Regelwerk`, die Seite
*HWG-Prüfung* und die Bibliothek der Alternativformate in `config/mrs.php`.

Im Browser durchgespielt: „Botox garantiert schmerzfrei — sehen Sie unsere
Vorher-Nachher-Bilder. Die beste Praxis der Stadt." ergibt sechs Befunde mit
Fundstelle und Formulierungsvorschlag, Ampel rot.

## Was offen bleibt — und im Produkt steht

**Das erste Abnahmekriterium ist nicht erfüllt.** „Ein Testsatz echter
Anzeigen der Branche wird korrekt klassifiziert, **geprüft durch einen
Medizinrechtler**" — die Prüfung steht aus, und ich kann sie nicht ersetzen.

Der Testsatz in `HwgTest.php` bildet ab, was die Regeln tun **sollen**, nicht
was jemand mit Zulassung bestätigt hat. Deshalb trägt jede Regelwerksfassung
`reviewed_by` und `reviewed_at`, beide bei Fassung 1 leer — und die Seite sagt
es in einem eigenen Warnblock: *„Dieses Regelwerk ist noch nicht juristisch
geprüft. Nehmen Sie die Ampel als Hinweis, nicht als Freigabe."*

Das ist die einzige ehrliche Form, in der diese Funktion vor WP-01 existieren
kann. Eine Ampel, der jemand vertraut, ohne dass sie geprüft ist, ist
gefährlicher als gar keine.

## Was das Bauen zutage gefördert hat

**Die Maschine entscheidet nicht über Bilder.** Geteilte Bilder, Pfeile,
Beschriftungen „vorher"/„nachher", Bildpaare — nichts davon ist aus einem
Dateinamen zu erkennen. Ein Bild bekommt deshalb **nie grün**, sondern gelb:
jemand muss hinsehen. Das ist Regel 6 in einer Zeile Code, und wer es für
streng hält, rechne 50.000 Euro gegen einen Klick.

**Gelb ist kein schwaches Grün.** Es heißt „jemand muss hinsehen", und das
ist nicht dasselbe wie hingesehen haben. Ein gelbes Ergebnis gibt deshalb
genauso wenig frei wie ein rotes — frei gibt nur Grün oder eine begründete
Übersteuerung (C3).

**Lärm schaltet eine Prüfung ab.** Die erste Fassung beanstandete jeden
Katalogeintrag, dessen *Name* eine Behandlung nennt — also alle. Ein Eintrag
ohne Beschreibung trägt aber keine Aussage. Jetzt werden nur Texte geprüft.

**Und die Seite behauptete etwas Falsches.** „Diese Texte stehen heute schon
öffentlich auf Ihrer Buchungsseite" — das stimmt nicht: die Buchungsseite
zeigt weder Preis noch Beschreibung, und ein Test aus WP-12 hält das fest.
Die Beschreibungen speisen das Praxiswissen des Assistenten und ab WP-31 die
Anzeigenvorschläge. Dort werden sie zu Aussagen, und dort greift die Prüfung.

**Ohne Regelwerk keine Prüfung** — dieselbe Richtung wie bei der Virenprüfung
in WP-33: was niemand geprüft hat, wird nicht weitergereicht. Die Prüfung
wirft, statt grün zu liefern.

## Offen

- **Die juristische Durchsicht** (WP-01). Bis dahin steht der Warnblock.
- **Die Bildprüfung selbst.** Sie erkennt Wörter, keine Kompositionen. Ein
  Modell dafür gehört zu WP-31, wo Bilder überhaupt erst entstehen. Was dort
  seit dem 20.09.2026 steht: ein erzeugtes Bild löst die Prüfung erneut aus,
  mit `hatBild` — und nimmt dem Entwurf damit sein Grün.
- ~~**Die Übersteuerung hat noch keine Oberfläche.**~~ Gebaut in **WP-31**:
  *Übersteuern und freigeben* im Detail eines Entwurfs, mit Pflichtbegründung
  und Protokoll (C3).
- **Templates und Buchungsseite** sind als Prüfgegenstände vorgesehen, aber
  noch nicht angeschlossen.
