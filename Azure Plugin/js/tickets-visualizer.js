/**
 * Tickets → Visualizer: fits the seat map to the page, shows who has a
 * seat on hover or focus (lighting up the seats bought with it), finds
 * names, checks an order in from a seat's right-click menu, and opens the
 * four-page printout.
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

    // ── Right-click: open the order, or check it in ────────────────────

    var door = $map.data('door') || null;
    var $menu = $('.ptv-menu');
    var $door = $('.ptv-door');
    var menuSeat = null;
    var doorSeat = null;
    var doorOrder = 0;
    var doorTickets = [];
    var picked = {};
    var busy = false;
    var closeTimer = null;

    function orderOf(el) {
        var s = seatInfo(el);
        return s && s.o ? { id: s.o, o: data.orders[s.o] || { number: s.o } } : null;
    }

    function closeMenu(refocus) {
        if ($menu.prop('hidden')) return;
        $menu.prop('hidden', true).empty();
        if (refocus && menuSeat) menuSeat.focus();
        menuSeat = null;
    }

    function openMenu(el, x, y) {
        var ord = orderOf(el);
        if (!ord) return false;
        hide();
        menuSeat = el;
        var head = 'Order #' + esc(ord.o.number) + (ord.o.buyer ? ' \u00b7 ' + esc(ord.o.buyer) : '');
        var isIn = seatInfo(el).st === 'checked_in';
        var seatNo = el.getAttribute('data-k').replace('-', '');
        $menu.html(
            '<div class="ptv-menu__head">' + head + '</div>' +
            (ord.o.url ? '<button type="button" role="menuitem" data-act="order"><span class="dashicons dashicons-external"></span> View order</button>' : '') +
            (door ? '<button type="button" role="menuitem" data-act="checkin"><span class="dashicons dashicons-yes-alt"></span> Check in\u2026</button>' : '') +
            (door && isIn ? '<button type="button" role="menuitem" data-act="undo"><span class="dashicons dashicons-undo"></span> Undo check-in (' + esc(seatNo) + ')</button>' : '')
        ).prop('hidden', false);
        var mw = $menu.outerWidth(), mh = $menu.outerHeight();
        $menu.css({
            left: Math.max(8, Math.min(x, window.innerWidth - mw - 8)) + 'px',
            top: Math.max(8, Math.min(y, window.innerHeight - mh - 8)) + 'px'
        });
        $menu.find('button').first().trigger('focus');
        return true;
    }

    $map.on('contextmenu', '.ptv-seat', function(e) {
        if (!orderOf(this)) return;
        e.preventDefault();
        var x = e.clientX, y = e.clientY;
        if (!x && !y) {
            var r = this.getBoundingClientRect();
            x = r.left + r.width / 2;
            y = r.bottom;
        }
        openMenu(this, x, y);
    }).on('keydown', '.ptv-seat', function(e) {
        if (e.key !== 'ContextMenu' && !(e.key === 'F10' && e.shiftKey)) return;
        var r = this.getBoundingClientRect();
        if (openMenu(this, r.left + r.width / 2, r.bottom)) e.preventDefault();
    });

    $menu.on('click', 'button', function() {
        var el = menuSeat, ord = el && orderOf(el);
        var act = $(this).data('act');
        closeMenu(false);
        if (!ord) return;
        if (act === 'order' && ord.o.url) window.open(ord.o.url, '_blank', 'noopener');
        if (act === 'checkin') openDoor(el, ord);
        if (act === 'undo') undoSeat(el, ord);
    }).on('keydown', function(e) {
        var $b = $menu.find('button'), i = $b.index(document.activeElement);
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            $b.eq((i + (e.key === 'ArrowDown' ? 1 : $b.length - 1)) % $b.length).trigger('focus');
        } else if (e.key === 'Escape' || e.key === 'Tab') {
            e.preventDefault();
            closeMenu(true);
        }
    });

    $(document).on('mousedown', function(e) {
        if (!$menu.prop('hidden') && !$(e.target).closest('.ptv-menu').length) closeMenu(false);
    });
    $(window).on('scroll resize blur', function() { closeMenu(false); });

    function seatName(t) {
        return t.seat || t.label;
    }

    function post(params, done) {
        busy = true;
        $door.find('button').prop('disabled', true);
        $.post(door.url, $.extend({ action: door.action, nonce: door.nonce, product_id: door.product, order_id: doorOrder }, params))
            .done(function(res) {
                busy = false;
                if (res && res.success) {
                    done(res.data);
                } else {
                    failed(res && res.data && res.data.message);
                }
            })
            .fail(function(xhr) {
                busy = false;
                failed(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message);
            });
    }

    function failed(message) {
        $door.find('.ptv-door__body').html('<div class="notice notice-error inline"><p>' +
            esc(message || 'Something went wrong. Check the connection and try again.') + '</p></div>');
        actions([['Close', 'close']]);
    }

    function actions(list) {
        $door.find('.ptv-door__actions').html(list.map(function(a) {
            return '<button type="button" class="button' + (a[2] ? ' button-primary' : '') + '" data-act="' + a[1] + '">' + esc(a[0]) + '</button>';
        }).join(''));
    }

    function pending() {
        return doorTickets.filter(function(t) { return t.status === 'active'; });
    }

    function startDoor(el, ord, title, wait) {
        clearTimeout(closeTimer);
        doorSeat = el;
        doorOrder = ord.id;
        doorTickets = [];
        picked = {};
        $door.find('#ptv-door-title').text(title + ord.o.number);
        $door.find('.ptv-door__sub').text([ord.o.buyer, ord.o.vip ? 'cast & crew' : ''].filter(Boolean).join(' \u00b7 '));
        $door.find('.ptv-door__body').html('<p class="ptv-door__wait"><span class="spinner is-active"></span> ' + wait + '</p>');
        actions([['Cancel', 'close']]);
        $door.prop('hidden', false);
        $door.find('[data-act="close"]').trigger('focus');
    }

    function openDoor(el, ord) {
        startDoor(el, ord, 'Check in order #', 'Loading tickets\u2026');
        post({ op: 'list' }, function(d) {
            doorTickets = d.tickets || [];
            showChoice();
        });
    }

    function undoSeat(el, ord) {
        startDoor(el, ord, 'Order #', 'Undoing the check-in\u2026');
        post({ op: 'undo', section: el.getAttribute('data-s'), key: el.getAttribute('data-k') }, function(d) {
            doorTickets = d.tickets || [];
            paint(doorTickets, d.total_in);
            $door.find('.ptv-door__body').html(d.undone
                ? '<p class="ptv-door__done ptv-door__done--undo">\u21a9 ' + esc(d.message) + '</p><p class="ptv-door__muted">Their QR code will scan again.</p>'
                : '<p>' + esc(d.message) + '</p>');
            actions([['Done', 'close', true]]);
            $door.find('[data-act="close"]').trigger('focus');
            if (d.undone) closeTimer = setTimeout(closeDoor, 1600);
        });
    }

    function closeDoor() {
        if (busy) return;
        clearTimeout(closeTimer);
        $door.prop('hidden', true);
        if (doorSeat) doorSeat.focus();
    }

    function inList(list) {
        return list.map(function(t) {
            return esc(seatName(t)) + (t.time ? ' <span class="ptv-door__muted">(' + esc(t.time) + ')</span>' : '');
        }).join(', ');
    }

    function showChoice() {
        var todo = pending();
        var done = doorTickets.filter(function(t) { return t.status === 'used'; });
        var $body = $door.find('.ptv-door__body');
        if (!doorTickets.length) {
            $body.html('<p>This order has no tickets for this performance.</p>');
            actions([['Close', 'close', true]]);
            return;
        }
        var html = '';
        if (done.length) html += '<p class="ptv-door__muted">\u2713 Already in: ' + inList(done) + '</p>';
        if (!todo.length) {
            $body.html('<p class="ptv-door__done">Everyone on this order is checked in.</p>' + html);
            actions([['Close', 'close', true]]);
            $door.find('[data-act="close"]').trigger('focus');
            return;
        }
        var seats = todo.map(seatName).join(', ');
        html += '<div class="ptv-door__choices">' +
            '<button type="button" class="ptv-door__big is-primary" data-act="all">' +
                (todo.length === 1 ? 'Check in ' + esc(seatName(todo[0])) : 'Check in all ' + todo.length) +
                '<small>' + esc(seats) + '</small></button>' +
            '<button type="button" class="ptv-door__big" data-act="pick"' + (todo.length === 1 ? ' disabled' : '') + '>Choose seats' +
                '<small>Some of the party aren\u2019t here yet</small></button>' +
            '</div>';
        $body.html(html);
        actions([['Cancel', 'close']]);
        $body.find('[data-act="all"]').trigger('focus');
    }

    function showPick() {
        var html = '<p>Tap the seats to check in now.</p><div class="ptv-door__seats">';
        doorTickets.forEach(function(t) {
            var used = t.status === 'used';
            html += '<button type="button" class="ptv-door__seat" data-id="' + t.id + '" aria-pressed="' + (picked[t.id] ? 'true' : 'false') + '"' + (used ? ' disabled' : '') + '>' +
                '<strong>' + esc(seatName(t)) + '</strong>' +
                '<span>' + (used ? '\u2713 In ' + esc(t.time) : esc(t.name || (t.seat ? t.label : ''))) + '</span></button>';
        });
        $door.find('.ptv-door__body').html(html + '</div>');
        updatePickActions();
        $door.find('.ptv-door__seat:not(:disabled)').first().trigger('focus');
    }

    function updatePickActions() {
        var n = Object.keys(picked).length;
        actions([['Back', 'back'], [n ? 'Check in ' + n + (n === 1 ? ' seat' : ' seats') : 'Choose seats to check in', 'some', true]]);
        $door.find('[data-act="some"]').prop('disabled', !n);
    }

    function checkIn(ids) {
        var params = ids ? { op: 'checkin', ids: ids } : { op: 'checkin', all: 1 };
        $door.find('.ptv-door__body').append('<p class="ptv-door__wait"><span class="spinner is-active"></span> Checking in\u2026</p>');
        post(params, function(d) {
            doorTickets = d.tickets || doorTickets;
            paint(doorTickets, d.total_in);
            var lines = [];
            if (d.checked_in) lines.push('<p class="ptv-door__done">\u2713 ' + d.checked_in + ' checked in</p>');
            if (d.already) lines.push('<p>' + d.already + ' had already been checked in (by a scan or someone else).</p>');
            if (d.problems && d.problems.length) {
                lines.push('<div class="notice notice-warning inline"><p>' + d.problems.map(esc).join('<br>') + '</p></div>');
            }
            var left = pending().length;
            if (left) lines.push('<p class="ptv-door__muted">' + left + ' still to come on this order.</p>');
            $door.find('.ptv-door__body').html(lines.join('') || '<p>Nothing to check in.</p>');
            actions(left ? [['Check in more', 'more'], ['Done', 'close', true]] : [['Done', 'close', true]]);
            $door.find('[data-act="close"]').trigger('focus');
            if (!left && !(d.problems && d.problems.length)) closeTimer = setTimeout(closeDoor, 1600);
        });
    }

    // Brings the map and the counts in line with what the server now says.
    function paint(tickets, totalIn) {
        tickets.forEach(function(t) {
            if (!t.key) return;
            var used = t.status === 'used';
            var s = data.seats[t.section + '|' + t.key];
            if (s) {
                s.st = used ? 'checked_in' : 'sold';
                s.in = t.in;
            }
            var $seat = $map.find('.ptv-seat[data-s="' + t.section + '"][data-k="' + t.key + '"]');
            if (used) {
                $seat.removeClass('is-sold is-ordered').addClass('is-checked-in');
            } else if ($seat.hasClass('is-checked-in')) {
                $seat.removeClass('is-checked-in').addClass('is-sold');
            }
        });
        var $count = $('.ptv-in-count');
        if ($count.length && typeof totalIn === 'number') {
            $count.text(' \u00b7 ' + String($count.data('label')).replace('%d', totalIn)).prop('hidden', !totalIn);
        }
    }

    $door.on('click', 'button', function() {
        if (busy) return;
        var $b = $(this), act = $b.data('act');
        if ($b.hasClass('ptv-door__seat')) {
            var id = $b.data('id');
            if (picked[id]) {
                delete picked[id];
            } else {
                picked[id] = true;
            }
            $b.attr('aria-pressed', picked[id] ? 'true' : 'false');
            updatePickActions();
            return;
        }
        if (act === 'close') closeDoor();
        else if (act === 'all') checkIn(null);
        else if (act === 'pick' || act === 'more') { picked = {}; showPick(); }
        else if (act === 'back') showChoice();
        else if (act === 'some') checkIn(Object.keys(picked).map(Number));
    });
    $door.on('click', function(e) { if (e.target === this) closeDoor(); });
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && !$door.prop('hidden')) closeDoor();
    });

    // ── Printout ───────────────────────────────────────────────────────

    var $modal = $('.ptv-print');
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
