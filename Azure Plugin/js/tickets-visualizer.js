/**
 * Tickets → Visualizer: fits the seat map to the page, shows who has a
 * seat on hover or focus (lighting up the seats bought with it), finds
 * names, and opens the four-page printout.
 */
(function($) {
    'use strict';

    var $map = $('.ptv-map');
    var $json = $('#ptv-data');
    if (!$map.length || !$json.length) return;

    var data = JSON.parse($json.text());
    var $canvas = $map.find('.ptv-canvas');
    var $tip = $('.ptv-tip');
    var W = Number($map.data('w')) || 800, H = Number($map.data('h')) || 600;

    var STATES = {
        sold: 'Sold',
        checked_in: 'Checked in',
        ordered: 'Awaiting payment',
        held: 'In someone\u2019s cart'
    };

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    function fit() {
        var s = Math.min(2.4, ($map.innerWidth() || W) / W);
        $canvas.css('transform', 'scale(' + s + ')');
        $map.css('height', Math.ceil(H * s) + 'px');
    }
    fit();
    $(window).on('resize', fit);

    function seatInfo(el) {
        return data.seats[el.getAttribute('data-s') + '|' + el.getAttribute('data-k')] || null;
    }

    function orderLines(o) {
        var out = [];
        if (!o) return out;
        out.push('Order #' + esc(o.number) + (o.status ? ' <span class="ptv-tip__muted">(' + esc(o.status) + ')</span>' : '') + (o.vip ? ' \u00b7 cast & crew' : ''));
        if (o.buyer) out.push(esc(o.buyer));
        if (o.email || o.phone) out.push('<span class="ptv-tip__muted">' + esc([o.email, o.phone].filter(Boolean).join(' \u00b7 ')) + '</span>');
        var seats = o.seats.concat(o.ga || []);
        if (seats.length > 1) out.push(seats.length + ' together: ' + esc(seats.join(', ')));
        if (o.placed) out.push('<span class="ptv-tip__muted">Bought ' + esc(o.placed) + '</span>');
        return out;
    }

    function seatTip(el) {
        var s = seatInfo(el), sid = el.getAttribute('data-s');
        var lines = ['<strong>' + esc(el.getAttribute('aria-label')) + '</strong>'];
        if (!s) {
            var on = data.sections[sid] && data.sections[sid].enabled;
            lines.push('<span class="ptv-tip__state">' + (on ? 'Free' : 'Free \u00b7 section not on public sale') + '</span>');
            return lines.join('<br>');
        }
        var state = STATES[s.st] || s.st;
        if (s.st === 'checked_in' && s.in) state += ' ' + s.in;
        if (s.st === 'held' && s.until) state += ' until ' + s.until;
        lines.push('<span class="ptv-tip__state">' + esc(state) + '</span>');
        if (s.n) lines.push('Ticket name: ' + esc(s.n));
        if (s.st === 'held') {
            lines.push(s.who ? 'Signed in as ' + esc(s.who) : '<span class="ptv-tip__muted">Not signed in</span>');
        }
        var o = s.o ? data.orders[s.o] : null;
        lines = lines.concat(orderLines(o));
        if (o && o.url) lines.push('<span class="ptv-tip__muted">Click to open the order</span>');
        return lines.join('<br>');
    }

    function gaTip(el) {
        var sid = el.getAttribute('data-ga'), sec = data.sections[sid];
        if (!sec || !sec.ga) return '';
        var g = sec.ga, lines = ['<strong>' + esc(sec.name) + '</strong>'];
        lines.push(g.sold + ' sold of ' + g.capacity + (g.in ? ' \u00b7 ' + g.in + ' checked in' : '') +
            (g.held + g.ordered ? ' \u00b7 ' + (g.held + g.ordered) + ' in carts or unpaid' : ''));
        var ids = Object.keys(g.orders || {});
        ids.slice(0, 12).forEach(function(oid) {
            var o = data.orders[oid];
            lines.push(g.orders[oid] + ' \u00d7 #' + esc(o ? o.number : oid) + (o && o.buyer ? ' ' + esc(o.buyer) : ''));
        });
        if (ids.length > 12) lines.push('<span class="ptv-tip__muted">and ' + (ids.length - 12) + ' more orders</span>');
        return lines.join('<br>');
    }

    function place(x, y) {
        var tw = $tip.outerWidth(), th = $tip.outerHeight();
        var left = x + 14, top = y + 14;
        if (left + tw > window.innerWidth - 8) left = Math.max(8, x - tw - 14);
        if (top + th > window.innerHeight - 8) top = Math.max(8, y - th - 14);
        $tip.css({ left: left + 'px', top: top + 'px' });
    }

    function show(el, x, y) {
        var html = el.hasAttribute('data-ga') ? gaTip(el) : seatTip(el);
        if (!html) return;
        $tip.html(html).prop('hidden', false);
        $map.find('.is-hl').removeClass('is-hl');
        var g = el.getAttribute('data-g');
        if (g) $map.find('.ptv-seat[data-g="' + g + '"]').addClass('is-hl');
        if (x === undefined) {
            var r = el.getBoundingClientRect();
            x = r.right;
            y = r.bottom;
        }
        place(x, y);
    }

    function hide() {
        $tip.prop('hidden', true);
        $map.find('.is-hl').removeClass('is-hl');
    }

    $map.on('mouseenter', '.ptv-seat, .ptv-block--ga', function(e) { show(this, e.clientX, e.clientY); })
        .on('mousemove', '.ptv-seat, .ptv-block--ga', function(e) { if (!$tip.prop('hidden')) place(e.clientX, e.clientY); })
        .on('mouseleave', '.ptv-seat, .ptv-block--ga', hide)
        .on('focus', '.ptv-seat, .ptv-block--ga', function() { show(this); })
        .on('blur', '.ptv-seat, .ptv-block--ga', hide)
        .on('click keydown', '.ptv-seat', function(e) {
            if (e.type === 'keydown' && e.key !== 'Enter') return;
            var s = seatInfo(this), o = s && s.o ? data.orders[s.o] : null;
            if (o && o.url) window.open(o.url, '_blank', 'noopener');
        });

    // ── Find ───────────────────────────────────────────────────────────

    $('.ptv-find-input').on('input', function() {
        var q = $.trim(this.value).toLowerCase().replace(/^#/, '');
        $map.find('.is-match').removeClass('is-match');
        $map.toggleClass('is-finding', q.length > 1);
        if (q.length < 2) return;
        $map.find('.ptv-seat').each(function() {
            var s = seatInfo(this);
            if (!s) return;
            var o = s.o ? data.orders[s.o] : null;
            var hay = [s.n, s.who, o && o.buyer, o && o.email, o && o.number].join(' ').toLowerCase();
            if (hay.indexOf(q) !== -1) $(this).addClass('is-match');
        });
    });

    // ── Printout ───────────────────────────────────────────────────────

    var $modal = $('.ptv-modal');
    var $opener = null;

    function closeModal() {
        $modal.prop('hidden', true);
        if ($opener) $opener.trigger('focus');
    }

    $('.ptv-print-open').on('click', function() {
        $opener = $(this);
        $modal.prop('hidden', false);
        $modal.find('input[name="product"]:checked, input[name="product"]').first().trigger('focus');
    });
    $modal.on('click', function(e) { if (e.target === this) closeModal(); });
    $modal.find('.ptv-print-cancel').on('click', closeModal);
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && !$modal.prop('hidden')) closeModal();
    });

    $modal.find('form').on('submit', function(e) {
        e.preventDefault();
        var $f = $(this);
        var url = new URL($f.data('url'), window.location.href);
        url.searchParams.set('product', $f.find('input[name="product"]:checked').val() || '');
        url.searchParams.set('paper', $f.find('select[name="paper"]').val());
        url.searchParams.set('names', $f.find('input[name="names"]').is(':checked') ? '1' : '0');
        url.searchParams.set('autoprint', '1');
        try { localStorage.setItem('ptaSeatPrintPaper', $f.find('select[name="paper"]').val()); } catch (err) {}
        window.open(url.toString(), '_blank');
        closeModal();
    });

    try {
        var paper = localStorage.getItem('ptaSeatPrintPaper');
        if (paper && $modal.find('option[value="' + paper + '"]').length) $modal.find('select[name="paper"]').val(paper);
    } catch (err) {}
})(jQuery);
