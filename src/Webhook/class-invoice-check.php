<?php
/**
 * Whether an invoice, as the payment server's API reports it, pays one of this plugin's payments.
 *
 * @package coinsnap-core
 */

declare(strict_types=1);

namespace CoinsnapCore\Webhook;

use CoinsnapCore\Database\PaymentTable;
use CoinsnapCore\PluginInstance;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class InvoiceCheck {

    /**
     * Settled, not marked paid by hand in BTCPay, and created through this plugin (it has a payment row).
     *
     * @param PluginInstance $instance   Plugin config.
     * @param string         $invoice_id Invoice id.
     * @param array          $invoice    Invoice as returned by the API.
     * @param bool           $is_btcpay  Whether the invoice comes from BTCPay Server.
     */
    public static function is_paid( PluginInstance $instance, string $invoice_id, array $invoice, bool $is_btcpay ): bool {
        if ( 'Settled' !== ( $invoice['status'] ?? '' ) ) {
            return false;
        }
        if ( $is_btcpay && 'Marked' === ( $invoice['additionalStatus'] ?? '' ) ) {
            return false;
        }
        return self::created_here( $instance, $invoice_id );
    }

    /**
     * An invoice with no payment row was not created by this plugin. Plugins that keep no table skip the check.
     */
    public static function created_here( PluginInstance $instance, string $invoice_id ): bool {
        global $wpdb;
        $table = PaymentTable::table_name( $instance, $wpdb );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return true;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the plugin config.
        return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE payment_invoice_id = %s", $invoice_id ) );
    }
}
