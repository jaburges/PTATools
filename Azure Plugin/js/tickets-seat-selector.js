/**
 * Tickets Module - Seat Selector (Frontend)
 */
(function($) {
    'use strict';
    
    var SeatSelector = {
        productId: 0,
        venueId: 0,
        layout: null,
        unavailableSeats: {},
        selectedSeats: [],
        maxTickets: 10,
        requireNames: false,
        sectionPrices: {},
        currencySymbol: '$',
        
        init: function() {
            var $container = $('.seat-selector-container');
            if (!$container.length) return;
            
            this.productId = $container.data('product-id');
            this.venueId = $container.data('venue-id');
            this.maxTickets = $container.data('max-tickets') || 10;
            this.requireNames = $container.data('require-names') == '1';
            
            // Get currency from WooCommerce if available
            if (typeof wc_add_to_cart_params !== 'undefined') {
                this.currencySymbol = wc_add_to_cart_params.currency_symbol || '$';
            }
            
            this.loadLayout();
            this.bindEvents();
        },
        
        loadLayout: function() {
            var self = this;
            
            $.ajax({
                url: azureTicketsFrontend.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'azure_tickets_get_availability',
                    product_id: this.productId,
                    nonce: azureTicketsFrontend.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.layout = response.data.layout;
                        self.unavailableSeats = response.data.unavailable || {};
                        self.extractPrices();
                        self.renderLayout();
                    } else {
                        self.showError(response.data ? response.data.message : 'Error loading seats');
                    }
                },
                error: function() {
                    self.showError('Network error loading seats');
                }
            });
        },
        
        extractPrices: function() {
            var self = this;
            if (this.layout && this.layout.blocks) {
                this.layout.blocks.forEach(function(block) {
                    if (block.type === 'rectangle' || block.type === 'general_admission') {
                        self.sectionPrices[block.id || block.name] = parseFloat(block.price) || 0;
                    }
                });
            }
        },
        
        renderLayout: function() {
            var self = this;
            var $seatMap = $('.seat-map');
            var $loading = $('.loading-seats');
            
            if (!this.layout || !this.layout.blocks) {
                this.showError('No seating layout configured');
                return;
            }
            
            // Calculate scale
            var canvasWidth = this.layout.canvas ? this.layout.canvas.width : 800;
            var canvasHeight = this.layout.canvas ? this.layout.canvas.height : 600;
            var containerWidth = $seatMap.parent().width() - 40;
            var scale = Math.min(1, containerWidth / canvasWidth);
            
            var $canvas = $('<div class="seat-canvas">')
                .css({
                    position: 'relative',
                    width: canvasWidth * scale + 'px',
                    height: canvasHeight * scale + 'px',
                    margin: '0 auto'
                });
            
            // Render blocks
            this.layout.blocks.forEach(function(block) {
                self.renderBlock($canvas, block, scale);
            });
            
            // Add legend
            var $legend = $('<div class="seat-legend">' +
                '<div class="legend-item"><span class="legend-color available"></span> Available</div>' +
                '<div class="legend-item"><span class="legend-color selected"></span> Selected</div>' +
                '<div class="legend-item"><span class="legend-color sold"></span> Sold</div>' +
                '</div>');
            
            $loading.hide();
            $seatMap.empty().append($canvas).append($legend).show();
            
            // Initial summary
            this.updateSummary();
        },
        
        renderBlock: function($canvas, block, scale) {
            var self = this;
            
            // Skip non-purchasable blocks
            if (block.type === 'stage' || block.type === 'aisle' || block.type === 'label') {
                var $block = $('<div>')
                    .addClass('stage-block')
                    .css({
                        position: 'absolute',
                        left: (block.x * scale) + 'px',
                        top: (block.y * scale) + 'px',
                        width: (block.width * scale) + 'px',
                        height: (block.height * scale) + 'px',
                        background: block.color || '#333',
                        color: '#fff',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        borderRadius: '4px',
                        fontSize: (12 * scale) + 'px'
                    })
                    .text(block.label || block.text || '');
                
                $canvas.append($block);
                return;
            }
            
            // Rectangle sections with individual seats
            if (block.type === 'rectangle') {
                this.renderSeatingSection($canvas, block, scale);
            }
            
            // General admission
            if (block.type === 'general_admission') {
                this.renderGASection($canvas, block, scale);
            }
        },
        
        renderSeatingSection: function($canvas, block, scale) {
            var self = this;
            var sectionId = block.id || 'section-' + Math.random().toString(36).substr(2, 9);
            block.id = sectionId;
            
            var $section = $('<div class="seating-section">')
                .attr('data-section-id', sectionId)
                .css({
                    left: (block.x * scale) + 'px',
                    top: (block.y * scale) + 'px',
                    width: (block.width * scale) + 'px',
                    minHeight: (block.height * scale) + 'px',
                    background: block.color || '#4CAF50',
                    padding: (10 * scale) + 'px'
                });
            
            // Section header
            var $header = $('<div class="section-name">')
                .text(block.name || 'Section')
                .css('fontSize', (14 * scale) + 'px');
            $section.append($header);
            
            // Price
            var price = block.price || 0;
            var $price = $('<div class="section-price">')
                .text(self.formatPrice(price))
                .css('fontSize', (12 * scale) + 'px');
            $section.append($price);
            
            // Generate seat rows
            var rows = block.rows || this.generateRowLetters(block.startRow || 'A', block.rowCount || 2);
            var seatsPerRow = block.seatsPerRow || 10;
            
            var $seatsContainer = $('<div class="seats-container">').css('marginTop', '10px');
            
            rows.forEach(function(row) {
                var $row = $('<div class="seat-row">');
                
                // Row label
                $row.append($('<span class="row-label">').text(row).css('fontSize', (10 * scale) + 'px'));
                
                for (var seat = 1; seat <= seatsPerRow; seat++) {
                    var seatKey = sectionId + '-' + row + '-' + seat;
                    var status = self.unavailableSeats[seatKey] || 'available';
                    
                    var $seat = $('<div class="seat">')
                        .addClass(status)
                        .attr('data-section', sectionId)
                        .attr('data-row', row)
                        .attr('data-seat', seat)
                        .attr('data-price', price)
                        .text(seat)
                        .css({
                            width: (20 * scale) + 'px',
                            height: (20 * scale) + 'px',
                            fontSize: (8 * scale) + 'px'
                        });
                    
                    if (status === 'available') {
                        $seat.on('click', function() {
                            self.toggleSeat($(this));
                        });
                    }
                    
                    $row.append($seat);
                }
                
                // Row label on right
                $row.append($('<span class="row-label">').text(row).css('fontSize', (10 * scale) + 'px'));
                
                $seatsContainer.append($row);
            });
            
            $section.append($seatsContainer);
            $canvas.append($section);
        },
        
        renderGASection: function($canvas, block, scale) {
            var self = this;
            var sectionId = block.id || 'ga-' + Math.random().toString(36).substr(2, 9);
            block.id = sectionId;
            
            // Count available
            var capacity = block.capacity || 100;
            var sold = 0;
            // Count sold GA tickets for this section
            for (var key in this.unavailableSeats) {
                if (key.startsWith(sectionId)) {
                    sold++;
                }
            }
            var available = Math.max(0, capacity - sold);
            
            var $section = $('<div class="seating-section ga-section">')
                .attr('data-section-id', sectionId)
                .attr('data-type', 'ga')
                .attr('data-available', available)
                .attr('data-price', block.price || 0)
                .css({
                    left: (block.x * scale) + 'px',
                    top: (block.y * scale) + 'px',
                    width: (block.width * scale) + 'px',
                    height: (block.height * scale) + 'px',
                    background: block.color || '#FF9800',
                    cursor: available > 0 ? 'pointer' : 'not-allowed',
                    opacity: available > 0 ? 1 : 0.6
                });
            
            var $header = $('<div class="section-name">')
                .text(block.name || 'General Admission')
                .css('fontSize', (14 * scale) + 'px');
            
            var $price = $('<div class="section-price">')
                .text(self.formatPrice(block.price || 0))
                .css('fontSize', (12 * scale) + 'px');
            
            var $availability = $('<div class="section-availability">')
                .text(available + ' available')
                .css('fontSize', (11 * scale) + 'px');
            
            $section.append($header, $price, $availability);
            
            if (available > 0) {
                $section.on('click', function() {
                    self.showGASelector($(this), block);
                });
            }
            
            $canvas.append($section);
        },
        
        generateRowLetters: function(startRow, count) {
            var rows = [];
            var startCode = startRow.charCodeAt(0);
            for (var i = 0; i < count; i++) {
                rows.push(String.fromCharCode(startCode + i));
            }
            return rows;
        },
        
        toggleSeat: function($seat) {
            var seatData = {
                section_id: $seat.data('section'),
                row: $seat.data('row'),
                seat: $seat.data('seat'),
                price: parseFloat($seat.data('price')) || 0
            };
            
            var seatKey = seatData.section_id + '-' + seatData.row + '-' + seatData.seat;
            
            if ($seat.hasClass('selected')) {
                // Deselect
                $seat.removeClass('selected').addClass('available');
                this.selectedSeats = this.selectedSeats.filter(function(s) {
                    return !(s.section_id === seatData.section_id && s.row === seatData.row && s.seat === seatData.seat);
                });
            } else {
                // Check max tickets
                if (this.selectedSeats.length >= this.maxTickets) {
                    alert('Maximum ' + this.maxTickets + ' tickets per order.');
                    return;
                }
                
                // Select
                $seat.removeClass('available').addClass('selected');
                this.selectedSeats.push(seatData);
            }
            
            this.updateSummary();
        },
        
        showGASelector: function($section, block) {
            var self = this;
            var available = parseInt($section.data('available')) || 0;
            var price = parseFloat($section.data('price')) || 0;
            var sectionId = $section.data('section-id');
            
            // Already selected from this section?
            var existingCount = this.selectedSeats.filter(function(s) {
                return s.section_id === sectionId;
            }).length;
            
            var maxCanAdd = Math.min(available - existingCount, this.maxTickets - this.selectedSeats.length);
            
            if (maxCanAdd <= 0) {
                alert('Maximum tickets reached.');
                return;
            }
            
            // Simple prompt for quantity
            var quantity = prompt('How many tickets? (1-' + maxCanAdd + ')', '1');
            quantity = parseInt(quantity) || 0;
            
            if (quantity < 1 || quantity > maxCanAdd) {
                if (quantity > 0) alert('Please enter a number between 1 and ' + maxCanAdd);
                return;
            }
            
            // Add GA tickets
            for (var i = 0; i < quantity; i++) {
                this.selectedSeats.push({
                    section_id: sectionId,
                    row: '',
                    seat: 'GA-' + (existingCount + i + 1),
                    price: price,
                    is_ga: true
                });
            }
            
            this.updateSummary();
        },
        
        updateSummary: function() {
            var self = this;
            var $list = $('.selected-seats-list');
            var $total = $('.total-amount');
            var $submitBtn = $('.ticket-add-to-cart-form button[type="submit"]');
            var $attendeeNames = $('.attendee-names');
            var $namesInputs = $('.names-inputs');
            
            $list.empty();
            
            if (this.selectedSeats.length === 0) {
                $list.html('<p class="no-seats-selected">' + azureTicketsFrontend.strings.selectSeats + '</p>');
                $total.text(this.formatPrice(0));
                $submitBtn.prop('disabled', true);
                $attendeeNames.hide();
                return;
            }
            
            var total = 0;
            
            this.selectedSeats.forEach(function(seat, index) {
                var seatLabel = seat.is_ga ? 'General Admission' : 'Row ' + seat.row + ', Seat ' + seat.seat;
                var sectionName = self.getSectionName(seat.section_id);
                
                var $item = $('<div class="selected-seat-item">' +
                    '<div class="seat-info">' +
                        '<span class="seat-label">' + seatLabel + '</span>' +
                        '<span class="section-label">' + sectionName + '</span>' +
                    '</div>' +
                    '<span class="seat-price">' + self.formatPrice(seat.price) + '</span>' +
                    '<button type="button" class="remove-seat" data-index="' + index + '">&times;</button>' +
                    '</div>');
                
                $list.append($item);
                total += seat.price;
            });
            
            // Bind remove buttons
            $list.find('.remove-seat').on('click', function() {
                var index = parseInt($(this).data('index'));
                var seat = self.selectedSeats[index];
                
                // Update visual
                if (!seat.is_ga) {
                    var $seat = $('.seat[data-section="' + seat.section_id + '"][data-row="' + seat.row + '"][data-seat="' + seat.seat + '"]');
                    $seat.removeClass('selected').addClass('available');
                }
                
                self.selectedSeats.splice(index, 1);
                self.updateSummary();
            });
            
            $total.text(this.formatPrice(total));
            $submitBtn.prop('disabled', false);
            
            // Attendee names
            if (this.requireNames) {
                $namesInputs.empty();
                this.selectedSeats.forEach(function(seat, index) {
                    var label = seat.is_ga ? 'GA Ticket ' + (index + 1) : seat.row + seat.seat;
                    var $group = $('<div class="name-input-group">' +
                        '<label>' + label + '</label>' +
                        '<input type="text" name="attendee_names[' + index + ']" placeholder="Attendee name" required>' +
                        '</div>');
                    $namesInputs.append($group);
                });
                $attendeeNames.show();
            }
            
            // Update hidden field
            $('input[name="selected_seats"]').val(JSON.stringify(this.selectedSeats));
        },
        
        getSectionName: function(sectionId) {
            if (!this.layout || !this.layout.blocks) return sectionId;
            
            var block = this.layout.blocks.find(function(b) {
                return b.id === sectionId;
            });
            
            return block ? (block.name || sectionId) : sectionId;
        },
        
        formatPrice: function(amount) {
            return this.currencySymbol + parseFloat(amount).toFixed(2);
        },
        
        showError: function(message) {
            $('.loading-seats').html('<p style="color: #d63638;">' + message + '</p>');
        },
        
        bindEvents: function() {
            var self = this;
            
            // Form submission
            $('.ticket-add-to-cart-form').on('submit', function(e) {
                e.preventDefault();
                
                if (self.selectedSeats.length === 0) {
                    alert(azureTicketsFrontend.strings.selectSeats);
                    return;
                }
                
                var $btn = $(this).find('button[type="submit"]');
                var originalText = $btn.text();
                $btn.prop('disabled', true).text('Adding...');
                
                // Collect attendee names
                var attendeeNames = [];
                $(this).find('input[name^="attendee_names"]').each(function() {
                    attendeeNames.push($(this).val());
                });
                
                $.ajax({
                    url: azureTicketsFrontend.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'azure_tickets_add_to_cart',
                        product_id: self.productId,
                        selected_seats: JSON.stringify(self.selectedSeats),
                        attendee_names: attendeeNames,
                        nonce: azureTicketsFrontend.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            window.location.href = response.data.cart_url;
                        } else {
                            alert(response.data ? response.data.message : 'Error adding to cart');
                            $btn.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function() {
                        alert('Network error. Please try again.');
                        $btn.prop('disabled', false).text(originalText);
                    }
                });
            });
        }
    };
    
    // Initialize on page load
    $(document).ready(function() {
        SeatSelector.init();
    });
    
})(jQuery);

