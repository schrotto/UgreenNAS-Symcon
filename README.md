# UGREEN NAS Monitoring für IP-Symcon

Version **0.2, Build 2 – Testversion**, Stand 31.10.2026.

Lokales Monitoring für UGREEN-NAS-Geräte mit UGOS Pro über deren Webschnittstelle. Es sind keine SSH-Verbindung, kein Docker-Container und kein Agent auf der NAS erforderlich. Die Bibliothek ist für IP-Symcon **ab 8.1** vorbereitet und verwendet `IPSModuleStrict` und das native HTML-SDK. Die tatsächlich eingesetzte Symcon-Version wurde noch nicht mitgeteilt.

Referenzanforderung ist ein **DXP8800 Plus mit UGOS Pro 1.20.0.0142**. Weitere DXP-Modelle sollen über dieselben API-Endpunkte funktionieren. Aus dem ersten Gerätetest über HTTP wurden eine erfolgreiche Anmeldung und korrekte CPU-, RAM-, Lüfter- und Festplattentemperaturwerte zurückgemeldet. HTTPS konnte beim Benutzer bisher nicht erfolgreich verwendet werden; die Ursache ist offen. Die Korrekturen für Variablenliste und Speicherbelegung aus Build 2 müssen am Gerät noch bestätigt werden. Andere DXP-Modelle wurden noch nicht geprüft. Die UGOS-Schnittstelle ist nicht als stabile öffentliche Monitoring-API zugesichert. Firmwareänderungen können Anpassungen erfordern.

## Funktionen

- Verbindung, CPU- und RAM-Auslastung, CPU-Temperatur und Betriebszeit.
- Dynamische Erkennung von CPU-/Gehäuselüftern und deren Drehzahlen.
- Speicherpools mit tatsächlicher, nach Volume-Kapazität gewichteter Volume-Belegung, RAID-Bezeichnung und unverändertem UGOS-Status.
- Laufwerke mit Modell, Größe und unverändertem UGOS-Status; Temperaturen nur bei sicherer Zuordnung über den Gerätenamen.
- Lokale Warnhinweise bei hoher CPU-Auslastung, CPU-Temperatur oder Poolbelegung.
- Responsive Dashboard-Kachel in hellem, dunklem oder automatischem Farbschema, mit wählbarer Akzentfarbe und ein-/ausblendbaren Bereichen.
- Manuelle Aktualisierung aus der Kachel, Live-Aktualisierung, markierte alte Daten und Verbindungsdiagnose.

Die Darstellung von Statuscodes ist bewusst keine zertifizierte SMART-/RAID-Gesundheitsbewertung. Es werden keine SMART-Selbsttests gestartet und keine NAS-Einstellungen verändert. Netzwerkdurchsatz, Docker-Überwachung, detaillierte SMART-Attribute, Push-Nachrichten, Ein-/Ausschalten und Neustarten sind in dieser Version nicht enthalten.

## Installation

### Lokaler erster Test

1. ZIP in einen eigenen Ordner entpacken. `library.json` muss im Wurzelverzeichnis der Bibliothek liegen; `UgreenNAS` und `libs` müssen daneben liegen.
2. Diesen vollständigen Bibliotheksordner auf das Symcon-System in dessen `modules`-Verzeichnis kopieren, zum Beispiel unter Linux nach `/var/lib/symcon/modules/UgreenNASMonitoring`. Der tatsächliche Datenpfad kann bei Docker, Windows oder abweichenden Installationen anders sein. Bei Docker den persistenten Symcon-Datenordner verwenden.
3. Bei einer lokalen Installation den Symcon-Dienst nach dem Kopieren neu starten, damit die Bibliothek geladen wird. Ein ZIP ist kein direktes Importformat für Module Control.
4. Eine Instanz **UGREEN NAS (UGOS Pro)** anlegen.

### Module Control

Die Bibliothek besitzt die erforderliche Repository-Struktur. Für die Installation über Module Control muss der Inhalt in einem erreichbaren eigenen Git-Repository liegen, mit `library.json` an der Repository-Wurzel. Anschließend dessen Repository-URL in **Module Control → Hinzufügen** eintragen. Es wurde nichts veröffentlicht oder auf einen externen Server gepusht; eine öffentliche Installations-URL ist daher noch nicht vorhanden.

