/**
 * WP LMS Plugin Frontend JavaScript
 */

(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        WP_LMS_Frontend.init();
    });

    // Main frontend object
    window.WP_LMS_Frontend = {
        
        // Current state
        currentLessonId: null,
        currentVideo: null,
        progressUpdateTimer: null,
        
        // Initialize frontend functionality
        init: function() {
            this.bindEvents();
            this.initializeVideoPlayer();
            this.loadInitialProgress();
        },
        
        // Bind event handlers
        bindEvents: function() {
            // Chapter toggle functionality
            $(document).on('click', '.chapter-header', function() {
                var chapterId = $(this).closest('.chapter-nav').data('chapter-id');
                WP_LMS_Frontend.toggleChapter(chapterId);
            });
            
            // Lesson selection
            $(document).on('click', '.lesson-nav', function() {
                var lessonId = $(this).data('lesson-id');
                WP_LMS_Frontend.loadLesson(lessonId);
            });
            
            // Code section buttons
            $(document).on('click', '.code-section-btn', function() {
                var sectionData = $(this).data('section');
                var sectionIndex = $(this).data('index');
                WP_LMS_Frontend.showCodeSection(sectionData, sectionIndex);
            });
            
            // Close overlay panels
            $(document).on('click', '#close-code-panel', function() {
                WP_LMS_Frontend.closeCodePanel();
            });
            
            $(document).on('click', '#close-wasm-panel', function() {
                WP_LMS_Frontend.closeWasmPanel();
            });
            
            // Run WASM button
            $(document).on('click', '#run-wasm-btn', function() {
                var wasmUrl = $(this).data('wasm-url');
                WP_LMS_Frontend.showWasmPanel(wasmUrl);
            });
            
            // Keyboard shortcuts
            $(document).on('keydown', function(e) {
                WP_LMS_Frontend.handleKeyboardShortcuts(e);
            });
            
            // Video progress tracking
            $(document).on('timeupdate', '#lesson-video', function() {
                WP_LMS_Frontend.handleVideoProgress(this);
            });
            
            // Video ended
            $(document).on('ended', '#lesson-video', function() {
                WP_LMS_Frontend.handleVideoEnded();
            });
            
            // Mark lesson complete button
            $(document).on('click', '.mark-complete-btn', function() {
                var lessonId = $(this).data('lesson-id');
                WP_LMS_Frontend.markLessonComplete(lessonId);
            });
        },
        
        // Toggle chapter visibility
        toggleChapter: function(chapterId) {
            var lessonsDiv = $('#lessons-' + chapterId);
            var toggle = $('[data-chapter-id="' + chapterId + '"] .chapter-toggle');
            
            // Prevent multiple rapid clicks
            if (lessonsDiv.is(':animated')) {
                return;
            }
            
            if (lessonsDiv.hasClass('open')) {
                lessonsDiv.removeClass('open').slideUp(300, function() {
                    toggle.text('▼');
                });
            } else {
                lessonsDiv.addClass('open').slideDown(300, function() {
                    toggle.text('▲');
                });
            }
        },
        
        // Load lesson content
        loadLesson: function(lessonId) {
            // Completely stop and cleanup previous video
            this.cleanupCurrentVideo();
            
            this.currentLessonId = lessonId;
            
            // Update active lesson styling
            $('.lesson-nav').removeClass('active');
            $('[data-lesson-id="' + lessonId + '"]').addClass('active');
            
            // Show loading state
            this.showLoadingState();
            
            // Load lesson data via AJAX
            $.ajax({
                url: wp_lms_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_lesson_data',
                    lesson_id: lessonId,
                    nonce: wp_lms_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WP_LMS_Frontend.displayLesson(response.data);
                    } else {
                        WP_LMS_Frontend.showError('Failed to load lesson: ' + response.data);
                    }
                },
                error: function() {
                    WP_LMS_Frontend.showError('Network error. Please try again.');
                }
            });
        },
        
        // Cleanup current video completely
        cleanupCurrentVideo: function() {
            if (this.currentVideo) {
                // Pause the video
                this.currentVideo.pause();
                
                // Reset video time to beginning
                this.currentVideo.currentTime = 0;
                
                // Remove all event listeners
                this.currentVideo.removeEventListener('timeupdate', this.handleVideoProgress);
                this.currentVideo.removeEventListener('ended', this.handleVideoEnded);
                
                // Clear the source to stop any background loading/playing
                this.currentVideo.src = '';
                this.currentVideo.load();
                
                // Clear reference
                this.currentVideo = null;
            }
            
            // Clear any progress update timers
            if (this.progressUpdateTimer) {
                clearTimeout(this.progressUpdateTimer);
                this.progressUpdateTimer = null;
            }
        },
        
        // Display lesson content
        displayLesson: function(lessonData) {
            // Hide welcome message and show video container
            $('#welcome-message').fadeOut(300, function() {
                $('#video-container').fadeIn(300);
            });
            
            // Create proper video container HTML
            var videoHtml = '';
            if (lessonData.video_url) {
                videoHtml = '<video id="lesson-video" controls width="100%">' +
                           '<source src="' + lessonData.video_url + '" type="video/mp4">' +
                           'Your browser does not support the video tag.' +
                           '</video>';
            } else {
                videoHtml = '<div class="no-video-message">' +
                           '<h3>No video available for this lesson</h3>' +
                           '<p>This lesson contains only text content and code sections.</p>' +
                           '</div>';
            }
            
            // Update video container content
            $('#video-container').html(
                videoHtml +
                '<div id="code-sections" class="code-sections"></div>'
            );
            
            // Set up video if available
            var video = document.getElementById('lesson-video');
            if (video && lessonData.video_url) {
                // Auto-play if user preference allows
                var playPromise = video.play();
                if (playPromise !== undefined) {
                    playPromise.catch(function(error) {
                        console.log('Auto-play prevented:', error);
                    });
                }
                
                this.currentVideo = video;
            }
            
            // Create code section buttons
            this.createCodeSectionButtons(lessonData.code_sections);
            
            // Update lesson title in header if exists
            if (lessonData.title) {
                $('.current-lesson-title').text(lessonData.title);
            }
        },
        
        // Create code section buttons
        createCodeSectionButtons: function(codeSections) {
            var container = $('#code-sections');
            container.empty();
            
            if (codeSections && codeSections.length > 0) {
                var validSections = [];
                
                // Filter out empty sections
                codeSections.forEach(function(section, index) {
                    // Only show sections that have content (title or code)
                    if (section.title || section.code) {
                        validSections.push({
                            section: section,
                            originalIndex: index
                        });
                    }
                });
                
                // Create buttons for valid sections
                validSections.forEach(function(item, displayIndex) {
                    var section = item.section;
                    var button = $('<button>')
                        .addClass('code-section-btn wp-lms-btn wp-lms-btn-secondary')
                        .text(section.title || 'Code Section ' + (displayIndex + 1))
                        .data('section', section)
                        .data('index', displayIndex);
                    
                    container.append(button);
                });
            }
        },
        
        // Show code section overlay
        showCodeSection: function(section, index) {
            // Pause video
            if (this.currentVideo) {
                this.currentVideo.pause();
            }
            
            // Populate code overlay
            $('#code-title').text(section.title || 'Code Section ' + (index + 1));
            
            // Clean HTML from code if it exists
            var codeElement = document.getElementById('code-display');
            var cleanCode = section.code;
            
            // If code contains HTML tags, extract plain text
            if (cleanCode && cleanCode.indexOf('<span class="token') !== -1) {
                // Create temporary element to extract text content
                var tempDiv = document.createElement('div');
                tempDiv.innerHTML = cleanCode;
                cleanCode = tempDiv.textContent || tempDiv.innerText || '';
            }
            
            // Set clean code as plain text only
            codeElement.textContent = cleanCode;
            codeElement.className = 'language-' + (section.language || 'kotlin');
            
            // Just show plain text - no syntax highlighting
            // This ensures clean, readable code display without any HTML issues
            
            // Update run button
            var runBtn = $('#run-wasm-btn');
            if (section.wasm_url) {
                runBtn.show().data('wasm-url', section.wasm_url);
            } else {
                runBtn.hide();
            }
            
            // Show overlay
            $('#code-overlay').fadeIn(300);
        },
        
        // Show WASM panel
        showWasmPanel: function(wasmUrl) {
            $('#wasm-frame').attr('src', wasmUrl);
            $('#wasm-overlay').fadeIn(300);
        },
        
        // Close code panel
        closeCodePanel: function() {
            $('#code-overlay').fadeOut(300);
            if (this.currentVideo) {
                this.currentVideo.play();
            }
        },
        
        // Close WASM panel
        closeWasmPanel: function() {
            $('#wasm-overlay').fadeOut(300);
            $('#wasm-frame').attr('src', '');
        },
        
        // Handle video progress updates
        handleVideoProgress: function(video) {
            if (!this.currentLessonId) return;
            
            // Throttle progress updates
            clearTimeout(this.progressUpdateTimer);
            this.progressUpdateTimer = setTimeout(function() {
                WP_LMS_Frontend.updateProgress(
                    WP_LMS_Frontend.currentLessonId, 
                    Math.floor(video.currentTime)
                );
            }, 2000);
        },
        
        // Handle video ended
        handleVideoEnded: function() {
            if (this.currentLessonId) {
                this.markLessonComplete(this.currentLessonId);
            }
        },
        
        // Update lesson progress
        updateProgress: function(lessonId, videoProgress) {
            $.ajax({
                url: wp_lms_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'update_lesson_progress',
                    lesson_id: lessonId,
                    video_progress: videoProgress,
                    nonce: wp_lms_ajax.nonce
                },
                success: function(response) {
                    if (response.success && response.data.completed) {
                        WP_LMS_Frontend.updateLessonStatus(lessonId, 'completed');
                    }
                }
            });
        },
        
        // Mark lesson as complete
        markLessonComplete: function(lessonId) {
            $.ajax({
                url: wp_lms_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'mark_lesson_complete',
                    lesson_id: lessonId,
                    nonce: wp_lms_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WP_LMS_Frontend.updateLessonStatus(lessonId, 'completed');
                        WP_LMS_Frontend.updateCourseProgress(response.data.course_completion);
                        WP_LMS_Frontend.showNotification('Lesson completed!', 'success');
                    }
                }
            });
        },
        
        // Update lesson status in UI
        updateLessonStatus: function(lessonId, status) {
            var statusElement = $('#status-' + lessonId);
            var lessonElement = $('[data-lesson-id="' + lessonId + '"]');
            
            switch (status) {
                case 'completed':
                    statusElement.html('<span class="status-icon completed">✓</span>');
                    lessonElement.addClass('completed');
                    break;
                case 'in-progress':
                    statusElement.html('<span class="status-icon in-progress">⏵</span>');
                    lessonElement.addClass('in-progress');
                    break;
                default:
                    statusElement.html('<span class="status-icon not-started">○</span>');
                    break;
            }
        },
        
        // Update course progress bar
        updateCourseProgress: function(percentage) {
            $('#course-progress').css('width', percentage + '%');
            $('.progress-percentage').text(Math.round(percentage) + '%');
        },
        
        // Handle keyboard shortcuts
        handleKeyboardShortcuts: function(e) {
            // Only handle shortcuts when not typing in input fields
            if ($(e.target).is('input, textarea')) return;
            
            switch (e.keyCode) {
                case 27: // Escape key
                    if ($('#code-overlay').is(':visible')) {
                        this.closeCodePanel();
                    } else if ($('#wasm-overlay').is(':visible')) {
                        this.closeWasmPanel();
                    }
                    break;
                case 32: // Spacebar
                    if (this.currentVideo && !$('#code-overlay').is(':visible') && !$('#wasm-overlay').is(':visible')) {
                        e.preventDefault();
                        if (this.currentVideo.paused) {
                            this.currentVideo.play();
                        } else {
                            this.currentVideo.pause();
                        }
                    }
                    break;
            }
        },
        
        // Initialize video player
        initializeVideoPlayer: function() {
            // Add custom video controls if needed
            $(document).on('loadedmetadata', '#lesson-video', function() {
                // Video loaded, can add custom functionality here
            });
        },
        
        // Load initial progress
        loadInitialProgress: function() {
            // Load course progress if on learning interface
            if ($('.wp-lms-learning-interface').length > 0) {
                this.loadCourseProgress();
            }
        },
        
        // Load course progress
        loadCourseProgress: function() {
            var courseId = this.getCourseId();
            if (!courseId) return;
            
            $.ajax({
                url: wp_lms_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_course_progress',
                    course_id: courseId,
                    nonce: wp_lms_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WP_LMS_Frontend.displayCourseProgress(response.data);
                    }
                }
            });
        },
        
        // Display course progress
        displayCourseProgress: function(progressData) {
            this.updateCourseProgress(progressData.completion_percentage);
            
            // Update lesson statuses
            if (progressData.chapters) {
                progressData.chapters.forEach(function(chapter) {
                    chapter.lessons.forEach(function(lesson) {
                        if (lesson.progress) {
                            var status = lesson.progress.completed ? 'completed' : 
                                        (lesson.progress.video_progress > 0 ? 'in-progress' : 'not-started');
                            WP_LMS_Frontend.updateLessonStatus(lesson.ID, status);
                        }
                    });
                });
            }
        },
        
        // Get current course ID
        getCourseId: function() {
            // Try to get from URL parameters or data attributes
            var urlParams = new URLSearchParams(window.location.search);
            var courseId = urlParams.get('course_id');
            
            if (!courseId) {
                courseId = $('.wp-lms-learning-interface').data('course-id');
            }
            
            return courseId;
        },
        
        // Show loading state
        showLoadingState: function() {
            $('#video-container').html('<div class="wp-lms-loading"><div class="wp-lms-spinner"></div></div>');
        },
        
        // Show error message
        showError: function(message) {
            $('#video-container').html('<div class="wp-lms-error"><p>' + message + '</p></div>');
        },
        
        // Show notification
        showNotification: function(message, type) {
            type = type || 'info';
            
            var notification = $('<div>')
                .addClass('wp-lms-notification wp-lms-notification-' + type)
                .text(message)
                .css({
                    position: 'fixed',
                    top: '20px',
                    right: '20px',
                    padding: '15px 20px',
                    borderRadius: '6px',
                    color: 'white',
                    zIndex: 10000,
                    opacity: 0
                });
            
            switch (type) {
                case 'success':
                    notification.css('background', '#46b450');
                    break;
                case 'error':
                    notification.css('background', '#d32f2f');
                    break;
                default:
                    notification.css('background', '#007cba');
                    break;
            }
            
            $('body').append(notification);
            
            notification.animate({opacity: 1}, 300).delay(3000).animate({opacity: 0}, 300, function() {
                $(this).remove();
            });
        },
        
        // Utility function to format time
        formatTime: function(seconds) {
            var minutes = Math.floor(seconds / 60);
            var remainingSeconds = Math.floor(seconds % 60);
            return minutes + ':' + (remainingSeconds < 10 ? '0' : '') + remainingSeconds;
        },
        
        // Utility function to debounce function calls
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
        },
        
        // Simple custom syntax highlighting
        applySyntaxHighlighting: function(element, language) {
            var code = element.textContent;
            var highlightedCode = code;
            
            // Define patterns for different languages
            var patterns = {
                kotlin: [
                    { pattern: /\b(val|var|fun|class|object|interface|enum|data|sealed|abstract|open|final|override|private|protected|public|internal|inline|suspend|operator|infix|lateinit|const|companion|init|constructor|this|super|return|if|else|when|for|while|do|break|continue|try|catch|finally|throw|import|package|as|is|in|out|by|where|reified|crossinline|noinline|vararg|tailrec|external|actual|expect|annotation|typealias)\b/g, className: 'keyword' },
                    { pattern: /"([^"\\]|\\.)*"/g, className: 'string' },
                    { pattern: /\b\d+(\.\d+)?[fFdDlL]?\b/g, className: 'number' },
                    { pattern: /\/\/.*$/gm, className: 'comment' },
                    { pattern: /\/\*[\s\S]*?\*\//g, className: 'comment' },
                    { pattern: /\b[A-Z][a-zA-Z0-9_]*\b/g, className: 'class-name' },
                    { pattern: /\b[a-zA-Z_][a-zA-Z0-9_]*(?=\s*\()/g, className: 'function' }
                ],
                java: [
                    { pattern: /\b(abstract|assert|boolean|break|byte|case|catch|char|class|const|continue|default|do|double|else|enum|extends|final|finally|float|for|goto|if|implements|import|instanceof|int|interface|long|native|new|package|private|protected|public|return|short|static|strictfp|super|switch|synchronized|this|throw|throws|transient|try|void|volatile|while)\b/g, className: 'keyword' },
                    { pattern: /"([^"\\]|\\.)*"/g, className: 'string' },
                    { pattern: /\b\d+(\.\d+)?[fFdDlL]?\b/g, className: 'number' },
                    { pattern: /\/\/.*$/gm, className: 'comment' },
                    { pattern: /\/\*[\s\S]*?\*\//g, className: 'comment' },
                    { pattern: /\b[A-Z][a-zA-Z0-9_]*\b/g, className: 'class-name' },
                    { pattern: /\b[a-zA-Z_][a-zA-Z0-9_]*(?=\s*\()/g, className: 'function' }
                ],
                javascript: [
                    { pattern: /\b(as|async|await|break|case|catch|class|const|continue|debugger|default|delete|do|else|enum|export|extends|finally|for|from|function|get|if|implements|import|in|instanceof|interface|let|new|null|of|package|private|protected|public|return|set|static|super|switch|this|throw|try|typeof|undefined|var|void|while|with|yield)\b/g, className: 'keyword' },
                    { pattern: /(?:"(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*'|`(?:[^`\\]|\\.)*`)/g, className: 'string' },
                    { pattern: /\b\d+(\.\d+)?([eE][+-]?\d+)?\b/g, className: 'number' },
                    { pattern: /\/\/.*$/gm, className: 'comment' },
                    { pattern: /\/\*[\s\S]*?\*\//g, className: 'comment' },
                    { pattern: /\b[a-zA-Z_][a-zA-Z0-9_]*(?=\s*\()/g, className: 'function' }
                ],
                python: [
                    { pattern: /\b(and|as|assert|break|class|continue|def|del|elif|else|except|exec|finally|for|from|global|if|import|in|is|lambda|not|or|pass|print|raise|return|try|while|with|yield)\b/g, className: 'keyword' },
                    { pattern: /(?:"""[\s\S]*?"""|'''[\s\S]*?'''|"(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*')/g, className: 'string' },
                    { pattern: /\b\d+(\.\d+)?([eE][+-]?\d+)?\b/g, className: 'number' },
                    { pattern: /#.*$/gm, className: 'comment' },
                    { pattern: /\b[a-zA-Z_][a-zA-Z0-9_]*(?=\s*\()/g, className: 'function' }
                ]
            };
            
            var langPatterns = patterns[language] || patterns.kotlin;
            
            // Apply highlighting patterns
            langPatterns.forEach(function(item) {
                highlightedCode = highlightedCode.replace(item.pattern, function(match) {
                    return '<span class="token ' + item.className + '">' + match + '</span>';
                });
            });
            
            // Set the highlighted code as HTML
            element.innerHTML = highlightedCode;
        }
    };

    // Course purchase functionality
    window.WP_LMS_Purchase = {
        
        stripe: null,
        elements: null,
        cardElement: null,
        paymentIntentId: null,
        
        init: function() {
            if (typeof Stripe === 'undefined') {
                console.error('Stripe.js not loaded');
                return;
            }
            
            // Initialize Stripe (publishable key should be localized from PHP)
            if (wp_lms_ajax.stripe_publishable_key) {
                this.stripe = Stripe(wp_lms_ajax.stripe_publishable_key);
                this.elements = this.stripe.elements();
                this.bindEvents();
            }
        },
        
        bindEvents: function() {
            $(document).on('click', '#wp-lms-purchase-btn', this.handlePurchaseClick.bind(this));
            $(document).on('click', '#submit-payment', this.handlePaymentSubmit.bind(this));
            $(document).on('click', '#cancel-payment', this.handlePaymentCancel.bind(this));
        },
        
        handlePurchaseClick: function(e) {
            var button = $(e.target);
            var courseId = button.data('course-id');
            
            this.createPaymentIntent(courseId);
        },
        
        createPaymentIntent: function(courseId) {
            $.ajax({
                url: wp_lms_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'create_payment_intent',
                    course_id: courseId,
                    nonce: wp_lms_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WP_LMS_Purchase.paymentIntentId = response.data.payment_intent_id;
                        WP_LMS_Purchase.showPaymentForm();
                    } else {
                        WP_LMS_Purchase.showError(response.data);
                    }
                }
            });
        },
        
        showPaymentForm: function() {
            $('#wp-lms-purchase-btn').hide();
            $('#wp-lms-payment-form').show();
            
            // Mount card element
            this.cardElement = this.elements.create('card');
            this.cardElement.mount('#card-element');
        },
        
        handlePaymentSubmit: function() {
            this.stripe.confirmCardPayment(this.paymentIntentId, {
                payment_method: {
                    card: this.cardElement
                }
            }).then(function(result) {
                if (result.error) {
                    $('#card-errors').text(result.error.message);
                } else {
                    WP_LMS_Purchase.confirmPayment();
                }
            });
        },
        
        confirmPayment: function() {
            $.ajax({
                url: wp_lms_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'confirm_payment',
                    payment_intent_id: this.paymentIntentId,
                    nonce: wp_lms_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WP_LMS_Purchase.showSuccess(response.data.message);
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        WP_LMS_Purchase.showError(response.data);
                    }
                }
            });
        },
        
        handlePaymentCancel: function() {
            $('#wp-lms-purchase-btn').show();
            $('#wp-lms-payment-form').hide();
            if (this.cardElement) {
                this.cardElement.unmount();
            }
        },
        
        showError: function(message) {
            $('#wp-lms-payment-status').html('<div class="error">' + message + '</div>');
        },
        
        showSuccess: function(message) {
            $('#wp-lms-payment-status').html('<div class="success">' + message + '</div>');
        }
    };

    // Initialize purchase functionality when document is ready
    $(document).ready(function() {
        WP_LMS_Purchase.init();
    });

})(jQuery);
