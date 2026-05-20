/**
 * Auction bid UI - place bid, quick buttons, max bid, countdown, confirm modal
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

    function showConfirmModal(amount, isMax, onConfirm) {
        var label = isMax ? 'Max bid' : 'Bid';
        var $overlay = $('<div class="auction-confirm-overlay">');
        var $box = $(
            '<div class="auction-confirm-box">' +
                '<h3>Confirm your ' + label.toLowerCase() + '</h3>' +
                '<div class="confirm-amount">' + formatPrice(amount) + '</div>' +
                '<p class="confirm-desc">This action cannot be undone.</p>' +
                '<div class="auction-confirm-actions">' +
                    '<button type="button" class="auction-confirm-yes">Confirm ' + label + '</button>' +
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

    /**
     * Lightweight poller for the bid history table, usable on its own when
     * the full bid-form init can't run (e.g. logged-out viewer where the
     * page only contains the read-only history table). Wired up by init()
     * AND by initReadOnly() below.
     */
    function pollFactory(productId, $bidBody, $bidTable, $noBids, $priceVal) {
        var lastSig = '';
        function render(bids) {
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
        function tick() {
            $.get(azureAuction.ajaxurl, {
                action: 'azure_auction_get_bid_history',
                product_id: productId
            }).done(function(res) {
                if (!res || !res.success || !res.data) return;
                var bids = res.data.bids || [];
                var sig = bids.slice(0, 5).map(function(b) {
                    return (b.time || '') + '|' + (b.amount || '');
                }).join(',');
                if (sig === lastSig) return;
                lastSig = sig;
                render(bids);
                if ($priceVal && typeof res.data.current_price !== 'undefined') {
                    $priceVal.html(formatPrice(res.data.current_price));
                    var lbl = (res.data.current_price && res.data.current_price > 0) ? 'Current bid:' : 'Starting bid:';
                    $('.auction-price-label').text(lbl);
                }
            });
        }
        return tick;
    }

    /** Run only the read-only history poller — used when the user is logged
     *  out (no place-bid form in the DOM, but the .auction-bid-history block
     *  is still rendered server-side). */
    function initReadOnly() {
        if (typeof azureAuction === 'undefined') return;
        var $wrapper = $('.azure-auction-bid-wrapper');
        if (!$wrapper.length) return;
        // If init() already ran (logged-in user) it set its own poller; skip.
        if ($wrapper.data('azurePollerStarted')) return;
        var productId = azureAuction.productId || $wrapper.data('product-id');
        if (!productId) return;
        var tick = pollFactory(productId, $('.auction-bid-list'), $('.auction-bid-table'), $('.no-bids'), $('.auction-price-value'));
        setTimeout(tick, 800);
        setInterval(tick, 30000);
        $(document).on('visibilitychange.azureAuctionRO', function() {
            if (document.visibilityState === 'visible') tick();
        });
        $wrapper.data('azurePollerStarted', true);
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
        var $useMax = $('.auction-use-max-bid');
        var $maxAmount = $('.auction-max-bid-amount');
        var $bidForm = $('.auction-bid-form');
        var $loginRequired = $('.auction-login-required');

        // Polling interval. Auction product pages are W3TC/Front-Door page-
        // cached for up to 1h for anon users — that's why "bids stopped
        // showing for the bidder, but a reload didn't update either" was
        // reported. Polling the bid-history endpoint refreshes the visible
        // table from the DB regardless of what the cached HTML says.
        var POLL_INTERVAL_MS = 30000;
        var lastSignature = '';
        var pollTimer = null;

        function getCurrentPrice() {
            var text = $priceVal.text().replace(/[^0-9.]/g, '');
            return parseFloat(text) || 0;
        }

        $('.auction-quick-bid').on('click', function() {
            var inc = parseFloat($(this).data('increment')) || 0;
            var cur = getCurrentPrice();
            $amount.val((cur + inc).toFixed(2));
        });

        $useMax.on('change', function() {
            $maxAmount.toggle($useMax.is(':checked'));
            if ($useMax.is(':checked') && !$maxAmount.val()) {
                $maxAmount.val($amount.val());
            }
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

        function setPrice(price) {
            $priceVal.html(formatPrice(price));
            var label = (price && price > 0) ? 'Current bid:' : 'Starting bid:';
            $('.auction-price-label').text(label);
        }

        // Replace the live bid form with a "session expired" banner that
        // links to wp-login.php with redirect_to set to this page. Idempotent
        // — clicking "Place bid" once the cookie is gone won't keep stacking
        // banners.
        function showSessionExpiredBanner(loginUrl) {
            $bidForm.hide();
            // Reuse the existing login-required block if it's in the DOM
            // (rare — happens if user was already logged out at page render
            // time). Otherwise inject our own banner.
            if (!$('.auction-session-expired').length) {
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
        }

        function pollBidHistory() {
            $.get(azureAuction.ajaxurl, {
                action: 'azure_auction_get_bid_history',
                product_id: productId
            })
            .done(function(res) {
                if (!res || !res.success || !res.data) return;
                var bids = res.data.bids || [];
                // Cheap change detector: join time+amount of the most recent
                // few bids and re-render only when the signature changes.
                // Avoids needless DOM churn every 30s on a quiet page.
                var sig = bids.slice(0, 5).map(function(b) {
                    return (b.time || '') + '|' + (b.amount || '');
                }).join(',');
                if (sig !== lastSignature) {
                    lastSignature = sig;
                    renderBids(bids);
                    if (typeof res.data.current_price !== 'undefined') {
                        setPrice(res.data.current_price);
                        // Prime the input for the user's next bid based on
                        // the freshest server-side price + $5 increment.
                        var nextMin = (parseFloat(res.data.current_price) || 0) + 5;
                        // Only prime if the user hasn't started typing.
                        if (!$amount.is(':focus')) {
                            $amount.val(nextMin.toFixed(2));
                        }
                    }
                }
            });
        }

        function startPolling() {
            if (pollTimer) return;
            pollTimer = setInterval(pollBidHistory, POLL_INTERVAL_MS);
            // Also refresh immediately when the tab regains focus — covers
            // the "left the tab open overnight" case faster than waiting up
            // to 30s.
            $(document).on('visibilitychange.azureAuction', function() {
                if (document.visibilityState === 'visible') {
                    pollBidHistory();
                }
            });
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

        function submitBid(isMax, amount, maxBid) {
            $placeBtn.prop('disabled', true);
            $msg.hide();

            var data = {
                action: 'azure_auction_place_bid',
                nonce: azureAuction.nonce,
                product_id: productId,
                is_max_bid: isMax ? '1' : '0'
            };
            if (isMax) {
                data.max_bid = maxBid;
                data.amount = maxBid;
            } else {
                data.amount = amount;
            }

            $.post(azureAuction.ajaxurl, data)
                .done(function(res) {
                    if (res.success && res.data) {
                        setPrice(res.data.current_price);
                        renderBids(res.data.bids || []);
                        // Update the signature so the poller won't re-render
                        // the same payload a second time.
                        lastSignature = (res.data.bids || []).slice(0, 5).map(function(b) {
                            return (b.time || '') + '|' + (b.amount || '');
                        }).join(',');
                        $msg.removeClass('error').addClass('success').text('Bid placed!').show();
                        var newPrice = res.data.current_price || getCurrentPrice();
                        if (newPrice > 0) {
                            $amount.val((newPrice + 5).toFixed(2));
                        }
                        setTimeout(function() { $msg.fadeOut(); }, 4000);
                    } else if (res.data && res.data.code === 'not_logged_in') {
                        // Session expired since the page loaded. Replace
                        // the bid form with a login banner instead of a
                        // transient error message they might dismiss.
                        showSessionExpiredBanner(res.data.login_url);
                    } else {
                        $msg.removeClass('success').addClass('error')
                            .text(res.data && res.data.message ? res.data.message : 'Bid failed.').show();
                    }
                })
                .fail(function(xhr) {
                    // 401 means the place-bid handler returned the
                    // not_logged_in payload — server may not have set
                    // success:false envelope on every gateway, so handle
                    // the status code path too.
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
            var isMax = $useMax.is(':checked');
            var amount = parseFloat($amount.val());
            var maxBid = parseFloat($maxAmount.val());

            if (isMax && (isNaN(maxBid) || maxBid <= 0)) {
                $msg.removeClass('success').addClass('error').text('Please enter a max bid.').show();
                return;
            }
            if (!isMax && (isNaN(amount) || amount <= 0)) {
                $msg.removeClass('success').addClass('error').text('Please enter a bid amount.').show();
                return;
            }

            var confirmAmount = isMax ? maxBid : amount;
            showConfirmModal(confirmAmount, isMax, function() {
                submitBid(isMax, amount, maxBid);
            });
        });

        // Kick off the background poller for ALL visitors — logged in,
        // logged out, and guests. The bid-history endpoint allows public
        // reads and the table is the same shape either way.
        startPolling();
        // Also do one immediate poll so anyone who landed on the page from
        // a stale CDN cache sees fresh bids within a second or two,
        // without waiting 30s for the first tick.
        setTimeout(pollBidHistory, 800);
        $wrapper.data('azurePollerStarted', true);
    }

    $(function() {
        initCountdown();
        init();
        // If init() bailed early because the user is logged out (no
        // place-bid form rendered, login prompt instead) we still want
        // to poll the read-only bid history so reloads don't show stale
        // data from the page cache.
        initReadOnly();
    });
})(jQuery);
