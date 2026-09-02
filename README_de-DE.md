# Emporiqa: KI-Chatbot für Shopware 6

*[English version](README.md)*

Der KI-Chatbot [Emporiqa](https://emporiqa.com) für Shopware 6 ist ein Online-Verkäufer, der in Ihrem Shop für Sie verkauft: Käufer beschreiben, was sie brauchen, oder laden ein Foto von etwas hoch, das ihnen gefällt, er findet passende Produkte aus Ihrem Katalog, behandelt Einwände wie „zu teuer“ mit Alternativen statt mit einem Rabatt, beantwortet Fragen anhand Ihrer CMS-Seiten und führt Käufer in 65+ Sprachen zum Warenkorb und zur Kasse. Dieses Plugin synchronisiert Ihren Produktkatalog und Ihre CMS-Seiten mit Emporiqa, bindet das Chat-Widget in Ihre Storefront ein und stellt Endpunkte für Warenkorb-Aktionen im Chat und für die Bestellverfolgung bereit.

[![Geöffnetes Emporiqa-Chat-Widget in einer deutschsprachigen Storefront. Auf die Frage, welches Notebook unter 1200 Euro sich für eine Studentin eignet, die Videos schneidet, nennt es das MacBook Air 13 ab 1.199,00 Euro, begründet die Wahl mit M3-Chip, Display und lüfterlosem Gehäuse, nennt die passende Variante und bietet an, das Gerät in den Warenkorb zu legen](docs/images/07-storefront-de.webp)](https://demo.emporiqa.com)

- **Integration im Überblick**: [emporiqa.com/de/integrations/shopware/](https://emporiqa.com/de/integrations/shopware/)
- **Vollständige Dokumentation**: [emporiqa.com/de/docs/shopware/](https://emporiqa.com/de/docs/shopware/) (Referenz des Webhook-Formats, Referenz zu CLI und Admin-API, Beispiele zu Events, Fehlersuche)
- **Funktionen**: [emporiqa.com/de/features/](https://emporiqa.com/de/features/) · **FAQ**: [emporiqa.com/de/faq/](https://emporiqa.com/de/faq/) · **Preise**: [emporiqa.com/de/pricing/](https://emporiqa.com/de/pricing/)
- **Live-Demo**: [demo.emporiqa.com](https://demo.emporiqa.com) und ein [30-Sekunden-Video](https://www.youtube.com/watch?v=7oREWt4mPB8). Diese Demo verkauft Elektronik, und das Verhalten ist bei jedem Katalog dasselbe.

## Voraussetzungen

- Shopware 6.6 oder 6.7
- PHP 8.2+
- Ein [Emporiqa-Konto](https://emporiqa.com/platform/create-store/). Anmeldung ohne Karte; 25 $ Startguthaben (rund 100 kostenfreie Konversationen) werden automatisch gutgeschrieben

## Installation

### Über die Erweiterungsverwaltung

1. Laden Sie die neueste Datei `EmporiqaIntegration-x.y.z.zip` von der Seite [Releases](https://github.com/emporiqa/shopware/releases) herunter.
2. Gehen Sie in der Shopware-Administration zu **Erweiterungen > Meine Erweiterungen > Erweiterung hochladen** und laden Sie die ZIP-Datei hoch.
3. Installieren und aktivieren Sie die Erweiterung.

### Über Composer (selbst gehostet)

```bash
composer require emporiqa/shopware-plugin
bin/console plugin:refresh
bin/console plugin:install --activate EmporiqaIntegration
bin/console cache:clear
```

### Verbinden und synchronisieren

Nach der Installation über einen der beiden Wege:

1. Öffnen Sie die Emporiqa-Erweiterung und klicken Sie auf **Mit Emporiqa verbinden**. Es öffnet sich ein neuer Tab auf emporiqa.com. Legen Sie ein kostenfreies Konto an (keine Karte erforderlich, 25 $ Startguthaben) oder melden Sie sich an, falls Sie bereits eines haben, und wählen Sie dann den Shop aus, den Sie verbinden möchten (oder legen Sie einen neuen an). Wenn Sie zurückkehren, ist das Plugin verbunden.
2. Klicken Sie im Tab **Synchronisierung** auf **Alles synchronisieren**. Produkte und Seiten werden übertragen; das Widget erscheint in Ihrer Storefront, sobald das erste Produkt angekommen ist.

**Läuft Ihr Shop über HTTP, oder tragen Sie die Zugangsdaten lieber selbst ein?** Tragen Sie in den Verbindungseinstellungen eine **Shop-ID** und ein **Webhook-Geheimnis** ein. Diese finden Sie in Ihrem Emporiqa-Dashboard unter **Settings → Integration**; das Dashboard ist englischsprachig. Beide Wege führen zum selben Ergebnis.

Kopieren Sie für die Bestellverfolgung die auf der Einstellungsseite angezeigte **Bestellverfolgungs-URL** und tragen Sie sie in Ihrem Emporiqa-Dashboard unter **Integration → Order tracking** ein (die URL wird auf den meisten Installationen auch von der One-Click-Verbindung automatisch abgeleitet).

## Konfiguration

Alle Einstellungen werden auf der Konfigurationsseite des Plugins verwaltet (**Erweiterungen > Meine Erweiterungen > Emporiqa > ⋯ > Einstellungen**):

**Verbindungseinstellungen**

Empfohlen ist der Weg über **Mit Emporiqa verbinden** (One-Click-Handshake, Sie müssen nichts einfügen). Für Shops über HTTP oder für die manuelle Einrichtung tragen Sie die Werte von Hand ein:

| Einstellung | Beschreibung | Standard |
|-------------|--------------|----------|
| Shop-ID | Ihre Emporiqa-Shop-Kennung (wird von der One-Click-Verbindung automatisch eingetragen) | (leer) |
| Webhook-Geheimnis | Signaturschlüssel für HMAC-SHA256 (wird von der One-Click-Verbindung automatisch eingetragen) | (leer) |
| Bestellverfolgungs-URL | Schreibgeschützter Endpunkt zum Eintragen in Ihr Emporiqa-Dashboard | automatisch erzeugt |

**Erweitert**

| Einstellung | Beschreibung | Standard |
|-------------|--------------|----------|
| Produkte synchronisieren | Echtzeit-Produktsynchronisierung aktivieren | An |
| Seiten synchronisieren | Echtzeit-Synchronisierung von CMS-Seiten aktivieren | An |
| Webhook-URL | Emporiqa-Webhook-Endpunkt | `https://emporiqa.com/webhooks/sync/` |
| Stapelgröße | Produkte bzw. Seiten pro Webhook-Anfrage bei der Massensynchronisierung | 50 |

Die Bestellverfolgung (mit Verifizierung der Kunden-E-Mail-Adresse) und die Warenkorb-Aktionen im Chat sind immer aktiv. Eine Konfiguration ist dafür nicht nötig.

## KI-Hinweis

Die Standardbegrüßung sagt dem Käufer, dass er mit dem KI-Assistenten Ihres Shops spricht, und zwar in jeder Sprache, die der Chat beherrscht. Eine eigene Begrüßung ohne diesen Hinweis wird beim Speichern abgelehnt. Abschnitt 8.6 der [Emporiqa-Nutzungsbedingungen](https://emporiqa.com/de/terms-of-service/) wertet das Entfernen des Hinweises, auch per eigenem CSS oder eigenem Code, als Vertragsverletzung.

## Katalog synchron halten

Das Plugin überträgt Produkt-, Seiten- und Bestelländerungen über die Events der Shopware-Datenschicht (DAL) automatisch an Emporiqa, sobald sie eintreten. Reine Bestandsänderungen senden eine kompakte Aktualisierung, die nur die Verfügbarkeit enthält, statt das ganze Produkt neu aufzubauen, und Änderungen an Produktmedien oder Preisen stoßen die Übertragung des betroffenen Produkts eigenständig an.

Manche Änderungen betreffen den gesamten Katalog (Bearbeitung von Kategorien, Herstellern oder Währungen, eine neue Sprache, geänderte Aktionen). Eine synchrone Neusynchronisierung Produkt für Produkt aus diesen Events heraus würde die Admin-Anfrage blockieren. Das Plugin schreibt deshalb eine Warnung mit Handlungshinweis in das Shopware-Log (`var/log/`) und überlässt die Aktualisierung des Katalogs einem manuellen Durchlauf.

Führen Sie im Tab **Synchronisierung** eine vollständige Synchronisierung aus, wenn:

- eine der Warnungen zu katalogweiten Änderungen im Shopware-Log steht
- Sie einen Verkaufskanal hinzufügen oder neu zuweisen (bestehende Produkte tragen die Daten des neuen Kanals erst dann, wenn sie aus anderem Anlass geändert werden)
- Sie Produkte per Massenimport anlegen oder Katalogdaten direkt in die Datenbank schreiben (solche Wege können die Standard-Events umgehen)
- Emporiqa längere Zeit nicht erreichbar war (Netzwerkausfall, geplante Wartung, abgelaufene Zugangsdaten)

Führen Sie zur Sicherheit einmal pro Woche eine vollständige Synchronisierung aus, um Abweichungen aufzufangen, die sich durch Fehler im Hintergrund angesammelt haben könnten.

## Felder der Produkt-Payload

Über die Felder der [Referenz zur Webhook-Payload](https://emporiqa.com/de/docs/shopware/) hinaus enthält die vollständige Payload eines Produkts und einer Variante diese Felder zu Merchandising und Preisgestaltung:

- `tier_prices`: Liste der Mengenstaffeln je Währung (`[{min_quantity, price}]`) aus den erweiterten Regelpreisen von Shopware. Das Feld erscheint an einem Preiseintrag nur dann, wenn für das Produkt oder die Variante Staffeln hinterlegt sind.
- `is_virtual`: Boolescher Wert; `true` für Download-Produkte ohne Versand (aus dem Download-Status von Shopware).
- `condition`: Enthalten, damit die Payloads über alle Plattformen hinweg gleich aufgebaut sind. Shopware kennt kein eigenes Feld für den Produktzustand, daher wird fest `null` gesendet.
- `available_for_order`: Enthalten, damit die Payloads über alle Plattformen hinweg gleich aufgebaut sind. Shopware kennt kein eigenes Kennzeichen für einen reinen Anzeige- oder Katalogmodus, daher wird fest `true` gesendet.

Diese Felder gehören zur vollständigen Payload von Produkt und Variante, nicht zum schlanken `product.availability`-Event, das nur die Identifikationsnummer, die SKU, den Verfügbarkeitsstatus je Kanal und die Lagermengen enthält.

## Aufbau des Plugins

```
EmporiqaIntegration/
├── src/
│   ├── EmporiqaIntegration.php          # Plugin-Lebenszyklus (Aufräumen bei Deinstallation: Konfiguration + Bestellmarkierungen)
│   ├── Command/                         # CLI: sync:products, sync:pages, sync:all, test-connection
│   ├── Controller/
│   │   ├── Admin/SyncController.php     # Admin-Endpunkte für Synchronisierung und Datenvorschau
│   │   ├── Admin/ConnectController.php  # Endpunkte der One-Click-Verbindung (PKCE)
│   │   ├── CartController.php           # Warenkorb-API für den Chat
│   │   └── OrderTrackingController.php  # HMAC-signierter Endpunkt für die Bestellverfolgung
│   ├── Service/
│   │   ├── SyncService.php              # Steuerung der Massensynchronisierung + Kanalkontexte
│   │   ├── ProductFormatter.php         # Aufbereitung der Produkt- und Varianten-Payloads
│   │   ├── CmsPageFormatter.php         # Aufbereitung der Payloads für Landing- und Kategorieseiten
│   │   ├── ChannelResolver.php          # Zuordnung von Verkaufskanälen zu Emporiqa-Kanälen
│   │   ├── ConnectService.php           # Handshake der One-Click-Verbindung (PKCE)
│   │   ├── ConfigService.php            # Zugriff auf die Einstellungen
│   │   └── WebhookClient.php            # HTTP-Client für HMAC-SHA256-signierte Webhooks
│   ├── Subscriber/                      # Listener für DAL-Events in Echtzeit
│   ├── MessageQueue/                    # Asynchrone Handler für Webhooks und vollständige Synchronisierung
│   ├── Event/                           # Erweiterungs-Events (Payload- und Widget-Hooks)
│   └── Resources/
│       ├── app/administration/          # Admin-Oberfläche (Sync-Dashboard, Einstellungen, One-Click-Verbindung)
│       ├── app/storefront/              # Widget-Einbindung und Storefront-Plugins für den Warenkorb
│       └── config/                      # config.xml, Services, Snippets
├── composer.json
└── CHANGELOG.md
```

## Registrierte Subscriber

| Subscriber | Zweck |
|------------|-------|
| `StorefrontSubscriber` | Bindet das Chat-Widget in die Storefront ein (`StorefrontRenderEvent`) |
| `ProductSubscriber` | Synchronisiert Produkte beim Schreiben und Löschen, löscht variantengenau und synchronisiert bei Medien- oder Preisänderungen erneut |
| `ProductSubscriber` (Verfügbarkeit) | Sendet bei Bestandsänderungen ein schlankes `product.availability`-Event (`ProductStockAlteredEvent`), ohne vollständigen Neuaufbau |
| `LandingPageSubscriber` | Synchronisiert Landingpages beim Anlegen, Ändern und Löschen |
| `CategorySubscriber` | Synchronisiert Kategorie-Shopseiten beim Anlegen, Ändern und Löschen |
| `OrderSubscriber` | Erfasst die Chat-Session und sendet `order.completed` bei Bestellabschluss und bei abschließenden Statuswechseln |
| `CatalogChangeSubscriber` | Schreibt bei katalogweiten Änderungen (Kategorie, Hersteller, Währung, Sprache, Aktion, Steuer, Preisregel) eine Warnung mit Handlungshinweis, damit der Händler eine vollständige Synchronisierung ausführen kann |

## Erweiterbarkeit

Entwickler können Symfony-Events abonnieren, um Payloads oder das Verhalten des Widgets anzupassen:

| Event | Zweck |
|-------|-------|
| `PostProductFormatEvent` | Produkt- bzw. Varianten-Payload vor dem Senden ändern |
| `PostPageFormatEvent` | Seiten-Payload vor dem Senden ändern |
| `PostOrderFormatEvent` | Bestell-Payload vor dem Senden ändern |
| `OrderTrackingResponseEvent` | Antwort der Bestellverfolgung ändern |
| `WidgetParamsEvent` | Einbindungsparameter des Chat-Widgets ändern |
| `PreSyncEvent` / `PostSyncEvent` | Logik vor und nach einem Lauf der Massensynchronisierung ausführen |

Jeder Service ist gegen ein Interface definiert (`ProductFormatterInterface`, `CmsPageFormatterInterface`, `SyncServiceInterface`, `WebhookClientInterface`, `ChannelResolverInterface`, `ConfigServiceInterface`, `ConnectServiceInterface`), sodass sich jeder davon mit einem gewöhnlichen Symfony-Service-Decorator dekorieren lässt.

## Preise

Das Plugin ist kostenfrei. Emporiqa selbst rechnet nutzungsbasiert ab: 0 $/Monat Grundgebühr plus 0,25 $ pro Konversation, mit 25 $ Startguthaben und ohne Karte bei der Anmeldung. Alle Preisangaben unter [emporiqa.com/de/pricing/](https://emporiqa.com/de/pricing/).

## Support

Schreiben Sie an support@emporiqa.com.

## Lizenz

[MIT](https://opensource.org/licenses/MIT)
