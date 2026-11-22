<?php
// Unit tests for coinsnap-core security classes, with minimal WordPress stubs.
// Usage: php tests/security-test.php [/path/to/coinsnap-core]
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(-1);
ini_set('display_errors', '1');
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

$CORE = rtrim($argv[1] ?? dirname(__DIR__), '/');
define('ABSPATH', '/tmp/wp/');
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);
define('EP_ROOT', 64);

// ---------- WP stubs ----------
$GLOBALS['opts'] = []; $GLOBALS['trans'] = []; $GLOBALS['http'] = []; $GLOBALS['http_log'] = []; $GLOBALS['doing_wrong'] = 0;
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opts'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['opts'][$k]); return true; }
function get_transient($k) { return $GLOBALS['trans'][$k] ?? false; }
function set_transient($k, $v, $e = 0) { $GLOBALS['trans'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['trans'][$k]); return true; }
function add_action(...$a) {} function add_filter(...$a) {}
function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f) { return 'https://example.test/wp-content/plugins/coinsnap-core/'; }
function __($s, $d = null) { return $s; }
function esc_html__($s, $d = null) { return $s; }
function get_home_url() { return 'https://shop.example.test'; }
function home_url($p = '') { return 'https://shop.example.test/' . ltrim($p, '/'); }
function wp_json_encode($v) { return json_encode($v); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_url($s) { return trim((string) $s); }
function esc_url_raw($s) { return trim((string) $s); }
function wp_unslash($s) { return $s; }
function _doing_it_wrong(...$a) { $GLOBALS['doing_wrong']++; }
function do_action(...$a) {}
function is_wp_error($x) { return $x instanceof WP_Error; }
class WP_Error { public $m; function __construct($c = '', $m = '') { $this->m = $m; } }
function wp_upload_dir() { return ['basedir' => $GLOBALS['UPLOADS']]; }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function wp_hash($s) { return hash_hmac('md5', $s, 'site-salt'); }
function current_time($f) { return gmdate('Y-m-d H:i:s'); }
// Fake HTTP: queue of responses matched by substring of "METHOD url"
function wp_remote_request($url, $args = []) {
    $GLOBALS['http_log'][] = ($args['method'] ?? 'GET') . ' ' . $url;
    foreach ($GLOBALS['http'] as $i => [$match, $resp]) {
        if (strpos(($args['method'] ?? 'GET') . ' ' . $url, $match) !== false) {
            return is_callable($resp) ? $resp($url, $args) : $resp;
        }
    }
    return new WP_Error('http', 'no route');
}
function wp_remote_retrieve_response_code($r) { return is_array($r) ? ($r['code'] ?? 0) : ''; }
function wp_remote_retrieve_body($r) { return is_array($r) ? ($r['body'] ?? '') : ''; }
function resp(int $code, $body) { return ['code' => $code, 'body' => is_string($body) ? $body : json_encode($body)]; }

// Fake wpdb that understands the queries the core issues.
class FakeWpdb {
    public $prefix = 'wp_'; public $tables = []; // table => [invoice_id => status]
    function prepare($q, ...$args) { foreach ($args as $a) { $q = preg_replace('/%s/', "'" . addslashes((string) $a) . "'", $q, 1); } return $q; }
    function get_var($q) {
        if (preg_match("/^SHOW TABLES LIKE '([^']+)'/", $q, $m)) { return isset($this->tables[$m[1]]) ? $m[1] : null; }
        if (preg_match("/SELECT id FROM (\S+) WHERE payment_invoice_id = '([^']*)'/", $q, $m)) { return isset($this->tables[$m[1]][$m[2]]) ? '1' : null; }
        throw new RuntimeException("unexpected get_var: $q");
    }
    function query($q) {
        if (preg_match("/UPDATE (\S+) SET payment_status = 'paid' WHERE payment_invoice_id = '([^']*)' AND payment_status IN \('unpaid', 'failed'\)/", $q, $m)) {
            $s = $this->tables[$m[1]][$m[2]] ?? null;
            if ($s === 'unpaid' || $s === 'failed') { $this->tables[$m[1]][$m[2]] = 'paid'; return 1; }
            return 0;
        }
        throw new RuntimeException("unexpected query: $q");
    }
}
$GLOBALS['wpdb'] = new FakeWpdb();
class FakeRequest { private $b; private $h; function __construct($b, $h) { $this->b = $b; $this->h = array_change_key_case($h); }
    function get_body() { return $this->b; } function get_header($n) { return $this->h[strtolower($n)] ?? null; } }

$GLOBALS['UPLOADS'] = sys_get_temp_dir() . '/core-test-uploads-' . getmypid();
require $CORE . '/coinsnap-core.php';

use CoinsnapCore\PluginInstance;
use CoinsnapCore\Webhook\Signature;
use CoinsnapCore\Webhook\InvoiceCheck;
use CoinsnapCore\Database\PaymentTable;
use CoinsnapCore\Rest\WebhookHelper;
use CoinsnapCore\Auth\BTCPayAuthorizer;
use CoinsnapCore\Admin\SettingsPage;
use CoinsnapCore\Providers\CoinsnapProvider;
use CoinsnapCore\Providers\BTCPayProvider;
use CoinsnapCore\Util\Logger;

$pass = 0; $fail = 0;
function t(string $name, bool $ok) { global $pass, $fail; if ($ok) { $pass++; } else { $fail++; echo "FAIL: $name\n"; } }
function reset_state() { $GLOBALS['opts'] = []; $GLOBALS['trans'] = []; $GLOBALS['http'] = []; $GLOBALS['http_log'] = []; $GLOBALS['wpdb']->tables = []; }
function log_text(PluginInstance $i) { $d = $GLOBALS['UPLOADS'] . '/' . $i->get('log_dir_name'); $s = ''; foreach (glob($d . '/*.log') ?: [] as $f) { $s .= file_get_contents($f); } return $s; }
function clear_logs(PluginInstance $i) { foreach (glob($GLOBALS['UPLOADS'] . '/' . $i->get('log_dir_name') . '/*.log') ?: [] as $f) { unlink($f); } }
function csig($secret, $body, $t) { return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret); }

