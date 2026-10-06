# Changelog

## 1.3.1 (2026-10-06)

### Hinzugefügt
- **„Order status“ antwortet mit der ganzen Bestellung.** Neben Status, Datum, Sendungsverfolgung und voraussichtlicher Lieferung enthält die Antwort jetzt Bestellnummer, Namen des Kunden, die Artikel (Name, Produktnummer, Variante, Menge, Einzel- und Positionspreis), die Summen (Zwischensumme, Versand, Steuer, Rabatt, Gesamtbetrag) in der Währung der Bestellung so, wie sie dem Kunden berechnet wurden, Zahlungsart und Zahlungsstatus, Versandart und deren Lieferzeit sowie Liefer- und Rechnungsadresse. Der Chat zeigt sie wie bisher erst, nachdem der Kunde nachgewiesen hat, dass die Bestellung ihm gehört (Bestellnummer und E-Mail-Adresse oder angemeldet); E-Mail-Adresse, Kunden-ID und interne IDs werden nie zurückgesendet. `OrderStatusResponseEvent` läuft, nachdem alles gefüllt ist, sodass eine Erweiterung jeden Wert ändern und unter `extra` eigene Felder hinzufügen kann (siehe README).
- **Headless-Verkaufskanäle werden genannt, statt stillschweigend übersprungen zu werden.** Der Verbindungstest (auf der Emporiqa-Seite und mit `bin/console emporiqa:test-connection`) und der Tab „Synchronisierung“ nennen die aktiven Headless-Verkaufskanäle (API), in denen Produkte sichtbar sind, und erklären, warum sie nicht synchronisiert werden: Shopware erzeugt für Headless-Kanäle keine Produktadressen (SEO-URLs), daher könnte der Chat dort nicht auf Produkte verlinken. Sie verweisen außerdem auf den Einbettungscode, mit dem Sie den Chat in ein Frontend einbinden, das Shopware nicht ausliefert. Der leere Standardkanal „Headless“ von Shopware wird nicht genannt. An dem, was synchronisiert wird, ändert sich nichts.

## 1.3.0 (2026-10-05)

### Kurz gesagt
- Die Bestellverfolgung funktioniert wie in 1.2.5 und bleibt eingeschaltet. Das Plugin arbeitet jetzt außerdem mit den fertigen Regeln von Emporiqa: Die neue Regel „Order status“ ersetzt die alte Bestellverfolgung, und die Emporiqa-Seite zeigt sie als „Ein“ oder „Nicht hinzugefügt“.
- Ein Ganzseiten-Cache oder ein Server-Log kann die Identität eines Kunden im Chat nicht mehr an einen anderen weitergeben.
- Die Preise, die der Chat nennt, entsprechen jetzt dem Warenkorb, auch bei erweiterten Preisen für alle Besucher, bei Varianten, die ihren Preis erben, und bei zeitlich begrenzten Aktionen, solange sie laufen.

