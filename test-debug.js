// Simple test script to check if WP LMS is loading
console.log('=== WP LMS DEBUG TEST ===');
console.log('Current URL:', window.location.href);
console.log('jQuery available:', typeof jQuery !== 'undefined');
console.log('wp_lms_ajax available:', typeof wp_lms_ajax !== 'undefined');

if (typeof wp_lms_ajax !== 'undefined') {
    console.log('wp_lms_ajax object:', wp_lms_ajax);
} else {
    console.log('ERROR: wp_lms_ajax is not defined!');
}

console.log('WP_LMS_Frontend available:', typeof WP_LMS_Frontend !== 'undefined');

// Check if we're on a course page
if (document.querySelector('.wp-lms-learning-interface')) {
    console.log('On learning interface page');
} else if (document.querySelector('.wp-lms-course-overview')) {
    console.log('On course overview page');
} else {
    console.log('Not on LMS page');
}

// Test AJAX manually
if (typeof wp_lms_ajax !== 'undefined' && typeof jQuery !== 'undefined') {
    console.log('Testing AJAX connection...');
    jQuery.ajax({
        url: wp_lms_ajax.ajax_url,
        type: 'POST',
        data: {
            action: 'wp_lms_test_connection',
            nonce: wp_lms_ajax.nonce
        },
        success: function(response) {
            console.log('AJAX test success:', response);
        },
        error: function(xhr, status, error) {
            console.log('AJAX test error:', error, xhr.responseText);
        }
    });
}
