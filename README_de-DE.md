# Emporiqa: KI-Chatbot für Shopware 6

*[English version](README.md)*

Der KI-Chatbot [Emporiqa](https://emporiqa.com) für Shopware 6 ist ein Online-Verkäufer, der in Ihrem Shop für Sie verkauft: Käufer beschreiben, was sie brauchen, oder laden ein Foto von etwas hoch, das ihnen gefällt, er findet passende Produkte aus Ihrem Katalog, behandelt Einwände wie „zu teuer“ mit Alternativen statt mit einem Rabatt, beantwortet Fragen anhand Ihrer CMS-Seiten und führt Käufer in 65+ Sprachen zum Warenkorb und zur Kasse. Dieses Plugin synchronisiert Ihren Produktkatalog und Ihre CMS-Seiten mit Emporiqa, bindet das Chat-Widget in Ihre Storefront ein und stellt Endpunkte für Warenkorb-Aktionen im Chat und für die Bestellverfolgung bereit.

[![Geöffnetes Emporiqa-Chat-Widget in einer Storefront. Auf die Frage „Welche Kopfhörer mit Geräuschunterdrückung haben Sie für lange Flüge, unter 400 Euro?“ nennt es den Sennheiser Momentum 4 mit bis zu 60 Stunden Akkulaufzeit mit ANC, den Sony WH-1000XM5 mit 30 Stunden und den Beats Studio Pro mit 24 Stunden und zeigt Sennheiser und Sony als Produktkarten mit Foto, Preis und Warenkorb-Button, darunter ein Eingabefeld mit Foto- und Sprach-Button](docs/images/lead-answer-de.webp)](https://demo.emporiqa.com)

- **Integration im Überblick**: [emporiqa.com/de/integrations/shopware/](https://emporiqa.com/de/integrations/shopware/)
- **Vollständige Dokumentation**: [emporiqa.com/de/docs/shopware/](https://emporiqa.com/de/docs/shopware/) (Referenz des Webhook-Formats, Referenz zu CLI und Admin-API, Beispiele zu Events, Fehlersuche)
- **Funktionen**: [emporiqa.com/de/features/](https://emporiqa.com/de/features/) · **FAQ**: [emporiqa.com/de/faq/](https://emporiqa.com/de/faq/) · **Preise**: [emporiqa.com/de/pricing/](https://emporiqa.com/de/pricing/)
- **Live-Demo**: [demo.emporiqa.com](https://demo.emporiqa.com) und ein [30-Sekunden-Video](https://www.youtube.com/watch?v=qck3AqYSFQw). Diese Demo verkauft Elektronik, und das Verhalten ist bei jedem Katalog dasselbe.

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

**Bestellstatus.** Sobald Emporiqa Ihrem Shop fertige Regeln anbietet (nach dem Verbinden oder einem Verbindungstest), zeigt der Tab „Einstellungen“ die Karte **Fertige Regeln** mit der **Adresse für den Bestellstatus**. Klicken Sie neben „Order status“ auf **In Emporiqa öffnen** und dann in Emporiqa auf **Try it**, um die Regel zu testen, und auf **Go live**, um sie einzuschalten (das Dashboard ist englischsprachig). Sie müssen nichts kopieren: Emporiqa trägt die Adresse Ihres Shops beim Verbinden mit einem Klick selbst ein. Falls Emporiqa doch einmal danach fragt, verwenden Sie die Adresse auf der Karte (mit Kopieren-Schaltfläche). Die Regel beantwortet „Wo ist meine Bestellung?“ aus Ihren Shopware-Bestellungen: Ein angemeldeter Kunde nennt nur die Bestellnummer, ein Gast zusätzlich die E-Mail-Adresse der Bestellung. Ist nachgewiesen, dass die Bestellung ihm gehört, nennt der Chat Status, Datum, Sendungsverfolgung, Lieferzeit, Artikel und Gesamtbetrag und auf Nachfrage Zahlungs-, Liefer- und Rechnungsdaten.

**Kundeninfo.** Für einen angemeldeten Kunden beantwortet das Plugin außerdem den signierten Kundeninfo-Aufruf von Emporiqa: Name und E-Mail-Adresse seines Kundenkontos und seine 10 neuesten Bestellungen (Nummer, Datum, Status und Gesamtbetrag), damit der Chat „Wo ist meine Bestellung?“ beantworten kann, ohne nach der Nummer zu fragen. Enthalten sind nur die eigenen Bestellungen des Kontos in den Verkaufskanälen, die mit Ihrem Emporiqa-Shop synchronisiert werden; eine Gastbestellung mit derselben E-Mail-Adresse gehört nicht dazu. Einzurichten ist nichts: Emporiqa nutzt sie, sobald der Chat sie unterstützt.

Die ältere Bestellverfolgung funktioniert weiter wie bisher. Wo fertige Regeln angeboten werden, steht sie unter **Erweitert** als *Alte Bestellverfolgung (veraltet)*: Sobald „Order status“ eingeschaltet ist, entfernen Sie ihre Adresse in Ihrem Emporiqa-Dashboard (**Settings > Integration > For your developer > Order tracking API URL**, das Dashboard ist englischsprachig) und schalten Sie sie dann aus.

## Konfiguration

Alle Einstellungen werden auf der Konfigurationsseite des Plugins verwaltet (**Erweiterungen > Meine Erweiterungen > Emporiqa > ⋯ > Einstellungen**):

**Verbindungseinstellungen**

Empfohlen ist der Weg über **Mit Emporiqa verbinden** (One-Click-Handshake, Sie müssen nichts einfügen). Für Shops über HTTP oder für die manuelle Einrichtung tragen Sie die Werte von Hand ein:

| Einstellung | Beschreibung | Standard |
|-------------|--------------|----------|
| Shop-ID | Ihre Emporiqa-Shop-Kennung (wird von der One-Click-Verbindung automatisch eingetragen) | (leer) |
| Webhook-Geheimnis | Signaturschlüssel für HMAC-SHA256 (wird von der One-Click-Verbindung automatisch eingetragen) | (leer) |
| Bestellverfolgungs-URL | Schreibgeschützter Endpunkt der alten Bestellverfolgung (hier angezeigt, bis fertige Regeln angeboten werden) | automatisch erzeugt |

**Erweitert**

| Einstellung | Beschreibung | Standard |
|-------------|--------------|----------|
| Produkte synchronisieren | Echtzeit-Produktsynchronisierung aktivieren | An |
| Seiten synchronisieren | Echtzeit-Synchronisierung von CMS-Seiten aktivieren | An |
| Webhook-URL | Emporiqa-Webhook-Endpunkt | `https://emporiqa.com/webhooks/sync/` |
| Stapelgröße | Produkte bzw. Seiten pro Webhook-Anfrage bei der Massensynchronisierung | 50 |

Der Tab **Synchronisierung** zeigt, wie viele Produkte und Seiten eine Synchronisierung sendet: Produkte, die in einem synchronisierten Verkaufskanal sichtbar sind, und Seiten, die dort erreichbar sind (eine Kategorieseite nur mit eigenem Text, die Startseite nur, wenn sie überhaupt Text hat).

**Headless-Verkaufskanäle** (Typ Headless/API) werden nicht synchronisiert: Shopware erzeugt für sie keine Produktadressen (SEO-URLs), daher hätte der Chat keine Produktlinks. Storefront-Verkaufskanäle werden wie gewohnt synchronisiert. Der Verbindungstest und der Tab „Synchronisierung“ nennen jeden aktiven Headless-Kanal, in dem Produkte sichtbar sind. Das Chat-Widget kommt mit dem Storefront-Theme; auf einem Frontend, das Shopware nicht ausliefert, binden Sie es mit dem [Einbettungscode](https://emporiqa.com/docs/widget-embedding/) ein.

Die Warenkorb-Aktionen im Chat sind immer aktiv. Die Identität eines angemeldeten Kunden erreicht den Chat nur über einen nicht zwischengespeicherten Endpunkt beim Öffnen des Chats, nie über die Seite oder die Widget-URL. Die alte Bestellverfolgung steht, wo fertige Regeln angeboten werden, mit einem Schalter unter „Erweitert“ (standardmäßig eingeschaltet).

## KI-Hinweis

Die Standardbegrüßung sagt dem Käufer, dass er mit dem KI-Assistenten Ihres Shops spricht, und zwar in jeder Sprache, die der Chat beherrscht. Eine eigene Begrüßung ohne diesen Hinweis wird beim Speichern abgelehnt. Abschnitt 8.6 der [Emporiqa-Nutzungsbedingungen](https://emporiqa.com/de/terms-of-service/) wertet das Entfernen des Hinweises, auch per eigenem CSS oder eigenem Code, als Vertragsverletzung.

## Katalog synchron halten

Das Plugin überträgt Produkt-, Seiten- und Bestelländerungen über die Events der Shopware-Datenschicht (DAL) automatisch an Emporiqa, sobald sie eintreten. Reine Bestandsänderungen senden eine kompakte Aktualisierung, die nur die Verfügbarkeit enthält, statt das ganze Produkt neu aufzubauen, und Änderungen an Produktmedien oder Preisen stoßen die Übertragung des betroffenen Produkts eigenständig an.

Manche Änderungen betreffen den gesamten Katalog (Bearbeitung von Kategorien, Herstellern oder Währungen, eine neue Sprache, geänderte Aktionen). Eine synchrone Neusynchronisierung Produkt für Produkt aus diesen Events heraus würde die Admin-Anfrage blockieren. Das Plugin schreibt deshalb eine Warnung mit Handlungshinweis in das Shopware-Log (`var/log/`) und überlässt die Aktualisierung des Katalogs einem manuellen Durchlauf.

Ist Emporiqa nicht erreichbar oder antwortet mit einem Serverfehler, wird eine Änderung nach 1, 5 und 15 Minuten und danach stündlich erneut gesendet, insgesamt sechsmal (etwa 2 Stunden 20 Minuten), sodass bei einem kurzen Ausfall nichts verloren geht. Erreicht eine neuere Speicherung desselben Produkts oder derselben Seite Emporiqa zuerst, wird die ältere Fassung nicht darüber gesendet. Eine Änderung, die Emporiqa ablehnt (zum Beispiel nach einem geänderten Webhook-Geheimnis), wird nicht wiederholt; sie wird protokolliert und in Shopwares Warteschlange `failed` aufbewahrt, aus der `bin/console messenger:failed:retry` sie erneut sendet, sobald die Ursache behoben ist.

Zeitlich begrenzte Aktionen (erweiterte Preise einer Regel mit Zeitraum) erscheinen im Chat, wenn sie beginnen, und verschwinden, wenn sie enden: Eine geplante Aufgabe synchronisiert die betroffenen Produkte alle 15 Minuten neu. Ihr Shop muss dafür die geplanten Aufgaben von Shopware ausführen (`bin/console scheduled-task:run` oder den Admin-Worker). Preise aus Regeln nach Uhrzeit oder Wochentag werden nie übertragen; der Chat nennt den Preis, der außerhalb dieser Zeiten gilt.

Führen Sie im Tab **Synchronisierung** eine vollständige Synchronisierung aus, wenn:

- eine der Warnungen zu katalogweiten Änderungen im Shopware-Log steht
- Sie einen Verkaufskanal hinzufügen oder neu zuweisen (bestehende Produkte tragen die Daten des neuen Kanals erst dann, wenn sie aus anderem Anlass geändert werden)
- Sie Produkte per Massenimport anlegen oder Katalogdaten direkt in die Datenbank schreiben (solche Wege können die Standard-Events umgehen)
- Emporiqa länger als etwa zwei Stunden nicht erreichbar war oder Änderungen abgelehnt hat (Netzwerkausfall, geplante Wartung, abgelaufene Zugangsdaten)

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
│   │   ├── ActionController.php         # Fertige Regeln: Bestellstatus, Kundenpreise, Kundeninfo + Adressprüfung (beidseitig signiert)
│   │   ├── UserTokenController.php      # Nicht zwischengespeichertes Kunden-Token für den Chat
│   │   └── OrderTrackingController.php  # Alter HMAC-signierter Endpunkt der Bestellverfolgung (veraltet)
│   ├── Service/
│   │   ├── SyncService.php              # Steuerung der Massensynchronisierung + Kanalkontexte
│   │   ├── ProductFormatter.php         # Aufbereitung der Produkt- und Varianten-Payloads
│   │   ├── CmsPageFormatter.php         # Aufbereitung der Payloads für Landing- und Kategorieseiten
│   │   ├── ChannelResolver.php          # Zuordnung von Verkaufskanälen zu Emporiqa-Kanälen
│   │   ├── ConnectService.php           # Handshake der One-Click-Verbindung (PKCE)
│   │   ├── ConfigService.php            # Zugriff auf die Einstellungen
│   │   └── WebhookClient.php            # HTTP-Client für HMAC-SHA256-signierte Webhooks
│   ├── Subscriber/                      # Listener für DAL-Events in Echtzeit
│   ├── ScheduledTask/                   # Synchronisiert Produkte neu, wenn eine Aktion beginnt oder endet
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
| `OrderStatusResponseEvent` | Antwort der Regel „Order status“ (`data`) ändern |
| `CustomerInfoResponseEvent` | Kundeninfo-Antwort (`data`) ändern: Felder entfernen oder `extra` hinzufügen |
| `OrderTrackingResponseEvent` | Antwort der alten Bestellverfolgung ändern |
| `WidgetParamsEvent` | Einbindungsparameter des Chat-Widgets ändern |
| `PreSyncEvent` / `PostSyncEvent` | Logik vor und nach einem Lauf der Massensynchronisierung ausführen |

**Eigene Felder in der Antwort von „Order status“.** `OrderStatusResponseEvent` läuft, nachdem das Plugin `data` gefüllt hat (Status, Daten, Sendungsverfolgung, Bestellnummer, Kundenname, Währung, Artikel, Summen, Zahlungs- und Versandart, Zahlungsstatus, Lieferzeit, Liefer- und Rechnungsadresse), sodass ein Subscriber jeden Wert ändern kann. Eigene Felder gehören unter `extra`: Jeden anderen Schlüssel, den Emporiqa nicht kennt, verwirft Emporiqa. `extra` nimmt Schlüssel als Text mit Text-, Zahlen- oder Ja/Nein-Werten oder verschachtelten Listen und Objekten an, höchstens 3 Ebenen tief, insgesamt 30 Schlüssel, Texte bis 500 Zeichen. Der Chat verwendet sie, wenn der Kunde danach fragt. Fügen Sie nur hinzu, was der Kunde sehen darf: Die Antwort kommt erst, nachdem er nachgewiesen hat, dass die Bestellung ihm gehört.

```php
public static function getSubscribedEvents(): array
{
    return [OrderStatusResponseEvent::class => 'onOrderStatus'];
}

public function onOrderStatus(OrderStatusResponseEvent $event): void
{
    $data = $event->getData();
    $data['extra'] = [
        'gift_wrap' => true,
        'pickup_point' => 'Filiale Berlin-Mitte',
    ];
    $event->setData($data);
}
```

**Die Kundeninfo-Antwort ändern.** `CustomerInfoResponseEvent` läuft, nachdem das Plugin `data` gefüllt hat: `customer` (`name`, `first_name`, `last_name`, `email`) und `orders` (bis zu 10, die neueste zuerst, jeweils `order_number`, `placed_at`, `status_code`, `status_label`, `total`, `currency`). `getCustomer()` und `getOrders()` liefern die geladenen Entitäten. Entfernen Sie, was Sie nicht teilen möchten, oder fügen Sie unter `extra` eigene Felder hinzu (mit denselben Grenzen wie bei „Order status“). Die Antwort betrifft nur den angemeldeten Kunden; fügen Sie nichts über andere Personen hinzu.

```php
public static function getSubscribedEvents(): array
{
    return [CustomerInfoResponseEvent::class => 'onCustomerInfo'];
}

public function onCustomerInfo(CustomerInfoResponseEvent $event): void
{
    $data = $event->getData();
    unset($data['customer']['email']);
    $data['extra'] = ['loyalty_tier' => 'Gold'];
    $event->setData($data);
}
```

Jeder Service ist gegen ein Interface definiert (`ProductFormatterInterface`, `CmsPageFormatterInterface`, `SyncServiceInterface`, `WebhookClientInterface`, `ChannelResolverInterface`, `ConfigServiceInterface`, `ConnectServiceInterface`), sodass sich jeder davon mit einem gewöhnlichen Symfony-Service-Decorator dekorieren lässt. Ein Decorator von `WebhookClientInterface`, der das Wiederholungsverhalten erhalten soll, implementiert zusätzlich `TransientFailureAwareInterface` (und reicht `isLastFailureTransient()` durch); ohne das wird jeder fehlgeschlagene Versand wiederholt, als wäre Emporiqa nicht erreichbar.

## Preise

Das Plugin ist kostenfrei. Emporiqa selbst rechnet nutzungsbasiert ab: 0 $/Monat Grundgebühr plus 0,25 $ pro Konversation, mit 25 $ Startguthaben (rund 100 Konversationen) und ohne Karte bei der Anmeldung. Ist das Startguthaben aufgebraucht, gilt eine monatliche Kostenobergrenze von standardmäßig 59 $, die Sie im Abrechnungsbereich selbst ändern können. Der Sprachmodus (sprechen statt tippen: der Kunde spricht und bekommt die Antwort vorgelesen) ist optional und standardmäßig ausgeschaltet. Spricht der Kunde, kommen einmalig 0,15 $ für dieses Gespräch hinzu, die auf die Obergrenze angerechnet werden. Preise verstehen sich zzgl. MwSt. Für Kataloge über 100.000 Produkte gibt es ein Enterprise-Angebot. Alle Preisangaben unter [emporiqa.com/de/pricing/](https://emporiqa.com/de/pricing/).

## Support

Schreiben Sie an support@emporiqa.com.

## Lizenz

[MIT](https://opensource.org/licenses/MIT)
