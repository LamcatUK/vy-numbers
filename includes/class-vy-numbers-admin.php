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
        add_action( 'admin_post_vy_numbers_reinitialize', array( __CLASS__, 'handle_reinitialize' ) );
    }

    /**
     * Add admin page under WooCommerce.
     */
    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            'Numbers',
            'Numbers',
            'manage_woocommerce',
            'vy-numbers',
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Render the management screen.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'vy-numbers' ) );
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

            <!-- Danger Zone: reinitialize all numbers -->
            <div style="margin-top:18px;padding:12px;border-left:4px solid #b00;background:#fff6f6;">
                <h3 style="color:#b00;margin-top:0;">Danger Zone</h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('WARNING: This will reset ALL numbers to available and clear reservations and related metadata. This action cannot be undone. Proceed?');">
                    <?php wp_nonce_field( 'vy_numbers_reinitialize' ); ?>
                    <input type="hidden" name="action" value="vy_numbers_reinitialize" />
                    <button class="button" style="background:#b00;color:#fff;border-color:#b00;">Reinitialize All Numbers</button>
                    <p class="description" style="color:#b00;margin:6px 0 0;">Sets every number to <code>available</code> and clears reservations, orders, user assignments and metadata. This cannot be undone.</p>
                </form>
            </div>

            <hr />

            <h2>Single actions</h2>
            <div style="display:flex; gap:24px; flex-wrap:wrap;">
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
                </form>
            </div>

            <hr />

            <h2>Bulk actions</h2>
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
                <button class="button button-primary">Run bulk action</button>
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
                                if ( 'available' === $r['status'] ) {
                                    $url = wp_nonce_url( admin_url( 'admin-post.php?action=vy_numbers_reserve&num=' . $n ), 'vy_numbers_reserve' );
                                    echo '<a class="button" href="' . esc_url( $url ) . '">Reserve</a>';
                                } elseif ( 'reserved' === $r['status'] ) {
                                    $url = wp_nonce_url( admin_url( 'admin-post.php?action=vy_numbers_release&num=' . $n ), 'vy_numbers_release' );
                                    echo '<a class="button" href="' . esc_url( $url ) . '">Release</a>';
                                } else {
                                    echo '&mdash;';
                                }
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
            self::redirect_with_msg( 'Invalid number. Use 0001–9999.', 'error' );
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
            self::redirect_with_msg( 'Reserved ' . $num . '.', 'success' );
        }

        self::redirect_with_msg( 'Could not reserve. It may already be reserved or sold.', 'error' );
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
            self::redirect_with_msg( 'Invalid number. Use 0001–9999.', 'error' );
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
            self::redirect_with_msg( 'Released ' . $num . '.', 'success' );
        }

        self::redirect_with_msg( 'Number is already available or does not exist.', 'error' );
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
            self::redirect_with_msg( $msg, 'success' );
            exit;
        }

        $nums = self::parse_numbers( $numbers_raw );
        if ( empty( $nums ) ) {
            self::redirect_with_msg( 'No valid 4-digit numbers found.', 'error' );
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
        self::redirect_with_msg( $msg, 'success' );
    }

    /**
     * Reinitialize all numbers to available and clear related fields.
     */
    public static function handle_reinitialize() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'vy-numbers' ) );
        }
        check_admin_referer( 'vy_numbers_reinitialize' );

        global $wpdb;
        $table = $wpdb->prefix . 'vy_numbers';

        // Truncate the table to remove all existing rows.
        $wpdb->query( "TRUNCATE TABLE {$table}" );

        // Reseed the table with numbers from 0001 to 9999.
        $values = array();
        for ( $i = 1; $i <= 9999; $i++ ) {
            $num      = sprintf( '%04d', $i );
            $values[] = $wpdb->prepare( '(%s, %s)', $num, 'available' );
        }

        // Bulk insert all rows in one query.
        $sql = "INSERT INTO {$table} (num, status) VALUES " . implode( ',', $values );
        $wpdb->query( $sql );

        // Clear cache entries the list uses. A full cache flush is simplest here.
        wp_cache_flush();

        self::redirect_with_msg( 'All numbers have been reinitialized.', 'success' );
    }

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
}

// boot it.
VY_Numbers_Admin::init();
