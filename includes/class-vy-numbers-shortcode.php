<?php
/**
 * VY Numbers – Shortcode: [vy_number_picker product_id="123" button_text="Secure my number"]
 *
 * Shortcode outputs a small 4-digit number picker (4 single-character inputs) and a
 * button to add the selected number to the specified product's add-to-cart flow.
 *
 * Why `product_id`?
 * - The shortcode needs a WooCommerce product to POST the chosen number to. The
 *   `product_id` attribute is used to build an add-to-cart action URL (so the
 *   chosen number is submitted via POST as `vy_num`). If no product_id is given
 *   the shortcode renders nothing for non-admin users.
 *
 * Shortcode attributes:
 * - product_id (int)  : required for normal usage; ID of the WC product to add to cart.
 * - button_text (str) : optional label for the submit button (default: "Secure my number").
 *
 * Behaviour and expectations:
 * - The shortcode enqueues inline CSS and JS. The JS checks availability by calling
 *   the REST endpoint at `wp-json/vy/v1/number/<NNNN>` and expects JSON with a
 *   top-level `status` string: one of 'available', 'reserved', or 'sold'.
 * - When a 4-digit number is available the hidden `vy_num` input is populated and
 *   the button is enabled. If the number is unavailable the inputs are cleared,
 *   focus is returned to the first digit field and a polite status message is shown.
 * - The JS is enqueued via `wp_add_inline_script` (not printed in the shortcode
 *   HTML) specifically to avoid content filters escaping characters (ampersands)
 *   inside the script.
 *
 * Accessibility:
 * - Status messages are written into an element with `aria-live="polite"` and
 *   inputs are grouped with a sensible `aria-label`.
 *
 * Implementation notes:
 * - Server-side expects `vy_num` as a zero-padded 4-digit string (e.g. "0001").
 * - Rapid typing can spawn overlapping availability requests; consider adding
 *   AbortController or request token logic if you see stale responses overriding
 *   newer ones.
 *
 * @package VY_Numbers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the [vy_number_picker] shortcode functionality for VY Numbers.
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
        $data = array(
            'restBase' => esc_url_raw( rest_url( 'vy/v1/number/' ) ),
        );
        wp_add_inline_script( 'vy-numbers-shortcode', 'window.vyNumbersData = ' . wp_json_encode( $data ) . ';' );
        wp_enqueue_script( 'vy-numbers-shortcode' );

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
        var hidden = form ? form.querySelector('input[name="vy_num"]') : null;
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

            fetch(url, { credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if ( data && data.status === 'available' ) {
                        setStatus( 'Number ' + num + ' is available.', true );
                        if(hidden) hidden.value = num;
                        if(btn) btn.disabled = false;
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
                    for(var i=0;i<digits.length && (idx + i) < inputs.length;i++){ inputs[idx + i].value = digits[i]; }
                } else {
                    if(!onlyDigit(v)){ input.value=''; return; }
                }
                for(var j=idx;j<inputs.length;j++){
                    if(inputs[j].value === ''){ inputs[j].focus(); break; }
                    if(j === inputs.length - 1){ input.blur(); }
                }
                var num = getValue();
                if(num.length === 4){ var padded = padFour(num); for(var k=0;k<4;k++){ inputs[k].value = padded[k]; } checkAvailability(padded); } else { setStatus('', false); if(btn) btn.disabled = true; }
            });

            input.addEventListener('keydown', function(e){
                if(e.key === 'Backspace' && input.value === '' && idx > 0){ inputs[idx-1].focus(); inputs[idx-1].value = ''; e.preventDefault(); setStatus('', false); if(btn) btn.disabled = true; }
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
    }

    document.addEventListener('DOMContentLoaded', function(){
        document.querySelectorAll('.vy-num-picker').forEach(function(root){ 
            initPicker(root); 
            
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
                            checkoutBtn.disabled = false;
                        } else {
                            checkoutBtn.disabled = true;
                        }
                    });
                });
                
                checkoutBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    var form = root.querySelector('form') || root;
                    
                    // Get number from individual digit inputs
                    var inputs = root.querySelectorAll('.vy-num-picker__input');
                    var vyNum = '';
                    inputs.forEach(function(input) { vyNum += (input.value || ''); });
                    
                    // Also update the hidden field
                    var hiddenInput = form.querySelector('input[name="vy_num"]');
                    if (hiddenInput) {
                        hiddenInput.value = vyNum;
                    }
                    
                    var nonce = form.querySelector('input[name="vy_num_nonce"]').value;
                    var productId = checkoutBtn.getAttribute('data-product-id');
                    
                    if(!vyNum || vyNum.length !== 4) {
                        alert('Please enter a valid 4-digit number');
                        return;
                    }
                    
                    // Submit via AJAX
                    var formData = new FormData();
                    formData.append('action', 'add_founder_number_checkout');
                    formData.append('vy_num', vyNum);
                    formData.append('vy_num_nonce', nonce);
                    formData.append('product_id', productId);
                    
                    checkoutBtn.disabled = true;
                    checkoutBtn.textContent = 'Adding...';
                    
                    fetch(window.location.origin + '/wp-admin/admin-ajax.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if(data.success) {
                            window.location.reload(); // Reload to show new cart item
                        } else {
                            alert('Error: ' + (data.data || 'Failed to add number'));
                            checkoutBtn.disabled = false;
                            checkoutBtn.textContent = checkoutBtn.getAttribute('data-original-text') || 'Add Another Number';
                        }
                    })
                    .catch(error => {
                        alert('Error: ' + error.message);
                        checkoutBtn.disabled = false;
                        checkoutBtn.textContent = checkoutBtn.getAttribute('data-original-text') || 'Add Another Number';
                    });
                });
                
                // Store original button text
                checkoutBtn.setAttribute('data-original-text', checkoutBtn.textContent);
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
                'product_id'  => '',
                'button_text' => 'Secure my number',
            ),
            $atts,
            'vy_number_picker'
        );

        $product_id = (int) $atts['product_id'];
        if ( $product_id <= 0 ) {
            if ( current_user_can( 'manage_options' ) ) {
                return '<p><strong>VY Numbers:</strong> Please provide a valid <code>product_id</code> to the shortcode.</p>';
            }
            return '';
        }

        // Build a product add-to-cart URL to POST against (keeps vy_num in POST, not query).
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
                <?php wp_nonce_field( 'vy_num_action', 'vy_num_nonce' ); ?>
                <input type="hidden" name="vy_num" value="">
                <input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $product_id ); ?>">
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
        </div>

        
        <?php
        return ob_get_clean();
    }
}

// boot it.
VY_Numbers_Shortcode::init();
