<?php
/**
 * VY Numbers – Shortcode for number picker
 *
 * @package VY_Numbers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the VY Numbers shortcode for displaying the number picker interface.
 */
class VY_Numbers_Shortcode {

    /**
     * Initializes the shortcode and enqueues necessary assets.
     *
     * @return void
     */
    public static function init() {
        add_shortcode( 'vy_number_picker', array( __CLASS__, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /**
     * Enqueues the necessary CSS and JS assets for the number picker shortcode.
     *
     * @return void
     */
    public static function enqueue_assets() {
        // minimal styles.
        $css = '
.vy-num-picker{display:grid;gap:1rem;justify-items:center}
.vy-num-picker__inputs{display:flex;gap:.5rem}
.vy-num-picker__input{width:3ch;max-width:3ch;text-align:center;font-size:2rem;line-height:1;padding:.4rem .2rem;border:1px solid #ccc;border-radius:.375rem}
.vy-num-picker__input:focus{outline:2px solid #0aaee0;outline-offset:1px}
.vy-num-picker__status{min-height:1.25rem;font-size:1.2rem;opacity:.9;font-weight:bold;}
.vy-num-picker__status--ok{color:#2e7d32}
.vy-num-picker__status--warn{color:#d32f2f}
.vy-num-picker__btn[disabled]{opacity:.5;pointer-events:none}
';
        wp_register_style( 'vy-numbers-shortcode', false );
        wp_add_inline_style( 'vy-numbers-shortcode', $css );
        wp_enqueue_style( 'vy-numbers-shortcode' );

        // data for JS.
        wp_register_script( 'vy-numbers-shortcode', '', array(), null, true );

        // Get cart numbers for frontend availability checking.
        $cart_numbers = array();
        if ( function_exists( 'WC' ) && WC()->cart ) {
            $cart_contents = WC()->cart->get_cart();
            foreach ( $cart_contents as $item ) {
                if ( ! empty( $item['vy_num'] ) ) {
                    $cart_numbers[] = $item['vy_num'];
                }
            }
        }

        $data = array(
            'restBase'    => esc_url_raw( rest_url( 'vy/v1/number/' ) ),
            'cartNumbers' => $cart_numbers,
        );
        wp_add_inline_script( 'vy-numbers-shortcode', 'window.vyNumbersData = ' . wp_json_encode( $data ) . ';' );
        wp_enqueue_script( 'vy-numbers-shortcode' );

        // Add a simple test script to verify JavaScript is loading.
        wp_add_inline_script( 'vy-numbers-shortcode', 'console.log("VY Numbers script enqueued successfully");', 'after' );

        // Inline behaviour script: attach to .vy-num-picker elements after DOM is ready.
        $behavior_js = <<<'JS'
(function(){
    function onlyDigit(ch){ return /[0-9]/.test(ch); }
    function padFour(n){
        n = String(n).replace(/\D/g,'').slice(0,4);
        while(n.length < 4){ n = '0' + n; }
        return n;
    }

    function initPicker(root){
        if(!root) return;
        // avoid double init
        if(root.dataset.vyInit) return;
        var inputs = root.querySelectorAll('.vy-num-picker__input');
        var statusEl = root.querySelector('.vy-num-picker__status');
        var form = root.querySelector('.vy-num-picker__form');
        var hidden = form ? form.querySelector('input[name="vy_num"]') : root.querySelector('input[name="vy_num"]');
        var btn = root.querySelector('.vy-num-picker__btn');

        function getValue(){ var s = ''; inputs.forEach(function(i){ s += (i.value || ''); }); return s; }
        function setStatus(text, ok){ if(!statusEl) return; statusEl.textContent = text || ''; statusEl.classList.remove('vy-num-picker__status--ok', 'vy-num-picker__status--warn'); if(text){ statusEl.classList.add(ok ? 'vy-num-picker__status--ok' : 'vy-num-picker__status--warn'); } }
        function clearInputsAndFocus(){ inputs.forEach(function(i){ i.value = ''; }); if(hidden) hidden.value = ''; if(btn) btn.disabled = true; try{ inputs[0].focus(); }catch(e){} }

        function checkAvailability(num){
            if(num.length !== 4){ return; }
            setStatus('Checking availability…', false);
            if(btn) btn.disabled = true;

            var restBase = ( typeof window.vyNumbersData !== 'undefined' ? window.vyNumbersData.restBase : '' ) || '';
            var url = restBase + encodeURIComponent(num);
            if(!url){ setStatus('Service not available.', false); return; }

            var cartNumbers = [];
            if (typeof window.vyNumbersData !== 'undefined' && window.vyNumbersData.cartNumbers) {
                cartNumbers = window.vyNumbersData.cartNumbers;
            }

            var fetchOptions = { 
                credentials: 'same-origin',
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    cart_numbers: cartNumbers
                })
            };

            fetch(url, fetchOptions)
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if ( data && data.status === 'available' ) {
                        setStatus( 'Number ' + num + ' is available.', true );
                        if(hidden) hidden.value = num;
                        if(btn) btn.disabled = false;
                    } else if (data && data.status === 'in_cart') {
                        clearInputsAndFocus();
                        setStatus( data.message || 'This number is already in your cart.', false );
                        if(hidden) hidden.value = '';
                        if(btn) btn.disabled = true;
                    } else if (data && data.status === 'reserved' && data.message) {
                        clearInputsAndFocus();
                        setStatus( data.message, false );
                        if(hidden) hidden.value = '';
                        if(btn) btn.disabled = true;
                    } else {
                        clearInputsAndFocus();
                        setStatus( 'Number ' + num + ' is taken.', false );
                        if(hidden) hidden.value = '';
                        if(btn) btn.disabled = true;
                    }
                })
                .catch(function(){
                    clearInputsAndFocus();
                    setStatus( 'Could not check availability. Please try again.', false );
                    if(hidden) hidden.value = '';
                    if(btn) btn.disabled = true;
                });
        }

        // attach handlers
        inputs.forEach(function(input, idx){
            input.addEventListener('input', function(e){
                var v = input.value;
                if(v.length > 1){
                    var digits = v.replace(/\D/g,'').slice(0, 4);
                    for(var i=0;i<digits.length && (idx + i) < inputs.length;i++){
                        inputs[idx + i].value = digits[i];
                    }
                }

                if(!onlyDigit(v)){ input.value = ''; }
                if(v.length === 1 && (idx + 1) < inputs.length){ inputs[idx + 1].focus(); }

                var num = getValue();
                if(num.length === 4){
                    var padded = padFour(num);
                    for(var k=0;k<4;k++){ inputs[k].value = padded[k]; }
                    checkAvailability(padded);
                } else {
                    setStatus('', false);
                    if(hidden) hidden.value = '';
                    if(btn) btn.disabled = true;
                }
            });

            input.addEventListener('keydown', function(e){
                if(e.key === 'Backspace' && !input.value && idx > 0){
                    inputs[idx - 1].focus();
                }
                if(e.key && e.key.length === 1 && !/[0-9]/.test(e.key)){ e.preventDefault(); }
            });

            input.addEventListener('paste', function(e){
                var text = (e.clipboardData || window.clipboardData).getData('text'); if(!text){ return; }
                e.preventDefault();
                var digits = text.replace(/\D/g,'').slice(0, 4);
                for(var i=0;i<digits.length && (idx + i) < inputs.length;i++){ inputs[idx + i].value = digits[i]; }
                var num = getValue();
                if(num.length === 4){ var padded = padFour(num); for(var k=0;k<4;k++){ inputs[k].value = padded[k]; } checkAvailability(padded); if(inputs[3]) inputs[3].blur(); }
            });
        });

        // Handle AJAX submission for checkout page
        var checkoutBtn = root.querySelector('.vy-checkout-add');
        if(checkoutBtn) {
            // Enable checkout button when number is complete and valid
            var checkoutInputs = root.querySelectorAll('.vy-num-picker__input');
            checkoutInputs.forEach(function(input) {
                input.addEventListener('input', function() {
                    var num = '';
                    checkoutInputs.forEach(function(inp) { num += (inp.value || ''); });
                    if(num.length === 4) {
                        var padded = padFour(num);
                        for(var k=0;k<4;k++){ checkoutInputs[k].value = padded[k]; }
                        checkAvailability(padded);
                    } else {
                        setStatus('', false);
                        checkoutBtn.disabled = true;
                    }
                });
            });
            
            checkoutBtn.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Get number from individual digit inputs
                var inputs = root.querySelectorAll('.vy-num-picker__input');
                var vyNum = '';
                inputs.forEach(function(input) { vyNum += (input.value || ''); });
                
                // Find nonce field (might be in a hidden div)
                var nonceInput = root.querySelector('input[name="vy_num_nonce"]');
                var nonce = nonceInput ? nonceInput.value : '';
                
                if(!vyNum || vyNum.length !== 4) {
                    alert('Please enter a complete 4-digit number.');
                    return;
                }
                
                // Show loading state
                checkoutBtn.disabled = true;
                checkoutBtn.textContent = 'Adding to Cart...';
                
                // Prepare form data
                var formData = new FormData();
                formData.append('action', 'woocommerce_add_to_cart');
                formData.append('product_id', checkoutBtn.getAttribute('data-product-id') || '134');
                formData.append('quantity', '1');
                formData.append('vy_num', vyNum);
                formData.append('vy_num_nonce', nonce);
                
                // Submit via AJAX
                fetch(window.wc_add_to_cart_params ? window.wc_add_to_cart_params.wc_ajax_url.replace('%%endpoint%%', 'add_to_cart') : '/wp-admin/admin-ajax.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(function(response) {
                    return response.text();
                })
                .then(function(data) {
                    // Clear inputs and show success message
                    clearInputsAndFocus();
                    setStatus('Number added to your order!', true);
                    
                    // Reset button state
                    if(checkoutBtn) {
                        checkoutBtn.disabled = true;
                        checkoutBtn.textContent = checkoutBtn.getAttribute('data-original-text') || 'Add Another Number';
                    }
                    
                    // Trigger checkout update
                    if(typeof jQuery !== 'undefined') {
                        jQuery(document.body).trigger('update_checkout');
                    }
                })
                .catch(function(error) {
                    // Clear inputs and reset button on error
                    clearInputsAndFocus();
                    if(checkoutBtn) {
                        var statusEl = root.querySelector('.vy-num-picker__status');
                        if(statusEl) {
                            statusEl.classList.remove('vy-num-picker__status--ok', 'vy-num-picker__status--warn');
                        }
                        
                        // Reset button and focus first input
                        checkoutBtn.disabled = true;
                        checkoutBtn.textContent = checkoutBtn.getAttribute('data-original-text') || 'Add Another Number';
                        if(inputs[0]) inputs[0].focus();
                        
                        alert('Error: ' + error.message);
                    }
                });
            });
            
            // Store original button text
            checkoutBtn.setAttribute('data-original-text', checkoutBtn.textContent);
        }

        // mark initialised
        root.dataset.vyInit = '1';
    }

    function scanAndInit(){
        var pickers = document.querySelectorAll('.vy-num-picker');
        pickers.forEach(function(root){
            // initialize only if not already
            if(!root.dataset.vyInit){
                initPicker(root);
            }
        });
    }

    // Initialise once DOM is ready
    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', scanAndInit);
    } else {
        scanAndInit();
    }

    // If theme exposes a checkout-ready event (vy:checkout-ready), re-scan when it's fired.
    document.addEventListener('vy:checkout-ready', function(){
        // small delay to allow any theme-side mutations to complete
        setTimeout(scanAndInit, 50);
    });

    // If the theme already exposed the global form, ensure pickers are initialised
    if(window.vy_regular_form){
        setTimeout(scanAndInit, 0);
    }

    // lightweight log for debugging only
    try{ console.log('VY Numbers JavaScript loaded (pickers found):', document.querySelectorAll('.vy-num-picker').length); } catch(e){}
})();
JS;
        wp_add_inline_script( 'vy-numbers-shortcode', $behavior_js );
    }

    /**
     * Shortcode renderer.
     *
     * @param array $atts Array of shortcode attributes.
     * @return string
     */
    public static function render( $atts ) {
        $atts = shortcode_atts(
            array(
                'product_id'  => VY_Numbers_Config::get_product_id(),
                'button_text' => VY_Numbers_Config::get_default_button_text(),
            ),
            $atts,
            'vy_number_picker'
        );

        $product_id = absint( $atts['product_id'] );
        $product    = wc_get_product( $product_id );
        if ( ! $product || ! $product->exists() ) {
            if ( current_user_can( 'manage_options' ) ) {
                return '<p><strong>VY Numbers:</strong> Product not found for <code>product_id=' . esc_html( $product_id ) . '</code>.</p>';
            }
            return '';
        }

        $product_url = get_permalink( $product_id );
        if ( ! $product_url ) {
            if ( current_user_can( 'manage_options' ) ) {
                return '<p><strong>VY Numbers:</strong> Product not found for <code>product_id</code>.</p>';
            }
            return '';
        }
        $add_to_cart_action = add_query_arg( 'add-to-cart', $product_id, $product_url );

        // Check if we're on checkout page to avoid nested forms.
        $is_checkout = function_exists( 'is_checkout' ) && is_checkout();

        // Check if there are VY numbers already in the cart.
        $cart_has_numbers = false;
        $cart_count       = 0;
        if ( function_exists( 'WC' ) && WC()->cart ) {
            $cart_contents = WC()->cart->get_cart();
            foreach ( $cart_contents as $item ) {
                if ( ! empty( $item['vy_num'] ) ) {
                    $cart_has_numbers = true;
                    ++$cart_count;
                }
            }
        }

        ob_start();
        ?>
        <div class="vy-num-picker" data-add-action="<?php echo esc_url( $add_to_cart_action ); ?>">
            <div class="vy-num-picker__inputs" role="group" aria-label="Choose your four-digit number">
                <input class="vy-num-picker__input" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="First digit">
                <input class="vy-num-picker__input" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Second digit">
                <input class="vy-num-picker__input" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Third digit">
                <input class="vy-num-picker__input" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Fourth digit">
            </div>

            <div class="vy-num-picker__status" aria-live="polite"></div>

            <?php if ( $is_checkout ) : ?>
                <!-- On checkout page: use AJAX instead of form submission to avoid nested forms -->
                <div style="display: none;">
                    <?php wp_nonce_field( 'vy_num_action', 'vy_num_nonce' ); ?>
                </div>
                <button type="button" class="vy-num-picker__btn button u-button text-uppercase vy-checkout-add" disabled data-product-id="<?php echo esc_attr( $product_id ); ?>">
                    <?php echo esc_html( $atts['button_text'] ); ?>
                </button>
            <?php else : ?>
                <!-- Regular form submission for non-checkout pages -->
                <form class="vy-num-picker__form" method="post" action="<?php echo esc_url( $add_to_cart_action ); ?>">
                    <?php wp_nonce_field( 'vy_num_action', 'vy_num_nonce' ); ?>
                    <input type="hidden" name="vy_num" value="">
                    <button type="submit" class="vy-num-picker__btn button u-button text-uppercase" disabled>
                        <?php echo esc_html( $atts['button_text'] ); ?>
                    </button>
                </form>
            <?php endif; ?>
            
            
            <?php if ( VY_Numbers_Config::show_cart_link() && is_front_page() && $cart_has_numbers && class_exists( 'WooCommerce' ) ) : ?>
                <?php $cart_url = '/cart/'; // Default cart URL. ?>
                <div class="vy-num-picker__cart-link" style="margin-top: 15px; text-align: center;">
                    <a href="<?php echo esc_url( $cart_url ); ?>" class="vy-view-cart-link" style="color: #fff; text-decoration: underline; font-size: 18px;">
                        View cart
                    </a>
                </div>
            <?php endif; ?>
        </div>

        
        <?php
        return ob_get_clean();
    }

    /**
     * Renders the founder profile page shortcode.
     * [vy_founder_profile]
     *
     * @return string HTML output.
     */
    public static function render_founder_profile() {
        // Enqueue styles and scripts inline (no external files needed initially).
        ob_start();

        if ( ! VY_Numbers_Auth::is_verified() ) {
            // Show authentication form.
            ?>
            <div class="vy-founder-auth" style="max-width:500px;margin:2rem auto;padding:2rem;background:#f5f5f5;border-radius:8px;">
                <h2 style="margin-top:0;">Founder Access</h2>
                <p>Enter your founder number and password to access your profile.</p>
                <form class="vy-founder-auth__form" id="vy-founder-auth-form" style="display:flex;flex-direction:column;gap:1rem;">
                    <div>
                        <label for="vy-founder-number" style="display:block;margin-bottom:0.5rem;font-weight:bold;">Founder Number</label>
                        <input type="text" id="vy-founder-number" name="number" maxlength="4" pattern="\d{4}" placeholder="0001" required style="width:100%;padding:0.5rem;font-size:1rem;" />
                    </div>
                    <div>
                        <label for="vy-founder-password" style="display:block;margin-bottom:0.5rem;font-weight:bold;">Password</label>
                        <input type="password" id="vy-founder-password" name="password" required style="width:100%;padding:0.5rem;font-size:1rem;" />
                        <small style="display:block;margin-top:0.25rem;color:#666;">Default password is your founder number (e.g., 0001)</small>
                    </div>
                    <div class="vy-founder-auth__error" id="vy-auth-error" style="display:none;color:#d32f2f;padding:0.5rem;background:#fee;border-radius:4px;"></div>
                    <button type="submit" class="vy-founder-auth__submit button" style="padding:0.75rem 1.5rem;font-size:1rem;cursor:pointer;">Login</button>
                </form>
            </div>
            <script>
            jQuery(document).ready(function($) {
                $('#vy-founder-auth-form').on('submit', function(e) {
                    e.preventDefault();
                    var number = $('#vy-founder-number').val();
                    var password = $('#vy-founder-password').val();
                    var errorEl = $('#vy-auth-error');

                    $.ajax({
                        url: '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',
                        type: 'POST',
                        data: {
                            action: 'vy_verify_founder',
                            nonce: '<?php echo esc_js( wp_create_nonce( 'vy_founder_auth' ) ); ?>',
                            number: number,
                            password: password
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                errorEl.text(response.data.message).show();
                            }
                        },
                        error: function() {
                            errorEl.text('An error occurred. Please try again.').show();
                        }
                    });
                });
            });
            </script>
            <?php
        } else {
            // User is verified, show profile.
            $founder_number = VY_Numbers_Auth::get_verified_number();
            
            global $wpdb;
            $table = $wpdb->prefix . 'vy_numbers';
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
            $profile = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE num = %s", $founder_number ) );

            if ( $profile ) {
                ?>
                <div class="vy-founder-profile" style="max-width:800px;margin:2rem auto;">
                    <div class="vy-founder-profile__header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;">
                        <h1 style="margin:0;">Founder #<?php echo esc_html( $founder_number ); ?></h1>
                        <button class="vy-founder-logout button" id="vy-founder-logout" style="cursor:pointer;">Logout</button>
                    </div>

                    <div class="vy-founder-profile__details" style="background:#f5f5f5;padding:2rem;border-radius:8px;margin-bottom:2rem;">
                        <h2 style="margin-top:0;">Profile</h2>
                        <div style="display:grid;gap:1rem;">
                            <div class="vy-profile-field">
                                <strong>Name:</strong>
                                <span><?php echo esc_html( trim( ( $profile->first_name ?? '' ) . ' ' . ( $profile->last_name ?? '' ) ) ?: 'Not set' ); ?></span>
                            </div>
                            <div class="vy-profile-field">
                                <strong>Association:</strong>
                                <span><?php echo esc_html( $profile->association ?? 'Not set' ); ?></span>
                            </div>
                            <div class="vy-profile-field">
                                <strong>Nickname:</strong>
                                <span><?php echo esc_html( $profile->nickname ?? 'Not set' ); ?></span>
                            </div>
                            <div class="vy-profile-field">
                                <strong>Category:</strong>
                                <span><?php echo esc_html( $profile->category ?? 'Not set' ); ?></span>
                            </div>
                            <div class="vy-profile-field">
                                <strong>Country:</strong>
                                <span><?php echo esc_html( $profile->country ?? 'Not set' ); ?></span>
                            </div>
                            <div class="vy-profile-field">
                                <strong>Founder Date:</strong>
                                <span><?php echo esc_html( $profile->founder_date ?? 'Not set' ); ?></span>
                            </div>
                            <?php if ( ! empty( $profile->significance ) ) : ?>
                                <div class="vy-profile-field">
                                    <strong>Significance:</strong>
                                    <p style="margin:0.5rem 0 0 0;"><?php echo esc_html( $profile->significance ); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="vy-founder-profile__orders" style="background:#f5f5f5;padding:2rem;border-radius:8px;">
                        <h2 style="margin-top:0;">Purchase History</h2>
                        <?php
                        // Get orders for this founder number.
                        $orders = wc_get_orders( array(
                            'meta_key'   => '_vy_num',
                            'meta_value' => $founder_number,
                            'limit'      => -1,
                        ) );

                        if ( ! empty( $orders ) ) :
                            ?>
                            <table class="vy-orders-table" style="width:100%;border-collapse:collapse;">
                                <thead>
                                    <tr style="border-bottom:2px solid #ddd;">
                                        <th style="text-align:left;padding:0.5rem;">Order</th>
                                        <th style="text-align:left;padding:0.5rem;">Date</th>
                                        <th style="text-align:left;padding:0.5rem;">Status</th>
                                        <th style="text-align:left;padding:0.5rem;">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $orders as $order ) : ?>
                                        <tr style="border-bottom:1px solid #eee;">
                                            <td style="padding:0.5rem;"><a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
                                            <td style="padding:0.5rem;"><?php echo esc_html( $order->get_date_created()->date_i18n( wc_date_format() ) ); ?></td>
                                            <td style="padding:0.5rem;"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td>
                                            <td style="padding:0.5rem;"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else : ?>
                            <p>No orders found.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <script>
                jQuery(document).ready(function($) {
                    $('#vy-founder-logout').on('click', function() {
                        $.ajax({
                            url: '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',
                            type: 'POST',
                            data: {
                                action: 'vy_founder_logout',
                                nonce: '<?php echo esc_js( wp_create_nonce( 'vy_founder_auth' ) ); ?>'
                            },
                            success: function() {
                                location.reload();
                            }
                        });
                    });
                });
                </script>
                <?php
            } else {
                echo '<p>Profile not found.</p>';
            }
        }

        return ob_get_clean();
    }
}

// Register the founder profile shortcode.
add_shortcode( 'vy_founder_profile', array( 'VY_Numbers_Shortcode', 'render_founder_profile' ) );
