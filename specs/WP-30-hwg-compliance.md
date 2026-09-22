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
