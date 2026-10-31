# Testbericht – 0.3, Build 3

Stand: 31.10.2026. Die folgenden Prüfungen wurden tatsächlich ausgeführt. Bei den Entwicklerprüfungen bestand **keine Verbindung zu einer echten NAS oder einem Symcon-Dienst**. Vom Benutzer wurden im ersten Gerätetest korrekte CPU-, RAM-, Lüfter- und Festplattentemperaturwerte gemeldet. Die beiden Korrekturen in Build 2 sind am Gerät noch zu bestätigen.

## Ergebnisse

| Bereich | Ergebnis | Aussagegrenze |
|---|---|---|
| PHP-Syntax | Sämtliche PHP-Dateien ohne Syntaxfehler unter PHP CLI 8.3.6 | Kein Beweis für Symcon-Laufzeit oder Gerätekompatibilität |
| JSON-Syntax | Alle JSON-Dateien gültig | Inhalt zusätzlich wie unten geprüft |
| `module.json` | Gegen offizielles `moduleSchema.json` bestanden | Schema-Abruf vom 31.10.2026 |
| `form.json` | Gegen offizielles `formSchema.json` bestanden | Kein echter Instanzeditor-/Konsolentest |
| `library.json` | Alle Felder außer Versions-Enum schema-konform | Öffentliches Schema führt weiterhin nur Versionen bis 6.2; Mindestversion 8.1 deshalb formal vom alten Enum abgelehnt. Die Bibliothek gibt ihre tatsächliche Mindestversion an; das Schema wurde nicht verändert. |
| Daten-/Clienttests | 41 Prüfungen bestanden | Synthetische Fixtures und echter lokaler HTTP-Testserver |
| SymconStubs | 35 Prüfungen bestanden | Unveränderte offizielle Stubs, kein echter Kernel |
| Gerenderter Browser | 33 Prüfungen bestanden | Headless Chromium/Brave mit Playwright, kein Symcon-Webclient |
| Sichtkontrolle | Dunkel-, Hell- und Smartphone-Vorschau geöffnet und geprüft | Testdaten, keine Live-Messwerte |
| ZIP | CRC und Bytegleichheit mit allen enthaltenen Workspace-Dateien geprüft | Installationsabnahme am Zielsystem noch erforderlich |

## Nachgewiesene Fälle

Daten-/Clienttests prüfen CPU-Werte, Poolbelegung, unveränderte Laufwerk-Idents bei Sortierwechsel, sichere Temperaturzuordnung anhand des Gerätenamens, fehlende/ungültige Werte sowie das Weglassen von Seriennummern in den Visualisierungsdaten. Der lokale Server entschlüsselt das übertragene RSA-Passwort tatsächlich mit einem temporären privaten Schlüssel. Sitzungs-Cookies, Wiederverwendung, einmalige Erneuerung nach Ablauf, falsches Passwort, fehlende Zwei-Faktor-Freigabe, Nicht-JSON-Antworten und das Größenlimit werden geprüft. Nicht freigegebene API-Pfade und ungeeignete Basis-URLs werden abgelehnt.

Die SymconStub-Prüfungen umfassen Neuinstallation ohne Variablen, Grundkatalog, initiale Kacheldaten, deaktivierte Instanz ohne Gerätezugriff, serverseitig abgelehnte Aktionen, individuelle Variablenwahl, korrekten Datentyp, Erhalt eigener Namen, keine Löschung nach Abwahl, fehlende Konfiguration, vollständigen Poll, dynamische Erkennung ohne Variablenmehrverbrauch, Doppelklickbegrenzung, sichere Initialdaten, Deaktivierung/Reaktivierung, Formularpersistenz, vorhandene Zielvariablen, Typfehler, Anmeldefehler mit 15-Minuten-Sperre und Datenquellenwechsel.

Browserprüfungen verwenden die tatsächliche Datei `UgreenNAS/module.html`: 240, 320, 480, 768 und 1200 Pixel Breite, jeweils hell und dunkel. Geprüft sind horizontaler Überlauf, Aktualisieren direkt nach Öffnen, lange Namen, sicheres Einfügen von Text, geöffnete Diagnose während Live-Updates, alte/fehlende Daten, deaktivierte Instanz, ausgeblendete Bereiche, SDK-Aktion, Sperren/Freigeben des Buttons und zeitgesteuertes Altern ohne Netzwerkpolling im Browser. Es gab keine JavaScript-Laufzeitfehler.

## Reproduzieren

Voraussetzungen: PHP 8.2+ mit cURL, OpenSSL und mbstring; Python 3. Der Testserver bindet nur an `127.0.0.1` und erzeugt temporäre RSA-Schlüssel. Keine dauerhafte Systeminstallation erforderlich.