### Hinzugefügt
- **Regel „Order status“.** Ein schreibgeschützter Endpunkt für die fertige Regel „Order status“ von Emporiqa (`/emporiqa/actions/order-status`), in beide Richtungen mit zweckgebundenen Schlüsseln signiert (Schema 2), 10 Minuten lang über `request_id` dedupliziert und begrenzt pro Bestellnummer und pro E-Mail-Adresse (10 pro 10 Minuten) sowie pro Shop (300). Bei Überschreitung antwortet er mit einem signierten 429, dessen `data.scope` die Grenze nennt, und einem `Retry-After`-Header. Er antwortet für eine unbekannte Bestellung und eine falsche E-Mail-Adresse gleich, prüft die Pflichtfelder vor der Suche, findet nur Bestellungen der synchronisierten Verkaufskanäle und liefert Bestellstatus, Datum, Sendungsnummern mit Links und das voraussichtliche Lieferdatum, sonst nichts. Ein angemeldeter Kunde nennt nur die Bestellnummer; eine Gastbestellung findet er weiterhin über deren E-Mail-Adresse. Erweiterungen können die Antwort mit dem neuen `OrderStatusResponseEvent` anpassen.
- **Karte „Fertige Regeln“** im Tab „Einstellungen“, sichtbar, sobald Emporiqa meldet, dass der Shop Regeln hat (beim Verbinden oder beim Verbindungstest). Sie zeigt die Adresse für den Bestellstatus mit einer Kopieren-Schaltfläche, ob „Order status“ eingeschaltet ist, und die Schaltfläche „In Emporiqa öffnen“. Nach dem Update ist keine neue Verbindung nötig.
- **Kundenpreise.** Ein schreibgeschützter Endpunkt (`/emporiqa/actions/customer-prices`), signiert und begrenzt wie „Order status“ (30 Aufrufe pro Kunde und 600 pro Shop alle 10 Minuten, über `request_id` dedupliziert), der Emporiqa mitteilt, was ein angemeldeter Kunde für bis zu 20 Produkte bezahlt: die Preise seiner Kundengruppe mit Netto- oder Bruttoanzeige, seine eigenen Regelpreise und Staffelpreise so, wie der Warenkorb sie berechnet, in der angefragten Währung, wenn der Verkaufskanal sie anbietet. Die Preise stammen aus der Preisberechnung von Shopware für einen neuen Kontext dieses Kunden; nichts wird gespeichert, Warenkorb und Sitzung bleiben unberührt. Produkte, die der Kunde nicht sehen kann, fehlen in der Antwort, ein unbekannter oder inaktiver Kunde erhält `not_found`, und die Antwort enthält nur Preise. Emporiqa nutzt den Endpunkt in einer späteren Version.
- **Zeitlich begrenzte Aktionen erscheinen im Chat, wenn sie beginnen, und verschwinden, wenn sie enden.** Eine neue geplante Aufgabe (`emporiqa.price_rule_boundary`, alle 15 Minuten) synchronisiert die Produkte einer Preisregel neu, deren Zeitraum gerade begonnen oder geendet hat. Regeln nach Uhrzeit oder Wochentag werden weiterhin nie übertragen. Sie läuft mit den geplanten Aufgaben von Shopware (`scheduled-task:run` oder Admin-Worker).
- `/emporiqa/actions/verify` beantwortet die signierte Adressprüfung von Emporiqa und beim Verbinden mit einem Klick den Nachweis, dass der Shop wirklich unter der verbundenen Adresse liegt.
- Synchronisierungs-Webhooks tragen neben dem alten `X-Webhook-Signature` den Header `X-Emporiqa-Webhook-Signature` (Schema 2) und `X-Emporiqa-Plugin-Version`, bei jeder Wiederholung neu signiert.
- Das Verbinden sendet die Adresse für den Bestellstatus (die Storefront-Domain auf dem Host der Administration, einschließlich eines Domain-Pfads wie `/de`), behält den Verbindungsschlüssel, bis Emporiqa antwortet, und speichert, was Emporiqa zu den Regeln meldet.
- Der Verbindungstest warnt, wenn die Uhr dieses Servers mehr als 2 Minuten von der von Emporiqa abweicht (Emporiqa lehnt Signaturen ab, die mehr als 5 Minuten abweichen).
- Bestellstatus, Adressprüfung und die alte Bestellverfolgung antworten auch im Wartungsmodus.

### Geändert
- **Das Kunden-Token steht nicht mehr in der Widget-URL und wird nicht mehr im Browser zwischengespeichert.** Es wird erst beim Öffnen des Chats vom nicht zwischengespeicherten Endpunkt `/emporiqa/api/user-token` geholt, enthält `aud` (die Emporiqa-Shop-ID) und wird dem Widget über einen privaten `MessageChannel`-Port übergeben. Ein Gast erhält die Antwort ohne Anfrage, das Token eines angemeldeten Kunden wird höchstens fünf Minuten wiederverwendet, und eine fehlgeschlagene Antwort wird nie wiederverwendet.
- **Alte Bestellverfolgung.** Sie funktioniert genau wie bisher und bleibt eingeschaltet, bei Neuinstallationen und nach dem Update. Wo fertige Regeln angeboten werden, steht sie unter „Erweitert“ als „Alte Bestellverfolgung (veraltet)“ mit einem Schalter; entfernen Sie zuerst ihre Adresse im Emporiqa-Dashboard und schalten Sie sie dann aus. Ungültige Bestellnummern werden ohne Suche wie unbekannte beantwortet, und ein Fehler zeigt nie einen Stacktrace.
- Die Adressen für Warenkorb und Kunden-Token enthalten den Domain-Pfad des Verkaufskanals (zum Beispiel `/de`) und funktionieren damit auch auf Domains mit Pfad.
- Varianten werden schlanker übertragen: Eine Variante wiederholt nicht mehr die Beschreibungen, Kategorien und den Hersteller des Hauptprodukts und nicht mehr die Felder `variation_attributes` und `is_parent`, die nur das Hauptprodukt betreffen. Emporiqa übernimmt sie vom Hauptprodukt, im Chat ändert sich nichts.
- Die manuelle Synchronisierung blättert nach ID statt nach Offset, sodass ein während der Synchronisierung deaktivierter oder gelöschter Artikel die Seiten nicht mehr verschiebt, keinen aktiven Artikel überspringt und dieser beim Abschluss nicht gelöscht wird.
- Das Verbinden wartet bis zu 20 Sekunden auf Emporiqa, das während des Austauschs die Shop-Adresse prüft.
- **Die eigenen Admin-Endpunkte des Plugins erfordern das Recht zur Plugin-Verwaltung** (`system.plugin_maintain`): Verbinden, Synchronisierung, Verbindungstest und die Einstellungsseite des Plugins lehnen eine Admin-Rolle oder Integration ohne dieses Recht jetzt ab. Die Emporiqa-Einstellungen bleiben normale Systemkonfiguration; eine Rolle mit dem Recht `system_config:update` kann sie weiterhin über die System-Config-API von Shopware ändern. Administratoren sind nicht betroffen.
- Ist ein Shop mit mehreren Emporiqa-Shops verbunden (ein Shop pro Verkaufskanal), durchsucht „Order status“ nur die Verkaufskanäle des anfragenden Shops, und Wiederholungsschutz sowie Anfragegrenzen gelten pro Shop.
- Fehler der Aktions-Endpunkte und der alten Bestellverfolgung werden ohne die Datenbankmeldung protokolliert, die eine E-Mail-Adresse oder Bestellnummer enthalten konnte.
- `GET /emporiqa/api/user-token` antwortet weiterhin für Storefront-Themes, die vor 1.3.0 kompiliert wurden; ab 1.4.0 nur noch per POST.

