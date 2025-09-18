<?php
/**
 * Database handler for WP LMS Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_LMS_Database {
    
    public function __construct() {
        // Database operations are handled through methods called by the main plugin
    }
    
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Table for course purchases
        $table_purchases = $wpdb->prefix . 'lms_course_purchases';
        $sql_purchases = "CREATE TABLE $table_purchases (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            course_id bigint(20) NOT NULL,
            stripe_payment_intent_id varchar(255) NOT NULL,
            amount decimal(10,2) NOT NULL,
            currency varchar(3) NOT NULL DEFAULT 'EUR',
            status varchar(20) NOT NULL DEFAULT 'pending',
            is_premium tinyint(1) NOT NULL DEFAULT 0,
            customer_email varchar(255) DEFAULT NULL,
            purchased_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY course_id (course_id),
            KEY stripe_payment_intent_id (stripe_payment_intent_id),
            KEY customer_email (customer_email),
            KEY is_premium (is_premium)
        ) $charset_collate;";
        
        // Table for user progress
        $table_progress = $wpdb->prefix . 'lms_user_progress';
        $sql_progress = "CREATE TABLE $table_progress (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            course_id bigint(20) NOT NULL,
            lesson_id bigint(20) NOT NULL,
            completed tinyint(1) NOT NULL DEFAULT 0,
            video_progress int(11) NOT NULL DEFAULT 0,
            last_accessed datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_user_lesson (user_id, lesson_id),
            KEY user_id (user_id),
            KEY course_id (course_id),
            KEY lesson_id (lesson_id)
        ) $charset_collate;";
        
        // Table for WASM files
        $table_wasm = $wpdb->prefix . 'lms_wasm_files';
        $sql_wasm = "CREATE TABLE $table_wasm (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            lesson_id bigint(20) NOT NULL,
            code_section_index int(11) NOT NULL,
            filename varchar(255) NOT NULL,
            file_path varchar(500) NOT NULL,
            file_url varchar(500) NOT NULL,
            file_size bigint(20) NOT NULL,
            uploaded_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY lesson_id (lesson_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_purchases);
        dbDelta($sql_progress);
        dbDelta($sql_wasm);
        
        // Fix existing table constraints
        $this->fix_purchase_table_constraints();
    }
    
    /**
     * Fix purchase table constraints
     */
    private function fix_purchase_table_constraints() {
        global $wpdb;
        
        $table_purchases = $wpdb->prefix . 'lms_course_purchases';
        
        // Check if the unique constraint exists and remove it
        $constraints = $wpdb->get_results("SHOW INDEX FROM $table_purchases WHERE Key_name = 'unique_user_course'");
        
        if (!empty($constraints)) {
            $wpdb->query("ALTER TABLE $table_purchases DROP INDEX unique_user_course");
            error_log("WP LMS: Removed unique_user_course constraint from purchases table");
        }
    }
    
    /**
     * Check if user has purchased a course
     */
    public function has_user_purchased_course($user_id, $course_id) {
        global $wpdb;
        
        $table_purchases = $wpdb->prefix . 'lms_course_purchases';
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_purchases 
             WHERE user_id = %d AND course_id = %d AND status = 'completed'",
            $user_id,
            $course_id
        ));
        
        return $result > 0;
    }
    
    /**
     * Record course purchase
     */
    public function record_course_purchase($user_id, $course_id, $stripe_payment_intent_id, $amount, $currency = 'EUR', $is_premium = false, $customer_email = null) {
        global $wpdb;
        
        $table_purchases = $wpdb->prefix . 'lms_course_purchases';
        
        return $wpdb->insert(
            $table_purchases,
            array(
                'user_id' => $user_id,
                'course_id' => $course_id,
                'stripe_payment_intent_id' => $stripe_payment_intent_id,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'pending',
                'is_premium' => $is_premium ? 1 : 0,
                'customer_email' => $customer_email
            ),
            array('%d', '%d', '%s', '%f', '%s', '%s', '%d', '%s')
        );
    }
    
    /**
     * Update purchase status
     */
    public function update_purchase_status($stripe_payment_intent_id, $status) {
        global $wpdb;
        
        $table_purchases = $wpdb->prefix . 'lms_course_purchases';
        
        // First check if the record exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_purchases WHERE stripe_payment_intent_id = %s",
            $stripe_payment_intent_id
        ));
        
        if (!$existing) {
            error_log("WP LMS: No purchase record found for payment intent: " . $stripe_payment_intent_id);
            return false;
        }
        
        $result = $wpdb->update(
            $table_purchases,
            array('status' => $status),
            array('stripe_payment_intent_id' => $stripe_payment_intent_id),
            array('%s'),
            array('%s')
        );
        
        if ($result === false) {
            error_log("WP LMS: Failed to update purchase status. Error: " . $wpdb->last_error);
        } else {
            error_log("WP LMS: Successfully updated purchase status to '$status' for payment intent: " . $stripe_payment_intent_id);
        }
        
        return $result;
    }
    
    /**
     * Get user's purchased courses
     */
    public function get_user_purchased_courses($user_id) {
        global $wpdb;
        
        $table_purchases = $wpdb->prefix . 'lms_course_purchases';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT course_id, purchased_at FROM $table_purchases 
             WHERE user_id = %d AND status = 'completed'
             ORDER BY purchased_at DESC",
            $user_id
        ));
    }
    
    /**
     * Get user progress for a lesson
     */
    public function get_user_lesson_progress($user_id, $lesson_id) {
        global $wpdb;
        
        $table_progress = $wpdb->prefix . 'lms_user_progress';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_progress 
             WHERE user_id = %d AND lesson_id = %d",
            $user_id,
            $lesson_id
        ));
    }
    
    /**
     * Update user lesson progress
     */
    public function update_lesson_progress($user_id, $course_id, $lesson_id, $video_progress = 0, $completed = null) {
        global $wpdb;
        
        $table_progress = $wpdb->prefix . 'lms_user_progress';
        
        $existing = $this->get_user_lesson_progress($user_id, $lesson_id);
        
        $data = array(
            'user_id' => $user_id,
            'course_id' => $course_id,
            'lesson_id' => $lesson_id,
            'video_progress' => $video_progress
        );
        
        // Only update completed status if explicitly provided
        if ($completed !== null) {
            $data['completed'] = $completed ? 1 : 0;
            
            // Set completion timestamp when marking as complete
            if ($completed) {
                $data['completed_at'] = current_time('mysql');
            }
        } else if ($existing && $existing->completed) {
            // Preserve existing completed status if not explicitly changing it
            $data['completed'] = 1;
            // Keep existing completion timestamp
            if ($existing->completed_at) {
                $data['completed_at'] = $existing->completed_at;
            }
        } else {
            // Default to not completed for new records
            $data['completed'] = 0;
        }
        
        if ($existing) {
            // For updates, only include fields that should be updated
            $update_data = array(
                'video_progress' => $video_progress
            );
            
            // Only update completed status if explicitly provided or preserving existing
            if ($completed !== null) {
                $update_data['completed'] = $completed ? 1 : 0;
                if ($completed) {
                    $update_data['completed_at'] = current_time('mysql');
                }
            }
            // If completed is null and existing is completed, preserve it (don't update)
            
            return $wpdb->update(
                $table_progress,
                $update_data,
                array('user_id' => $user_id, 'lesson_id' => $lesson_id),
                array('%d', '%d', '%s'),
                array('%d', '%d')
            );
        } else {
            // For new records, include all data
            return $wpdb->insert(
                $table_progress,
                $data,
                array('%d', '%d', '%d', '%d', '%d', '%s')
            );
        }
    }
    
    /**
     * Get user progress for entire course
     */
    public function get_user_course_progress($user_id, $course_id) {
        global $wpdb;
        
        $table_progress = $wpdb->prefix . 'lms_user_progress';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_progress 
             WHERE user_id = %d AND course_id = %d
             ORDER BY last_accessed DESC",
            $user_id,
            $course_id
        ));
    }
    
    /**
     * Get course completion percentage
     */
    public function get_course_completion_percentage($user_id, $course_id) {
        global $wpdb;
        
        // Get all lessons for the course
        $lessons = $wpdb->get_results($wpdb->prepare(
            "SELECT l.ID 
             FROM {$wpdb->posts} l
             INNER JOIN {$wpdb->posts} c ON c.ID = (
                 SELECT meta_value FROM {$wpdb->postmeta} 
                 WHERE post_id = (
                     SELECT meta_value FROM {$wpdb->postmeta} 
                     WHERE post_id = l.ID AND meta_key = '_lesson_chapter_id'
                 ) AND meta_key = '_chapter_course_id'
             )
             WHERE l.post_type = 'lms_lesson' 
             AND l.post_status = 'publish'
             AND c.ID = %d",
            $course_id
        ));
        
        if (empty($lessons)) {
            return 0;
        }
        
        $total_lessons = count($lessons);
        $lesson_ids = array_map(function($lesson) { return $lesson->ID; }, $lessons);
        
        $table_progress = $wpdb->prefix . 'lms_user_progress';
        $placeholders = implode(',', array_fill(0, count($lesson_ids), '%d'));
        
        $completed_lessons = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_progress 
             WHERE user_id = %d AND lesson_id IN ($placeholders) AND completed = 1",
            array_merge(array($user_id), $lesson_ids)
        ));
        
        return ($completed_lessons / $total_lessons) * 100;
    }
    
    /**
     * Store WASM file information
     */
    public function store_wasm_file($lesson_id, $code_section_index, $filename, $file_path, $file_url, $file_size) {
        global $wpdb;
        
        $table_wasm = $wpdb->prefix . 'lms_wasm_files';
        
        return $wpdb->insert(
            $table_wasm,
            array(
                'lesson_id' => $lesson_id,
                'code_section_index' => $code_section_index,
                'filename' => $filename,
                'file_path' => $file_path,
                'file_url' => $file_url,
                'file_size' => $file_size
            ),
            array('%d', '%d', '%s', '%s', '%s', '%d')
        );
    }
    
    /**
     * Get WASM file for lesson and code section
     */
    public function get_wasm_file($lesson_id, $code_section_index) {
        global $wpdb;
        
        $table_wasm = $wpdb->prefix . 'lms_wasm_files';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_wasm 
             WHERE lesson_id = %d AND code_section_index = %d
             ORDER BY uploaded_at DESC
             LIMIT 1",
            $lesson_id,
            $code_section_index
        ));
    }
    
    /**
     * Delete WASM file record
     */
    public function delete_wasm_file($id) {
        global $wpdb;
        
        $table_wasm = $wpdb->prefix . 'lms_wasm_files';
        
        return $wpdb->delete(
            $table_wasm,
            array('id' => $id),
            array('%d')
        );
    }
    
    /**
     * Get all WASM files for a lesson
     */
    public function get_lesson_wasm_files($lesson_id) {
        global $wpdb;
        
        $table_wasm = $wpdb->prefix . 'lms_wasm_files';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_wasm 
             WHERE lesson_id = %d
             ORDER BY code_section_index ASC, uploaded_at DESC",
            $lesson_id
        ));
    }
    
    /**
     * Get WASM file by ID
     */
    public function get_wasm_file_by_id($id) {
        global $wpdb;
        
        $table_wasm = $wpdb->prefix . 'lms_wasm_files';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_wasm WHERE id = %d",
            $id
        ));
    }
    
    /**
     * Grant free access to a course for a user
     */
    public function grant_free_course_access($user_id, $course_id) {
        global $wpdb;
        
        $table_purchases = $wpdb->prefix . 'lms_course_purchases';
        
        // Check if user already has access
        if ($this->has_user_purchased_course($user_id, $course_id)) {
            return true;
        }
        
        return $wpdb->insert(
            $table_purchases,
            array(
                'user_id' => $user_id,
                'course_id' => $course_id,
                'stripe_payment_intent_id' => 'free_access_' . time(),
                'amount' => 0.00,
                'currency' => 'EUR',
                'status' => 'completed'
            ),
            array('%d', '%d', '%s', '%f', '%s', '%s')
        );
    }
    
    /**
     * Remove course access for a user
     */
    public function remove_course_access($user_id, $course_id) {
        global $wpdb;
        
        $table_purchases = $wpdb->prefix . 'lms_course_purchases';
        
        return $wpdb->delete(
            $table_purchases,
            array(
                'user_id' => $user_id,
                'course_id' => $course_id
            ),
            array('%d', '%d')
        );
    }
    
    /**
     * Check if user has premium access to a course
     */
    public function has_user_premium_access($user_id, $course_id) {
        global $wpdb;
        
        $table_purchases = $wpdb->prefix . 'lms_course_purchases';
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_purchases 
             WHERE user_id = %d AND course_id = %d AND status = 'completed' AND is_premium = 1",
            $user_id,
            $course_id
        ));
        
        return $result > 0;
    }
    
    /**
     * Get all course purchases with filters
     */
    public function get_course_purchases($filters = array()) {
        global $wpdb;
        
        $table_purchases = $wpdb->prefix . 'lms_course_purchases';
        
        $where_conditions = array("status = 'completed'");
        $where_values = array();
        
        // Email filter - search in both customer_email and user_email
        if (!empty($filters['email'])) {
            $where_conditions[] = "(customer_email LIKE %s OR u.user_email LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($filters['email']) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }
        
        // Premium filter
        if (isset($filters['is_premium'])) {
            $where_conditions[] = "is_premium = %d";
            $where_values[] = $filters['is_premium'] ? 1 : 0;
        }
        
        // Course filter
        if (!empty($filters['course_id'])) {
            $where_conditions[] = "course_id = %d";
            $where_values[] = $filters['course_id'];
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $query = "SELECT p.*, u.display_name, u.user_email 
                  FROM $table_purchases p 
                  LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID 
                  WHERE $where_clause 
                  ORDER BY p.purchased_at DESC";
        
        if (!empty($where_values)) {
            return $wpdb->get_results($wpdb->prepare($query, $where_values));
        } else {
            return $wpdb->get_results($query);
        }
    }
    
    /**
     * Get purchase statistics
     */
    public function get_purchase_statistics() {
        global $wpdb;
        
        $table_purchases = $wpdb->prefix . 'lms_course_purchases';
        
        $stats = array();
        
        // Total purchases
        $stats['total_purchases'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_purchases WHERE status = 'completed'"
        );
        
        // Premium purchases
        $stats['premium_purchases'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_purchases WHERE status = 'completed' AND is_premium = 1"
        );
        
        // Standard purchases
        $stats['standard_purchases'] = $stats['total_purchases'] - $stats['premium_purchases'];
        
        // Total revenue
        $stats['total_revenue'] = $wpdb->get_var(
            "SELECT SUM(amount) FROM $table_purchases WHERE status = 'completed'"
        ) ?: 0;
        
        // Premium revenue
        $stats['premium_revenue'] = $wpdb->get_var(
            "SELECT SUM(amount) FROM $table_purchases WHERE status = 'completed' AND is_premium = 1"
        ) ?: 0;
        
        // Standard revenue
        $stats['standard_revenue'] = $stats['total_revenue'] - $stats['premium_revenue'];
        
        return $stats;
    }
    
    /**
     * Get user's purchase details for a course
     */
    public function get_user_course_purchase($user_id, $course_id) {
        global $wpdb;
        
        $table_purchases = $wpdb->prefix . 'lms_course_purchases';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_purchases 
             WHERE user_id = %d AND course_id = %d AND status = 'completed'
             ORDER BY purchased_at DESC
             LIMIT 1",
            $user_id,
            $course_id
        ));
    }
}
