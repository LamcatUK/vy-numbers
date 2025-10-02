<?php
/**
 * VY Numbers – REST API
 *
 * @package VY_Numbers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles REST API endpoints for VY Numbers plugin.
 */
class VY_Numbers_REST {

    /**
     * Register routes.
     */
    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /**
     * Define /vy/v1/number/{num} endpoint.
     */
    public static function register_routes() {
        register_rest_route(
            'vy/v1',
            '/number/(?P<num>\d{4})',
            array(
                'methods'             => array( 'GET', 'POST' ),
                'callback'            => array( __CLASS__, 'check_number' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Callback for availability check.
     *
     * @param WP_REST_Request $request The request.
     * @return WP_REST_Response
     */
    public static function check_number( WP_REST_Request $request ) {
        global $wpdb;

        $num   = $request->get_param( 'num' );
        $table = $wpdb->prefix . 'vy_numbers';

        // Debug: Log the request
        error_log( 'VY Numbers API: Checking number ' . $num );

        // make sure num is four digits between 0001 and 9999.
        if ( ! preg_match( '/^\d{4}$/', $num ) || (int) $num < 1 || (int) $num > 9999 ) {
            return new WP_REST_Response(
                array(
                    'status'  => 'invalid',
                    'message' => 'Number must be between 0001 and 9999.',
                ),
                400
            );
        }

        // Table names cannot be used with placeholders in $wpdb->prepare().
        // Escape the table name and inject it via sprintf, then prepare the value placeholder.
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $safe_table = esc_sql( $table );
        $sql        = sprintf( 'SELECT status, reserve_expires, nickname FROM `%s` WHERE num = %%s LIMIT 1', $safe_table );
        $row        = $wpdb->get_row( $wpdb->prepare( $sql, $num ), ARRAY_A );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

        if ( ! $row ) {
            return new WP_REST_Response(
                array(
                    'status'  => 'not_found',
                    'message' => 'Number not found in table.',
                ),
                404
            );
        }

        // Debug: Log database status
        error_log( 'VY Numbers API: DB status for ' . $num . ' is ' . $row['status'] );

        // if reserved but expired, treat as available.
        if (
            'reserved' === $row['status']
            && $row['reserve_expires']
            && strtotime( $row['reserve_expires'] ) < time()
        ) {
            $row['status'] = 'available';
            error_log( 'VY Numbers API: Reservation expired, treating as available' );
        }

        // Check if this number is already in the user's cart.
        if ( 'reserved' === $row['status'] || 'available' === $row['status'] ) {
            // Check if cart_numbers parameter was passed from frontend.
            $cart_numbers = $request->get_param( 'cart_numbers' );
            
            if ( ! empty( $cart_numbers ) && is_array( $cart_numbers ) ) {
                error_log( 'VY Numbers API: Checking against cart numbers: ' . implode( ', ', $cart_numbers ) );
                if ( in_array( $num, $cart_numbers, true ) ) {
                    error_log( 'VY Numbers API: Number ' . $num . ' is in cart (frontend)!' );
                    return new WP_REST_Response(
                        array(
                            'status'  => 'in_cart',
                            'message' => 'This number is already in your cart.',
                        ),
                        200
                    );
                }
            } else {
                // Fallback: Try to check the cart if WooCommerce is available.
                if ( function_exists( 'WC' ) && WC()->cart ) {
                    try {
                        $cart_contents = WC()->cart->get_cart();
                        error_log( 'VY Numbers API: Cart has ' . count( $cart_contents ) . ' items' );
                        
                        if ( ! empty( $cart_contents ) ) {
                            foreach ( $cart_contents as $item ) {
                                if ( ! empty( $item['vy_num'] ) ) {
                                    error_log( 'VY Numbers API: Found cart item with number ' . $item['vy_num'] );
                                    if ( $item['vy_num'] === $num ) {
                                        error_log( 'VY Numbers API: Number ' . $num . ' is in cart!' );
                                        return new WP_REST_Response(
                                            array(
                                                'status'  => 'in_cart',
                                                'message' => 'This number is already in your cart.',
                                            ),
                                            200
                                        );
                                    }
                                }
                            }
                        }
                    } catch ( Exception $e ) {
                        // If cart access fails, continue with normal reserved logic.
                        error_log( 'VY Numbers API: Cart access failed: ' . $e->getMessage() );
                        // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                    }
                } else {
                    error_log( 'VY Numbers API: WooCommerce cart not available' );
                }
            }
        }

        // Custom logic: if reserved and nickname exists, return custom message.
        if ( 'reserved' === $row['status'] && ! empty( $row['nickname'] ) ) {
            return new WP_REST_Response(
                array(
                    'status'  => 'reserved',
                    'message' => 'Number ' . $num . ' is ' . $row['nickname'],
                ),
                200
            );
        }

        return new WP_REST_Response(
            array(
                'status'          => $row['status'],
                'reserve_expires' => $row['reserve_expires'],
            ),
            200
        );
    }
}

// boot it.
VY_Numbers_REST::init();
