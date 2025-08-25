<?php
/**
 * VY Numbers – Cron (reservation sweeper)
 *
 * @package VY_Numbers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles WP-Cron scheduling and sweeping of expired number reservations for VY Numbers.
 */
class VY_Numbers_Cron {

    const HOOK_RELEASE_EXPIRED = 'vy_numbers_release_expired';

    /**
     * Boot: register schedule, ensure event is queued, attach callback.
     */
    public static function init() {
        // add a 5-minute schedule.
        add_filter( 'cron_schedules', array( __CLASS__, 'add_five_minute_schedule' ) );

        // ensure our event is scheduled.
        add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );

        // handler.
        add_action( self::HOOK_RELEASE_EXPIRED, array( __CLASS__, 'release_expired' ) );
    }

    /**
     * Add a "five_minutes" interval to WP-Cron.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public static function add_five_minute_schedule( $schedules ) {
        $schedules['five_minutes'] = array(
            'interval' => 300,
            'display'  => __( 'Every 5 minutes', 'vy-numbers' ),
        );
        return $schedules;
    }

    /**
     * Schedule the sweeper if not already present.
     */
    public static function maybe_schedule() {
        if ( ! wp_next_scheduled( self::HOOK_RELEASE_EXPIRED ) ) {
            // start one minute from now to avoid race with plugin load.
            wp_schedule_event( time() + 60, 'five_minutes', self::HOOK_RELEASE_EXPIRED );
        }
    }

    /**
     * Unschedule our event. Call this from a plugin deactivation hook if desired.
     */
    public static function unschedule() {
        $timestamp = wp_next_scheduled( self::HOOK_RELEASE_EXPIRED );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK_RELEASE_EXPIRED );
        }
    }

    /**
     * Release any numbers whose reservation window has expired.
     *
     * Runs via WP-Cron every five minutes.
     */
    public static function release_expired() {
        global $wpdb;

        $table = $wpdb->prefix . 'vy_numbers';

        // Flip any expired reservations back to available.
        // Using UTC on both sides to avoid TZ drift.
        $wpdb->query(
            "UPDATE {$table}
             SET status = 'available',
                 reserved_by = NULL,
                 reserve_expires = NULL
             WHERE status = 'reserved'
               AND reserve_expires IS NOT NULL
               AND reserve_expires < UTC_TIMESTAMP()"
        );
    }
}

// boot it.
VY_Numbers_Cron::init();
