<?php
/**
 * Webhook verification and parsing helpers.
 *
 * @package coinsnap-core
 */

declare(strict_types=1);

namespace CoinsnapCore\Rest;

use CoinsnapCore\PluginInstance;
use CoinsnapCore\Providers\CoinsnapProvider;
use CoinsnapCore\Providers\BTCPayProvider;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static utilities for webhook signature verification and data parsing.
 * Consuming plugins call these in their own REST route handlers.
 */
class WebhookHelper {

    /**
     * Verify Coinsnap webhook signature.
     *
     * @param PluginInstance $instance Plugin config (for reading webhook secret).
     * @return bool True if signature is valid.
     */
    public static function verify_coinsnap_signature( PluginInstance $instance ): bool {
        $provider = new CoinsnapProvider( $instance );
        return $provider->verify_signature();
    }

    /**
     * Verify BTCPay webhook signature.
     *
     * @param PluginInstance $instance Plugin config (for reading webhook secret).
     * @return bool True if signature is valid.
     */
    public static function verify_btcpay_signature( PluginInstance $instance ): bool {
        $provider = new BTCPayProvider( $instance );
        return $provider->verify_signature();
    }

    /**
     * Parse webhook data into a standard format.
     *
     * Only the invoice id is taken from the request. Pass the plugin instance so 'paid' is answered by the
     * payment server's API; without it 'paid' is always false, since the request body proves nothing.
     *
     * @param string              $provider Provider name ('coinsnap' or 'btcpay').
     * @param array               $data     Raw webhook JSON data.
     * @param PluginInstance|null $instance Plugin config, used to ask the API.
     * @return array { invoice_id: string, paid: bool, retry: bool, type: string, metadata: array }
     */
    public static function parse_webhook( string $provider, array $data, ?PluginInstance $instance = null ): array {
        $invoice_id = isset( $data['invoiceId'] ) ? (string) $data['invoiceId'] : '';
        $type       = isset( $data['type'] ) ? (string) $data['type'] : '';
        $paid       = false;
        $retry      = false;

        if ( null === $instance ) {
            _doing_it_wrong( __METHOD__, 'Pass the PluginInstance so the invoice status comes from the API.', '1.1.0' );
        } elseif ( '' !== $invoice_id ) {
            $client = ( 'btcpay' === $provider ) ? new BTCPayProvider( $instance ) : new CoinsnapProvider( $instance );
            $status = $client->check_invoice_status( $invoice_id );
            $paid   = ! empty( $status['paid'] );
            $retry  = ! empty( $status['retry'] );
        }

        return array(
            'invoice_id' => $invoice_id,
            'paid'       => $paid,
            // The API could not be asked: reply 503 so the payment server sends the webhook again.
            'retry'      => $retry,
            'type'       => $type,
            'metadata'   => $data,
        );
    }
}
