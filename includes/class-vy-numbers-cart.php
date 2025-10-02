<?php
/**
 * VY Numbers – WooCommerce cart and checkout logic
 *
 * @package VY_Numbers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.*

// phpcs:disable Generic.PHP.NoSilencedErrors.Discouraged

/**
 * Handles cart and checkout logic for VY Numbers.
 *
 * Manages number reservation, validation, persistence, and release throughout the cart and order lifecycle.
 */
class VY_Numbers_Cart {

    /**
     * Get the VY Founder product ID from configuration.
     *
     * @return int Product ID.
     */
    private static function get_founder_product_id() {
        return VY_Numbers_Config::get_product_id();
    }

    /**
     * Initialize cart hooks.
     */
    public static function init() {

        // Prevent duplicate numbers from being added to cart.
        add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'prevent_duplicate_founder' ), 20, 3 );

		// belt-and-braces: always force quantity = 1 when a founder number is added.
		add_filter( 'woocommerce_add_to_cart_quantity', array( __CLASS__, 'force_quantity_one' ), 10, 2 );

        // validate and reserve when adding to cart.
        add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'reserve_on_add' ), 10, 3 );

        // attach the number to the cart item meta.
        add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_item_meta' ), 10, 3 );

        // prevent duplicate numbers in the cart and clean up invalid items.
        add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'guard_duplicates' ), 10 );
        add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'clean_invalid_founder_items' ), 5 );

        // persist number to order line item.
        add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'store_item_meta' ), 10, 4 );

        // on successful payment, mark number as sold.
        add_action( 'woocommerce_payment_complete', array( __CLASS__, 'lock_on_payment' ), 10, 1 );

        // release immediately if a cart item is removed.
        add_action( 'woocommerce_remove_cart_item', array( __CLASS__, 'release_on_remove' ), 10, 2 );

        // release on order failure or cancellation (belt and braces; cron also cleans up).
        add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'release_on_order_fail_or_cancel' ), 10, 1 );
        add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'release_on_order_fail_or_cancel' ), 10, 1 );

		// Send founder flow straight to checkout (with loop prevention).
		add_filter( 'woocommerce_add_to_cart_redirect', array( 'VY_Numbers_Cart', 'redirect_to_checkout' ), 99 );

		// Redirect empty cart to homepage to prevent loops.
		add_action( 'template_redirect', array( 'VY_Numbers_Cart', 'redirect_empty_cart_to_homepage' ) );

		// hide the default "added to cart" notice for this flow.
		add_filter( 'wc_add_to_cart_message_html', array( 'VY_Numbers_Cart', 'suppress_added_notice' ), 10, 3 );

		// Additional suppression for VY Founder product specifically.
		add_filter( 'woocommerce_add_to_cart_message_html', array( 'VY_Numbers_Cart', 'suppress_founder_notice' ), 10, 3 );

		// Completely suppress messages for VY Founder product.
		add_filter( 'wc_add_to_cart_message', array( 'VY_Numbers_Cart', 'suppress_founder_message' ), 10, 2 );

		// Note: Empty cart redirect filter also removed to prevent loops.
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
        unset( $quantity );

        // Only validate when actually adding new items to cart (not during checkout processing).
        if ( empty( $_POST['vy_num'] ) ) {
            // If this is VY Founder product and we're not just processing existing cart items.
            if ( self::get_founder_product_id() === (int) $product_id &&
                ( isset( $_POST['add-to-cart'] ) || isset( $_GET['add-to-cart'] ) ) &&
                ! doing_action( 'woocommerce_checkout_order_processed' ) &&
                ! doing_action( 'woocommerce_checkout_create_order' ) &&
                ! ( isset( $_GET['wc-ajax'] ) && 'checkout' === $_GET['wc-ajax'] ) ) {
                wc_add_notice( 'Please select a founder number.', 'error' );
                return false;
            }
            return $passed; // Allow for non-founder products or existing items.
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
        $expires = gmdate( 'Y-m-d H:i:s', time() + VY_Numbers_Config::get_reservation_timeout() );
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
            // Make each founder number unique in cart by adding the number as a key.
            $cart_item_data['unique_key'] = 'founder_' . $num;
        }

        return $cart_item_data;
    }

    /**
     * Remove any VY Founder items (product ID 134) that don't have a founder number.
     */
    public static function clean_invalid_founder_items() {
        if ( empty( WC()->cart ) ) {
            return;
        }

        // Don't run cleanup if we're in the middle of adding an item to cart.
        if ( doing_action( 'woocommerce_add_to_cart' ) ||
            doing_action( 'woocommerce_ajax_added_to_cart' ) ||
            ( isset( $_POST['add-to-cart'] ) && ! empty( $_POST['vy_num'] ) ) ) {
            return;
        }

        foreach ( WC()->cart->get_cart() as $key => $item ) {
            // Check if this is a VY Founder product without a founder number.
            if ( isset( $item['product_id'] ) && self::get_founder_product_id() === (int) $item['product_id'] && empty( $item['vy_num'] ) ) {
                // Remove invalid founder item.
                WC()->cart->remove_cart_item( $key );
                continue;
            }

            // Also check if the number is no longer reserved in database (released via admin).
            if ( isset( $item['vy_num'] ) && self::get_founder_product_id() === (int) $item['product_id'] ) {
                $num = $item['vy_num'];
                if ( self::is_valid_num( $num ) ) {
                    global $wpdb;
                    $table = $wpdb->prefix . 'vy_numbers';

                    // Check current database status.
                    $safe_table     = esc_sql( $table );
                    $status_sql     = sprintf( 'SELECT status FROM `%s` WHERE num = %%s LIMIT 1', $safe_table );
                    $current_status = $wpdb->get_var( $wpdb->prepare( $status_sql, $num ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared WordPress.DB.DirectDatabaseQuery

                    // If number is available (released via admin), remove from cart.
                    if ( 'available' === $current_status ) {
                        WC()->cart->remove_cart_item( $key );
                    }
                }
            }
        }
    }

    /**
     * Prevent duplicate numbers in the cart - keep only the first instance.
     */
    public static function guard_duplicates() {
        if ( empty( WC()->cart ) ) {
            return;
        }

        $seen_numbers = array();

        foreach ( WC()->cart->get_cart() as $key => $item ) {
            if ( empty( $item['vy_num'] ) ) {
                continue;
            }

            $num = $item['vy_num'];

            // If we've already seen this number, remove this duplicate.
            if ( in_array( $num, $seen_numbers, true ) ) {
                WC()->cart->remove_cart_item( $key );
                // Note: No need to release_number() here because the first instance is still in cart.
            } else {
                // First time seeing this number, add it to our tracking array.
                $seen_numbers[] = $num;
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
     * Prevent duplicate numbers from being added to cart while allowing multiple unique numbers.
     *
     * This function ensures that the same founder number cannot be added twice.
     *
     * @param bool $passed      Whether the product can be added to the cart.
     * @param int  $product_id  (Unused) The ID of the product being added.
     * @param int  $quantity    (Unused) The quantity of the product being added.
     * @return bool
     */
    public static function prevent_duplicate_founder( $passed, $product_id, $quantity ) {
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

			// If the same number is already in the cart, don't add it again.
			if ( $old_num === $new_num ) {
				wc_add_notice( 'This number is already in your cart.', 'notice' );
				return false; // Prevent adding duplicate.
			}

			// Allow different numbers - don't remove them!
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
        // Only when our 4-digit flow posted successfully.
        $nonce = isset( $_POST['vy_num_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['vy_num_nonce'] ) ) : '';
        if ( ! empty( $_POST['vy_num'] ) && $nonce && wp_verify_nonce( $nonce, 'vy_num_action' ) ) {
            // Don't redirect if we're in AJAX.
            if ( wp_doing_ajax() ) {
                return $url;
            }

            // Check if we actually have VY Founder items in cart before redirecting.
            if ( function_exists( 'WC' ) && WC()->cart ) {
                foreach ( WC()->cart->get_cart() as $item ) {
                    if ( isset( $item['product_id'] ) && 134 === (int) $item['product_id'] && ! empty( $item['vy_num'] ) ) {
                        return wc_get_checkout_url();
                    }
                }
            }

            // If no valid founder items in cart, redirect to homepage to prevent product page.
            return home_url();
        }
        return $url;
    }

    /**
     * Redirect empty cart to homepage to prevent redirect loops.
     */
    public static function redirect_empty_cart_to_homepage() {
        if ( function_exists( 'is_cart' ) && is_cart() && empty( WC()->cart->get_cart() ) ) {
            wp_safe_redirect( home_url() );
            exit;
        }
        if ( function_exists( 'is_checkout' ) && is_checkout() && empty( WC()->cart->get_cart() ) ) {
            wp_safe_redirect( home_url() );
            exit;
        }
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

    /**
     * Suppress "added to cart" notices specifically for VY Founder product (ID 134).
     *
     * @param string $message   The default add to cart message.
     * @param array  $products  The products added to cart.
     * @param bool   $show_qty  Whether to show quantity.
     * @return string           The modified message (empty if suppressed).
     */
    public static function suppress_founder_notice( $message, $products, $show_qty ) {
        unset( $show_qty );

        // Check if VY Founder product (ID 134) was added.
        if ( is_array( $products ) ) {
            foreach ( $products as $product_id => $quantity ) {
                if ( 134 === (int) $product_id ) {
                    return ''; // Suppress message for VY Founder product.
                }
            }
        }

        return $message;
    }

    /**
     * Suppress "added to cart" message completely for VY Founder product.
     *
     * @param string $message     The add to cart message.
     * @param int    $product_id  The product ID that was added.
     * @return string             Empty string for VY Founder, original message for others.
     */
    public static function suppress_founder_message( $message, $product_id ) {
        if ( 134 === (int) $product_id ) {
            return '';
        }
        return $message;
    }

    /**
     * Prevent WooCommerce from redirecting checkout to cart when founder numbers are present.
     *
     * @param bool $redirect Whether to redirect to cart on empty cart.
     * @return bool False to prevent redirect if founder numbers exist.
     */
    public static function prevent_empty_cart_redirect( $redirect ) {
        // If there are founder numbers in the cart, don't redirect to cart page.
        if ( function_exists( 'WC' ) && WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $item ) {
                if ( ! empty( $item['vy_num'] ) ) {
                    return false; // Don't redirect, we have founder numbers.
                }
            }
        }
        return $redirect;
    }
}
// phpcs:enable

// boot it.
VY_Numbers_Cart::init();
