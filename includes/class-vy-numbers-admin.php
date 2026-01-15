<?php
/**
 * VY Numbers – Admin tools
 *
 * @package VY_Numbers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared


/**
 * Class VY_Numbers_Admin
 *
 * Provides admin tools for managing VY Numbers in WooCommerce.
 */
class VY_Numbers_Admin {

    /**
     * Boot hooks.
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );

        // actions.
        add_action( 'admin_post_vy_numbers_reserve', array( __CLASS__, 'handle_reserve' ) );
        add_action( 'admin_post_vy_numbers_release', array( __CLASS__, 'handle_release' ) );
        add_action( 'admin_post_vy_numbers_bulk', array( __CLASS__, 'handle_bulk' ) );
        add_action( 'admin_post_vy_numbers_edit_number', array( __CLASS__, 'handle_edit_number' ) );
        add_action( 'admin_post_vy_numbers_set_password', array( __CLASS__, 'handle_set_password' ) );
        add_action( 'admin_post_vy_numbers_update_profile', array( __CLASS__, 'handle_update_profile' ) );
    }

    /**
     * Add VY Numbers as top-level menu with submenu pages.
     */
    public static function add_menu() {
        // Main menu.
        add_menu_page(
            'VY Numbers',
            'VY Numbers',
            'manage_woocommerce',
            'vy-numbers',
            array( __CLASS__, 'render_page' ),
            'dashicons-tag',
            56
        );

        // Numbers submenu (same slug as parent to replace default).
        add_submenu_page(
            'vy-numbers',
            'Manage Numbers',
            'Numbers',
            'manage_woocommerce',
            'vy-numbers',
            array( __CLASS__, 'render_page' )
        );

        // Profiles submenu.
        add_submenu_page(
            'vy-numbers',
            'Founder Profiles',
            'Profiles',
            'manage_woocommerce',
            'vy-numbers-profiles',
            array( __CLASS__, 'render_profiles_page' )
        );

        // Tools submenu.
        add_submenu_page(
            'vy-numbers',
            'Tools',
            'Tools',
            'manage_woocommerce',
            'vy-numbers-tools',
            array( __CLASS__, 'render_tools_page' )
        );
    }

