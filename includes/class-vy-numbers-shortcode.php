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

            // Get cart numbers from global if available
            var cartNumbers = [];
            if (typeof window.vyNumbersData !== 'undefined' && window.vyNumbersData.cartNumbers) {
                cartNumbers = window.vyNumbersData.cartNumbers;
            }

            // Prepare fetch body with cart numbers
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
    }

    document.addEventListener('DOMContentLoaded', function(){
        console.log('VY Numbers JavaScript loaded');
        var pickers = document.querySelectorAll('.vy-num-picker');
        console.log('Found', pickers.length, 'number pickers');
        
        pickers.forEach(function(root){ 
            console.log('Initializing picker:', root);
            initPicker(root); 
            
            // Handle regular form submission double-click prevention for non-checkout pages
            var regularForm = root.querySelector('.vy-num-picker__form');
            console.log('Found regular form:', regularForm);
            if(regularForm) {
                var regularBtn = regularForm.querySelector('button[type="submit"]');
                
                if(regularBtn) {
                    // Add submit prevention directly on the button click too
                    regularBtn.addEventListener('click', function(e) {
                        // Check if button is disabled
                        if(regularBtn.disabled) {
                            e.preventDefault();
                            e.stopPropagation();
                            console.log('Button click prevented - button disabled');
                            return false;
                        }
                        
                        // Check status for errors
                        var statusEl = root.querySelector('.vy-num-picker__status');
                        if(statusEl && (statusEl.textContent.toLowerCase().includes('already in your cart') || statusEl.classList.contains('vy-num-picker__status--warn'))) {
                            e.preventDefault();
                            e.stopPropagation();
                            console.log('Button click prevented - error status detected');
                            return false;
                        }
                    });
                    
                    regularForm.addEventListener('submit', function(e) {
                        console.log('Form submit event triggered');
                        
                        // Prevent submission if button is disabled
                        if(regularBtn.disabled) {
                            e.preventDefault();
                            e.stopImmediatePropagation();
                            console.log('Form submit prevented - button disabled');
                            return false;
                        }
                        
                        // Check if status shows "already in your cart"
                        var statusEl = root.querySelector('.vy-num-picker__status');
                        if(statusEl && statusEl.textContent.toLowerCase().includes('already in your cart')) {
                            e.preventDefault();
                            e.stopImmediatePropagation();
                            console.log('Form submit prevented - already in cart status');
                            return false;
                        }
                        
                        // Check if status shows any error (warn class)
                        if(statusEl && statusEl.classList.contains('vy-num-picker__status--warn')) {
                            e.preventDefault();
                            e.stopImmediatePropagation();
                            console.log('Form submit prevented - status shows warning');
                            return false;
                        }
                        
                        // Disable button immediately to prevent double-click
                        regularBtn.disabled = true;
                        regularBtn.textContent = 'Processing...';
                        
                        console.log('Form submit allowed');
                        return true;
                    });
                }
            }
        });
    });
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
}

// boot it.
VY_Numbers_Shortcode::init();