```bash
python3 tests/run_local.py
```

Optional unveränderte offizielle SymconStubs über `SYMCON_STUBS=/pfad/zu/SymconStubs` einbeziehen. In dieser Umgebung wurde dafür Commit `31f2e0478eb3763236fcf44ea4144271fdeddfd5` verwendet. Der Stub selbst wird nicht mitgeliefert und nicht um fehlende Funktionen ergänzt.

Die verwendete isolierte PHP-Laufzeit lag unter `/tmp/ugreen-php`; `PHP_BINARY` und `PHP_EXTENSION_DIR` in `tests/run_local.py` erlauben diese Variante, ohne PHP systemweit zu installieren. Bei regulärem PHP mit aktivierten Erweiterungen sind diese Umgebungsvariablen nicht erforderlich.

Vorschau und Browserprüfung mit PHP und Node.js/Playwright:

```bash
php tests/make_preview.php
node tests/browser.cjs
```

`BROWSER_BINARY` kann auf einen vorhandenen Chromium-/Brave-Browser zeigen. Die Browserprüfung erzeugt die drei PNG-Dateien unter `docs`. Die HTML-Vorschau wird aus derselben Modul-HTML und denselben ausdrücklich synthetischen Fixtures erstellt.

## Noch offen

- Installation, Timer, Formularbedienung und HTML-SDK-Nachrichtenaustausch im tatsächlichen IP-Symcon ab 8.1.
- Anmeldung am DXP8800 Plus mit UGOS Pro 1.20.0.0142 und tatsächlich benötigte NAS-Kontorechte.
- Alle gerätespezifischen Antwortformen, Firmwarebesonderheiten, Temperaturidentitäten und Statuscodes.
- Andere DXP-Modelle. Es gibt keine geprüfte Modellmatrix.
- Nicht-ASCII-NAS-Passwörter, alle Varianten des RSA-Headers und eventuelle zusätzliche Gerätefreigaben am echten Gerät.
- Fallback-Endpunkt und einzelne teilweise nicht verfügbare Speicherendpunkte am echten Gerät.
- Zwei-Faktor-Gerätefreigabe ist noch nicht implementiert.
- IPSView/TileHtml, Android-Wandtablet, Touch-/Vollbildverhalten und echte kleine Symcon-Rasterkacheln.

Diese Grenzen bleiben trotz bestandener lokaler Tests bestehen. Die nächste Abnahme ist ein Verbindungstest auf der genannten NAS mit der tatsächlich eingesetzten Symcon-Version.

## Regressionen in Build 2

Zusätzlich geprüft: zu 100 % an Volumes zugewiesener Pool bei nur 39,6 % tatsächlicher Volume-Belegung; gewichtete Summierung mehrerer unterschiedlich großer Volumes; leeres Volume; fehlende, ungültige und teilweise fehlende Volume-Kapazitäten. Es wird niemals auf Pool-Zuweisung zurückgefallen.

Die erste Erkennung wird nach 1000 ms eingeplant, anschließend gilt das reguläre Pollingintervall. Der offene Formularstand wird nach Erkennung sofort ergänzt, ohne vorhandene noch nicht übernommene Auswahl/Namen zu verlieren. Der flüchtige Formularstand und der persistente Messwertkatalog sind mit unveränderten SymconStubs geprüft. Die tatsächliche Darstellung der aktualisierten Liste im Symcon-Instanzeditor ist weiterhin am Zielsystem zu prüfen.

Vom Benutzer wurde außerdem eine funktionierende Anmeldung über HTTP zurückgemeldet. HTTP bleibt im Update unterstützt; bestehende BaseURL-Konfigurationen werden nicht geändert. HTTPS konnte beim Benutzer bisher nicht erfolgreich verwendet werden. Die Ursache ist ohne Fehlerdiagnose offen.

## Regressionen in Build 3

Nach Entfernung von HTTPS erneut ausgeführt: sämtliche PHP-Syntaxprüfungen, Daten-/HTTP-Clienttests (41 Prüfungen) und unveränderte offizielle SymconStubs (35 Prüfungen). Zusätzlich geprüft: Ablehnung von HTTPS vor Netzwerkzugriff; keine Zertifikats-/CA-Felder im Konfigurationsformular; HTTP-Vorgabe für neue Instanzen; Erhalt einer bestehenden HTTP-Adresse; vollständiger HTTP-Poll auch mit einer alten, ungültigen und nun ignorierten CA-Dateieigenschaft.

Die Modul-HTML ist seit dem bestandenen Browserlauf von Build 2 unverändert; dieser Browserlauf wurde für die reine HTTP-/Formularänderung nicht wiederholt. Es wurden keine zusätzlichen Home-Assistant-Funktionen eingebaut.
