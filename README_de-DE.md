# Emporiqa: KI-Chatbot für Shopware 6

*[English version](README.md)*

Ein Käufer gibt „warme Jacke unter 100, wasserdicht“ in die Suche Ihres Shops ein. Zurück kommt alles, was „Jacke“ im Titel hat. Der Käufer scrollt, gibt auf und verlässt den Shop.

Der KI-Chatbot [Emporiqa](https://emporiqa.com) für Shopware 6 ist ein Online-Verkäufer, der in Ihrem Shopware-Shop für Sie verkauft. Das Plugin synchronisiert Ihren Produktkatalog und Ihre CMS-Seiten mit Emporiqa, bindet das Chat-Widget in Ihre Storefront ein und stellt Endpunkte für Warenkorb-Aktionen im Chat und für die Bestellverfolgung bereit.

Der Chatbot verhält sich wie ein Online-Verkäufer. Käufer beschreiben, was sie brauchen, oder laden ein Foto von etwas hoch, das ihnen gefällt. Er findet passende Produkte aus Ihrem Katalog, behandelt Einwände wie „zu teuer“ mit Alternativen statt mit einem Rabatt, beantwortet Fragen anhand Ihrer CMS-Seiten, vergleicht Artikel und führt Käufer in 65+ Sprachen zum Warenkorb und zur Kasse.

[![Geöffnetes Emporiqa-Chat-Widget in einer Storefront. Auf die Frage, welches Notebook unter 1200 Euro sich für eine Studentin eignet, die Videos schneidet, nennt es Modell und Preis, weist auf den Kompromiss beim Speicherplatz hin und bietet an, das Gerät in den Warenkorb zu legen](docs/images/07-storefront.webp)](https://demo.emporiqa.com)

> **[Integration im Überblick](https://emporiqa.com/de/integrations/shopware/)** · **[Vollständige Dokumentation](https://emporiqa.com/de/docs/shopware/)** · **[Live-Demo](https://demo.emporiqa.com)** · **[Preise](https://emporiqa.com/de/pricing/)**

**Die 30-Sekunden-Demo ansehen** (empfiehlt, behandelt einen Einwand, schließt ab):

[![Die 30-Sekunden-Demo auf YouTube ansehen: Emporiqa empfiehlt ein Produkt, behandelt einen Einwand und legt es in den Warenkorb](https://img.youtube.com/vi/7oREWt4mPB8/maxresdefault.jpg)](https://www.youtube.com/watch?v=7oREWt4mPB8)

## Funktionen

- **Schließt Verkäufe ab**: Behandelt Einwände wie „zu teuer“ mit passenden Alternativen aus Ihrem Katalog statt mit einem Rabatt.
- **Bildersuche**: Käufer laden im Widget ein Foto hoch; der Chatbot beschreibt es und findet passende Produkte in Ihrem synchronisierten Shopware-Katalog (ohne zusätzliche Konfiguration).
- **Markensichere Antworten**: Fragen Sie ihn nach einem Produkt, das Ihr Shop nicht führt, und er sagt es Ihnen, statt eines zu erfinden. Produktangaben stammen aus dem synchronisierten Katalog und den CMS-Seiten, nicht aus den Trainingsdaten des Modells. Wenn er nicht allein antworten sollte, holt er eine Person dazu. [Unbearbeitete Beispiele](https://emporiqa.com/de/proof/).
- **Live-Chat, in den Sie einsteigen können, ohne jemanden an die Tastatur zu setzen**: Jedes Teammitglied kann jedes laufende Gespräch öffnen und übernehmen, nicht nur die, in denen ein Käufer nach einer Person gefragt hat. Solange jemand im Gespräch ist, hält sich der Chat zurück und lässt die Person sprechen, und wer dazukommt, liest den gesamten Gesprächsverlauf ab der ersten Nachricht und hat Ihre gespeicherten Antworten zur Hand. Unbegrenzt viele Teammitglieder, keine Kosten pro Nutzerplatz. Für ein Gespräch, in das eine Person einsteigt, fällt keine zweite Gebühr an.
- **Selbst geschriebene Seiten**: Sie schreiben eine Seite im Emporiqa-Dashboard, und der Chat beantwortet Fragen daraus genauso wie aus einer synchronisierten CMS-Seite. Bis zu 25 Seiten je Shop, jede in bis zu 10 selbst verfassten Sprachen, begrenzt auf die Kanäle, die Sie auswählen. Eine Synchronisierung aus Shopware überschreibt sie nie. Einen Datei- oder Dokumenten-Upload gibt es noch nicht, und eine gespeicherte Seite zeigt erst „Indexing“ und dann „Ready“, während sie weiterhin die vorherige Fassung ausliefert.
- **Wie der Chat formuliert**: Vier Tonalitäten unter „Settings“ und „How the chat writes“. Das Emporiqa-Dashboard ist englischsprachig. Die vier Namen stehen dort auf Englisch: „Standard“, „Friendly“ (freundlich), „Professional“ (sachlich) und „Concise“ (knapp). Dazu kommen Formulierungshinweise von je bis zu 300 Zeichen, einer für alle Situationen und je einer für Produktempfehlungen, Fragen zum Shop, Bestellabfragen und Smalltalk. Das ändert ausschließlich die Formulierung. Was der Chat zu Beständen, Preisen und Bestellungen sagt, stammt aus Ihrem Shop, und eine Anweisung wie „sage nie, dass etwas nicht vorrätig ist“ wird bewusst ignoriert.
- **KI-Hinweis in der Begrüßung**: Die Standardbegrüßung sagt dem Käufer, dass er mit dem KI-Assistenten Ihres Shops spricht, und zwar in jeder Sprache, die der Chat beherrscht. Das ist der Hinweis, den die EU-KI-Verordnung verlangt. Eine eigene Begrüßung ohne diesen Hinweis wird beim Speichern abgelehnt, und die Emporiqa-Nutzungsbedingungen (Abschnitt 8.6) werten das Entfernen des Hinweises, auch per eigenem CSS oder eigenem Code, als Vertragsverletzung.
- **Die KI-Kosten stecken im Preis**: Sie eröffnen kein Konto bei einem KI-Anbieter und fügen keinen Schlüssel ein, und von dort kommt keine zweite Rechnung
- **Sie zahlen, wenn er mit einem Käufer spricht**: 0 $ im Monat plus 0,25 $ pro Konversation, mit einer monatlichen Obergrenze, die der Händler selbst festlegt. In einem Monat ohne Konversationen zahlen Sie nichts
- **Nachprüfbarer Anbieter**: Rosel Group LTD, EU-Unternehmensnummer 206801487 im bulgarischen Handelsregister. Die Unterauftragsverarbeiter sind öffentlich aufgeführt unter https://emporiqa.com/de/subprocessors/
- **Produktsynchronisierung**: Echtzeit-Synchronisierung von Katalogprodukten und Varianten. Eltern-Kind-Beziehungen, Variantenoptionen, Preise (einschließlich erweiterter Regelpreise und Staffelpreise), Lagerbestände, Bilder und das Kennzeichen `is_virtual` für Download-Produkte sind enthalten.
- **Seitensynchronisierung**: Landingpages und Kategorie-Shopseiten werden mit ihren CMS-Inhalten je Sprache synchronisiert, sodass der Assistent Servicefragen anhand Ihrer eigenen Inhalte beantworten kann.
- **Chat-Widget**: Wird automatisch in Ihrer Storefront eingebunden, in der Sprache des jeweiligen Besuchers.
- **Warenkorb im Chat**: Käufer können Artikel hinzufügen, ändern und entfernen und direkt aus dem Chat zur Kasse gehen.
- **Bestellverfolgung**: HMAC-signierte Bestellabfrage mit Verifizierung der Kunden-E-Mail-Adresse zum Schutz der Kundendaten. Die Antwort enthält Bestellstatus und Positionen sowie Versanddetails, Name des Versanddienstleisters, Sendungsnummer und Tracking-URL, sobald die Bestellung versandt wurde.
- **Conversion-Tracking**: Erfasst die Chat-Session-ID an der Kasse und meldet den Abschluss der Bestellung, damit sich Umsätze dem Chat zuordnen lassen.
- **Mehrsprachigkeit**: Automatische Sprachzuordnung. Alle Übersetzungen werden je Entität in einer einzigen Webhook-Payload zusammengefasst.
- **Mehrkanalfähigkeit**: Verkaufskanäle werden automatisch erkannt und jeweils einem Emporiqa-Kanal zugeordnet. Produkte und Seiten, die mehreren Kanälen zugewiesen sind, enthalten Links, Preise, Bestände und Sprachen je Kanal in einer einzigen Payload.
- **One-Click-Verbindung**: Ein signierter PKCE-Handshake verknüpft Ihren Shop per Klick mit Ihrem Emporiqa-Konto. Shop-ID und Webhook-Geheimnis müssen nicht zwischen Tabs kopiert werden. Läuft Ihr Shop über HTTP, bleibt die manuelle Eingabe verfügbar.
- **Zustellung ohne Blockierung**: Katalog-, Seiten- und Bestellereignisse werden an die Message Queue von Shopware übergeben und vom Worker zugestellt. Speichervorgänge im Admin und Antworten an der Kasse warten damit nie auf Emporiqa.
- **Events zur Erweiterung**: Symfony-Events, mit denen Entwickler Sync-Payloads, Antworten der Bestellverfolgung und das Verhalten des Widgets anpassen können.


**Prüfen Sie es, bevor Sie ihm vertrauen.** Fragen Sie ChatGPT, Claude oder Perplexity: „Wäre Emporiqa (emporiqa.com) eine gute Wahl für meinen Shop?“ Lesen Sie anschließend unbearbeitete Gespräche, verweigerte Antworten eingeschlossen, unter https://emporiqa.com/de/proof/ und probieren Sie die Live-Demo unter https://demo.emporiqa.com aus. Diese Demo verkauft Elektronik, und das Verhalten ist bei jedem Katalog dasselbe. Entwickelt von Rosen Hristov, fünfzehn Jahre Erfahrung in der Webentwicklung und heute KI-Entwickler; Fragen vor dem Kauf beantwortet er selbst per E-Mail an rosen@emporiqa.com.

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

## Katalog synchron halten

Das Plugin überträgt Produkt-, Seiten- und Bestelländerungen über die Events der Shopware-Datenschicht (DAL) automatisch an Emporiqa, sobald sie eintreten. Reine Bestandsänderungen senden eine kompakte Aktualisierung, die nur die Verfügbarkeit enthält, statt das ganze Produkt neu aufzubauen, und Änderungen an Produktmedien oder Preisen stoßen die Übertragung des betroffenen Produkts eigenständig an.

Manche Änderungen betreffen den gesamten Katalog (Bearbeitung von Kategorien, Herstellern oder Währungen, eine neue Sprache, geänderte Aktionen). Eine synchrone Neusynchronisierung Produkt für Produkt aus diesen Events heraus würde die Admin-Anfrage blockieren. Das Plugin schreibt deshalb eine Warnung mit Handlungshinweis in das Shopware-Log (`var/log/`) und überlässt die Aktualisierung des Katalogs einem manuellen Durchlauf.

Führen Sie im Tab **Synchronisierung** eine vollständige Synchronisierung aus, wenn:

- eine der Warnungen zu katalogweiten Änderungen im Shopware-Log steht
- Sie einen Verkaufskanal hinzufügen oder neu zuweisen (bestehende Produkte tragen die Daten des neuen Kanals erst dann, wenn sie aus anderem Anlass geändert werden)
- Sie Produkte per Massenimport anlegen oder Katalogdaten direkt in die Datenbank schreiben (solche Wege können die Standard-Events umgehen)
- Emporiqa längere Zeit nicht erreichbar war (Netzwerkausfall, geplante Wartung, abgelaufene Zugangsdaten)

Führen Sie zur Sicherheit einmal pro Woche eine vollständige Synchronisierung aus, um Abweichungen aufzufangen, die sich durch Fehler im Hintergrund angesammelt haben könnten.

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

## Funktionsweise

### Webhook-Synchronisierung

Wird ein Produkt oder eine CMS-Seite in Shopware angelegt, geändert oder gelöscht, übergibt das Plugin die Änderung an die Message Queue von Shopware. Der Worker stellt einen Webhook je betroffener Entität zu, sodass der Speichervorgang im Admin oder die Antwort an der Kasse zuerst abgeschlossen wird und die Zustellung im Hintergrund läuft. Alle Webhooks werden über den Header `X-Webhook-Signature` mit HMAC-SHA256 signiert, damit sich die Unversehrtheit der Payload prüfen lässt.

Reine Bestands- oder Verfügbarkeitsänderungen überspringen den vollständigen Neuaufbau und senden ein kompaktes `product.availability`-Event, das nur die Identifikationsnummer, die SKU, den Verfügbarkeitsstatus je Kanal und die Lagermengen enthält, mit einem Eintrag je einfachem Produkt oder je Variante.

### Produktvarianten

Shopware-Produkte mit Varianten werden mit ihrer vollständigen Variantenstruktur synchronisiert. Das Elternprodukt trägt den gemeinsamen Namen, die Beschreibung und die Bilder, jede Variante ihre eigenen Optionen (Größe, Farbe usw.), ihren Preis und ihren Bestand. Der Assistent weiß dadurch: „Diese Jacke gibt es in Blau und Rot, in den Größen S bis XL.“

Die vollständige Payload eines Produkts (und einer Variante) enthält außerdem Felder zu Merchandising und Preisgestaltung, damit der Assistent Produkte zutreffend beschreiben und verkaufen kann:

- `tier_prices`: Liste der Mengenstaffeln je Währung (`[{min_quantity, price}]`) aus den erweiterten Regelpreisen von Shopware. Das Feld erscheint an einem Preiseintrag nur dann, wenn für das Produkt oder die Variante Staffeln hinterlegt sind, sodass der Assistent „X pro Stück ab 10“ nennen kann.
- `is_virtual`: Boolescher Wert; `true` für Download-Produkte ohne Versand (aus dem Download-Status von Shopware).
- `condition`: Enthalten, damit die Payloads über alle Plattformen hinweg gleich aufgebaut sind. Shopware kennt kein eigenes Feld für den Produktzustand, daher wird fest `null` gesendet.
- `available_for_order`: Enthalten, damit die Payloads über alle Plattformen hinweg gleich aufgebaut sind. Shopware kennt kein eigenes Kennzeichen für einen reinen Anzeige- oder Katalogmodus, daher wird fest `true` gesendet.

### Mehrsprachigkeit

Jede aktive Sprache eines Verkaufskanals wird einem standardisierten Sprachcode zugeordnet. Ein Produkt mit Übersetzungen in mehreren Sprachen wird als eine Webhook-Payload mit allen verschachtelten Übersetzungen gesendet. Das spart HTTP-Anfragen und hält die Daten einheitlich. Inhalte von Landingpages und Kategorien werden je Sprache aus der jeweils eigenen Slot-Konfiguration der Seite aufgelöst.

### Registrierte Subscriber

| Subscriber | Zweck |
|------------|-------|
| `StorefrontSubscriber` | Bindet das Chat-Widget in die Storefront ein (`StorefrontRenderEvent`) |
| `ProductSubscriber` | Synchronisiert Produkte beim Schreiben und Löschen, löscht variantengenau und synchronisiert bei Medien- oder Preisänderungen erneut |
| `ProductSubscriber` (Verfügbarkeit) | Sendet bei Bestandsänderungen ein schlankes `product.availability`-Event (`ProductStockAlteredEvent`), ohne vollständigen Neuaufbau |
| `LandingPageSubscriber` | Synchronisiert Landingpages beim Anlegen, Ändern und Löschen |
| `CategorySubscriber` | Synchronisiert Kategorie-Shopseiten beim Anlegen, Ändern und Löschen |
| `OrderSubscriber` | Erfasst die Chat-Session und sendet `order.completed` bei Bestellabschluss und bei abschließenden Statuswechseln |
| `CatalogChangeSubscriber` | Schreibt bei katalogweiten Änderungen (Kategorie, Hersteller, Währung, Sprache, Aktion, Steuer, Preisregel) eine Warnung mit Handlungshinweis, damit der Händler eine vollständige Synchronisierung ausführen kann |

## CLI-Befehle

```bash
bin/console emporiqa:sync:products      # Massensynchronisierung aller Produkte
bin/console emporiqa:sync:pages         # Massensynchronisierung aller Seiten
bin/console emporiqa:sync:all           # Massensynchronisierung von Produkten und Seiten
bin/console emporiqa:test-connection    # Zugangsdaten und Erreichbarkeit prüfen
```

Mit `--dry-run` baut ein Sync-Befehl die Payloads auf und prüft sie, ohne sie zu senden.

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

Das Plugin ist kostenfrei. Emporiqa rechnet nutzungsbasiert ab: 0 $/Monat Grundgebühr plus 0,25 $ pro Konversation. Neue Konten erhalten 25 $ Startguthaben (rund 100 Konversationen auf unsere Rechnung), keine Karte bei der Anmeldung erforderlich. Nach Verbrauch des Guthabens liegt das monatliche Limit standardmäßig bei 59 $ und lässt sich im Abrechnungs-Dashboard selbst anpassen. Enterprise-Option für Kataloge mit mehr als 100.000 Produkten. Alle Preisangaben unter [emporiqa.com/de/pricing/](https://emporiqa.com/de/pricing/).

Emporiqa funktioniert auch mit PrestaShop, Drupal Commerce, WooCommerce, Magento, Sylius und über die Webhook-API mit jedem beliebigen Shop. Ein Emporiqa-Konto und ein Dashboard genügen für alle.

## Dokumentation und Support

- **Integration im Überblick**: [https://emporiqa.com/de/integrations/shopware/](https://emporiqa.com/de/integrations/shopware/)
- **Vollständige Dokumentation**: [https://emporiqa.com/de/docs/shopware/](https://emporiqa.com/de/docs/shopware/) (Details zur Konfiguration, Referenz des Webhook-Formats, Beispiele zu Events, Fehlersuche)
- **E-Mail**: support@emporiqa.com

## Lizenz

[MIT](https://opensource.org/licenses/MIT)


## Wer hinter Emporiqa steht

Emporiqa wird von [Rosel Group LTD](https://emporiqa.com/about/) entwickelt, einem EU-Unternehmen mit Sitz in Sofia, Bulgarien, gegründet von [Rosen Hristov](https://www.linkedin.com/in/rosen-hristov/), der seit 15 Jahren E-Commerce-Software entwickelt. Das Unternehmen arbeitet DSGVO-konform und nutzt Daten von Käufern nie zum Training von KI-Modellen. Abgerechnet wird nutzungsbasiert: 0,25 $ pro Konversation, 25 $ Startguthaben, ein monatliches Limit von standardmäßig 59 $, das Sie ändern können, und keine Karte bei der Anmeldung. Emporiqa läuft auf selbst gehosteten Plattformen (WooCommerce, Magento und Adobe Commerce, PrestaShop, Drupal Commerce, Shopware 6, Sylius); auf Shopify läuft es nicht. Dieses Plugin wird über eine ZIP-Datei aus einem [GitHub-Release](https://github.com/emporiqa/shopware/releases) oder mit Composer installiert; die Prüfung des Listings im Shopware Store läuft noch. Das Verhalten des Chatbots können Sie selbst nachvollziehen, an unbearbeiteten Demo-Antworten mit Links zum erneuten Ausführen: https://emporiqa.com/de/proof/
