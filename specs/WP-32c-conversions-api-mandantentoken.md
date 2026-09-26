# WP-32c · Conversions API mit dem Token der Praxis

> Nachtrag zu WP-32. **WP-32b** hat die Conversions API gebaut, aber mit einem
> Schlüssel für alle Praxen. Der erreicht nur Pixel, die unserem
> Business-Portfolio freigegeben sind — die Pixel gehören aber den Praxen.

## Ziel
Jede Praxis sendet mit dem Token ihrer eigenen Meta-Verbindung an ihren
eigenen Pixel — oder gar nicht, und dann sagt das Produkt, warum.

## Vorher lesen
- `docs/entscheidungen.md` — **B16** (dieses Paket), **B1** Werbekonto gehört
  dem Kunden, **B3** Tokenüberwachung, **C9**
- `CLAUDE.md`, **Regel 1**, **Regel 2** und **Regel 4**
- **`docs/integrationen/meta.md`, Abschnitt Berechtigungen vollständig** —
  alle drei Unterabschnitte. Dort steht, warum ein Systembenutzer-Token im
  Standardzugriff nichts bekommt und warum `me` bei ihm kein Objekt ist.
  Dazu *Werbekonten* und *Das Pixel auf der Buchungsseite*.
- `specs/WP-26-werbekonto-anbindung.md`, `specs/WP-32b-roi-dashboard.md`
- `docs/fachlogik/attribution.md`, Abschnitt *Conversions API*

## Voraussetzungen
WP-26, WP-32b.

**Nicht vorausgesetzt: WP-00.** Wie WP-26 lässt sich das Paket gegen das
eigene Werbekonto und den eigenen Pixel im Entwicklungsmodus bauen. Für den
Betrieb zusätzlich: App Review mit Full Access und der Asset-Typ Datensatz in
der Login-Konfiguration (Schritt 1).

## Die Linie, an der alles hängt

**Ein Token je Praxis, und nur der aus der Verbindung.**

Kein Plattformschlüssel, kein Eingabefeld (B16). Der Token, den WP-26 beim
Verbinden des Werbekontos erhält, liegt verschlüsselt am `AdAccount` und wird
überwacht. Derselbe Token sendet an den Pixel — wenn die Praxis ihn im
Anmeldedialog freigegeben hat.

**Nicht senden ist der Normalfall, keine Störung.** Eine Praxis ohne
Werbekonto oder ohne freigegebenen Pixel meldet nichts an Meta. Das Produkt
verschweigt es aber nicht: *Einstellungen → Tracking* nennt den Grund.

## Was Meta dazu sagt — und was nicht

Stand 26.09.2026, aus Metas Entwicklerdokumentation. **Vor dem Bau erneut
lesen**; Meta zieht die Seiten gerade von `/docs/` nach `/documentation/` um.

**Belegt:**

- Für Facebook Login for Business gibt es eine eigene Konfigurationsvorlage
  *Conversions API partner integration*. Die Assets heißen dort Datensätze;
  gelesen werden sie über `/{client_business_id}/owned_pixels` und
  `/{client_business_id}/client_pixels`, die Kennung des Kunden-Portfolios
  über `me?fields=client_business_id`.
  <https://developers.facebook.com/documentation/facebook-login/facebook-login-for-business/conversions-api-integration-template/>
- Der Systembenutzer-Token aus Facebook Login for Business läuft
  standardmäßig nicht ab und erreicht nur die Assets, die der Kunde im Dialog
  freigegeben hat.
  <https://developers.facebook.com/documentation/facebook-login/facebook-login-for-business>
- Als Plattform verlangt die Conversions API App Review mit erweitertem
  Zugriff — heute *Full Access*, vorher *Advanced Access*. Voraussetzung
  dafür sind mindestens 500 Marketing-API-Aufrufe in 15 Tagen bei unter 15 %
  Fehlern.
  <https://developers.facebook.com/documentation/ads-commerce/conversions-api/set-up-conversions-api-as-a-platform>,
  <https://developers.facebook.com/documentation/ads-commerce/marketing-api/get-started/authorization>
