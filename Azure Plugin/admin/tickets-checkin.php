<?php
/**
 * Tickets Module - Check-in Scanner Page
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get available events with tickets
global $wpdb;
$tickets_table = $wpdb->prefix . 'azure_tickets';

$events = $wpdb->get_results("
    SELECT DISTINCT p.ID, p.post_title, 
           COUNT(t.id) as total_tickets,
           SUM(CASE WHEN t.status = 'used' THEN 1 ELSE 0 END) as checked_in
    FROM {$wpdb->posts} p
    INNER JOIN {$tickets_table} t ON p.ID = t.product_id
    WHERE p.post_type = 'product'
    GROUP BY p.ID
    ORDER BY p.post_title
");

$settings = Azure_Settings::get_all_settings();
?>

<div class="wrap azure-tickets-checkin">
    <h1>
        <span class="dashicons dashicons-smartphone"></span>
        <?php _e('Ticket Check-in', 'azure-plugin'); ?>
    </h1>
    
    <div class="checkin-container">
        <!-- Event Selector -->
        <div class="event-selector">
            <label for="checkin-event"><?php _e('Select Event:', 'azure-plugin'); ?></label>
            <select id="checkin-event">
                <option value=""><?php _e('All Events', 'azure-plugin'); ?></option>
                <?php foreach ($events as $event): ?>
                <option value="<?php echo $event->ID; ?>" 
                        data-total="<?php echo $event->total_tickets; ?>"
                        data-checked="<?php echo $event->checked_in; ?>">
                    <?php echo esc_html($event->post_title); ?> 
                    (<?php echo $event->checked_in; ?>/<?php echo $event->total_tickets; ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- Stats Bar -->
        <div class="checkin-stats">
            <div class="stat">
                <span class="stat-number" id="stats-total">0</span>
                <span class="stat-label"><?php _e('Total Tickets', 'azure-plugin'); ?></span>
            </div>
            <div class="stat">
                <span class="stat-number" id="stats-checked">0</span>
                <span class="stat-label"><?php _e('Checked In', 'azure-plugin'); ?></span>
            </div>
            <div class="stat">
                <span class="stat-number" id="stats-remaining">0</span>
                <span class="stat-label"><?php _e('Remaining', 'azure-plugin'); ?></span>
            </div>
        </div>
        
        <!-- Scanner Section -->
        <div class="scanner-section">
            <div class="scanner-tabs">
                <button type="button" class="tab-btn active" data-tab="camera">
                    <span class="dashicons dashicons-camera"></span>
                    <?php _e('Camera Scan', 'azure-plugin'); ?>
                </button>
                <button type="button" class="tab-btn" data-tab="manual">
                    <span class="dashicons dashicons-edit"></span>
                    <?php _e('Manual Entry', 'azure-plugin'); ?>
                </button>
            </div>
            
            <div class="scanner-content">
                <!-- Camera Scanner -->
                <div class="tab-content active" id="tab-camera">
                    <div id="qr-reader"></div>
                    <div class="scanner-controls">
                        <button type="button" id="start-scanner" class="button button-primary button-hero">
                            <span class="dashicons dashicons-camera"></span>
                            <?php _e('Start Scanner', 'azure-plugin'); ?>
                        </button>
                        <button type="button" id="stop-scanner" class="button button-hero" style="display: none;">
                            <span class="dashicons dashicons-no"></span>
                            <?php _e('Stop Scanner', 'azure-plugin'); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Manual Entry -->
                <div class="tab-content" id="tab-manual">
                    <div class="manual-entry">
                        <label for="manual-code"><?php _e('Enter Ticket Code:', 'azure-plugin'); ?></label>
                        <div class="input-group">
                            <input type="text" id="manual-code" placeholder="XXXXXXXX" maxlength="8" 
                                   autocomplete="off" autocapitalize="characters">
                            <button type="button" id="check-manual" class="button button-primary">
                                <?php _e('Check In', 'azure-plugin'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Result Display -->
        <div id="scan-result" class="scan-result" style="display: none;">
            <div class="result-icon"></div>
            <div class="result-message"></div>
            <div class="result-details"></div>
        </div>
        
        <!-- Recent Check-ins -->
        <div class="recent-checkins">
            <h3><?php _e('Recent Check-ins', 'azure-plugin'); ?></h3>
            <div id="recent-list">
                <p class="no-checkins"><?php _e('No check-ins yet', 'azure-plugin'); ?></p>
            </div>
        </div>
    </div>
</div>

<style>
.azure-tickets-checkin {
    max-width: 600px;
    margin: 20px auto;
}

.azure-tickets-checkin h1 {
    display: flex;
    align-items: center;
    gap: 10px;
    justify-content: center;
}

.checkin-container {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.event-selector {
    padding: 20px;
    background: #f6f7f7;
    border-bottom: 1px solid #dcdcde;
}

.event-selector label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
}

.event-selector select {
    width: 100%;
    padding: 10px;
    font-size: 16px;
}

.checkin-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    padding: 20px;
    background: #1d2327;
    color: #fff;
    text-align: center;
}

.checkin-stats .stat-number {
    display: block;
    font-size: 32px;
    font-weight: 700;
}

.checkin-stats .stat-label {
    font-size: 12px;
    text-transform: uppercase;
    opacity: 0.8;
}

.scanner-section {
    padding: 20px;
}

.scanner-tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.tab-btn {
    flex: 1;
    padding: 12px;
    border: 2px solid #dcdcde;
    background: #fff;
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 14px;
    transition: all 0.2s;
}

.tab-btn:hover {
    border-color: #2271b1;
}

.tab-btn.active {
    background: #2271b1;
    border-color: #2271b1;
    color: #fff;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

#qr-reader {
    width: 100%;
    max-width: 400px;
    margin: 0 auto;
    border-radius: 8px;
    overflow: hidden;
}

.scanner-controls {
    text-align: center;
    margin-top: 20px;
}

.button-hero {
    padding: 15px 40px !important;
    font-size: 16px !important;
    height: auto !important;
}

.manual-entry {
    max-width: 400px;
    margin: 0 auto;
}

.manual-entry label {
    display: block;
    margin-bottom: 10px;
    font-weight: 600;
}

.input-group {
    display: flex;
    gap: 10px;
}

.input-group input {
    flex: 1;
    padding: 15px;
    font-size: 24px;
    text-align: center;
    letter-spacing: 4px;
    text-transform: uppercase;
    font-family: monospace;
}

.input-group button {
    padding: 15px 25px !important;
    height: auto !important;
}

.scan-result {
    margin: 20px;
    padding: 30px;
    border-radius: 12px;
    text-align: center;
}

.scan-result.success {
    background: #d7f0e5;
    border: 2px solid #00a32a;
}

.scan-result.error {
    background: #fce4e4;
    border: 2px solid #d63638;
}

.scan-result.warning {
    background: #fff8e5;
    border: 2px solid #dba617;
}

.result-icon {
    font-size: 48px;
    margin-bottom: 10px;
}

.scan-result.success .result-icon::before {
    content: "✓";
    color: #00a32a;
}

.scan-result.error .result-icon::before {
    content: "✗";
    color: #d63638;
}

.scan-result.warning .result-icon::before {
    content: "⚠";
    color: #dba617;
}

.result-message {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 10px;
}

.result-details {
    font-size: 14px;
    color: #666;
}

.recent-checkins {
    padding: 20px;
    border-top: 1px solid #dcdcde;
}

.recent-checkins h3 {
    margin: 0 0 15px;
    font-size: 14px;
    text-transform: uppercase;
    color: #666;
}

.no-checkins {
    text-align: center;
    color: #999;
    padding: 20px;
}

.checkin-item {
    display: flex;
    align-items: center;
    padding: 10px;
    background: #f6f7f7;
    border-radius: 6px;
    margin-bottom: 8px;
}

.checkin-item .attendee {
    flex: 1;
}

.checkin-item .attendee strong {
    display: block;
}

.checkin-item .attendee small {
    color: #666;
}

.checkin-item .time {
    font-size: 12px;
    color: #999;
}

/* Mobile optimizations */
@media (max-width: 600px) {
    .azure-tickets-checkin {
        margin: 10px;
    }
    
    .checkin-stats .stat-number {
        font-size: 24px;
    }
    
    .scanner-tabs {
        flex-direction: column;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    var html5QrcodeScanner = null;
    var isScanning = false;
    var settings = {
        sounds: <?php echo ($settings['tickets_checkin_sounds'] ?? true) ? 'true' : 'false'; ?>,
        autoContinue: <?php echo ($settings['tickets_auto_continue'] ?? true) ? 'true' : 'false'; ?>
    };
    
    // Tab switching
    $('.tab-btn').on('click', function() {
        var tab = $(this).data('tab');
        $('.tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.tab-content').removeClass('active');
        $('#tab-' + tab).addClass('active');
    });
    
    // Update stats when event changes
    $('#checkin-event').on('change', function() {
        var $option = $(this).find(':selected');
        var total = $option.data('total') || 0;
        var checked = $option.data('checked') || 0;
        
        $('#stats-total').text(total);
        $('#stats-checked').text(checked);
        $('#stats-remaining').text(total - checked);
    }).trigger('change');
    
    // Start camera scanner
    $('#start-scanner').on('click', function() {
        if (typeof Html5Qrcode === 'undefined') {
            alert('QR scanner library not loaded');
            return;
        }
        
        $(this).hide();
        $('#stop-scanner').show();
        
        html5QrcodeScanner = new Html5Qrcode("qr-reader");
        
        html5QrcodeScanner.start(
            { facingMode: "environment" },
            {
                fps: 10,
                qrbox: { width: 250, height: 250 }
            },
            function(decodedText) {
                // QR code scanned
                processTicket(decodedText);
                
                if (!settings.autoContinue) {
                    html5QrcodeScanner.stop();
                    $('#start-scanner').show();
                    $('#stop-scanner').hide();
                }
            },
            function(error) {
                // Scan error - ignore
            }
        ).catch(function(err) {
            alert('Unable to start camera: ' + err);
            $('#start-scanner').show();
            $('#stop-scanner').hide();
        });
        
        isScanning = true;
    });
    
    // Stop camera scanner
    $('#stop-scanner').on('click', function() {
        if (html5QrcodeScanner) {
            html5QrcodeScanner.stop();
        }
        $(this).hide();
        $('#start-scanner').show();
        isScanning = false;
    });
    
    // Manual entry
    $('#check-manual').on('click', function() {
        var code = $('#manual-code').val().trim().toUpperCase();
        if (code.length < 6) {
            alert('Please enter a valid ticket code');
            return;
        }
        processTicketCode(code);
    });
    
    $('#manual-code').on('keypress', function(e) {
        if (e.which === 13) {
            $('#check-manual').click();
        }
    });
    
    // Process ticket from QR data
    function processTicket(qrData) {
        try {
            var data = JSON.parse(qrData);
            if (data.code) {
                processTicketCode(data.code);
            }
        } catch (e) {
            // Maybe it's just a code
            processTicketCode(qrData);
        }
    }
    
    // Process ticket code
    function processTicketCode(code) {
        $('#scan-result').hide();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'azure_tickets_checkin',
                ticket_code: code,
                nonce: '<?php echo wp_create_nonce('azure_tickets_nonce'); ?>'
            },
            success: function(response) {
                showResult(response);
                
                if (response.success) {
                    addToRecentList(response.data.ticket);
                    updateStats(1);
                    playSound('success');
                } else {
                    playSound('error');
                }
            },
            error: function() {
                showResult({
                    success: false,
                    data: { message: 'Network error. Please try again.' }
                });
                playSound('error');
            }
        });
    }
    
    // Show result
    function showResult(response) {
        var $result = $('#scan-result');
        var resultClass = 'success';
        
        if (!response.success) {
            if (response.data && response.data.status === 'already_used') {
                resultClass = 'warning';
            } else {
                resultClass = 'error';
            }
        }
        
        $result.removeClass('success error warning').addClass(resultClass);
        $result.find('.result-message').text(response.success ? response.data.message : (response.data ? response.data.message : 'Error'));
        
        if (response.success && response.data.ticket) {
            var ticket = response.data.ticket;
            $result.find('.result-details').html(
                '<strong>' + ticket.attendee + '</strong><br>' +
                ticket.event + ' - ' + ticket.seat
            );
        } else if (response.data && response.data.checked_in_at) {
            $result.find('.result-details').text('Checked in at: ' + response.data.checked_in_at);
        } else {
            $result.find('.result-details').text('');
        }
        
        $result.show();
        
        // Auto-hide after 3 seconds
        setTimeout(function() {
            $result.fadeOut();
        }, 3000);
    }
    
    // Add to recent list
    function addToRecentList(ticket) {
        var $list = $('#recent-list');
        $list.find('.no-checkins').remove();
        
        var html = '<div class="checkin-item">' +
            '<div class="attendee"><strong>' + ticket.attendee + '</strong>' +
            '<small>' + ticket.seat + '</small></div>' +
            '<div class="time">just now</div>' +
            '</div>';
        
        $list.prepend(html);
        
        // Keep only last 10
        $list.find('.checkin-item:gt(9)').remove();
    }
    
    // Update stats
    function updateStats(increment) {
        var current = parseInt($('#stats-checked').text()) || 0;
        $('#stats-checked').text(current + increment);
        
        var total = parseInt($('#stats-total').text()) || 0;
        $('#stats-remaining').text(total - current - increment);
    }
    
    // Play sound
    function playSound(type) {
        if (!settings.sounds) return;
        
        // Create audio context for beeps
        try {
            var context = new (window.AudioContext || window.webkitAudioContext)();
            var oscillator = context.createOscillator();
            var gainNode = context.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(context.destination);
            
            if (type === 'success') {
                oscillator.frequency.value = 800;
                oscillator.type = 'sine';
            } else {
                oscillator.frequency.value = 300;
                oscillator.type = 'square';
            }
            
            gainNode.gain.setValueAtTime(0.3, context.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, context.currentTime + 0.2);
            
            oscillator.start(context.currentTime);
            oscillator.stop(context.currentTime + 0.2);
        } catch (e) {
            // Audio not supported
        }
    }
});
</script>

