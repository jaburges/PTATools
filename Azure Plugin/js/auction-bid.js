/**
 * Auction bid UI - place manual bid, quick buttons, countdown, confirm modal
 *
 * Manual-bid only (max-bid / auto-bid was removed in v3.74).
 */
(function($) {
    'use strict';

    function formatPrice(amount) {
        return '$' + parseFloat(amount).toFixed(2);
    }

    function initCountdown() {
        var $cd = $('.auction-countdown');
        if (!$cd.length) return;

        var endTs = parseInt($cd.data('end'), 10);
        if (!endTs) return;

        var $timer = $cd.find('.auction-countdown-timer');

        function update() {
            var now = Math.floor(Date.now() / 1000);
            var diff = endTs - now;
            if (diff <= 0) {
                $timer.text('Ended').addClass('ending-soon');
                $cd.trigger('auction:ended');
                return;
            }
            var days = Math.floor(diff / 86400);
            var hours = Math.floor((diff % 86400) / 3600);
            var mins = Math.floor((diff % 3600) / 60);
            var secs = diff % 60;
            var parts = [];
            if (days > 0) parts.push(days + 'd');
            if (hours > 0 || days > 0) parts.push(hours + 'h');
            parts.push(mins + 'm');
            parts.push(secs + 's');
            $timer.text(parts.join(' '));
            if (diff < 3600) {
                $timer.addClass('ending-soon');
            }
            setTimeout(update, 1000);
        }
        update();
    }

    function showConfirmModal(amount, onConfirm) {
        var $overlay = $('<div class="auction-confirm-overlay">');
        var $box = $(
            '<div class="auction-confirm-box">' +
                '<h3>Confirm your bid</h3>' +
                '<div class="confirm-amount">' + formatPrice(amount) + '</div>' +
                '<p class="confirm-desc">This action cannot be undone.</p>' +
                '<div class="auction-confirm-actions">' +
                    '<button type="button" class="auction-confirm-yes">Confirm Bid</button>' +
                    '<button type="button" class="auction-confirm-no">Cancel</button>' +
                '</div>' +
            '</div>'
        );
        $overlay.append($box);
        $('body').append($overlay);

        $overlay.on('click', '.auction-confirm-yes', function() {
            $overlay.remove();
            onConfirm();
        });
        $overlay.on('click', '.auction-confirm-no', function() {
            $overlay.remove();
        });
        $overlay.on('click', function(e) {
            if (e.target === $overlay[0]) $overlay.remove();
        });
    }

    function init() {
        var $wrapper = $('.azure-auction-bid-wrapper');
        if (!$wrapper.length || typeof azureAuction === 'undefined') return;

        var productId = azureAuction.productId || $wrapper.data('product-id');
        var $amount = $('.auction-bid-amount');
        var $priceVal = $('.auction-price-value');
        var $bidBody = $('.auction-bid-list');
        var $noBids = $('.no-bids');
        var $bidTable = $('.auction-bid-table');
        var $msg = $('.auction-bid-message');
        var $placeBtn = $('.auction-place-bid');

        // Live-poll cadence. 5s is a good balance for an auction with O(10s)
        // of concurrent bidders — short enough to feel responsive, long
        // enough that we don't hammer the server during the live event.
        var POLL_INTERVAL_MS = 5000;
        var lastKnownPrice = 0;
        var lastKnownTopBid = '';
        var pollTimer = null;
        var auctionEnded = false;

        function getCurrentPrice() {
            var text = $priceVal.text().replace(/[^0-9.]/g, '');
            return parseFloat(text) || 0;
        }

        // Fingerprint the top bid (bidder + amount + time) so we can tell
        // when the leader actually changed vs the same row being re-fetched.
        function topBidFingerprint(bids) {
            if (!bids || !bids.length) return '';
            var b = bids[0];
            return (b.bidder || '') + '|' + (b.amount || '') + '|' + (b.time || '');
        }

        // Format an increment value for the quick-bid button label.
        // Whole numbers render as "+$5", fractional as "+$1.50".
        function formatIncrementLabel(inc) {
            var n = parseFloat(inc) || 0;
            return '+$' + (n % 1 === 0 ? n.toFixed(0) : n.toFixed(2));
        }

        // Sync the quick-bid buttons + bid input default to the current
        // tier. Called whenever a poll/place-bid response includes fresh
        // tier info, so the UI reflects the price-tier increments without
        // duplicating the tier table client-side.
        function syncBidControls(nextMin, increments) {
            if (typeof nextMin === 'number' && nextMin > 0) {
                var typed = parseFloat($amount.val()) || 0;
                if (typed <= 0 || typed < nextMin) {
                    $amount.val(nextMin.toFixed(2));
                }
            }
            if (Array.isArray(increments) && increments.length) {
                var $btns = $('.auction-quick-bid');
                $btns.each(function(i) {
                    if (typeof increments[i] === 'undefined') return;
                    var inc = parseFloat(increments[i]) || 0;
                    $(this).attr('data-increment', inc).data('increment', inc).text(formatIncrementLabel(inc));
                });
            }
        }

        function flashUpdate(newPrice) {
            var $banner = $('<div class="auction-live-update">')
                .text('New bid placed: ' + formatPrice(newPrice))
                .css({
                    background: '#1a7f37',
                    color: '#fff',
                    padding: '10px 14px',
                    'border-radius': '4px',
                    'font-weight': '600',
                    'text-align': 'center',
                    margin: '10px 0',
                    opacity: 0
                });
            $msg.before($banner);
            $banner.animate({ opacity: 1 }, 200);
            setTimeout(function() {
                $banner.fadeOut(400, function() { $banner.remove(); });
            }, 4000);
        }

        function pollState() {
            if (auctionEnded) return;
            $.get(azureAuction.ajaxurl, {
                action: 'azure_auction_get_bid_history',
                product_id: productId
            })
                .done(function(res) {
                    if (!res || !res.success || !res.data) return;
                    var newPrice = parseFloat(res.data.current_price) || 0;
                    var newFingerprint = topBidFingerprint(res.data.bids);

                    // Update only when something actually changed so the
                    // place-bid form's currently-typed amount doesn't get
                    // disturbed on every poll.
                    if (newFingerprint && newFingerprint !== lastKnownTopBid) {
                        setPrice(newPrice);
                        renderBids(res.data.bids || []);
                        $('.auction-price-label').text('Current bid:');

                        // First poll after page load: just record state
                        // silently. Subsequent changes show a flash.
                        if (lastKnownTopBid !== '') {
                            flashUpdate(newPrice);
                            syncBidControls(
                                parseFloat(res.data.next_min_bid) || 0,
                                res.data.quick_increments || []
                            );
                        } else {
                            // Initial sync: refresh quick-bid labels but
                            // don't disturb the user's pre-typed amount.
                            syncBidControls(0, res.data.quick_increments || []);
                        }
                        lastKnownPrice = newPrice;
                        lastKnownTopBid = newFingerprint;
                    }
                });
        }

        function startPolling() {
            if (pollTimer) clearInterval(pollTimer);
            pollTimer = setInterval(pollState, POLL_INTERVAL_MS);
        }

        function stopPolling() {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        }

        // Pause polling when tab is hidden — saves bandwidth/battery for
        // people who left the page open in a background tab. Resume +
        // immediate poll when they come back so they catch up fast.
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                stopPolling();
            } else if (!auctionEnded) {
                pollState();
                startPolling();
            }
        });

        // Stop polling once the countdown hits zero. We still leave the
        // last poll result on screen so the user sees the final state.
        $('.auction-countdown').on('auction:ended', function() {
            auctionEnded = true;
            stopPolling();
        });

        // Seed lastKnownTopBid from whatever was server-rendered so the
        // first real change triggers a flash, not the initial sync.
        lastKnownPrice = getCurrentPrice();
        var initialRows = $bidBody.find('tr');
        if (initialRows.length) {
            var $first = initialRows.first();
            lastKnownTopBid = ($first.find('td').eq(0).text() || '') + '|' +
                              parseFloat($first.find('td').eq(1).text().replace(/[^0-9.]/g, '')) + '|' +
                              ($first.find('td').eq(2).text() || '');
        }

        $('.auction-quick-bid').on('click', function() {
            var inc = parseFloat($(this).data('increment')) || 0;
            var cur = getCurrentPrice();
            $amount.val((cur + inc).toFixed(2));
        });

        function renderBids(bids) {
            $bidBody.empty();
            if (!bids || !bids.length) {
                $bidTable.hide();
                $noBids.show();
                return;
            }
            $noBids.hide();
            $bidTable.show();
            bids.forEach(function(b) {
                var $row = $('<tr>');
                $row.append($('<td>').text(b.bidder || '***'));
                $row.append($('<td>').html(formatPrice(b.amount)));
                $row.append($('<td class="bid-time">').text(b.time || ''));
                $bidBody.append($row);
            });
        }

        // Cache jQuery wrapped refs we toggle when the session expires.
        var $bidForm = $('.auction-bid-form');

        // Replace the live bid form with a session-expired banner that links
        // to wp-login.php with redirect_to back to this auction. Idempotent
        // â calling twice won't stack banners.
        function showSessionExpiredBanner(loginUrl) {
            $bidForm.hide();
            if ($('.auction-session-expired').length) return;
            var login = loginUrl || (azureAuction && azureAuction.loginUrl) || '';
            var html =
                '<div class="auction-session-expired" style="background:#fff8e5;border-left:4px solid #f0b849;padding:10px 14px;margin:10px 0;">' +
                    '<strong>Your session has expired.</strong> ' +
                    (login
                        ? '<a href="' + login + '">Log in to continue bidding</a>.'
                        : 'Please refresh the page and log in to continue bidding.') +
                '</div>';
            $bidForm.before(html);
        }

        function setPrice(price) {
            $priceVal.html(formatPrice(price));
        }

        $('.auction-buy-it-now-btn').on('click', function() {
            var $btn = $(this);
            if (!confirm(azureAuction.i18n.buyItNowConfirm || 'Create order and go to checkout?')) {
                return;
            }
            $btn.prop('disabled', true).text('Processing...');
            $.post(azureAuction.ajaxurl, {
                action: 'azure_auction_buy_it_now',
                nonce: azureAuction.nonce,
                product_id: productId
            })
                .done(function(res) {
                    if (res.success && res.data && res.data.checkout_url) {
                        window.location.href = res.data.checkout_url;
                    } else {
                        alert(res.data && res.data.message ? res.data.message : 'Could not create order.');
                        $btn.prop('disabled', false);
                    }
                })
                .fail(function() {
                    alert('Network error.');
                    $btn.prop('disabled', false);
                });
        });

        function submitBid(amount) {
            $placeBtn.prop('disabled', true);
            $msg.hide();

            var data = {
                action: 'azure_auction_place_bid',
                nonce: azureAuction.nonce,
                product_id: productId,
                amount: amount
            };

            $.post(azureAuction.ajaxurl, data)
                .done(function(res) {
                    if (res.success && res.data) {
                        setPrice(res.data.current_price);
                        renderBids(res.data.bids || []);
                        $msg.removeClass('error').addClass('success').text('Bid placed!').show();
                        syncBidControls(
                            parseFloat(res.data.next_min_bid) || 0,
                            res.data.quick_increments || []
                        );
                        $('.auction-price-label').text('Current bid:');
                        setTimeout(function() { $msg.fadeOut(); }, 4000);
                    } else if (res.data && res.data.code === 'not_logged_in') {
                        // Cookie expired since the page loaded. Swap the bid
                        // form for a session-expired banner instead of a
                        // transient error message the bidder might dismiss.
                        showSessionExpiredBanner(res.data.login_url);
                    } else {
                        $msg.removeClass('success').addClass('error')
                            .text(res.data && res.data.message ? res.data.message : 'Bid failed.').show();
                    }
                })
                .fail(function(xhr) {
                    // HTTP 401 path: the place-bid handler returned the
                    // not_logged_in payload but it may have arrived as an
                    // error response rather than success:false envelope on
                    // some PHP/Apache configurations. Parse the body and
                    // route to the same banner.
                    if (xhr && xhr.status === 401) {
                        var loginUrl = '';
                        try {
                            var parsed = xhr.responseJSON || JSON.parse(xhr.responseText || '{}');
                            loginUrl = parsed && parsed.data && parsed.data.login_url ? parsed.data.login_url : '';
                        } catch (e) {}
                        showSessionExpiredBanner(loginUrl);
                        return;
                    }
                    $msg.removeClass('success').addClass('error').text('Network error.').show();
                })
                .always(function() {
                    $placeBtn.prop('disabled', false);
                });
        }

        $placeBtn.on('click', function() {
            var amount = parseFloat($amount.val());

            if (isNaN(amount) || amount <= 0) {
                $msg.removeClass('success').addClass('error').text('Please enter a bid amount.').show();
                return;
            }

            showConfirmModal(amount, function() {
                submitBid(amount);
            });
        });

        // Kick off the live-poll loop. Fires immediately to catch any bids
        // placed in the second between page render and JS init.
        pollState();
        startPolling();
    }

    $(function() {
        initCountdown();
        init();
    });
})(jQuery);
