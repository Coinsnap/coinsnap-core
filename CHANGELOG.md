# Changelog

## Unreleased

- New: `create_invoice()` sends `$invoice_data['order_id']` as the top-level `orderId` on Coinsnap (Coinsnap shows and searches it, and without it generates its own `CS-…` number and writes that into `metadata.orderId`). On BTCPay Server it goes in `metadata.orderId`, where BTCPay reads it. `metadata.orderId` and the top-level field always carry the same value.
- New: `$invoice_data['metadata']` may hold any keys, nested arrays included (`lineItems`, `formFields`, …); they are sent as they are and win over the keys the core builds. `order_number` sets `metadata.orderNumber` (default: `order_id`). The buyer name is also sent as `customerName`.
- Change: empty values (`''`, `null`, empty arrays) are no longer sent in metadata, and the Coinsnap top-level `buyerEmail` is sent only when there is one.

## 1.1.0

Upgrade note for consuming plugins: pass the plugin instance to `WebhookHelper::parse_webhook( $provider, $data, $instance )`. Without it, `paid` is now always `false`, because the request body proves nothing.

- Security: Webhook signatures are checked in the format the servers send. Coinsnap: the timestamped `X-Coinsnap-Signature: t=…,v1=…` with a 5 minute window. BTCPay Server: `BTCPay-Sig`. Before, the Coinsnap check compared a plain HMAC against the timestamped header and the BTCPay check read a header BTCPay does not send, so neither ever verified a real webhook.
- Security: `paid` comes from the payment server's API, never from the webhook body. `handle_webhook()` and `parse_webhook()` used the body's `type`; `check_invoice_status()` also treated `Paid` and `Complete` as paid. An invoice is now paid only when it is Settled, was not marked settled by hand in BTCPay, has a row in this plugin's payment table (it was created through this plugin), and matches the amount and currency the plugin sent when it created it. Invoices created before 1.1.0 have no such record and are accepted only during the first day after the upgrade.
- Security: The BTCPay Server setup callback only accepts a one-time code issued to an administrator, and saves the server URL only together with a key that works on it. Before, its nonce was created in the same request that checked it, and a key that failed the check was saved anyway.
- Security: API key fields no longer show the saved key; leave them empty to keep it. A BTCPay key is not kept when the server URL changes.
- Security: Log files get a per-site random name (existing `coinsnap.log` files, rotated copies included, are moved), the log directory gets `.htaccess` and `index.php`, and keys, secrets, tokens, passwords, signatures and cookies in log context are redacted. Logs in `wp-content/uploads` were downloadable on Nginx.
- Security: The connection check requires an administrator, and BTCPay connection errors no longer pass WordPress error text through.
- Security: The settings page no longer prints the saved API keys; the BTCPay setup reads the key's rights from BTCPay instead of the posted form.
- Security: BTCPay Server setup asks only for `canviewinvoices`, `cancreateinvoice`, `canviewstoresettings` and `webhooks.canmodifywebhooks` for one store, and refuses any key with more (`BTCPayAuthorizer::acceptable_key_store()`). Before, it asked for `cancreatenonapprovedpullpayments` (can send funds out) and `canmodifyinvoices`, neither of which the core uses.
- Security: The "Disable Webhook Verification" switch is gone, and `get_settings_for()` always returns `disable_webhook_verification => false`, so a value saved by an earlier version can't keep a consuming plugin accepting unsigned webhooks.
- Security: `check_webhook()` returns `true` when the payment server can't be reached (network error, 5xx). Before, a glitch looked like "no webhook", and plugins that then call `register_webhook()` replaced a working webhook and its secret.
- Security: Amount records are kept per plugin, so with two Coinsnap plugins on one site an invoice of one is never accepted as the other's.
- New: `WebhookHelper::check_request( $instance, $provider, $request )` for a REST route's `permission_callback`: checks the signature over the request's raw body and logs each rejection with a fixed reason (`bad signature`, `stale timestamp`), at most 10 SECURITY lines a minute. `parse_webhook()` with an instance logs `unknown invoice`, `status <name>`, `retry: API unavailable` (INFO) and `forged status` (SECURITY).
- New: `PaymentTable::mark_paid_once( $instance, $invoice_id )` moves a row from `unpaid` (or `failed`, for a late payment) to `paid` in one SQL statement and returns `true` only for the call that changed it, so a retried or replayed webhook fulfils once and a paid or refunded row never changes.
- Security: The logs page checks `manage_options` before clearing or showing logs.
- Fix: `check_invoice_status()`, `handle_webhook()` and `parse_webhook()` return `retry` when the payment server could not be asked, so a webhook can be answered with 503 and sent again.
