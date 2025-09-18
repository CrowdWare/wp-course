<?php
/**
 * Plugin Name: WP LMS Plugin
 * Plugin URI: https://example.com/wp-lms-plugin
 * Description: A comprehensive Learning Management System for WordPress with video lessons, code sections, and WASM integration.
 * Version: 1.0.113
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: wp-lms
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_LMS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_LMS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WP_LMS_VERSION', '1.0.113');

// Main plugin class
class WP_LMS_Plugin {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Load text domain for translations
        load_plugin_textdomain('wp-lms', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Register post types directly
        $this->register_post_types();
        
        // Include required files
        $this->include_files();
        
        // Initialize components
        new WP_LMS_Post_Types();
        $database = new WP_LMS_Database();
        
        // Force database table repair on every load (temporary fix)
        $database->create_tables();
        
        new WP_LMS_Stripe_Integration();
        new WP_LMS_Frontend();
        new WP_LMS_Admin();
        new WP_LMS_User_Progress();
    }
    
    private function include_files() {
        require_once WP_LMS_PLUGIN_PATH . 'includes/class-post-types.php';
        require_once WP_LMS_PLUGIN_PATH . 'includes/class-database.php';
        require_once WP_LMS_PLUGIN_PATH . 'includes/class-stripe-integration.php';
        require_once WP_LMS_PLUGIN_PATH . 'includes/class-frontend.php';
        require_once WP_LMS_PLUGIN_PATH . 'includes/class-admin.php';
        require_once WP_LMS_PLUGIN_PATH . 'includes/class-user-progress.php';
    }
    
    public function enqueue_scripts() {
        // Always enqueue on frontend for debugging
        wp_enqueue_script('wp-lms-frontend', WP_LMS_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), WP_LMS_VERSION, true);
        wp_enqueue_style('wp-lms-frontend', WP_LMS_PLUGIN_URL . 'assets/css/frontend.css', array(), WP_LMS_VERSION);
        
        // Enqueue Prism.js for syntax highlighting
        wp_enqueue_script('prism-js', WP_LMS_PLUGIN_URL . 'assets/js/prism.js', array(), WP_LMS_VERSION, true);
        wp_enqueue_style('prism-css', WP_LMS_PLUGIN_URL . 'assets/css/prism.css', array(), WP_LMS_VERSION);
        
        // Always localize script for AJAX - available on all pages for debugging
        wp_localize_script('wp-lms-frontend', 'wp_lms_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_lms_nonce'),
            'stripe_publishable_key' => get_option('wp_lms_stripe_publishable_key', ''),
            'debug' => WP_DEBUG,
            'current_page' => get_post_type(),
            'is_course_page' => is_singular('lms_course')
        ));
        
    }
    
    public function admin_enqueue_scripts() {
        wp_enqueue_script('wp-lms-admin', WP_LMS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), WP_LMS_VERSION, true);
        wp_enqueue_style('wp-lms-admin', WP_LMS_PLUGIN_URL . 'assets/css/admin.css', array(), WP_LMS_VERSION);
    }
    
    public function activate() {
        // Include required files first
        $this->include_files();
        
        // Register post types first
        $post_types = new WP_LMS_Post_Types();
        $post_types->register_post_types();
        
        // Create database tables
        $database = new WP_LMS_Database();
        $database->create_tables();
        
        // Flush rewrite rules to register new post types
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    private function register_post_types() {
        // Register Course Post Type
        register_post_type('lms_course', array(
            'labels' => array(
                'name' => 'Courses',
                'singular_name' => 'Course',
                'add_new' => 'Add New Course',
                'add_new_item' => 'Add New Course',
                'edit_item' => 'Edit Course',
                'new_item' => 'New Course',
                'view_item' => 'View Course',
                'search_items' => 'Search Courses',
                'not_found' => 'No courses found',
                'not_found_in_trash' => 'No courses found in trash',
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
                'name' => 'Chapters',
                'singular_name' => 'Chapter',
                'add_new' => 'Add New Chapter',
                'add_new_item' => 'Add New Chapter',
                'edit_item' => 'Edit Chapter',
                'new_item' => 'New Chapter',
                'view_item' => 'View Chapter',
                'search_items' => 'Search Chapters',
                'not_found' => 'No chapters found',
                'not_found_in_trash' => 'No chapters found in trash',
            ),
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'chapter'),
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => false,
            'menu_position' => null,
            'menu_icon' => 'dashicons-list-view',
            'supports' => array('title', 'editor'),
            'show_in_rest' => true,
        ));
        
        // Register Lesson Post Type
        register_post_type('lms_lesson', array(
            'labels' => array(
                'name' => 'Lessons',
                'singular_name' => 'Lesson',
                'add_new' => 'Add New Lesson',
                'add_new_item' => 'Add New Lesson',
                'edit_item' => 'Edit Lesson',
                'new_item' => 'New Lesson',
                'view_item' => 'View Lesson',
                'search_items' => 'Search Lessons',
                'not_found' => 'No lessons found',
                'not_found_in_trash' => 'No lessons found in trash',
            ),
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'lesson'),
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => false,
            'menu_position' => null,
            'menu_icon' => 'dashicons-video-alt3',
            'supports' => array('title', 'editor'),
            'show_in_rest' => true,
        ));
    }
}

// Initialize the plugin
new WP_LMS_Plugin();
