# Changelog

## 1.1.0

Upgrade note for consuming plugins: pass the plugin instance to `WebhookHelper::parse_webhook( $provider, $data, $instance )`. Without it, `paid` is now always `false`, because the request body proves nothing.

- Security: Webhook signatures are checked in the format the servers send. Coinsnap: the timestamped `X-Coinsnap-Signature: t=…,v1=…` with a 5 minute window. BTCPay Server: `BTCPay-Sig`. Before, the Coinsnap check compared a plain HMAC against the timestamped header and the BTCPay check read a header BTCPay does not send, so neither ever verified a real webhook.
- Security: `paid` comes from the payment server's API, never from the webhook body. `handle_webhook()` and `parse_webhook()` used the body's `type`; `check_invoice_status()` also treated `Paid` and `Complete` as paid. An invoice is now paid only when it is Settled, was not marked settled by hand in BTCPay, has a row in this plugin's payment table (it was created through this plugin), and matches the amount and currency the plugin sent when it created it (recorded for 180 days).
- Security: The BTCPay Server setup callback only accepts a one-time code issued to an administrator, and saves the server URL only together with a key that works on it. Before, its nonce was created in the same request that checked it, and a key that failed the check was saved anyway.
- Security: API key fields no longer show the saved key; leave them empty to keep it. A BTCPay key is not kept when the server URL changes.
- Security: Log files get a per-site random name (existing `coinsnap.log` files, rotated copies included, are moved), the log directory gets `.htaccess` and `index.php`, and keys, secrets, tokens, passwords, signatures and cookies in log context are redacted. Logs in `wp-content/uploads` were downloadable on Nginx.
- Security: The connection check requires an administrator, and BTCPay connection errors no longer pass WordPress error text through.
