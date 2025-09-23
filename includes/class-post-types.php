<?php
/**
 * Custom Post Types for WP LMS Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_LMS_Post_Types {
    
    public function __construct() {
        add_action('init', array($this, 'register_post_types'), 0);
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_boxes'));
    }
    
    public function register_post_types() {
        // Register Course Post Type
        register_post_type('lms_course', array(
            'labels' => array(
                'name' => __('Courses', 'wp-lms'),
                'singular_name' => __('Course', 'wp-lms'),
                'add_new' => __('Add New Course', 'wp-lms'),
                'add_new_item' => __('Add New Course', 'wp-lms'),
                'edit_item' => __('Edit Course', 'wp-lms'),
                'new_item' => __('New Course', 'wp-lms'),
                'view_item' => __('View Course', 'wp-lms'),
                'search_items' => __('Search Courses', 'wp-lms'),
                'not_found' => __('No courses found', 'wp-lms'),
                'not_found_in_trash' => __('No courses found in trash', 'wp-lms'),
            ),
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'course'),
            'capability_type' => 'post',
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => null,
            'menu_icon' => 'dashicons-book-alt',
            'supports' => array('title', 'editor', 'thumbnail'),
            'show_in_rest' => true,
        ));
        
        // Register Chapter Post Type
        register_post_type('lms_chapter', array(
            'labels' => array(
                'name' => __('Chapters', 'wp-lms'),
                'singular_name' => __('Chapter', 'wp-lms'),
                'add_new' => __('Add New Chapter', 'wp-lms'),
                'add_new_item' => __('Add New Chapter', 'wp-lms'),
                'edit_item' => __('Edit Chapter', 'wp-lms'),
                'new_item' => __('New Chapter', 'wp-lms'),
                'view_item' => __('View Chapter', 'wp-lms'),
                'search_items' => __('Search Chapters', 'wp-lms'),
                'not_found' => __('No chapters found', 'wp-lms'),
                'not_found_in_trash' => __('No chapters found in trash', 'wp-lms'),
            ),
            'public' => true,
            'has_archive' => false,
            'menu_icon' => 'dashicons-list-view',
            'supports' => array('title', 'editor'),
            'rewrite' => array('slug' => 'chapter'),
            'show_in_rest' => true,
        ));
        
        // Register Lesson Post Type
        register_post_type('lms_lesson', array(
            'labels' => array(
                'name' => __('Lessons', 'wp-lms'),
                'singular_name' => __('Lesson', 'wp-lms'),
                'add_new' => __('Add New Lesson', 'wp-lms'),
                'add_new_item' => __('Add New Lesson', 'wp-lms'),
                'edit_item' => __('Edit Lesson', 'wp-lms'),
                'new_item' => __('New Lesson', 'wp-lms'),
                'view_item' => __('View Lesson', 'wp-lms'),
                'search_items' => __('Search Lessons', 'wp-lms'),
                'not_found' => __('No lessons found', 'wp-lms'),
                'not_found_in_trash' => __('No lessons found in trash', 'wp-lms'),
            ),
            'public' => true,
            'has_archive' => false,
            'menu_icon' => 'dashicons-video-alt3',
            'supports' => array('title', 'editor'),
            'rewrite' => array('slug' => 'lesson'),
            'show_in_rest' => true,
        ));
    }
    
    public function add_meta_boxes() {
        // Course meta boxes
        add_meta_box(
            'course_details',
            __('Course Details', 'wp-lms'),
            array($this, 'course_details_callback'),
            'lms_course',
            'normal',
            'high'
        );
        
        // Chapter meta boxes
        add_meta_box(
            'chapter_details',
            __('Chapter Details', 'wp-lms'),
            array($this, 'chapter_details_callback'),
            'lms_chapter',
            'normal',
            'high'
        );
        
        // Lesson meta boxes
        add_meta_box(
            'lesson_details',
            __('Lesson Details', 'wp-lms'),
            array($this, 'lesson_details_callback'),
            'lms_lesson',
            'normal',
            'high'
        );
        
        add_meta_box(
            'lesson_code_sections',
            __('Code Sections', 'wp-lms'),
            array($this, 'lesson_code_sections_callback'),
            'lms_lesson',
            'normal',
            'high'
        );
    }
    
    public function course_details_callback($post) {
        wp_nonce_field('course_details_nonce', 'course_details_nonce');
        
        $price = get_post_meta($post->ID, '_course_price', true);
        $currency = get_post_meta($post->ID, '_course_currency', true) ?: 'EUR';
        $premium_enabled = get_post_meta($post->ID, '_course_premium_enabled', true);
        $premium_price = get_post_meta($post->ID, '_course_premium_price', true);
        $premium_features = get_post_meta($post->ID, '_course_premium_features', true) ?: array();
        $standard_features = get_post_meta($post->ID, '_course_standard_features', true) ?: array();
        
        echo '<table class="form-table">';
        
        // Standard Price
        echo '<tr>';
        echo '<th><label for="course_price">' . __('Standard Price', 'wp-lms') . '</label></th>';
        echo '<td>';
        echo '<input type="number" id="course_price" name="course_price" value="' . esc_attr($price) . '" step="0.01" min="0" />';
        echo '<select name="course_currency">';
        echo '<option value="EUR"' . selected($currency, 'EUR', false) . '>EUR</option>';
        echo '<option value="USD"' . selected($currency, 'USD', false) . '>USD</option>';
        echo '<option value="GBP"' . selected($currency, 'GBP', false) . '>GBP</option>';
        echo '</select>';
        echo '</td>';
        echo '</tr>';
        
        // Standard Features
        echo '<tr>';
        echo '<th><label>' . __('Standard Features', 'wp-lms') . '</label></th>';
        echo '<td>';
        
        $available_features = array(
            'support' => __('Email Support', 'wp-lms'),
            'certificate' => __('Course Certificate', 'wp-lms'),
            'downloads' => __('Downloadable Resources', 'wp-lms'),
            'community' => __('Private Community Access', 'wp-lms'),
            'updates' => __('Lifetime Updates', 'wp-lms')
        );
        
        foreach ($available_features as $feature_key => $feature_label) {
            $checked = in_array($feature_key, $standard_features);
            echo '<label style="display: block; margin-bottom: 5px;">';
            echo '<input type="checkbox" name="course_standard_features[]" value="' . $feature_key . '"' . checked($checked, true, false) . ' />';
            echo ' ' . $feature_label;
            echo '</label>';
        }
        
        echo '<p class="description">' . __('Wählen Sie die Features aus, die in der Standard-Version enthalten sind.', 'wp-lms') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        // Premium Options
        echo '<tr>';
        echo '<th><label for="course_premium_enabled">' . __('Premium Version', 'wp-lms') . '</label></th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" id="course_premium_enabled" name="course_premium_enabled" value="1"' . checked($premium_enabled, '1', false) . ' />';
        echo ' ' . __('Offer Premium version of this course', 'wp-lms');
        echo '</label>';
        echo '<p class="description">' . __('When enabled, customers can choose between Standard and Premium versions.', 'wp-lms') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr id="premium_price_row" style="' . ($premium_enabled ? '' : 'display: none;') . '">';
        echo '<th><label for="course_premium_price">' . __('Premium Price', 'wp-lms') . '</label></th>';
        echo '<td>';
        echo '<input type="number" id="course_premium_price" name="course_premium_price" value="' . esc_attr($premium_price) . '" step="0.01" min="0" />';
        echo '<p class="description">' . __('Price for the premium version (should be higher than standard price).', 'wp-lms') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr id="premium_features_row" style="' . ($premium_enabled ? '' : 'display: none;') . '">';
        echo '<th><label>' . __('Premium Features', 'wp-lms') . '</label></th>';
        echo '<td>';
        
        $available_features = array(
            'support' => __('Email Support', 'wp-lms'),
            'certificate' => __('Course Certificate', 'wp-lms'),
            'downloads' => __('Downloadable Resources', 'wp-lms'),
            'community' => __('Private Community Access', 'wp-lms'),
            'updates' => __('Lifetime Updates', 'wp-lms')
        );
        
        foreach ($available_features as $feature_key => $feature_label) {
            $checked = in_array($feature_key, $premium_features);
            echo '<label style="display: block; margin-bottom: 5px;">';
            echo '<input type="checkbox" name="course_premium_features[]" value="' . $feature_key . '"' . checked($checked, true, false) . ' />';
            echo ' ' . $feature_label;
            echo '</label>';
        }
        
        echo '<p class="description">' . __('Select which premium features are included with the premium version.', 'wp-lms') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        
        // JavaScript functionality moved to assets/js/admin.js
    }
    
    public function chapter_details_callback($post) {
        wp_nonce_field('chapter_details_nonce', 'chapter_details_nonce');
        
        $course_id = get_post_meta($post->ID, '_chapter_course_id', true);
        $order = get_post_meta($post->ID, '_chapter_order', true);
        
        $courses = get_posts(array(
            'post_type' => 'lms_course',
            'numberposts' => -1,
            'post_status' => 'publish'
        ));
        
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th><label for="chapter_course_id">' . __('Course', 'wp-lms') . '</label></th>';
        echo '<td>';
        echo '<select id="chapter_course_id" name="chapter_course_id">';
        echo '<option value="">' . __('Select Course', 'wp-lms') . '</option>';
        foreach ($courses as $course) {
            echo '<option value="' . $course->ID . '"' . selected($course_id, $course->ID, false) . '>' . esc_html($course->post_title) . '</option>';
        }
        echo '</select>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th><label for="chapter_order">' . __('Order', 'wp-lms') . '</label></th>';
        echo '<td><input type="number" id="chapter_order" name="chapter_order" value="' . esc_attr($order) . '" min="1" /></td>';
        echo '</tr>';
        echo '</table>';
    }
    
    public function lesson_details_callback($post) {
        wp_nonce_field('lesson_details_nonce', 'lesson_details_nonce');
        
        $chapter_id = get_post_meta($post->ID, '_lesson_chapter_id', true);
        $duration = get_post_meta($post->ID, '_lesson_duration', true);
        $video_url = get_post_meta($post->ID, '_lesson_video_url', true);
        $order = get_post_meta($post->ID, '_lesson_order', true);
        $preview_enabled = get_post_meta($post->ID, '_lesson_preview_enabled', true);
        
        $chapters = get_posts(array(
            'post_type' => 'lms_chapter',
            'numberposts' => -1,
            'post_status' => 'publish'
        ));
        
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th><label for="lesson_chapter_id">' . __('Chapter', 'wp-lms') . '</label></th>';
        echo '<td>';
        echo '<select id="lesson_chapter_id" name="lesson_chapter_id">';
        echo '<option value="">' . __('Select Chapter', 'wp-lms') . '</option>';
        foreach ($chapters as $chapter) {
            echo '<option value="' . $chapter->ID . '"' . selected($chapter_id, $chapter->ID, false) . '>' . esc_html($chapter->post_title) . '</option>';
        }
        echo '</select>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th><label for="lesson_duration">' . __('Duration (seconds)', 'wp-lms') . '</label></th>';
        echo '<td>';
        echo '<input type="number" id="lesson_duration" name="lesson_duration" value="' . esc_attr($duration) . '" min="1" />';
        echo '<p class="description">' . __('Enter duration in seconds (e.g., 90 for 1:30)', 'wp-lms') . '</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th><label for="lesson_video_url">' . __('Video URL', 'wp-lms') . '</label></th>';
        echo '<td><input type="url" id="lesson_video_url" name="lesson_video_url" value="' . esc_attr($video_url) . '" class="regular-text" /></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th><label for="lesson_preview_enabled">' . __('Preview Available', 'wp-lms') . '</label></th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" id="lesson_preview_enabled" name="lesson_preview_enabled" value="1"' . checked($preview_enabled, '1', false) . ' />';
        echo ' ' . __('Allow users to preview this lesson without purchasing the course', 'wp-lms');
        echo '</label>';
        echo '<p class="description">' . __('When enabled, a "Preview" button will be shown for this lesson in the course overview, allowing potential customers to watch the video before purchasing.', 'wp-lms') . '</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th><label for="lesson_order">' . __('Order', 'wp-lms') . '</label></th>';
        echo '<td><input type="number" id="lesson_order" name="lesson_order" value="' . esc_attr($order) . '" min="1" /></td>';
        echo '</tr>';
        echo '</table>';
    }
    
    public function lesson_code_sections_callback($post) {
        wp_nonce_field('lesson_code_sections_nonce', 'lesson_code_sections_nonce');
        
        $code_sections = get_post_meta($post->ID, '_lesson_code_sections', true) ?: array();
        
        echo '<div id="code-sections-container">';
        
        if (!empty($code_sections)) {
            foreach ($code_sections as $index => $section) {
                $this->render_code_section($index, $section);
            }
        } else {
            $this->render_code_section(0, array());
        }
        
        echo '</div>';
        echo '<button type="button" id="add-code-section" class="button">' . __('Add Code Section', 'wp-lms') . '</button>';
        
        // JavaScript functionality moved to assets/js/admin.js
        // Pass data to JavaScript via data attributes
        echo '<div id="code-sections-data" data-section-count="' . (count($code_sections) ?: 1) . '" data-template="' . esc_attr(json_encode($this->get_code_section_template())) . '" style="display: none;"></div>';
    }
    
    private function render_code_section($index, $section) {
        $title = isset($section['title']) ? $section['title'] : '';
        $code = isset($section['code']) ? $section['code'] : '';
        $language = isset($section['language']) ? $section['language'] : 'kotlin';
        $wasm_url = isset($section['wasm_url']) ? $section['wasm_url'] : '';
        
        echo '<div class="code-section" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px;">';
        echo '<h4>' . __('Code Section', 'wp-lms') . ' ' . ($index + 1) . '</h4>';
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th><label>' . __('Title', 'wp-lms') . '</label></th>';
        echo '<td><input type="text" name="code_sections[' . $index . '][title]" value="' . esc_attr($title) . '" class="regular-text" /></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th><label>' . __('Language', 'wp-lms') . '</label></th>';
        echo '<td>';
        echo '<select name="code_sections[' . $index . '][language]">';
        echo '<option value="kotlin"' . selected($language, 'kotlin', false) . '>Kotlin</option>';
        echo '<option value="java"' . selected($language, 'java', false) . '>Java</option>';
        echo '<option value="javascript"' . selected($language, 'javascript', false) . '>JavaScript</option>';
        echo '<option value="python"' . selected($language, 'python', false) . '>Python</option>';
        echo '</select>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th><label>' . __('Code', 'wp-lms') . '</label></th>';
        echo '<td><textarea name="code_sections[' . $index . '][code]" rows="10" class="large-text">' . esc_textarea($code) . '</textarea></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th><label>' . __('WASM URL', 'wp-lms') . '</label></th>';
        echo '<td><input type="url" name="code_sections[' . $index . '][wasm_url]" value="' . esc_attr($wasm_url) . '" class="regular-text" /></td>';
        echo '</tr>';
        echo '</table>';
        echo '<button type="button" class="button remove-code-section">' . __('Remove Section', 'wp-lms') . '</button>';
        echo '</div>';
    }
    
    private function get_code_section_template() {
        return '<div class="code-section" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px;">
            <h4>' . __('Code Section', 'wp-lms') . ' [INDEX]</h4>
            <table class="form-table">
                <tr>
                    <th><label>' . __('Title', 'wp-lms') . '</label></th>
                    <td><input type="text" name="code_sections[[INDEX]][title]" value="" class="regular-text" /></td>
                </tr>
                <tr>
                    <th><label>' . __('Language', 'wp-lms') . '</label></th>
                    <td>
                        <select name="code_sections[[INDEX]][language]">
                            <option value="kotlin">Kotlin</option>
                            <option value="java">Java</option>
                            <option value="javascript">JavaScript</option>
                            <option value="python">Python</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label>' . __('Code', 'wp-lms') . '</label></th>
                    <td><textarea name="code_sections[[INDEX]][code]" rows="10" class="large-text"></textarea></td>
                </tr>
                <tr>
                    <th><label>' . __('WASM URL', 'wp-lms') . '</label></th>
                    <td><input type="url" name="code_sections[[INDEX]][wasm_url]" value="" class="regular-text" /></td>
                </tr>
            </table>
            <button type="button" class="button remove-code-section">' . __('Remove Section', 'wp-lms') . '</button>
        </div>';
    }
    
    public function save_meta_boxes($post_id) {
        // Check if user has permission to edit
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Save course details
        if (isset($_POST['course_details_nonce']) && wp_verify_nonce($_POST['course_details_nonce'], 'course_details_nonce')) {
            if (isset($_POST['course_price'])) {
                update_post_meta($post_id, '_course_price', sanitize_text_field($_POST['course_price']));
            }
            if (isset($_POST['course_currency'])) {
                update_post_meta($post_id, '_course_currency', sanitize_text_field($_POST['course_currency']));
            }
            
            // Save standard features
            if (isset($_POST['course_standard_features']) && is_array($_POST['course_standard_features'])) {
                $standard_features = array_map('sanitize_text_field', $_POST['course_standard_features']);
                update_post_meta($post_id, '_course_standard_features', $standard_features);
            } else {
                delete_post_meta($post_id, '_course_standard_features');
            }
            
            // Save premium settings
            if (isset($_POST['course_premium_enabled'])) {
                update_post_meta($post_id, '_course_premium_enabled', '1');
            } else {
                delete_post_meta($post_id, '_course_premium_enabled');
            }
            
            if (isset($_POST['course_premium_price'])) {
                update_post_meta($post_id, '_course_premium_price', sanitize_text_field($_POST['course_premium_price']));
            }
            
            if (isset($_POST['course_premium_features']) && is_array($_POST['course_premium_features'])) {
                $premium_features = array_map('sanitize_text_field', $_POST['course_premium_features']);
                update_post_meta($post_id, '_course_premium_features', $premium_features);
            } else {
                delete_post_meta($post_id, '_course_premium_features');
            }
        }
        
        // Save chapter details
        if (isset($_POST['chapter_details_nonce']) && wp_verify_nonce($_POST['chapter_details_nonce'], 'chapter_details_nonce')) {
            if (isset($_POST['chapter_course_id'])) {
                update_post_meta($post_id, '_chapter_course_id', intval($_POST['chapter_course_id']));
            }
            if (isset($_POST['chapter_order'])) {
                update_post_meta($post_id, '_chapter_order', intval($_POST['chapter_order']));
            }
        }
        
        // Save lesson details
        if (isset($_POST['lesson_details_nonce']) && wp_verify_nonce($_POST['lesson_details_nonce'], 'lesson_details_nonce')) {
            if (isset($_POST['lesson_chapter_id'])) {
                update_post_meta($post_id, '_lesson_chapter_id', intval($_POST['lesson_chapter_id']));
            }
            if (isset($_POST['lesson_duration'])) {
                update_post_meta($post_id, '_lesson_duration', intval($_POST['lesson_duration']));
            }
            if (isset($_POST['lesson_video_url'])) {
                update_post_meta($post_id, '_lesson_video_url', esc_url_raw($_POST['lesson_video_url']));
            }
            if (isset($_POST['lesson_preview_enabled'])) {
                update_post_meta($post_id, '_lesson_preview_enabled', '1');
            } else {
                delete_post_meta($post_id, '_lesson_preview_enabled');
            }
            if (isset($_POST['lesson_order'])) {
                update_post_meta($post_id, '_lesson_order', intval($_POST['lesson_order']));
            }
        }
        
        // Save lesson code sections
        if (isset($_POST['lesson_code_sections_nonce']) && wp_verify_nonce($_POST['lesson_code_sections_nonce'], 'lesson_code_sections_nonce')) {
            if (isset($_POST['code_sections']) && is_array($_POST['code_sections'])) {
                $code_sections = array();
                foreach ($_POST['code_sections'] as $section) {
                    // Only save sections that have content (title or code)
                    if (!empty($section['title']) || !empty($section['code'])) {
                        $code_sections[] = array(
                            'title' => sanitize_text_field($section['title']),
                            'language' => sanitize_text_field($section['language']),
                            'code' => sanitize_textarea_field($section['code']), // Use sanitize_textarea_field instead of wp_kses_post
                            'wasm_url' => esc_url_raw($section['wasm_url'])
                        );
                    }
                }
                update_post_meta($post_id, '_lesson_code_sections', $code_sections);
            } else {
                // If no code sections are submitted, clear the meta
                delete_post_meta($post_id, '_lesson_code_sections');
            }
        }
    }
}
