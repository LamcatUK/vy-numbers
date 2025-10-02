<?php
/**
 * VY Numbers Configuration Manager
 *
 * @package VY_Numbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages configuration settings for the VY Numbers plugin.
 */
class VY_Numbers_Config {

    /**
     * Configuration data.
     *
     * @var array
     */
    private static $config = null;

    /**
     * Load configuration from config.php file.
     *
     * @return array Configuration array.
     */
    public static function get_config() {
        if ( null === self::$config ) {
            $config_file = plugin_dir_path( __FILE__ ) . '../config.php';

            if ( file_exists( $config_file ) ) {
                self::$config = include $config_file;
            } else {
                // Fallback default configuration.
                self::$config = self::get_default_config();
            }
        }

        return self::$config;
    }

    /**
     * Get a specific configuration value.
     *
     * @param string $key Configuration key (supports dot notation for nested values).
     * @param mixed  $default Default value if key not found.
     * @return mixed Configuration value.
     */
    public static function get( $key, $default = null ) {
        $config = self::get_config();

        // Support dot notation for nested values (e.g., 'admin.required_capability').
        if ( strpos( $key, '.' ) !== false ) {
            $keys  = explode( '.', $key );
            $value = $config;

            foreach ( $keys as $k ) {
                if ( ! is_array( $value ) || ! isset( $value[ $k ] ) ) {
                    return $default;
                }
                $value = $value[ $k ];
            }

            return $value;
        }

        return isset( $config[ $key ] ) ? $config[ $key ] : $default;
    }

    /**
     * Get the WooCommerce product ID for VY Founder membership.
     *
     * @return int Product ID.
     */
    public static function get_product_id() {
        return (int) self::get( 'product_id', 134 );
    }

    /**
     * Get the reservation timeout in seconds.
     *
     * @return int Timeout in seconds.
     */
    public static function get_reservation_timeout() {
        return (int) self::get( 'reservation_timeout', 300 );
    }

    /**
     * Get the minimum founder number.
     *
     * @return int Minimum number.
     */
    public static function get_min_number() {
        return (int) self::get( 'number_range.min', 1 );
    }

    /**
     * Get the maximum founder number.
     *
     * @return int Maximum number.
     */
    public static function get_max_number() {
        return (int) self::get( 'number_range.max', 9999 );
    }

    /**
     * Get the required capability for admin access.
     *
     * @return string WordPress capability.
     */
    public static function get_admin_capability() {
        return self::get( 'admin.required_capability', 'manage_options' );
    }

    /**
     * Get the default button text for the shortcode.
     *
     * @return string Button text.
     */
    public static function get_default_button_text() {
        return self::get( 'frontend.default_button_text', 'Secure my number' );
    }

    /**
     * Check if cart link should be shown.
     *
     * @return bool Whether to show cart link.
     */
    public static function show_cart_link() {
        return (bool) self::get( 'frontend.show_cart_link', true );
    }

    /**
     * Check if real-time checking is enabled.
     *
     * @return bool Whether real-time checking is enabled.
     */
    public static function is_realtime_checking_enabled() {
        return (bool) self::get( 'frontend.enable_realtime_checking', true );
    }

    /**
     * Check if debug logging is enabled.
     *
     * @return bool Whether debug logging is enabled.
     */
    public static function is_debug_logging_enabled() {
        return (bool) self::get( 'debug.enable_logging', false );
    }

    /**
     * Get default configuration array.
     *
     * @return array Default configuration.
     */
    private static function get_default_config() {
        return array(
            'product_id'          => 134,
            'reservation_timeout' => 300,
            'number_range'        => array(
                'min' => 1,
                'max' => 9999,
            ),
            'admin'               => array(
                'required_capability' => 'manage_options',
                'numbers_per_page'    => 100,
            ),
            'frontend'            => array(
                'default_button_text'      => 'Secure my number',
                'show_cart_link'           => true,
                'enable_realtime_checking' => true,
            ),
            'performance'         => array(
                'cache_duration'    => 0,
                'cleanup_frequency' => 'hourly',
            ),
            'security'            => array(
                'strict_validation' => true,
                'rate_limit'        => 60,
            ),
            'debug'               => array(
                'enable_logging'   => false,
                'log_api_requests' => false,
            ),
        );
    }
}
