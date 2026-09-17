# Changelog

## 1.2.1 (2026-09-17)

### Behoben
- **Seiteninhalte werden jetzt so gelesen, wie die Storefront sie darstellt.** Zugeordnete Felder, seitenspezifische Textüberschreibungen, Übersetzungs-Fallbacks und CMS-Elemente anderer Plugins (zum Beispiel FAQ-Akkordeons) sind in den synchronisierten Seiteninhalten enthalten.
- **Seitenlinks führen immer zu einer echten Storefront-Seite.** Links verwenden die kanonische SEO-URL je Verkaufskanal und Sprache (deutsche Links von Erlebniswelten lieferten zuvor 404). Solange Shopware die SEO-URL noch nicht erzeugt hat, wird die technische Route gesendet und die Seite automatisch erneut synchronisiert, sobald die URL vorhanden ist oder sich ändert, auch bei Änderungen unter Einstellungen > SEO. Seiten, die in keinem synchronisierten Verkaufskanal erreichbar sind, werden aus Emporiqa entfernt statt verlinkt.
- **Kategorien mit einem Landingpage-Layout werden als Seiten synchronisiert.** Wurzelkategorien (Navigation, Footer, Service) werden nie als Seiten synchronisiert.
- **Eine Synchronisierung ohne passenden Verkaufskanal oder passende Sprache meldet einen Fehler** statt einer erfolgreichen Synchronisierung von null Elementen.
- **Verkaufskanäle mit gleichem Namen werden nicht mehr zu einem Emporiqa-Kanal zusammengefasst**, und Produktlinks zeigen auf einen Verkaufskanal, in dem das Produkt sichtbar ist.
- **Das Speichern der Plugin-Einstellungen kann die Zugangsdaten nicht mehr überschreiben**, solange sie noch geladen werden; unbekannte Sprachcodes werden abgelehnt.
- **Darstellung der Statusmeldungen und Buttons in der Shopware-6.7-Administration.**
- **Die an Emporiqa gemeldete Plugin-Version** (`plugin_version`, User-Agent) war noch 1.1.0.

### Geändert
- **Seitenänderungen in Echtzeit werden im Hintergrund verarbeitet** (Message Queue), sodass große Importe und Layout-Änderungen das Speichern nicht mehr verlangsamen. Eine Änderung an einem Layout der Erlebniswelten synchronisiert alle Seiten, die es verwenden, erneut.
- **Die Plugin-Konfiguration entspricht den Regeln des Shopware Store.** Der Extension Manager wird nicht mehr überschrieben: „Erweiterungen > Konfigurieren“ öffnet die native Konfigurationsseite von Shopware, die jetzt auf die vollständige Emporiqa-Seite verweist (Verbindung, Sprachen, Synchronisierung). „Einstellungen > Emporiqa“ bleibt unverändert.
- **Die statische Codeanalyse ist fehlerfrei**: veraltete Shopware-APIs ersetzt, XML-Service- und Routendefinitionen zu YAML migriert, Storefront-Plugins verwenden `window.PluginBaseClass`.
- Der Hilfetext zu „Aktivierte Sprachen“ weist darauf hin, dass das Chat-Widget für nicht ausgewählte Sprachen ausgeblendet wird.
- Für Entwickler: `PostPageFormatEvent` wird jetzt aus dem Message-Worker (nicht aus der Admin-Anfrage) ausgelöst, auch nach SEO-URL- und Layout-Änderungen.

## 1.1.1 (2026-09-02)

### Behoben
- **Der deutsche Hilfetext in der Konfiguration nannte Menüpunkte, die es nicht gibt.** Beide `helpText lang="de-DE"`-Texte verwiesen auf „Einstellungen → Shop-Integration → Integrationsübersicht“. Das Emporiqa-Dashboard ist jedoch nicht lokalisiert, eine deutsche Seite dieses Namens gibt es also nicht. Beide nennen jetzt den tatsächlichen englischen Pfad und weisen darauf hin, dass das Dashboard englischsprachig ist. Die Plugin-Verwaltung in Shopware bleibt deutsch, wie sie es immer war.

## 1.1.0 (2026-07-10)
Erste öffentliche Veröffentlichung. Ausgeliefert als ZIP-Datei aus einem GitHub-Release und über Composer.

### Funktionen

