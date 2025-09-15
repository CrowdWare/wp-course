/**
 * WP LMS Plugin Admin JavaScript
 */

(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        WP_LMS_Admin.init();
    });

    // Main admin object
    window.WP_LMS_Admin = {
        
        // Initialize admin functionality
        init: function() {
            this.bindEvents();
            this.initializeCodeSections();
            this.initializeWasmUpload();
        },
        
        // Bind event handlers
        bindEvents: function() {
            // Code section management
            $(document).on('click', '#add-code-section', this.addCodeSection.bind(this));
            $(document).on('click', '.remove-code-section', this.removeCodeSection.bind(this));
            
            // WASM upload
            $(document).on('click', '.upload-wasm-btn', this.handleWasmUpload.bind(this));
            $(document).on('click', '.delete-wasm-btn', this.handleWasmDelete.bind(this));
            
            // Connection tests
            $(document).on('click', '#test-stripe-connection', this.testStripeConnection.bind(this));
            $(document).on('click', '#test-sftp-connection', this.testSftpConnection.bind(this));
            
            // Form validation
            $(document).on('submit', 'form', this.validateForm.bind(this));
            
            // Auto-save functionality
            $(document).on('change', 'input, select, textarea', this.debounce(this.autoSave.bind(this), 2000));
        },
        
        // Initialize code sections functionality
        initializeCodeSections: function() {
            var sectionIndex = $('#code-sections-container .code-section').length || 1;
            
            // Store section index for new sections
            $('#add-code-section').data('section-index', sectionIndex);
            
            // Initialize syntax highlighting for existing code sections
            this.highlightCodeSections();
        },
        
        // Add new code section
        addCodeSection: function(e) {
            e.preventDefault();
            
            var button = $(e.target);
            var sectionIndex = button.data('section-index') || 0;
            var template = this.getCodeSectionTemplate(sectionIndex);
            
            $('#code-sections-container').append(template);
            button.data('section-index', sectionIndex + 1);
            
            // Scroll to new section
            var newSection = $('#code-sections-container .code-section').last();
            $('html, body').animate({
                scrollTop: newSection.offset().top - 100
            }, 500);
            
            // Focus on title field
            newSection.find('input[name*="[title]"]').focus();
        },
        
        // Remove code section
        removeCodeSection: function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to remove this code section?')) {
                return;
            }
            
            var section = $(e.target).closest('.code-section');
            section.fadeOut(300, function() {
                $(this).remove();
                WP_LMS_Admin.reindexCodeSections();
            });
        },
        
        // Reindex code sections after removal
        reindexCodeSections: function() {
            $('#code-sections-container .code-section').each(function(index) {
                var section = $(this);
                
                // Update section title
                section.find('h4').text('Code Section ' + (index + 1));
                
                // Update input names
                section.find('input, select, textarea').each(function() {
                    var input = $(this);
                    var name = input.attr('name');
                    if (name) {
                        var newName = name.replace(/\[\d+\]/, '[' + index + ']');
                        input.attr('name', newName);
                    }
                });
            });
        },
        
        // Get code section template
        getCodeSectionTemplate: function(index) {
            return `
                <div class="code-section" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px;">
                    <h4>Code Section ${index + 1}</h4>
                    <table class="form-table">
                        <tr>
                            <th><label>Title</label></th>
                            <td><input type="text" name="code_sections[${index}][title]" value="" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th><label>Language</label></th>
                            <td>
                                <select name="code_sections[${index}][language]">
                                    <option value="kotlin">Kotlin</option>
                                    <option value="java">Java</option>
                                    <option value="javascript">JavaScript</option>
                                    <option value="python">Python</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Code</label></th>
                            <td><textarea name="code_sections[${index}][code]" rows="10" class="large-text code-textarea"></textarea></td>
                        </tr>
                        <tr>
                            <th><label>WASM URL</label></th>
                            <td><input type="url" name="code_sections[${index}][wasm_url]" value="" class="regular-text" /></td>
                        </tr>
                    </table>
                    <button type="button" class="button remove-code-section">Remove Section</button>
                </div>
            `;
        },
        
        // Initialize WASM upload functionality
        initializeWasmUpload: function() {
            // File input change handler
            $(document).on('change', '.wasm-file-input', function() {
                var input = $(this);
                var file = input[0].files[0];
                var sectionIndex = input.data('section-index');
                var uploadBtn = $('.upload-wasm-btn[data-section-index="' + sectionIndex + '"]');
                
                if (file) {
                    uploadBtn.prop('disabled', false);
                    
                    // Show file info
                    var fileInfo = WP_LMS_Admin.formatFileSize(file.size) + ' - ' + file.name;
                    input.next('.file-info').remove();
                    input.after('<span class="file-info" style="margin-left: 10px; color: #666;">' + fileInfo + '</span>');
                } else {
                    uploadBtn.prop('disabled', true);
                    input.next('.file-info').remove();
                }
            });
        },
        
        // Handle WASM file upload
        handleWasmUpload: function(e) {
            e.preventDefault();
            
            var button = $(e.target);
            var sectionIndex = button.data('section-index');
            var lessonId = button.data('lesson-id');
            var fileInput = $('.wasm-file-input[data-section-index="' + sectionIndex + '"]');
            var file = fileInput[0].files[0];
            
            if (!file) {
                this.showNotice('Please select a file to upload.', 'error');
                return;
            }
            
            // Validate file type
            var allowedTypes = ['application/wasm', 'text/html', 'application/octet-stream'];
            var allowedExtensions = ['wasm', 'html'];
            var fileExtension = file.name.split('.').pop().toLowerCase();
            
            if (!allowedExtensions.includes(fileExtension)) {
                this.showNotice('Invalid file type. Only WASM and HTML files are allowed.', 'error');
                return;
            }
            
            // Check file size (50MB limit)
            if (file.size > 50 * 1024 * 1024) {
                this.showNotice('File too large. Maximum size is 50MB.', 'error');
                return;
            }
            
            var formData = new FormData();
            formData.append('action', 'upload_wasm_file');
            formData.append('lesson_id', lessonId);
            formData.append('section_index', sectionIndex);
            formData.append('wasm_file', file);
            formData.append('nonce', $('#wasm-upload-nonce').val());
            
            var progressContainer = button.closest('.wasm-section').find('.upload-progress');
            var resultContainer = button.closest('.wasm-section').find('.upload-result');
            
            // Show progress
            button.prop('disabled', true).addClass('loading');
            progressContainer.show();
            resultContainer.empty();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: function() {
                    var xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener('progress', function(evt) {
                        if (evt.lengthComputable) {
                            var percentComplete = (evt.loaded / evt.total) * 100;
                            progressContainer.find('.progress-fill').css('width', percentComplete + '%');
                            progressContainer.find('.progress-text').text(Math.round(percentComplete) + '%');
                        }
                    }, false);
                    return xhr;
                },
                success: function(response) {
                    if (response.success) {
                        resultContainer.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                        WP_LMS_Admin.showNotice('File uploaded successfully!', 'success');
                        
                        // Reload page after 2 seconds to show updated file info
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        resultContainer.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                        WP_LMS_Admin.showNotice('Upload failed: ' + response.data, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    resultContainer.html('<div class="notice notice-error"><p>Upload failed. Please try again.</p></div>');
                    WP_LMS_Admin.showNotice('Network error. Please try again.', 'error');
                },
                complete: function() {
                    button.prop('disabled', false).removeClass('loading');
                    progressContainer.hide();
                }
            });
        },
        
        // Handle WASM file deletion
        handleWasmDelete: function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to delete this WASM file?')) {
                return;
            }
            
            var button = $(e.target);
            var wasmId = button.data('wasm-id');
            
            button.prop('disabled', true).addClass('loading');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'delete_wasm_file',
                    wasm_id: wasmId,
                    nonce: $('#wasm-delete-nonce').val()
                },
                success: function(response) {
                    if (response.success) {
                        button.closest('.current-wasm-file').fadeOut(300, function() {
                            $(this).remove();
                        });
                        WP_LMS_Admin.showNotice('File deleted successfully!', 'success');
                    } else {
                        WP_LMS_Admin.showNotice('Delete failed: ' + response.data, 'error');
                    }
                },
                error: function() {
                    WP_LMS_Admin.showNotice('Network error. Please try again.', 'error');
                },
                complete: function() {
                    button.prop('disabled', false).removeClass('loading');
                }
            });
        },
        
        // Test Stripe connection
        testStripeConnection: function(e) {
            e.preventDefault();
            
            var button = $(e.target);
            var resultContainer = $('#stripe-test-result');
            
            button.prop('disabled', true).addClass('loading');
            resultContainer.empty();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'test_stripe_connection',
                    nonce: $('#stripe-test-nonce').val()
                },
                success: function(response) {
                    if (response.success) {
                        resultContainer.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                    } else {
                        resultContainer.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    resultContainer.html('<div class="notice notice-error"><p>Connection test failed. Please try again.</p></div>');
                },
                complete: function() {
                    button.prop('disabled', false).removeClass('loading');
                }
            });
        },
        
        // Test SFTP connection
        testSftpConnection: function(e) {
            e.preventDefault();
            
            var button = $(e.target);
            var resultContainer = $('#sftp-test-result');
            
            button.prop('disabled', true).addClass('loading');
            resultContainer.empty();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'test_sftp_connection',
                    nonce: $('#sftp-test-nonce').val()
                },
                success: function(response) {
                    if (response.success) {
                        resultContainer.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                    } else {
                        resultContainer.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    resultContainer.html('<div class="notice notice-error"><p>Connection test failed. Please try again.</p></div>');
                },
                complete: function() {
                    button.prop('disabled', false).removeClass('loading');
                }
            });
        },
        
        // Form validation
        validateForm: function(e) {
            var form = $(e.target);
            var isValid = true;
            var errors = [];
            
            // Validate required fields
            form.find('input[required], select[required], textarea[required]').each(function() {
                var field = $(this);
                if (!field.val().trim()) {
                    isValid = false;
                    errors.push(field.attr('name') + ' is required.');
                    field.addClass('error');
                } else {
                    field.removeClass('error');
                }
            });
            
            // Validate email fields
            form.find('input[type="email"]').each(function() {
                var field = $(this);
                var email = field.val().trim();
                if (email && !WP_LMS_Admin.isValidEmail(email)) {
                    isValid = false;
                    errors.push('Please enter a valid email address.');
                    field.addClass('error');
                } else {
                    field.removeClass('error');
                }
            });
            
            // Validate URL fields
            form.find('input[type="url"]').each(function() {
                var field = $(this);
                var url = field.val().trim();
                if (url && !WP_LMS_Admin.isValidUrl(url)) {
                    isValid = false;
                    errors.push('Please enter a valid URL.');
                    field.addClass('error');
                } else {
                    field.removeClass('error');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                this.showNotice(errors.join('<br>'), 'error');
                
                // Scroll to first error field
                var firstError = form.find('.error').first();
                if (firstError.length) {
                    $('html, body').animate({
                        scrollTop: firstError.offset().top - 100
                    }, 500);
                    firstError.focus();
                }
            }
            
            return isValid;
        },
        
        // Auto-save functionality
        autoSave: function(e) {
            var field = $(e.target);
            var form = field.closest('form');
            
            // Only auto-save on specific forms
            if (!form.hasClass('auto-save-enabled')) {
                return;
            }
            
            var formData = form.serialize();
            formData += '&action=wp_lms_auto_save&nonce=' + $('#auto-save-nonce').val();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        WP_LMS_Admin.showAutoSaveIndicator();
                    }
                }
            });
        },
        
        // Show auto-save indicator
        showAutoSaveIndicator: function() {
            var indicator = $('#auto-save-indicator');
            if (!indicator.length) {
                indicator = $('<div id="auto-save-indicator" style="position: fixed; top: 32px; right: 20px; background: #00a32a; color: white; padding: 8px 12px; border-radius: 4px; font-size: 12px; z-index: 9999;">Saved</div>');
                $('body').append(indicator);
            }
            
            indicator.fadeIn(200).delay(2000).fadeOut(200);
        },
        
        // Highlight code sections
        highlightCodeSections: function() {
            $('.code-textarea').each(function() {
                var textarea = $(this);
                var language = textarea.closest('.code-section').find('select[name*="[language]"]').val() || 'kotlin';
                
                // Add syntax highlighting class
                textarea.addClass('language-' + language);
                
                // Initialize basic syntax highlighting
                if (typeof Prism !== 'undefined') {
                    textarea.on('input', WP_LMS_Admin.debounce(function() {
                        // Could add live syntax highlighting here
                    }, 500));
                }
            });
        },
        
        // Show notification
        showNotice: function(message, type) {
            type = type || 'info';
            
            var notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            
            // Insert after page title
            var target = $('.wrap h1').first();
            if (target.length) {
                target.after(notice);
            } else {
                $('.wrap').prepend(notice);
            }
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
            
            // Scroll to notice
            $('html, body').animate({
                scrollTop: notice.offset().top - 100
            }, 300);
        },
        
        // Utility functions
        formatFileSize: function(bytes) {
            if (bytes === 0) return '0 Bytes';
            
            var k = 1024;
            var sizes = ['Bytes', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },
        
        isValidEmail: function(email) {
            var regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return regex.test(email);
        },
        
        isValidUrl: function(url) {
            try {
                new URL(url);
                return true;
            } catch (e) {
                return false;
            }
        },
        
        debounce: function(func, wait) {
            var timeout;
            return function executedFunction() {
                var context = this;
                var args = arguments;
                var later = function() {
                    timeout = null;
                    func.apply(context, args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
    };

})(jQuery);
