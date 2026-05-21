/*
 * PTA User Dropdown — header account menu
 *
 * Tiny vanilla-JS toggle: clicking the trigger opens the panel,
 * clicking outside or pressing Esc closes it. Shipped only on pages
 * that render the [pta_user_dropdown] shortcode.
 */
(function () {
    'use strict';

    function closeAll(except) {
        var open = document.querySelectorAll('[data-pta-user-dropdown] .pta-user-dropdown__trigger[aria-expanded="true"]');
        for (var i = 0; i < open.length; i++) {
            if (open[i] !== except) {
                open[i].setAttribute('aria-expanded', 'false');
                var panel = open[i].nextElementSibling;
                if (panel) panel.hidden = true;
            }
        }
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-pta-user-dropdown] .pta-user-dropdown__trigger');
        if (trigger) {
            var expanded = trigger.getAttribute('aria-expanded') === 'true';
            closeAll(trigger);
            trigger.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            var panel = trigger.nextElementSibling;
            if (panel) panel.hidden = expanded;
            e.stopPropagation();
            return;
        }
        // Click outside any dropdown closes everything.
        if (!e.target.closest('.pta-user-dropdown__panel')) {
            closeAll(null);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAll(null);
    });
})();