### Behoben
- **Erweiterte Preise für alle Besucher sind jetzt der Preis, den der Chat nennt.** Hat eine Preisregel, die für Gäste gilt (zum Beispiel „Immer gültig“), erweiterte Preise, berechnet die Storefront die erste Staffel dieser Regel und ignoriert den eigenen Produktpreis. Gesendet wurde bisher trotzdem der Produktpreis. Aktueller Preis, Streichpreis und Staffelpreise kommen jetzt aus dieser Regel, genau wie im Warenkorb.
- **Varianten, die ihren Preis erben, werden nicht mehr ohne Preis gesendet.** Eine Variante, die den Preis oder die erweiterten Preise des Hauptprodukts verwendet, erhält diese jetzt wie in der Storefront.
- **Preise aus Regeln, die von Uhrzeit oder Wochentag abhängen, werden nicht mehr gesendet.** Sie wechseln zu oft, um synchron zu bleiben, daher konnte ein Happy-Hour- oder Sonntagspreis außerhalb seiner Zeit genannt werden. Stattdessen wird der Preis gesendet, der außerhalb dieser Zeiten gilt.
- **Ein Produkt, das keinem Verkaufskanal zugeordnet ist, wird nicht mehr synchronisiert.** Keine Storefront zeigt es, im Chat wurde es aber in jedem Kanal angeboten.
- **Ein Produkt mit der Sichtbarkeit „In Produktlisten und Suche ausblenden“ in einem Verkaufskanal wird nicht mehr in diesen Kanal synchronisiert.** Die Storefront öffnet es dort nur über seinen Link, also bietet es auch der Chat nicht an. Mit „In Produktlisten ausblenden“ findet die Suche der Storefront ein Produkt weiterhin, daher bleibt es.
- **Ein Produkt, das kein synchronisierter Verkaufskanal mehr zeigt, verschwindet beim Speichern aus dem Chat**, samt seinen Varianten. Wurde der letzte Verkaufskanal entfernt oder auf „In Produktlisten und Suche ausblenden“ gestellt, blieb es bisher bis zur nächsten vollständigen Synchronisierung im Chat, und eine Änderung nur an den Verkaufskanälen eines Produkts oder an deren Sichtbarkeit wurde gar nicht gesendet.
- Die Datenvorschau im Tab „Synchronisierung“ zeigt Staffelpreise so, wie die Synchronisierung sie sendet.
- Zwei gleichzeitige Rückrufe mit demselben Verbindungslink können nicht mehr beide eingelöst werden.
- **Eine Deinstallation ohne Beibehalten der Daten entfernt jetzt alle Plugin-Einstellungen.** Verbindungsstatus, Regelstatus, der Schalter der alten Bestellverfolgung, die Auswahl der Sprachen und Verkaufskanäle sowie Sync-Sitzungen blieben bisher zurück und kehrten bei einer Neuinstallation zurück.
- **Produkte werden nach dem Update einmalig neu synchronisiert**, sodass bereits an Emporiqa gesendete Preise ohne weiteres Zutun korrigiert werden. Ohne Storefront (Headless) bitte einmal „Produkte synchronisieren“ auf der Emporiqa-Seite ausführen.
- Manuelle Synchronisierung, Verbindungstest und die CLI-Befehle funktionieren wieder unter Shopware 6.6.0.x, wo sie seit 1.2.1 mit „Call to undefined method Context::createCLIContext()“ abbrachen.
- Erreicht eine Synchronisierung Emporiqa nicht, zeigt sie den Fehler einmal pro Stapel statt einmal pro Anfrage und ohne interne ID als „Offset“, und eine Fehlermeldung von Emporiqa behält ihren Hinweis (zum Beispiel, wie lange bis zum erneuten Start einer hängenden Synchronisierung zu warten ist).