$A = new PluginInstance(['option_key' => 'plugA_settings', 'webhook_key' => 'plugA_webhook', 'table_suffix' => 'plugA_payments', 'rest_namespace' => 'plugA/v1', 'log_dir_name' => 'plugA-logs', 'log_file_name' => 'plugA.log']);
$B = new PluginInstance(['option_key' => 'plugB_settings', 'webhook_key' => 'plugB_webhook', 'table_suffix' => 'plugB_payments', 'rest_namespace' => 'plugB/v1', 'log_dir_name' => 'plugB-logs', 'log_file_name' => 'plugB.log']);

// ---------- Signature: Coinsnap ----------
$now = time(); $body = '{"invoiceId":"inv1","type":"Settled"}'; $sec = 'whsec_A';
t('cs valid', null === Signature::coinsnapProblem(csig($sec, $body, $now), $body, $sec, $now));
t('cs valid 299s old', null === Signature::coinsnapProblem(csig($sec, $body, $now - 299), $body, $sec, $now));
t('cs valid 300s old (boundary)', null === Signature::coinsnapProblem(csig($sec, $body, $now - 300), $body, $sec, $now));
t('cs stale 301s', 'stale timestamp' === Signature::coinsnapProblem(csig($sec, $body, $now - 301), $body, $sec, $now));
t('cs stale 10min', 'stale timestamp' === Signature::coinsnapProblem(csig($sec, $body, $now - 600), $body, $sec, $now));
t('cs future 301s', 'stale timestamp' === Signature::coinsnapProblem(csig($sec, $body, $now + 301), $body, $sec, $now));
t('cs wrong secret', 'bad signature' === Signature::coinsnapProblem(csig('other', $body, $now), $body, $sec, $now));
t('cs modified body', 'bad signature' === Signature::coinsnapProblem(csig($sec, $body, $now), $body . ' ', $sec, $now));
t('cs changed t', 'bad signature' === Signature::coinsnapProblem('t=' . ($now - 1) . ',v1=' . hash_hmac('sha256', $now . '.' . $body, $sec), $body, $sec, $now));
t('cs missing header', 'bad signature' === Signature::coinsnapProblem('', $body, $sec, $now));
t('cs empty secret, sig with empty key', 'bad signature' === Signature::coinsnapProblem(csig('', $body, $now), $body, '', $now));
t('cs no t', 'bad signature' === Signature::coinsnapProblem('v1=' . hash_hmac('sha256', '0.' . $body, $sec), $body, $sec, $now));
t('cs old X-Coinsnap-Sig format sha256=', 'bad signature' === Signature::coinsnapProblem('sha256=' . hash_hmac('sha256', $body, $sec), $body, $sec, $now));
t('cs v1 array', 'bad signature' === Signature::coinsnapProblem('t=' . $now . ',v1[]=x', $body, $sec, $now));
t('cs uppercase hex rejected', 'bad signature' === Signature::coinsnapProblem('t=' . $now . ',v1=' . strtoupper(hash_hmac('sha256', $now . '.' . $body, $sec)), $body, $sec, $now));
t('cs stale + wrong sig reports bad signature', 'bad signature' === Signature::coinsnapProblem(csig('x', $body, $now - 900), $body, $sec, $now));
// ---------- Signature: BTCPay ----------
$bs = 'sha256=' . hash_hmac('sha256', $body, $sec);
t('btc valid', null === Signature::btcpayProblem($bs, $body, $sec));
t('btc without prefix', 'bad signature' === Signature::btcpayProblem(hash_hmac('sha256', $body, $sec), $body, $sec));
t('btc wrong secret', 'bad signature' === Signature::btcpayProblem('sha256=' . hash_hmac('sha256', $body, 'x'), $body, $sec));
t('btc empty secret', 'bad signature' === Signature::btcpayProblem('sha256=' . hash_hmac('sha256', $body, ''), $body, ''));
t('btc missing', 'bad signature' === Signature::btcpayProblem('', $body, $sec));

