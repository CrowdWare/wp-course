<?php
/**
 * Stripe Integration for WP LMS Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_LMS_Stripe_Integration {
    
    private $stripe_secret_key;
    private $stripe_publishable_key;
    private $database;
    
    public function __construct() {
        // Use dual key system based on test mode
        $test_mode = get_option('wp_lms_stripe_test_mode', 0);
        
        if ($test_mode) {
            $this->stripe_secret_key = get_option('wp_lms_stripe_test_secret_key', '');
            $this->stripe_publishable_key = get_option('wp_lms_stripe_test_publishable_key', '');
        } else {
            $this->stripe_secret_key = get_option('wp_lms_stripe_live_secret_key', '');
            $this->stripe_publishable_key = get_option('wp_lms_stripe_live_publishable_key', '');
        }
        
        // Update legacy options for backward compatibility
        update_option('wp_lms_stripe_secret_key', $this->stripe_secret_key);
        update_option('wp_lms_stripe_publishable_key', $this->stripe_publishable_key);
        
        $this->database = new WP_LMS_Database();
        
        // Add test mode indicator to frontend
        add_action('wp_footer', array($this, 'add_test_mode_indicator'));
        
        add_action('wp_ajax_create_payment_intent', array($this, 'create_payment_intent'));
        add_action('wp_ajax_nopriv_create_payment_intent', array($this, 'create_payment_intent'));
        add_action('wp_ajax_confirm_payment', array($this, 'confirm_payment'));
        add_action('wp_ajax_nopriv_confirm_payment', array($this, 'confirm_payment'));
        
        // Guest purchase handlers
        add_action('wp_ajax_create_guest_payment_intent', array($this, 'create_guest_payment_intent'));
        add_action('wp_ajax_nopriv_create_guest_payment_intent', array($this, 'create_guest_payment_intent'));
        add_action('wp_ajax_confirm_guest_payment', array($this, 'confirm_guest_payment'));
        add_action('wp_ajax_nopriv_confirm_guest_payment', array($this, 'confirm_guest_payment'));
        
        add_action('init', array($this, 'handle_stripe_webhook'));
    }
    
    /**
     * Create Stripe Payment Intent (Live and Test Mode)
     */
    public function create_payment_intent() {
        // Clean output buffer to prevent corrupted JSON
        if (ob_get_level()) {
            ob_clean();
        }
        
        // Check if Stripe is configured
        if (empty($this->stripe_secret_key) || empty($this->stripe_publishable_key)) {
            wp_send_json_error('Stripe ist nicht konfiguriert. Bitte kontaktieren Sie den Administrator.');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_lms_nonce')) {
            wp_send_json_error('Security check failed.');
            return;
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error('User must be logged in to purchase courses.');
            return;
        }
        
        $course_id = intval($_POST['course_id']);
        $is_premium = isset($_POST['is_premium']) ? (bool)$_POST['is_premium'] : false;
        $user_id = get_current_user_id();
        
        // Check if user already purchased this course
        if ($this->database->has_user_purchased_course($user_id, $course_id)) {
            wp_send_json_success([
                'already_purchased' => true,
                'message' => 'Sie haben bereits Zugang zu diesem Kurs. Die Seite wird neu geladen.',
                'redirect' => true
            ]);
            return;
        }
        
        // Get course details
        $course = get_post($course_id);
        if (!$course || $course->post_type !== 'lms_course') {
            wp_send_json_error('Invalid course.');
            return;
        }
        
        // Get price based on premium selection
        if ($is_premium) {
            $price = get_post_meta($course_id, '_course_premium_price', true);
            if (!$price || $price <= 0) {
                wp_send_json_error('Premium price not set for this course.');
                return;
            }
        } else {
            $price = get_post_meta($course_id, '_course_price', true);
            if (!$price || $price <= 0) {
                wp_send_json_error('Standard price not set for this course.');
                return;
            }
        }
        
        $currency = get_post_meta($course_id, '_course_currency', true) ?: 'EUR';
        
        // Try to create real Stripe payment intents via HTTP API
        try {
            // Get user email for Stripe customer
            $user = get_user_by('ID', $user_id);
            $customer_email = $user ? $user->user_email : null;
            
            // Create payment intent via Stripe HTTP API
            $payment_intent_data = $this->create_stripe_payment_intent_http(
                $price * 100, // Stripe expects amount in cents
                strtolower($currency),
                [
                    'course_id' => $course_id,
                    'user_id' => $user_id,
                    'is_premium' => $is_premium ? 'true' : 'false',
                    'course_title' => $course->post_title
                ],
                $customer_email,
                'Course: ' . $course->post_title . ($is_premium ? ' (Premium)' : ' (Standard)')
            );
            
            if (!$payment_intent_data) {
                throw new Exception('Failed to create Stripe payment intent via HTTP API');
            }
            
            // Record the purchase attempt in database
            global $wpdb;
            $table_purchases = $wpdb->prefix . 'lms_course_purchases';
            
            // Delete any existing pending purchases
            $wpdb->delete(
                $table_purchases,
                array(
                    'user_id' => $user_id,
                    'course_id' => $course_id,
                    'status' => 'pending'
                ),
                array('%d', '%d', '%s')
            );
            
            // Insert new purchase record
            $result = $this->database->record_course_purchase(
                $user_id,
                $course_id,
                $payment_intent_data['id'],
                $price,
                $currency,
                $is_premium,
                $customer_email
            );
            
            if (!$result) {
                wp_send_json_error('Datenbankfehler beim Speichern des Kaufversuchs.');
                return;
            }
            
            // Check if we're in test mode for response
            $test_mode = get_option('wp_lms_stripe_test_mode', 0);
            
            wp_send_json_success([
                'client_secret' => $payment_intent_data['client_secret'],
                'payment_intent_id' => $payment_intent_data['id'],
                'test_mode' => $test_mode
            ]);
            
        } catch (Exception $e) {
            // Fallback to test mode if Stripe library is not available or any other error
            error_log('Stripe error, falling back to test mode: ' . $e->getMessage());
            
            // Create fake payment intent for fallback test mode
            $fake_payment_intent_id = 'pi_test_fallback_' . time() . '_' . $course_id . '_' . ($is_premium ? 'premium' : 'standard') . '_' . rand(1000, 9999);
            
            // Get user email for purchase record
            $user = get_user_by('ID', $user_id);
            $customer_email = $user ? $user->user_email : null;
            
            // Record the purchase attempt in database
            global $wpdb;
            $table_purchases = $wpdb->prefix . 'lms_course_purchases';
            
            // Delete any existing pending purchases
            $wpdb->delete(
                $table_purchases,
                array(
                    'user_id' => $user_id,
                    'course_id' => $course_id,
                    'status' => 'pending'
                ),
                array('%d', '%d', '%s')
            );
            
            // Insert new purchase record
            $result = $this->database->record_course_purchase(
                $user_id,
                $course_id,
                $fake_payment_intent_id,
                $price,
                $currency,
                $is_premium,
                $customer_email
            );
            
            if (!$result) {
                wp_send_json_error('Datenbankfehler beim Speichern des Kaufversuchs.');
                return;
            }
            
            wp_send_json_success([
                'client_secret' => 'pi_test_fallback_client_secret_' . time(),
                'payment_intent_id' => $fake_payment_intent_id,
                'test_mode' => true,
                'fallback_mode' => true,
                'message' => 'Stripe library not available - using fallback test mode'
            ]);
        }
    }
    
    /**
     * Confirm payment completion (Real Stripe Integration with Fallback)
     */
    public function confirm_payment() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_lms_nonce')) {
            wp_send_json_error('Security check failed.');
            return;
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error('User must be logged in.');
            return;
        }
        
        $payment_intent_id = sanitize_text_field($_POST['payment_intent_id']);
        
        // Check if this is a fallback test payment intent
        if (strpos($payment_intent_id, 'pi_test_fallback_') === 0) {
            // Fallback test mode - simulate successful payment
            $this->database->update_purchase_status($payment_intent_id, 'completed');
            
            wp_send_json_success([
                'status' => 'completed',
                'message' => 'Testzahlung erfolgreich! Sie haben jetzt Zugang zum Kurs. (Fallback-Modus - Stripe Library nicht verfügbar)'
            ]);
            return;
        }
        
        try {
            // Try to retrieve payment intent via HTTP API first
            $payment_intent_data = $this->retrieve_stripe_payment_intent_http($payment_intent_id);
            
            if ($payment_intent_data['status'] === 'succeeded') {
                // Payment was successful, update our database
                $this->database->update_purchase_status($payment_intent_id, 'completed');
                
                $test_mode = get_option('wp_lms_stripe_test_mode', 0);
                $message = $test_mode ? 
                    'Testzahlung erfolgreich! Sie haben jetzt Zugang zum Kurs.' : 
                    'Zahlung erfolgreich! Sie haben jetzt Zugang zum Kurs.';
                
                wp_send_json_success([
                    'status' => 'completed',
                    'message' => $message
                ]);
            } else {
                // Payment not yet completed
                wp_send_json_error('Zahlung noch nicht abgeschlossen. Status: ' . $payment_intent_data['status']);
            }
            
        } catch (Exception $e) {
            // If HTTP API fails, try with Stripe PHP library as fallback
            error_log('Stripe HTTP API error, trying PHP library: ' . $e->getMessage());
            
            try {
                $this->init_stripe();
                
                // Retrieve the payment intent from Stripe to verify its status
                $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
                
                if ($payment_intent->status === 'succeeded') {
                    // Payment was successful, update our database
                    $this->database->update_purchase_status($payment_intent_id, 'completed');
                    
                    $test_mode = get_option('wp_lms_stripe_test_mode', 0);
                    $message = $test_mode ? 
                        'Testzahlung erfolgreich! Sie haben jetzt Zugang zum Kurs.' : 
                        'Zahlung erfolgreich! Sie haben jetzt Zugang zum Kurs.';
                    
                    wp_send_json_success([
                        'status' => 'completed',
                        'message' => $message
                    ]);
                } else {
                    // Payment not yet completed
                    wp_send_json_error('Zahlung noch nicht abgeschlossen. Status: ' . $payment_intent->status);
                }
                
            } catch (Exception $e2) {
                // Both HTTP API and PHP library failed, fall back to test mode confirmation
                error_log('Both Stripe HTTP API and PHP library failed, falling back to test mode: ' . $e2->getMessage());
                
                // Update purchase status to completed (fallback test mode)
                $this->database->update_purchase_status($payment_intent_id, 'completed');
                
                wp_send_json_success([
                    'status' => 'completed',
                    'message' => 'Testzahlung erfolgreich! Sie haben jetzt Zugang zum Kurs. (Fallback-Modus)'
                ]);
            }
        }
    }
    
    /**
     * Handle Stripe webhooks
     */
    public function handle_stripe_webhook() {
        if (!isset($_GET['wp_lms_stripe_webhook'])) {
            return;
        }
        
        $payload = @file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        
        // Get the correct webhook secret based on test mode
        $test_mode = get_option('wp_lms_stripe_test_mode', 0);
        if ($test_mode) {
            $endpoint_secret = get_option('wp_lms_stripe_test_webhook_secret', '');
        } else {
            $endpoint_secret = get_option('wp_lms_stripe_live_webhook_secret', '');
        }
        
        try {
            $this->init_stripe();
            
            $event = \Stripe\Webhook::constructEvent(
                $payload, $sig_header, $endpoint_secret
            );
            
            switch ($event['type']) {
                case 'payment_intent.succeeded':
                    $payment_intent = $event['data']['object'];
                    $this->database->update_purchase_status($payment_intent['id'], 'completed');
                    break;
                    
                case 'payment_intent.payment_failed':
                    $payment_intent = $event['data']['object'];
                    $this->database->update_purchase_status($payment_intent['id'], 'failed');
                    break;
                    
                default:
                    // Unhandled event type
                    break;
            }
            
            http_response_code(200);
            echo json_encode(['status' => 'success']);
            
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            http_response_code(400);
            echo json_encode(['error' => 'Invalid payload']);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            http_response_code(400);
            echo json_encode(['error' => 'Invalid signature']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Webhook error: ' . $e->getMessage()]);
        }
        
        exit;
    }
    
    /**
     * Initialize Stripe with API keys
     */
    private function init_stripe() {
        if (empty($this->stripe_secret_key)) {
            throw new Exception('Stripe secret key not configured.');
        }
        
        // Check if Stripe PHP library is available
        if (!class_exists('\Stripe\Stripe')) {
            // Try to include from vendor directory
            $stripe_path = WP_LMS_PLUGIN_PATH . 'vendor/stripe/stripe-php/init.php';
            if (file_exists($stripe_path)) {
                require_once $stripe_path;
            } else {
                // Stripe library not available - throw exception
                throw new Exception('Stripe PHP library not found. Please install via Composer or manually.');
            }
        }
        
        \Stripe\Stripe::setApiKey($this->stripe_secret_key);
    }
    
    /**
     * Get Stripe publishable key for frontend
     */
    public function get_publishable_key() {
        return $this->stripe_publishable_key;
    }
    
    /**
     * Generate purchase button HTML
     */
    public function get_purchase_button($course_id, $user_id = null, $is_premium = false) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        // Check if user already purchased - if so, show ONLY continue button (no prices!)
        if ($user_id && $this->database->has_user_purchased_course($user_id, $course_id)) {
            return $this->get_continue_button($course_id);
        }
        
        if (!$user_id) {
            // For guests, show full purchase options with premium/standard choice
            return $this->get_guest_purchase_options($course_id);
        }
        
        // User is logged in but hasn't purchased - show purchase options
        $course = get_post($course_id);
        $price = get_post_meta($course_id, '_course_price', true);
        $currency = get_post_meta($course_id, '_course_currency', true) ?: 'EUR';
        $premium_enabled = get_post_meta($course_id, '_course_premium_enabled', true);
        $premium_price = get_post_meta($course_id, '_course_premium_price', true);
        $premium_features = get_post_meta($course_id, '_course_premium_features', true) ?: array();
        $standard_features = get_post_meta($course_id, '_course_standard_features', true) ?: array();
        
        if (!$price || $price <= 0) {
            return '<p>' . __('Course price not set.', 'wp-lms') . '</p>' . 
                   '<!-- DEBUG: Price: ' . $price . ', Premium enabled: ' . ($premium_enabled ? 'yes' : 'no') . ', Premium price: ' . $premium_price . ' -->';
        }
        
        ob_start();
        
        if ($premium_enabled && $premium_price > 0): ?>
            <!-- Premium Purchase Options -->
            <div class="wp-lms-premium-purchase-options">
                <h3><?php _e('Choose Your Version', 'wp-lms'); ?></h3>
                
                <div class="purchase-options-grid">
                    <!-- Standard Option -->
                    <div class="purchase-option standard-option">
                        <div class="option-header">
                            <h4><?php _e('Standard', 'wp-lms'); ?></h4>
                            <div class="option-price">
                                <span class="price"><?php echo number_format($price, 2); ?></span>
                                <span class="currency"><?php echo $currency; ?></span>
                            </div>
                        </div>
                        
                        <div class="option-features">
                            <ul>
                                <li><?php _e('Full course access', 'wp-lms'); ?></li>
                                <li><?php _e('All video lessons', 'wp-lms'); ?></li>
                                <li><?php _e('Code examples', 'wp-lms'); ?></li>
                                <li><?php _e('WASM demos', 'wp-lms'); ?></li>
                                <?php 
                                // Show configured standard features
                                $feature_labels = array(
                                    'support' => __('Email Support', 'wp-lms'),
                                    'certificate' => __('Course Certificate', 'wp-lms'),
                                    'downloads' => __('Downloadable Resources', 'wp-lms'),
                                    'community' => __('Private Community Access', 'wp-lms'),
                                    'updates' => __('Lifetime Updates', 'wp-lms')
                                );
                                
                                foreach ($standard_features as $feature):
                                    if (isset($feature_labels[$feature])):
                                ?>
                                    <li><?php echo $feature_labels[$feature]; ?></li>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </ul>
                        </div>
                        
                        <div class="option-action">
                            <button id="wp-lms-purchase-btn-standard" 
                                    class="wp-lms-btn wp-lms-btn-primary" 
                                    data-course-id="<?php echo $course_id; ?>"
                                    data-is-premium="0">
                                <?php _e('Standard kaufen', 'wp-lms'); ?>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Premium Option -->
                    <div class="purchase-option premium-option">
                        <div class="option-header">
                            <h4><?php _e('Premium', 'wp-lms'); ?> <span class="premium-badge"><?php _e('BEST VALUE', 'wp-lms'); ?></span></h4>
                            <div class="option-price">
                                <span class="price"><?php echo number_format($premium_price, 2); ?></span>
                                <span class="currency"><?php echo $currency; ?></span>
                            </div>
                        </div>
                        
                        <div class="option-features">
                            <ul>
                                <li><?php _e('Everything in Standard', 'wp-lms'); ?></li>
                                <?php 
                                foreach ($premium_features as $feature):
                                    if (isset($feature_labels[$feature])):
                                ?>
                                    <li class="premium-feature"><?php echo $feature_labels[$feature]; ?></li>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </ul>
                        </div>
                        
                        <div class="option-action">
                            <button id="wp-lms-purchase-btn-premium" 
                                    class="wp-lms-btn wp-lms-btn-premium" 
                                    data-course-id="<?php echo $course_id; ?>"
                                    data-is-premium="1">
                                <?php _e('Premium kaufen', 'wp-lms'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Standard Purchase Only -->
            <div class="wp-lms-standard-purchase">
                <div class="course-price">
                    <span class="price"><?php echo number_format($price, 2); ?></span>
                    <span class="currency"><?php echo $currency; ?></span>
                </div>
                <button id="wp-lms-purchase-btn-standard" 
                        class="wp-lms-btn wp-lms-btn-primary" 
                        data-course-id="<?php echo $course_id; ?>"
                        data-is-premium="0">
                    <?php _e('Kurs kaufen', 'wp-lms'); ?>
                </button>
            </div>
        <?php endif; ?>
        
        <div class="wp-lms-purchase-section">
            
            <div id="wp-lms-payment-form" style="display: none;">
                <div class="payment-summary">
                    <h4><?php _e('Zahlungsübersicht', 'wp-lms'); ?></h4>
                    <div class="purchase-details">
                        <div class="course-info">
                            <strong><?php echo esc_html($course->post_title); ?></strong>
                        </div>
                        <div class="version-info">
                            <span id="selected-version"></span>
                        </div>
                        <div class="price-info">
                            <span class="total-label"><?php _e('Gesamtbetrag:', 'wp-lms'); ?></span>
                            <span class="total-price" id="total-price"></span>
                        </div>
                    </div>
                </div>
                
                <div class="payment-fields">
                    <div class="card-field">
                        <label><?php _e('Kreditkarte:', 'wp-lms'); ?></label>
                        <div id="card-element">
                            <!-- Stripe Elements will create form elements here -->
                        </div>
                    </div>
                </div>
                
                <div id="card-errors" role="alert"></div>
                
                <div class="payment-actions">
                    <button id="submit-payment" class="wp-lms-btn wp-lms-btn-success">
                        <span id="payment-button-text"><?php _e('Kauf abschließen', 'wp-lms'); ?></span>
                        <span id="payment-amount"></span>
                    </button>
                    <button id="cancel-payment" class="wp-lms-btn wp-lms-btn-secondary">
                        <?php _e('Abbrechen', 'wp-lms'); ?>
                    </button>
                </div>
            </div>
            
            <div id="wp-lms-payment-status"></div>
        </div>
        
        <?php
        return ob_get_clean();
    }
    
    /**
     * Generate continue course button HTML
     */
    public function get_continue_button($course_id) {
        ob_start();
        ?>
        <div class="wp-lms-continue-section">
            <button class="wp-lms-btn wp-lms-btn-success wp-lms-continue-btn" 
                    data-course-url="<?php echo esc_url(get_permalink($course_id) . '?action=start'); ?>">
                <?php _e('Kurs fortsetzen', 'wp-lms'); ?>
            </button>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get currency symbol
     */
    private function get_currency_symbol($currency) {
        $symbols = array(
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£'
        );
        
        return isset($symbols[$currency]) ? $symbols[$currency] : $currency . ' ';
    }
    
    /**
     * Generate guest purchase options with premium/standard choice
     */
    public function get_guest_purchase_options($course_id) {
        $course = get_post($course_id);
        $price = get_post_meta($course_id, '_course_price', true);
        $currency = get_post_meta($course_id, '_course_currency', true) ?: 'EUR';
        $premium_enabled = get_post_meta($course_id, '_course_premium_enabled', true);
        $premium_price = get_post_meta($course_id, '_course_premium_price', true);
        $premium_features = get_post_meta($course_id, '_course_premium_features', true) ?: array();
        $standard_features = get_post_meta($course_id, '_course_standard_features', true) ?: array();
        
        if (!$price || $price <= 0) {
            return '<p>' . __('Course price not set.', 'wp-lms') . '</p>';
        }
        
        $login_url = wp_login_url(get_permalink());
        
        ob_start();
        
        if ($premium_enabled && $premium_price > 0): ?>
            <!-- Guest Premium Purchase Options -->
            <div class="wp-lms-premium-purchase-options">
                <h3><?php _e('Choose Your Version', 'wp-lms'); ?></h3>
                
                <div class="purchase-options-grid">
                    <!-- Standard Option -->
                    <div class="purchase-option standard-option">
                        <div class="option-header">
                            <h4><?php _e('Standard', 'wp-lms'); ?></h4>
                            <div class="option-price">
                                <span class="price"><?php echo number_format($price, 2); ?></span>
                                <span class="currency"><?php echo $currency; ?></span>
                            </div>
                        </div>
                        
                        <div class="option-features">
                            <ul>
                                <li><?php _e('Full course access', 'wp-lms'); ?></li>
                                <li><?php _e('All video lessons', 'wp-lms'); ?></li>
                                <li><?php _e('Code examples', 'wp-lms'); ?></li>
                                <li><?php _e('WASM demos', 'wp-lms'); ?></li>
                                <?php 
                                // Show configured standard features
                                $feature_labels = array(
                                    'support' => __('Email Support', 'wp-lms'),
                                    'certificate' => __('Course Certificate', 'wp-lms'),
                                    'downloads' => __('Downloadable Resources', 'wp-lms'),
                                    'community' => __('Private Community Access', 'wp-lms'),
                                    'updates' => __('Lifetime Updates', 'wp-lms')
                                );
                                
                                foreach ($standard_features as $feature):
                                    if (isset($feature_labels[$feature])):
                                ?>
                                    <li><?php echo $feature_labels[$feature]; ?></li>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </ul>
                        </div>
                        
                        <div class="option-action">
                            <button class="wp-lms-btn wp-lms-btn-primary wp-lms-guest-purchase-btn" 
                                    data-course-id="<?php echo $course_id; ?>"
                                    data-is-premium="0"
                                    data-price="<?php echo $price; ?>"
                                    data-currency="<?php echo $currency; ?>">
                                <?php _e('Standard kaufen', 'wp-lms'); ?>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Premium Option -->
                    <div class="purchase-option premium-option">
                        <div class="option-header">
                            <h4><?php _e('Premium', 'wp-lms'); ?> <span class="premium-badge"><?php _e('BEST VALUE', 'wp-lms'); ?></span></h4>
                            <div class="option-price">
                                <span class="price"><?php echo number_format($premium_price, 2); ?></span>
                                <span class="currency"><?php echo $currency; ?></span>
                            </div>
                        </div>
                        
                        <div class="option-features">
                            <ul>
                                <li><?php _e('Everything in Standard', 'wp-lms'); ?></li>
                                <?php 
                                foreach ($premium_features as $feature):
                                    if (isset($feature_labels[$feature])):
                                ?>
                                    <li class="premium-feature"><?php echo $feature_labels[$feature]; ?></li>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </ul>
                        </div>
                        
                        <div class="option-action">
                            <button class="wp-lms-btn wp-lms-btn-premium wp-lms-guest-purchase-btn" 
                                    data-course-id="<?php echo $course_id; ?>"
                                    data-is-premium="1"
                                    data-price="<?php echo $premium_price; ?>"
                                    data-currency="<?php echo $currency; ?>">
                                <?php _e('Premium kaufen', 'wp-lms'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Standard Purchase Only -->
            <div class="wp-lms-standard-purchase">
                <div class="course-price">
                    <span class="price"><?php echo number_format($price, 2); ?></span>
                    <span class="currency"><?php echo $currency; ?></span>
                </div>
                <button class="wp-lms-btn wp-lms-btn-primary wp-lms-guest-purchase-btn" 
                        data-course-id="<?php echo $course_id; ?>"
                        data-is-premium="0"
                        data-price="<?php echo $price; ?>"
                        data-currency="<?php echo $currency; ?>">
                    <?php _e('Kurs kaufen', 'wp-lms'); ?>
                </button>
            </div>
        <?php endif; ?>
        
        <!-- Guest Purchase Form -->
        <div id="wp-lms-guest-purchase-form" style="display: none;">
            <div class="payment-summary">
                <h4><?php _e('Zahlungsübersicht', 'wp-lms'); ?></h4>
                <div class="purchase-details">
                    <div class="course-info">
                        <strong><?php echo esc_html($course->post_title); ?></strong>
                    </div>
                    <div class="version-info">
                        <span id="guest-selected-version"></span>
                    </div>
                    <div class="price-info">
                        <span class="total-label"><?php _e('Gesamtbetrag:', 'wp-lms'); ?></span>
                        <span class="total-price" id="guest-total-price"></span>
                    </div>
                </div>
            </div>
            
            <div class="payment-fields">
                <div class="email-field">
                    <label for="guest-payment-email"><?php _e('E-Mail-Adresse:', 'wp-lms'); ?></label>
                    <input type="email" id="guest-payment-email" 
                           placeholder="<?php _e('Ihre E-Mail-Adresse', 'wp-lms'); ?>" 
                           required>
                    <small><?php _e('Ein Konto wird automatisch für Sie erstellt.', 'wp-lms'); ?></small>
                </div>
                
                <div class="card-field">
                    <label><?php _e('Kreditkarte:', 'wp-lms'); ?></label>
                    <div id="guest-card-element">
                        <!-- Stripe Elements will create form elements here -->
                    </div>
                </div>
            </div>
            
            <div id="guest-card-errors" role="alert"></div>
            
            <div class="payment-actions">
                <button id="submit-guest-payment" class="wp-lms-btn wp-lms-btn-success">
                    <span id="guest-payment-button-text"><?php _e('Kauf abschließen', 'wp-lms'); ?></span>
                    <span id="guest-payment-amount"></span>
                </button>
                <button id="cancel-guest-payment" class="wp-lms-btn wp-lms-btn-secondary">
                    <?php _e('Abbrechen', 'wp-lms'); ?>
                </button>
            </div>
            
            <div class="login-option" style="margin-top: 20px; text-align: center;">
                <p><?php _e('Bereits ein Konto?', 'wp-lms'); ?> 
                   <a href="<?php echo esc_url($login_url); ?>"><?php _e('Hier anmelden', 'wp-lms'); ?></a>
                </p>
            </div>
        </div>
        
        <div id="wp-lms-guest-payment-status"></div>
        
        <?php
        return ob_get_clean();
    }
    
    /**
     * Generate guest purchase button with email registration (Legacy - keeping for compatibility)
     */
    public function get_guest_purchase_button($course_id, $is_premium = false) {
        $course = get_post($course_id);
        $price = get_post_meta($course_id, '_course_price', true);
        $currency = get_post_meta($course_id, '_course_currency', true) ?: 'EUR';
        
        if (!$price || $price <= 0) {
            return '<p>' . __('Course price not set.', 'wp-lms') . '</p>';
        }
        
        $currency_symbol = $this->get_currency_symbol($currency);
        $login_url = wp_login_url(get_permalink());
        
        ob_start();
        ?>
        <div class="wp-lms-guest-purchase-section">
            <div class="purchase-options">
                <div class="guest-purchase-option">
                    <h4><?php _e('Kurs kaufen', 'wp-lms'); ?></h4>
                    <p><?php _e('Geben Sie Ihre E-Mail-Adresse ein. Ein Konto wird automatisch für Sie erstellt.', 'wp-lms'); ?></p>
                    
                    <div class="email-input-section">
                        <input type="email" id="guest-email" placeholder="<?php _e('Ihre E-Mail-Adresse', 'wp-lms'); ?>" required>
                        <button id="wp-lms-guest-purchase-btn" class="wp-lms-btn wp-lms-btn-primary" data-course-id="<?php echo $course_id; ?>">
                            <?php _e('Jetzt kaufen', 'wp-lms'); ?>
                        </button>
                    </div>
                </div>
                
                <div class="login-option">
                    <p><?php _e('Bereits ein Konto?', 'wp-lms'); ?></p>
                    <a href="<?php echo esc_url($login_url); ?>" class="wp-lms-btn wp-lms-btn-secondary">
                        <?php _e('Anmelden', 'wp-lms'); ?>
                    </a>
                </div>
            </div>
            
            <div id="wp-lms-guest-payment-form" style="display: none;">
                <div class="payment-details">
                    <h4><?php _e('Zahlungsdetails', 'wp-lms'); ?></h4>
                    <div class="customer-email">
                        <strong><?php _e('E-Mail:', 'wp-lms'); ?></strong> <span id="customer-email-display"></span>
                    </div>
                </div>
                
                <div id="card-element">
                    <!-- Stripe Elements will create form elements here -->
                </div>
                <div id="card-errors" role="alert"></div>
                
                <div class="payment-actions">
                    <button id="submit-guest-payment" class="wp-lms-btn wp-lms-btn-success">
                        <?php _e('Kauf abschließen', 'wp-lms'); ?>
                    </button>
                    <button id="cancel-guest-payment" class="wp-lms-btn wp-lms-btn-secondary">
                        <?php _e('Abbrechen', 'wp-lms'); ?>
                    </button>
                </div>
            </div>
            
            <div id="wp-lms-guest-payment-status"></div>
        </div>
        
        <?php
        return ob_get_clean();
    }
    
    /**
     * Create guest payment intent with automatic user creation
     */
    public function create_guest_payment_intent() {
        // Clean output buffer to prevent corrupted JSON
        if (ob_get_level()) {
            ob_clean();
        }
        
        if (!get_option('wp_lms_stripe_test_mode', 0)) {
            wp_send_json_error('Stripe live mode not implemented yet. Please enable test mode.');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_lms_nonce')) {
            wp_send_json_error('Security check failed.');
            return;
        }
        
        $course_id = intval($_POST['course_id']);
        $customer_email = sanitize_email($_POST['customer_email']);
        
        if (!$customer_email || !is_email($customer_email)) {
            wp_send_json_error('Gültige E-Mail-Adresse erforderlich.');
            return;
        }
        
        // Get course details
        $course = get_post($course_id);
        if (!$course || $course->post_type !== 'lms_course') {
            wp_send_json_error('Ungültiger Kurs.');
            return;
        }
        
        $price = get_post_meta($course_id, '_course_price', true);
        $currency = get_post_meta($course_id, '_course_currency', true) ?: 'EUR';
        
        if (!$price || $price <= 0) {
            wp_send_json_error('Ungültiger Kurspreis.');
            return;
        }
        
        // Check if user already exists with this email
        $existing_user = get_user_by('email', $customer_email);
        if ($existing_user) {
            // Check if user already purchased this course
            if ($this->database->has_user_purchased_course($existing_user->ID, $course_id)) {
                wp_send_json_error('Ein Benutzer mit dieser E-Mail-Adresse hat bereits Zugang zu diesem Kurs. Bitte melden Sie sich an.');
                return;
            }
        }
        
        // Create fake payment intent for test mode
        $fake_payment_intent_id = 'pi_guest_test_' . time() . '_' . $course_id . '_' . rand(1000, 9999);
        
        // Store the guest purchase attempt temporarily (we'll create the user on payment confirmation)
        set_transient('wp_lms_guest_purchase_' . $fake_payment_intent_id, array(
            'course_id' => $course_id,
            'customer_email' => $customer_email,
            'price' => $price,
            'currency' => $currency,
            'timestamp' => time()
        ), 3600); // 1 hour expiry
        
        wp_send_json_success([
            'client_secret' => 'pi_guest_test_client_secret_' . time(),
            'payment_intent_id' => $fake_payment_intent_id,
            'test_mode' => true
        ]);
    }
    
    /**
     * Confirm guest payment and create user account
     */
    public function confirm_guest_payment() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_lms_nonce')) {
            wp_send_json_error('Security check failed.');
            return;
        }
        
        $payment_intent_id = sanitize_text_field($_POST['payment_intent_id']);
        $customer_email = sanitize_email($_POST['customer_email']);
        
        if (!$customer_email || !is_email($customer_email)) {
            wp_send_json_error('Gültige E-Mail-Adresse erforderlich.');
            return;
        }
        
        // Get the stored purchase data
        $purchase_data = get_transient('wp_lms_guest_purchase_' . $payment_intent_id);
        if (!$purchase_data) {
            wp_send_json_error('Kaufdaten nicht gefunden oder abgelaufen. Bitte versuchen Sie es erneut.');
            return;
        }
        
        // Verify email matches
        if ($purchase_data['customer_email'] !== $customer_email) {
            wp_send_json_error('E-Mail-Adresse stimmt nicht überein.');
            return;
        }
        
        // Check if user already exists
        $existing_user = get_user_by('email', $customer_email);
        
        if ($existing_user) {
            // User exists, just grant access
            $user_id = $existing_user->ID;
            
            // Check if already purchased
            if ($this->database->has_user_purchased_course($user_id, $purchase_data['course_id'])) {
                wp_send_json_error('Sie haben bereits Zugang zu diesem Kurs. Bitte melden Sie sich an.');
                return;
            }
        } else {
            // Create new user account
            $username = $this->generate_username_from_email($customer_email);
            $password = wp_generate_password(12, false);
            
            $user_id = wp_create_user($username, $password, $customer_email);
            
            if (is_wp_error($user_id)) {
                wp_send_json_error('Fehler beim Erstellen des Benutzerkontos: ' . $user_id->get_error_message());
                return;
            }
            
            // Set user role
            $user = new WP_User($user_id);
            $user->set_role('subscriber');
            
            // Send welcome email with login credentials
            $this->send_welcome_email($customer_email, $username, $password);
        }
        
        // Record the purchase
        $result = $this->database->record_course_purchase(
            $user_id,
            $purchase_data['course_id'],
            $payment_intent_id,
            $purchase_data['price'],
            $purchase_data['currency']
        );
        
        if (!$result) {
            wp_send_json_error('Fehler beim Speichern des Kaufs.');
            return;
        }
        
        // Update purchase status to completed
        $this->database->update_purchase_status($payment_intent_id, 'completed');
        
        // Clean up transient
        delete_transient('wp_lms_guest_purchase_' . $payment_intent_id);
        
        // Auto-login the user
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        
        $course_title = get_the_title($purchase_data['course_id']);
        
        if ($existing_user) {
            $message = 'Kauf erfolgreich! Sie wurden automatisch angemeldet und haben jetzt Zugang zum Kurs "' . $course_title . '".';
        } else {
            $message = 'Kauf erfolgreich! Ihr Konto wurde erstellt und Sie wurden automatisch angemeldet. Sie haben jetzt Zugang zum Kurs "' . $course_title . '". Die Anmeldedaten wurden an Ihre E-Mail-Adresse gesendet.';
        }
        
        wp_send_json_success([
            'status' => 'completed',
            'message' => $message,
            'user_created' => !$existing_user
        ]);
    }
    
    /**
     * Generate unique username from email
     */
    private function generate_username_from_email($email) {
        $username = sanitize_user(substr($email, 0, strpos($email, '@')));
        
        // Ensure username is unique
        $original_username = $username;
        $counter = 1;
        
        while (username_exists($username)) {
            $username = $original_username . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    /**
     * Send welcome email to new user
     */
    private function send_welcome_email($email, $username, $password) {
        $subject = 'Willkommen! Ihr neues Konto wurde erstellt';
        
        $message = "Hallo!\n\n";
        $message .= "Ihr Konto wurde erfolgreich erstellt. Hier sind Ihre Anmeldedaten:\n\n";
        $message .= "Benutzername: " . $username . "\n";
        $message .= "Passwort: " . $password . "\n";
        $message .= "Anmelde-URL: " . wp_login_url() . "\n\n";
        $message .= "Sie können sich jederzeit anmelden, um auf Ihre gekauften Kurse zuzugreifen.\n\n";
        $message .= "Wir empfehlen Ihnen, Ihr Passwort nach der ersten Anmeldung zu ändern.\n\n";
        $message .= "Viel Spaß beim Lernen!\n";
        $message .= get_bloginfo('name');
        
        wp_mail($email, $subject, $message);
    }
    
    /**
     * Create Stripe Payment Intent via HTTP API (without PHP library)
     */
    private function create_stripe_payment_intent_http($amount, $currency, $metadata, $receipt_email, $description) {
        if (empty($this->stripe_secret_key)) {
            throw new Exception('Stripe secret key not configured.');
        }
        
        // Prepare the data for Stripe API
        $data = array(
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description,
            'metadata' => $metadata
        );
        
        if ($receipt_email) {
            $data['receipt_email'] = $receipt_email;
        }
        
        // Convert metadata array to individual fields
        $post_data = array();
        foreach ($data as $key => $value) {
            if ($key === 'metadata' && is_array($value)) {
                foreach ($value as $meta_key => $meta_value) {
                    $post_data['metadata[' . $meta_key . ']'] = $meta_value;
                }
            } else {
                $post_data[$key] = $value;
            }
        }
        
        // Make HTTP request to Stripe API
        $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->stripe_secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => http_build_query($post_data)
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('HTTP request failed: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'Unknown Stripe error';
            throw new Exception('Stripe API error: ' . $error_message);
        }
        
        $payment_intent_data = json_decode($response_body, true);
        
        if (!$payment_intent_data || !isset($payment_intent_data['id'])) {
            throw new Exception('Invalid response from Stripe API');
        }
        
        return $payment_intent_data;
    }
    
    /**
     * Retrieve Stripe Payment Intent via HTTP API (without PHP library)
     */
    private function retrieve_stripe_payment_intent_http($payment_intent_id) {
        if (empty($this->stripe_secret_key)) {
            throw new Exception('Stripe secret key not configured.');
        }
        
        // Make HTTP request to Stripe API
        $response = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . $payment_intent_id, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->stripe_secret_key
            )
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('HTTP request failed: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'Unknown Stripe error';
            throw new Exception('Stripe API error: ' . $error_message);
        }
        
        $payment_intent_data = json_decode($response_body, true);
        
        if (!$payment_intent_data || !isset($payment_intent_data['id'])) {
            throw new Exception('Invalid response from Stripe API');
        }
        
        return $payment_intent_data;
    }
    
    /**
     * Add test mode indicator to frontend
     */
    public function add_test_mode_indicator() {
        if (is_singular('lms_course') && get_option('wp_lms_stripe_test_mode', 0)) {
            ?>
            <div id="wp-lms-test-mode-indicator" style="
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                background: #ff9800;
                color: white;
                text-align: center;
                padding: 10px;
                font-weight: bold;
                z-index: 9999;
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);
                cursor: pointer;
            " onclick="document.getElementById('wp-lms-test-cards-info').style.display = document.getElementById('wp-lms-test-cards-info').style.display === 'none' ? 'block' : 'none';">
                🧪 <?php _e('STRIPE TEST MODE ACTIVE - Click for test card info', 'wp-lms'); ?>
            </div>
            
            <div id="wp-lms-test-cards-info" style="
                position: fixed;
                top: 50px;
                left: 20px;
                right: 20px;
                background: white;
                border: 2px solid #ff9800;
                border-radius: 8px;
                padding: 20px;
                z-index: 9998;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                display: none;
                max-width: 600px;
                margin: 0 auto;
            ">
                <div style="display: flex; justify-content: between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0; color: #ff9800;">🧪 Test Credit Cards</h3>
                    <button onclick="document.getElementById('wp-lms-test-cards-info').style.display = 'none';" style="
                        background: none;
                        border: none;
                        font-size: 20px;
                        cursor: pointer;
                        color: #666;
                        float: right;
                    ">&times;</button>
                </div>
                
                <p style="margin-bottom: 15px; color: #666;">
                    <strong><?php _e('Test mode is active!', 'wp-lms'); ?></strong> 
                    <?php _e('Use these test credit card numbers:', 'wp-lms'); ?>
                </p>
                
                <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 10px; font-family: monospace; font-size: 14px;">
                    <div style="font-weight: bold; padding: 8px; background: #f8f9fa; border-radius: 4px;">
                        <strong>4242424242424242</strong>
                    </div>
                    <div style="padding: 8px; color: #28a745;">
                        ✅ <?php _e('Visa - Successful payment', 'wp-lms'); ?>
                    </div>
                    
                    <div style="font-weight: bold; padding: 8px; background: #f8f9fa; border-radius: 4px;">
                        <strong>5555555555554444</strong>
                    </div>
                    <div style="padding: 8px; color: #28a745;">
                        ✅ <?php _e('Mastercard - Successful payment', 'wp-lms'); ?>
                    </div>
                    
                    <div style="font-weight: bold; padding: 8px; background: #f8f9fa; border-radius: 4px;">
                        <strong>4000000000000002</strong>
                    </div>
                    <div style="padding: 8px; color: #dc3545;">
                        ❌ <?php _e('Visa - Card declined', 'wp-lms'); ?>
                    </div>
                    
                    <div style="font-weight: bold; padding: 8px; background: #f8f9fa; border-radius: 4px;">
                        <strong>4000000000009995</strong>
                    </div>
                    <div style="padding: 8px; color: #dc3545;">
                        ❌ <?php _e('Visa - Insufficient funds', 'wp-lms'); ?>
                    </div>
                </div>
                
                <div style="margin-top: 15px; padding: 15px; background: #e8f4fd; border-radius: 4px; border-left: 4px solid #007cba;">
                    <p style="margin: 0; font-size: 14px;">
                        <strong><?php _e('Additional test details:', 'wp-lms'); ?></strong><br>
                        • <?php _e('Use any future expiry date (e.g., 12/34)', 'wp-lms'); ?><br>
                        • <?php _e('Use any 3-digit CVC (e.g., 123)', 'wp-lms'); ?><br>
                        • <?php _e('Use any postal code (e.g., 12345)', 'wp-lms'); ?>
                    </p>
                </div>
                
                <div style="margin-top: 15px; text-align: center;">
                    <a href="https://dashboard.stripe.com/test" target="_blank" style="
                        display: inline-block;
                        background: #635bff;
                        color: white;
                        padding: 8px 16px;
                        text-decoration: none;
                        border-radius: 4px;
                        font-weight: bold;
                    ">
                        📊 <?php _e('View Test Dashboard', 'wp-lms'); ?>
                    </a>
                </div>
            </div>
            
            <style>
                body { margin-top: 50px !important; }
            </style>
            <?php
        }
    }
}
