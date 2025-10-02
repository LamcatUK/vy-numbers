<?php
/**
 * VY Numbers Plugin Configuration
 *
 * @package VY_Numbers
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * VY Numbers Configuration Settings
 *
 * Modify these values to customize the plugin behavior.
 * After making changes, clear any caches and test thoroughly.
 */
return array(
    /**
     * WooCommerce Product ID
     *
     * The ID of the WooCommerce product that represents the VY Founder membership.
     * This product will be added to the cart when customers select numbers.
     *
     * @type int
     * @default 134
     */
    'product_id' => 134,

    /**
     * Reservation Timeout (seconds)
     *
     * How long to hold a number reservation while a customer completes their purchase.
     * After this time expires, the number becomes available again.
     *
     * Recommended values:
     * - 300 (5 minutes) - Production default
     * - 600 (10 minutes) - For slower checkout processes
     * - 10 (10 seconds) - For testing/development only
     *
     * @type int
     * @default 300
     */
    'reservation_timeout' => 300,

    /**
     * Number Range Settings
     *
     * Configure the range of available founder numbers.
     * Default is 0001-9999 for maximum exclusivity.
     *
     * @type array
     */
    'number_range' => array(
        'min' => 1,    // Minimum number (will be padded to 4 digits).
        'max' => 9999, // Maximum number.
    ),

    /**
     * Admin Settings
     *
     * Configure administrative features and permissions.
     *
     * @type array
     */
    'admin' => array(
        /**
         * Required capability to manage VY Numbers
         *
         * WordPress capability required to access the admin interface.
         * Common values: 'manage_options', 'manage_woocommerce', 'edit_shop_orders'
         *
         * @type string
         * @default 'manage_options'
         */
        'required_capability' => 'manage_options',

        /**
         * Numbers per page in admin table
         *
         * How many numbers to show per page in the admin interface.
         *
         * @type int
         * @default 100
         */
        'numbers_per_page' => 100,
    ),

    /**
     * Frontend Settings
     *
     * Configure the customer-facing interface.
     *
     * @type array
     */
    'frontend' => array(
        /**
         * Default button text for the shortcode
         *
         * @type string
         * @default 'Secure my number'
         */
        'default_button_text' => 'Secure my number',

        /**
         * Show view cart link on homepage
         *
         * Whether to show the "View cart" link when numbers are in cart.
         *
         * @type bool
         * @default true
         */
        'show_cart_link' => true,

        /**
         * Enable real-time availability checking
         *
         * Whether to check number availability as users type.
         *
         * @type bool
         * @default true
         */
        'enable_realtime_checking' => true,
    ),

    /**
     * Performance Settings
     *
     * Configure performance and caching options.
     *
     * @type array
     */
    'performance' => array(
        /**
         * Cache availability responses (seconds)
         *
         * How long to cache number availability responses.
         * Set to 0 to disable caching.
         *
         * @type int
         * @default 0
         */
        'cache_duration' => 0,

        /**
         * Cleanup cron frequency
         *
         * How often to run the cleanup job for expired reservations.
         * Uses WordPress cron scheduling format.
         *
         * @type string
         * @default 'hourly'
         */
        'cleanup_frequency' => 'hourly',
    ),

    /**
     * Security Settings
     *
     * Configure security and validation options.
     *
     * @type array
     */
    'security' => array(
        /**
         * Enable strict validation
         *
         * Whether to enforce strict validation of number formats and ranges.
         *
         * @type bool
         * @default true
         */
        'strict_validation' => true,

        /**
         * Rate limiting (requests per minute)
         *
         * Maximum number of availability check requests per IP per minute.
         * Set to 0 to disable rate limiting.
         *
         * @type int
         * @default 60
         */
        'rate_limit' => 60,
    ),

    /**
     * Debug Settings
     *
     * Configure debugging and logging options.
     * Only enable in development environments.
     *
     * @type array
     */
    'debug' => array(
        /**
         * Enable debug logging
         *
         * Whether to log plugin activities to WordPress debug log.
         * Only enable for troubleshooting.
         *
         * @type bool
         * @default false
         */
        'enable_logging' => false,

        /**
         * Log API requests
         *
         * Whether to log all availability check API requests.
         * Can generate large logs - use with caution.
         *
         * @type bool
         * @default false
         */
        'log_api_requests' => false,
    ),
);
