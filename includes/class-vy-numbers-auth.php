<?php
/**
 * VY Numbers – Founder Authentication
 *
 * @package VY_Numbers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles founder number authentication for merchandise purchases.
 */
class VY_Numbers_Auth {

    /**
     * Session key for storing verified founder number.
     */
    const SESSION_KEY = 'vy_verified_founder_number';

    /**
     * Initialize authentication hooks.
     */
    public static function init() {
        // Start session if not already started.
        add_action( 'init', array( __CLASS__, 'maybe_start_session' ), 1 );

        // AJAX endpoints for authentication.
        add_action( 'wp_ajax_vy_verify_founder', array( __CLASS__, 'ajax_verify_founder' ) );
        add_action( 'wp_ajax_nopriv_vy_verify_founder', array( __CLASS__, 'ajax_verify_founder' ) );

        // Logout endpoint.
        add_action( 'wp_ajax_vy_founder_logout', array( __CLASS__, 'ajax_founder_logout' ) );
        add_action( 'wp_ajax_nopriv_vy_founder_logout', array( __CLASS__, 'ajax_founder_logout' ) );

        // Clear verification on actual logout.
        add_action( 'wp_logout', array( __CLASS__, 'clear_verification' ) );
    }

    /**
     * Start PHP session if not already started.
     */
    public static function maybe_start_session() {
        if ( ! session_id() && ! headers_sent() ) {
            session_start();
        }
    }

    /**
     * AJAX handler for founder verification.
     */
    public static function ajax_verify_founder() {
        check_ajax_referer( 'vy_founder_auth', 'nonce' );

        $number   = isset( $_POST['number'] ) ? sanitize_text_field( wp_unslash( $_POST['number'] ) ) : '';
        $password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';

        if ( empty( $number ) || empty( $password ) ) {
            wp_send_json_error( array( 'message' => 'Please enter both number and password.' ) );
        }

        // Validate number format.
        if ( ! preg_match( '/^\d{4}$/', $number ) ) {
            wp_send_json_error( array( 'message' => 'Invalid number format. Must be 4 digits.' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'vy_numbers';

        // Get number record.
        $safe_table = esc_sql( $table );
        $sql        = sprintf( 'SELECT num, status, password_hash FROM `%s` WHERE num = %%s LIMIT 1', $safe_table );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $record = $wpdb->get_row( $wpdb->prepare( $sql, $number ), ARRAY_A );

        if ( ! $record ) {
            wp_send_json_error( array( 'message' => 'Number not found.' ) );
        }

        if ( 'sold' !== $record['status'] ) {
            wp_send_json_error( array( 'message' => 'This number is not yet sold. Purchase a founder number first.' ) );
        }

        if ( empty( $record['password_hash'] ) ) {
            wp_send_json_error( array( 'message' => 'No password set for this number. Please contact support.' ) );
        }

        // Verify password.
        if ( ! password_verify( $password, $record['password_hash'] ) ) {
            wp_send_json_error( array( 'message' => 'Incorrect password.' ) );
        }

        // Store verified number in session.
        self::set_verified_number( $number );

        wp_send_json_success( array(
            'message' => 'Authentication successful! You can now purchase merchandise.',
            'number'  => $number,
        ) );
    }

    /**
     * AJAX handler for founder logout.
     */
    public static function ajax_founder_logout() {
        check_ajax_referer( 'vy_founder_auth', 'nonce' );

        self::clear_verification();

        wp_send_json_success( array( 'message' => 'Logged out successfully.' ) );
    }

    /**
     * Store verified founder number in session.
     *
     * @param string $number The founder number.
     */
    public static function set_verified_number( $number ) {
        if ( isset( $_SESSION ) ) {
            $_SESSION[ self::SESSION_KEY ] = $number;
        }
    }

    /**
     * Get verified founder number from session.
     *
     * @return string|null The verified number or null.
     */
    public static function get_verified_number() {
        if ( isset( $_SESSION[ self::SESSION_KEY ] ) ) {
            return $_SESSION[ self::SESSION_KEY ];
        }
        return null;
    }

    /**
     * Check if a founder number is verified in the current session.
     *
     * @return bool True if verified, false otherwise.
     */
    public static function is_verified() {
        return ! empty( self::get_verified_number() );
    }

    /**
     * Clear verification from session.
     */
    public static function clear_verification() {
        if ( isset( $_SESSION[ self::SESSION_KEY ] ) ) {
            unset( $_SESSION[ self::SESSION_KEY ] );
        }
    }

    /**
     * Set password for a founder number (admin only).
     *
     * @param string $number   The founder number.
     * @param string $password The password to set.
     * @return bool True on success, false on failure.
     */
    public static function set_password( $number, $password ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'vy_numbers';

        $hash = password_hash( $password, PASSWORD_DEFAULT );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $updated = $wpdb->update(
            $table,
            array( 'password_hash' => $hash ),
            array( 'num' => $number ),
            array( '%s' ),
            array( '%s' )
        );

        return false !== $updated;
    }

    /**
     * Generate a random password for a founder number.
     *
     * @param int $length Password length.
     * @return string The generated password.
     */
    public static function generate_password( $length = 12 ) {
        return wp_generate_password( $length, false );
    }

    /**
     * Check if a number has a password set.
     *
     * @param string $number The founder number.
     * @return bool True if password is set, false otherwise.
     */
    public static function has_password( $number ) {
        global $wpdb;
        $table = $wpdb->prefix . 'vy_numbers';

        $safe_table = esc_sql( $table );
        $sql        = sprintf( 'SELECT password_hash FROM `%s` WHERE num = %%s LIMIT 1', $safe_table );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $hash = $wpdb->get_var( $wpdb->prepare( $sql, $number ) );

        return ! empty( $hash );
    }

    /**
     * Verify password for a founder number.
     *
     * @param string $number   The founder number.
     * @param string $password The password to verify.
     * @return bool True if password is correct, false otherwise.
     */
    public static function verify_password( $number, $password ) {
        global $wpdb;
        $table = $wpdb->prefix . 'vy_numbers';

        $safe_table = esc_sql( $table );
        $sql        = sprintf( 'SELECT num, status, password_hash FROM `%s` WHERE num = %%s LIMIT 1', $safe_table );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $record = $wpdb->get_row( $wpdb->prepare( $sql, $number ), ARRAY_A );

        if ( ! $record ) {
            return false;
        }

        if ( 'sold' !== $record['status'] ) {
            return false;
        }

        if ( empty( $record['password_hash'] ) ) {
            return false;
        }

        // Verify password.
        return password_verify( $password, $record['password_hash'] );
    }
}

// Boot it.
VY_Numbers_Auth::init();
