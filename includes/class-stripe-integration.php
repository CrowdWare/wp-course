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
        
        add_action('wp_ajax_create_payment_intent', array($this, 'create_payment_intent'));
        add_action('wp_ajax_nopriv_create_payment_intent', array($this, 'create_payment_intent'));
        add_action('wp_ajax_confirm_payment', array($this, 'confirm_payment'));
        add_action('wp_ajax_nopriv_confirm_payment', array($this, 'confirm_payment'));
        add_action('init', array($this, 'handle_stripe_webhook'));
    }
    
    /**
     * Create Stripe Payment Intent
     */
    public function create_payment_intent() {
        check_ajax_referer('wp_lms_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error('User must be logged in to purchase courses.');
            return;
        }
        
        $course_id = intval($_POST['course_id']);
        $user_id = get_current_user_id();
        
        // Check if user already purchased this course
        if ($this->database->has_user_purchased_course($user_id, $course_id)) {
            wp_send_json_error('Course already purchased.');
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
        
        // Convert price to cents for Stripe
        $amount = intval($price * 100);
        
        try {
            $this->init_stripe();
            
            $payment_intent = \Stripe\PaymentIntent::create([
                'amount' => $amount,
                'currency' => strtolower($currency),
                'metadata' => [
                    'course_id' => $course_id,
                    'user_id' => $user_id,
                    'course_title' => $course->post_title
                ],
                'description' => 'Course: ' . $course->post_title
            ]);
            
            // Record the purchase attempt
            $this->database->record_course_purchase(
                $user_id,
                $course_id,
                $payment_intent->id,
                $price,
                $currency
            );
            
            wp_send_json_success([
                'client_secret' => $payment_intent->client_secret,
                'payment_intent_id' => $payment_intent->id
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error('Payment initialization failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Confirm payment completion
     */
    public function confirm_payment() {
        check_ajax_referer('wp_lms_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error('User must be logged in.');
            return;
        }
        
        $payment_intent_id = sanitize_text_field($_POST['payment_intent_id']);
        
        try {
            $this->init_stripe();
            
            $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
            
            if ($payment_intent->status === 'succeeded') {
                // Update purchase status
                $this->database->update_purchase_status($payment_intent_id, 'completed');
                
                wp_send_json_success([
                    'status' => 'completed',
                    'message' => 'Payment successful! You now have access to the course.'
                ]);
            } else {
                wp_send_json_error('Payment not completed. Status: ' . $payment_intent->status);
            }
            
        } catch (Exception $e) {
            wp_send_json_error('Payment confirmation failed: ' . $e->getMessage());
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
                <?php _e('Purchase Course', 'wp-lms'); ?>
            </button>
            
            <div id="wp-lms-payment-form" style="display: none;">
                <div id="card-element">
                    <!-- Stripe Elements will create form elements here -->
                </div>
                <div id="card-errors" role="alert"></div>
                <button id="submit-payment" class="wp-lms-btn wp-lms-btn-success">
                    <?php _e('Complete Purchase', 'wp-lms'); ?>
                </button>
                <button id="cancel-payment" class="wp-lms-btn wp-lms-btn-secondary">
                    <?php _e('Cancel', 'wp-lms'); ?>
                </button>
            </div>
            
            <div id="wp-lms-payment-status"></div>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Stripe === 'undefined') {
                console.error('Stripe.js not loaded');
                return;
            }
            
            const stripe = Stripe('<?php echo $this->get_publishable_key(); ?>');
            const elements = stripe.elements();
            const cardElement = elements.create('card');
            
            let paymentIntentId = null;
            
            document.getElementById('wp-lms-purchase-btn').addEventListener('click', function() {
                const courseId = this.getAttribute('data-course-id');
                
                // Create payment intent
                jQuery.ajax({
                    url: wp_lms_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'create_payment_intent',
                        course_id: courseId,
                        nonce: wp_lms_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            paymentIntentId = response.data.payment_intent_id;
                            document.getElementById('wp-lms-purchase-btn').style.display = 'none';
                            document.getElementById('wp-lms-payment-form').style.display = 'block';
                            cardElement.mount('#card-element');
                        } else {
                            document.getElementById('wp-lms-payment-status').innerHTML = 
                                '<div class="error">' + response.data + '</div>';
                        }
                    }
                });
            });
            
            document.getElementById('submit-payment').addEventListener('click', function() {
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
                            url: wp_lms_ajax.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'confirm_payment',
                                payment_intent_id: paymentIntentId,
                                nonce: wp_lms_ajax.nonce
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
                <?php _e('Continue Course', 'wp-lms'); ?>
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
}
