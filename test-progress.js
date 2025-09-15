// Test Progress Tracking System
console.log("=== TESTING PROGRESS TRACKING ===");

// Check if we're on a learning interface
if (document.querySelector('.wp-lms-learning-interface')) {
    console.log("✅ On learning interface page");
    
    // Check if lessons are available
    const lessons = document.querySelectorAll('.lesson-nav');
    console.log("📚 Found", lessons.length, "lessons");
    
    if (lessons.length > 0) {
        console.log("🎯 Testing lesson click...");
        
        // Click first lesson
        const firstLesson = lessons[0];
        const lessonId = firstLesson.getAttribute('data-lesson-id');
        console.log("📖 Clicking lesson ID:", lessonId);
        
        // Simulate lesson click
        firstLesson.click();
        
        // Wait a bit then check for video
        setTimeout(function() {
            const video = document.getElementById('lesson-video');
            if (video) {
                console.log("🎥 Video element found:", video.src);
                
                // Test progress update manually
                if (typeof WP_LMS_Frontend !== 'undefined' && WP_LMS_Frontend.updateProgress) {
                    console.log("🔄 Testing manual progress update...");
                    WP_LMS_Frontend.updateProgress(lessonId, 30); // 30 seconds
                    
                    setTimeout(function() {
                        console.log("✅ Progress update test completed");
                    }, 2000);
                } else {
                    console.log("❌ WP_LMS_Frontend.updateProgress not available");
                }
            } else {
                console.log("❌ No video element found");
            }
        }, 1000);
    } else {
        console.log("❌ No lessons found");
    }
} else {
    console.log("❌ Not on learning interface page");
}

// Test AJAX connection
console.log("🔗 Testing AJAX connection...");
jQuery.ajax({
    url: wp_lms_ajax.ajax_url,
    type: 'POST',
    data: {
        action: 'wp_lms_test_connection',
        nonce: wp_lms_ajax.nonce
    },
    success: function(response) {
        console.log("✅ AJAX connection successful:", response);
    },
    error: function(xhr, status, error) {
        console.log("❌ AJAX connection failed:", error, xhr.responseText);
    }
});

// Check admin dashboard stats (if accessible)
console.log("📊 Admin dashboard stats test available at: " + wp_lms_ajax.ajax_url.replace('admin-ajax.php', '') + 'admin.php?page=wp-lms-settings');

