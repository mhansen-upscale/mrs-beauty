# WP-49 · Recruiting: Stellenanzeigen und Bewerbungen

> Praxen brauchen zwei Dinge: neue Patientinnen und, „wirklich regelmäßig",
> neues Personal für Empfang und Praxishilfe. Beides kaufen sie heute bei
> Agenturen. Gewünscht ist ein zweiter Pfad im Assistenten: „Was suchst du,
> neue Kunden oder neue Mitarbeitende?" Dahinter steht statt des
> Buchungslinks ein Bewerbungsformular wie bei JOIN, mit Lebenslauf und
> Anschreiben, und die Bewerbungen landen unter einem eigenen Menüpunkt.
> Entscheidung **P16**.

## Ziel
Eine Praxis schaltet mit dem Kampagnen-Assistenten eine Stellenanzeige für
einen Standort. Bewerberinnen bewerben sich auf einer öffentlichen Seite der
Praxis ohne Konto. Die Bewerbungen liegen unter *Praxis → Bewerbungen*, mit
Stand, Unterlagen und Antwort, getrennt von allem, was Patientinnen betrifft.

## Vorher lesen
- `docs/feedback/2026-10-05-feedbackschleife-1.md`: Aussagen 21 und 26
- `specs/WP-42-kampagnen-assistent.md`; der Pfad „Neue Mitarbeitende" hängt sich dort ein
- `specs/WP-12-oeffentliche-buchungsseite.md` als Muster für eine öffentliche Seite der Praxis
- Entscheidungen **P16, C8, C19, B22, A5**; CLAUDE.md **Regeln 1, 3 und 4**
- `app/Datenschutz/Anhangspeicher.php`, die Virenprüfung aus WP-33

## Voraussetzungen
WP-42 (der Assistent), WP-41 (die Strecke). WP-50 ist hilfreich, weil
Teamfotos für Stellenanzeigen besser wirken, aber keine Voraussetzung.

## Die Linie, an der alles hängt

**Bewerbungen sind keine Kontakte.** Eine Bewerberin ist keine Patientin,
und eine Patientin, die sich bewirbt, wird nicht zur Bewerberin ihres
Kontakts. Bewerbungen bekommen eigene Tabellen, eine eigene Fähigkeit und
eine eigene Frist. Sie erscheinen nie in Posteingang, Anfragen, Warteliste,
Auswertung oder Conversions API, und der Assistent sieht sie nie.

**Stellenanzeigen sind ausrichtungsfrei.** Kein Alter, kein Geschlecht und
kein kleinerer Umkreis als erlaubt. Das AGG verlangt diskriminierungsfreie
Stellenausschreibungen, und Meta führt Stellenanzeigen in der Sonderkategorie
Beschäftigung mit genau diesen Einschränkungen. Der Assistent bietet die
Felder gar nicht erst an.

**Die Prüfhilfe gilt auch hier.** Wie die HWG-Ampel für Werbung prüft eine
kleine AGG-Liste den Stellentext, als Hinweis und nicht als Sperre (C18 sinngemäß):
- fehlendes „(m/w/d)"
- „junges Team"
- „Muttersprache"
- „belastbar und jung"

## Schritte
1. Abnahmekriterien als Tests.
2. **Schema:**

   | Tabelle | Inhalt |
   |---|---|
   | `job_postings` | `organization_id`, `location_id`, Titel, Umfang (Vollzeit, Teilzeit, Minijob, Ausbildung), Text, Status (`draft`, `open`, `closed`), `slug` |
   | `job_applications` | `organization_id`, `job_posting_id`, Vor- und Nachname, E-Mail, Telefon, Nachricht (verschlüsselt), Stand (`new`, `reviewing`, `interview`, `offer`, `rejected`, `withdrawn`), `talent_pool_consent_at`, `rejected_at`, `attribution` (Kampagnen-IDs aus dem Link, keine Namen) |
   | Anhänge | `Anhangspeicher`, Typ `application`, Virenprüfung, nur `clean` wird ausgeliefert |
   | `job_application_notes` | Notizen des Teams zur Bewerbung (verschlüsselt) |

