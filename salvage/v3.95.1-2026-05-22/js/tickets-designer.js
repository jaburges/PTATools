/**
 * Tickets Module - Venue/Seating Designer
 */
(function($) {
    'use strict';
    
    var VenueDesigner = {
        canvas: null,
        blocks: [],
        selectedBlock: null,
        blockIdCounter: 0,
        zoom: 1,
        isDragging: false,
        isResizing: false,
        dragOffset: { x: 0, y: 0 },
        
        init: function() {
            console.log('[VenueDesigner] Initializing...');
            this.canvas = $('#venue-canvas');
            
            if (!this.canvas.length) {
                console.log('[VenueDesigner] Canvas not found, exiting');
                return;
            }
            
            console.log('[VenueDesigner] Canvas found:', this.canvas.attr('id'));
            
            this.loadLayout();
            this.bindEvents();
            this.updateCapacity();
            
            console.log('[VenueDesigner] Initialization complete');
        },
        
        loadLayout: function() {
            var layoutData = this.canvas.data('layout');
            
            if (typeof layoutData === 'string') {
                try {
                    layoutData = JSON.parse(layoutData);
                } catch (e) {
                    layoutData = { canvas: { width: 800, height: 600 }, blocks: [] };
                }
            }
            
            // Set canvas size
            if (layoutData.canvas) {
                this.canvas.css({
                    width: layoutData.canvas.width + 'px',
                    height: layoutData.canvas.height + 'px'
                });
                $('#canvas-width').val(layoutData.canvas.width);
                $('#canvas-height').val(layoutData.canvas.height);
            }
            
            // Load blocks
            if (layoutData.blocks && layoutData.blocks.length) {
                layoutData.blocks.forEach(function(block) {
                    VenueDesigner.addBlock(block, false);
                });
            }
        },
        
        bindEvents: function() {
            var self = this;
            
            console.log('[VenueDesigner] Binding events, canvas found:', this.canvas.length > 0);
            console.log('[VenueDesigner] Block items found:', $('.block-item').length);
            
            // Click to add block (primary method - always works)
            $('.block-item').on('click', function(e) {
                e.preventDefault();
                var blockType = $(this).data('type');
                console.log('[VenueDesigner] Click to add:', blockType);
                
                // Add block at center of canvas
                var canvasWidth = parseInt($('#canvas-width').val()) || 800;
                var canvasHeight = parseInt($('#canvas-height').val()) || 600;
                
                self.addBlock({
                    type: blockType,
                    x: Math.round((canvasWidth / 2 - 100) / 20) * 20,
                    y: Math.round((canvasHeight / 2 - 40) / 20) * 20
                }, true);
            });
            
            // Store the block type being dragged
            var draggedBlockType = null;
            
            // Drag from palette - use native events for reliability
            document.querySelectorAll('.block-item').forEach(function(item) {
                item.addEventListener('dragstart', function(e) {
                    draggedBlockType = this.getAttribute('data-type');
                    console.log('[VenueDesigner] Drag started:', draggedBlockType);
                    e.dataTransfer.setData('text/plain', draggedBlockType);
                    e.dataTransfer.effectAllowed = 'copyMove';
                    this.classList.add('dragging');
                });
                
                item.addEventListener('dragend', function(e) {
                    console.log('[VenueDesigner] Drag ended');
                    this.classList.remove('dragging');
                    self.canvas.removeClass('drag-over');
                    draggedBlockType = null;
                });
            });
            
            // Drop on canvas - use native events
            var canvasEl = document.getElementById('venue-canvas');
            var containerEl = document.querySelector('.designer-canvas-container');
            
            if (canvasEl && containerEl) {
                // Dragover on container - MUST prevent default to allow drop
                containerEl.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.dataTransfer.dropEffect = 'copy';
                    self.canvas.addClass('drag-over');
                }, false);
                
                containerEl.addEventListener('dragenter', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    self.canvas.addClass('drag-over');
                }, false);
                
                containerEl.addEventListener('dragleave', function(e) {
                    e.preventDefault();
                    // Only remove if leaving container entirely
                    if (!containerEl.contains(e.relatedTarget)) {
                        self.canvas.removeClass('drag-over');
                    }
                }, false);
                
                containerEl.addEventListener('drop', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    self.canvas.removeClass('drag-over');
                    
                    var blockType = e.dataTransfer.getData('text/plain') || draggedBlockType;
                    console.log('[VenueDesigner] Drop received, blockType:', blockType);
                    
                    if (!blockType) {
                        console.log('[VenueDesigner] No block type in drop data');
                        return;
                    }
                    
                    var canvasRect = canvasEl.getBoundingClientRect();
                    var x = (e.clientX - canvasRect.left) / self.zoom;
                    var y = (e.clientY - canvasRect.top) / self.zoom;
                    
                    // Snap to grid (20px)
                    x = Math.round(x / 20) * 20;
                    y = Math.round(y / 20) * 20;
                    
                    // Ensure block is within canvas bounds
                    var canvasWidth = parseInt($('#canvas-width').val()) || 800;
                    var canvasHeight = parseInt($('#canvas-height').val()) || 600;
                    x = Math.max(0, Math.min(x - 75, canvasWidth - 150));
                    y = Math.max(0, Math.min(y - 30, canvasHeight - 60));
                    
                    console.log('[VenueDesigner] Adding block at:', x, y);
                    
                    self.addBlock({
                        type: blockType,
                        x: x,
                        y: y
                    }, true);
                }, false);
                
                console.log('[VenueDesigner] Native drop events bound to container');
            } else {
                console.error('[VenueDesigner] Canvas or container not found!');
            }
            
            // Select block
            this.canvas.on('click', '.venue-block', function(e) {
                e.stopPropagation();
                self.selectBlock($(this).data('blockId'));
            });
            
            // Deselect on canvas click
            this.canvas.on('click', function(e) {
                if (e.target === this) {
                    self.deselectBlock();
                }
            });
            
            // Drag block
            this.canvas.on('mousedown', '.venue-block', function(e) {
                if ($(e.target).hasClass('resize-handle')) return;
                
                self.isDragging = true;
                self.selectedBlock = $(this).data('blockId');
                
                var block = self.getBlock(self.selectedBlock);
                self.dragOffset = {
                    x: e.pageX - (self.canvas.offset().left + block.x * self.zoom),
                    y: e.pageY - (self.canvas.offset().top + block.y * self.zoom)
                };
                
                $(this).css('cursor', 'grabbing');
            });
            
            $(document).on('mousemove', function(e) {
                if (!self.isDragging || !self.selectedBlock) return;
                
                var canvasOffset = self.canvas.offset();
                var x = (e.pageX - canvasOffset.left - self.dragOffset.x) / self.zoom;
                var y = (e.pageY - canvasOffset.top - self.dragOffset.y) / self.zoom;
                
                // Snap to grid
                x = Math.round(x / 20) * 20;
                y = Math.round(y / 20) * 20;
                
                // Constrain to canvas
                x = Math.max(0, Math.min(x, self.canvas.width() - 50));
                y = Math.max(0, Math.min(y, self.canvas.height() - 30));
                
                self.updateBlockPosition(self.selectedBlock, x, y);
            });
            
            $(document).on('mouseup', function() {
                if (self.isDragging) {
                    self.isDragging = false;
                    $('.venue-block').css('cursor', 'move');
                }
            });
            
            // Resize handle
            this.canvas.on('mousedown', '.resize-handle', function(e) {
                e.stopPropagation();
                self.isResizing = true;
                self.selectedBlock = $(this).closest('.venue-block').data('blockId');
                self.selectBlock(self.selectedBlock);
            });
            
            $(document).on('mousemove', function(e) {
                if (!self.isResizing || !self.selectedBlock) return;
                
                var block = self.getBlock(self.selectedBlock);
                var $block = $('#block-' + self.selectedBlock);
                var canvasOffset = self.canvas.offset();
                
                var newWidth = (e.pageX - canvasOffset.left) / self.zoom - block.x;
                var newHeight = (e.pageY - canvasOffset.top) / self.zoom - block.y;
                
                // Snap to grid
                newWidth = Math.round(newWidth / 20) * 20;
                newHeight = Math.round(newHeight / 20) * 20;
                
                // Minimum size
                newWidth = Math.max(60, newWidth);
                newHeight = Math.max(40, newHeight);
                
                block.width = newWidth;
                block.height = newHeight;
                
                $block.css({
                    width: newWidth + 'px',
                    height: newHeight + 'px'
                });
            });
            
            $(document).on('mouseup', function() {
                self.isResizing = false;
            });
            
            // Settings changes
            $(document).on('input change', '.block-setting', function() {
                if (!self.selectedBlock) return;
                
                var setting = $(this).data('setting');
                var value = $(this).val();
                
                // Handle special cases
                if ($(this).attr('type') === 'number') {
                    value = parseFloat(value) || 0;
                }
                
                self.updateBlockSetting(self.selectedBlock, setting, value);
            });
            
            // Delete block
            $(document).on('click', '.delete-block', function() {
                if (self.selectedBlock) {
                    self.deleteBlock(self.selectedBlock);
                }
            });
            
            // Canvas size
            $('#canvas-width, #canvas-height').on('change', function() {
                self.canvas.css({
                    width: $('#canvas-width').val() + 'px',
                    height: $('#canvas-height').val() + 'px'
                });
            });
            
            // Zoom controls
            $('#zoom-in').on('click', function() {
                self.setZoom(self.zoom + 0.1);
            });
            
            $('#zoom-out').on('click', function() {
                self.setZoom(self.zoom - 0.1);
            });
            
            $('#zoom-reset').on('click', function() {
                self.setZoom(1);
            });
            
            // Save venue
            $('#save-venue').on('click', function() {
                self.saveVenue();
            });
            
            // Keyboard shortcuts
            $(document).on('keydown', function(e) {
                if (!self.selectedBlock) return;
                
                // Delete key
                if (e.key === 'Delete' || e.key === 'Backspace') {
                    // Don't delete if typing in input
                    if ($(e.target).is('input, textarea, select')) return;
                    
                    e.preventDefault();
                    self.deleteBlock(self.selectedBlock);
                }
                
                // Arrow keys for nudging
                if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
                    if ($(e.target).is('input, textarea')) return;
                    
                    e.preventDefault();
                    var block = self.getBlock(self.selectedBlock);
                    var step = e.shiftKey ? 20 : 5;
                    
                    switch (e.key) {
                        case 'ArrowUp': block.y -= step; break;
                        case 'ArrowDown': block.y += step; break;
                        case 'ArrowLeft': block.x -= step; break;
                        case 'ArrowRight': block.x += step; break;
                    }
                    
                    self.updateBlockPosition(self.selectedBlock, block.x, block.y);
                }
            });
        },
        
        addBlock: function(data, select) {
            var id = 'block-' + (++this.blockIdCounter);
            
            var defaults = this.getBlockDefaults(data.type);
            var block = $.extend({}, defaults, data, { id: id });
            
            this.blocks.push(block);
            this.renderBlock(block);
            
            if (select) {
                this.selectBlock(id);
            }
            
            this.updateCapacity();
        },
        
        getBlockDefaults: function(type) {
            switch (type) {
                case 'rectangle':
                    return {
                        type: 'rectangle',
                        name: 'Section',
                        price: 25,
                        rowCount: 2,
                        seatsPerRow: 10,
                        startRow: 'A',
                        selectionMode: 'exact',
                        color: '#4CAF50',
                        width: 200,
                        height: 80
                    };
                case 'general_admission':
                    return {
                        type: 'general_admission',
                        name: 'General Admission',
                        price: 15,
                        capacity: 100,
                        color: '#FF9800',
                        width: 200,
                        height: 100
                    };
                case 'stage':
                    return {
                        type: 'stage',
                        label: 'STAGE',
                        color: '#333333',
                        width: 400,
                        height: 60
                    };
                case 'label':
                    return {
                        type: 'label',
                        text: 'Label',
                        fontSize: 14,
                        color: '#333333',
                        width: 100,
                        height: 30
                    };
                case 'aisle':
                    return {
                        type: 'aisle',
                        orientation: 'horizontal',
                        width: 200,
                        height: 20
                    };
                default:
                    return {
                        width: 100,
                        height: 50
                    };
            }
        },
        
        renderBlock: function(block) {
            var $block = $('<div>')
                .attr('id', block.id)
                .addClass('venue-block type-' + block.type)
                .data('blockId', block.id)
                .css({
                    left: block.x + 'px',
                    top: block.y + 'px',
                    width: block.width + 'px',
                    height: block.height + 'px',
                    backgroundColor: block.color || '#666'
                });
            
            // Block content
            var label = '';
            switch (block.type) {
                case 'rectangle':
                    var totalSeats = (block.rowCount || 2) * (block.seatsPerRow || 10);
                    label = '<div class="block-label">' + (block.name || 'Section') + 
                            '<div class="block-seats">' + totalSeats + ' seats</div></div>';
                    break;
                case 'general_admission':
                    label = '<div class="block-label">' + (block.name || 'GA') + 
                            '<div class="block-seats">' + (block.capacity || 0) + ' capacity</div></div>';
                    break;
                case 'stage':
                    label = '<div class="block-label">' + (block.label || 'STAGE') + '</div>';
                    break;
                case 'label':
                    $block.css({
                        fontSize: (block.fontSize || 14) + 'px',
                        color: block.color || '#333'
                    });
                    label = '<div class="block-label">' + (block.text || 'Label') + '</div>';
                    break;
                case 'aisle':
                    label = '';
                    if (block.orientation === 'vertical') {
                        $block.css({
                            width: '20px',
                            height: block.height + 'px'
                        });
                    }
                    break;
            }
            
            $block.html(label);
            
            // Add resize handle
            $block.append('<div class="resize-handle se"></div>');
            
            this.canvas.append($block);
        },
        
        selectBlock: function(blockId) {
            this.deselectBlock();
            this.selectedBlock = blockId;
            
            $('#' + blockId).addClass('selected');
            
            var block = this.getBlock(blockId);
            this.showBlockSettings(block);
        },
        
        deselectBlock: function() {
            if (this.selectedBlock) {
                $('#' + this.selectedBlock).removeClass('selected');
            }
            this.selectedBlock = null;
            $('#block-settings-panel').html('<p class="no-selection">Select a block to edit its settings</p>');
        },
        
        showBlockSettings: function(block) {
            var $template = $('#' + block.type + '-settings');
            if (!$template.length) {
                $template = $('#label-settings'); // fallback
            }
            
            var html = $template.html();
            $('#block-settings-panel').html(html);
            
            // Populate values
            for (var key in block) {
                var $input = $('.block-setting[data-setting="' + key + '"]');
                if ($input.length) {
                    $input.val(block[key]);
                }
            }
        },
        
        getBlock: function(blockId) {
            return this.blocks.find(function(b) { return b.id === blockId; });
        },
        
        updateBlockPosition: function(blockId, x, y) {
            var block = this.getBlock(blockId);
            block.x = x;
            block.y = y;
            
            $('#' + blockId).css({
                left: x + 'px',
                top: y + 'px'
            });
        },
        
        updateBlockSetting: function(blockId, setting, value) {
            var block = this.getBlock(blockId);
            var $block = $('#' + blockId);
            
            block[setting] = value;
            
            // Update visual
            switch (setting) {
                case 'color':
                    if (block.type !== 'label') {
                        $block.css('backgroundColor', value);
                    } else {
                        $block.css('color', value);
                    }
                    break;
                case 'name':
                case 'label':
                case 'text':
                    this.updateBlockLabel($block, block);
                    break;
                case 'rowCount':
                case 'seatsPerRow':
                case 'capacity':
                    this.updateBlockLabel($block, block);
                    this.updateCapacity();
                    break;
                case 'fontSize':
                    $block.css('fontSize', value + 'px');
                    break;
                case 'orientation':
                    if (value === 'vertical') {
                        var h = block.height;
                        block.width = 20;
                        block.height = h > 20 ? h : 200;
                    } else {
                        var w = block.width;
                        block.height = 20;
                        block.width = w > 20 ? w : 200;
                    }
                    $block.css({
                        width: block.width + 'px',
                        height: block.height + 'px'
                    });
                    break;
            }
        },
        
        updateBlockLabel: function($block, block) {
            var label = '';
            switch (block.type) {
                case 'rectangle':
                    var totalSeats = (block.rowCount || 2) * (block.seatsPerRow || 10);
                    label = '<div class="block-label">' + (block.name || 'Section') + 
                            '<div class="block-seats">' + totalSeats + ' seats</div></div>';
                    break;
                case 'general_admission':
                    label = '<div class="block-label">' + (block.name || 'GA') + 
                            '<div class="block-seats">' + (block.capacity || 0) + ' capacity</div></div>';
                    break;
                case 'stage':
                    label = '<div class="block-label">' + (block.label || 'STAGE') + '</div>';
                    break;
                case 'label':
                    label = '<div class="block-label">' + (block.text || 'Label') + '</div>';
                    break;
            }
            
            $block.find('.block-label').remove();
            $block.prepend(label);
        },
        
        deleteBlock: function(blockId) {
            var index = this.blocks.findIndex(function(b) { return b.id === blockId; });
            if (index > -1) {
                this.blocks.splice(index, 1);
            }
            
            $('#' + blockId).remove();
            this.deselectBlock();
            this.updateCapacity();
        },
        
        updateCapacity: function() {
            var total = 0;
            
            this.blocks.forEach(function(block) {
                if (block.type === 'rectangle') {
                    total += (block.rowCount || 0) * (block.seatsPerRow || 0);
                } else if (block.type === 'general_admission') {
                    total += block.capacity || 0;
                }
            });
            
            $('#total-capacity').text(total);
        },
        
        setZoom: function(level) {
            this.zoom = Math.max(0.5, Math.min(2, level));
            this.canvas.css('transform', 'scale(' + this.zoom + ')');
            $('#zoom-level').text(Math.round(this.zoom * 100) + '%');
        },
        
        getLayoutJSON: function() {
            var layout = {
                canvas: {
                    width: parseInt($('#canvas-width').val()) || 800,
                    height: parseInt($('#canvas-height').val()) || 600
                },
                blocks: []
            };
            
            this.blocks.forEach(function(block) {
                var cleanBlock = $.extend({}, block);
                delete cleanBlock.id; // Remove internal ID
                layout.blocks.push(cleanBlock);
            });
            
            return JSON.stringify(layout);
        },
        
        saveVenue: function() {
            var self = this;
            var $btn = $('#save-venue');
            var originalText = $btn.html();
            var venueId = this.canvas.data('venueId') || 0;
            
            // Validate name for new venues
            if (!venueId && !$('#venue-name').val().trim()) {
                alert('Please enter a venue name');
                $('#venue-name').focus();
                return;
            }
            
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Saving...');
            
            // Use azureTicketsDesigner if available, fall back to azureTickets
            var ticketsData = (typeof azureTicketsDesigner !== 'undefined') ? azureTicketsDesigner : azureTickets;
            
            var data = {
                action: 'azure_tickets_save_venue',
                nonce: ticketsData.nonce,
                venue_id: venueId,
                layout_json: this.getLayoutJSON()
            };
            
            // Include venue details only for new venues (TEC venue creation)
            if (!venueId) {
                data.name = $('#venue-name').val().trim();
                data.address = $('#venue-address').val().trim();
                data.city = $('#venue-city').val().trim();
            }
            
            console.log('[VenueDesigner] Saving venue:', data);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: data,
                success: function(response) {
                    console.log('[VenueDesigner] Save response:', response);
                    $btn.prop('disabled', false);
                    
                    if (response.success) {
                        $btn.html('<span class="dashicons dashicons-yes"></span> Saved!');
                        
                        // Update venue ID for future saves
                        self.canvas.data('venueId', response.data.venue_id);
                        
                        // Update URL if new venue - redirect to edit mode
                        if (!venueId && response.data.venue_id) {
                            var baseUrl = window.location.pathname + window.location.search;
                            // Replace action=new with action=edit and add venue_id
                            var newUrl = baseUrl.replace('action=new', 'action=edit') + '&venue_id=' + response.data.venue_id;
                            window.location.href = newUrl;
                            return;
                        }
                        
                        setTimeout(function() {
                            $btn.html(originalText);
                        }, 2000);
                    } else {
                        $btn.html('<span class="dashicons dashicons-no"></span> Error');
                        alert(response.data ? response.data.message : 'Save failed');
                        setTimeout(function() {
                            $btn.html(originalText);
                        }, 2000);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[VenueDesigner] Save error:', status, error);
                    $btn.prop('disabled', false).html(originalText);
                    alert('Network error. Please try again.');
                }
            });
        }
    };
    
    // Initialize
    $(document).ready(function() {
        VenueDesigner.init();
    });
    
})(jQuery);