// ---------- check_request ----------
reset_state(); clear_logs($A);
$GLOBALS['opts']['plugA_webhook'] = ['coinsnap' => ['id' => 'wh1', 'secret' => 'secA'], 'btcpay' => ['id' => 'wh2', 'secret' => 'secAb']];
$GLOBALS['opts']['plugB_webhook'] = ['coinsnap' => ['id' => 'wh3', 'secret' => 'secB']];
t('check_request valid', WebhookHelper::check_request($A, 'coinsnap', new FakeRequest($body, ['X-Coinsnap-Signature' => csig('secA', $body, time())])));
t('check_request other plugin secret refused', !WebhookHelper::check_request($A, 'coinsnap', new FakeRequest($body, ['X-Coinsnap-Signature' => csig('secB', $body, time())])));
t('check_request old header only refused', !WebhookHelper::check_request($A, 'coinsnap', new FakeRequest($body, ['X-Coinsnap-Sig' => 'sha256=' . hash_hmac('sha256', $body, 'secA')])));
t('check_request stale refused', !WebhookHelper::check_request($A, 'coinsnap', new FakeRequest($body, ['X-Coinsnap-Signature' => csig('secA', $body, time() - 400)])));
t('check_request empty body refused', !WebhookHelper::check_request($A, 'coinsnap', new FakeRequest('', ['X-Coinsnap-Signature' => csig('secA', '', time())])));
t('check_request btcpay valid', WebhookHelper::check_request($A, 'btcpay', new FakeRequest($body, ['BTCPay-Sig' => 'sha256=' . hash_hmac('sha256', $body, 'secAb')])));
t('check_request btcpay with coinsnap secret refused', !WebhookHelper::check_request($A, 'btcpay', new FakeRequest($body, ['BTCPay-Sig' => 'sha256=' . hash_hmac('sha256', $body, 'secA')])));
$GLOBALS['opts']['plugC_webhook'] = ['coinsnap' => ['secret' => '']];
$C = new PluginInstance(['option_key' => 'plugC', 'webhook_key' => 'plugC_webhook', 'log_dir_name' => 'plugC-logs']);
t('check_request empty stored secret refused', !WebhookHelper::check_request($C, 'coinsnap', new FakeRequest($body, ['X-Coinsnap-Signature' => csig('', $body, time())])));
$log = log_text($A);
t('log has SECURITY bad signature', strpos($log, 'SECURITY webhook reason=bad signature') !== false);
t('log has SECURITY stale timestamp', strpos($log, 'SECURITY webhook reason=stale timestamp') !== false);
t('log has no secret', strpos($log, 'secA') === false && strpos($log, 'secB') === false);
// memo: same request object checked twice logs once
clear_logs($A); $GLOBALS['trans'] = [];
$r = new FakeRequest($body, ['X-Coinsnap-Signature' => 'bogus']);
WebhookHelper::check_request($A, 'coinsnap', $r); WebhookHelper::check_request($A, 'coinsnap', $r);
t('check_request logs once per request', 1 === substr_count(log_text($A), 'SECURITY'));
// cap 10 a minute + one summary line
clear_logs($A); $GLOBALS['trans'] = [];
for ($i = 0; $i < 25; $i++) { WebhookHelper::check_request($A, 'coinsnap', new FakeRequest($body, ['X-Coinsnap-Signature' => 'bogus'])); }
$l = log_text($A);
t('security log capped at 10 + summary', 10 === substr_count($l, 'reason=bad signature') && 1 === substr_count($l, 'more rejections'));