3. **Stellen** unter *Praxis → Bewerbungen → Stellen*:
   - anlegen, öffnen, schließen
   - der Text kann als Vorschlag aus der Marke (WP-29) entstehen, mit AGG-Hinweisen
4. **Öffentliche Bewerbungsseite `/bewerben/{praxis}/{stelle}`:**
   - im Erscheinungsbild der Praxis (WP-07), mit Impressum und Datenschutz
   - Felder: Name, E-Mail, Telefon, Nachricht, Lebenslauf (Pflicht, PDF), weitere Unterlagen (optional, bis 5 Dateien, je 10 MB)
   - Hinweis nach Art. 13 DSGVO; „Talentpool" als eigene, freiwillige Einwilligung
   - Schutz gegen Missbrauch: Honigtopf-Feld, Drosselung je IP und Stelle, kein CAPTCHA
   - Pixel nur nach Einwilligung wie auf der Buchungsseite, mit `PageView`, ohne eigenes Ereignis
5. **Eingangsbestätigung** an die Bewerberin über das Postfach der Praxis (B22), als Auftrag (B21); ohne Postfach mit Hinweis an der Bewerbung.
6. **Bewerbungen** unter *Praxis → Bewerbungen*:
   - Liste je Stelle und Standort, Neue oben, Stand ändern
   - Unterlagen ansehen (jedes Öffnen wird protokolliert), Notizen
   - Antwort per Mail aus Vorlagen (Einladung, Absage) über das Postfach der Praxis
7. **Assistent, Pfad „Neue Mitarbeitende"** (WP-42, Schritt 1 freischalten):
   - Stelle wählen oder anlegen → Bild (KI oder ab WP-50 Teamfoto) → Standort, Monatsbetrag, Laufzeit → Prüfen und starten
   - Ziel `OUTCOME_TRAFFIC` auf die Bewerbungsseite; `special_ad_categories: ["EMPLOYMENT"]`
   - Umkreis mindestens `mrs.ads.employment.min_radius_km`, gemessen; keine Alters- und Geschlechtsfelder
   - `url_tags` wie in WP-41
   - Namen nach `Kampagnenname` mit Merkmal (Regel 2 gilt hier sinngemäß: kein Katalogname)
8. **Aufbewahrung:**
   - Datenart `job_application` in `retention` und `RetentionPolicy`: 6 Monate nach Absage oder Schließung der Stelle (Frist aus § 15 Abs. 4 AGG und § 61b ArbGG, mit Puffer)
   - im Talentpool 24 Monate ab Einwilligung, widerrufbar
   - Zusage: Übergabe in die Personalakte außerhalb des Produkts, dann löschen
   - durchgesetzt von Hand (C19)
9. **Fähigkeit** `applications.manage`: Inhaberin und Verwaltung. Marketing sieht im Assistenten die Zahl der Bewerbungen je Kampagne, nie eine Bewerbung.
10. **Auswertung:** Unter *Laufende Werbung* zeigt eine Stellenkampagne „Bewerbungen" statt „Anfragen". Dahinter steht eine Zählung aus `job_applications.attribution`, ohne Personendaten.

