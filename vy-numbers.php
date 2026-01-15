<?php
/**
 * Plugin Name: VY Numbers
 * Description: Reserve and sell numbers (0001–9999) through WooCommerce.
 * Version:     1.0.2
 * Author:      Lamcat - DS
 *
 * @package     VY_Numbers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Define paths.
 */
define( 'VY_NUMBERS_PATH', plugin_dir_path( __FILE__ ) );
define( 'VY_NUMBERS_URL', plugin_dir_url( __FILE__ ) );

// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

/**
 * Load installer and run db checks.
 */
require_once VY_NUMBERS_PATH . 'includes/class-vy-numbers-installer.php';

/**
 * Load configuration manager.
 */
require_once VY_NUMBERS_PATH . 'includes/class-vy-numbers-config.php';

/**
 * Activation hook — runs once when plugin is activated.
 */
register_activation_hook( __FILE__, array( 'VY_Numbers_Installer', 'install' ) );

/**
 * Flush rewrite rules on activation to register the /founder/[number] URLs.
 */
register_activation_hook(
	__FILE__,
	function () {
		flush_rewrite_rules();
	}
);

/**
 * Ensure db schema is current every time plugin loads.
 */
add_action( 'plugins_loaded', array( 'VY_Numbers_Installer', 'maybe_upgrade' ) );

/**
 * Autoload the rest of our classes.
 * (you can swap this for a proper PSR-4 autoloader later if you want)
 */
foreach ( array(
    'class-vy-numbers-rest.php',
    'class-vy-numbers-cart.php',
    'class-vy-numbers-cron.php',
    'class-vy-numbers-admin.php',
    'class-vy-numbers-shortcode.php',
    'class-vy-numbers-auth.php',
) as $file ) {
    $include_path = VY_NUMBERS_PATH . 'includes/' . $file;
    if ( file_exists( $include_path ) ) {
        require_once $include_path;
    }
}

/**
 * Initialise components.
 * each class will hook itself into WP when loaded.
 */
add_action(
	'init',
	function () {
		// Initialize the shortcode
		if ( class_exists( 'VY_Numbers_Shortcode' ) ) {
			VY_Numbers_Shortcode::init();
		}
		
		// Add rewrite rule for /founder/[number] URLs.
		add_rewrite_rule(
			'^founder/(\d{4})/?$',
			'index.php?pagename=founder&founder_num=$matches[1]',
			'top'
		);
	}
);

/**
 * Add founder_num query var.
 */
add_filter(
	'query_vars',
	function ( $vars ) {
		$vars[] = 'founder_num';
		return $vars;
	}
);

/**
 * Set default password when a founder number is purchased.
 */
add_action(
    'woocommerce_order_status_completed',
    function ( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Check if this order has a founder number.
        $founder_number = $order->get_meta( '_vy_num', true );
        if ( empty( $founder_number ) ) {
            return;
        }

        // Check if password is already set.
        if ( VY_Numbers_Auth::has_password( $founder_number ) ) {
            return;
        }

        // Set default password to the founder number itself.
        VY_Numbers_Auth::set_password( $founder_number, $founder_number );

        // Set the founder date to today.
        global $wpdb;
        $table = $wpdb->prefix . 'vy_numbers';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            array( 'founder_date' => current_time( 'Y-m-d' ) ),
            array( 'num' => $founder_number ),
            array( '%s' ),
            array( '%s' )
        );
    }
);

add_filter(
    'wc_add_to_cart_message_html',
    function ( $message ) {
        if ( ! empty( $_POST['vy_num'] ) && isset( $_POST['_vy_num_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_vy_num_nonce'] ) ), 'vy_num_action' ) ) {
            return ''; // no intermediate notice.
        }
        return $message;
    },
    10,
    1
);

add_filter(
    'woocommerce_is_sold_individually',
    function ( $sold_individually, $product ) {
        // if you have the product ID, check it here; otherwise use the presence of vy_num in POST.
        if (
            isset( $_POST['vy_num'] ) &&
            isset( $_POST['_vy_num_nonce'] ) &&
            wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['_vy_num_nonce'] ) ),
                'vy_num_action'
            ) &&
            $product instanceof WC_Product
        ) {
            return true;
        }
        return $sold_individually;
    },
    10,
    2
);

add_action(
    'template_redirect',
    function () {
        if ( is_cart() && ! is_admin() ) {
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }
    }
);