### NAS-Verbindung einrichten

1. NAS-Adresse einschließlich Protokoll und API-Port eingeben, üblicherweise `https://NAS-IP:9443`. Benutzerdefinierte Ports können abweichen. Kein `/ugreen` und keinen weiteren Pfad anhängen. Die übliche unverschlüsselte Variante ist `http://NAS-IP:9999`.
2. Einen **lokalen NAS-Benutzer** und dessen Passwort im Instanzformular eintragen. Ein UGREEN-Cloudkonto ist kein Ersatz für das lokale Konto. Das Konto benötigt Leserechte auf die abgefragten System- und Speicherinformationen. Welche Rechte auf der genannten Firmware ausreichen, muss am Gerät geprüft werden; nicht vorsorglich zusätzliche Rechte vergeben.
3. HTTPS-Zertifikat prüfen lassen. Bei eigener CA die PEM-CA-Datei auf dem Symcon-System hinterlegen und deren absoluten Pfad angeben. Das Zertifikat muss zur eingegebenen Adresse passen. Bei selbstsigniertem Zertifikat ist eine bewusste Ausnahme über „HTTPS-Zertifikat prüfen“ möglich. HTTP verschlüsselt die Sitzung nicht.
4. Überwachung aktivieren und **Übernehmen** anklicken. Die erste Erkennung startet nach einer Sekunde; bei Bedarf **Verbindung testen / jetzt aktualisieren** ausführen.
5. Erkannte Pools, Laufwerke und Lüfter erscheinen automatisch in der bereits geöffneten Variablenliste. Vor einer erfolgreichen Geräteverbindung sind nur die fünf allgemeinen Messwerte bekannt; es werden keine erfundenen Laufwerksplätze angezeigt. Gewünschte Variablen einzeln aktivieren und erneut übernehmen. Ohne Auswahl bleibt es bei **null zusätzlichen Variablen**.
6. Instanz als Kachel in die Symcon-Kachelvisualisierung aufnehmen. Keine HTML-Box anlegen. Nach einem Modulupdate die geöffnete Visualisierung neu laden, damit Änderungen am HTML/CSS/JavaScript wirksam werden.

Anmeldung mit RSA-Passwortverschlüsselung, Sitzungstoken und Cookies erfolgt automatisch. Die notwendige Freigabe eines neuen Clients bei **Zwei-Faktor-Anmeldung wird noch nicht unterstützt**. Ein solches Konto liefert eine Diagnose; die Zwei-Faktor-Prüfung wird nicht umgangen. Für produktive Nutzung mit diesem Konto muss der Freigabeablauf zunächst ergänzt werden.

## Änderungen in Build 2

- Geräteerkennung direkt nach dem Übernehmen, ohne das volle Aktualisierungsintervall abzuwarten.
- Offene Variablenliste wird nach Erkennung automatisch ergänzt; noch nicht übernommene Auswahl und Namen bleiben erhalten. Einmal erkannte Messwerte bleiben auch bei kurzzeitigen optionalen Datenlücken auswählbar.
- Poolbelegung verwendet `sum(volumes.used) / sum(volumes.total)`. Pool-`used` bezeichnet zugewiesenen Speicher und wird nicht mehr als Dateisystembelegung interpretiert. Fehlende oder unvollständige Volume-Werte werden als nicht verfügbar angezeigt.
- Vorhandene GUIDs, Variablen-Idents und Benutzereinstellungen bleiben erhalten.

Für ein lokales Update den Inhalt des bestehenden Bibliotheksordners durch Build 2 ersetzen und den Symcon-Dienst neu starten. Keine neue Instanz anlegen und alte Variablen nicht löschen. Anschließend einmal übernehmen und die Visualisierung neu laden. Alte entpackte Releases nicht als zusätzliche Unterordner an der Bibliothekswurzel ablegen. Die alte entpackte Arbeitskopie wurde unverändert unter `.releases` aufbewahrt.

## Variablen und Lizenzverbrauch

