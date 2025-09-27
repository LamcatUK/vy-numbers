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
 * Activation hook — runs once when plugin is activated.
 */
register_activation_hook( __FILE__, array( 'VY_Numbers_Installer', 'install' ) );

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
    	// placeholder if you want central init calls.
	}
);

// Disabled to prevent redirect loops - handled in VY_Numbers_Cart class
// add_filter(
//     'woocommerce_add_to_cart_redirect',
//     function ( $url ) {
//         if ( ! empty( $_POST['vy_num'] ) && isset( $_POST['_vy_num_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_vy_num_nonce'] ) ), 'vy_num_action' ) ) {
//             return wc_get_checkout_url();
//         }
//         return $url;
//     }
// );

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