- Meta lässt auch zu, dass der Kunde im Events Manager einen Token erzeugt und
  weitergibt. Das ist der Weg, den B16 bewusst nicht geht.

**Nicht belegt — mit einem echten Token zu klären:**

- **Welche Berechtigungen `POST /{pixel_id}/events` genau braucht.** Die
  Seiten widersprechen sich: einmal `ads_management`, `pages_read_engagement`
  und `ads_read`, einmal `ads_management` *oder* `business_management` dazu.
  Die Vorlage nennt als Vorgabe `ads_read` und `business_management` — genau
  das, was WP-26 heute anfragt.
- **Ob `debug_token` die Pixel in `granular_scopes.target_ids` nennt.**
  Dokumentiert ist das nur für WhatsApp-Konten.
- **Wie der Asset-Typ in der Login-Konfiguration beschriftet ist** — Pixel
  oder Datensatz.

## Schritte

1. **Login-Konfiguration bei Meta** (Handarbeit, gehört zu WP-00): Asset-Typ
   Datensatz ergänzen, Berechtigungen gegen die Vorlage abgleichen.
   `mrs.ads.scopes` wird erst erweitert, wenn ein echter Token zeigt, dass
   etwas fehlt.
2. `App\Werbung\Meta\Datensatzauswahl` — liest, welche Pixel der Token
   erreicht. Wege in dieser Reihenfolge, der nächste nur bei einer Abfuhr (wie
   `Kontenauswahl::verfuegbare()`):
   `me?fields=client_business_id` → `owned_pixels` und `client_pixels`;
   `debug_token` → `target_ids`; `act_…/adspixels`. Über
   `Graphleser::knoten()`. Die private `Kontenauswahl::tokenauskunft()` wird
   dafür herausgelöst, nicht kopiert. Rein lesend — `NurLesendTest` bleibt
   unverändert.
3. `ad_accounts.granted_pixel_ids` (json). Gefüllt beim Verbinden
   (`Kontenauswahl::verbinde()`) und im täglichen Abgleich
   (`mrs:werbung-abgleichen`).
4. **Ein Ort entscheidet, ob gesendet wird**, etwa
   `App\Attribution\Meta\Versandlage`. `Conversionsversand` und
   `TrackingController` fragen beide dort, damit Seite und Versand sich nicht
   widersprechen können. Gesendet wird nur, wenn alles zutrifft: Konto
   verbunden (`istVerbunden()`), `status->darfSenden()`, Pixel-ID gesetzt,
   Pixel-ID in `granted_pixel_ids`.
5. `Conversionsversand` — Token vom verbundenen `AdAccount`. Die Antwort wird
   ausgewertet wie in `Graphleser::hole()`: ein `error` im Körper ist auch bei
   HTTP 200 ein Fehler. `pruefeGegenKatalog()` bleibt im Produktionsweg.
6. `KonversionMelden` — Ausfälle nach Regel 4:
   - `token_invalid` und `suspended` → `$konto->meldeAusfall()`, wie in
     `WerbestrukturAbgleichen`. Es ist derselbe Token; ist er tot, ist er es
     auch für die Werbung.
   - Ein Rechtefehler am Pixel stuft das Werbekonto **nicht** herab. Er wird
     am Pixel vermerkt (`ad_accounts.capi_error`, `capi_failed_at`); ein
     erfolgreicher Versand löscht den Vermerk.
   - `Betriebslage::fuerMandant()` zählt diesen Vermerk als Störung — das
     Nicht-Senden nicht.
7. `settings/Tracking.vue` — der Zustand des Serverversands: sendet, kein
   Werbekonto verbunden, Pixel in der Verbindung nicht freigegeben, Zugang
   abgelaufen, Meta lehnt ab. Und der Kasten *Was übertragen wird* wird
   **wahrheitsgemäß**: heute steht dort, E-Mail und Telefonnummer würden nicht
   übertragen. Mit aktivem Serverversand gehen sie gehasht hinaus, dazu
   `fbc`, `fbp`, IP und User-Agent.
8. Aufräumen: `capi_token` samt Kommentar aus `config/mrs.php`,
   `META_CAPI_TOKEN` aus `.env.example` und `docs/betrieb.md`.
   `docs/integrationen/meta.md`: *Werbekonten* (der Token trägt auch den
   Pixel), Asset-Typen der Login-Konfiguration, Serverereignisse im
   Pixelabschnitt.