| Messwert | Typ | Einheit | Standard |
|---|---|---|---|
| Verbindung | Boolean | Online/Offline | aus |
| CPU-Auslastung | Float | % | aus |
| RAM-Auslastung | Float | % | aus |
| CPU-Temperatur | Float | °C | aus |
| Betriebszeit | Integer | s | aus |
| Je Lüfter: Drehzahl | Integer | rpm | aus |
| Je Pool: Volume-Belegung | Float | % | aus |
| Je Pool: UGOS-Status | String | Rohstatus | aus |
| Je Laufwerk: Temperatur | Float | °C | aus |
| Je Laufwerk: UGOS-Status | String | Rohstatus | aus |

Die Kachel erhält ihre Daten direkt aus dem Instanzattribut und funktioniert unabhängig von den optionalen Variablen. Sitzungstoken liegen nur in einem flüchtigen Buffer. Es gibt keine Hilfs-, Debug- oder HTML-Box-Variablen. Profile werden erst bei tatsächlich angelegten Variablen benötigt und zählen nicht als Statusvariablen.

Standard: **0**, empfohlene Auswahl: **4** Variablen. Maximale Auswahl bei den implementierten Erkennungsgrenzen: `5 + Lüfter + 2 × Pools + 2 × Laufwerke`. Je 16 CPU- und Gehäuselüfter sowie je 32 Pools und Laufwerke ergeben maximal **165** Variablen einer aktuellen Datenquelle. Erhaltene Altvariablen können diese Zahl erhöhen. Nicht erkannte Komponenten werden nicht als Platzhalter angelegt.

**Vorhandene Variable**: Ziel `0` legt eine eigene Variable an. Eine andere ausgewählte Variable wird mit dem Messwert beschrieben und spart eine zusätzliche Variable. Sie muss zum Datentyp passen und darf keine Variablenaktion besitzen. Die Auswahl ist damit ein ausdrücklicher Schreibauftrag für diese Symcon-Variable. Namen und Profile externer Variablen bleiben unangetastet.

Name und Position gelten für das erstmalige Anlegen eigener Variablen. Spätere Benutzereinstellungen im Objektbaum bleiben erhalten. Abwählen stoppt die Aktualisierung, löscht aber nichts. Nicht mehr benötigte Variablen können nach Prüfung ihrer Verknüpfungen ausdrücklich im Objektbaum gelöscht werden; es gibt keinen automatischen Löschmechanismus.

Fehlende Messwerte überschreiben bestehende Werte nicht mit `0`. Für Automationen bedeutet das: Der letzte Messwert kann alt sein. Bei Bedarf die optionale Verbindungsvariable mitverwenden und zusätzlich den Änderungs-/Aktualisierungszeitpunkt prüfen. Eine erfolgreiche Verbindung garantiert nicht, dass jeder optionale Sensor oder Speicherendpunkt verfügbar ist.

## Visualisierung

CPU, RAM und CPU-Temperatur bilden die Hauptanzeige. Darunter folgen Speicherpools mit Volume-Belegungsbalken, Laufwerke und Kühlung. Warnschwellen bleiben sichtbar. Diagnose ist aufklappbar und bleibt bei Updates geöffnet. Lange Namen werden umgebrochen. Kleine Kacheln verwenden eine vertikale Anordnung und vertikales Scrollen. Die Kachel steuert keinen Vollbildmodus der Host-App.

Konfigurierbar sind Anzeigename, Farbschema, Akzentfarbe, kompakte Abstände, Pools, Laufwerke, Lüfter und die Aktualisieren-Schaltfläche. Das Hauptdashboard bleibt sichtbar. Keine externen Schriften, Bilder, Bibliotheken oder Cloud-Dienste werden geladen.

Die Kachel zeigt Daten nach Ablauf von `max(45 Sekunden, 2 × Intervall)` als veraltet an. Beim ersten Laden erfolgt eine vollständige Initialisierung. Messwerte aus dem letzten erfolgreichen Poll bleiben bei Fehlern sichtbar, werden aber als veraltet markiert. Nach Konfigurationsänderungen und Reaktivierung ist eine neue Messung erforderlich, bevor Daten wieder als aktuell gelten.

**Designvorschau:** [HTML-Vorschau](docs/preview.html), [Dunkel](docs/preview-dark.png), [Hell](docs/preview-light.png), [Smartphone](docs/preview-mobile.png). Die Bilder zeigen die tatsächlich im Browser gerenderte Modul-HTML mit ausdrücklich **synthetischen Testdaten**, keine Live-NAS-Daten.

