/**
 * Tickets Module - seat hold countdown.
 *
 * The cart and checkout pages print a .pta-hold bar with the seconds left
 * on the earliest seat hold; the seat map calls ptaHoldTimer(seconds, onEnd)
 * once the buyer holds a seat, again whenever it learns the time left, and
 * ptaHoldTimer(0) when they let every seat go. On the cart page the bar
 * reloads when time is up, so the server removes the seats; on checkout it
 * only says so, to keep what the buyer has typed; on the seat map onEnd
 * decides, and returns the bar's message.
 */
(function() {
    'use strict';

    var current = null;

    function clock(s) {
        return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
    }

    function start(bar, onEnd) {
        var end = Date.now() + (parseInt(bar.getAttribute('data-left'), 10) || 0) * 1000;
        var clockEl = bar.querySelector('.pta-hold__clock');
        var textEl = bar.querySelector('.pta-hold__text');
        var timer = null;
        var heldText = textEl.innerHTML;
        document.body.classList.add('has-pta-hold');
        var tick = function() {
            var s = Math.max(0, Math.round((end - Date.now()) / 1000));
            if (s > 0) {
                clockEl.textContent = clock(s);
                bar.classList.toggle('is-ending', s <= 60);
                return;
            }
            clearInterval(timer);
            timer = null;
            bar.classList.remove('is-ending');
            bar.classList.add('is-ended');
            if (onEnd) {
                textEl.textContent = onEnd() || 'Your seat hold has ended.';
                return;
            }
            if (bar.getAttribute('data-reload') === '1') {
                textEl.textContent = 'Your seat hold has ended. Checking your seats…';
                // A moment past the expiry, so the server sees the hold as lapsed.
                setTimeout(function() { window.location.reload(); }, 2000);
                return;
            }
            textEl.textContent = 'Your seat hold has ended, so your seats have been let go. Please go back and choose them again.';
        };
        var run = function() {
            if (!timer) timer = setInterval(tick, 1000);
            tick();
        };
        run();
        return {
            set: function(seconds, nextOnEnd) {
                end = Date.now() + seconds * 1000;
                if (nextOnEnd !== undefined) onEnd = nextOnEnd;
                if (bar.classList.contains('is-ended')) {
                    bar.classList.remove('is-ended');
                    textEl.innerHTML = heldText;
                    clockEl = textEl.querySelector('.pta-hold__clock');
                }
                run();
            },
            stop: function() {
                clearInterval(timer);
                bar.parentNode && bar.parentNode.removeChild(bar);
                document.body.classList.remove('has-pta-hold');
            }
        };
    }

    window.ptaHoldTimer = function(seconds, onEnd) {
        seconds = parseInt(seconds, 10) || 0;
        if (seconds <= 0) {
            if (current) current.stop();
            current = null;
            return;
        }
        if (current) {
            current.set(seconds, onEnd);
            return;
        }
        if (document.querySelector('.pta-hold')) return;
        var bar = document.createElement('div');
        bar.className = 'pta-hold';
        bar.setAttribute('data-left', String(seconds));
        bar.setAttribute('role', 'timer');
        bar.setAttribute('aria-live', 'off');
        bar.innerHTML = '<span class="pta-hold__text">Your seats are held for <strong class="pta-hold__clock">' + clock(seconds) + '</strong></span>';
        document.body.appendChild(bar);
        current = start(bar, onEnd);
    };

    function init() {
        var bars = document.querySelectorAll('.pta-hold');
        for (var i = 0; i < bars.length; i++) start(bars[i]);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
