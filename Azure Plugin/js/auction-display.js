/**
 * Auction display page client.
 *
 * Two responsibilities:
 *   1. Poll /wp-admin/admin-ajax.php?action=azure_auction_display_prices
 *      every 30s and update each card's price + masked bidder in place.
 *   2. Drive a "screens" carousel: only one page of cards (cards_wide x
 *      cards_tall) is visible at a time, auto-advancing on a configurable
 *      interval so a projector can cycle through every active item.
 *
 * Pauses both timers while the tab is hidden so backgrounded TVs/laptops
 * don't keep hammering the server.
 */
(function ($) {
    'use strict';

    if (typeof azureAuctionDisplay === 'undefined') {
        return;
    }

    var REFRESH_MS = parseInt(azureAuctionDisplay.refreshMs, 10) || 30000;
    var SLIDE_MS   = parseInt(azureAuctionDisplay.slideMs, 10)   || 5000;
    var $wrapper = $('.auction-display-wrapper');
    if (!$wrapper.length) {
        return;
    }

    var inFlight = false;
    var refreshTimer = null;
    var slideTimer = null;

    // ----- Carousel ---------------------------------------------------------

    var $grid = $wrapper.find('.auction-display-grid');
    var $pager = $wrapper.find('.auction-display-pager');
    var pageCount = Math.max(1, parseInt($grid.attr('data-page-count'), 10) || 1);
    var currentPage = 0;

    function showPage(idx) {
        if (pageCount <= 1) {
            return;
        }
        if (idx < 0) idx = pageCount - 1;
        if (idx >= pageCount) idx = 0;
        currentPage = idx;
        $grid.find('.auction-card').each(function () {
            var $c = $(this);
            $c.toggleClass('is-current', parseInt($c.attr('data-page'), 10) === idx);
        });
        $pager.find('.auction-display-dot').each(function () {
            var $d = $(this);
            $d.toggleClass('is-current', parseInt($d.attr('data-page'), 10) === idx);
        });
    }

    function startSlide() {
        if (slideTimer || pageCount <= 1) {
            return;
        }
        slideTimer = window.setInterval(function () {
            showPage(currentPage + 1);
        }, SLIDE_MS);
    }

    function stopSlide() {
        if (!slideTimer) {
            return;
        }
        window.clearInterval(slideTimer);
        slideTimer = null;
    }

    // Click a dot to jump to that page (and reset the timer).
    $pager.on('click', '.auction-display-dot', function () {
        var idx = parseInt($(this).attr('data-page'), 10) || 0;
        showPage(idx);
        stopSlide();
        startSlide();
    });

    // ----- Live price refresh ----------------------------------------------

    function updateCard(item) {
        var $card = $wrapper.find('.auction-card[data-product-id="' + item.id + '"]');
        if (!$card.length) {
            return;
        }

        var $price = $card.find('.auction-card-price');
        var $label = $card.find('.auction-card-label');
        var $bid = $card.find('.auction-card-bid');
        var $bidder = $card.find('.auction-card-bidder');

        var newPriceHtml = item.price_html || '';
        if (newPriceHtml && $price.html() !== newPriceHtml) {
            $price.html(newPriceHtml);
            $card.addClass('flash-update');
            window.setTimeout(function () {
                $card.removeClass('flash-update');
            }, 1100);
        }

        if (item.has_bids) {
            $label.text('Current bid');
            if ($bidder.length) {
                $bidder.find('.auction-card-bidder-name').text(item.bidder || '***');
            } else {
                $price.before(
                    '<span class="auction-card-bidder">(<span class="auction-card-bidder-name">' +
                    $('<div>').text(item.bidder || '***').html() +
                    '</span>)</span>'
                );
            }
        } else {
            $label.text('Starting bid');
            $bidder.remove();
        }
    }

    function refresh() {
        if (inFlight || document.hidden) {
            return;
        }
        inFlight = true;

        $.ajax({
            url: azureAuctionDisplay.ajaxurl,
            method: 'GET',
            dataType: 'json',
            data: {
                action: 'azure_auction_display_prices',
                nonce: azureAuctionDisplay.nonce
            }
        }).done(function (resp) {
            if (!resp || !resp.success || !resp.data) {
                return;
            }
            var items = resp.data.items || [];
            for (var i = 0; i < items.length; i++) {
                updateCard(items[i]);
            }
        }).always(function () {
            inFlight = false;
        });
    }

    function startRefresh() {
        if (refreshTimer) {
            return;
        }
        refreshTimer = window.setInterval(refresh, REFRESH_MS);
    }

    function stopRefresh() {
        if (!refreshTimer) {
            return;
        }
        window.clearInterval(refreshTimer);
        refreshTimer = null;
    }

    // Pause when the tab/window is hidden, resume when it comes back.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopRefresh();
            stopSlide();
        } else {
            // Refresh immediately on return so cards aren't stale.
            refresh();
            startRefresh();
            startSlide();
        }
    });

    startRefresh();
    startSlide();
})(jQuery);
