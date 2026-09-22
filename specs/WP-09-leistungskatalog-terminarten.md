# WP-09 · Leistungskatalog & Terminarten

## Ziel
Der Katalog ist die einzige Quelle für Behandlungsnamen, Preise und
Terminlängen — und wird von vier späteren Paketen als genau das benutzt.

## Vorher lesen
- **`docs/fachlogik/verfuegbarkeit.md`** — V7 Behandlerfreigabe, V8 Standortangebot, V9 Vorlaufzeit, V11 volle Dauer inklusive Rüstzeit
- `docs/entscheidungen.md` — **D2** Behandlungswunsch als `treatment_id`, nie als Freitext; **D14** `avg_revenue_cents` beim Onboarding erheben; **G5** keine Preisaussagen außerhalb des Katalogs
- `docs/fachlogik/attribution.md` — Abschnitt Conversions API, „Verboten, ausnahmslos"
- `CLAUDE.md`, Regel 2

## Voraussetzungen
WP-08

## Wer später davon abhängt

Dieses Paket wirkt klein und ist es nicht. Vier Pakete benutzen den Katalog
als **Autorität**, nicht als Nachschlagewerk:

| Paket | Was es vom Katalog verlangt |
|---|---|
| WP-22, WP-23 | Der Agent löst `treatment_id` **nur** gegen den Katalog auf und prüft jeden erzeugten Preis und Behandlungsnamen dagegen. Was nicht im Katalog steht, darf er nicht sagen. |
| WP-25 | Die Warteliste passt über `appointment_type_id` (K2) und rechnet den gefüllten Wert über `treatments.avg_revenue_cents`. |
| WP-30 | Die HWG-Prüfung liest die Behandlungsbeschreibung. |
| WP-32 | Der zugeordnete Umsatz ist die Summe der `avg_revenue_cents`. **Und: kein Katalogname darf je an Meta gehen** — der Test dort lädt alle aktiven Namen und prüft jeden ausgehenden Payload dagegen. |

Der letzte Punkt dreht die übliche Richtung um: Der Katalog ist nicht nur das,
was gezeigt werden darf, sondern auch die Liste dessen, was **nie** nach außen
gehen darf. Beides aus derselben Tabelle.

## Zwei Begriffe, die nicht dasselbe sind

| | |
|---|---|
| **Behandlung** (`treatments`) | Was die Praxis anbietet: Botox, Hyaluron, Bruststraffung. Kaufmännisch und werblich. Trägt Preis und Umsatzschätzung. |
| **Terminart** (`appointment_types`) | Was gebucht wird: „Erstberatung Botox", 30 Minuten. Trägt Dauer, Rüstzeit und Vorlauf. |

Eine Terminart gehört zu höchstens einer Behandlung. `treatment_id` ist
**nullable**, weil es Termine ohne Behandlungsbezug gibt — Nachkontrolle,
allgemeine Beratung. Ohne diesen Bezug fehlt dem Termin allerdings der
Umsatzwert, und er taucht in der Auswertung von WP-32 mit null Euro auf. Das
gehört in die Oberfläche als Hinweis, nicht in eine Fußnote.

## Zeit gehört der Terminart, nicht der Behandlung

Aus V9 und V11:

```
belegte Strecke  = Rüstzeit davor + Dauer + Rüstzeit danach
angezeigte Zeit  =                  Dauer
```

**Die Rüstzeit belegt, sie zeigt nicht.** Ein Kontakt, dem als Termin
„14:00–15:00" angezeigt wird, während im Kalender 13:45–15:15 belegt ist,
sieht die richtige Zeit. Wer beides vermischt, verschiebt jede Terminanzeige
um die Rüstzeit.

**Vorlaufzeit** (`lead_time_hours`) ist die Untergrenze aus V9: ein Termin in
zwei Stunden ist für eine Beratung denkbar und für eine Operation nicht.

## Schritte

1. `treatments`: Name, Kurzname, Beschreibung, Kategorie, Preisspanne,
   **`avg_revenue_cents`**, aktiv.
2. `appointment_types`: Name, optionale Behandlung, Dauer, Rüstzeit davor und
   danach, Vorlaufzeit, Farbe, öffentlich buchbar, aktiv.
3. `appointment_type_practitioner` — Freigabe je Behandler (V7).
4. `appointment_type_location` — Angebot je Standort (V8).
5. `AppointmentType::belegteDauer()` und `angezeigteDauer()` als getrennte
   Größen.
6. `AppointmentType::wirdAngebotenVon($behandler, $standort)` — fasst V7 und
   V8 zusammen, wie WP-08 es für V1 bis V3 tut.
7. `Treatment::aktiveNamen()` — die Liste, gegen die WP-22 und WP-32 prüfen.
8. Oberfläche für beides.

## Abnahmekriterien

**Katalog**