// ---------- InvoiceCheck ----------
reset_state();
$tbl = 'wp_plugA_payments';
$GLOBALS['wpdb']->tables[$tbl] = ['inv1' => 'unpaid'];
InvoiceCheck::record($A, 'inv1', '10.00000000', 'EUR');
$inv = ['id' => 'inv1', 'status' => 'Settled', 'currency' => 'EUR', 'fiatAmount' => 10, 'amount' => 25000, 'additionalStatus' => 'None'];
t('ic settled exact', InvoiceCheck::is_paid($A, 'inv1', $inv, false));
t('ic invoiceAmount used first', InvoiceCheck::is_paid($A, 'inv1', ['invoiceAmount' => '10.00', 'fiatAmount' => 3] + $inv, false));
t('ic underpaid', !InvoiceCheck::is_paid($A, 'inv1', ['fiatAmount' => 9.98] + $inv, false));
t('ic within 1 cent', InvoiceCheck::is_paid($A, 'inv1', ['fiatAmount' => '9.99'] + $inv, false));
t('ic 2 cents over refused?', !InvoiceCheck::is_paid($A, 'inv1', ['fiatAmount' => '10.02'] + $inv, false));
t('ic wrong currency', !InvoiceCheck::is_paid($A, 'inv1', ['currency' => 'USD'] + $inv, false));
t('ic currency case-insensitive', InvoiceCheck::is_paid($A, 'inv1', ['currency' => 'eur'] + $inv, false));
t('ic empty fiatAmount', !InvoiceCheck::is_paid($A, 'inv1', ['fiatAmount' => ''] + $inv, false));
t('ic missing fiatAmount', !InvoiceCheck::is_paid($A, 'inv1', array_diff_key($inv, ['fiatAmount' => 1]), false));
t('ic sats amount not used for coinsnap', !InvoiceCheck::is_paid($A, 'inv1', ['fiatAmount' => null, 'amount' => 10] + $inv, false));
foreach (['New', 'Processing', 'Expired', 'Invalid', 'settled', ''] as $st) { t("ic status $st", !InvoiceCheck::is_paid($A, 'inv1', ['status' => $st] + $inv, false)); }
t('ic overpaid ok? (fiatAmount equals price)', InvoiceCheck::is_paid($A, 'inv1', ['additionalStatus' => 'Overpaid'] + $inv, false));
t('ic btcpay amount', InvoiceCheck::is_paid($A, 'inv1', ['amount' => '10.00'] + $inv, true));
t('ic btcpay Marked refused', !InvoiceCheck::is_paid($A, 'inv1', ['amount' => '10.00', 'additionalStatus' => 'Marked'] + $inv, true));
t('ic coinsnap Marked field ignored', InvoiceCheck::is_paid($A, 'inv1', ['additionalStatus' => 'Marked'] + $inv, false));
t('ic no payment row', !InvoiceCheck::is_paid($A, 'inv-other', $inv, false));
InvoiceCheck::record($B, 'invB', '10.00000000', 'EUR');
$GLOBALS['wpdb']->tables[$tbl]['invB'] = 'unpaid';
t('ic other plugin record not counted (no record, window closed)', !InvoiceCheck::is_paid($A, 'invB', $inv, false));
$GLOBALS['opts']['coinsnap_core_records_since'] = time() - 3600;
t('ic legacy invoice in 1-day window accepted', InvoiceCheck::is_paid($A, 'invB', $inv, false));
$GLOBALS['opts']['coinsnap_core_records_since'] = time() - DAY_IN_SECONDS - 5;
t('ic legacy invoice after window refused', !InvoiceCheck::is_paid($A, 'invB', $inv, false));
unset($GLOBALS['opts']['coinsnap_core_records_since']);
InvoiceCheck::record($A, 'inv0', '0.00000000', 'EUR'); $GLOBALS['wpdb']->tables[$tbl]['inv0'] = 'unpaid';
t('ic zero price never paid', !InvoiceCheck::is_paid($A, 'inv0', ['fiatAmount' => 0] + $inv, false));
// plugin without a table: relies on record
$N = new PluginInstance(['option_key' => 'plugN', 'table_suffix' => 'plugN_none']);
InvoiceCheck::record($N, 'invN', '5.00000000', 'USD');
t('ic no-table plugin with record', InvoiceCheck::is_paid($N, 'invN', ['currency' => 'USD', 'fiatAmount' => 5] + $inv, false));
t('ic no-table plugin without record', !InvoiceCheck::is_paid($N, 'invX', ['currency' => 'USD', 'fiatAmount' => 5] + $inv, false));
// SATS / BTC
InvoiceCheck::record($A, 'invS', '1000.00000000', 'SATS'); $GLOBALS['wpdb']->tables[$tbl]['invS'] = 'unpaid';
t('ic SATS exact', InvoiceCheck::is_paid($A, 'invS', ['currency' => 'SATS', 'fiatAmount' => 1000] + $inv, false));
t('ic SATS 1 short', !InvoiceCheck::is_paid($A, 'invS', ['currency' => 'SATS', 'fiatAmount' => 999] + $inv, false));
t('to_minor_units "10.5"', 1050000000 === InvoiceCheck::to_minor_units('10.5'));
t('to_minor_units float', 1000000 === InvoiceCheck::to_minor_units(0.01));
t('to_minor_units negative null', null === InvoiceCheck::to_minor_units('-1'));
t('to_minor_units junk null', null === InvoiceCheck::to_minor_units('1e3'));
t('to_minor_units array null', null === InvoiceCheck::to_minor_units(['1']));

// ---------- mark_paid_once ----------
$GLOBALS['wpdb']->tables[$tbl] = ['p1' => 'unpaid', 'p2' => 'refunded', 'p3' => 'failed', 'p4' => 'paid'];
t('mpo first call true', PaymentTable::mark_paid_once($A, 'p1'));
t('mpo second call false', !PaymentTable::mark_paid_once($A, 'p1'));
t('mpo refunded unchanged', !PaymentTable::mark_paid_once($A, 'p2') && 'refunded' === $GLOBALS['wpdb']->tables[$tbl]['p2']);
t('mpo failed -> paid', PaymentTable::mark_paid_once($A, 'p3'));
t('mpo already paid false', !PaymentTable::mark_paid_once($A, 'p4'));
t('mpo empty id false', !PaymentTable::mark_paid_once($A, ''));
t('mpo unknown id false', !PaymentTable::mark_paid_once($A, 'nope'));

