<?php
/**
 * Frontend functionality for WP LMS Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_LMS_Frontend {
    
    private $database;
    private $stripe;
    
    public function __construct() {
        $this->database = new WP_LMS_Database();
        $this->stripe = new WP_LMS_Stripe_Integration();
        
        add_filter('the_content', array($this, 'modify_course_content'));
        add_action('wp_ajax_get_lesson_data', array($this, 'get_lesson_data'));
        add_action('wp_ajax_nopriv_get_lesson_data', array($this, 'get_lesson_data'));
        add_action('wp_ajax_update_lesson_progress', array($this, 'update_lesson_progress'));
        add_action('wp_ajax_nopriv_update_lesson_progress', array($this, 'update_lesson_progress'));
        add_action('wp_ajax_wp_lms_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_nopriv_wp_lms_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_get_course_progress', array($this, 'get_course_progress'));
        add_action('wp_ajax_nopriv_get_course_progress', array($this, 'get_course_progress'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_stripe_js'));
        add_action('wp_head', array($this, 'hide_course_meta'));
        // Removed template override - using CSS only to hide meta information
    }
    
    /**
     * Enqueue scripts and localize AJAX
     */
    public function enqueue_stripe_js() {
        if (is_singular('lms_course')) {
            // Enqueue our frontend script first
            wp_enqueue_script('wp-lms-frontend', plugin_dir_url(__FILE__) . '../assets/js/frontend.js', array('jquery'), '1.0', true);
            
            // Enqueue Stripe.js
            wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);
            
            // Localize script for AJAX - this is crucial for progress tracking
            global $post;
            wp_localize_script('wp-lms-frontend', 'wp_lms_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wp_lms_nonce'),
                'stripe_publishable_key' => get_option('wp_lms_stripe_publishable_key', ''),
                'debug' => WP_DEBUG,
                'course_id' => isset($post) ? $post->ID : null
            ));
        }
    }
    
    /**
     * Hide course meta information (date, author, etc.)
     */
    public function hide_course_meta() {
        if (is_singular('lms_course')) {
            ?>
            <style type="text/css">
                /* Hide post meta information on course pages */
                .single-lms_course .entry-meta,
                .single-lms_course .post-meta,
                .single-lms_course .entry-date,
                .single-lms_course .posted-on,
                .single-lms_course .byline,
                .single-lms_course .author,
                .single-lms_course .entry-footer,
                .single-lms_course .post-date,
                .single-lms_course .published,
                .single-lms_course .updated,
                .single-lms_course .cat-links,
                .single-lms_course .tags-links,
                .single-lms_course .comments-link,
                .single-lms_course .edit-link,
                .single-lms_course .post-navigation,
                .single-lms_course .nav-links {
                    display: none !important;
                }
                
                /* Hide common theme meta classes */
                .single-lms_course .meta-info,
                .single-lms_course .post-info,
                .single-lms_course .entry-info,
                .single-lms_course .post-details,
                .single-lms_course .article-meta,
                .single-lms_course .post-header-meta {
                    display: none !important;
                }
                
                /* Hide breadcrumbs if they show post type */
                .single-lms_course .breadcrumb,
                .single-lms_course .breadcrumbs {
                    display: none !important;
                }
                
                /* Hide more specific meta elements */
                .single-lms_course .entry-header .entry-meta,
                .single-lms_course .entry-content .entry-meta,
                .single-lms_course .post-header .post-meta,
                .single-lms_course .post-content .post-meta,
                .single-lms_course time,
                .single-lms_course .time,
                .single-lms_course .date,
                .single-lms_course .datetime,
                .single-lms_course .post-author,
                .single-lms_course .author-name,
                .single-lms_course .post-categories,
                .single-lms_course .post-tags,
                .single-lms_course .meta-separator,
                .single-lms_course .meta-divider {
                    display: none !important;
                }
                
                /* Hide elements that contain date/author info */
                .single-lms_course p:has(time),
                .single-lms_course div:has(.entry-date),
                .single-lms_course span:has(.published) {
                    display: none !important;
                }
                
                /* Hide any text nodes that might contain date patterns */
                .single-lms_course .entry-header > *:not(.entry-title):not(h1):not(h2):not(h3) {
                    display: none !important;
                }
            </style>
            <?php
        }
    }
    
    /**
     * Modify course content display
     */
    public function modify_course_content($content) {
        if (!is_singular('lms_course')) {
            return $content;
        }
        
        // Prevent multiple executions
        static $already_processed = false;
        if ($already_processed) {
            return $content;
        }
        $already_processed = true;
        
        global $post;
        $course_id = $post->ID;
        $user_id = get_current_user_id();
        
        // Check if user wants to start the course
        $action = isset($_GET['action']) ? $_GET['action'] : '';
        
        if ($action === 'start' && $user_id && $this->database->has_user_purchased_course($user_id, $course_id)) {
            return $this->render_learning_interface($course_id, $user_id);
        }
        
        // Show course overview with purchase/continue button
        return $this->render_course_overview($course_id, $user_id, $content);
    }
    
    /**
     * Render course overview page
     */
    private function render_course_overview($course_id, $user_id, $original_content) {
        $price = get_post_meta($course_id, '_course_price', true);
        $currency = get_post_meta($course_id, '_course_currency', true) ?: 'EUR';
        $premium_enabled = get_post_meta($course_id, '_course_premium_enabled', true);
        $premium_price = get_post_meta($course_id, '_course_premium_price', true);
        $premium_features = get_post_meta($course_id, '_course_premium_features', true) ?: array();
        
        // Get course structure
        $chapters = $this->get_course_chapters($course_id);
        
        ob_start();
        ?>
        <div class="wp-lms-course-overview">
            <div class="course-content">
                <?php echo $original_content; ?>
            </div>
            
            <div class="course-structure">
                <h3><?php _e('Course Content', 'wp-lms'); ?></h3>
                <?php if (!empty($chapters)): ?>
                    <div class="chapters-list">
                        <?php foreach ($chapters as $chapter): ?>
                            <div class="chapter-item">
                                <h4><?php echo esc_html($chapter->post_title); ?></h4>
                                <?php if (!empty($chapter->lessons)): ?>
                                    <ul class="lessons-list">
                                        <?php foreach ($chapter->lessons as $lesson): ?>
                                            <li class="lesson-item">
                                                <div class="lesson-main-content">
                                                    <div class="lesson-title"><?php echo esc_html($lesson->post_title); ?></div>
                                                    
                                                    <?php 
                                                    // Check if preview is enabled for this lesson
                                                    $preview_enabled = get_post_meta($lesson->ID, '_lesson_preview_enabled', true);
                                                    $video_url = get_post_meta($lesson->ID, '_lesson_video_url', true);
                                                    
                                    if ($preview_enabled && $video_url): ?>
                                        <div class="lesson-preview-action wp-lms-preview-container-unique">
                                            <button class="wp-lms-btn wp-lms-btn-secondary wp-lms-preview-btn wp-lms-preview-btn-unique" 
                                                    data-lesson-id="<?php echo $lesson->ID; ?>"
                                                    data-lesson-title="<?php echo esc_attr($lesson->post_title); ?>"
                                                    data-video-url="<?php echo esc_attr($video_url); ?>"
                                                    id="wp-lms-preview-<?php echo $lesson->ID; ?>">
                                                <?php _e('Vorschau', 'wp-lms'); ?>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="lesson-duration-column">
                                                    <?php 
                                                    $duration = get_post_meta($lesson->ID, '_lesson_duration', true);
                                                    if ($duration): 
                                                        $minutes = floor($duration / 60);
                                                        $seconds = $duration % 60;
                                                        $formatted_duration = sprintf('%d:%02d', $minutes, $seconds);
                                                    ?>
                                                        <span class="lesson-duration"><?php echo $formatted_duration; ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p><?php _e('No content available yet.', 'wp-lms'); ?></p>
                <?php endif; ?>
            </div>
            
            <div class="course-purchase">
                <?php 
                // Debug output
                echo '<!-- DEBUG: Course ID: ' . $course_id . ', User ID: ' . $user_id . ' -->';
                echo $this->stripe->get_purchase_button($course_id, $user_id, false); 
                ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render learning interface
     */
    private function render_learning_interface($course_id, $user_id) {
        $chapters = $this->get_course_chapters($course_id);
        $course_title = get_the_title($course_id);
        
        ob_start();
        ?>
        <div class="wp-lms-learning-interface">
            <div class="learning-header">
                <h1><?php echo esc_html($course_title); ?></h1>
                <div class="progress-bar">
                    <div class="progress-fill" id="course-progress"></div>
                </div>
            </div>
            
            <div class="learning-content">
                <div class="video-panel">
                    <div id="welcome-message" class="welcome-message">
                        <h2><?php _e('Willkommen zum Kurs!', 'wp-lms'); ?></h2>
                        <p><?php _e('Wählen Sie eine Lektion aus dem rechten Panel, um mit dem Lernen zu beginnen.', 'wp-lms'); ?></p>
                    </div>
                    
                    <div id="video-container" style="display: none;">
                        <video id="lesson-video" controls width="100%">
                            <source src="" type="video/mp4">
                            <?php _e('Your browser does not support the video tag.', 'wp-lms'); ?>
                        </video>
                        
                        <div id="code-sections" class="code-sections">
                            <!-- Code section buttons will be populated here -->
                        </div>
                    </div>
                    
                    <!-- Code overlay panel -->
                    <div id="code-overlay" class="code-overlay" style="display: none;">
                        <div class="code-panel">
                            <div class="code-header">
                                <h3 id="code-title"></h3>
                                <button id="close-code-panel" class="close-btn">&times;</button>
                            </div>
                            <div class="code-content">
                                <pre><code id="code-display" class="language-kotlin"></code></pre>
                                <div class="code-actions">
                                    <button id="run-wasm-btn" class="wp-lms-btn wp-lms-btn-primary">
                                        <?php _e('Run Code', 'wp-lms'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- WASM overlay -->
                    <div id="wasm-overlay" class="wasm-overlay" style="display: none;">
                        <div class="wasm-panel">
                            <div class="wasm-header">
                                <h3><?php _e('Code Execution', 'wp-lms'); ?></h3>
                                <button id="close-wasm-panel" class="close-btn">&times;</button>
                            </div>
                            <div class="wasm-content">
                                <iframe id="wasm-frame" src="" width="100%" height="500px"></iframe>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="navigation-panel">
                    <div class="chapters-navigation">
                        <?php if (!empty($chapters)): ?>
                            <?php foreach ($chapters as $chapter): ?>
                                <div class="chapter-nav" data-chapter-id="<?php echo $chapter->ID; ?>">
                                    <div class="chapter-header">
                                        <span class="chapter-title"><?php echo esc_html($chapter->post_title); ?></span>
                                        <span class="chapter-toggle">▼</span>
                                    </div>
                                    
                                    <?php if (!empty($chapter->lessons)): ?>
                                        <div class="lessons-nav" id="lessons-<?php echo $chapter->ID; ?>">
                                            <?php foreach ($chapter->lessons as $lesson): ?>
                                                <div class="lesson-nav" data-lesson-id="<?php echo $lesson->ID; ?>">
                                                    <span class="lesson-title"><?php echo esc_html($lesson->post_title); ?></span>
                                                    <?php 
                                                    $duration = get_post_meta($lesson->ID, '_lesson_duration', true);
                                                    if ($duration): 
                                                        $minutes = floor($duration / 60);
                                                        $seconds = $duration % 60;
                                                        $formatted_duration = sprintf('%d:%02d', $minutes, $seconds);
                                                    ?>
                                                        <span class="lesson-duration"><?php echo $formatted_duration; ?></span>
                                                    <?php endif; ?>
                                                    <span class="lesson-status" id="status-<?php echo $lesson->ID; ?>"></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get lesson data via AJAX
     */
    public function get_lesson_data() {
        check_ajax_referer('wp_lms_nonce', 'nonce');
        
        $lesson_id = intval($_POST['lesson_id']);
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            wp_send_json_error('User not logged in.');
            return;
        }
        
        $lesson = get_post($lesson_id);
        if (!$lesson || $lesson->post_type !== 'lms_lesson') {
            wp_send_json_error('Invalid lesson.');
            return;
        }
        
        // Check if user has access to this lesson's course
        $chapter_id = get_post_meta($lesson_id, '_lesson_chapter_id', true);
        $course_id = get_post_meta($chapter_id, '_chapter_course_id', true);
        
        if (!$this->database->has_user_purchased_course($user_id, $course_id)) {
            wp_send_json_error('Access denied.');
            return;
        }
        
        $video_url = get_post_meta($lesson_id, '_lesson_video_url', true);
        $duration = get_post_meta($lesson_id, '_lesson_duration', true);
        $code_sections = get_post_meta($lesson_id, '_lesson_code_sections', true) ?: array();
        
        wp_send_json_success(array(
            'title' => $lesson->post_title,
            'content' => $lesson->post_content,
            'video_url' => $video_url,
            'duration' => $duration,
            'code_sections' => $code_sections
        ));
    }
    
    /**
     * Update lesson progress via AJAX
     */
    public function update_lesson_progress() {
        check_ajax_referer('wp_lms_nonce', 'nonce');
        
        $lesson_id = intval($_POST['lesson_id']);
        $video_progress = intval($_POST['video_progress']);
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            wp_send_json_error('User not logged in.');
            return;
        }
        
        // Get course ID
        $chapter_id = get_post_meta($lesson_id, '_lesson_chapter_id', true);
        $course_id = get_post_meta($chapter_id, '_chapter_course_id', true);
        
        // Check if lesson is completed (90% watched)
        $duration = get_post_meta($lesson_id, '_lesson_duration', true);
        $completed = false;
        
        if ($duration > 0) {
            // Duration is stored in seconds, video_progress is also in seconds
            $completion_percentage = ($video_progress / $duration);
            $completed = $completion_percentage >= 0.9;
        }
        
        $this->database->update_lesson_progress($user_id, $course_id, $lesson_id, $video_progress, $completed);
        
        wp_send_json_success(array(
            'completed' => $completed,
            'progress' => $video_progress
        ));
    }
    
    /**
     * Get course chapters with lessons
     */
    private function get_course_chapters($course_id) {
        global $wpdb;
        
        // Get chapters for this course
        $chapters = get_posts(array(
            'post_type' => 'lms_chapter',
            'meta_key' => '_chapter_course_id',
            'meta_value' => $course_id,
            'meta_query' => array(
                array(
                    'key' => '_chapter_order',
                    'type' => 'NUMERIC'
                )
            ),
            'orderby' => 'meta_value_num',
            'order' => 'ASC',
            'numberposts' => -1
        ));
        
        // Get lessons for each chapter
        foreach ($chapters as &$chapter) {
            $chapter->lessons = get_posts(array(
                'post_type' => 'lms_lesson',
                'meta_key' => '_lesson_chapter_id',
                'meta_value' => $chapter->ID,
                'meta_query' => array(
                    array(
                        'key' => '_lesson_order',
                        'type' => 'NUMERIC'
                    )
                ),
                'orderby' => 'meta_value_num',
                'order' => 'ASC',
                'numberposts' => -1
            ));
        }
        
        return $chapters;
    }
    
    /**
     * Get course progress via AJAX
     */
    public function get_course_progress() {
        check_ajax_referer('wp_lms_nonce', 'nonce');
        
        $course_id = intval($_POST['course_id']);
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            wp_send_json_error('User not logged in.');
            return;
        }
        
        // Get all lessons in this course with their progress
        $chapters = $this->get_course_chapters($course_id);
        $total_video_time = 0;
        $watched_video_time = 0;
        $completed_lessons = 0;
        $total_lessons = 0;
        
        foreach ($chapters as &$chapter) {
            foreach ($chapter->lessons as &$lesson) {
                $total_lessons++;
                $lesson_duration = get_post_meta($lesson->ID, '_lesson_duration', true);
                $total_video_time += $lesson_duration;
                
                // Get progress for this lesson
                global $wpdb;
                $progress_table = $wpdb->prefix . 'lms_user_progress';
                $progress = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $progress_table WHERE user_id = %d AND lesson_id = %d",
                    $user_id, $lesson->ID
                ));
                
                if ($progress) {
                    $lesson->progress = array(
                        'video_progress' => $progress->video_progress,
                        'completed' => $progress->completed,
                        'last_accessed' => $progress->last_accessed
                    );
                    
                    // Add watched time (but cap at lesson duration)
                    $watched_time = min($progress->video_progress, $lesson_duration);
                    $watched_video_time += $watched_time;
                    
                    if ($progress->completed) {
                        $completed_lessons++;
                    }
                } else {
                    $lesson->progress = null;
                }
            }
        }
        
        // Calculate completion percentage based on watched video time
        $completion_percentage = $total_video_time > 0 ? ($watched_video_time / $total_video_time) * 100 : 0;
        
        wp_send_json_success(array(
            'chapters' => $chapters,
            'completion_percentage' => $completion_percentage,
            'total_lessons' => $total_lessons,
            'completed_lessons' => $completed_lessons,
            'total_video_time' => $total_video_time,
            'watched_video_time' => $watched_video_time
        ));
    }
    
    /**
     * Test connection via AJAX
     */
    public function test_connection() {
        check_ajax_referer('wp_lms_nonce', 'nonce');
        
        wp_send_json_success(array(
            'message' => 'WP LMS AJAX connection working!',
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'debug' => WP_DEBUG
        ));
    }
    
    /**
     * Load custom template for courses
     */
    public function load_course_template($template) {
        if (is_singular('lms_course')) {
            $plugin_template = plugin_dir_path(__FILE__) . '../templates/single-lms_course.php';
            if (file_exists($plugin_template)) {
                return $plugin_template;
            }
        }
        return $template;
    }
}