1. Eine Behandlung ohne `avg_revenue_cents` ist nicht speicherbar
   (Entscheidung D14).
2. `avg_revenue_cents` von 0 oder negativ ist nicht speicherbar.
3. Ein Name ist je Organisation eindeutig.
4. Zwei Organisationen dürfen denselben Namen führen.
5. `Treatment::aktiveNamen()` liefert genau die aktiven Namen der eigenen
   Organisation — nichts von fremden, nichts Inaktives.

**Terminart**

6. Eine Terminart ohne Dauer ist nicht speicherbar.
7. Eine Dauer von 0 oder negativ ist nicht speicherbar.
8. Rüstzeiten dürfen 0 sein.
9. Negative Rüstzeit oder negative Vorlaufzeit ist nicht speicherbar.
10. Die belegte Strecke ist Rüstzeit davor plus Dauer plus Rüstzeit danach.
11. Die angezeigte Dauer ist **nur** die Dauer.
12. Eine Terminart ohne Behandlung ist zulässig und hat den Umsatzwert 0.

**Freigaben (V7, V8)**

13. Ein Behandler ohne Freigabe bietet die Terminart nicht an.
14. Ein Standort ohne Angebot bietet die Terminart nicht an.
15. Beides zusammen ergibt erst die Freigabe.
16. Eine Freigabe über die Organisationsgrenze hinweg ist **auf
    Datenbankebene** ausgeschlossen.
17. Eine inaktive Terminart wird nirgends angeboten.

**Vorlaufzeit (V9)**

18. Ein Zeitpunkt innerhalb der Vorlaufzeit ist nicht buchbar.
19. Ein Zeitpunkt außerhalb ist buchbar.

## Stand

Alle 19 Abnahmekriterien sind als Tests umgesetzt und laufen:
`tests/Feature/Katalog/KatalogTest.php`.

Die beiden Abfragen, auf die WP-10 aufsetzt:

| | |
|---|---|
| `AppointmentType::wirdAngebotenVon($behandler, $standort)` | V7 und V8 |
| `AppointmentType::istBuchbarAm($zeitpunkt, $jetzt)` | V9 |
| `AppointmentType::belegteDauer()` / `angezeigteDauer()` | V11 |

Zusammen mit `Practitioner::arbeitetAm()` aus WP-08 hat WP-10 damit sieben der
elf Bedingungen als fertige Abfragen. Es fehlen V5, V6 und V10 — die entstehen
dort, weil sie auf den Slots selbst beruhen.

## Was das Bauen zutage gefördert hat

**`TenantSchema::reference()` sprengte MySQLs Namensgrenze.** Der automatisch
erzeugte Fremdschlüsselname
`appointment_type_practitioner_appointment_type_id_organization_id_foreign`
hat 72 Zeichen, erlaubt sind 64. Die Meldung lautet „Identifier name is too
long" und sagt nichts darüber, dass die Ursache eine Laravel-Konvention ist
und kein Fehler im Schema.

Behoben in der Hilfsklasse aus WP-03: der Name ist jetzt
`{tabelle}_{spalte}_fk`, bei Bedarf mit angehängtem Kurz-Hash. Damit trifft
das Problem kein späteres Paket mehr — und es hätte es getroffen, denn jede
weitere Pivot-Tabelle mit zusammengesetztem Fremdschlüssel läuft in dieselbe
Grenze.

## Nicht in diesem Paket

Die Verfügbarkeits-Engine (WP-10). Hier entstehen V7 bis V9 und V11 als
abfragbare Größen, nicht die Slot-Berechnung.

Die öffentliche Buchungsseite (WP-12). `is_public` entsteht hier, ausgewertet
wird es dort.

Die HWG-Prüfung der Beschreibung (WP-30). Das Feld entsteht hier.

Preisanzeige im Detail. Eine Preisspanne genügt; was davon öffentlich gezeigt
wird, entscheidet WP-12 zusammen mit WP-30.

## Fallstricke

- **Rüstzeit ist keine Terminzeit.** Wer beides in ein Feld legt, zeigt dem
  Kontakt eine falsche Uhrzeit oder belegt zu wenig. Zwei Größen, immer.
- **`avg_revenue_cents` ist eine Schätzung**, kein abgerechneter Umsatz. Das
  gehört so in die Oberfläche, sonst hält ein Arzt die ROAS-Zahl für eine
  Buchhaltung.
- **Der Katalog ist auch eine Sperrliste.** Jeder Name, der hier steht, darf
  nie an Meta gehen (Regel 2). Wer den Katalog um einen Namen erweitert,
  erweitert damit auch, wonach der Test in WP-32 sucht.
- **`treatment_id` bleibt nullable, aber nicht folgenlos.** Eine Terminart
  ohne Behandlung erscheint in der Auswertung mit null Euro. Das ist richtig
  und muss trotzdem sichtbar sein.