- **One-Click-Verbindung**: Der Button „Mit Emporiqa verbinden“ startet einen sicheren PKCE-Handshake, kein manuelles Kopieren von Shop-ID und Webhook-Geheimnis erforderlich. Die manuelle Eingabe der Zugangsdaten bleibt weiterhin möglich.
- **Produktsynchronisierung**: Echtzeit- und Stapelsynchronisierung über die Webhook-API mit asynchroner Zustellung per Message Queue
- **Schlanke Verfügbarkeitssynchronisierung**: Reine Bestandsänderungen (einschließlich bestellbedingter Bestandsreduzierungen) senden kompakte `product.availability`-Events statt vollständiger Produkt-Payloads
- **Staffelpreise**: Erweiterte Mengenpreise werden als `tier_prices` je Währung exportiert (sortiert, dedupliziert, wirkungslose Staffeln entfernt)
- **Backorder-Status**: Produkte ohne Bestand, die keine Abverkaufsartikel sind, melden `backorder` statt `out_of_stock`; Elternprodukte aggregieren die Verfügbarkeit ihrer Varianten
- **Umfangreiche Produkt-Payloads**: `min_order_quantities`, `max_order_quantities`, `available_for_order`, `condition` und `is_virtual` (digitale/Download-Produkte) sind enthalten
- **Präzises Löschen von Varianten**: Das Löschen einer Variante sendet ein `variation-…`-Löschevent und aktualisiert das Elternprodukt; das Löschen eines Elternprodukts entfernt auch alle seine Varianten aus Emporiqa
- **Medien- und Preis-Trigger**: Änderungen an Produktbildern und erweiterten Preisen stoßen automatisch eine erneute Produktsynchronisierung an
- **Warnungen bei Katalogänderungen**: Das Umbenennen von Kategorien, Herstellern, Währungen, Steuern oder Sprachen protokolliert eine Warnung mit dem Handlungshinweis, eine vollständige Synchronisierung auszuführen
- **Landingpage-Synchronisierung**: CMS-Landingpages und Shopseiten werden als Seiten-Payloads synchronisiert
- **Konsolidiertes Webhook-Format**: Verschachtelte `{channel: {language: value}}`-Struktur, identisch mit allen Emporiqa-Integrationen
- **Verkaufskanal-Zuordnung**: Shopware-Verkaufskanäle lassen sich Emporiqa-Kanalschlüsseln (`b2b`, `retail` usw.) zur Katalogsegmentierung zuordnen
- **Multi-Währungs-Preise**: Produkte enthalten Preise für alle Währungen je Verkaufskanal-Domain
- **Mehrsprachigkeit**: Alle konfigurierten Sprachen werden in einem Durchlauf synchronisiert
- **Kategoriehierarchie**: Vollständige Kategoriepfade mit `>`-Trennzeichen (z. B. `Elektronik > Gadgets`)
- **Konfigurierbare Markenquelle**: Produkthersteller oder eine Eigenschaftsgruppe als Markenquelle verwenden
- **Preise inkl. Steuer**: Produkte werden mit dem Bruttopreis sowie einer Brutto-/Netto-Aufschlüsselung gesendet, wenn Steuer anfällt; die Anzeige wird im Emporiqa-Dashboard gesteuert
- **Chat-Widget-Einbindung**: Storefront-Widget mit Benutzer-Token-Unterstützung und währungsabhängiger Konfiguration
- **Warenkorb-API**: Storefront-Warenkorb-Endpunkte für den Einkauf im Chat mit SEO-URLs und dynamischer Checkout-URL
- **Bestellverfolgung**: Konfigurierbare Bestell-/Transaktionsstatus lösen den `order.completed`-Webhook aus, mit optionaler E-Mail-Verifizierung
- **Conversion-Webhook genau einmal**: `order.completed` wird über eine persistente Markierung an der Bestellung anfrageübergreifend dedupliziert; die Emporiqa-Chat-Session-ID wird bei der Bestellung gespeichert, sodass die Attribution auch bei späteren Statusänderungen erhalten bleibt
- **Webhook-Wiederholung mit Backoff**: Vorübergehende Fehler (429, 5xx, Netzwerkfehler) werden mit exponentiellem Backoff und verzögertem erneutem Einreihen wiederholt
- **Unterstützung für langlaufende Worker**: Dienste implementieren `ResetInterface`, um zwischengespeicherten Zustand zwischen Anfragen in Swoole- oder Messenger-Workern zurückzusetzen
- **Dry-Run-Verbindungstest**: Sendet ein Produkt aus Ihrem Katalog an `?dry_run=true` und liefert eine Feld-für-Feld-Validierung, erkannte Sprachen/Kanäle und Warnungen
- **Admin-Dashboard**: Vollständige Einstellungsoberfläche mit Verbindungstest, Datenvorschau, Synchronisierungssteuerung, Verkaufskanal-Zuordnung, Bestellverfolgungs-Konfiguration und CLI-Befehlsreferenz
- **Fortschrittsanzeige für die Synchronisierung**: Über den Admin gestartete Massensynchronisierungen laufen in gesteuerten Stapeln mit Live-Fortschrittsbalken, Protokoll je Stapel, Abbrechen-Button und geschütztem Abschluss, der eine unvollständige Synchronisierung nicht als abgeschlossen wertet
- **CLI-Befehle**: `emporiqa:sync:products`, `emporiqa:sync:pages`, `emporiqa:sync:all`, `emporiqa:test-connection` (alle mit `--dry-run`-Unterstützung)
- **Deutsche Übersetzungen**: Vollständige de-DE-Unterstützung für Admin-Oberfläche und Konfiguration
