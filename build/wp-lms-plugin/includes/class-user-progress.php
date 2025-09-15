<?php
/**
 * User Progress tracking for WP LMS Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_LMS_User_Progress {
    
    private $database;
    
    public function __construct() {
        $this->database = new WP_LMS_Database();
        
        add_action('wp_ajax_get_course_progress', array($this, 'get_course_progress'));
        add_action('wp_ajax_nopriv_get_course_progress', array($this, 'get_course_progress'));
        add_action('wp_ajax_update_lesson_progress', array($this, 'update_lesson_progress_ajax'));
        add_action('wp_ajax_nopriv_update_lesson_progress', array($this, 'update_lesson_progress_ajax'));
        add_action('wp_ajax_mark_lesson_complete', array($this, 'mark_lesson_complete'));
        add_action('wp_ajax_nopriv_mark_lesson_complete', array($this, 'mark_lesson_complete'));
        add_shortcode('lms_user_progress', array($this, 'user_progress_shortcode'));
        add_shortcode('lms_user_courses', array($this, 'user_courses_shortcode'));
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
        
        if (!$this->database->has_user_purchased_course($user_id, $course_id)) {
            wp_send_json_error('Access denied.');
            return;
        }
        
        $progress_data = $this->get_detailed_course_progress($user_id, $course_id);
        
        wp_send_json_success($progress_data);
    }
    
    /**
     * Update lesson progress (video progress only) via AJAX
     */
    public function update_lesson_progress_ajax() {
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
        
        if (!$this->database->has_user_purchased_course($user_id, $course_id)) {
            wp_send_json_error('Access denied.');
            return;
        }
        
        // Update video progress only (completed status is preserved)
        $this->database->update_lesson_progress($user_id, $course_id, $lesson_id, $video_progress, null);
        
        // Check if lesson should be marked as completed based on video progress
        $lesson_duration = get_post_meta($lesson_id, '_lesson_duration', true);
        $completion_threshold = $lesson_duration * 0.9; // 90% threshold
        
        $is_completed = ($video_progress >= $completion_threshold);
        
        wp_send_json_success(array(
            'completed' => $is_completed,
            'video_progress' => $video_progress,
            'lesson_duration' => $lesson_duration
        ));
    }
    
    /**
     * Mark lesson as complete via AJAX
     */
    public function mark_lesson_complete() {
        check_ajax_referer('wp_lms_nonce', 'nonce');
        
        $lesson_id = intval($_POST['lesson_id']);
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            wp_send_json_error('User not logged in.');
            return;
        }
        
        // Get course ID
        $chapter_id = get_post_meta($lesson_id, '_lesson_chapter_id', true);
        $course_id = get_post_meta($chapter_id, '_chapter_course_id', true);
        
        if (!$this->database->has_user_purchased_course($user_id, $course_id)) {
            wp_send_json_error('Access denied.');
            return;
        }
        
        // Mark lesson as complete
        $this->database->update_lesson_progress($user_id, $course_id, $lesson_id, 0, true);
        
        // Get updated progress using the same time-based calculation as get_course_progress
        $progress_data = $this->get_detailed_course_progress($user_id, $course_id);
        
        wp_send_json_success(array(
            'completed' => true,
            'course_completion' => $progress_data['completion_percentage']
        ));
    }
    
    /**
     * Get detailed course progress
     */
    public function get_detailed_course_progress($user_id, $course_id) {
        $chapters = $this->get_course_chapters_with_progress($course_id, $user_id);
        
        $total_lessons = 0;
        $completed_lessons = 0;
        $total_video_time = 0;
        $watched_video_time = 0;
        
        foreach ($chapters as $chapter) {
            foreach ($chapter->lessons as $lesson) {
                $total_lessons++;
                $lesson_duration = get_post_meta($lesson->ID, '_lesson_duration', true);
                $total_video_time += $lesson_duration;
                
                if ($lesson->progress) {
                    if ($lesson->progress->completed) {
                        $completed_lessons++;
                    }
                    // Add watched time (but cap at lesson duration)
                    $watched_time = min($lesson->progress->video_progress, $lesson_duration);
                    $watched_video_time += $watched_time;
                }
            }
        }
        
        // Calculate completion percentage based on watched video time
        $completion_percentage = $total_video_time > 0 ? ($watched_video_time / $total_video_time) * 100 : 0;
        
        return array(
            'chapters' => $chapters,
            'completion_percentage' => $completion_percentage,
            'total_lessons' => $total_lessons,
            'completed_lessons' => $completed_lessons,
            'total_video_time' => $total_video_time,
            'watched_video_time' => $watched_video_time,
            'estimated_time_remaining' => max(0, ($total_video_time - $watched_video_time) / 60) // Convert to minutes
        );
    }
    
    /**
     * Get course chapters with progress data
     */
    private function get_course_chapters_with_progress($course_id, $user_id) {
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
        
        // Get lessons for each chapter with progress
        foreach ($chapters as &$chapter) {
            $lessons = get_posts(array(
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
            
            // Add progress data to each lesson
            foreach ($lessons as &$lesson) {
                $lesson->progress = $this->database->get_user_lesson_progress($user_id, $lesson->ID);
                $lesson->duration = get_post_meta($lesson->ID, '_lesson_duration', true);
                $lesson->video_url = get_post_meta($lesson->ID, '_lesson_video_url', true);
            }
            
            $chapter->lessons = $lessons;
        }
        
        return $chapters;
    }
    
    /**
     * User progress shortcode
     */
    public function user_progress_shortcode($atts) {
        $atts = shortcode_atts(array(
            'course_id' => 0,
            'user_id' => 0
        ), $atts);
        
        $user_id = $atts['user_id'] ?: get_current_user_id();
        $course_id = intval($atts['course_id']);
        
        if (!$user_id) {
            return '<p>' . __('Please log in to view progress.', 'wp-lms') . '</p>';
        }
        
        if (!$course_id) {
            return '<p>' . __('Course ID required.', 'wp-lms') . '</p>';
        }
        
        if (!$this->database->has_user_purchased_course($user_id, $course_id)) {
            return '<p>' . __('You do not have access to this course.', 'wp-lms') . '</p>';
        }
        
        $progress_data = $this->get_detailed_course_progress($user_id, $course_id);
        
        ob_start();
        ?>
        <div class="wp-lms-progress-widget">
            <div class="progress-header">
                <h3><?php _e('Course Progress', 'wp-lms'); ?></h3>
                <div class="progress-percentage"><?php echo round($progress_data['completion_percentage']); ?>%</div>
            </div>
            
            <div class="progress-bar-container">
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $progress_data['completion_percentage']; ?>%"></div>
                </div>
            </div>
            
            <div class="progress-stats">
                <div class="stat">
                    <span class="stat-label"><?php _e('Lessons Completed:', 'wp-lms'); ?></span>
                    <span class="stat-value"><?php echo $progress_data['completed_lessons']; ?>/<?php echo $progress_data['total_lessons']; ?></span>
                </div>
                <div class="stat">
                    <span class="stat-label"><?php _e('Time Watched:', 'wp-lms'); ?></span>
                    <span class="stat-value"><?php echo round($progress_data['watched_duration']); ?> min</span>
                </div>
                <div class="stat">
                    <span class="stat-label"><?php _e('Time Remaining:', 'wp-lms'); ?></span>
                    <span class="stat-value"><?php echo round($progress_data['estimated_time_remaining']); ?> min</span>
                </div>
            </div>
            
            <div class="chapters-progress">
                <?php foreach ($progress_data['chapters'] as $chapter): ?>
                    <div class="chapter-progress">
                        <h4><?php echo esc_html($chapter->post_title); ?></h4>
                        <div class="lessons-progress">
                            <?php foreach ($chapter->lessons as $lesson): ?>
                                <div class="lesson-progress <?php echo ($lesson->progress && $lesson->progress->completed) ? 'completed' : 'incomplete'; ?>">
                                    <span class="lesson-title"><?php echo esc_html($lesson->post_title); ?></span>
                                    <span class="lesson-status">
                                        <?php if ($lesson->progress && $lesson->progress->completed): ?>
                                            <span class="status-icon completed">✓</span>
                                        <?php elseif ($lesson->progress && $lesson->progress->video_progress > 0): ?>
                                            <span class="status-icon in-progress">⏵</span>
                                        <?php else: ?>
                                            <span class="status-icon not-started">○</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * User courses shortcode
     */
    public function user_courses_shortcode($atts) {
        $atts = shortcode_atts(array(
            'user_id' => 0
        ), $atts);
        
        $user_id = $atts['user_id'] ?: get_current_user_id();
        
        if (!$user_id) {
            return '<p>' . __('Please log in to view your courses.', 'wp-lms') . '</p>';
        }
        
        $purchased_courses = $this->database->get_user_purchased_courses($user_id);
        
        if (empty($purchased_courses)) {
            return '<p>' . __('You have not purchased any courses yet.', 'wp-lms') . '</p>';
        }
        
        ob_start();
        ?>
        <div class="wp-lms-user-courses">
            <h3><?php _e('My Courses', 'wp-lms'); ?></h3>
            <div class="courses-grid">
                <?php foreach ($purchased_courses as $purchase): ?>
                    <?php 
                    $course = get_post($purchase->course_id);
                    if (!$course) continue;
                    
                    $completion_percentage = $this->database->get_course_completion_percentage($user_id, $course->ID);
                    $thumbnail = get_the_post_thumbnail($course->ID, 'medium');
                    ?>
                    <div class="course-card">
                        <?php if ($thumbnail): ?>
                            <div class="course-thumbnail"><?php echo $thumbnail; ?></div>
                        <?php endif; ?>
                        
                        <div class="course-info">
                            <h4><?php echo esc_html($course->post_title); ?></h4>
                            <div class="course-progress">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $completion_percentage; ?>%"></div>
                                </div>
                                <span class="progress-text"><?php echo round($completion_percentage); ?>% <?php _e('Complete', 'wp-lms'); ?></span>
                            </div>
                            
                            <div class="course-actions">
                                <a href="<?php echo get_permalink($course->ID); ?>?action=start" class="wp-lms-btn wp-lms-btn-primary">
                                    <?php _e('Continue Learning', 'wp-lms'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get user's learning statistics
     */
    public function get_user_learning_stats($user_id) {
        global $wpdb;
        
        $progress_table = $wpdb->prefix . 'lms_user_progress';
        $purchases_table = $wpdb->prefix . 'lms_course_purchases';
        
        $stats = array();
        
        // Total courses purchased
        $stats['total_courses'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $purchases_table WHERE user_id = %d AND status = 'completed'",
            $user_id
        ));
        
        // Total lessons completed
        $stats['completed_lessons'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $progress_table WHERE user_id = %d AND completed = 1",
            $user_id
        ));
        
        // Total time watched (in minutes)
        $stats['total_time_watched'] = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(video_progress) FROM $progress_table WHERE user_id = %d",
            $user_id
        )) / 60;
        
        // Courses completed (100% progress)
        $purchased_courses = $this->database->get_user_purchased_courses($user_id);
        $completed_courses = 0;
        
        foreach ($purchased_courses as $purchase) {
            $completion = $this->database->get_course_completion_percentage($user_id, $purchase->course_id);
            if ($completion >= 100) {
                $completed_courses++;
            }
        }
        
        $stats['completed_courses'] = $completed_courses;
        
        // Learning streak (days with activity)
        $stats['current_streak'] = $this->calculate_learning_streak($user_id);
        
        return $stats;
    }
    
    /**
     * Calculate user's learning streak
     */
    private function calculate_learning_streak($user_id) {
        global $wpdb;
        
        $progress_table = $wpdb->prefix . 'lms_user_progress';
        
        // Get distinct days with activity in the last 30 days
        $activity_days = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT DATE(last_accessed) as activity_date 
             FROM $progress_table 
             WHERE user_id = %d 
             AND last_accessed >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY activity_date DESC",
            $user_id
        ));
        
        if (empty($activity_days)) {
            return 0;
        }
        
        $streak = 0;
        $current_date = new DateTime();
        
        foreach ($activity_days as $day) {
            $activity_date = new DateTime($day->activity_date);
            $diff = $current_date->diff($activity_date)->days;
            
            if ($diff <= $streak + 1) {
                $streak++;
                $current_date = $activity_date;
            } else {
                break;
            }
        }
        
        return $streak;
    }
    
    /**
     * Generate learning certificate
     */
    public function generate_certificate($user_id, $course_id) {
        if (!$this->database->has_user_purchased_course($user_id, $course_id)) {
            return false;
        }
        
        $completion_percentage = $this->database->get_course_completion_percentage($user_id, $course_id);
        
        if ($completion_percentage < 100) {
            return false;
        }
        
        $user = get_user_by('ID', $user_id);
        $course = get_post($course_id);
        
        // This would generate a PDF certificate
        // For now, we'll return certificate data
        return array(
            'user_name' => $user->display_name,
            'course_title' => $course->post_title,
            'completion_date' => current_time('Y-m-d'),
            'certificate_id' => 'CERT-' . $course_id . '-' . $user_id . '-' . time()
        );
    }
}
