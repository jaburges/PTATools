/**
 * Tickets Module - seat hold countdown.
 *
 * The cart and checkout pages print a .pta-hold bar with the seconds left
 * on the earliest seat hold; the seat map calls ptaHoldTimer(seconds) after
 * "Add to cart" when it stays on the page for the extras step. On the cart
 * page the bar reloads when time is up, so the server can take the seats
 * back (if still free) or remove them; on checkout it only says so, to keep
 * what the buyer has typed.
 */
(function() {
    'use strict';

    function clock(s) {
        return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
    }

    function start(bar) {
        var end = Date.now() + (parseInt(bar.getAttribute('data-left'), 10) || 0) * 1000;
        var clockEl = bar.querySelector('.pta-hold__clock');
        var textEl = bar.querySelector('.pta-hold__text');
        var timer;
        document.body.classList.add('has-pta-hold');
        var tick = function() {
            var s = Math.max(0, Math.round((end - Date.now()) / 1000));
            if (s > 0) {
                clockEl.textContent = clock(s);
                bar.classList.toggle('is-ending', s <= 60);
                return;
            }
            clearInterval(timer);
            bar.classList.remove('is-ending');
            bar.classList.add('is-ended');
            if (bar.getAttribute('data-reload') === '1') {
                textEl.textContent = 'Your seat hold has ended. Checking your seats…';
                // A moment past the expiry, so the server sees the hold as lapsed.
                setTimeout(function() { window.location.reload(); }, 2000);
                return;
            }
            textEl.textContent = 'Your seat hold has ended. If your seats are still free they will be held again when you continue; if someone else took one, you will be asked to choose again.';
        };
        timer = setInterval(tick, 1000);
        tick();
    }

    window.ptaHoldTimer = function(seconds) {
        seconds = parseInt(seconds, 10) || 0;
        if (seconds <= 0 || document.querySelector('.pta-hold')) return;
        var bar = document.createElement('div');
        bar.className = 'pta-hold';
        bar.setAttribute('data-left', String(seconds));
        bar.setAttribute('role', 'timer');
        bar.setAttribute('aria-live', 'off');
        bar.innerHTML = '<span class="pta-hold__text">Your seats are held for <strong class="pta-hold__clock">' + clock(seconds) + '</strong></span>';
        document.body.appendChild(bar);
        start(bar);
    };

    function init() {
        var bars = document.querySelectorAll('.pta-hold');
        for (var i = 0; i < bars.length; i++) start(bars[i]);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
