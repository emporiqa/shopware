# Changelog

## 1.3.1 (2026-10-06)

### Added
- **Order status answers with the whole order.** Besides the status, date, tracking and expected delivery, the answer now carries the order number, the customer's name, the items (name, product number, variant, quantity, unit and line price), the totals (subtotal, shipping, tax, discount, total) in the order's currency as the customer was charged, the payment method and payment status, the shipping method and its delivery time, and the shipping and billing address. The chat shows them only after the shopper proved the order is theirs (order number and email, or signed in), as before; the email, customer id and internal ids are never sent back. `OrderStatusResponseEvent` runs after all of it is filled, so an extension can change any of it and add its own fields under `extra` (see the README).
- **Headless sales channels are named instead of skipped in silence.** Test connection (on the Emporiqa page and `bin/console emporiqa:test-connection`) and the Sync tab list the active headless (API) sales channels that show products, and say why they are not synced: Shopware creates no product page addresses (SEO URLs) for headless channels, so the chat could not link to products there. They also point to the embed code for adding the chat to a frontend Shopware does not render. Shopware's empty default "Headless" channel is not listed. Nothing changes in what is synced.

## 1.3.0 (2026-10-05)

### In short
- Order tracking works as in 1.2.5 and stays on. The plugin now also works with Emporiqa's ready-made rules: the new Order status rule replaces the old order tracking, and the Emporiqa page shows it as On or Not added.
- A full-page cache or a server log can no longer hand one shopper's identity to another in the chat.
- Prices the chat quotes now match the cart for advanced prices that apply to every visitor, for variants that inherit their price, and for dated sales while they run.

