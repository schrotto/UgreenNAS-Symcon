# Technische Quellen

Abruf/Prüfung am 31.10.2026. Inhalte wurden als technische Referenzen gelesen; es wurden keine fremden Projektanweisungen übernommen. Der Client und die Kachel wurden eigenständig in PHP/HTML/CSS/JavaScript implementiert. Keine fremden NAS-Bilder oder Quelltextdateien sind Teil der Bibliothek.

## Symcon – primäre Dokumentation

- [PHP-SDK](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
- [Bibliotheksstruktur](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/struktur/)
- [HTML-SDK](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/)
- [SetVisualizationType](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/setvisualizationtype/)
- [GetVisualizationTile](https://www.symcon.de/en/service/documentation/developer-area/sdk-tools/sdk-php/module/getvisualizationtile/)
- [UpdateVisualizationValue](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/updatevisualizationvalue/)
- [requestAction](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/requestaction/)
- [List: Persistenz, dynamische Werte, Formularaktionen](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/konfigurationsformulare/list/)
- [Offizielle SymconStubs](https://github.com/symcon/SymconStubs), geprüfter Commit `31f2e0478eb3763236fcf44ea4144271fdeddfd5`.
- [Offizielles HTML-SDK-Beispiel](https://github.com/symcon/SymconTest/tree/master/HTMLVisuTestDuckCounters).

HTML-SDK seit 7.1; Bibliothek setzt wegen des gewählten Gesamtstands 8.1 voraus. Verwendet wird der dokumentierte Visualisierungstyp `1`. Der neuere Vollbildtyp `2` ist laut aktueller Dokumentation erst seit 9.1 verfügbar und wird hier nicht vorausgesetzt.

## UGREEN und protokollbezogene Primärquellen

- [UGREEN Task Manager User Guide](https://support.ugnas.com/detail/article/en-US/227): verfügbare Überwachungsbereiche im Herstellerprodukt, kein Protokollvertrag für diese Integration.
- [UGREEN Desktop Guide](https://support.ugnas.com/detail/article/en-US/61): lokale Oberfläche und Zwei-Faktor-Anmeldung.
- [UGREEN Entwicklerplattform](https://developer.ugnas.com/en/doc/backend/quick-start/prepare.html): UPK-App-Entwicklung; für dieses externe Modul nicht erforderlich.
- [Home-Assistant-UGREEN-Integration](https://github.com/Tom-Bom-badil/home-assistant_ugreen-nas), geprüfter Commit `f7216b164b8a27457cb2be60a7a5fd9c37237ac8`: beobachteter Login, RSA-Header, `get_all`, Pool-/Disk-Endpunkte und Messwertpfade.
- [UGOS-Client: Auth-Protokoll](https://github.com/metaneutrons/ugos-cli/blob/main/docs/api-auth.md): RSA-PKCS1-v1.5, Sitzungsführung und Standardports.
- [UGOS-Client: Core-API](https://github.com/metaneutrons/ugos-cli/blob/main/docs/api-system.md): Machine-Info, Monitoring, Modell-/Hardwarevarianten. Im Quellprojekt auf einer anderen Firmware geprüft; das ist kein Nachweis für 1.20.0.0142.

Der Schluss, dass weitere DXP-Modelle mit gleichem UGOS-API-Format angebunden werden können, ist eine technische Annahme. Er wird nicht als geprüfte Kompatibilität für alle DXP-Geräte dargestellt. Nicht eindeutig belegte Einheiten von I/O-Werten werden in dieser Version nicht ausgewertet. Nicht zuordenbare Laufwerkstemperaturen bleiben nicht verfügbar.
