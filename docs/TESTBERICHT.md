# Testbericht – 0.1, Build 1

Stand: 31.10.2026. Die folgenden Prüfungen wurden tatsächlich ausgeführt. Es bestand **keine Verbindung zu einer echten NAS oder einem Symcon-Dienst**.

## Ergebnisse

| Bereich | Ergebnis | Aussagegrenze |
|---|---|---|
| PHP-Syntax | Sämtliche PHP-Dateien ohne Syntaxfehler unter PHP CLI 8.3.6 | Kein Beweis für Symcon-Laufzeit oder Gerätekompatibilität |
| JSON-Syntax | Alle JSON-Dateien gültig | Inhalt zusätzlich wie unten geprüft |
| `module.json` | Gegen offizielles `moduleSchema.json` bestanden | Schema-Abruf vom 31.10.2026 |
| `form.json` | Gegen offizielles `formSchema.json` bestanden | Kein echter Instanzeditor-/Konsolentest |
| `library.json` | Alle Felder außer Versions-Enum schema-konform | Öffentliches Schema führt weiterhin nur Versionen bis 6.2; Mindestversion 8.1 deshalb formal vom alten Enum abgelehnt. Die Bibliothek gibt ihre tatsächliche Mindestversion an; das Schema wurde nicht verändert. |
| Daten-/Clienttests | 29 Prüfungen bestanden | Synthetische Fixtures und echter lokaler HTTP-Testserver |
| SymconStubs | 27 Prüfungen bestanden | Unveränderte offizielle Stubs, kein echter Kernel |
| Gerenderter Browser | 32 Prüfungen bestanden | Headless Chromium/Brave mit Playwright, kein Symcon-Webclient |
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
- Funktionierende NAS-HTTPS-Zertifikatskette und Zugriff mit eigener CA.
- Nicht-ASCII-NAS-Passwörter, alle Varianten des RSA-Headers und eventuelle zusätzliche Gerätefreigaben am echten Gerät.
- Fallback-Endpunkt und einzelne teilweise nicht verfügbare Speicherendpunkte am echten Gerät.
- Zwei-Faktor-Gerätefreigabe ist noch nicht implementiert.
- IPSView/TileHtml, Android-Wandtablet, Touch-/Vollbildverhalten und echte kleine Symcon-Rasterkacheln.

Diese Grenzen bleiben trotz bestandener lokaler Tests bestehen. Die nächste Abnahme ist ein Verbindungstest auf der genannten NAS mit der tatsächlich eingesetzten Symcon-Version.