### Added
- **Order status rule.** A read-only endpoint for Emporiqa's ready-made Order status rule (`/emporiqa/actions/order-status`), signed both ways with per-purpose keys (scheme 2), deduplicated on `request_id` for 10 minutes and rate limited per order number and per email (10 per 10 minutes) and per store (300), answered with a signed 429 whose `data.scope` says which limit was hit and a `Retry-After` header. It answers the same for an unknown order and a wrong email, checks the required fields before the lookup, only finds orders of the synced sales channels, and returns the order state, date, tracking numbers with their links and the expected delivery date, nothing else. A signed-in shopper only gives the order number; a signed-in shopper can still look up a guest order by giving its email. Extensions can adjust the answer with the new `OrderStatusResponseEvent`.
- **Ready-made rules card** on the Settings tab, shown once Emporiqa says the store has rules (at connect or Test connection). It shows the plugin's Order status address with a Copy button, whether Order status is On or Not added, and an Open in Emporiqa button. No reconnect is needed after the update.
- **Customer prices.** A read-only endpoint (`/emporiqa/actions/customer-prices`), signed and rate limited like Order status (30 calls per customer and 600 per store every 10 minutes, deduplicated on `request_id`), that tells Emporiqa what one signed-in customer pays for up to 20 products: their customer group's prices and net or gross display, their own rule prices, quantity prices as the cart charges them, in the requested currency when the sales channel sells in it. Prices come from Shopware's own price calculation for a fresh context of that customer; nothing is saved and no cart or session is touched. Products the customer cannot see are left out, an unknown or inactive customer gets `not_found`, and the answer holds prices only. Emporiqa will use it in a later release.
- **Dated sales reach the chat when they start and leave it when they end.** A new scheduled task (`emporiqa.price_rule_boundary`, every 15 minutes) re-syncs the products priced by a rule whose date range has just started or ended. Rules on time of day or weekday are still never published. It runs with Shopware's scheduled tasks (`scheduled-task:run` or the admin worker).
- `/emporiqa/actions/verify` answers Emporiqa's signed address check and, during one-click connect, the proof that the shop really is at the connected address.
- Sync webhooks carry the scheme-2 `X-Emporiqa-Webhook-Signature` beside the old `X-Webhook-Signature`, plus `X-Emporiqa-Plugin-Version`, signed afresh on every retry.
- Connect sends the Order status address (the storefront domain on the admin's host, including a domain path such as `/de`), keeps the connect verifier until Emporiqa answers, and stores what Emporiqa says about rules.
- Test connection warns when this server's clock is more than 2 minutes off Emporiqa's (Emporiqa refuses signatures more than 5 minutes off).
- Order status, verify and the old order tracking answer while the shop is in maintenance mode.

### Changed
- **The customer token is no longer put in the widget URL or cached in the browser.** It is fetched from the uncached `/emporiqa/api/user-token` endpoint only when the chat opens, carries `aud` (the Emporiqa store id), and is handed to the widget on a private `MessageChannel` port. A guest is answered without a request, a signed-in shopper's token is reused for at most five minutes, and a failed answer is never reused.
- **Old order tracking.** It keeps working exactly as before and stays on, on new installs and after the update. Where ready-made rules are offered it moves under Advanced as "Old order tracking (deprecated)" with an on/off switch; remove its address in the Emporiqa dashboard first, then switch it off. Invalid order numbers are answered as unknown without a lookup, and an error never shows a stack trace.
- The cart and customer-token addresses include the sales channel domain path (for example `/de`), so they work on domains with a path.
- Variant payloads are smaller: a variant no longer repeats the parent's descriptions, categories and manufacturer, or the parent-only fields `variation_attributes` and `is_parent`. Emporiqa takes them from the parent product, so nothing changes in the chat.
- The manual sync pages by id instead of by offset, so an item deactivated or deleted mid-sync can no longer shift the pages, skip a live item and get it deleted when the sync completes.
- Connect waits up to 20 seconds for Emporiqa, which checks the shop address during the exchange.
- **The plugin's own admin endpoints need the plugin-maintenance privilege** (`system.plugin_maintain`): Connect, Sync, Test connection and the plugin's settings page now refuse an admin role or integration without it. The Emporiqa settings are still ordinary system configuration, so a role with the `system_config:update` privilege can change them through Shopware's own system-config API. Administrators are unaffected.
- When one shop is connected to several Emporiqa stores (a store per sales channel), Order status only searches the sales channels of the store that asked, and request replay and rate limits are kept per store.
- Errors of the action endpoints and the old order tracking are logged without the database message, which could contain a shopper's email or order number.
- `GET /emporiqa/api/user-token` still answers for storefront themes compiled before 1.3.0; it will be POST only from 1.4.0.

### Fixed
- **Advanced prices for all visitors are now the price the chat quotes.** When a price rule that applies to guests (for example "Always valid") has advanced prices, the storefront charges its first quantity row and ignores the product's own price, but that price was sent instead. The current price, list price and quantity prices now come from that rule, exactly as the cart charges them.
- **Variants that inherit their price are no longer sent without a price.** A variant using the parent's price, or the parent's advanced prices, now gets them, as in the storefront.
- **Prices from rules that depend on the time of day or weekday are no longer sent.** They change too often to keep in sync, so a happy-hour or Sunday price could be quoted outside its hours. The price that applies outside them is sent instead.
- **A product assigned to no sales channel is no longer synced.** No storefront shows it, but it was offered in the chat on every channel.
- **A product set to "Hide in listings and search" in a sales channel is no longer synced to that channel.** The storefront only opens it by its link there, so the chat does not offer it either. "Hide in listings" still lets the storefront search find it, so such a product stays.
- **A product that no synced sales channel shows any more leaves the chat as soon as it is saved**, with its variants. Removing its last sales channel or setting it to "Hide in listings and search" used to keep it in the chat until the next full sync, and a change made only to a product's sales channels or their visibility was not sent at all.
- The data preview on the Sync tab shows quantity prices, as the sync sends them.
- Two connect callbacks racing with the same link can no longer both be exchanged.
- **Uninstalling without keeping data now removes all plugin settings.** The connection state, the rules status, the old order tracking switch, the language and sales channel choices and sync sessions were left behind and came back on a reinstall.
- **Products are re-synced once after updating**, so prices already sent to Emporiqa are corrected without any action. On a headless setup without the storefront, run Sync products once from the Emporiqa page.
- Manual sync, Test connection and the CLI commands work again on Shopware 6.6.0.x, where they failed with "Call to undefined method Context::createCLIContext()" since 1.2.1.
- A sync that cannot reach Emporiqa shows the error once per batch instead of once per request, without an internal id as "offset", and an error from Emporiqa keeps its hint (for example how long to wait before a stuck sync can be started again).

## 1.2.5 (2026-09-29)

### Fixed
- **Prices in other currencies are now converted.** A product priced only in the default currency was sent with the same amount for every other currency (for example 5,496 EUR became 5,496 USD). Prices, list prices and quantity prices now use the currency's exchange rate and rounding, exactly as the storefront shows them. Prices set explicitly for a currency are unchanged.
- **Product changes saved in several steps are no longer lost.** When an import or integration saved a product and then its prices or images in separate steps of the same request or run, only the first step reached Emporiqa. Every step is now synced. Saving in the Administration was not affected.
- **Products are re-synced once after updating from 1.2.4 or earlier**, so prices already sent to Emporiqa are corrected without any action. On a headless setup without the storefront, run Sync products once from the Emporiqa page.

## 1.2.4 (2026-09-29)

### Fixed
- **Quantity prices for a customer group are no longer shown to every shopper.** Advanced prices were merged across all price rules, so a rule meant for one customer group (for example dealer or B2B prices) could appear as a public quantity discount in the chat. Quantity prices now come only from the highest-priority rule a guest shopper actually matches, the same way the storefront prices a product for a visitor who is not logged in.
- **Products are re-synced once after updating**, so quantity prices already sent to Emporiqa are corrected without any action. The sync is queued in the background with the next storefront page view. On a headless setup without the storefront, run Sync products once from the Emporiqa page.

## 1.2.3 (2026-09-21)

### Fixed
- **Categories are synced as pages regardless of their layout.** Previously only categories with a "Shop page" or "Landing page" layout were considered; a normal category using the product listing layout is now synced too when it carries its own text, such as an SEO description, a guide or an FAQ accordion above or below the product grid. A category that is only a product grid, with no text of its own, is still left out.
- **The storefront home page is now synced.** It was previously always excluded as a tree root.

## 1.2.2 (2026-09-17)

### Added
- **Synced sales channels setting.** The Advanced card lists every storefront sales channel; unticked channels are left out of product and page syncs and the chat widget is not shown on their storefronts. All channels stay synced by default.
- **Extensions > Configure opens the Emporiqa settings page directly again** (no duplicate configuration page).

## 1.2.1 (2026-09-17)

### Fixed
- **Page content is now read the way the storefront renders it.** Mapped fields, per-page text overrides, translation fallback and CMS elements from other plugins (for example FAQ accordions) are included in synced page content.
- **Page links always open a real storefront page.** Links use the canonical SEO URL per sales channel and language (German landing page links previously returned 404). While Shopware has not generated the SEO URL yet, the technical route is sent and the page is re-synced automatically once the URL exists or changes, including edits made under Settings > SEO. Pages that are not reachable in any synced sales channel are removed from Emporiqa instead of being linked.
- **Categories with a landing page layout are synced as pages.** Tree roots (navigation, footer, service) are never synced as pages.
- **A sync with no matching sales channel or language reports an error** instead of a successful sync of zero items.
- **Sales channels sharing the same name no longer collapse into one Emporiqa channel**, and product links point to a sales channel the product is visible in.
- **Saving the plugin settings can no longer wipe the credentials** while they are still loading, and unknown language codes are rejected.
- **Shopware 6.7 admin styling** of the status banners and buttons.
- **The plugin version reported to Emporiqa** (`plugin_version`, User-Agent) was still 1.1.0.

### Changed
- **Real-time page changes are processed in the background** (message queue), so large imports and layout edits no longer slow down saving. Changing a Shopping Experiences layout re-syncs every page that uses it.
- **Plugin configuration complies with the Shopware store rules.** The Extension Manager is no longer overridden: Extensions > Configure opens Shopware's native configuration page, which now links to the full Emporiqa page (connect, languages, sync). Settings > Emporiqa is unchanged.
- **Static code analysis is clean**: deprecated Shopware APIs replaced, XML service and route definitions migrated to YAML, storefront plugins use `window.PluginBaseClass`.
- The Enabled languages help text mentions that the chat widget is hidden for unselected languages.
- For developers: `PostPageFormatEvent` is now dispatched from the message worker (not the admin request) and also after SEO URL and layout changes.

## 1.1.1 (2026-09-02)

### Fixed
- **The German configuration help text named screens that do not exist.** Both
  `helpText lang="de-DE"` strings pointed merchants at "Einstellungen →
  Shop-Integration → Integrationsübersicht", but the Emporiqa dashboard is not
  localized, so no German screen of that name exists. Both now give the real
  English path and say that the dashboard is in English. The Shopware plugin
  admin itself stays German, which it always was.

## 1.1.0 (2026-07-10)
First public release. Distributed as a GitHub release zip and via Composer.

### Features

- **One-click connect**: "Connect to Emporiqa" button starts a secure PKCE handshake, no manual copying of Store ID and Webhook Secret required. Manual credential entry remains available.
- **Product sync**: Real-time and batch sync via webhook API with async message queue delivery
- **Lightweight availability sync**: Stock-only changes (including order-driven stock reductions) send compact `product.availability` events instead of full product payloads
- **Tiered pricing**: Advanced quantity prices are exported as `tier_prices` per currency (sorted, deduplicated, no-op tiers dropped)
- **Backorder status**: Products without stock that are not clearance items report `backorder` instead of `out_of_stock`; parents aggregate the availability of their variants
- **Rich product payloads**: `min_order_quantities`, `max_order_quantities`, `available_for_order`, `condition`, and `is_virtual` (digital/download products) are included
- **Precise variant deletion**: Deleting a variant sends a `variation-…` delete event and refreshes the parent; deleting a parent also removes all of its variants from Emporiqa
- **Media and price triggers**: Changes to product images and advanced prices queue a product re-sync automatically
- **Catalog change warnings**: Renaming categories, manufacturers, currencies, taxes, or languages logs an actionable warning to run a full sync
- **Landing page sync**: CMS landing pages and shop pages synced as page payloads
- **Consolidated webhook format**: Nested `{channel: {language: value}}` structure matching all Emporiqa integrations
- **Sales channel mapping**: Map Shopware sales channels to Emporiqa channel keys (`b2b`, `retail`, etc.) for catalog segmentation
- **Multi-currency pricing**: Products include prices for all currencies per sales channel domain
- **Multi-language support**: All configured languages synced in a single pass
- **Category hierarchy**: Full category paths with `>` separator (e.g., `Electronics > Gadgets`)
- **Configurable brand source**: Use product manufacturer or a property group as the brand source
- **Tax-inclusive prices**: Products send the tax-included price plus an incl./excl. breakdown when tax applies; display is controlled in the Emporiqa dashboard
- **Chat widget embedding**: Storefront widget with user token support and currency-aware config
- **Cart API**: Storefront cart endpoints for in-chat shopping with SEO URLs and dynamic checkout URL
- **Order tracking**: Configurable order/transaction states trigger the `order.completed` webhook, with optional email verification
- **Conversion webhook sent exactly once**: `order.completed` is deduplicated across requests via a persistent marker on the order; the Emporiqa chat session ID is persisted at placement so attribution survives later state changes
- **Webhook retry with backoff**: Transient failures (429, 5xx, network errors) are retried with exponential backoff and delayed re-queueing
- **Long-running worker support**: Services implement `ResetInterface` to clear cached state between requests in Swoole or Messenger workers
- **Dry run connection test**: Sends a real product to `?dry_run=true` and returns field-by-field validation, detected languages/channels, and warnings
- **Admin dashboard**: Full settings UI with connection test, data preview, sync controls, sales channel mapping, order tracking config, and CLI command reference
- **Sync progress bar**: Admin-triggered bulk syncs run in driven batches with a live progress bar, per-batch log, cancel button, and guarded completion that refuses to finalize a partial sync
- **CLI commands**: `emporiqa:sync:products`, `emporiqa:sync:pages`, `emporiqa:sync:all`, `emporiqa:test-connection` (all support `--dry-run`)
- **German translations**: Full de-DE support for admin UI and config
