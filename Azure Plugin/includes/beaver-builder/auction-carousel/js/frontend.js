/**
 * Auction Carousel — frontend behavior.
 *
 * Each .pta-auction-carousel instance on the page is wired independently
 * (a single page can include multiple modules with different settings).
 * No external dependencies; vanilla JS so the module is cheap to ship in
 * a Beaver Builder layout.
 */
(function () {
    'use strict';

    function init(root) {
        if (root.dataset.pacInit === '1') {
            return;
        }
        root.dataset.pacInit = '1';

        var track = root.querySelector('.pta-ac-track');
        var pager = root.querySelector('.pta-ac-pager');
        var prev = root.querySelector('.pta-ac-prev');
        var next = root.querySelector('.pta-ac-next');
        if (!track) {
            return;
        }

        var pageCount = Math.max(1, parseInt(root.dataset.pageCount, 10) || 1);
        var cycleMs = parseInt(root.dataset.cycleMs, 10) || 5000;
        var current = 0;
        var timer = null;
        var paused = false;

        function applyTransform() {
            track.style.transform = 'translateX(-' + (current * 100) + '%)';
            if (pager) {
                var dots = pager.querySelectorAll('.pta-ac-dot');
                for (var i = 0; i < dots.length; i++) {
                    dots[i].classList.toggle('is-current', i === current);
                }
            }
        }

        function go(idx) {
            if (pageCount <= 1) {
                return;
            }
            if (idx < 0) idx = pageCount - 1;
            if (idx >= pageCount) idx = 0;
            current = idx;
            applyTransform();
        }

        function start() {
            if (timer || pageCount <= 1 || paused) {
                return;
            }
            timer = window.setInterval(function () {
                go(current + 1);
            }, cycleMs);
        }

        function stop() {
            if (!timer) {
                return;
            }
            window.clearInterval(timer);
            timer = null;
        }

        function reset() {
            stop();
            start();
        }

        if (prev) {
            prev.addEventListener('click', function () { go(current - 1); reset(); });
        }
        if (next) {
            next.addEventListener('click', function () { go(current + 1); reset(); });
        }
        if (pager) {
            pager.addEventListener('click', function (ev) {
                var dot = ev.target.closest('.pta-ac-dot');
                if (!dot) {
                    return;
                }
                var idx = parseInt(dot.dataset.page, 10) || 0;
                go(idx);
                reset();
            });
        }

        // Pause on hover so a viewer can click into a card without being
        // yanked to the next slide. Resume on mouseleave.
        root.addEventListener('mouseenter', function () { paused = true; stop(); });
        root.addEventListener('mouseleave', function () { paused = false; start(); });

        // Pause when the tab is hidden.
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stop();
            } else if (!paused) {
                start();
            }
        });

        applyTransform();
        start();
    }

    function initAll() {
        var nodes = document.querySelectorAll('.pta-auction-carousel');
        for (var i = 0; i < nodes.length; i++) {
            init(nodes[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
