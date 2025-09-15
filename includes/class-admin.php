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
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Stripe settings
        register_setting('wp_lms_stripe_settings', 'wp_lms_stripe_secret_key');
        register_setting('wp_lms_stripe_settings', 'wp_lms_stripe_publishable_key');
        register_setting('wp_lms_stripe_settings', 'wp_lms_stripe_webhook_secret');
        
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
            update_option('wp_lms_stripe_secret_key', sanitize_text_field($_POST['wp_lms_stripe_secret_key']));
            update_option('wp_lms_stripe_publishable_key', sanitize_text_field($_POST['wp_lms_stripe_publishable_key']));
            update_option('wp_lms_stripe_webhook_secret', sanitize_text_field($_POST['wp_lms_stripe_webhook_secret']));
            update_option('wp_lms_stripe_test_mode', isset($_POST['wp_lms_stripe_test_mode']) ? 1 : 0);
            echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'wp-lms') . '</p></div>';
        }
        
        $secret_key = get_option('wp_lms_stripe_secret_key', '');
        $publishable_key = get_option('wp_lms_stripe_publishable_key', '');
        $webhook_secret = get_option('wp_lms_stripe_webhook_secret', '');
        ?>
        <div class="wrap">
            <h1><?php _e('Stripe Configuration', 'wp-lms'); ?></h1>
            
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Test Mode', 'wp-lms'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wp_lms_stripe_test_mode" value="1" <?php checked(get_option('wp_lms_stripe_test_mode', 0), 1); ?> />
                                <?php _e('Enable Test Mode', 'wp-lms'); ?>
                            </label>
                            <p class="description"><?php _e('Use Stripe test keys and enable test card numbers for development.', 'wp-lms'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Secret Key', 'wp-lms'); ?></th>
                        <td>
                            <input type="password" name="wp_lms_stripe_secret_key" value="<?php echo esc_attr($secret_key); ?>" class="regular-text" />
                            <p class="description"><?php _e('Your Stripe secret key (starts with sk_test_ for test mode or sk_live_ for live mode)', 'wp-lms'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Publishable Key', 'wp-lms'); ?></th>
                        <td>
                            <input type="text" name="wp_lms_stripe_publishable_key" value="<?php echo esc_attr($publishable_key); ?>" class="regular-text" />
                            <p class="description"><?php _e('Your Stripe publishable key (starts with pk_)', 'wp-lms'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Webhook Secret', 'wp-lms'); ?></th>
                        <td>
                            <input type="password" name="wp_lms_stripe_webhook_secret" value="<?php echo esc_attr($webhook_secret); ?>" class="regular-text" />
                            <p class="description">
                                <?php _e('Your Stripe webhook endpoint secret. Set your webhook URL to:', 'wp-lms'); ?>
                                <br><code><?php echo home_url('/?wp_lms_stripe_webhook=1'); ?></code>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
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
        
        <script>
        jQuery(document).ready(function($) {
            $('#test-stripe-connection').click(function() {
                var button = $(this);
                button.prop('disabled', true).text('<?php _e('Testing...', 'wp-lms'); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'test_stripe_connection',
                        nonce: '<?php echo wp_create_nonce('test_stripe_connection'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#stripe-test-result').html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                        } else {
                            $('#stripe-test-result').html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                        }
                    },
                    complete: function() {
                        button.prop('disabled', false).text('<?php _e('Test Stripe Connection', 'wp-lms'); ?>');
                    }
                });
            });
        });
        </script>
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
                            <input type="submit" name="remove_access" class="button button-secondary" value="<?php _e('Remove Access', 'wp-lms'); ?>" onclick="return confirm('<?php _e('Are you sure you want to remove access?', 'wp-lms'); ?>')" />
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
