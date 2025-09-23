<?php
/**
 * Admin functionality for WP LMS Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_LMS_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_test_stripe_connection', array($this, 'test_stripe_connection'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('LMS Settings', 'wp-lms'),
            __('LMS Settings', 'wp-lms'),
            'manage_options',
            'wp-lms-settings',
            array($this, 'settings_page'),
            'dashicons-graduation-cap',
            30
        );
        
        add_submenu_page(
            'wp-lms-settings',
            __('Stripe Configuration', 'wp-lms'),
            __('Stripe Config', 'wp-lms'),
            'manage_options',
            'wp-lms-stripe',
            array($this, 'stripe_settings_page')
        );
        
        add_submenu_page(
            'wp-lms-settings',
            __('WASM Configuration', 'wp-lms'),
            __('WASM Config', 'wp-lms'),
            'manage_options',
            'wp-lms-wasm',
            array($this, 'wasm_settings_page')
        );
        
        add_submenu_page(
            'wp-lms-settings',
            __('Course Access', 'wp-lms'),
            __('Course Access', 'wp-lms'),
            'manage_options',
            'wp-lms-access',
            array($this, 'course_access_page')
        );
        
        add_submenu_page(
            'wp-lms-settings',
            __('Sales Management', 'wp-lms'),
            __('Sales', 'wp-lms'),
            'manage_options',
            'wp-lms-sales',
            array($this, 'sales_management_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Stripe settings - Dual Key System
        register_setting('wp_lms_stripe_settings', 'wp_lms_stripe_test_secret_key');
        register_setting('wp_lms_stripe_settings', 'wp_lms_stripe_test_publishable_key');
        register_setting('wp_lms_stripe_settings', 'wp_lms_stripe_live_secret_key');
        register_setting('wp_lms_stripe_settings', 'wp_lms_stripe_live_publishable_key');
        register_setting('wp_lms_stripe_settings', 'wp_lms_stripe_test_webhook_secret');
        register_setting('wp_lms_stripe_settings', 'wp_lms_stripe_live_webhook_secret');
        register_setting('wp_lms_stripe_settings', 'wp_lms_stripe_test_mode');
        
        // Legacy settings for backward compatibility
        register_setting('wp_lms_stripe_settings', 'wp_lms_stripe_secret_key');
        register_setting('wp_lms_stripe_settings', 'wp_lms_stripe_publishable_key');
        
        // SFTP settings
        register_setting('wp_lms_sftp_settings', 'wp_lms_sftp_host');
        register_setting('wp_lms_sftp_settings', 'wp_lms_sftp_port');
        register_setting('wp_lms_sftp_settings', 'wp_lms_sftp_username');
        register_setting('wp_lms_sftp_settings', 'wp_lms_sftp_password');
        register_setting('wp_lms_sftp_settings', 'wp_lms_sftp_path');
        register_setting('wp_lms_sftp_settings', 'wp_lms_sftp_url_base');
    }
    
    /**
     * Main settings page
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('LMS Settings', 'wp-lms'); ?></h1>
            
            <div class="wp-lms-admin-dashboard">
                <div class="dashboard-widgets">
                    <div class="dashboard-widget">
                        <h3><?php _e('Course Statistics', 'wp-lms'); ?></h3>
                        <?php $this->display_course_stats(); ?>
                    </div>
                    
                    <div class="dashboard-widget">
                        <h3><?php _e('Recent Purchases', 'wp-lms'); ?></h3>
                        <?php $this->display_recent_purchases(); ?>
                    </div>
                    
                    <div class="dashboard-widget">
                        <h3><?php _e('User Progress', 'wp-lms'); ?></h3>
                        <?php $this->display_user_progress(); ?>
                    </div>
                </div>
                
                <div class="quick-actions">
                    <h3><?php _e('Quick Actions', 'wp-lms'); ?></h3>
                    <a href="<?php echo admin_url('post-new.php?post_type=lms_course'); ?>" class="button button-primary">
                        <?php _e('Create New Course', 'wp-lms'); ?>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=wp-lms-stripe'); ?>" class="button">
                        <?php _e('Configure Stripe', 'wp-lms'); ?>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=wp-lms-wasm'); ?>" class="button">
                        <?php _e('Configure WASM', 'wp-lms'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Stripe settings page
     */
    public function stripe_settings_page() {
        if (isset($_POST['submit'])) {
            // Save dual key system
            update_option('wp_lms_stripe_test_secret_key', sanitize_text_field($_POST['wp_lms_stripe_test_secret_key']));
            update_option('wp_lms_stripe_test_publishable_key', sanitize_text_field($_POST['wp_lms_stripe_test_publishable_key']));
            update_option('wp_lms_stripe_live_secret_key', sanitize_text_field($_POST['wp_lms_stripe_live_secret_key']));
            update_option('wp_lms_stripe_live_publishable_key', sanitize_text_field($_POST['wp_lms_stripe_live_publishable_key']));
            update_option('wp_lms_stripe_test_webhook_secret', sanitize_text_field($_POST['wp_lms_stripe_test_webhook_secret']));
            update_option('wp_lms_stripe_live_webhook_secret', sanitize_text_field($_POST['wp_lms_stripe_live_webhook_secret']));
            update_option('wp_lms_stripe_test_mode', isset($_POST['wp_lms_stripe_test_mode']) ? 1 : 0);
            
            // Update legacy keys for backward compatibility
            $test_mode = isset($_POST['wp_lms_stripe_test_mode']) ? 1 : 0;
            if ($test_mode) {
                update_option('wp_lms_stripe_secret_key', sanitize_text_field($_POST['wp_lms_stripe_test_secret_key']));
                update_option('wp_lms_stripe_publishable_key', sanitize_text_field($_POST['wp_lms_stripe_test_publishable_key']));
            } else {
                update_option('wp_lms_stripe_secret_key', sanitize_text_field($_POST['wp_lms_stripe_live_secret_key']));
                update_option('wp_lms_stripe_publishable_key', sanitize_text_field($_POST['wp_lms_stripe_live_publishable_key']));
            }
            
            echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'wp-lms') . '</p></div>';
        }
        
        // Get dual key system values
        $test_secret_key = get_option('wp_lms_stripe_test_secret_key', '');
        $test_publishable_key = get_option('wp_lms_stripe_test_publishable_key', '');
        $live_secret_key = get_option('wp_lms_stripe_live_secret_key', '');
        $live_publishable_key = get_option('wp_lms_stripe_live_publishable_key', '');
        $test_webhook_secret = get_option('wp_lms_stripe_test_webhook_secret', '');
        $live_webhook_secret = get_option('wp_lms_stripe_live_webhook_secret', '');
        $test_mode = get_option('wp_lms_stripe_test_mode', 0);
        
        // Migration from legacy single key system
        if (empty($test_secret_key) && empty($live_secret_key)) {
            $legacy_secret = get_option('wp_lms_stripe_secret_key', '');
            $legacy_publishable = get_option('wp_lms_stripe_publishable_key', '');
            
            if (!empty($legacy_secret)) {
                if (strpos($legacy_secret, 'sk_test_') === 0) {
                    $test_secret_key = $legacy_secret;
                    $test_publishable_key = $legacy_publishable;
                } else {
                    $live_secret_key = $legacy_secret;
                    $live_publishable_key = $legacy_publishable;
                }
            }
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Stripe Configuration', 'wp-lms'); ?></h1>
            
            <div class="notice notice-info">
                <p><strong><?php _e('Dual Key System:', 'wp-lms'); ?></strong> <?php _e('Configure both test and live keys, then simply toggle between test and live mode.', 'wp-lms'); ?></p>
            </div>
            
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row" style="background: #f0f8ff; padding: 10px; border-left: 4px solid #007cba;">
                            <strong><?php _e('Mode Selection', 'wp-lms'); ?></strong>
                        </th>
                        <td style="background: #f0f8ff; padding: 10px;">
                            <label style="font-size: 16px; font-weight: bold;">
                                <input type="checkbox" name="wp_lms_stripe_test_mode" value="1" <?php checked($test_mode, 1); ?> style="transform: scale(1.2); margin-right: 8px;" />
                                <?php _e('Enable Test Mode', 'wp-lms'); ?>
                            </label>
                            <p class="description" style="margin-top: 8px;">
                                <?php _e('Toggle between test and live mode. Test mode uses test keys and allows testing with test credit cards.', 'wp-lms'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <h2 style="color: #ff9800; border-bottom: 2px solid #ff9800; padding-bottom: 5px;">
                    🧪 <?php _e('Test Keys (Development)', 'wp-lms'); ?>
                </h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Test Secret Key', 'wp-lms'); ?></th>
                        <td>
                            <input type="password" name="wp_lms_stripe_test_secret_key" value="<?php echo esc_attr($test_secret_key); ?>" class="regular-text" placeholder="sk_test_..." />
                            <p class="description"><?php _e('Your Stripe test secret key (starts with sk_test_)', 'wp-lms'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Test Publishable Key', 'wp-lms'); ?></th>
                        <td>
                            <input type="text" name="wp_lms_stripe_test_publishable_key" value="<?php echo esc_attr($test_publishable_key); ?>" class="regular-text" placeholder="pk_test_..." />
                            <p class="description"><?php _e('Your Stripe test publishable key (starts with pk_test_)', 'wp-lms'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <h2 style="color: #d32f2f; border-bottom: 2px solid #d32f2f; padding-bottom: 5px;">
                    🔴 <?php _e('Live Keys (Production)', 'wp-lms'); ?>
                </h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Live Secret Key', 'wp-lms'); ?></th>
                        <td>
                            <input type="password" name="wp_lms_stripe_live_secret_key" value="<?php echo esc_attr($live_secret_key); ?>" class="regular-text" placeholder="sk_live_..." />
                            <p class="description"><?php _e('Your Stripe live secret key (starts with sk_live_)', 'wp-lms'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Live Publishable Key', 'wp-lms'); ?></th>
                        <td>
                            <input type="text" name="wp_lms_stripe_live_publishable_key" value="<?php echo esc_attr($live_publishable_key); ?>" class="regular-text" placeholder="pk_live_..." />
                            <p class="description"><?php _e('Your Stripe live publishable key (starts with pk_live_)', 'wp-lms'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <h2 style="color: #666; border-bottom: 2px solid #666; padding-bottom: 5px;">
                    🔗 <?php _e('Webhook Configuration', 'wp-lms'); ?>
                </h2>
                
                <div class="notice notice-info inline" style="margin-bottom: 15px;">
                    <p><strong><?php _e('Webhook Info:', 'wp-lms'); ?></strong> <?php _e('Webhooks sind für vollständige Stripe-Integration empfohlen, aber für grundlegende Tests optional.', 'wp-lms'); ?></p>
                </div>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Test Webhook Secret', 'wp-lms'); ?></th>
                        <td>
                            <input type="password" name="wp_lms_stripe_test_webhook_secret" value="<?php echo esc_attr($test_webhook_secret); ?>" class="regular-text" placeholder="whsec_..." />
                            <p class="description"><?php _e('Your Stripe test webhook secret (from test dashboard)', 'wp-lms'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Live Webhook Secret', 'wp-lms'); ?></th>
                        <td>
                            <input type="password" name="wp_lms_stripe_live_webhook_secret" value="<?php echo esc_attr($live_webhook_secret); ?>" class="regular-text" placeholder="whsec_..." />
                            <p class="description"><?php _e('Your Stripe live webhook secret (from live dashboard)', 'wp-lms'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Webhook URL', 'wp-lms'); ?></th>
                        <td>
                            <code><?php echo home_url('/?wp_lms_stripe_webhook=1'); ?></code>
                            <p class="description">
                                <?php _e('Use this URL for both test and live webhooks in your Stripe dashboard.', 'wp-lms'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <!-- Webhook Setup Instructions -->
                <div class="webhook-instructions" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin-top: 20px;">
                    <h3 style="margin-top: 0; color: #495057;">📋 <?php _e('Webhook Setup Instructions', 'wp-lms'); ?></h3>
                    
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #ff9800;">🧪 <?php _e('Test Mode Webhook:', 'wp-lms'); ?></h4>
                        <ol style="margin-left: 20px;">
                            <li><?php _e('Go to', 'wp-lms'); ?> <a href="https://dashboard.stripe.com/test/webhooks" target="_blank">Stripe Test Dashboard → Webhooks</a></li>
                            <li><?php _e('Click "Add endpoint"', 'wp-lms'); ?></li>
                            <li><?php _e('Endpoint URL:', 'wp-lms'); ?> <code><?php echo home_url('/?wp_lms_stripe_webhook=1'); ?></code></li>
                            <li><?php _e('Select events:', 'wp-lms'); ?> <code>payment_intent.succeeded</code>, <code>payment_intent.payment_failed</code></li>
                            <li><?php _e('Copy the "Signing secret" (whsec_...) and paste it in the "Test Webhook Secret" field above', 'wp-lms'); ?></li>
                        </ol>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #d32f2f;">🔴 <?php _e('Live Mode Webhook:', 'wp-lms'); ?></h4>
                        <ol style="margin-left: 20px;">
                            <li><?php _e('Go to', 'wp-lms'); ?> <a href="https://dashboard.stripe.com/webhooks" target="_blank">Stripe Live Dashboard → Webhooks</a></li>
                            <li><?php _e('Click "Add endpoint"', 'wp-lms'); ?></li>
                            <li><?php _e('Endpoint URL:', 'wp-lms'); ?> <code><?php echo home_url('/?wp_lms_stripe_webhook=1'); ?></code></li>
                            <li><?php _e('Select events:', 'wp-lms'); ?> <code>payment_intent.succeeded</code>, <code>payment_intent.payment_failed</code></li>
                            <li><?php _e('Copy the "Signing secret" (whsec_...) and paste it in the "Live Webhook Secret" field above', 'wp-lms'); ?></li>
                        </ol>
                    </div>
                    
                    <div class="notice notice-warning inline">
                        <p><strong><?php _e('Für grundlegende Tests:', 'wp-lms'); ?></strong> <?php _e('Sie können ohne Webhook testen. Zahlungen werden manuell bestätigt.', 'wp-lms'); ?></p>
                        <p><strong><?php _e('Für Produktion:', 'wp-lms'); ?></strong> <?php _e('Webhook ist erforderlich für automatische Zahlungsbestätigung.', 'wp-lms'); ?></p>
                    </div>
                </div>
                
                <?php submit_button(__('Save Stripe Configuration', 'wp-lms'), 'primary', 'submit', true, array('style' => 'font-size: 16px; padding: 10px 20px;')); ?>
            </form>
            
            <!-- Current Mode Status -->
            <div class="stripe-mode-status" style="background: <?php echo $test_mode ? '#fff3cd' : '#f8d7da'; ?>; border: 1px solid <?php echo $test_mode ? '#ffeaa7' : '#f5c6cb'; ?>; padding: 20px; border-radius: 5px; margin-top: 20px;">
                <h3 style="margin-top: 0;">
                    <?php if ($test_mode): ?>
                        🧪 <?php _e('Currently in TEST MODE', 'wp-lms'); ?>
                    <?php else: ?>
                        🔴 <?php _e('Currently in LIVE MODE', 'wp-lms'); ?>
                    <?php endif; ?>
                </h3>
                <p>
                    <?php if ($test_mode): ?>
                        <strong><?php _e('Active Keys:', 'wp-lms'); ?></strong> <?php _e('Test keys are being used. No real money will be charged.', 'wp-lms'); ?>
                        <br><strong><?php _e('Dashboard:', 'wp-lms'); ?></strong> <a href="https://dashboard.stripe.com/test" target="_blank">Stripe Test Dashboard</a>
                    <?php else: ?>
                        <strong><?php _e('Active Keys:', 'wp-lms'); ?></strong> <?php _e('Live keys are being used. Real money will be charged!', 'wp-lms'); ?>
                        <br><strong><?php _e('Dashboard:', 'wp-lms'); ?></strong> <a href="https://dashboard.stripe.com" target="_blank">Stripe Live Dashboard</a>
                    <?php endif; ?>
                </p>
            </div>
            
            <?php if (get_option('wp_lms_stripe_test_mode', 0)): ?>
            <div class="stripe-test-info" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 20px; border-radius: 5px; margin-top: 20px;">
                <h3><?php _e('Test Mode Information', 'wp-lms'); ?></h3>
                <p><strong><?php _e('Test mode is enabled!', 'wp-lms'); ?></strong> <?php _e('You can use the following test card numbers:', 'wp-lms'); ?></p>
                
                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th><?php _e('Card Number', 'wp-lms'); ?></th>
                            <th><?php _e('Description', 'wp-lms'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>4242424242424242</code></td>
                            <td><?php _e('Visa - Successful payment', 'wp-lms'); ?></td>
                        </tr>
                        <tr>
                            <td><code>4000000000000002</code></td>
                            <td><?php _e('Visa - Card declined', 'wp-lms'); ?></td>
                        </tr>
                        <tr>
                            <td><code>4000000000009995</code></td>
                            <td><?php _e('Visa - Insufficient funds', 'wp-lms'); ?></td>
                        </tr>
                        <tr>
                            <td><code>5555555555554444</code></td>
                            <td><?php _e('Mastercard - Successful payment', 'wp-lms'); ?></td>
                        </tr>
                    </tbody>
                </table>
                
                <p style="margin-top: 15px;">
                    <strong><?php _e('Additional test details:', 'wp-lms'); ?></strong><br>
                    • <?php _e('Use any future expiry date (e.g., 12/34)', 'wp-lms'); ?><br>
                    • <?php _e('Use any 3-digit CVC (e.g., 123)', 'wp-lms'); ?><br>
                    • <?php _e('Use any postal code (e.g., 12345)', 'wp-lms'); ?>
                </p>
                
                <div class="notice notice-warning inline" style="margin-top: 15px;">
                    <p><strong><?php _e('Important:', 'wp-lms'); ?></strong> <?php _e('No real money will be charged in test mode. All transactions are simulated.', 'wp-lms'); ?></p>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="stripe-test-section">
                <h3><?php _e('Test Connection', 'wp-lms'); ?></h3>
                <button id="test-stripe-connection" class="button"><?php _e('Test Stripe Connection', 'wp-lms'); ?></button>
                <div id="stripe-test-result"></div>
            </div>
        </div>
        
        <?php
        // JavaScript functionality moved to assets/js/admin.js
        // Pass nonces and texts via data attributes
        echo '<div id="stripe-test-data" 
                   data-nonce="' . wp_create_nonce('test_stripe_connection') . '" 
                   data-testing-text="' . esc_attr(__('Testing...', 'wp-lms')) . '"
                   data-test-text="' . esc_attr(__('Test Stripe Connection', 'wp-lms')) . '"
                   style="display: none;"></div>';
        ?>
        <?php
    }
    
    /**
     * WASM settings page (simplified - no SFTP upload needed)
     */
    public function wasm_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('WASM Configuration', 'wp-lms'); ?></h1>
            
            <div class="notice notice-info">
                <p><?php _e('WASM files should be uploaded manually via FTP/SFTP to your web server. In the lesson editor, you can then enter the direct URL to each WASM file in the code sections.', 'wp-lms'); ?></p>
            </div>
            
            <h2><?php _e('How to use WASM files:', 'wp-lms'); ?></h2>
            <ol>
                <li><?php _e('Upload your compiled WASM files (and any HTML wrappers) to your web server using FTP/SFTP', 'wp-lms'); ?></li>
                <li><?php _e('Make sure the files are accessible via HTTP/HTTPS', 'wp-lms'); ?></li>
                <li><?php _e('In the lesson editor, add code sections and enter the direct URL to the WASM file', 'wp-lms'); ?></li>
                <li><?php _e('Students will be able to run the WASM application by clicking the "Run" button', 'wp-lms'); ?></li>
            </ol>
            
            <h3><?php _e('Recommended file structure:', 'wp-lms'); ?></h3>
            <pre style="background: #f1f1f1; padding: 15px; border-radius: 4px;">
/wp-content/uploads/wasm/
├── course-1/
│   ├── lesson-1/
│   │   ├── example1.html
│   │   ├── example1.wasm
│   │   └── example2.html
│   └── lesson-2/
│       └── example3.html
└── course-2/
    └── lesson-1/
        └── demo.html
            </pre>
            
            <h3><?php _e('Example URLs:', 'wp-lms'); ?></h3>
            <ul>
                <li><code><?php echo home_url('/wp-content/uploads/wasm/course-1/lesson-1/example1.html'); ?></code></li>
                <li><code><?php echo home_url('/wp-content/uploads/wasm/course-1/lesson-1/example2.html'); ?></code></li>
            </ul>
            
            <div class="notice notice-warning">
                <p><strong><?php _e('Security Note:', 'wp-lms'); ?></strong> <?php _e('Make sure your web server is configured to serve WASM files with the correct MIME type (application/wasm).', 'wp-lms'); ?></p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Display course statistics
     */
    private function display_course_stats() {
        global $wpdb;
        
        $course_counts = wp_count_posts('lms_course');
        $chapter_counts = wp_count_posts('lms_chapter');
        $lesson_counts = wp_count_posts('lms_lesson');
        
        $total_courses = isset($course_counts->publish) ? $course_counts->publish : 0;
        $total_chapters = isset($chapter_counts->publish) ? $chapter_counts->publish : 0;
        $total_lessons = isset($lesson_counts->publish) ? $lesson_counts->publish : 0;
        
        $purchases_table = $wpdb->prefix . 'lms_course_purchases';
        $total_sales = $wpdb->get_var("SELECT COUNT(*) FROM $purchases_table WHERE status = 'completed'") ?: 0;
        $total_revenue = $wpdb->get_var("SELECT SUM(amount) FROM $purchases_table WHERE status = 'completed'") ?: 0;
        
        ?>
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-number"><?php echo $total_courses; ?></div>
                <div class="stat-label"><?php _e('Courses', 'wp-lms'); ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $total_chapters; ?></div>
                <div class="stat-label"><?php _e('Chapters', 'wp-lms'); ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $total_lessons; ?></div>
                <div class="stat-label"><?php _e('Lessons', 'wp-lms'); ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $total_sales; ?></div>
                <div class="stat-label"><?php _e('Sales', 'wp-lms'); ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-number">€<?php echo number_format((float)$total_revenue, 2); ?></div>
                <div class="stat-label"><?php _e('Revenue', 'wp-lms'); ?></div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Display recent purchases
     */
    private function display_recent_purchases() {
        global $wpdb;
        
        $purchases_table = $wpdb->prefix . 'lms_course_purchases';
        $recent_purchases = $wpdb->get_results("
            SELECT p.*, u.display_name, c.post_title as course_title
            FROM $purchases_table p
            LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
            LEFT JOIN {$wpdb->posts} c ON p.course_id = c.ID
            WHERE p.status = 'completed'
            ORDER BY p.purchased_at DESC
            LIMIT 10
        ");
        
        if ($recent_purchases) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>' . __('User', 'wp-lms') . '</th><th>' . __('Course', 'wp-lms') . '</th><th>' . __('Amount', 'wp-lms') . '</th><th>' . __('Date', 'wp-lms') . '</th></tr></thead>';
            echo '<tbody>';
            foreach ($recent_purchases as $purchase) {
                echo '<tr>';
                echo '<td>' . esc_html($purchase->display_name) . '</td>';
                echo '<td>' . esc_html($purchase->course_title) . '</td>';
                echo '<td>' . esc_html($purchase->currency) . ' ' . number_format($purchase->amount, 2) . '</td>';
                echo '<td>' . date('Y-m-d H:i', strtotime($purchase->purchased_at)) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . __('No purchases yet.', 'wp-lms') . '</p>';
        }
    }
    
    /**
     * Display user progress overview
     */
    private function display_user_progress() {
        global $wpdb;
        
        $progress_table = $wpdb->prefix . 'lms_user_progress';
        $active_users = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id) 
            FROM $progress_table 
            WHERE last_accessed > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        
        $completed_lessons = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM $progress_table 
            WHERE completed = 1
        ");
        
        echo '<div class="progress-stats">';
        echo '<p><strong>' . __('Active Users (7 days):', 'wp-lms') . '</strong> ' . $active_users . '</p>';
        echo '<p><strong>' . __('Completed Lessons:', 'wp-lms') . '</strong> ' . $completed_lessons . '</p>';
        echo '</div>';
    }
    
    /**
     * Course access management page
     */
    public function course_access_page() {
        $database = new WP_LMS_Database();
        
        // Handle form submissions
        if (isset($_POST['grant_access'])) {
            $user_id = intval($_POST['user_id']);
            $course_id = intval($_POST['course_id']);
            
            if ($user_id && $course_id) {
                $result = $database->grant_free_course_access($user_id, $course_id);
                if ($result) {
                    echo '<div class="notice notice-success"><p>' . __('Free access granted successfully!', 'wp-lms') . '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>' . __('Failed to grant access or user already has access.', 'wp-lms') . '</p></div>';
                }
            }
        }
        
        if (isset($_POST['remove_access'])) {
            $user_id = intval($_POST['user_id']);
            $course_id = intval($_POST['course_id']);
            
            if ($user_id && $course_id) {
                $result = $database->remove_course_access($user_id, $course_id);
                if ($result) {
                    echo '<div class="notice notice-success"><p>' . __('Access removed successfully!', 'wp-lms') . '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>' . __('Failed to remove access.', 'wp-lms') . '</p></div>';
                }
            }
        }
        
        // Get all courses
        $courses = get_posts(array(
            'post_type' => 'lms_course',
            'numberposts' => -1,
            'post_status' => 'publish'
        ));
        
        // Get all users
        $users = get_users(array(
            'orderby' => 'display_name',
            'order' => 'ASC'
        ));
        
        ?>
        <div class="wrap">
            <h1><?php _e('Course Access Management', 'wp-lms'); ?></h1>
            
            <div class="notice notice-info">
                <p><?php _e('Here you can grant free access to courses for testing purposes or remove access from users.', 'wp-lms'); ?></p>
            </div>
            
            <div style="display: flex; gap: 30px;">
                <!-- Grant Access Form -->
                <div style="flex: 1;">
                    <h2><?php _e('Grant Free Access', 'wp-lms'); ?></h2>
                    <form method="post" action="">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('User', 'wp-lms'); ?></th>
                                <td>
                                    <select name="user_id" required>
                                        <option value=""><?php _e('Select User', 'wp-lms'); ?></option>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?php echo $user->ID; ?>">
                                                <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Course', 'wp-lms'); ?></th>
                                <td>
                                    <select name="course_id" required>
                                        <option value=""><?php _e('Select Course', 'wp-lms'); ?></option>
                                        <?php foreach ($courses as $course): ?>
                                            <option value="<?php echo $course->ID; ?>">
                                                <?php echo esc_html($course->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <input type="submit" name="grant_access" class="button button-primary" value="<?php _e('Grant Free Access', 'wp-lms'); ?>" />
                        </p>
                    </form>
                </div>
                
                <!-- Remove Access Form -->
                <div style="flex: 1;">
                    <h2><?php _e('Remove Access', 'wp-lms'); ?></h2>
                    <form method="post" action="">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('User', 'wp-lms'); ?></th>
                                <td>
                                    <select name="user_id" required>
                                        <option value=""><?php _e('Select User', 'wp-lms'); ?></option>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?php echo $user->ID; ?>">
                                                <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Course', 'wp-lms'); ?></th>
                                <td>
                                    <select name="course_id" required>
                                        <option value=""><?php _e('Select Course', 'wp-lms'); ?></option>
                                        <?php foreach ($courses as $course): ?>
                                            <option value="<?php echo $course->ID; ?>">
                                                <?php echo esc_html($course->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <input type="submit" name="remove_access" class="button button-secondary wp-lms-remove-access-btn" value="<?php _e('Remove Access', 'wp-lms'); ?>" data-confirm="<?php echo esc_attr(__('Are you sure you want to remove access?', 'wp-lms')); ?>" />
                        </p>
                    </form>
                </div>
            </div>
            
            <!-- Current Access Overview -->
            <h2><?php _e('Current Course Access', 'wp-lms'); ?></h2>
            <?php $this->display_current_access(); ?>
        </div>
        <?php
    }
    
    /**
     * Display current course access
     */
    private function display_current_access() {
        global $wpdb;
        
        $purchases_table = $wpdb->prefix . 'lms_course_purchases';
        $access_list = $wpdb->get_results("
            SELECT p.*, u.display_name, u.user_email, c.post_title as course_title
            FROM $purchases_table p
            LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
            LEFT JOIN {$wpdb->posts} c ON p.course_id = c.ID
            WHERE p.status = 'completed'
            ORDER BY p.purchased_at DESC
        ");
        
        if ($access_list) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . __('User', 'wp-lms') . '</th>';
            echo '<th>' . __('Course', 'wp-lms') . '</th>';
            echo '<th>' . __('Amount', 'wp-lms') . '</th>';
            echo '<th>' . __('Type', 'wp-lms') . '</th>';
            echo '<th>' . __('Date', 'wp-lms') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($access_list as $access) {
                $is_free = ($access->amount == 0.00 || strpos($access->stripe_payment_intent_id, 'free_access_') === 0);
                echo '<tr>';
                echo '<td>' . esc_html($access->display_name . ' (' . $access->user_email . ')') . '</td>';
                echo '<td>' . esc_html($access->course_title) . '</td>';
                echo '<td>' . esc_html($access->currency) . ' ' . number_format($access->amount, 2) . '</td>';
                echo '<td>' . ($is_free ? '<span style="color: green;">Free Access</span>' : '<span style="color: blue;">Paid</span>') . '</td>';
                echo '<td>' . date('Y-m-d H:i', strtotime($access->purchased_at)) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        } else {
            echo '<p>' . __('No course access granted yet.', 'wp-lms') . '</p>';
        }
    }
    
    /**
     * Sales management page
     */
    public function sales_management_page() {
        $database = new WP_LMS_Database();
        
        // Get filter parameters
        $email_filter = isset($_GET['email_filter']) ? sanitize_text_field($_GET['email_filter']) : '';
        $premium_filter = isset($_GET['premium_filter']) ? sanitize_text_field($_GET['premium_filter']) : '';
        $course_filter = isset($_GET['course_filter']) ? intval($_GET['course_filter']) : '';
        
        // Build filters array
        $filters = array();
        if (!empty($email_filter)) {
            $filters['email'] = $email_filter;
        }
        if ($premium_filter !== '') {
            $filters['is_premium'] = ($premium_filter === '1');
        }
        if (!empty($course_filter)) {
            $filters['course_id'] = $course_filter;
        }
        
        // Get purchases and statistics
        $purchases = $database->get_course_purchases($filters);
        $stats = $database->get_purchase_statistics();
        
        // Get all courses for filter dropdown
        $courses = get_posts(array(
            'post_type' => 'lms_course',
            'numberposts' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC'
        ));
        
        ?>
        <div class="wrap">
            <h1><?php _e('Sales Management', 'wp-lms'); ?></h1>
            
            <!-- Statistics Dashboard -->
            <div class="wp-lms-sales-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h3 style="margin: 0 0 10px 0; color: #333;"><?php _e('Total Sales', 'wp-lms'); ?></h3>
                    <div style="font-size: 24px; font-weight: bold; color: #007cba;"><?php echo $stats['total_purchases']; ?></div>
                    <div style="font-size: 14px; color: #666;">€<?php echo number_format($stats['total_revenue'], 2); ?></div>
                </div>
                
                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h3 style="margin: 0 0 10px 0; color: #333;"><?php _e('Standard Sales', 'wp-lms'); ?></h3>
                    <div style="font-size: 24px; font-weight: bold; color: #46b450;"><?php echo $stats['standard_purchases']; ?></div>
                    <div style="font-size: 14px; color: #666;">€<?php echo number_format($stats['standard_revenue'], 2); ?></div>
                </div>
                
                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h3 style="margin: 0 0 10px 0; color: #333;"><?php _e('Premium Sales', 'wp-lms'); ?></h3>
                    <div style="font-size: 24px; font-weight: bold; color: #ff9800;"><?php echo $stats['premium_purchases']; ?></div>
                    <div style="font-size: 14px; color: #666;">€<?php echo number_format($stats['premium_revenue'], 2); ?></div>
                </div>
                
                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h3 style="margin: 0 0 10px 0; color: #333;"><?php _e('Premium Rate', 'wp-lms'); ?></h3>
                    <div style="font-size: 24px; font-weight: bold; color: #9c27b0;">
                        <?php 
                        $premium_rate = $stats['total_purchases'] > 0 ? ($stats['premium_purchases'] / $stats['total_purchases']) * 100 : 0;
                        echo number_format($premium_rate, 1) . '%'; 
                        ?>
                    </div>
                    <div style="font-size: 14px; color: #666;"><?php _e('of all sales', 'wp-lms'); ?></div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="wp-lms-sales-filters" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px;">
                <h3><?php _e('Filter Sales', 'wp-lms'); ?></h3>
                <form method="get" action="">
                    <input type="hidden" name="page" value="wp-lms-sales" />
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
                        <div>
                            <label for="email_filter"><?php _e('Email Filter', 'wp-lms'); ?></label>
                            <input type="text" id="email_filter" name="email_filter" value="<?php echo esc_attr($email_filter); ?>" 
                                   placeholder="<?php _e('Enter email or part of email', 'wp-lms'); ?>" class="regular-text" />
                        </div>
                        
                        <div>
                            <label for="premium_filter"><?php _e('Purchase Type', 'wp-lms'); ?></label>
                            <select id="premium_filter" name="premium_filter">
                                <option value=""><?php _e('All Types', 'wp-lms'); ?></option>
                                <option value="0" <?php selected($premium_filter, '0'); ?>><?php _e('Standard Only', 'wp-lms'); ?></option>
                                <option value="1" <?php selected($premium_filter, '1'); ?>><?php _e('Premium Only', 'wp-lms'); ?></option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="course_filter"><?php _e('Course', 'wp-lms'); ?></label>
                            <select id="course_filter" name="course_filter">
                                <option value=""><?php _e('All Courses', 'wp-lms'); ?></option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?php echo $course->ID; ?>" <?php selected($course_filter, $course->ID); ?>>
                                        <?php echo esc_html($course->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <input type="submit" class="button button-primary" value="<?php _e('Filter', 'wp-lms'); ?>" />
                            <a href="<?php echo admin_url('admin.php?page=wp-lms-sales'); ?>" class="button"><?php _e('Clear', 'wp-lms'); ?></a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Sales Table -->
            <div class="wp-lms-sales-table">
                <?php if (!empty($purchases)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Date', 'wp-lms'); ?></th>
                                <th><?php _e('Customer', 'wp-lms'); ?></th>
                                <th><?php _e('Email', 'wp-lms'); ?></th>
                                <th><?php _e('Course', 'wp-lms'); ?></th>
                                <th><?php _e('Type', 'wp-lms'); ?></th>
                                <th><?php _e('Amount', 'wp-lms'); ?></th>
                                <th><?php _e('Payment ID', 'wp-lms'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($purchases as $purchase): ?>
                                <?php 
                                $course = get_post($purchase->course_id);
                                $course_title = $course ? $course->post_title : __('Unknown Course', 'wp-lms');
                                $customer_email = $purchase->customer_email ?: $purchase->user_email;
                                ?>
                                <tr>
                                    <td><?php echo date('Y-m-d H:i', strtotime($purchase->purchased_at)); ?></td>
                                    <td><?php echo esc_html($purchase->display_name ?: __('Guest', 'wp-lms')); ?></td>
                                    <td><?php echo esc_html($customer_email); ?></td>
                                    <td><?php echo esc_html($course_title); ?></td>
                                    <td>
                                        <?php if ($purchase->is_premium): ?>
                                            <span style="background: #ff9800; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold;">
                                                <?php _e('PREMIUM', 'wp-lms'); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="background: #46b450; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold;">
                                                <?php _e('STANDARD', 'wp-lms'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($purchase->currency); ?> <?php echo number_format($purchase->amount, 2); ?></strong>
                                    </td>
                                    <td>
                                        <code style="font-size: 11px;"><?php echo esc_html(substr($purchase->stripe_payment_intent_id, 0, 20)); ?>...</code>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                        <strong><?php _e('Filtered Results:', 'wp-lms'); ?></strong>
                        <?php 
                        $filtered_total = count($purchases);
                        $filtered_revenue = array_sum(array_column($purchases, 'amount'));
                        $filtered_premium = count(array_filter($purchases, function($p) { return $p->is_premium; }));
                        ?>
                        <?php echo sprintf(__('%d sales totaling €%s (%d premium, %d standard)', 'wp-lms'), 
                            $filtered_total, 
                            number_format($filtered_revenue, 2),
                            $filtered_premium,
                            $filtered_total - $filtered_premium
                        ); ?>
                    </div>
                    
                <?php else: ?>
                    <div class="notice notice-info">
                        <p><?php _e('No sales found matching your criteria.', 'wp-lms'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        .wp-lms-sales-filters label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .wp-lms-sales-filters select,
        .wp-lms-sales-filters input[type="text"] {
            width: 100%;
        }
        
        .wp-list-table th,
        .wp-list-table td {
            padding: 12px 8px;
        }
        
        .wp-list-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        </style>
        <?php
    }
    
    /**
     * Test Stripe connection
     */
    public function test_stripe_connection() {
        check_ajax_referer('test_stripe_connection', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        $secret_key = get_option('wp_lms_stripe_secret_key', '');
        
        if (empty($secret_key)) {
            wp_send_json_error('Stripe secret key not configured.');
            return;
        }
        
        try {
            // Include Stripe PHP library
            if (!class_exists('\Stripe\Stripe')) {
                // For now, we'll just check if the key format is correct
                if (strpos($secret_key, 'sk_') !== 0) {
                    wp_send_json_error('Invalid Stripe secret key format. Key should start with "sk_".');
                    return;
                }
                
                // Check if it's test or live key
                $is_test_key = strpos($secret_key, 'sk_test_') === 0;
                $test_mode = get_option('wp_lms_stripe_test_mode', 0);
                
                if ($test_mode && !$is_test_key) {
                    wp_send_json_error('Test mode is enabled but you are using a live key. Please use a test key (sk_test_...).');
                    return;
                }
                
                if (!$test_mode && $is_test_key) {
                    wp_send_json_error('Test mode is disabled but you are using a test key. Please use a live key (sk_live_...) or enable test mode.');
                    return;
                }
                
                wp_send_json_success('Stripe key format is valid. ' . ($is_test_key ? 'Test mode key detected.' : 'Live mode key detected.') . ' Note: Full connection test requires Stripe PHP library.');
                return;
            }
            
            // If Stripe library is available, do a real test
            \Stripe\Stripe::setApiKey($secret_key);
            
            // Try to retrieve account information
            $account = \Stripe\Account::retrieve();
            
            wp_send_json_success('Stripe connection successful! Account: ' . $account->display_name);
            
        } catch (Exception $e) {
            wp_send_json_error('Stripe connection failed: ' . $e->getMessage());
        }
    }
}
