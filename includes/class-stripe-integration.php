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
        $this->stripe_secret_key = get_option('wp_lms_stripe_secret_key', '');
        $this->stripe_publishable_key = get_option('wp_lms_stripe_publishable_key', '');
        $this->database = new WP_LMS_Database();
        
        // Add test mode indicator to frontend
        add_action('wp_footer', array($this, 'add_test_mode_indicator'));
        
        add_action('wp_ajax_create_payment_intent', array($this, 'create_payment_intent'));
        add_action('wp_ajax_nopriv_create_payment_intent', array($this, 'create_payment_intent'));
        add_action('wp_ajax_confirm_payment', array($this, 'confirm_payment'));
        add_action('wp_ajax_nopriv_confirm_payment', array($this, 'confirm_payment'));
        add_action('init', array($this, 'handle_stripe_webhook'));
    }
    
    /**
     * Create Stripe Payment Intent (Simplified for testing)
     */
    public function create_payment_intent() {
        // Clean output buffer to prevent corrupted JSON
        if (ob_get_level()) {
            ob_clean();
        }
        
        // For now, simulate successful payment in test mode
        if (!get_option('wp_lms_stripe_test_mode', 0)) {
            wp_send_json_error('Stripe live mode not implemented yet. Please enable test mode.');
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
        
        $price = get_post_meta($course_id, '_course_price', true);
        $currency = get_post_meta($course_id, '_course_currency', true) ?: 'EUR';
        
        if (!$price || $price <= 0) {
            wp_send_json_error('Invalid course price.');
            return;
        }
        
        // In test mode, simulate successful payment intent creation
        $fake_payment_intent_id = 'pi_test_' . time() . '_' . $course_id . '_' . rand(1000, 9999);
        
        // For test mode, directly grant access without inserting duplicate records
        // First remove any existing pending purchases for this user/course
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
        
        // Now insert the new purchase record
        $result = $this->database->record_course_purchase(
            $user_id,
            $course_id,
            $fake_payment_intent_id,
            $price,
            $currency
        );
        
        if (!$result) {
            wp_send_json_error('Datenbankfehler beim Speichern des Kaufversuchs.');
            return;
        }
        
        wp_send_json_success([
            'client_secret' => 'pi_test_client_secret_' . time(),
            'payment_intent_id' => $fake_payment_intent_id,
            'test_mode' => true
        ]);
    }
    
    /**
     * Confirm payment completion (Simplified for testing)
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
        
        // In test mode, simulate successful payment
        if (get_option('wp_lms_stripe_test_mode', 0)) {
            // Update purchase status to completed
            $this->database->update_purchase_status($payment_intent_id, 'completed');
            
            wp_send_json_success([
                'status' => 'completed',
                'message' => 'Testzahlung erfolgreich! Sie haben jetzt Zugang zum Kurs.'
            ]);
            return;
        }
        
        // For live mode, would need real Stripe integration
        wp_send_json_error('Live mode not implemented yet. Please use test mode.');
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
        $endpoint_secret = get_option('wp_lms_stripe_webhook_secret', '');
        
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
        
        // Include Stripe PHP library (you would need to install this via Composer or include manually)
        if (!class_exists('\Stripe\Stripe')) {
            require_once WP_LMS_PLUGIN_PATH . 'vendor/stripe/stripe-php/init.php';
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
    public function get_purchase_button($course_id, $user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return '<p>' . __('Please log in to purchase this course.', 'wp-lms') . '</p>';
        }
        
        // Check if user already purchased
        if ($this->database->has_user_purchased_course($user_id, $course_id)) {
            return $this->get_continue_button($course_id);
        }
        
        $course = get_post($course_id);
        $price = get_post_meta($course_id, '_course_price', true);
        $currency = get_post_meta($course_id, '_course_currency', true) ?: 'EUR';
        
        if (!$price || $price <= 0) {
            return '<p>' . __('Course price not set.', 'wp-lms') . '</p>';
        }
        
        $currency_symbol = $this->get_currency_symbol($currency);
        
        ob_start();
        ?>
        <div class="wp-lms-purchase-section">
            <div class="course-price">
                <span class="price"><?php echo $currency_symbol . number_format($price, 2); ?></span>
                <span class="currency"><?php echo $currency; ?></span>
            </div>
            
            <button id="wp-lms-purchase-btn" class="wp-lms-btn wp-lms-btn-primary" data-course-id="<?php echo $course_id; ?>">
                <?php _e('Kurs kaufen', 'wp-lms'); ?>
            </button>
            
            <div id="wp-lms-payment-form" style="display: none;">
                <div id="card-element">
                    <!-- Stripe Elements will create form elements here -->
                </div>
                <div id="card-errors" role="alert"></div>
                <button id="submit-payment" class="wp-lms-btn wp-lms-btn-success">
                    <?php _e('Kauf abschließen', 'wp-lms'); ?>
                </button>
                <button id="cancel-payment" class="wp-lms-btn wp-lms-btn-secondary">
                    <?php _e('Abbrechen', 'wp-lms'); ?>
                </button>
            </div>
            
            <div id="wp-lms-payment-status"></div>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Stripe integration loading...');
            
            if (typeof Stripe === 'undefined') {
                console.error('Stripe.js not loaded');
                document.getElementById('wp-lms-payment-status').innerHTML = 
                    '<div class="error">Stripe.js konnte nicht geladen werden. Bitte laden Sie die Seite neu.</div>';
                return;
            }
            
            if (typeof jQuery === 'undefined') {
                console.error('jQuery not loaded');
                document.getElementById('wp-lms-payment-status').innerHTML = 
                    '<div class="error">jQuery konnte nicht geladen werden. Bitte laden Sie die Seite neu.</div>';
                return;
            }
            
            const publishableKey = '<?php echo $this->get_publishable_key(); ?>';
            if (!publishableKey) {
                console.error('Stripe publishable key not configured');
                document.getElementById('wp-lms-payment-status').innerHTML = 
                    '<div class="error">Stripe ist nicht konfiguriert. Bitte kontaktieren Sie den Administrator.</div>';
                return;
            }
            
            const stripe = Stripe(publishableKey);
            const elements = stripe.elements();
            const cardElement = elements.create('card');
            
            let paymentIntentId = null;
            
            const purchaseBtn = document.getElementById('wp-lms-purchase-btn');
            if (!purchaseBtn) {
                console.error('Purchase button not found');
                return;
            }
            
            purchaseBtn.addEventListener('click', function() {
                console.log('Purchase button clicked');
                const courseId = this.getAttribute('data-course-id');
                
                // Create payment intent
                jQuery.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'create_payment_intent',
                        course_id: courseId,
                        nonce: '<?php echo wp_create_nonce('wp_lms_nonce'); ?>'
                    },
                    success: function(response) {
                        console.log('Payment intent response:', response);
                        if (response.success) {
                            if (response.data.already_purchased) {
                                // User already has access, reload page
                                document.getElementById('wp-lms-payment-status').innerHTML = 
                                    '<div class="success">' + response.data.message + '</div>';
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                // Show payment form
                                paymentIntentId = response.data.payment_intent_id;
                                document.getElementById('wp-lms-purchase-btn').style.display = 'none';
                                document.getElementById('wp-lms-payment-form').style.display = 'block';
                                cardElement.mount('#card-element');
                            }
                        } else {
                            var errorMessage = response.data || response.message || 'Unbekannter Fehler aufgetreten.';
                            document.getElementById('wp-lms-payment-status').innerHTML = 
                                '<div class="error">' + errorMessage + '</div>';
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error:', error);
                        document.getElementById('wp-lms-payment-status').innerHTML = 
                            '<div class="error">Netzwerkfehler. Bitte versuchen Sie es erneut.</div>';
                    }
                });
            });
            
            document.getElementById('submit-payment').addEventListener('click', function() {
                // In test mode, skip Stripe validation and directly confirm payment
                if (paymentIntentId && paymentIntentId.startsWith('pi_test_')) {
                    // Simulate successful payment for test mode
                    jQuery.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'confirm_payment',
                            payment_intent_id: paymentIntentId,
                            nonce: '<?php echo wp_create_nonce('wp_lms_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                document.getElementById('wp-lms-payment-status').innerHTML = 
                                    '<div class="success">' + response.data.message + '</div>';
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                document.getElementById('wp-lms-payment-status').innerHTML = 
                                    '<div class="error">' + response.data + '</div>';
                            }
                        }
                    });
                } else {
                    // Real Stripe payment for live mode
                    stripe.confirmCardPayment(paymentIntentId, {
                        payment_method: {
                            card: cardElement
                        }
                    }).then(function(result) {
                        if (result.error) {
                            document.getElementById('card-errors').textContent = result.error.message;
                        } else {
                            // Payment succeeded
                            jQuery.ajax({
                                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                type: 'POST',
                                data: {
                                    action: 'confirm_payment',
                                    payment_intent_id: paymentIntentId,
                                    nonce: '<?php echo wp_create_nonce('wp_lms_nonce'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        document.getElementById('wp-lms-payment-status').innerHTML = 
                                            '<div class="success">' + response.data.message + '</div>';
                                        setTimeout(function() {
                                            location.reload();
                                        }, 2000);
                                    } else {
                                        document.getElementById('wp-lms-payment-status').innerHTML = 
                                            '<div class="error">' + response.data + '</div>';
                                    }
                                }
                            });
                        }
                    });
                }
            });
            
            document.getElementById('cancel-payment').addEventListener('click', function() {
                document.getElementById('wp-lms-purchase-btn').style.display = 'block';
                document.getElementById('wp-lms-payment-form').style.display = 'none';
                cardElement.unmount();
            });
        });
        </script>
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
            <button class="wp-lms-btn wp-lms-btn-success" onclick="window.location.href='<?php echo get_permalink($course_id); ?>?action=start'">
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
            ">
                🧪 <?php _e('STRIPE TEST MODE ACTIVE - No real payments will be processed', 'wp-lms'); ?>
            </div>
            <style>
                body { margin-top: 50px !important; }
            </style>
            <?php
        }
    }
}