// ---------- BTCPay key acceptance ----------
$S = 'Store1';
$good = ['btcpay.store.canviewinvoices:' . $S, 'btcpay.store.cancreateinvoice:' . $S, 'btcpay.store.canviewstoresettings:' . $S, 'btcpay.store.webhooks.canmodifywebhooks:' . $S];
t('key good', $S === BTCPayAuthorizer::acceptable_key_store($good));
t('key empty', '' === BTCPayAuthorizer::acceptable_key_store([]));
t('key unrestricted', '' === BTCPayAuthorizer::acceptable_key_store(['unrestricted']));
t('key all stores (no store id)', '' === BTCPayAuthorizer::acceptable_key_store(array_map(fn($p) => explode(':', $p)[0], $good)));
t('key two stores', '' === BTCPayAuthorizer::acceptable_key_store(array_merge($good, ['btcpay.store.canviewinvoices:Store2'])));
t('key pull payments extra', '' === BTCPayAuthorizer::acceptable_key_store(array_merge($good, ['btcpay.store.cancreatenonapprovedpullpayments:' . $S])));
t('key canmodifystoresettings extra', '' === BTCPayAuthorizer::acceptable_key_store(array_merge($good, ['btcpay.store.canmodifystoresettings:' . $S])));
t('key server perm', '' === BTCPayAuthorizer::acceptable_key_store(array_merge($good, ['btcpay.server.canmodifyserversettings'])));
t('key missing one', '' === BTCPayAuthorizer::acceptable_key_store(array_slice($good, 0, 3)));
t('key empty store id', '' === BTCPayAuthorizer::acceptable_key_store(array_map(fn($p) => explode(':', $p)[0] . ':', $good)));
$u = BTCPayAuthorizer::get_authorize_url('https://btcpay.test/', BTCPayAuthorizer::REQUIRED_PERMISSIONS, 'App', true, true, 'https://shop/cb', 'id');
t('authorize url strict + minimal perms', strpos($u, 'strict=true') !== false && 4 === substr_count($u, 'permissions=') && strpos($u, 'pullpayment') === false);

// ---------- Settings ----------
reset_state();
$GLOBALS['opts']['plugA_settings'] = ['coinsnap_api_key' => 'csk_SECRET1234', 'btcpay_host' => 'https://btc.one', 'btcpay_api_key' => 'btk_SECRET5678', 'disable_webhook_verification' => true];
t('settings: old disable switch ignored', false === SettingsPage::get_settings_for($A)['disable_webhook_verification']);
$s = SettingsPage::sanitize_for($A, ['coinsnap_api_key' => '', 'btcpay_api_key' => '', 'btcpay_host' => 'https://btc.one', 'disable_webhook_verification' => '1']);
t('settings: empty coinsnap key keeps stored', 'csk_SECRET1234' === $s['coinsnap_api_key']);
t('settings: empty btcpay key same host keeps', 'btk_SECRET5678' === $s['btcpay_api_key']);
t('settings: disable switch cannot be saved', false === $s['disable_webhook_verification']);
$s = SettingsPage::sanitize_for($A, ['btcpay_api_key' => '', 'btcpay_host' => 'https://evil.example']);
t('settings 6.14: new host + empty key drops key', '' === $s['btcpay_api_key']);
$s = SettingsPage::sanitize_for($A, ['coinsnap_api_key' => 'newkey', 'coinsnap_webhook_secret' => 'x', 'btcpay_webhook_secret' => 'y']);
t('settings: new key saved', 'newkey' === $s['coinsnap_api_key']);
t('settings: webhook secrets not writable', !isset($s['coinsnap_webhook_secret']) && !isset($s['btcpay_webhook_secret']));

// ---------- check_webhook ----------
$hook = 'https://shop.example.test/wp-json/plugA/v1/webhook/coinsnap';
foreach (['coinsnap', 'btcpay'] as $prov) {
    reset_state();
    $GLOBALS['opts']['plugA_settings'] = ['coinsnap_api_key' => 'k', 'coinsnap_store_id' => 's', 'btcpay_host' => 'https://btc.test', 'btcpay_api_key' => 'k', 'btcpay_store_id' => 's'];
    $GLOBALS['opts']['plugA_webhook'] = [$prov => ['id' => 'W1', 'secret' => 'sec']];
    $p = $prov === 'btcpay' ? new BTCPayProvider($A) : new CoinsnapProvider($A);
    $url = $prov === 'btcpay' ? str_replace('coinsnap', 'btcpay', $hook) : $hook;
    t("$prov check_webhook network error = true (keep)", true === $p->check_webhook());
    $GLOBALS['http'] = [['GET', resp(502, 'bad gateway')]];
    t("$prov check_webhook 5xx = true (keep)", true === $p->check_webhook());
    $GLOBALS['http'] = [['GET', resp(200, [['id' => 'W1', 'url' => $url]])]];
    t("$prov check_webhook present = true", true === $p->check_webhook());
    $GLOBALS['http'] = [['GET', resp(200, [])]];
    t("$prov check_webhook missing = false", false === $p->check_webhook());
    $GLOBALS['http'] = [['GET', resp(401, ['error' => 'x'])]];
    t("$prov check_webhook 401 = false", false === $p->check_webhook());
}