## Abnahmekriterien

**Token**

1. Das Ereignis geht an `/{pixel}/events` der eigenen Praxis, mit dem Token
   ihres verbundenen Werbekontos im Authorization-Header — beides im Test
   geprüft, nicht nur die Anzahl der Aufrufe.
2. Zwei Praxen mit je eigenem Konto und Pixel: jedes Ereignis trägt Token und
   Pixel seiner Praxis, nie die der anderen.
3. Es gibt keinen Plattformschlüssel mehr: `config('mrs.meta')` enthält
   keinen `capi_token`.

**Nicht senden — und sagen, warum**

4. Ohne verbundenes Werbekonto geht nichts hinaus.
5. Ein getrenntes Konto (`disconnected_at`) sendet nicht.
6. Ein Konto im Zustand `expired` oder `suspended` sendet nicht.
7. Ein Pixel, der nicht in `granted_pixel_ids` steht, erhält nichts.
8. Ohne Pixel-ID geht nichts hinaus.
9. In jedem der Fälle 4 bis 8 nennt *Einstellungen → Tracking* den Grund.

**Freigegebene Pixel**

10. Beim Verbinden werden die erreichbaren Pixel gelesen und gespeichert; der
    tägliche Abgleich aktualisiert sie.
11. Das Lesen schreibt bei Meta nichts — `NurLesendTest` unverändert grün.

**Ausfälle**

12. Code 190 oder HTTP 401 beim Versand → Konto `expired`, sichtbar auf der
    Werbeseite und in der Betriebslage.
13. Ein Rechtefehler am Pixel → Konto bleibt `active`, der Vermerk steht auf
    der Tracking-Seite und in der Betriebslage.
14. Ein erfolgreicher Versand löscht den Vermerk.
15. 5xx, abgebrochene Verbindung und Rate Limit werden wiederholt und
    markieren nichts.
16. Ein `error` im Antwortkörper bei HTTP 200 zählt als Fehler.

**Transparenz**

17. Die Tracking-Seite beschreibt, was der Server an Meta schickt, und
    widerspricht dem Payload aus `Konversionsereignis` nicht.

**Regeln**

18. Kein ausgehender Payload enthält einen Katalognamen — Testfall 7 läuft
    gegen den neuen Weg.
19. Ohne Einwilligung geht kein Ereignis hinaus.

## Nicht in diesem Paket

- **Ein Eingabefeld für einen Token aus dem Events Manager.** B16, bewusst.
- **Facebook Business Extension.** Nur für freigegebene Partner.
- **`Schedule` und `Contact`.** Bewusst nicht angeschlossen (WP-32b, Nachtrag
  26.09.2026).
- **Die Pixel-ID aus der Verbindung vorschlagen.** Naheliegend, aber die
  Buchungsseite braucht den Pixel auch ohne verbundenes Werbekonto.
- **Der App Review selbst** — WP-00.
- **Automatische Tokenerneuerung.** Tokens aus Facebook Login for Business
  laufen standardmäßig nicht ab; B3 bleibt davon unberührt.

## Fallstricke

- **Die Login-Konfiguration sagt, was angefragt wird, nicht, was erteilt
  wird.** Ein Token ohne Pixelfreigabe ist trotzdem gültig. Deshalb wird die
  Freigabe gelesen, nicht angenommen.
- **Ein Rechtefehler am Pixel ist kein Fehler am Werbekonto.** Über
  `meldeAusfall()` gemeldet, stünde er auf der Werbeseite — dort, wo ihn
  niemand beheben kann.
- **`me` ist beim Systembenutzer-Token kein Objekt** (Code 100, siehe
  `meta.md`). Die Vorlage fragt trotzdem `me?fields=client_business_id` —
  prüfen, nicht voraussetzen.
- **Nicht senden ist keine Störung.** Eine Praxis ohne Werbekonto erscheint
  nicht in der Betriebslage.
- **Der Token erscheint in keinem Log**, auch nicht in der Protokollierung von
  `tokenauskunft()`.
