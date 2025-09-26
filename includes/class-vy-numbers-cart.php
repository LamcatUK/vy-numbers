<?php
/**
 * VY Numbers – WooCommerce cart + checkout logic
 *
 * @package VY_Numbers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.*
/**
 * Handles WooCommerce cart and checkout logic for VY Numbers.
 *
 * Manages number reservation, validation, persistence, and release throughout the cart and order lifecycle.
 */
class VY_Numbers_Cart {

    /**
     * Boot hooks.
     */
    public static function init() {

		// replace any existing founder number in the cart with the new one.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'replace_existing_founder' ), 20, 3 );

		// belt-and-braces: always force quantity = 1 when a founder number is added.
		add_filter( 'woocommerce_add_to_cart_quantity', array( __CLASS__, 'force_quantity_one' ), 10, 2 );

        // validate and reserve when adding to cart.
        add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'reserve_on_add' ), 10, 3 );

        // attach the number to the cart item meta.
        add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_item_meta' ), 10, 3 );

        // prevent duplicate numbers in the cart.
        add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'guard_duplicates' ), 10 );

        // persist number to order line item.
        add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'store_item_meta' ), 10, 4 );

        // on successful payment, mark number as sold.
        add_action( 'woocommerce_payment_complete', array( __CLASS__, 'lock_on_payment' ), 10, 1 );

        // release immediately if a cart item is removed.
        add_action( 'woocommerce_remove_cart_item', array( __CLASS__, 'release_on_remove' ), 10, 2 );

        // release on order failure or cancellation (belt and braces; cron also cleans up).
        add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'release_on_order_fail_or_cancel' ), 10, 1 );
        add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'release_on_order_fail_or_cancel' ), 10, 1 );

		// send founder flow straight to checkout
		add_filter( 'woocommerce_add_to_cart_redirect', array( 'VY_Numbers_Cart', 'redirect_to_checkout' ), 99 );

		// hide the default "added to cart" notice for this flow
		add_filter( 'wc_add_to_cart_message_html', array( 'VY_Numbers_Cart', 'suppress_added_notice' ), 10, 3 );

    }

    /**
     * Validate user input and reserve the number atomically if available.
     *
     * @param bool $passed      Whether the product can be added to the cart.
     * @param int  $product_id  The ID of the product being added.
     * @param int  $quantity    The quantity of the product being added.
     * @return bool
     */
    public static function reserve_on_add( $passed, $product_id, $quantity ) {
        unset( $product_id, $quantity );

        if ( empty( $_POST['vy_num'] ) ) {
            return $passed; // not our product or no number submitted.
        }

        // Nonce verification for security.
        $vy_num_nonce = isset( $_POST['vy_num_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['vy_num_nonce'] ) ) : '';
        if ( empty( $vy_num_nonce ) || ! wp_verify_nonce( $vy_num_nonce, 'vy_num_action' ) ) {
            wc_add_notice( 'Security check failed. Please try again.', 'error' );
            return false;
        }

        $num = sanitize_text_field( wp_unslash( $_POST['vy_num'] ) );
        if ( ! self::is_valid_num( $num ) ) {
            wc_add_notice( 'Please enter a valid 4-digit number between 0001 and 9999.', 'error' );
            return false;
        }

        global $wpdb;
        $table       = $wpdb->prefix . 'vy_numbers';
        $session_key = self::get_session_key();

        // attempt atomic reserve if currently available. Use $wpdb->update with an expiry computed in PHP
        // to avoid interpolating table names inside a prepared SQL string.
        $expires = gmdate( 'Y-m-d H:i:s', time() + ( 15 * MINUTE_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery WordPress.DB.DirectDatabaseQuery.NoCaching WordPress.DB.PreparedSQL.NotPrepared -- intentional update via $wpdb->update with escaped table
		$updated = $wpdb->update(
			$table,
			array(
				'status'          => 'reserved',
				'reserved_by'     => $session_key,
				'reserve_expires' => $expires,
			),
			array(
				'num'    => $num,
				'status' => 'available',
			),
			array( '%s', '%s', '%s' ),
			array( '%s', '%s' )
		);

        if ( 1 === $updated ) {
            return true;
        }

        // if reserved but expired, let cron clear it; user sees a clear message.
        wc_add_notice( 'That number is not available. Please try another.', 'error' );
        return false;
    }

    /**
     * Add the chosen number onto the cart item so it follows through to the order.
     *
     * @param array $cart_item_data The cart item data array.
     * @param int   $product_id The ID of the product being added.
     * @param int   $variation_id The ID of the product variation being added.
     * @return array
     */
    public static function add_item_meta( $cart_item_data, $product_id, $variation_id ) {
        // Mark unused parameters to satisfy PHPCS.
        unset( $product_id, $variation_id );
        // Nonce verification for security.
        $vy_num_nonce = isset( $_POST['vy_num_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['vy_num_nonce'] ) ) : '';
        if ( empty( $_POST['vy_num'] ) || empty( $vy_num_nonce ) || ! wp_verify_nonce( $vy_num_nonce, 'vy_num_action' ) ) {
            return $cart_item_data;
        }

        $num = sanitize_text_field( wp_unslash( $_POST['vy_num'] ) );
        if ( self::is_valid_num( $num ) ) {
            $cart_item_data['vy_num'] = $num;
        }

        return $cart_item_data;
    }

    /**
     * Prevent duplicate numbers within the same cart.
     */
    public static function guard_duplicates() {
        if ( empty( WC()->cart ) ) {
            return;
        }

        $seen = array();

        foreach ( WC()->cart->get_cart() as $key => $item ) {
            if ( empty( $item['vy_num'] ) ) {
                continue;
            }

            $n = $item['vy_num'];
            if ( isset( $seen[ $n ] ) ) {
                WC()->cart->remove_cart_item( $key );
                wc_add_notice( 'Duplicate number removed from your basket.', 'notice' );
            } else {
                $seen[ $n ] = true;
            }
        }
    }

    /**
     * Persist the number to the order line item meta for admin/customer visibility.
     *
     * @param WC_Order_Item_Product $item The order item object.
     * @param string                $cart_item_key The cart item key.
     * @param array                 $values The cart item values.
     * @param WC_Order              $order The order object.
     */
    public static function store_item_meta( $item, $cart_item_key, $values, $order ) {
        unset( $order ); // unused but part of the action signature.

        if ( ! empty( $values['vy_num'] ) ) {
            $item->add_meta_data( 'vy_num', sanitize_text_field( $values['vy_num'] ), true );
        }
    }

    /**
     * On successful payment, mark the number as sold and clear reservation fields.
     *
     * @param int $order_id The ID of the order that has completed payment.
     */
    public static function lock_on_payment( $order_id ) {
        global $wpdb;

	    $order = wc_get_order( $order_id );

    	if ( ! $order ) {
            return;
        }

        $user_id = $order->get_user_id();
        $txn_ref = $order->get_transaction_id();
        $table   = $wpdb->prefix . 'vy_numbers';

        foreach ( $order->get_items() as $item ) {
            $num = $item->get_meta( 'vy_num', true );
            if ( ! $num ) {
                continue;
            }

            // Finalise: mark sold, bind to order/user/txn, clear reservation fields.
            // Read current status first and only update if not already sold to avoid race conditions.
            $safe_table     = esc_sql( $table );
            $status_sql     = sprintf( 'SELECT status FROM `%s` WHERE num = %%s LIMIT 1', $safe_table );
            $cache_key      = 'vy_num_status_' . $num;
            $current_status = wp_cache_get( $cache_key, 'vy_numbers' );
            if ( false === $current_status ) {
                $current_status = $wpdb->get_var( $wpdb->prepare( $status_sql, $num ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared WordPress.DB.DirectDatabaseQuery
                wp_cache_set( $cache_key, $current_status, 'vy_numbers', MINUTE_IN_SECONDS );
            }

            if ( 'sold' !== $current_status ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- intentional update via $wpdb->update
                $wpdb->update(
                    $table,
                    array(
                        'status'          => 'sold',
                        'order_id'        => $order_id,
                        'user_id'         => $user_id,
                        'txn_ref'         => $txn_ref,
                        'reserved_by'     => null,
                        'reserve_expires' => null,
                    ),
                    array( 'num' => $num ),
                    array( '%s', '%d', '%d', '%s', '%s', '%s' ),
                    array( '%s' )
                );
                // Keep cache in sync.
                wp_cache_delete( 'vy_num_status_' . $num, 'vy_numbers' );
            }
        }
    }

    /**
     * If an item is removed from the cart, release its reservation immediately.
     *
     * @param string  $cart_item_key The cart item key that was removed.
     * @param WC_Cart $cart The WooCommerce cart object.
     */
    public static function release_on_remove( $cart_item_key, $cart ) {
        $item = $cart->removed_cart_contents[ $cart_item_key ] ?? null;
        if ( empty( $item['vy_num'] ) ) {
            return;
        }

        self::release_number( $item['vy_num'] );
    }

    /**
     * On order failure/cancellation, release any reserved numbers on the order.
     *
     * @param int $order_id The ID of the order that failed or was cancelled.
     */
    public static function release_on_order_fail_or_cancel( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        foreach ( $order->get_items() as $item ) {
            $num = $item->get_meta( 'vy_num', true );
            if ( $num ) {
                self::release_number( $num );
            }
        }
    }

    /**
     * Release helper: set status back to available if currently reserved and past expiry or reserved by any session.
     *
     * @param string $num The number to be released.
     */
    protected static function release_number( $num ) {
        if ( ! self::is_valid_num( $num ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'vy_numbers';

        // Only flip reserved rows back to available; leave sold untouched.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional update via $wpdb->update
		$wpdb->update(
			$table,
			array(
				'status'          => 'available',
				'reserved_by'     => null,
				'reserve_expires' => null,
			),
			array(
				'num'    => $num,
				'status' => 'reserved',
			),
			array( '%s', '%s', '%s' ),
			array( '%s', '%s' )
		);
		// Keep cache in sync.
		wp_cache_delete( 'vy_num_status_' . $num, 'vy_numbers' );
	}

    /**
     * Validate number format and range.
	 *
	 * @param string $num The number to be validated.
     */
    protected static function is_valid_num( $num ) {
        if ( ! preg_match( '/^\d{4}$/', $num ) ) {
            return false;
        }
        $i = (int) $num;
        return $i >= 1 && $i <= 9999;
    }

    /**
     * Derive a stable session key for reservations (works for guests and logged-in users).
     */
    protected static function get_session_key() {
        if ( function_exists( 'WC' ) && WC()->session ) {
            $key = WC()->session->get_customer_id();
            if ( ! empty( $key ) ) {
                return $key;
            }
        }

        // fallback for edge cases.
        return wp_get_session_token() ? wp_get_session_token() : wp_generate_uuid4();
    }


    /**
     * Replace any existing founder number in the cart with the new one.
     *
     * This function ensures that only one founder number is present in the cart at a time.
     *
     * @param bool $passed      Whether the product can be added to the cart.
     * @param int  $product_id  (Unused) The ID of the product being added.
     * @param int  $quantity    (Unused) The quantity of the product being added.
     * @return bool
     */
    public static function replace_existing_founder( $passed, $product_id, $quantity ) {
        unset( $product_id, $quantity );
        $vy_num_nonce = isset( $_POST['vy_num_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['vy_num_nonce'] ) ) : '';
        if ( ! $passed || empty( $_POST['vy_num'] ) || empty( WC()->cart ) || empty( $vy_num_nonce ) || ! wp_verify_nonce( $vy_num_nonce, 'vy_num_action' ) ) {
            return $passed;
        }

        $new_num = sanitize_text_field( wp_unslash( $_POST['vy_num'] ) );

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( empty( $item['vy_num'] ) ) {
				continue;
			}

			$old_num = $item['vy_num'];

			// if the same number is already in the cart, keep it and just force qty = 1.
			if ( $old_num === $new_num ) {
				WC()->cart->set_quantity( $key, 1 );
				continue;
			}

			// remove different founder-number items and free their holds.
			WC()->cart->remove_cart_item( $key );
			self::release_number( $old_num );
		}

		return $passed;
	}

    /**
     * Force quantity to 1 when a founder number is added to the cart.
     *
     * @param int $qty The quantity being added.
     * @param int $product_id The ID of the product being added (unused).
     * @return int
     */
    public static function force_quantity_one( $qty, $product_id ) {
        unset( $product_id );
        $vy_num_nonce = isset( $_POST['vy_num_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['vy_num_nonce'] ) ) : '';
        if ( ! empty( $_POST['vy_num'] ) && ! empty( $vy_num_nonce ) && wp_verify_nonce( $vy_num_nonce, 'vy_num_action' ) ) {
            return 1;
        }
        return $qty;
    }

    /**
     * Redirect to checkout after adding a founder number to the cart.
     *
     * @param string $url The default redirect URL.
     * @return string The checkout URL if a founder number was added, otherwise the original URL.
     */
    public static function redirect_to_checkout( $url ) {
        // only when our 4-digit flow posted successfully.
        $nonce = isset( $_POST['vy_num_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['vy_num_nonce'] ) ) : '';
        if ( ! empty( $_POST['vy_num'] ) && $nonce && wp_verify_nonce( $nonce, 'vy_num_action' ) ) {
            return wc_get_checkout_url();
        }
        return $url;
    }

    /**
     * Suppress the default WooCommerce "added to cart" notice for founder number flow.
     *
     * @param string $message   The default add to cart message.
     * @param array  $products  The products added to cart.
     * @param bool   $show_qty  Whether to show quantity.
     * @return string           The modified message (empty if suppressed).
     */
    public static function suppress_added_notice( $message, $products, $show_qty ) {
        unset( $products, $show_qty );
        $nonce = isset( $_POST['vy_num_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['vy_num_nonce'] ) ) : '';
        if ( ! empty( $_POST['vy_num'] ) && $nonce && wp_verify_nonce( $nonce, 'vy_num_action' ) ) {
            return '';
        }
        return $message;
    }

}
// phpcs:enable

// boot it.
VY_Numbers_Cart::init();