## Abnahmekriterien
1. Eine Bewerbung einer fremden Praxis ist nicht erreichbar (Regel 1); eine Bewerbungsseite einer geschlossenen Stelle zeigt „Diese Stelle ist besetzt" mit Status 410.
2. Eine Bewerbung ohne Lebenslauf wird abgewiesen; eine Datei mit Virenfund wird nie ausgeliefert, und die Bewerbung zeigt das.
3. Name, E-Mail, Telefon, Nachricht und Notizen liegen nur verschlüsselt in der Datenbank.
4. Ein ausgefülltes Honigtopf-Feld legt keine Bewerbung an und antwortet trotzdem mit der Erfolgsseite. Die sechste Bewerbung derselben IP auf dieselbe Stelle binnen einer Stunde wird gedrosselt.
5. Die Eingangsbestätigung geht als Auftrag über das Postfach der Praxis; ohne Postfach steht an der Bewerbung „keine Bestätigung verschickt".
6. Der Architekturtest scheitert, sobald eine Klasse unter `app/Agent`, `app/Kanaele`, `app/Leads` oder `app/Attribution` ein Bewerbungsmodell verwendet; die Zählung aus Schritt 10 liegt deshalb unter `app/Werbung`.
7. Eine Bewerberin mit derselben E-Mail wie ein Kontakt wird nicht zusammengeführt und erscheint nicht auf der Kontaktseite (D6 gilt nicht).
8. Die Stellenkampagne trägt `special_ad_categories: ["EMPLOYMENT"]` und weder `age_min`/`age_max` noch `genders` im Targeting; ein Umkreis unter dem Mindestwert wird abgewiesen.
9. Die Stellenkampagne verlinkt auf `/bewerben/{praxis}/{stelle}` mit `url_tags`; eine Bewerbung über diesen Link trägt die Kampagnen-IDs.
10. Ein Stellentext ohne „(m/w/d)" oder mit „junges Team" zeigt einen AGG-Hinweis mit Vorschlag und lässt sich trotzdem speichern.
11. `applications.manage` fehlt Empfang, Behandlerin und Marketing: 403 auf jede Bewerbungsroute, kein Menüpunkt.
12. Jedes Öffnen einer Bewerbungsunterlage steht im Protokoll ohne Inhalt (C5).
13. `mrs:aufbewahrung` zeigt eine Bewerbung sechs Monate nach `rejected_at` als fällig, mit Talentpool-Einwilligung erst nach 24 Monaten; gelöscht wird nur von Hand (C19).
14. Ein Widerruf der Talentpool-Einwilligung setzt die Frist auf die normale zurück.
15. Die Kachel „Neue Mitarbeitende" im Assistenten (WP-42) ist freigeschaltet und führt in diesen Pfad.

## Nicht in diesem Paket
- Ein vollständiges Bewerbermanagement: Interviewplanung mit Kalender, Bewertungsbögen, Mehrstufen-Freigaben.
- Veröffentlichung auf Jobbörsen (Indeed, StepStone, Arbeitsagentur).
- Meta-Lead-Formulare für Bewerbungen; die Daten blieben bei Meta.
- Personalakte und Onboarding nach der Zusage.

## Offen — vor dem Bau zu entscheiden
- **Mindestumkreis und Pflicht der Sonderkategorie in Deutschland** bei Meta messen (wie WP-41, Schritt 1). Die Einschränkungen gelten im Assistenten unabhängig davon, aus dem AGG.
- **Wo im Menü:** *Praxis → Bewerbungen* (Vorschlag aus dem Video) oder unter *Organisation*, neben *Team*.
- **Rechtsgrundlage und Hinweistext** der Bewerbungsseite mit dem Datenschutz (WP-01) abstimmen; § 26 BDSG ist seit der Entscheidung des EuGH (C-34/21) als Grundlage unsicher.

## Fallstricke
- **Eine Bewerbung ist nicht weniger schutzwürdig als eine Anfrage.** Lebensläufe tragen Geburtsdaten, Fotos und manchmal Angaben zur Gesundheit. Es gelten Verschlüsselung, Virenprüfung, Protokoll und Frist wie bei Anhängen aus WP-18.
- **Keine Bewerberin im Werbekonto.** Kein Custom Audience, kein Ereignis mit Personendaten an Meta, keine Rückmeldung „Bewerbung eingegangen" an die Conversions API (C8 sinngemäß).
- **Die Absage-Vorlage wird nicht automatisch verschickt.** Eine falsche Absage ist nicht rückholbar, wie ein zu weit laufender Löschlauf (C19).
