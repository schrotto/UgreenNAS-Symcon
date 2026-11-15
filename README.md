# UGREEN NAS Monitoring für IP-Symcon

Version **0.11, Build 11 – Testversion**, Stand 15.11.2026.

Lokales Monitoring für UGREEN-NAS-Geräte mit UGOS Pro über deren Webschnittstelle. Es sind keine SSH-Verbindung, kein Docker-Container und kein Agent auf der NAS erforderlich. Die Bibliothek ist für IP-Symcon **ab 8.1** vorbereitet und verwendet `IPSModuleStrict` und das native HTML-SDK. Die tatsächlich eingesetzte Symcon-Version wurde noch nicht mitgeteilt.

Referenzanforderung ist ein **DXP8800 Plus mit UGOS Pro 1.20.0.0142**. Weitere DXP-Modelle sollen über dieselben API-Endpunkte funktionieren. Aus dem ersten Gerätetest über HTTP wurden eine erfolgreiche Anmeldung und korrekte CPU-, RAM-, Lüfter- und Festplattentemperaturwerte zurückgemeldet. Der Zugriff erfolgt über HTTP im lokalen Netzwerk. Die Korrekturen für Variablenliste und Speicherbelegung aus Build 2 müssen am Gerät noch bestätigt werden. Andere DXP-Modelle wurden noch nicht geprüft. Die UGOS-Schnittstelle ist nicht als stabile öffentliche Monitoring-API zugesichert. Firmwareänderungen können Anpassungen erfordern.

## Funktionen

- Verbindung, CPU- und RAM-Auslastung, CPU-Temperatur und Betriebszeit.
- LAN-Sende- und Empfangsrate, freier RAM, RAM-Cache, verfügbarer Speicher je Volume und Betriebsstunden je Laufwerk.
- Dynamische Erkennung von CPU-/Gehäuselüftern und deren Drehzahlen.
- Speicherpools mit tatsächlicher, nach Volume-Kapazität gewichteter Volume-Belegung, RAID-Bezeichnung und unverändertem UGOS-Status.
- Laufwerke mit Modell, Größe und unverändertem UGOS-Status; Temperaturen nur bei sicherer Zuordnung über den Gerätenamen.
- Lokale Warnhinweise bei hoher CPU-Auslastung, CPU-Temperatur oder Poolbelegung.
- Responsive Dashboard-Kachel in hellem, dunklem oder automatischem Farbschema, mit wählbarer Akzentfarbe und ein-/ausblendbaren Bereichen.
- Manuelle Aktualisierung aus der Kachel, Live-Aktualisierung, markierte alte Daten und Verbindungsdiagnose.

Die Darstellung von Statuscodes ist bewusst keine zertifizierte SMART-/RAID-Gesundheitsbewertung. Es werden keine SMART-Selbsttests gestartet und keine NAS-Einstellungen verändert. Docker-Überwachung, detaillierte SMART-Attribute, Push-Nachrichten, Ein-/Ausschalten und Neustarten sind in dieser Version nicht enthalten.

## Installation

### Lokaler erster Test

1. ZIP in einen eigenen Ordner entpacken. `library.json` muss im Wurzelverzeichnis der Bibliothek liegen; `UgreenNAS` und `libs` müssen daneben liegen.
2. Diesen vollständigen Bibliotheksordner auf das Symcon-System in dessen `modules`-Verzeichnis kopieren, zum Beispiel unter Linux nach `/var/lib/symcon/modules/UgreenNASMonitoring`. Der tatsächliche Datenpfad kann bei Docker, Windows oder abweichenden Installationen anders sein. Bei Docker den persistenten Symcon-Datenordner verwenden.
3. Bei einer lokalen Installation den Symcon-Dienst nach dem Kopieren neu starten, damit die Bibliothek geladen wird. Ein ZIP ist kein direktes Importformat für Module Control.
4. Eine Instanz **UGREEN NAS (UGOS Pro)** anlegen.

### Module Control