// ---------- parse_webhook ----------
function setup_api(array $routes) {
    reset_state();
    $GLOBALS['opts']['plugA_settings'] = ['coinsnap_api_key' => 'csk_SECRET1234', 'coinsnap_store_id' => 'STOREID9876', 'btcpay_host' => 'https://btc.test', 'btcpay_api_key' => 'btk_SECRET', 'btcpay_store_id' => 'STOREID9876', 'log_level' => 'info'];
    $GLOBALS['wpdb']->tables['wp_plugA_payments'] = ['invP' => 'unpaid'];
    InvoiceCheck::record($GLOBALS['A'], 'invP', '10.00000000', 'EUR');
    $GLOBALS['http'] = $routes;
}
$settled = ['id' => 'invP', 'status' => 'Settled', 'currency' => 'EUR', 'fiatAmount' => '10.00', 'amount' => '10.00', 'additionalStatus' => 'None'];
clear_logs($A);
setup_api([['/invoices/invP', resp(200, $settled)]]);
$r = WebhookHelper::parse_webhook('coinsnap', ['invoiceId' => 'invP', 'type' => 'Settled'], $A);
t('pw real payment paid', true === $r['paid'] && false === $r['retry']);
setup_api([['/invoices/', resp(400, ['error' => 'not found'])]]);
$r = WebhookHelper::parse_webhook('coinsnap', ['invoiceId' => 'testinv', 'type' => 'Settled'], $A);
t('pw dashboard test webhook: not paid, no retry', false === $r['paid'] && false === $r['retry']);
setup_api([['/invoices/', resp(503, 'down')]]);
$r = WebhookHelper::parse_webhook('coinsnap', ['invoiceId' => 'invP', 'type' => 'Settled'], $A);
t('pw coinsnap 5xx -> retry', false === $r['paid'] && true === $r['retry']);
setup_api([]);
$r = WebhookHelper::parse_webhook('coinsnap', ['invoiceId' => 'invP', 'type' => 'Settled'], $A);
t('pw coinsnap network down -> retry', false === $r['paid'] && true === $r['retry']);
setup_api([['/api/v1/stores/', resp(503, 'down')], ['/api/stores/', resp(404, 'no such route')]]);
$r = WebhookHelper::parse_webhook('coinsnap', ['invoiceId' => 'invP', 'type' => 'Settled'], $A);
t('pw coinsnap v1 5xx + legacy path 404 -> retry', false === $r['paid'] && true === $r['retry']);
setup_api([['/invoices/invP', resp(200, ['status' => 'New'] + $settled)]]);
$r = WebhookHelper::parse_webhook('coinsnap', ['invoiceId' => 'invP', 'type' => 'Settled'], $A);
t('pw forged Settled (API says New) not paid', false === $r['paid']);
foreach (['New', 'Expired', 'Invalid', 'Processing'] as $st) { setup_api([['/invoices/invP', resp(200, ['status' => $st] + $settled)]]); t("pw status $st not paid", false === WebhookHelper::parse_webhook('coinsnap', ['invoiceId' => 'invP', 'type' => $st], $A)['paid']); }
setup_api([['/invoices/invP', resp(200, ['fiatAmount' => '1.00'] + $settled)]]);
t('pw underpaid not paid', false === WebhookHelper::parse_webhook('coinsnap', ['invoiceId' => 'invP'], $A)['paid']);
setup_api([['/invoices/invP', resp(200, ['currency' => 'USD'] + $settled)]]);
t('pw wrong currency not paid', false === WebhookHelper::parse_webhook('coinsnap', ['invoiceId' => 'invP'], $A)['paid']);
setup_api([['/invoices/invP', resp(200, $settled)]]);
t('pw no instance -> never paid', false === WebhookHelper::parse_webhook('coinsnap', ['invoiceId' => 'invP', 'type' => 'Settled'])['paid'] && $GLOBALS['doing_wrong'] > 0);
t('pw invoiceId non-string ignored', '' === WebhookHelper::parse_webhook('coinsnap', ['invoiceId' => ['x']], $A)['invoice_id']);
setup_api([['/invoices/invP', resp(200, $settled)]]);
t('pw btcpay paid', true === WebhookHelper::parse_webhook('btcpay', ['invoiceId' => 'invP', 'type' => 'InvoiceSettled'], $A)['paid']);
setup_api([['/invoices/invP', resp(200, ['additionalStatus' => 'Marked'] + $settled)]]);
t('pw btcpay Marked not paid', false === WebhookHelper::parse_webhook('btcpay', ['invoiceId' => 'invP', 'type' => 'InvoiceSettled'], $A)['paid']);
setup_api([['/invoices/', resp(404, '')]]);
$r = WebhookHelper::parse_webhook('btcpay', ['invoiceId' => 'nope'], $A);
t('pw btcpay 404: no retry', false === $r['paid'] && false === $r['retry']);
setup_api([['/invoices/', resp(500, '')]]);
t('pw btcpay 500: retry', true === WebhookHelper::parse_webhook('btcpay', ['invoiceId' => 'invP'], $A)['retry']);
$l = log_text($A);
t('pw logs forged status', strpos($l, 'SECURITY webhook reason=forged status invoice=invP') !== false);
t('pw logs unknown invoice', strpos($l, 'reason=unknown invoice') !== false);
t('pw logs retry', strpos($l, 'reason=retry: API unavailable') !== false);
t('pw logs status New', strpos($l, 'reason=status New') !== false);
t('pw log has no key/store id', strpos($l, 'SECRET') === false && strpos($l, 'STOREID') === false);

