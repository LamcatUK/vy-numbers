<?php
/**
 * VY Numbers – Installer
 *
 * @package Vy_Numbers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles installation and upgrades for the VY Numbers plugin.
 */
class VY_Numbers_Installer {

    const DB_VERSION = '1.0.6';

    /**
     * Run on plugin activation.
     */
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = $wpdb->prefix . 'vy_numbers';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            num CHAR(4) NOT NULL,
            status ENUM('available','reserved','sold') NOT NULL DEFAULT 'available',
            reserved_by VARCHAR(64) NULL,
            reserve_expires DATETIME NULL,
            order_id BIGINT UNSIGNED NULL,
            user_id BIGINT UNSIGNED NULL,
            txn_ref VARCHAR(128) NULL,
            association VARCHAR(128) NULL,
            nickname VARCHAR(128) NULL,
            category VARCHAR(64) NULL,
            country VARCHAR(64) NULL,
            significance TEXT NULL,
            first_name VARCHAR(128) NULL,
            last_name VARCHAR(128) NULL,
            password_hash VARCHAR(255) NULL,
            founder_date DATE NULL,
            profile_picture_url VARCHAR(512) NULL,
            city VARCHAR(128) NULL,
            state VARCHAR(128) NULL,
            profession VARCHAR(255) NULL,
            bio TEXT NULL,
            social_handles TEXT NULL,
            instagram VARCHAR(255) NULL,
            twitter VARCHAR(255) NULL,
            linkedin VARCHAR(255) NULL,
            website VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY num_unique (num)
        ) {$charset};";

        dbDelta( $sql );

        // Seed only if the table is empty. This is an install-time operation.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $exists = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $table ) . '`' );

        if ( 0 === $exists ) {
            // Use $wpdb->insert for each row so PHPCS and DB escaping are handled correctly.
            // Executed only on install; inserting 9999 rows is acceptable for setup.
            for ( $i = 1; $i <= 9999; $i++ ) {
                $num = sprintf( '%04d', $i );
                $wpdb->insert(
                    $table,
                    array(
                        'num'    => $num,
                        'status' => 'available',
                    ),
                    array( '%s', '%s' )
                );
            }
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

        update_option( 'vy_numbers_db_version', self::DB_VERSION );

        // Attempt to flush rewrite rules and write .htaccess if possible.
        // This helps on dev servers where permalinks haven't been saved yet.
        if ( function_exists( 'flush_rewrite_rules' ) ) {
            // Use hard flush so WordPress will attempt to write .htaccess.
            flush_rewrite_rules( true );
        }
    }

    /**
     * Run on plugin load to check db version and upgrade if needed.
     */
    public static function maybe_upgrade() {
        $current = get_option( 'vy_numbers_db_version' );
        if ( self::DB_VERSION !== $current ) {
            self::install();
        }
    }

    /**
     * Reinitialize the numbers table.
     */
    public static function reinitialize_numbers() {
        global $wpdb;
        $table = $wpdb->prefix . 'vy_numbers';

        // Truncate the table to remove all existing rows.
        $wpdb->query( "TRUNCATE TABLE {$table}" );

        // Reseed the table with numbers from 0001 to 9999.
        for ( $i = 1; $i <= 9999; $i++ ) {
            $num = sprintf( '%04d', $i );
            $wpdb->insert(
                $table,
                array(
                    'num'    => $num,
                    'status' => 'available',
                ),
                array( '%s', '%s' )
            );
        }
    }
}