Für die Installation über **Module Control → Hinzufügen** die Repository-Adresse `https://github.com/schrotto/UgreenNAS-Symcon` eintragen. Bei bestehender GitHub-Installation die Bibliothek in Module Control aktualisieren. [GitHub-Repository](https://github.com/schrotto/UgreenNAS-Symcon)

### NAS-Verbindung einrichten

1. Unter **NAS-Adresse** die IP-Adresse oder den Hostnamen eintragen. Unter **HTTP-Port** den in UGOS eingestellten Port eintragen. Die Vorgabe ist `9999`. Adresse ohne Protokoll, Port und Pfad eingeben.
2. Einen **lokalen NAS-Benutzer** und dessen Passwort im Instanzformular eintragen. Ein UGREEN-Cloudkonto ist kein Ersatz für das lokale Konto. Das Konto benötigt Leserechte auf die abgefragten System- und Speicherinformationen. Welche Rechte auf der genannten Firmware ausreichen, muss am Gerät geprüft werden; nicht vorsorglich zusätzliche Rechte vergeben.
3. Überwachung aktivieren und **Übernehmen** anklicken. Mit **Verbindung testen** prüfst du die HTTP-Antwort, die Anmeldung mit einer neuen Sitzung und die abrufbaren Daten. Eine laufende Abfrage oder Wiederholsperre wird im Testergebnis angezeigt.
4. Erkannte Pools, Laufwerke und Lüfter erscheinen automatisch in der bereits geöffneten Variablenliste. Die neun allgemeinen Messgrößen stehen bereits vor der Geräteerkennung zur Auswahl. Nach dem ersten Datenabruf ergänzt das Modul die vom Gerät erkannten Pools, Volumes, Lüfter und Laufwerke. Gewünschte Variablen einzeln aktivieren und erneut übernehmen. Ohne Auswahl bleibt es bei **null zusätzlichen Variablen**.
5. Instanz als Kachel in die Symcon-Kachelvisualisierung aufnehmen. Keine HTML-Box anlegen. Nach einem Modulupdate die geöffnete Visualisierung neu laden, damit Änderungen am HTML/CSS/JavaScript wirksam werden.

Anmeldung mit RSA-Passwortverschlüsselung, Sitzungstoken und Cookies erfolgt automatisch. Die notwendige Freigabe eines neuen Clients bei **Zwei-Faktor-Anmeldung wird noch nicht unterstützt**. Ein solches Konto liefert eine Diagnose; die Zwei-Faktor-Prüfung wird nicht umgangen. Für produktive Nutzung mit diesem Konto muss der Freigabeablauf zunächst ergänzt werden.

## Änderungen in Build 11

Wenn freier RAM oder RAM-Cache in der Ressourcenstatistik fehlen, versucht das Modul eine separate lesende HTTP-Abfrage beim UGOS-Task-Manager. Es ergänzt vorhandene Byte-Werte und meldet in **Verbindung testen**, welche Messwerte noch fehlen. Die separate Abfrage ist auf dem DXP8800 Plus mit UGOS Pro 1.20.0.0142 noch zu bestätigen. [API-Referenz des NAS-Monitor-Projekts](https://gitlab.com/K-22/nas-monitor-interface/-/blob/main/docs/API.md)

## Änderungen in Build 10

Wenn die ausführliche Statistik ein Zeitlimit erreicht, öffnet das Modul für die Dauer des Abrufs eine UGOS-Live-Verbindung auf dem konfigurierten HTTP-Port und versucht die Statistik erneut. Ist der Abruf erfolgreich, wird die Live-Verbindung bei der nächsten Abfrage vor der Statistik geöffnet. [Live-Abonnement der Home-Assistant-Integration](https://github.com/Tom-Bom-badil/home-assistant_ugreen-nas/blob/main/custom_components/ugreen/api.py#L874-L946)

Die Geräteüberwachung des UGOS-Desktops dient als zweite Quelle für CPU- und RAM-Auslastung sowie LAN-Sende- und Empfangsraten. Die CPU-Temperatur kann zusätzlich aus den Geräteinformationen kommen. Freier RAM und Cache benötigen weiterhin die Detailstatistik. **Verbindung testen** zeigt die verwendeten Rückfälle und fehlende Werte an. [UGOS-Geräteantworten für mehrere Modelle](https://github.com/Tom-Bom-badil/home-assistant_ugreen-nas/discussions/43)

## Änderungen in Build 9

Der HTTP-Abruf liest die Geräteinformationen wieder vor der Ressourcenstatistik. Fehlen CPU-Auslastung, CPU-Temperatur oder RAM-Auslastung in der Statistik, ergänzt die Ressourcenübersicht diese Werte, soweit verfügbar. LAN-Gesamtraten werden direkt aus der Ressourcenstatistik gelesen. Bei fehlenden Raten ergänzt die separate LAN-Abfrage die Antwort. [Home-Assistant-Integration, LAN in der Ressourcenstatistik](https://github.com/Tom-Bom-badil/home-assistant_ugreen-nas/blob/main/custom_components/ugreen/api.py#L794-L800)

Bei Zeitlimits bleiben erfolgreiche Speicher- und Laufwerksabrufe nutzbar. **Verbindung testen** nennt fehlende System- und LAN-Messwerte. Die Sichtbarkeit der Messwerte stellst du in der Modulkonfiguration unter **Premium-Visualisierung → Messwerte in der Kachel** ein.

## Änderungen in Build 8

Die Sichtbarkeit einzelner Werte stellst du in der Modulkonfiguration unter **Premium-Visualisierung → Messwerte in der Kachel** ein. Mit **Übernehmen** wird die Auswahl gespeichert. Build 8 übernimmt die bisherigen Einstellungen aus der Kachel. Nach einem ersten erfolgreichen Abruf ergänzt das Modul neu erkannte Messwerte in der Liste.

Die Abfrage meldet langsame oder ausgefallene Datenbereiche als Teilabruf. Wenn die ausführliche Ressourcenstatistik ausfällt, versucht das Modul die einfachere UGOS-Ressourcenübersicht. Bei einem vorübergehenden Gesamtausfall versucht es die Abfrage automatisch nach 10 bis 60 Sekunden erneut. Der manuelle Verbindungstest kann nach mindestens fünf Sekunden seit dem vorherigen Start erneut prüfen. Für abgelehnte Anmeldungen gilt weiterhin die Schutzpause von 15 Minuten.

## Änderungen in Build 7

CPU-Auslastung, RAM-Auslastung und CPU-Temperatur wurden der individuellen Sichtbarkeitsauswahl hinzugefügt.

## Änderungen in Build 6

Die Sichtbarkeitsauswahl wurde für zusätzliche LAN-, RAM-, Volume- und Laufwerkswerte eingeführt. Die Auswahl wird für die Symcon-Instanz gespeichert. Die Messwerte erscheinen auch dann in der Kachel, wenn in der Variablenverwaltung keine Statusvariablen aktiviert wurden.

Modulvariablen verwenden native Symcon-Wertdarstellungen. Bei der Umstellung bestehender Variablen werden ihre IDs beibehalten. Benutzerdefinierte Legacy-Darstellungen an Modulvariablen werden durch die nativen Vorgaben ersetzt. Vorhandene native Benutzerdarstellungen haben Vorrang. Die automatische Skalierung verwendet kB, MB, GB und bei größeren Werten TB oder PB, bei LAN jeweils pro Sekunde. Skripte und Archive erhalten dieselben Rohwerte in Byte oder Byte pro Sekunde. [Symcon-Dokumentation zur Wertdarstellung](https://www.symcon.de/en/service/documentation/developer-area/sdk-tools/sdk-php/presentations/value-presentation/)

## Änderungen in Build 5

Die zusätzlichen Messwerte sind unter **Variablenverwaltung** einzeln auswählbar. LAN-Werte geben die Gesamtrate der NAS in Byte pro Sekunde an. RAM und Volume-Speicher werden in Byte gespeichert. Beim Volume wird der vom Dateisystem gemeldete verfügbare Speicher erfasst. Laufwerksbetriebsstunden stammen aus der regulären Laufwerksliste.

Falls der LAN-Endpunkt fehlt oder Leserechte fehlen, meldet der Verbindungstest einen Teilabruf. Vorübergehend fehlende Werte lassen den zuletzt gespeicherten Variablenwert bestehen. Die Felder für verfügbaren Volume-Speicher und Betriebsstunden erscheinen, sobald UGOS dafür einen gültigen Wert liefert.

## Änderungen in Build 4

Die gespeicherte HTTP-Adresse wird beim Modulupdate in NAS-Adresse und Port aufgeteilt. Bei einer bisherigen HTTP-Adresse ohne ausdrückliche Portangabe bleibt der tatsächlich verwendete Standardport 80 erhalten. Neue Instanzen erhalten Port 9999 als Vorgabe.

Der Verbindungstest prüft eine neue Anmeldung. Er meldet HTTP-Antwort, UGOS-Anmeldung, erkannte Messwerte und Teilabrufe. Er nutzt die gespeicherten Einstellungen und das reguläre Wiederholintervall nach Fehlern.

## Änderungen in Build 3

- Ausschließlich HTTP, Vorgabe `http://NAS-IP:9999`; vorhandene HTTP-Adressen und Zugangsdaten bleiben erhalten.
- HTTPS-, Zertifikats- und CA-Einstellungen aus dem Formular und der Gerätekommunikation entfernt. Historische Instanzeigenschaften bleiben ausschließlich zur Update-Kompatibilität intern registriert und werden nicht ausgewertet.
- Bestehende HTTPS-Adressen werden mit einem klaren Hinweis abgelehnt und nicht stillschweigend auf HTTP umgeschrieben.
- Die Korrekturen aus Build 2 sind weiterhin enthalten. Keine zusätzlichen Home-Assistant-Funktionen wie Zwei-Faktor-Gerätefreigabe oder neue Verbindungsfelder wurden ergänzt.

Zum lokalen Update den Inhalt des bestehenden Bibliotheksordners ersetzen, Symcon neu starten, einmal übernehmen und die Visualisierung neu laden. Die vorhandene Instanz weiterverwenden.

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
| LAN senden und LAN empfangen (gesamt) | Float | B/s | aus |
| Freier RAM und RAM-Cache | Float | B | aus |
| Je Volume: verfügbarer Speicher | Float | B | aus |
| Je Lüfter: Drehzahl | Integer | rpm | aus |
| Je Pool: Volume-Belegung | Float | % | aus |
| Je Pool: UGOS-Status | String | Rohstatus | aus |
| Je Laufwerk: Temperatur | Float | °C | aus |
| Je Laufwerk: UGOS-Status | String | Rohstatus | aus |
| Je Laufwerk: Betriebsstunden | Integer | h | aus |

Die Kachel erhält ihre Daten direkt aus dem Instanzattribut und funktioniert unabhängig von den optionalen Variablen. Sitzungstoken liegen nur in einem flüchtigen Buffer. Es gibt keine Hilfs-, Debug- oder HTML-Box-Variablen. Das Modul weist den angelegten Statusvariablen native Symcon-Wertdarstellungen zu.

Standard: **0**, empfohlene Auswahl: **4** Variablen. Maximale Auswahl bei den implementierten Erkennungsgrenzen: `9 + Lüfter + 2 × Pools + Volumes + 3 × Laufwerke`. Je 16 CPU- und Gehäuselüfter, je 32 Pools und Laufwerke sowie insgesamt bis zu 256 Volumes ergeben maximal **457** Variablen einer aktuellen Datenquelle. Erhaltene Altvariablen können diese Zahl erhöhen. Nicht erkannte Komponenten werden nicht als Platzhalter angelegt.

**Vorhandene Variable**: Ziel `0` legt eine eigene Variable an. Eine andere ausgewählte Variable wird mit dem Messwert beschrieben und spart eine zusätzliche Variable. Sie muss zum Datentyp passen und darf keine Variablenaktion besitzen. Die Auswahl ist damit ein ausdrücklicher Schreibauftrag für diese Symcon-Variable. Für extern zugeordnete Variablen gilt deren eigene Darstellung.

Name und Position gelten für das erstmalige Anlegen eigener Variablen. Der Objektbaum übernimmt die bestehenden Namen und Positionen bei der Umstellung auf native Darstellungen. Abwählen stoppt die Aktualisierung, löscht aber nichts. Nicht mehr benötigte Variablen können nach Prüfung ihrer Verknüpfungen ausdrücklich im Objektbaum gelöscht werden; es gibt keinen automatischen Löschmechanismus.

Fehlende Messwerte überschreiben bestehende Werte nicht mit `0`. Für Automationen bedeutet das: Der letzte Messwert kann alt sein. Bei Bedarf die optionale Verbindungsvariable mitverwenden und zusätzlich den Änderungs-/Aktualisierungszeitpunkt prüfen. Eine erfolgreiche Verbindung garantiert nicht, dass jeder optionale Sensor oder Speicherendpunkt verfügbar ist.

## Visualisierung

CPU, RAM und CPU-Temperatur bilden die Hauptanzeige. Darunter folgen die einzeln auswählbaren zusätzlichen Messwerte, Speicherpools mit Volume-Belegungsbalken, Laufwerke und Kühlung. Warnschwellen bleiben sichtbar. Diagnose ist aufklappbar und bleibt bei Updates geöffnet. Lange Namen werden umgebrochen. Kleine Kacheln verwenden eine vertikale Anordnung und vertikales Scrollen. Die Kachel steuert keinen Vollbildmodus der Host-App.

Konfigurierbar sind Anzeigename, Farbschema, Akzentfarbe, kompakte Abstände, Pools, Laufwerke, Lüfter und die Aktualisieren-Schaltfläche. Die drei Hauptwerte lassen sich einzeln ausblenden. Keine externen Schriften, Bilder, Bibliotheken oder Cloud-Dienste werden geladen.

Die Kachel zeigt Daten nach Ablauf von `max(45 Sekunden, 2 × Intervall)` als veraltet an. Beim ersten Laden erfolgt eine vollständige Initialisierung. Messwerte aus dem letzten erfolgreichen Poll bleiben bei Fehlern sichtbar, werden aber als veraltet markiert. Nach Konfigurationsänderungen und Reaktivierung ist eine neue Messung erforderlich, bevor Daten wieder als aktuell gelten.

**Designvorschau:** [HTML-Vorschau](docs/preview.html), [Dunkel](docs/preview-dark.png), [Hell](docs/preview-light.png), [Smartphone](docs/preview-mobile.png). Die Bilder zeigen die tatsächlich im Browser gerenderte Modul-HTML mit ausdrücklich **synthetischen Testdaten**, keine Live-NAS-Daten.

## Kommunikation und Fehlerbehandlung

Pro Poll werden Systeminformationen, Statistiken, Speicherpools und Laufwerke gelesen. Bei fehlenden CPU- oder RAM-Grundwerten ergänzt `taskmgr/stat/overview` die ausführliche Ressourcenstatistik. Fehler einzelner Endpunkte ergeben eine Teildiagnose. Nur gültige Messwerte werden übernommen.

Mindestintervall 15 Sekunden, Standard 60 Sekunden. Anfragen haben ein konfigurierbares Zeitlimit von 2–30 Sekunden; ein kompletter Durchlauf ist zusätzlich auf höchstens 55 Sekunden begrenzt. Zeitgleiche Polls werden per Symcon-Semaphore verhindert; manuelle Wiederholungen innerhalb von 5 Sekunden werden begrenzt. Ein abgelaufenes Token wird je Leseanfrage höchstens einmal erneuert.

Bei einem vorübergehenden Gesamtausfall greift eine automatische Wiederholpause von 10 bis 60 Sekunden. Abgelehnte Anmeldungen führen zu einer 15-minütigen Schutzpause. Erneutes Übernehmen einer korrigierten Konfiguration setzt Pause und Sitzung zurück.

Verbindungsdetails, Zugangsdaten, Sitzungstoken und vollständige Geräteantworten werden nicht protokolliert und nicht an die Kachel übertragen. Die Verbindung verwendet ausschließlich HTTP. Weiterleitungen und Umgebungs-Proxys werden nicht verwendet; Antworten sind auf 2 MiB begrenzt. Das Modul hat eine feste Liste erlaubter Lese-Endpunkte. NAS-Passwörter werden als Instanzeigenschaft gespeichert und sind damit Bestandteil entsprechend geschützter Symcon-Konfigurationen und Backups.

## Skriptfunktionen

```php
// Instanz-ID anpassen. true bei einer erfolgreichen Abfrage,
// false bei deaktivierter Instanz, Wiederholsperre oder Fehler.
$erfolgreich = UGNAS_Update(12345);
```

`UGNAS_SelectVariables` ist eine Hilfsfunktion für das Instanzformular. Sie ändert dessen aktuelle Auswahl; anschließend muss der Benutzer übernehmen. Die Kachel verwendet `Refresh` für die Aktualisierung. Die Anzeigeauswahl wird in der Instanzkonfiguration gespeichert.

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
  UgosResourceSubscription.php
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
  websocket_fixture.py
  make_preview.php
  preview-payload.json
  browser.cjs
```

## Prüfung und verbleibende Abnahme

Details stehen im [Testbericht](docs/TESTBERICHT.md). Es wurden PHP-Syntax, Modul-/Formularstruktur, Datenverarbeitung, echte HTTP-Anfragen gegen einen isolierten Testserver, RSA-Anmeldung, Sitzungswechsel, Fehlerfälle, offizielle unveränderte SymconStubs und ein tatsächlicher Browserlauf geprüft.

**Offen:** Installation und Laufzeit im echten Symcon-Dienst, Login und Leserechte auf dem DXP8800 Plus mit UGOS Pro 1.20.0.0142, Antwortformat und Feldzuordnung aller Sensoren, DXP-Modellmatrix, IPSView/TileHtml und Android-Wandtablet. Ein Browser- oder Stub-Test ersetzt diese Abnahme nicht. Die Version ist deshalb eine Testversion und noch keine als produktionsreif bestätigte Geräteintegration.