// ---------- create_invoice: orderId and metadata ----------
// Captures the create-invoice request body for both providers (fake HTTP, no real API).
function capture_create(string $prov, array $invoice_data, string $currency = 'EUR', int $amount = 1500): array {
    setup_api([]);
    $GLOBALS['captured'] = null;
    $GLOBALS['http'] = [['POST ', function ($url, $args) { $GLOBALS['captured'] = json_decode($args['body'], true); return resp(200, ['id' => 'invNew', 'checkoutLink' => 'https://pay.test/i/invNew']); }]];
    $p = $prov === 'btcpay' ? new BTCPayProvider($GLOBALS['A']) : new CoinsnapProvider($GLOBALS['A']);
    $r = $p->create_invoice(42, $amount, $currency, $invoice_data);
    return [$r, $GLOBALS['captured'] ?? []];
}
$full = [
    'order_id'    => 'donation_123',
    'email'       => 'jane@example.test',
    'name'        => 'Jane Doe',
    'description' => 'Thank you',
    'metadata'    => [
        'platform'   => 'wordpress',
        'campaignId' => '7',
        'publicDonor' => '0',
        'satsamount' => '',
        'lineItems'  => [['name' => 'Donation', 'quantity' => 1, 'price' => 15, 'sku' => ''], ['name' => '', 'quantity' => null]],
        'formFields' => ['Company' => 'ACME', 'Empty' => ''],
        'nothing'    => [],
    ],
];
foreach (['coinsnap', 'btcpay'] as $prov) {
    [$r, $b] = capture_create($prov, $full);
    $m = $b['metadata'] ?? [];
    t("ci $prov invoice returned", ($r['invoice_id'] ?? '') === 'invNew');
    t("ci $prov metadata.orderId", ($m['orderId'] ?? null) === 'donation_123');
    t("ci $prov metadata.orderNumber defaults to order_id", ($m['orderNumber'] ?? null) === 'donation_123');
    t("ci $prov buyer fields", ($m['buyerEmail'] ?? '') === 'jane@example.test' && ($m['buyerName'] ?? '') === 'Jane Doe' && ($m['customerName'] ?? '') === 'Jane Doe' && ($m['itemDesc'] ?? '') === 'Thank you');
    t("ci $prov legacy keys kept", ($m['form_id'] ?? null) === 42 && ($m['email'] ?? '') === 'jane@example.test');
    t("ci $prov custom metadata passed through", ($m['platform'] ?? '') === 'wordpress' && ($m['campaignId'] ?? '') === '7' && ($m['publicDonor'] ?? null) === '0');
    t("ci $prov nested arrays passed, empties dropped", ($m['lineItems'] ?? null) === [['name' => 'Donation', 'quantity' => 1, 'price' => 15]] && ($m['formFields'] ?? null) === ['Company' => 'ACME']);
    t("ci $prov empty values not sent", !array_key_exists('satsamount', $m) && !array_key_exists('nothing', $m));
    t("ci $prov amount", (string) ($b['amount'] ?? '') === '15');
}
[, $b] = capture_create('coinsnap', $full);
t('ci coinsnap top-level orderId', ($b['orderId'] ?? null) === 'donation_123');
t('ci coinsnap top-level buyerEmail', ($b['buyerEmail'] ?? null) === 'jane@example.test');
t('ci coinsnap referralCode kept', array_key_exists('referralCode', $b));
[, $b] = capture_create('btcpay', $full);
t('ci btcpay no top-level orderId/buyerEmail (BTCPay reads metadata)', !array_key_exists('orderId', $b) && !array_key_exists('buyerEmail', $b));
// Caller metadata wins; top-level orderId follows metadata.orderId.
[, $b] = capture_create('coinsnap', ['order_id' => 'A1', 'metadata' => ['orderId' => 'B2', 'orderNumber' => 'No. 5']]);
t('ci metadata.orderId wins and top-level matches', ($b['orderId'] ?? '') === 'B2' && ($b['metadata']['orderId'] ?? '') === 'B2' && ($b['metadata']['orderNumber'] ?? '') === 'No. 5');
[, $b] = capture_create('btcpay', ['order_id' => 'A1', 'order_number' => '1001']);
t('ci order_number sets orderNumber', ($b['metadata']['orderId'] ?? '') === 'A1' && ($b['metadata']['orderNumber'] ?? '') === '1001');
[, $b] = capture_create('coinsnap', ['metadata' => ['orderId' => 77]]);
t('ci numeric orderId sent as string', ($b['orderId'] ?? null) === '77' && ($b['metadata']['orderId'] ?? null) === '77');
// Nothing given: no orderId/buyerEmail sent (Coinsnap then makes its own), no empty strings in metadata.
[, $b] = capture_create('coinsnap', []);
t('ci empty data: no orderId, no buyerEmail', !array_key_exists('orderId', $b) && !array_key_exists('buyerEmail', $b));
t('ci empty data: metadata has no empty strings', $b['metadata'] === ['form_id' => 42]);
[, $b] = capture_create('coinsnap', ['buyer_email' => 'old@example.test']);
t('ci buyer_email alias still works', ($b['buyerEmail'] ?? '') === 'old@example.test' && ($b['metadata']['buyerEmail'] ?? '') === 'old@example.test');
[, $b] = capture_create('btcpay', ['order_id' => 'S1'], 'SATS', 2100);
t('ci btcpay SATS amount unchanged', ($b['amount'] ?? '') === '2100' && ($b['metadata']['orderId'] ?? '') === 'S1');
[$r, $b] = capture_create('coinsnap', ['order_id' => 'R1']);
$rec = get_option('coinsnap_core_inv_' . md5('plugA_settings|invNew'));
t('ci amount record still written', is_array($rec) && $rec['amount'] === '15.00000000' && $rec['currency'] === 'EUR');

