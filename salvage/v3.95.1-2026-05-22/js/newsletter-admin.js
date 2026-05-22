/**
 * Newsletter Admin JavaScript
 */
(function($) {
    'use strict';

    // Current step tracking
    var currentStep = 1;

    /**
     * Initialize when document is ready
     */
    $(document).ready(function() {
        // Skip initialization on the editor page - newsletter-editor.js handles it
        if ($('#gjs-editor').length > 0) {
            return;
        }
        
        initStepNavigation();
        initRecipientCheckboxes();
        initSubjectCharCount();
        initSendOptions();
        initPageOptions();
        initPersonalizationTags();
    });

    /**
     * Step Navigation
     */
    function initStepNavigation() {
        // Get initial step from hidden field
        currentStep = parseInt($('#current_step').val()) || 1;

        // Next step buttons
        $('.next-step').on('click', function(e) {
            e.preventDefault();
            var nextStep = parseInt($(this).data('next'));
            
            // Validate current step before proceeding
            if (validateStep(currentStep)) {
                goToStep(nextStep);
            }
        });

        // Previous step buttons
        $('.prev-step').on('click', function(e) {
            e.preventDefault();
            var prevStep = parseInt($(this).data('prev'));
            goToStep(prevStep);
        });

        // Clickable steps (only for completed or current steps)
        $('.arrow-step').on('click', function() {
            var stepNum = parseInt($(this).data('step'));
            
            // Only allow clicking on completed steps or current step
            if ($(this).hasClass('completed') || stepNum <= currentStep) {
                goToStep(stepNum);
            }
        });
    }

    /**
     * Go to specific step
     */
    function goToStep(step) {
        // Hide all step contents
        $('.step-content').hide();
        
        // Show target step content
        $('#step-' + step + '-content').show();
        
        // Update arrow flow
        updateArrowFlow(step);
        
        // Update hidden field
        $('#current_step').val(step);
        
        // Update current step
        currentStep = step;
        
        // Trigger step-specific actions
        if (step === 3) {
            updateReviewSummary();
            updatePreview();
        }
        
        // Scroll to top of form
        $('html, body').animate({
            scrollTop: $('.newsletter-editor-wrap').offset().top - 50
        }, 300);
    }

    /**
     * Update arrow flow visual state
     */
    function updateArrowFlow(activeStep) {
        $('.arrow-step').each(function() {
            var stepNum = parseInt($(this).data('step'));
            var $step = $(this);
            
            // Remove all state classes
            $step.removeClass('current completed pending');
            
            if (stepNum < activeStep) {
                // Completed step
                $step.addClass('completed');
                // Replace number with checkmark
                $step.find('.step-num').replaceWith('<span class="dashicons dashicons-yes-alt"></span>');
            } else if (stepNum === activeStep) {
                // Current step
                $step.addClass('current');
                // Ensure it shows the number if it was a checkmark
                if ($step.find('.dashicons').length) {
                    $step.find('.dashicons').replaceWith('<span class="step-num">' + stepNum + '</span>');
                }
            } else {
                // Pending step
                $step.addClass('pending');
                // Ensure it shows the number
                if ($step.find('.dashicons').length) {
                    $step.find('.dashicons').replaceWith('<span class="step-num">' + stepNum + '</span>');
                }
            }
        });
    }

    /**
     * Validate step before proceeding
     */
    function validateStep(step) {
        var isValid = true;
        var errors = [];

        if (step === 1) {
            // Validate Step 1: Setup
            var name = $('#newsletter_name').val().trim();
            var subject = $('#newsletter_subject').val().trim();
            var from = $('#newsletter_from').val();
            var recipients = $('input[name="newsletter_lists[]"]:checked').length;

            if (!name) {
                errors.push('Please enter an internal name');
                $('#newsletter_name').addClass('error');
                isValid = false;
            } else {
                $('#newsletter_name').removeClass('error');
            }

            if (!subject) {
                errors.push('Please enter an email subject');
                $('#newsletter_subject').addClass('error');
                isValid = false;
            } else {
                $('#newsletter_subject').removeClass('error');
            }

            if (!from) {
                errors.push('Please select a sender');
                $('#newsletter_from').addClass('error');
                isValid = false;
            } else {
                $('#newsletter_from').removeClass('error');
            }

            if (recipients === 0) {
                errors.push('Please select at least one recipient list');
                isValid = false;
            }
        }

        if (!isValid && errors.length > 0) {
            alert(errors.join('\n'));
        }

        return isValid;
    }

    /**
     * Recipient Checkboxes
     */
    function initRecipientCheckboxes() {
        // Update count when checkboxes change
        $('input[name="newsletter_lists[]"]').on('change', function() {
            updateRecipientCount();
        });

        // Initial count
        updateRecipientCount();
    }

    /**
     * Update total recipient count
     */
    function updateRecipientCount() {
        var selectedLists = $('input[name="newsletter_lists[]"]:checked');
        var totalCount = 0;
        var pendingRequests = selectedLists.length;

        if (pendingRequests === 0) {
            $('#total-recipient-count').text('0');
            return;
        }

        selectedLists.each(function() {
            var listId = $(this).val();
            
            $.post(azureNewsletter.ajaxUrl, {
                action: 'azure_newsletter_get_recipients_count',
                list_id: listId,
                nonce: azureNewsletter.nonce
            }, function(response) {
                if (response.success) {
                    totalCount += parseInt(response.data.count);
                }
                pendingRequests--;
                
                if (pendingRequests === 0) {
                    $('#total-recipient-count').text(totalCount.toLocaleString());
                }
            });
        });
    }

    /**
     * Subject line character counter
     */
    function initSubjectCharCount() {
        var $subject = $('#newsletter_subject');
        var $counter = $('#subject-chars');

        // Only run if elements exist (editor page only)
        if (!$subject.length || !$counter.length) {
            return;
        }

        function updateCount() {
            var len = $subject.val() ? $subject.val().length : 0;
            $counter.text(len);
            
            if (len > 60) {
                $counter.css('color', '#d63638');
            } else if (len > 50) {
                $counter.css('color', '#dba617');
            } else {
                $counter.css('color', '#646970');
            }
        }

        $subject.on('input', updateCount);
        updateCount(); // Initial count
    }

    /**
     * Send options (schedule visibility)
     */
    function initSendOptions() {
        $('input[name="send_option"]').on('change', function() {
            var value = $(this).val();
            
            if (value === 'schedule') {
                $('#schedule-options').slideDown();
            } else {
                $('#schedule-options').slideUp();
            }
            
            // Update button text
            if (value === 'draft') {
                $('#final-send-btn').hide();
                $('#save-draft-btn').show();
            } else {
                $('#final-send-btn').show();
                if (value === 'now') {
                    $('#final-send-btn').find('span:last').text('Send Newsletter');
                } else {
                    $('#final-send-btn').find('span:last').text('Schedule Newsletter');
                }
            }
        });
    }

    /**
     * Page options (create WP page)
     */
    function initPageOptions() {
        $('#create_wp_page').on('change', function() {
            if ($(this).is(':checked')) {
                $('#page-settings').slideDown();
            } else {
                $('#page-settings').slideUp();
            }
        });
    }

    /**
     * Personalization/Merge tags
     */
    function initPersonalizationTags() {
        $('.insert-personalization').on('click', function() {
            var tag = $(this).data('tag');
            var $subject = $('#newsletter_subject');
            
            // Insert at cursor position
            var cursorPos = $subject[0].selectionStart;
            var textBefore = $subject.val().substring(0, cursorPos);
            var textAfter = $subject.val().substring(cursorPos);
            
            $subject.val(textBefore + tag + textAfter);
            
            // Update character count
            $subject.trigger('input');
            
            // Focus back on the field
            $subject.focus();
        });
    }

    /**
     * Update review summary (Step 3)
     */
    function updateReviewSummary() {
        $('#summary-subject').text($('#newsletter_subject').val());
        
        var fromSelect = $('#newsletter_from');
        var fromText = fromSelect.find('option:selected').text() || 'Not selected';
        $('#summary-from').text(fromText);
        
        var recipientCount = $('#total-recipient-count').text();
        $('#summary-recipients').text(recipientCount + ' ' + azureNewsletter.strings.recipients);
    }

    /**
     * Update preview iframe (Step 3)
     */
    function updatePreview() {
        var html = $('#newsletter_content_html').val();
        if (html) {
            var iframe = document.getElementById('preview-frame');
            var doc = iframe.contentDocument || iframe.contentWindow.document;
            doc.open();
            doc.write(html);
            doc.close();
        }
    }

    // Expose functions globally if needed
    window.newsletterGoToStep = goToStep;
    window.newsletterUpdateArrowFlow = updateArrowFlow;

})(jQuery);

