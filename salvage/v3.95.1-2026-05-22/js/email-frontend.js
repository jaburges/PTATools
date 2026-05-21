/**
 * Azure Plugin Email Frontend JavaScript
 */

jQuery(document).ready(function($) {
    
    // Handle contact form submissions
    $('.azure-contact-form-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var formId = form.data('form-id');
        var submitButton = form.find('.azure-contact-submit');
        var messagesContainer = form.find('.form-messages');
        
        // Disable submit button
        submitButton.prop('disabled', true);
        var originalText = submitButton.text();
        submitButton.html('<span class="azure-email-loading"></span> Sending...');
        
        // Clear previous messages
        messagesContainer.empty();
        
        // Show loading message
        messagesContainer.html('<div class="form-message loading">Sending your message, please wait...</div>');
        
        // Serialize form data
        var formData = form.serialize();
        formData += '&action=azure_contact_form';
        
        // Submit via AJAX
        $.ajax({
            url: azure_email_ajax.ajax_url,
            type: 'POST',
            data: formData,
            timeout: 30000,
            success: function(response) {
                messagesContainer.empty();
                
                if (response.success) {
                    // Show success message
                    messagesContainer.html('<div class="form-message success">' + response.data + '</div>');
                    
                    // Reset form
                    form[0].reset();
                    
                    // Scroll to message
                    $('html, body').animate({
                        scrollTop: messagesContainer.offset().top - 50
                    }, 500);
                    
                } else {
                    // Show error message
                    messagesContainer.html('<div class="form-message error">' + response.data + '</div>');
                }
            },
            error: function(xhr, status, error) {
                messagesContainer.empty();
                
                var errorMessage = 'An error occurred while sending your message. Please try again.';
                
                if (status === 'timeout') {
                    errorMessage = 'The request timed out. Please try again.';
                } else if (xhr.status === 500) {
                    errorMessage = 'Server error occurred. Please try again later.';
                } else if (xhr.status === 403) {
                    errorMessage = 'Access denied. Please refresh the page and try again.';
                }
                
                messagesContainer.html('<div class="form-message error">' + errorMessage + '</div>');
            },
            complete: function() {
                // Re-enable submit button
                submitButton.prop('disabled', false).html(originalText);
            }
        });
    });
    
    // Form validation
    $('.azure-contact-form-form input, .azure-contact-form-form textarea').on('blur', function() {
        var field = $(this);
        var fieldValue = field.val().trim();
        var isRequired = field.prop('required');
        var fieldType = field.attr('type');
        
        // Remove previous error styling
        field.removeClass('field-error');
        field.next('.field-error-message').remove();
        
        if (isRequired && !fieldValue) {
            showFieldError(field, 'This field is required.');
            return;
        }
        
        if (fieldValue) {
            // Email validation
            if (fieldType === 'email') {
                var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(fieldValue)) {
                    showFieldError(field, 'Please enter a valid email address.');
                    return;
                }
            }
            
            // Phone validation (basic)
            if (fieldType === 'tel') {
                var phoneRegex = /^[\+]?[\s\-\(\)]*[\d\s\-\(\)]+$/;
                if (!phoneRegex.test(fieldValue)) {
                    showFieldError(field, 'Please enter a valid phone number.');
                    return;
                }
            }
        }
        
        // Show success styling for valid fields
        field.addClass('field-valid');
    });
    
    function showFieldError(field, message) {
        field.addClass('field-error');
        field.removeClass('field-valid');
        field.after('<div class="field-error-message">' + message + '</div>');
    }
    
    // Character counter for message fields
    $('.azure-contact-form-form textarea[name="contact_message"]').on('input', function() {
        var textarea = $(this);
        var currentLength = textarea.val().length;
        var maxLength = textarea.attr('maxlength') || 1000;
        
        var counter = textarea.siblings('.character-counter');
        if (counter.length === 0) {
            counter = $('<div class="character-counter"></div>');
            textarea.after(counter);
        }
        
        counter.text(currentLength + ' / ' + maxLength + ' characters');
        
        if (currentLength > maxLength * 0.9) {
            counter.addClass('near-limit');
        } else {
            counter.removeClass('near-limit');
        }
    });
    
    // Auto-resize textarea
    $('.azure-contact-form-form textarea').on('input', function() {
        var textarea = this;
        textarea.style.height = 'auto';
        textarea.style.height = (textarea.scrollHeight) + 'px';
    });
    
    // Spam protection - honeypot field
    $('<input type="text" name="website_url" style="display:none;" tabindex="-1" autocomplete="off">').appendTo('.azure-contact-form-form');
    
    // Rate limiting - prevent rapid submissions
    var lastSubmitTime = 0;
    $('.azure-contact-form-form').on('submit', function(e) {
        var currentTime = Date.now();
        var timeDiff = currentTime - lastSubmitTime;
        
        if (timeDiff < 3000) { // 3 second minimum between submissions
            e.preventDefault();
            var messagesContainer = $(this).find('.form-messages');
            messagesContainer.html('<div class="form-message error">Please wait a moment before submitting again.</div>');
            return false;
        }
        
        lastSubmitTime = currentTime;
    });
    
    // Handle email status refresh
    $('.azure-email-status .refresh-status').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var statusContainer = button.closest('.azure-email-status');
        
        button.prop('disabled', true).html('<span class="azure-email-loading"></span> Refreshing...');
        
        $.ajax({
            url: azure_email_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'azure_get_email_status',
                nonce: azure_email_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    statusContainer.html(response.data);
                } else {
                    console.error('Failed to refresh email status:', response.data);
                }
            },
            error: function() {
                console.error('Error refreshing email status');
            },
            complete: function() {
                button.prop('disabled', false).text('Refresh');
            }
        });
    });
    
    // Copy shortcode to clipboard
    $('.shortcode-copy').on('click', function() {
        var button = $(this);
        var shortcode = button.siblings('input').val();
        
        if (navigator.clipboard) {
            navigator.clipboard.writeText(shortcode).then(function() {
                showTooltip(button, 'Copied!');
            }).catch(function() {
                fallbackCopyToClipboard(shortcode, button);
            });
        } else {
            fallbackCopyToClipboard(shortcode, button);
        }
    });
    
    function fallbackCopyToClipboard(text, button) {
        var textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.left = "-999999px";
        textArea.style.top = "-999999px";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            document.execCommand('copy');
            showTooltip(button, 'Copied!');
        } catch (err) {
            showTooltip(button, 'Copy failed');
        }
        
        document.body.removeChild(textArea);
    }
    
    function showTooltip(element, message) {
        var tooltip = $('<div class="copy-tooltip">' + message + '</div>');
        element.parent().append(tooltip);
        
        setTimeout(function() {
            tooltip.fadeOut(function() {
                tooltip.remove();
            });
        }, 2000);
    }
    
    // Smooth scrolling for form anchors
    $('a[href^="#azure-contact-form"]').on('click', function(e) {
        e.preventDefault();
        
        var target = $(this.getAttribute('href'));
        if (target.length) {
            $('html, body').animate({
                scrollTop: target.offset().top - 50
            }, 1000);
        }
    });
    
    // Form accessibility improvements
    $('.azure-contact-form-form input, .azure-contact-form-form textarea').each(function() {
        var field = $(this);
        var label = field.siblings('label');
        
        if (label.length && !field.attr('aria-labelledby')) {
            var labelId = 'label_' + Math.random().toString(36).substr(2, 9);
            label.attr('id', labelId);
            field.attr('aria-labelledby', labelId);
        }
        
        if (field.prop('required')) {
            field.attr('aria-required', 'true');
        }
    });
    
    // Initialize on page load
    initializeEmailForms();
    
    function initializeEmailForms() {
        // Auto-focus first empty required field
        var firstEmptyRequired = $('.azure-contact-form-form input[required], .azure-contact-form-form textarea[required]').filter(function() {
            return !$(this).val();
        }).first();
        
        if (firstEmptyRequired.length && !isMobileDevice()) {
            setTimeout(function() {
                firstEmptyRequired.focus();
            }, 500);
        }
        
        // Initialize character counters
        $('.azure-contact-form-form textarea[name="contact_message"]').trigger('input');
    }
    
    function isMobileDevice() {
        return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    }
    
    // Handle browser back button
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
            // Reset forms when page is restored from cache
            $('.azure-contact-form-form')[0]?.reset();
            $('.form-messages').empty();
        }
    });
});