// ---------- create_invoice: the server's refusal reaches the caller ----------
$dust = ['message' => 'Amount is below the on-chain dust threshold. Enable Lightning on this store to accept smaller amounts.'];
setup_api([['/api/v1/stores/', resp(400, $dust)], ['/api/stores/', resp(404, '<pre>Cannot POST</pre>')]]);
$r = (new CoinsnapProvider($A))->create_invoice(42, 100, 'SATS', []);
t('ci 400 with message: invoice_refused + message', ($r['error'] ?? '') === 'invoice_refused' && ($r['status'] ?? 0) === 400 && ($r['message'] ?? '') === $dust['message'] && empty($r['invoice_id']));
t('ci 400: legacy path not tried', 1 === count($GLOBALS['http_log']));
setup_api([['/api/v1/stores/', resp(422, ['message' => '<b>Bad</b> currency'])]]);
$r = (new CoinsnapProvider($A))->create_invoice(42, 100, 'SATS', []);
t('ci 422 message is plain text', ($r['error'] ?? '') === 'invoice_refused' && ($r['message'] ?? '') === 'Bad currency');
setup_api([['/api/v1/stores/', resp(400, ['error' => 'x'])]]);
$r = (new CoinsnapProvider($A))->create_invoice(42, 100, 'SATS', []);
t('ci 400 without message: invoice_refused, empty message', ($r['error'] ?? '') === 'invoice_refused' && ($r['message'] ?? null) === '');
// Auth and server errors stay an empty result (nothing from them is shown to visitors).
foreach ([[401, ['message' => 'Invalid API key']], [403, ['message' => 'Forbidden']], [500, ['message' => 'boom']]] as [$code, $b]) {
    setup_api([['/api/v1/stores/', resp($code, $b)], ['/api/stores/', resp(404, 'nope')]]);
    t("ci $code -> empty result", [] === (new CoinsnapProvider($A))->create_invoice(42, 100, 'SATS', []));
}

// ---------- Logger ----------
$lg = new Logger('plugZ-logs', 'plugZ.log', 'debug');
$lg->error('ctx test', ['api_key' => 'K1', 'nested' => ['webhookSecret' => 'S1', 'Authorization' => 'token T1'], 'BTCPay-Sig' => 'sha256=abc', 'note' => 'fine']);
$f = $lg->get_log_file_path();
$c = (string) file_get_contents($f);
t('logger redacts keys/secrets/auth', strpos($c, 'K1') === false && strpos($c, 'S1') === false && strpos($c, 'T1') === false && strpos($c, 'fine') !== false);
t('logger file name not guessable', basename($f) !== 'plugZ.log' && preg_match('/^plugZ-[0-9a-f]{20}\.log$/', basename($f)) === 1);
t('logger .htaccess present', is_file(dirname($f) . '/.htaccess'));
// old public log moved
$d = $GLOBALS['UPLOADS'] . '/plugY-logs'; @mkdir($d, 0777, true); file_put_contents("$d/plugY.log", "old\n");
$ly = new Logger('plugY-logs', 'plugY.log');
t('logger moves old public log', !file_exists("$d/plugY.log") && strpos((string) file_get_contents($ly->get_log_file_path()), 'old') === 0);

echo "\n$pass passed, $fail failed (PHP " . PHP_VERSION . ")\n";
exit($fail ? 1 : 0);
