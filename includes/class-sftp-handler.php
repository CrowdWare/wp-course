<?php
/**
 * SFTP Handler for WP LMS Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_LMS_SFTP_Handler {
    
    private $database;
    private $sftp_connection;
    
    public function __construct() {
        $this->database = new WP_LMS_Database();
        
        add_action('wp_ajax_upload_wasm_file', array($this, 'upload_wasm_file'));
        add_action('wp_ajax_test_sftp_connection', array($this, 'test_sftp_connection'));
        add_action('wp_ajax_delete_wasm_file', array($this, 'delete_wasm_file'));
        add_action('add_meta_boxes', array($this, 'add_wasm_upload_meta_box'));
    }
    
    /**
     * Add WASM upload meta box to lesson edit page
     */
    public function add_wasm_upload_meta_box() {
        add_meta_box(
            'wasm_file_upload',
            __('WASM File Upload', 'wp-lms'),
            array($this, 'wasm_upload_meta_box_callback'),
            'lms_lesson',
            'normal',
            'high'
        );
    }
    
    /**
     * WASM upload meta box callback
     */
    public function wasm_upload_meta_box_callback($post) {
        $code_sections = get_post_meta($post->ID, '_lesson_code_sections', true) ?: array();
        $wasm_files = $this->database->get_lesson_wasm_files($post->ID);
        
        // Group WASM files by code section index
        $wasm_by_section = array();
        foreach ($wasm_files as $wasm) {
            $wasm_by_section[$wasm->code_section_index] = $wasm;
        }
        
        ?>
        <div id="wasm-upload-container">
            <?php if (!empty($code_sections)): ?>
                <?php foreach ($code_sections as $index => $section): ?>
                    <div class="wasm-section" data-section-index="<?php echo $index; ?>">
                        <h4><?php echo esc_html($section['title'] ?: 'Code Section ' . ($index + 1)); ?></h4>
                        
                        <?php if (isset($wasm_by_section[$index])): ?>
                            <div class="current-wasm-file">
                                <p><strong><?php _e('Current WASM File:', 'wp-lms'); ?></strong></p>
                                <p>
                                    <span class="filename"><?php echo esc_html($wasm_by_section[$index]->filename); ?></span>
                                    <span class="filesize">(<?php echo $this->format_file_size($wasm_by_section[$index]->file_size); ?>)</span>
                                </p>
                                <p>
                                    <a href="<?php echo esc_url($wasm_by_section[$index]->file_url); ?>" target="_blank" class="button">
                                        <?php _e('View File', 'wp-lms'); ?>
                                    </a>
                                    <button type="button" class="button button-secondary delete-wasm-btn" 
                                            data-wasm-id="<?php echo $wasm_by_section[$index]->id; ?>">
                                        <?php _e('Delete', 'wp-lms'); ?>
                                    </button>
                                </p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="wasm-upload-form">
                            <p>
                                <input type="file" 
                                       class="wasm-file-input" 
                                       data-section-index="<?php echo $index; ?>" 
                                       accept=".wasm,.html" />
                                <button type="button" 
                                        class="button button-primary upload-wasm-btn" 
                                        data-section-index="<?php echo $index; ?>"
                                        data-lesson-id="<?php echo $post->ID; ?>">
                                    <?php _e('Upload WASM File', 'wp-lms'); ?>
                                </button>
                            </p>
                            <p class="description">
                                <?php _e('Upload a WASM file or HTML file containing the compiled Kotlin Compose application.', 'wp-lms'); ?>
                            </p>
                        </div>
                        
                        <div class="upload-progress" style="display: none;">
                            <div class="progress-bar">
                                <div class="progress-fill"></div>
                            </div>
                            <span class="progress-text">0%</span>
                        </div>
                        
                        <div class="upload-result"></div>
                    </div>
                    <hr>
                <?php endforeach; ?>
            <?php else: ?>
                <p><?php _e('No code sections found. Please add code sections first.', 'wp-lms'); ?></p>
            <?php endif; ?>
        </div>
        
        <?php
        // JavaScript functionality moved to assets/js/admin.js
        // Pass nonces via data attributes
        echo '<div id="wasm-upload-data" 
                   data-upload-nonce="' . wp_create_nonce('upload_wasm_file') . '" 
                   data-delete-nonce="' . wp_create_nonce('delete_wasm_file') . '" 
                   data-upload-text="' . esc_attr(__('Please select a file to upload.', 'wp-lms')) . '"
                   data-delete-confirm="' . esc_attr(__('Are you sure you want to delete this WASM file?', 'wp-lms')) . '"
                   data-upload-failed="' . esc_attr(__('Upload failed. Please try again.', 'wp-lms')) . '"
                   style="display: none;"></div>';
    }
    
    /**
     * Upload WASM file via AJAX
     */
    public function upload_wasm_file() {
        check_ajax_referer('upload_wasm_file', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        $lesson_id = intval($_POST['lesson_id']);
        $section_index = intval($_POST['section_index']);
        
        if (!isset($_FILES['wasm_file']) || $_FILES['wasm_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('File upload error.');
            return;
        }
        
        $file = $_FILES['wasm_file'];
        $allowed_types = array('application/wasm', 'text/html', 'application/octet-stream');
        $allowed_extensions = array('wasm', 'html');
        
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_extensions)) {
            wp_send_json_error('Invalid file type. Only WASM and HTML files are allowed.');
            return;
        }
        
        // Check file size (max 50MB)
        if ($file['size'] > 50 * 1024 * 1024) {
            wp_send_json_error('File too large. Maximum size is 50MB.');
            return;
        }
        
        try {
            $upload_result = $this->upload_file_to_sftp($file, $lesson_id, $section_index);
            
            if ($upload_result) {
                wp_send_json_success(array(
                    'message' => 'File uploaded successfully.',
                    'file_url' => $upload_result['url']
                ));
            } else {
                wp_send_json_error('Failed to upload file to SFTP server.');
            }
            
        } catch (Exception $e) {
            wp_send_json_error('Upload error: ' . $e->getMessage());
        }
    }
    
    /**
     * Delete WASM file via AJAX
     */
    public function delete_wasm_file() {
        check_ajax_referer('delete_wasm_file', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        $wasm_id = intval($_POST['wasm_id']);
        $wasm_file = $this->database->get_wasm_file_by_id($wasm_id);
        
        if (!$wasm_file) {
            wp_send_json_error('WASM file not found.');
            return;
        }
        
        try {
            // Delete from SFTP server
            $this->delete_file_from_sftp($wasm_file->file_path);
            
            // Delete from database
            $this->database->delete_wasm_file($wasm_id);
            
            wp_send_json_success('File deleted successfully.');
            
        } catch (Exception $e) {
            wp_send_json_error('Delete error: ' . $e->getMessage());
        }
    }
    
    /**
     * Test SFTP connection via AJAX
     */
    public function test_sftp_connection() {
        check_ajax_referer('test_sftp_connection', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        try {
            $connection = $this->connect_sftp();
            
            if ($connection) {
                $this->disconnect_sftp();
                wp_send_json_success('SFTP connection successful.');
            } else {
                wp_send_json_error('Failed to connect to SFTP server.');
            }
            
        } catch (Exception $e) {
            wp_send_json_error('SFTP connection error: ' . $e->getMessage());
        }
    }
    
    /**
     * Upload file to SFTP server
     */
    private function upload_file_to_sftp($file, $lesson_id, $section_index) {
        $connection = $this->connect_sftp();
        
        if (!$connection) {
            throw new Exception('Failed to connect to SFTP server.');
        }
        
        $remote_path = get_option('wp_lms_sftp_path', '/');
        $url_base = get_option('wp_lms_sftp_url_base', '');
        
        // Create unique filename
        $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'lesson_' . $lesson_id . '_section_' . $section_index . '_' . time() . '.' . $file_extension;
        $remote_file_path = rtrim($remote_path, '/') . '/' . $filename;
        
        // Upload file
        $upload_success = ssh2_scp_send($connection, $file['tmp_name'], $remote_file_path);
        
        if (!$upload_success) {
            $this->disconnect_sftp();
            throw new Exception('Failed to upload file to SFTP server.');
        }
        
        $this->disconnect_sftp();
        
        // Store file information in database
        $file_url = rtrim($url_base, '/') . '/' . $filename;
        
        $this->database->store_wasm_file(
            $lesson_id,
            $section_index,
            $filename,
            $remote_file_path,
            $file_url,
            $file['size']
        );
        
        // Update lesson code section with WASM URL
        $this->update_lesson_code_section_wasm_url($lesson_id, $section_index, $file_url);
        
        return array(
            'filename' => $filename,
            'path' => $remote_file_path,
            'url' => $file_url,
            'size' => $file['size']
        );
    }
    
    /**
     * Delete file from SFTP server
     */
    private function delete_file_from_sftp($remote_file_path) {
        $connection = $this->connect_sftp();
        
        if (!$connection) {
            throw new Exception('Failed to connect to SFTP server.');
        }
        
        $sftp = ssh2_sftp($connection);
        
        if (!$sftp) {
            $this->disconnect_sftp();
            throw new Exception('Failed to initialize SFTP subsystem.');
        }
        
        $delete_success = ssh2_sftp_unlink($sftp, $remote_file_path);
        
        $this->disconnect_sftp();
        
        if (!$delete_success) {
            throw new Exception('Failed to delete file from SFTP server.');
        }
        
        return true;
    }
    
    /**
     * Connect to SFTP server
     */
    private function connect_sftp() {
        if ($this->sftp_connection) {
            return $this->sftp_connection;
        }
        
        $host = get_option('wp_lms_sftp_host', '');
        $port = get_option('wp_lms_sftp_port', 22);
        $username = get_option('wp_lms_sftp_username', '');
        $password = get_option('wp_lms_sftp_password', '');
        
        if (empty($host) || empty($username) || empty($password)) {
            throw new Exception('SFTP configuration incomplete.');
        }
        
        if (!function_exists('ssh2_connect')) {
            throw new Exception('SSH2 extension not available. Please install php-ssh2.');
        }
        
        $connection = ssh2_connect($host, $port);
        
        if (!$connection) {
            throw new Exception('Failed to connect to SFTP server.');
        }
        
        $auth_success = ssh2_auth_password($connection, $username, $password);
        
        if (!$auth_success) {
            throw new Exception('SFTP authentication failed.');
        }
        
        $this->sftp_connection = $connection;
        return $connection;
    }
    
    /**
     * Disconnect from SFTP server
     */
    private function disconnect_sftp() {
        if ($this->sftp_connection) {
            ssh2_disconnect($this->sftp_connection);
            $this->sftp_connection = null;
        }
    }
    
    /**
     * Update lesson code section with WASM URL
     */
    private function update_lesson_code_section_wasm_url($lesson_id, $section_index, $wasm_url) {
        $code_sections = get_post_meta($lesson_id, '_lesson_code_sections', true) ?: array();
        
        if (isset($code_sections[$section_index])) {
            $code_sections[$section_index]['wasm_url'] = $wasm_url;
            update_post_meta($lesson_id, '_lesson_code_sections', $code_sections);
        }
    }
    
    /**
     * Format file size for display
     */
    private function format_file_size($bytes) {
        $units = array('B', 'KB', 'MB', 'GB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Get WASM file by ID (add to database class if not exists)
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
     * Validate WASM file
     */
    private function validate_wasm_file($file_path) {
        // Basic validation - check if file starts with WASM magic number
        $handle = fopen($file_path, 'rb');
        if (!$handle) {
            return false;
        }
        
        $magic = fread($handle, 4);
        fclose($handle);
        
        // WASM magic number is 0x00 0x61 0x73 0x6D
        return $magic === "\x00\x61\x73\x6D";
    }
    
    /**
     * Create WASM wrapper HTML if needed
     */
    private function create_wasm_wrapper($wasm_url, $lesson_id, $section_index) {
        $html_content = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WASM Application</title>
    <style>
        body {
            margin: 0;
            padding: 20px;
            font-family: Arial, sans-serif;
            background: #f5f5f5;
        }
        .wasm-container {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .loading {
            text-align: center;
            padding: 40px;
        }
        .error {
            color: #d32f2f;
            text-align: center;
            padding: 20px;
        }
    </style>
</head>
<body>
    <div class="wasm-container">
        <div id="loading" class="loading">Loading WASM application...</div>
        <div id="error" class="error" style="display: none;">Failed to load WASM application.</div>
        <div id="wasm-content"></div>
    </div>
    
    <!-- WASM loading functionality moved to external JavaScript file -->
</body>
</html>';
        
        return $html_content;
    }
}