    /**
     * Render the management screen.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'vy-numbers' ) );
        }

        // Check if we're showing the edit form.
        if ( isset( $_GET['edit'] ) ) {
            self::render_edit_number_form();
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'vy_numbers';

        // filters.
        $status   = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $per_page = 50;
        $offset   = ( $page - 1 ) * $per_page;

        $where = array();
        $args  = array();

        if ( in_array( $status, array( 'available', 'reserved', 'sold' ), true ) ) {
            $where[] = 'status = %s';
            $args[]  = $status;
        }

        if ( '' !== $search ) {
            // allow partial search; but num is fixed 4 chars, so exact helps.
            $where[] = 'num LIKE %s';
            $args[]  = '%' . $wpdb->esc_like( $search ) . '%';
        }

        $where_sql = '';
        if ( ! empty( $where ) ) {
            $where_sql = 'WHERE ' . implode( ' AND ', $where );
        }

        // total count.
        $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        // cache the count to avoid repeated direct DB calls when paginating/filtering.
    	$count_cache_key = 'vy_numbers_count_' . md5( $count_sql . '|' . wp_json_encode( $args ) );
        $total           = wp_cache_get( $count_cache_key, 'vy-numbers' );
        if ( false === $total ) {
            if ( empty( $args ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders
                $total = (int) $wpdb->get_var( $count_sql );
            } else {
                $prepared_count_sql = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $count_sql ), $args ) );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders
                $total = (int) $wpdb->get_var( $prepared_count_sql );
            }
            wp_cache_set( $count_cache_key, $total, 'vy-numbers', HOUR_IN_SECONDS );
        }

        // rows.
        $rows_sql = "SELECT num, status, reserved_by, reserve_expires, order_id, user_id, txn_ref, association, nickname, category, country, significance, updated_at
             FROM {$table} {$where_sql}
             ORDER BY num ASC
             LIMIT %d OFFSET %d";

        $rows_args   = $args;
        $rows_args[] = $per_page;
        $rows_args[] = $offset;

        // cache page rows keyed by filters and page to reduce DB load while navigating pages.
    	$rows_cache_key = 'vy_numbers_rows_' . md5( $rows_sql . '|' . wp_json_encode( $rows_args ) );
        $rows           = wp_cache_get( $rows_cache_key, 'vy-numbers' );
        if ( false === $rows ) {
            $prepared_rows_sql = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $rows_sql ), $rows_args ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders
            $rows = $wpdb->get_results( $prepared_rows_sql, ARRAY_A );
            wp_cache_set( $rows_cache_key, $rows, 'vy-numbers', HOUR_IN_SECONDS );
        }

        $base_url = admin_url( 'admin.php?page=vy-numbers' );
        ?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Numbers</h1>

			<?php
			if ( isset( $_GET['vy_msg'], $_GET['vy_type'] ) ) {
				$msg  = sanitize_text_field( wp_unslash( $_GET['vy_msg'] ) );
                $type = 'success' === $_GET['vy_type'] ? 'updated' : 'error';
				printf(
					'<div id="message" class="%1$s notice is-dismissible"><p>%2$s</p></div>',
					esc_attr( $type ),
					esc_html( $msg )
				);
			}
			?>

            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-top:12px;">
                <input type="hidden" name="page" value="vy-numbers" />
                <select name="status">
                    <option value="">All statuses</option>
                    <option value="available" <?php selected( $status, 'available' ); ?>>Available</option>
                    <option value="reserved"  <?php selected( $status, 'reserved' ); ?>>Reserved</option>
                    <option value="sold"      <?php selected( $status, 'sold' ); ?>>Sold</option>
                </select>
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search number (e.g. 0427)" />
                <button class="button">Filter</button>
            </form>

            <hr />

            <h2>Results</h2>
            <p><?php echo esc_html( number_format_i18n( $total ) ); ?> matching numbers.</p>
            <table class="widefat fixed striped">
                <thead>
                <tr>
                    <th>Number</th>
                    <th>Status</th>
                    <th>Reserved by</th>
                    <th>Reserve expires</th>
                    <th>Order</th>
                    <th>User</th>
                    <th>Txn ref</th>
                    <th>Association</th>
                    <th>Nickname</th>
                    <th>Category</th>
                    <th>Country</th>
                    <th>Significance</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) { ?>
                    <tr><td colspan="14">No numbers found.</td></tr>
                <?php } else { ?>
                    <?php foreach ( $rows as $r ) { ?>
                        <tr>
                            <td><code><?php echo esc_html( $r['num'] ); ?></code></td>
                            <td><?php echo esc_html( ucfirst( $r['status'] ) ); ?></td>
                            <td><?php echo esc_html( $r['reserved_by'] ); ?></td>
                            <td><?php echo esc_html( $r['reserve_expires'] ); ?></td>
                            <td>
                                <?php
                                if ( $r['order_id'] ) {
                                    echo '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $r['order_id'] . '&action=edit' ) ) . '">#' . (int) $r['order_id'] . '</a>';
                                } else {
                                    echo '&mdash;';
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                if ( $r['user_id'] ) {
                                    echo '<a href="' . esc_url( get_edit_user_link( (int) $r['user_id'] ) ) . '">' . (int) $r['user_id'] . '</a>';
                                } else {
                                    echo '&mdash;';
                                }
                                ?>
                            </td>
                            <td><?php echo $r['txn_ref'] ? esc_html( $r['txn_ref'] ) : '&mdash;'; ?></td>
                            <td><?php echo esc_html( $r['association'] ); ?></td>
                            <td><?php echo esc_html( $r['nickname'] ); ?></td>
                            <td><?php echo esc_html( $r['category'] ); ?></td>
                            <td><?php echo esc_html( $r['country'] ); ?></td>
                            <td><?php echo esc_html( $r['significance'] ); ?></td>
                            <td><?php echo esc_html( $r['updated_at'] ); ?></td>
                            <td>
                                <?php
                                $n = rawurlencode( $r['num'] );
                                $edit_url = admin_url( 'admin.php?page=vy-numbers&edit=' . $n );
                                echo '<a class="button" href="' . esc_url( $edit_url ) . '">Edit</a>';
                                ?>
                            </td>
                        </tr>
                    <?php } ?>
                <?php } ?>
                </tbody>
            </table>

            <?php
            // pagination.
            $total_pages = max( 1, (int) ceil( $total / $per_page ) );
            if ( $total_pages > 1 ) {
                // Build pagination links. Use '&paged=%#%' as the format and supply current
                // filter args via 'add_args' so paginate_links builds safe hrefs without
                // producing HTML-encoded ampersands like "#038;paged=...".
                $base = esc_url_raw( add_query_arg( 'paged', '%#%', $base_url ) );

                $add_args = array();
                if ( '' !== $search ) {
                    $add_args['s'] = $search;
                }
                if ( '' !== $status ) {
                    $add_args['status'] = $status;
                }

                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo wp_kses_post(
                    paginate_links(
                        array(
                            'base'      => $base,
                            'format'    => '&paged=%#%',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'current'   => $page,
                            'total'     => $total_pages,
                            'add_args'  => $add_args,
                        )
                    )
                );
                echo '</div></div>';
            }
            ?>
        </div>
        <?php
    }

    /**
     * Reserve a single number (admin hold, no expiry).
     */
    public static function handle_reserve() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'vy-numbers' ) );
        }
        check_admin_referer( 'vy_numbers_reserve' );

        $num = isset( $_REQUEST['num'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['num'] ) ) : '';
        if ( ! self::is_valid_num( $num ) ) {
            self::redirect_to_tools( 'Invalid number. Use 0001–9999.', 'error' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'vy_numbers';

        // only reserve if currently available.
        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $table . '
                 SET status=\'reserved\', reserved_by=NULL, reserve_expires=NULL
                 WHERE num=%s AND status=\'available\'',
                $num
            )
        );

        if ( 1 === $updated ) {
            self::redirect_to_tools( 'Reserved ' . $num . '.', 'success' );
        }

        self::redirect_to_tools( 'Could not reserve. It may already be reserved or sold.', 'error' );
    }

    /**
     * Release a single number back to available.
     */
    public static function handle_release() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'vy-numbers' ) );
        }
        check_admin_referer( 'vy_numbers_release' );

        $num = isset( $_REQUEST['num'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['num'] ) ) : '';
        if ( ! self::is_valid_num( $num ) ) {
            self::redirect_to_tools( 'Invalid number. Use 0001–9999.', 'error' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'vy_numbers';

        $updated = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamically set and safe.
                "UPDATE {$table}
                 SET status='available', reserved_by=NULL, reserve_expires=NULL, order_id=NULL, user_id=NULL, txn_ref=NULL
                 WHERE num=%s AND status IN ('reserved', 'sold')",
                $num
            )
        );

        if ( 1 === $updated ) {
            self::redirect_to_tools( 'Released ' . $num . '.', 'success' );
        }

        self::redirect_to_tools( 'Number is already available or does not exist.', 'error' );
    }

    /**
     * Bulk reserve or release via textarea.
     */
    public static function handle_bulk() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'vy-numbers' ) );
        }
        check_admin_referer( 'vy_numbers_bulk' );

        $numbers_raw = isset( $_POST['numbers'] ) ? sanitize_textarea_field( wp_unslash( $_POST['numbers'] ) ) : '';
        $action      = isset( $_POST['bulk_action'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : 'reserve';

        // CSV upload bulk action.
        if ( 'csv_upload' === $action && ! empty( $_FILES['vy_bulk_csv']['tmp_name'] ) ) {
            global $wpdb;
            $table    = $wpdb->prefix . 'vy_numbers';
            $csv_file = $_FILES['vy_bulk_csv']['tmp_name'];
            if ( ! is_readable( $csv_file ) ) {
                error_log( 'CSV file not readable: ' . $csv_file );
                self::redirect_with_msg( 'CSV file could not be read.', 'error' );
            }
            $handle = fopen( $csv_file, 'r' );
            if ( ! $handle ) {
                error_log( 'Failed to open CSV file: ' . $csv_file );
                self::redirect_with_msg( 'Could not open CSV file.', 'error' );
            }
            $ok         = 0;
            $duplicates = array();
            $seen       = array();
            $row_num    = 0;
            while ( ( $row = fgetcsv( $handle ) ) !== false ) {
                ++$row_num;
                // Skip header row.
                if ( 1 === $row_num ) {
                    continue;
                }
                // Expect: founder number, association, nickname, category, country, significance.
                if ( count( $row ) < 6 ) {
                    error_log( 'CSV row ' . $row_num . ' has insufficient columns: ' . print_r( $row, true ) );
                    continue;
                }
                $num_raw = trim( $row[0] );
                $num     = str_pad( $num_raw, 4, '0', STR_PAD_LEFT );
                if ( ! self::is_valid_num( $num ) ) {
                    error_log( 'CSV row ' . $row_num . ' invalid founder number: ' . $num_raw );
                    continue;
                }
                if ( isset( $seen[ $num ] ) ) {
                    $duplicates[] = array(
                        'row' => $row_num,
                        'num' => $num_raw,
                    );
                    continue;
                }
                $seen[ $num ] = true;
                $association  = sanitize_text_field( $row[1] );
                $nickname     = sanitize_text_field( $row[2] );
                $category     = sanitize_text_field( $row[3] );
                $country      = sanitize_text_field( $row[4] );
                $significance = sanitize_textarea_field( $row[5] );
                $updated      = $wpdb->query(
                    $wpdb->prepare(
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamically set and safe.
                        "UPDATE {$table} SET status='reserved', reserved_by=NULL, reserve_expires=NULL, association=%s, nickname=%s, category=%s, country=%s, significance=%s WHERE num=%s",
                        $association,
                        $nickname,
                        $category,
                        $country,
                        $significance,
                        $num
                    )
                );
                if ( false === $updated ) {
                    error_log( 'CSV row ' . $row_num . ' DB update failed for number: ' . $num );
                }
                if ( 1 === (int) $updated ) {
                    ++$ok;
                }
            }
            fclose( $handle );
            $msg = 'CSV upload: Reserved and updated ' . $ok . ' numbers.';
            if ( ! empty( $duplicates ) ) {
                $msg .= ' Duplicates skipped: ';
                foreach ( $duplicates as $dup ) {
                    $msg .= 'Row ' . $dup['row'] . ' (number ' . esc_html( $dup['num'] ) . '); ';
                }
            }
            self::redirect_to_tools( $msg, 'success' );
            exit;
        }

        $nums = self::parse_numbers( $numbers_raw );
        if ( empty( $nums ) ) {
            self::redirect_to_tools( 'No valid 4-digit numbers found.', 'error' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'vy_numbers';

        $ok = 0;
        foreach ( $nums as $n ) {
            if ( 'release' === $action ) {
                $updated = $wpdb->query(
                    $wpdb->prepare(
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamically set and safe.
                        "UPDATE {$table}
                         SET status='available', reserved_by=NULL, reserve_expires=NULL, order_id=NULL, user_id=NULL, txn_ref=NULL
                         WHERE num=%s AND status IN ('reserved', 'sold')",
                        $n
                    )
                );
            } else {
                $updated = $wpdb->query(
                    $wpdb->prepare(
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamically set and safe.
                        "UPDATE {$table}
                         SET status='reserved', reserved_by=NULL, reserve_expires=NULL
                         WHERE num=%s AND status='available'",
                        $n
                    )
                );
            }
            if ( 1 === (int) $updated ) {
                ++$ok;
            }
        }

        $msg = 'release' === $action
            ? 'Released ' . $ok . ' numbers.'
            : 'Reserved ' . $ok . ' numbers.';
        self::redirect_to_tools( $msg, 'success' );
    }

    /**
     * Reinitialize all numbers to available and clear related fields.
     */
    /**
     * Check if a number is valid (1–9999, zero-padded to 4 digits).
     *
     * @param string $num The number to validate (can be zero-padded or not).
     * @return bool True if valid, false otherwise.
     */
    protected static function is_valid_num( $num ) {
        $i = (int) ltrim( $num, '0' );
        return $i >= 1 && $i <= 9999;
    }

    /**
     * Parse a blob of text and extract valid 4-digit numbers (0001–9999).
     *
     * @param string $blob The. input text containing numbers.
     * @return array Array of valid 4-digit numbers as strings.
     */
    protected static function parse_numbers( $blob ) {
        $out   = array();
        $parts = preg_split( '/[\s,;]+/', (string) $blob );
        foreach ( $parts as $p ) {
            $p = trim( $p );
            if ( '' === $p ) {
                continue;
            }
            // accept 1–4 digits and pad left.
            if ( preg_match( '/^\d{1,4}$/', $p ) ) {
                $n = sprintf( '%04d', (int) $p );
                if ( self::is_valid_num( $n ) ) {
                    $out[ $n ] = true;
                }
            }
        }
        return array_keys( $out );
    }

    /**
     * Render the edit form for a specific number.
     */
    protected static function render_edit_number_form() {
        $num = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
        
        if ( ! preg_match( '/^\d{4}$/', $num ) ) {
            echo '<div class="wrap"><h1>Invalid number format</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=vy-numbers' ) ) . '">Back to Numbers</a></p></div>';
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'vy_numbers';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE num = %s", $num ) );
        
        if ( ! $row ) {
            echo '<div class="wrap"><h1>Number not found</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=vy-numbers' ) ) . '">Back to Numbers</a></p></div>';
            return;
        }
        
        ?>
        <div class="wrap">
            <h1>Edit Number: <?php echo esc_html( $num ); ?></h1>
            
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=vy-numbers' ) ); ?>">&larr; Back to Numbers</a></p>
            
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'vy_numbers_edit_number' ); ?>
                <input type="hidden" name="action" value="vy_numbers_edit_number" />
                <input type="hidden" name="num" value="<?php echo esc_attr( $num ); ?>" />
                
                <h2>Status</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="status">Status</label></th>
                        <td>
                            <select id="status" name="status" required>
                                <option value="available" <?php selected( $row->status, 'available' ); ?>>Available</option>
                                <option value="reserved" <?php selected( $row->status, 'reserved' ); ?>>Reserved</option>
                                <option value="sold" <?php selected( $row->status, 'sold' ); ?>>Sold</option>
                            </select>
                            <p class="description">Change the status of this number.</p>
                        </td>
                    </tr>
                </table>
                
                <div id="profile-fields" style="<?php echo 'sold' === $row->status ? '' : 'display:none;'; ?>">
                    <h2>Profile Information (for Sold Numbers)</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="first_name">First Name</label></th>
                            <td><input type="text" id="first_name" name="first_name" value="<?php echo esc_attr( $row->first_name ?? '' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="last_name">Last Name</label></th>
                            <td><input type="text" id="last_name" name="last_name" value="<?php echo esc_attr( $row->last_name ?? '' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="association">Association</label></th>
                            <td><input type="text" id="association" name="association" value="<?php echo esc_attr( $row->association ?? '' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nickname">Nickname</label></th>
                            <td><input type="text" id="nickname" name="nickname" value="<?php echo esc_attr( $row->nickname ?? '' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="category">Category</label></th>
                            <td><input type="text" id="category" name="category" value="<?php echo esc_attr( $row->category ?? '' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="country">Country</label></th>
                            <td><input type="text" id="country" name="country" value="<?php echo esc_attr( $row->country ?? '' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="city">City</label></th>
                            <td><input type="text" id="city" name="city" value="<?php echo esc_attr( $row->city ?? '' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="state">State / Region</label></th>
                            <td><input type="text" id="state" name="state" value="<?php echo esc_attr( $row->state ?? '' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="profession">Profession</label></th>
                            <td><input type="text" id="profession" name="profession" value="<?php echo esc_attr( $row->profession ?? '' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="founder_date">Founder Date</label></th>
                            <td><input type="date" id="founder_date" name="founder_date" value="<?php echo esc_attr( $row->founder_date ?? '' ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="profile_picture">Profile Picture</label></th>
                            <td>
                                <?php if ( ! empty( $row->profile_picture_url ) ) : ?>
                                    <img src="<?php echo esc_url( $row->profile_picture_url ); ?>" style="max-width: 200px; max-height: 200px; display: block; margin-bottom: 10px;" alt="Current profile picture" />
                                    <p class="description">Current profile picture</p>
                                <?php endif; ?>
                                <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/jpg,image/png" />
                                <p class="description">Upload a JPG or PNG image. Leave empty to keep current picture.</p>
                                <?php if ( ! empty( $row->profile_picture_url ) ) : ?>
                                    <label>
                                        <input type="checkbox" name="remove_profile_picture" value="1" />
                                        Remove current profile picture
                                    </label>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="significance">Significance</label></th>
                            <td><textarea id="significance" name="significance" rows="3" class="large-text"><?php echo esc_textarea( $row->significance ?? '' ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bio">Short Bio</label></th>
                            <td>
                                <textarea id="bio" name="bio" rows="5" class="large-text" placeholder="Brief description about this founder..."><?php echo esc_textarea( $row->bio ?? '' ); ?></textarea>
                                <p class="description">A short biography or description.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <h2>Social Media</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="instagram">Instagram</label></th>
                            <td>
                                <input type="url" id="instagram" name="instagram" value="<?php echo esc_attr( $row->instagram ?? '' ); ?>" class="regular-text" placeholder="https://instagram.com/username" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="twitter">X (Twitter)</label></th>
                            <td>
                                <input type="url" id="twitter" name="twitter" value="<?php echo esc_attr( $row->twitter ?? '' ); ?>" class="regular-text" placeholder="https://x.com/username" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="linkedin">LinkedIn</label></th>
                            <td>
                                <input type="url" id="linkedin" name="linkedin" value="<?php echo esc_attr( $row->linkedin ?? '' ); ?>" class="regular-text" placeholder="https://linkedin.com/in/username" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="website">Website</label></th>
                            <td>
                                <input type="url" id="website" name="website" value="<?php echo esc_attr( $row->website ?? '' ); ?>" class="regular-text" placeholder="https://example.com" />
                            </td>
                        </tr>
                    </table>
                    
                    <h2>Password Management</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Current Password Status</th>
                            <td>
                                <?php if ( ! empty( $row->password_hash ) ) : ?>
                                    <span style="color: green;">✓ Password is set</span>
                                <?php else : ?>
                                    <span style="color: red;">✗ No password set</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="new_password">New Password</label></th>
                            <td>
                                <input type="text" id="new_password" name="new_password" value="<?php echo esc_attr( $num ); ?>" class="regular-text" placeholder="Leave blank to keep current" />
                                <p class="description">Enter a new password or leave blank to keep the existing password. Defaults to the founder number.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Auto-generate Password</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="generate_password" value="1" />
                                    Generate random password (will override manual password)
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes" />
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=vy-numbers' ) ); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>
        
        <script>
        (function($) {
            $('#status').on('change', function() {
                if ($(this).val() === 'sold') {
                    $('#profile-fields').show();
                } else {
                    $('#profile-fields').hide();
                }
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Handle editing a number.
     */
    public static function handle_edit_number() {
        check_admin_referer( 'vy_numbers_edit_number' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'vy-numbers' ) );
        }

        $num = isset( $_POST['num'] ) ? sanitize_text_field( wp_unslash( $_POST['num'] ) ) : '';
        $status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
        
        if ( ! preg_match( '/^\d{4}$/', $num ) ) {
            self::redirect_with_msg( 'Invalid number format.', 'error' );
        }
        
        if ( ! in_array( $status, array( 'available', 'reserved', 'sold' ), true ) ) {
            self::redirect_with_msg( 'Invalid status.', 'error' );
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'vy_numbers';
        
        // Prepare update data.
        $update_data = array( 'status' => $status );
        $format = array( '%s' );
        
        // If status is sold, update profile fields.
        if ( 'sold' === $status ) {
            $update_data['first_name'] = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
            $update_data['last_name'] = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
            $update_data['association'] = isset( $_POST['association'] ) ? sanitize_text_field( wp_unslash( $_POST['association'] ) ) : '';
            $update_data['nickname'] = isset( $_POST['nickname'] ) ? sanitize_text_field( wp_unslash( $_POST['nickname'] ) ) : '';
            $update_data['category'] = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
            $update_data['country'] = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '';
            $update_data['city'] = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';
            $update_data['state'] = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
            $update_data['profession'] = isset( $_POST['profession'] ) ? sanitize_text_field( wp_unslash( $_POST['profession'] ) ) : '';
            $update_data['founder_date'] = isset( $_POST['founder_date'] ) ? sanitize_text_field( wp_unslash( $_POST['founder_date'] ) ) : '';
            $update_data['significance'] = isset( $_POST['significance'] ) ? sanitize_textarea_field( wp_unslash( $_POST['significance'] ) ) : '';
            $update_data['bio'] = isset( $_POST['bio'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bio'] ) ) : '';
            $update_data['instagram'] = isset( $_POST['instagram'] ) ? esc_url_raw( wp_unslash( $_POST['instagram'] ) ) : '';
            $update_data['twitter'] = isset( $_POST['twitter'] ) ? esc_url_raw( wp_unslash( $_POST['twitter'] ) ) : '';
            $update_data['linkedin'] = isset( $_POST['linkedin'] ) ? esc_url_raw( wp_unslash( $_POST['linkedin'] ) ) : '';
            $update_data['website'] = isset( $_POST['website'] ) ? esc_url_raw( wp_unslash( $_POST['website'] ) ) : '';
            
            // Handle profile picture upload.
            if ( isset( $_POST['remove_profile_picture'] ) ) {
                $update_data['profile_picture_url'] = null;
                $format = array_merge( $format, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
            } elseif ( ! empty( $_FILES['profile_picture']['name'] ) ) {
                $upload = self::handle_profile_picture_upload( $_FILES['profile_picture'] );
                if ( ! is_wp_error( $upload ) ) {
                    $update_data['profile_picture_url'] = $upload['url'];
                    $format = array_merge( $format, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
                }
            } else {
                $format = array_merge( $format, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
            }
        } elseif ( 'available' === $status ) {
            // Clear reservation and order data when setting to available.
            $update_data['reserved_by'] = null;
            $update_data['reserve_expires'] = null;
            $update_data['order_id'] = null;
            $update_data['user_id'] = null;
            $update_data['txn_ref'] = null;
            $format = array_merge( $format, array( '%s', '%s', '%d', '%d', '%s' ) );
        }
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $updated = $wpdb->update(
            $table,
            $update_data,
            array( 'num' => $num ),
            $format,
            array( '%s' )
        );
        
        // Handle password update for sold numbers.
        $password_message = '';
        if ( 'sold' === $status ) {
            $new_password = isset( $_POST['new_password'] ) ? wp_unslash( $_POST['new_password'] ) : '';
            $generate_password = isset( $_POST['generate_password'] );
            
            if ( $generate_password ) {
                $password = VY_Numbers_Auth::generate_password();
                if ( VY_Numbers_Auth::set_password( $num, $password ) ) {
                    $password_message = " Password set to: {$password}";
                }
            } elseif ( ! empty( $new_password ) ) {
                if ( VY_Numbers_Auth::set_password( $num, $new_password ) ) {
                    $password_message = ' Password updated successfully.';
                }
            }
        }
        
        if ( false !== $updated || ! empty( $password_message ) ) {
            self::redirect_with_msg( "Number {$num} updated successfully.{$password_message}", 'success' );
        } else {
            self::redirect_with_msg( "No changes made to number {$num}.", 'success' );
        }
    }

    /**
     * Render the Tools page with single and bulk actions.
     */
    public static function render_tools_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'vy-numbers' ) );
        }
        ?>
        <div class="wrap">
            <h1>Tools</h1>

            <?php
            if ( isset( $_GET['vy_msg'], $_GET['vy_type'] ) ) {
                $msg  = sanitize_text_field( wp_unslash( $_GET['vy_msg'] ) );
                $type = 'success' === $_GET['vy_type'] ? 'updated' : 'error';
                printf(
                    '<div id="message" class="%1$s notice is-dismissible"><p>%2$s</p></div>',
                    esc_attr( $type ),
                    esc_html( $msg )
                );
            }
            ?>

            <h2>Single Actions</h2>
            <div style="display:flex; gap:24px; flex-wrap:wrap; margin-bottom: 2rem;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'vy_numbers_reserve' ); ?>
                    <input type="hidden" name="action" value="vy_numbers_reserve" />
                    <label>Reserve number:
                        <input type="text" name="num" maxlength="4" size="6" placeholder="0001" />
                    </label>
                    <button class="button button-primary">Reserve</button>
                    <p class="description">Creates an admin hold (no expiry) until released.</p>
                </form>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'vy_numbers_release' ); ?>
                    <input type="hidden" name="action" value="vy_numbers_release" />
                    <label>Release number:
                        <input type="text" name="num" maxlength="4" size="6" placeholder="0001" />
                    </label>
                    <button class="button">Release</button>
                    <p class="description">Returns a reserved or sold number to available.</p>
                </form>
            </div>

            <hr />

            <h2>Bulk Actions</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'vy_numbers_bulk' ); ?>
                <input type="hidden" name="action" value="vy_numbers_bulk" />
                
                <p><label for="vy_bulk_numbers">Paste numbers (comma/space/newline separated). Non-4-digit tokens will be ignored.</label></p>
                <textarea id="vy_bulk_numbers" name="numbers" rows="5" style="width:100%; max-width:680px;" placeholder="0001, 0002, 0042, 1234"></textarea>
                
                <p><label for="vy_bulk_csv">Or upload CSV (founder number, association, nickname, category, country, significance):</label></p>
                <input type="file" id="vy_bulk_csv" name="vy_bulk_csv" accept=".csv" />
                
                <p>
                    <label>
                        <input type="radio" name="bulk_action" value="reserve" checked />
                        Reserve (admin hold)
                    </label>
                    &nbsp;&nbsp;
                    <label>
                        <input type="radio" name="bulk_action" value="release" />
                        Release to available
                    </label>
                    &nbsp;&nbsp;
                    <label>
                        <input type="radio" name="bulk_action" value="csv_upload" />
                        Upload CSV (set to reserved)
                    </label>
                </p>
                
                <button class="button button-primary">Run Bulk Action</button>
            </form>
        </div>
        <?php
    }

    /**
     * Redirect to the admin tools page with a message.
     *
     * @param string $message The message to display.
     * @param string $type    The type of message ('success' or 'error').
     */
    protected static function redirect_to_tools( $message, $type ) {
        $url = add_query_arg(
            array(
                'page'    => 'vy-numbers-tools',
                'vy_msg'  => rawurlencode( $message ),
                'vy_type' => 'success' === $type ? 'success' : 'error',
            ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Redirect to the admin numbers page with a message.
     *
     * @param string $message The message to display.
     * @param string $type    The type of message ('success' or 'error').
     */
    protected static function redirect_with_msg( $message, $type ) {
        $url = add_query_arg(
            array(
                'page'    => 'vy-numbers',
                'vy_msg'  => rawurlencode( $message ),
                'vy_type' => 'success' === $type ? 'success' : 'error',
            ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Redirect to the profiles page with a message.
     *
     * @param string $message The message to display.
     * @param string $type    The type of message ('success' or 'error').
     */
    protected static function redirect_to_profiles( $message, $type ) {
        $url = add_query_arg(
            array(
                'page'    => 'vy-numbers-profiles',
                'vy_msg'  => rawurlencode( $message ),
                'vy_type' => 'success' === $type ? 'success' : 'error',
            ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Handle password setting for a founder number.
     */
    public static function handle_set_password() {
        check_admin_referer( 'vy_numbers_set_password' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'vy-numbers' ) );
        }

        $num      = isset( $_POST['num'] ) ? sanitize_text_field( wp_unslash( $_POST['num'] ) ) : '';
        $password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';
        $generate = isset( $_POST['generate'] );

        // Normalize the number to 4 digits with leading zeros.
        $num = trim( $num );
        if ( is_numeric( $num ) ) {
            $num = sprintf( '%04d', intval( $num ) );
        }

        if ( ! preg_match( '/^\d{4}$/', $num ) ) {
            self::redirect_with_msg( 'Invalid number format. Must be 4 digits (e.g., 0001).', 'error' );
        }

        if ( $generate ) {
            $password = VY_Numbers_Auth::generate_password();
        }

        if ( empty( $password ) ) {
            self::redirect_with_msg( 'Password cannot be empty.', 'error' );
        }

        if ( VY_Numbers_Auth::set_password( $num, $password ) ) {
            if ( $generate ) {
                self::redirect_with_msg( "Password for {$num} set to: {$password}", 'success' );
            } else {
                self::redirect_with_msg( "Password for {$num} updated successfully.", 'success' );
            }
        } else {
            self::redirect_with_msg( "Failed to set password for {$num}.", 'error' );
        }
    }

    /**
     * Render the founder profiles page.
     */
    public static function render_profiles_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'vy_numbers';
        
        // Get the founder number to edit (if any).
        $edit_number = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
        
        if ( $edit_number ) {
            self::render_profile_edit_form( $edit_number );
            return;
        }
        
        // Get all sold founder numbers with their details.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            "SELECT * FROM {$table} 
             WHERE status = 'sold' 
             ORDER BY CAST(num AS UNSIGNED) ASC"
        );
        
        ?>
        <div class="wrap">
            <h1>Founder Profiles</h1>
            
            <?php if ( isset( $_GET['vy_msg'] ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( isset( $_GET['vy_type'] ) ? sanitize_text_field( wp_unslash( $_GET['vy_type'] ) ) : 'info' ); ?> is-dismissible">
                    <p><?php echo esc_html( isset( $_GET['vy_msg'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['vy_msg'] ) ) ) : '' ); ?></p>
                </div>
            <?php endif; ?>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Number</th>
                        <th>Name</th>
                        <th>Association</th>
                        <th>Nickname</th>
                        <th>Category</th>
                        <th>Country</th>
                        <th>Founder Date</th>
                        <th>Has Password</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $results ) ) : ?>
                        <tr>
                            <td colspan="9">No sold founder numbers found.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $results as $row ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $row->num ); ?></strong></td>
                                <td><?php echo esc_html( trim( ( $row->first_name ?? '' ) . ' ' . ( $row->last_name ?? '' ) ) ?: '-' ); ?></td>
                                <td><?php echo esc_html( $row->association ?? '-' ); ?></td>
                                <td><?php echo esc_html( $row->nickname ?? '-' ); ?></td>
                                <td><?php echo esc_html( $row->category ?? '-' ); ?></td>
                                <td><?php echo esc_html( $row->country ?? '-' ); ?></td>
                                <td><?php echo esc_html( $row->founder_date ?? '-' ); ?></td>
                                <td><?php echo ! empty( $row->password_hash ) ? '✓' : '✗'; ?></td>
                                <td>
                                    <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'vy-numbers-profiles', 'edit' => $row->num ), admin_url( 'admin.php' ) ) ); ?>" class="button button-small">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render the profile edit form for a specific founder number.
     *
     * @param string $num The founder number to edit.
     */
    protected static function render_profile_edit_form( $num ) {
        global $wpdb;
        $table = $wpdb->prefix . 'vy_numbers';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE num = %s", $num ) );
        
        if ( ! $row ) {
            echo '<div class="wrap"><h1>Founder number not found</h1></div>';
            return;
        }
        
        ?>
        <div class="wrap">
            <h1>Edit Founder Profile: <?php echo esc_html( $num ); ?></h1>
            
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'vy_numbers_update_profile' ); ?>
                <input type="hidden" name="action" value="vy_numbers_update_profile" />
                <input type="hidden" name="num" value="<?php echo esc_attr( $num ); ?>" />
                
                <h2>Personal Information</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="first_name">First Name</label></th>
                        <td><input type="text" id="first_name" name="first_name" value="<?php echo esc_attr( $row->first_name ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="last_name">Last Name</label></th>
                        <td><input type="text" id="last_name" name="last_name" value="<?php echo esc_attr( $row->last_name ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                
                <h2>Profile Details</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="association">Association</label></th>
                        <td><input type="text" id="association" name="association" value="<?php echo esc_attr( $row->association ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="nickname">Nickname</label></th>
                        <td><input type="text" id="nickname" name="nickname" value="<?php echo esc_attr( $row->nickname ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="category">Category</label></th>
                        <td><input type="text" id="category" name="category" value="<?php echo esc_attr( $row->category ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="country">Country</label></th>
                        <td><input type="text" id="country" name="country" value="<?php echo esc_attr( $row->country ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="city">City</label></th>
                        <td><input type="text" id="city" name="city" value="<?php echo esc_attr( $row->city ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="state">State / Region</label></th>
                        <td><input type="text" id="state" name="state" value="<?php echo esc_attr( $row->state ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="profession">Profession</label></th>
                        <td><input type="text" id="profession" name="profession" value="<?php echo esc_attr( $row->profession ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="founder_date">Founder Date</label></th>
                        <td><input type="date" id="founder_date" name="founder_date" value="<?php echo esc_attr( $row->founder_date ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="profile_picture">Profile Picture</label></th>
                        <td>
                            <?php if ( ! empty( $row->profile_picture_url ) ) : ?>
                                <img src="<?php echo esc_url( $row->profile_picture_url ); ?>" style="max-width: 200px; max-height: 200px; display: block; margin-bottom: 10px;" alt="Current profile picture" />
                                <p class="description">Current profile picture</p>
                            <?php endif; ?>
                            <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/jpg,image/png" />
                            <p class="description">Upload a JPG or PNG image. Leave empty to keep current picture.</p>
                            <?php if ( ! empty( $row->profile_picture_url ) ) : ?>
                                <label>
                                    <input type="checkbox" name="remove_profile_picture" value="1" />
                                    Remove current profile picture
                                </label>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="significance">Significance</label></th>
                        <td><textarea id="significance" name="significance" rows="3" class="large-text"><?php echo esc_textarea( $row->significance ?? '' ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bio">Short Bio</label></th>
                        <td>
                            <textarea id="bio" name="bio" rows="5" class="large-text" placeholder="Brief description about this founder..."><?php echo esc_textarea( $row->bio ?? '' ); ?></textarea>
                            <p class="description">A short biography or description.</p>
                        </td>
                    </tr>
                </table>
                
                <h2>Social Media</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="instagram">Instagram</label></th>
                        <td>
                            <input type="url" id="instagram" name="instagram" value="<?php echo esc_attr( $row->instagram ?? '' ); ?>" class="regular-text" placeholder="https://instagram.com/username" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="twitter">X (Twitter)</label></th>
                        <td>
                            <input type="url" id="twitter" name="twitter" value="<?php echo esc_attr( $row->twitter ?? '' ); ?>" class="regular-text" placeholder="https://x.com/username" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="linkedin">LinkedIn</label></th>
                        <td>
                            <input type="url" id="linkedin" name="linkedin" value="<?php echo esc_attr( $row->linkedin ?? '' ); ?>" class="regular-text" placeholder="https://linkedin.com/in/username" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="website">Website</label></th>
                        <td>
                            <input type="url" id="website" name="website" value="<?php echo esc_attr( $row->website ?? '' ); ?>" class="regular-text" placeholder="https://example.com" />
                        </td>
                    </tr>
                </table>
                
                <h2>Password Management</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Current Password Status</th>
                        <td>
                            <?php if ( ! empty( $row->password_hash ) ) : ?>
                                <span style="color: green;">✓ Password is set</span>
                            <?php else : ?>
                                <span style="color: red;">✗ No password set (will default to founder number)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="new_password">New Password</label></th>
                        <td>
                            <input type="text" id="new_password" name="new_password" value="" class="regular-text" placeholder="Leave blank to keep current" />
                            <p class="description">Enter a new password or leave blank to keep the existing password. Default is the founder number.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto-generate Password</th>
                        <td>
                            <label>
                                <input type="checkbox" name="generate_password" value="1" />
                                Generate random password (will override manual password)
                            </label>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" class="button button-primary" value="Update Profile" />
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=vy-numbers-profiles' ) ); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Handle profile update form submission.
     */
    public static function handle_update_profile() {
        check_admin_referer( 'vy_numbers_update_profile' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'vy-numbers' ) );
        }

        $num               = isset( $_POST['num'] ) ? sanitize_text_field( wp_unslash( $_POST['num'] ) ) : '';
        $first_name        = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
        $last_name         = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
        $association       = isset( $_POST['association'] ) ? sanitize_text_field( wp_unslash( $_POST['association'] ) ) : '';
        $nickname          = isset( $_POST['nickname'] ) ? sanitize_text_field( wp_unslash( $_POST['nickname'] ) ) : '';
        $category          = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
        $country           = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '';
        $city              = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';
        $state             = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
        $profession        = isset( $_POST['profession'] ) ? sanitize_text_field( wp_unslash( $_POST['profession'] ) ) : '';
        $founder_date      = isset( $_POST['founder_date'] ) ? sanitize_text_field( wp_unslash( $_POST['founder_date'] ) ) : '';
        $significance      = isset( $_POST['significance'] ) ? sanitize_textarea_field( wp_unslash( $_POST['significance'] ) ) : '';
        $bio               = isset( $_POST['bio'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bio'] ) ) : '';
        $instagram         = isset( $_POST['instagram'] ) ? esc_url_raw( wp_unslash( $_POST['instagram'] ) ) : '';
        $twitter           = isset( $_POST['twitter'] ) ? esc_url_raw( wp_unslash( $_POST['twitter'] ) ) : '';
        $linkedin          = isset( $_POST['linkedin'] ) ? esc_url_raw( wp_unslash( $_POST['linkedin'] ) ) : '';
        $website           = isset( $_POST['website'] ) ? esc_url_raw( wp_unslash( $_POST['website'] ) ) : '';
        $new_password      = isset( $_POST['new_password'] ) ? wp_unslash( $_POST['new_password'] ) : '';
        $generate_password = isset( $_POST['generate_password'] );

        if ( ! preg_match( '/^\d{4}$/', $num ) ) {
            self::redirect_to_profiles( 'Invalid number format.', 'error' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'vy_numbers';

        // Prepare update data
        $update_data = array(
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'association'  => $association,
            'nickname'     => $nickname,
            'category'     => $category,
            'country'      => $country,
            'city'         => $city,
            'state'        => $state,
            'profession'   => $profession,
            'founder_date' => $founder_date,
            'significance' => $significance,
            'bio'          => $bio,
            'instagram'    => $instagram,
            'twitter'      => $twitter,
            'linkedin'     => $linkedin,
            'website'      => $website,
        );
        $format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

        // Handle profile picture upload
        if ( isset( $_POST['remove_profile_picture'] ) ) {
            $update_data['profile_picture_url'] = null;
            $format[] = '%s';
        } elseif ( ! empty( $_FILES['profile_picture']['name'] ) ) {
            $upload = self::handle_profile_picture_upload( $_FILES['profile_picture'] );
            if ( ! is_wp_error( $upload ) ) {
                $update_data['profile_picture_url'] = $upload['url'];
                $format[] = '%s';
            }
        }

        // Update profile fields.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
        $updated = $wpdb->update(
            $table,
            $update_data,
            array( 'num' => $num ),
            $format,
            array( '%s' )
        );

        // Handle password update.
        $password_message = '';
        if ( $generate_password ) {
            $password = VY_Numbers_Auth::generate_password();
            if ( VY_Numbers_Auth::set_password( $num, $password ) ) {
                $password_message = " Password set to: {$password}";
            }
        } elseif ( ! empty( $new_password ) ) {
            if ( VY_Numbers_Auth::set_password( $num, $new_password ) ) {
                $password_message = ' Password updated successfully.';
            }
        }

        $message = 'Profile updated successfully.' . $password_message;
        self::redirect_to_profiles( $message, 'success' );
    }

    /**
     * Handle profile picture upload.
     *
     * @param array $file The uploaded file from $_FILES.
     * @return array|WP_Error Upload result with 'url' key or WP_Error on failure.
     */
    private static function handle_profile_picture_upload( $file ) {
        // Check for upload errors.
        if ( ! empty( $file['error'] ) ) {
            return new WP_Error( 'upload_error', 'File upload error: ' . $file['error'] );
        }

        // Check file type.
        $allowed_types = array( 'image/jpeg', 'image/jpg', 'image/png' );
        $file_type = wp_check_filetype( $file['name'] );
        
        if ( ! in_array( $file['type'], $allowed_types, true ) && ! in_array( $file_type['type'], $allowed_types, true ) ) {
            return new WP_Error( 'invalid_type', 'Only JPG and PNG images are allowed.' );
        }

        // Check file size (limit to 5MB).
        if ( $file['size'] > 5 * 1024 * 1024 ) {
            return new WP_Error( 'file_too_large', 'File size must be less than 5MB.' );
        }

        // Set up upload directory.
        $upload_dir = wp_upload_dir();
        $founder_dir = $upload_dir['basedir'] . '/founder-profiles';
        $founder_url = $upload_dir['baseurl'] . '/founder-profiles';

        // Create directory if it doesn't exist.
        if ( ! file_exists( $founder_dir ) ) {
            wp_mkdir_p( $founder_dir );
        }

        // Generate unique filename.
        $filename = wp_unique_filename( $founder_dir, $file['name'] );
        $filepath = $founder_dir . '/' . $filename;

        // Move uploaded file.
        if ( ! move_uploaded_file( $file['tmp_name'], $filepath ) ) {
            return new WP_Error( 'move_failed', 'Failed to move uploaded file.' );
        }

        // Set proper file permissions.
        chmod( $filepath, 0644 );

        return array(
            'file' => $filepath,
            'url' => $founder_url . '/' . $filename,
            'type' => $file_type['type'],
        );
    }
}

// boot it.
VY_Numbers_Admin::init();
