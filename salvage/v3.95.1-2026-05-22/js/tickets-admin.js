/**
 * Tickets Module - Admin JavaScript
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Module toggle
        $('.module-toggle').on('change', function() {
            var $toggle = $(this);
            var module = $toggle.data('module');
            var enabled = $toggle.is(':checked');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'azure_toggle_module',
                    module: module,
                    enabled: enabled,
                    nonce: azureTickets.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $toggle.closest('.module-toggle-card').find('.module-status').text(enabled ? 'Enabled' : 'Disabled');
                    } else {
                        alert('Error: ' + response.data);
                        $toggle.prop('checked', !enabled);
                    }
                },
                error: function() {
                    alert('Error toggling module');
                    $toggle.prop('checked', !enabled);
                }
            });
        });
        
        // Delete venue
        $('.delete-venue').on('click', function() {
            var venueId = $(this).data('venue-id');
            
            if (!confirm(azureTickets.strings.confirmDelete)) {
                return;
            }
            
            var $card = $(this).closest('.venue-card');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'azure_tickets_delete_venue',
                    nonce: azureTickets.nonce,
                    venue_id: venueId
                },
                success: function(response) {
                    if (response.success) {
                        $card.fadeOut(300, function() {
                            $(this).remove();
                            
                            // Show empty state if no venues left
                            if ($('.venue-card').length === 0) {
                                $('.venues-grid').html(
                                    '<div class="no-venues">' +
                                    '<span class="dashicons dashicons-admin-home" style="font-size: 48px; width: 48px; height: 48px; color: #ccc;"></span>' +
                                    '<h3>No venues created yet</h3>' +
                                    '<p>Create your first venue layout to start selling tickets.</p>' +
                                    '<a href="?page=azure-plugin-tickets&tab=venues&action=new" class="button button-primary button-hero">Create Your First Venue</a>' +
                                    '</div>'
                                );
                            }
                        });
                    } else {
                        alert(response.data ? response.data.message : 'Delete failed');
                    }
                },
                error: function() {
                    alert('Network error. Please try again.');
                }
            });
        });
        
        // Duplicate venue
        $('.duplicate-venue').on('click', function() {
            var venueId = $(this).data('venue-id');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'azure_tickets_get_venue',
                    nonce: azureTickets.nonce,
                    venue_id: venueId
                },
                success: function(response) {
                    if (response.success && response.data.venue) {
                        var venue = response.data.venue;
                        // Redirect to new venue with layout pre-filled
                        var url = window.location.href.split('?')[0] + 
                                  '?page=azure-plugin-tickets&tab=venues&action=new' +
                                  '&duplicate=' + venueId;
                        window.location.href = url;
                    }
                }
            });
        });
        
        // Render mini layouts in venue cards
        $('.mini-layout').each(function() {
            var $container = $(this);
            var layoutData = $container.data('layout');
            
            if (typeof layoutData === 'string') {
                try {
                    layoutData = JSON.parse(layoutData);
                } catch (e) {
                    return;
                }
            }
            
            if (!layoutData || !layoutData.blocks) return;
            
            var canvasWidth = layoutData.canvas ? layoutData.canvas.width : 800;
            var canvasHeight = layoutData.canvas ? layoutData.canvas.height : 600;
            
            // Scale to fit container
            var containerWidth = $container.width() || 300;
            var containerHeight = $container.height() || 150;
            var scale = Math.min(containerWidth / canvasWidth, containerHeight / canvasHeight) * 0.8;
            
            var $canvas = $('<div>')
                .css({
                    position: 'relative',
                    width: canvasWidth + 'px',
                    height: canvasHeight + 'px',
                    transform: 'scale(' + scale + ')',
                    transformOrigin: 'center center',
                    background: '#fff'
                });
            
            layoutData.blocks.forEach(function(block) {
                var $block = $('<div>')
                    .css({
                        position: 'absolute',
                        left: block.x + 'px',
                        top: block.y + 'px',
                        width: block.width + 'px',
                        height: block.height + 'px',
                        backgroundColor: block.color || '#666',
                        borderRadius: '2px'
                    });
                
                $canvas.append($block);
            });
            
            $container.append($canvas);
        });
    });
    
})(jQuery);