## Kommunikation und Fehlerbehandlung

Pro Poll werden Systeminformationen, Statistiken, Speicherpools und Laufwerke gelesen. `taskmgr/stat/get_all` hat für HTTP 404 bzw. UGOS-Code 9404 einen Rückfall auf `taskmgr/stat/overview`. Fehler optionaler Speicherendpunkte ergeben eine Teildiagnose; unbekannte Formate werden nicht in scheinbar gültige Messwerte umgewandelt.

Mindestintervall 15 Sekunden, Standard 60 Sekunden. Anfragen haben ein konfigurierbares Zeitlimit von 2–30 Sekunden; ein kompletter Durchlauf ist zusätzlich auf höchstens 55 Sekunden begrenzt. Zeitgleiche Polls werden per Symcon-Semaphore verhindert; manuelle Wiederholungen innerhalb von 5 Sekunden werden begrenzt. Ein abgelaufenes Token wird je Leseanfrage höchstens einmal erneuert.

Bei Fehlern greift eine Wiederholsperre von 30 bis 900 Sekunden. Abgelehnte Zugangsdaten führen sofort zu 15 Minuten Pause, um Kontosperren durch wiederholte Anmeldungen zu vermeiden. Der normale Timer berücksichtigt diese Sperre. Erneutes Übernehmen der Konfiguration setzt die Sperre und die Sitzung zurück. Nicht wiederholt auf „Übernehmen“ klicken, um eine gesperrte Anmeldung erneut anzustoßen.

Verbindungsdetails, Zugangsdaten, Sitzungstoken und vollständige Geräteantworten werden nicht protokolliert und nicht an die Kachel übertragen. HTTPS wird standardmäßig geprüft. Weiterleitungen und Umgebungs-Proxys werden nicht verwendet; Antworten sind auf 2 MiB begrenzt. Das Modul hat eine feste Liste erlaubter Lese-Endpunkte. NAS-Passwörter werden als Instanzeigenschaft gespeichert und sind damit Bestandteil entsprechend geschützter Symcon-Konfigurationen und Backups.

## Skriptfunktionen

```php
// Instanz-ID anpassen. true bei einer erfolgreichen Abfrage,
// false bei deaktivierter Instanz, Wiederholsperre oder Fehler.
$erfolgreich = UGNAS_Update(12345);
```

`UGNAS_SelectVariables` ist eine Hilfsfunktion für das Instanzformular. Sie ändert dessen aktuelle Auswahl; anschließend muss der Benutzer übernehmen. Die Kachel verwendet ausschließlich `requestAction('Refresh', true)`.

## Struktur

```text
library.json
UgreenNAS/
  module.json
  module.php
  form.json
  module.html
libs/
  UgosClient.php
  Telemetry.php
docs/
  TESTBERICHT.md
  QUELLEN.md
  preview.html
  preview-dark.png
  preview-light.png
  preview-mobile.png
tests/
  fixtures.php
  http-router.php
  run.php
  symcon.php
  run_local.py
  make_preview.php
  preview-payload.json
  browser.cjs
```

## Prüfung und verbleibende Abnahme

Details stehen im [Testbericht](docs/TESTBERICHT.md). Es wurden PHP-Syntax, Modul-/Formularstruktur, Datenverarbeitung, echte HTTP-Anfragen gegen einen isolierten Testserver, RSA-Anmeldung, Sitzungswechsel, Fehlerfälle, offizielle unveränderte SymconStubs und ein tatsächlicher Browserlauf geprüft.

**Offen:** Installation und Laufzeit im echten Symcon-Dienst, Login und Leserechte auf dem DXP8800 Plus mit UGOS Pro 1.20.0.0142, Antwortformat und Feldzuordnung aller Sensoren, DXP-Modellmatrix, NAS-TLS-Konfiguration, IPSView/TileHtml und Android-Wandtablet. Ein Browser- oder Stub-Test ersetzt diese Abnahme nicht. Die Version ist deshalb eine Testversion und noch keine als produktionsreif bestätigte Geräteintegration.
