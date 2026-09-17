# Changelog

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