## 1.2.5 (2026-09-29)

### Behoben
- **Preise in weiteren Währungen werden jetzt umgerechnet.** Ein Artikel, der nur in der Standardwährung gepflegt ist, wurde für jede andere Währung mit demselben Betrag übertragen (zum Beispiel wurden aus 5.496 EUR 5.496 USD). Preise, Streichpreise und Staffelpreise verwenden jetzt den Umrechnungsfaktor und die Rundung der Währung, genau wie in der Storefront. Für eine Währung explizit gepflegte Preise bleiben unverändert.
- **In mehreren Schritten gespeicherte Produktänderungen gehen nicht mehr verloren.** Wenn ein Import oder eine Schnittstelle einen Artikel und danach seine Preise oder Bilder in getrennten Schritten derselben Anfrage oder desselben Laufs gespeichert hat, kam nur der erste Schritt bei Emporiqa an. Jetzt wird jeder Schritt synchronisiert. Das Speichern in der Administration war nicht betroffen.
- **Produkte werden nach dem Update von 1.2.4 oder älter einmalig neu synchronisiert**, sodass bereits an Emporiqa gesendete Preise ohne weiteres Zutun korrigiert werden. Ohne Storefront (Headless) bitte einmal „Produkte synchronisieren“ auf der Emporiqa-Seite ausführen.

## 1.2.4 (2026-09-29)

### Behoben
- **Staffelpreise einer Kundengruppe werden nicht mehr allen Kunden angezeigt.** Erweiterte Preise wurden über alle Preisregeln hinweg zusammengeführt, sodass eine Regel für eine einzelne Kundengruppe (zum Beispiel Händler- oder B2B-Preise) im Chat als öffentlicher Mengenrabatt erscheinen konnte. Staffelpreise stammen jetzt nur noch aus der Regel mit der höchsten Priorität, die für einen Gast tatsächlich gilt, so wie die Storefront einen Artikel für nicht angemeldete Besucher berechnet.
- **Produkte werden nach dem Update einmalig neu synchronisiert**, sodass bereits an Emporiqa gesendete Staffelpreise ohne weiteres Zutun korrigiert werden. Die Synchronisierung wird beim nächsten Storefront-Seitenaufruf im Hintergrund eingeplant. Ohne Storefront (Headless) bitte einmal „Produkte synchronisieren“ auf der Emporiqa-Seite ausführen.

## 1.2.3 (2026-09-21)

### Behoben
- **Kategorien werden unabhängig von ihrem Layout als Seiten synchronisiert.** Zuvor wurden nur Kategorien mit dem Layout „Shop-Seite“ oder „Erlebniswelt“ berücksichtigt; eine normale Kategorie mit Produktlisten-Layout wird jetzt ebenfalls synchronisiert, wenn sie eigenen Text enthält, etwa eine SEO-Beschreibung, einen Ratgeber oder ein FAQ-Akkordeon ober- oder unterhalb der Produktübersicht. Eine Kategorie, die nur eine Produktübersicht ohne eigenen Text ist, bleibt weiterhin ausgelassen.
- **Die Startseite der Storefront wird jetzt synchronisiert.** Sie wurde zuvor als Wurzelkategorie immer ausgeschlossen.

## 1.2.2 (2026-09-17)

### Hinzugefügt
- **Einstellung „Synchronisierte Verkaufskanäle“.** Die Karte „Erweitert“ listet alle Storefront-Verkaufskanäle auf; nicht ausgewählte Kanäle werden bei der Produkt- und Seitensynchronisierung ausgelassen und das Chat-Widget wird auf ihren Storefronts nicht angezeigt. Standardmäßig bleiben alle Kanäle synchronisiert.
- **„Erweiterungen > Konfigurieren“ öffnet wieder direkt die Emporiqa-Einstellungsseite** (keine doppelte Konfigurationsseite).

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