// Add CSS for validation styling
var validationCSS = `
<style>
.azure-contact-form input.field-error,
.azure-contact-form textarea.field-error {
    border-color: #dc3232 !important;
    box-shadow: 0 0 0 2px rgba(220, 50, 50, 0.2) !important;
}

.azure-contact-form input.field-valid,
.azure-contact-form textarea.field-valid {
    border-color: #46b450 !important;
}

.field-error-message {
    color: #dc3232;
    font-size: 12px;
    margin-top: 4px;
    display: block;
}

.character-counter {
    font-size: 12px;
    color: #666;
    text-align: right;
    margin-top: 4px;
}

.character-counter.near-limit {
    color: #dc3232;
    font-weight: bold;
}

.copy-tooltip {
    position: absolute;
    background: #333;
    color: #fff;
    padding: 5px 8px;
    border-radius: 4px;
    font-size: 12px;
    white-space: nowrap;
    z-index: 1000;
    top: -30px;
    left: 50%;
    transform: translateX(-50%);
}

.copy-tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    border: 5px solid transparent;
    border-top-color: #333;
}

@media (max-width: 480px) {
    .copy-tooltip {
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
    }
    
    .copy-tooltip::after {
        display: none;
    }
}
</style>
`;

document.head.insertAdjacentHTML('beforeend', validationCSS);

















