/**
 * Tickets Module - seat map on a ticket product page.
 *
 * Draws the venue layout, lets the buyer pick reserved seats and general
 * admission places, and sends the choice to azure_tickets_add_to_cart.
 * Each reserved seat is held on the server as it is tapped
 * (azure_tickets_pick), starting the buyer's countdown, and the map
 * refreshes while it is on screen so seats others take grey out. Prices,
 * limits and availability shown here are for guidance; the server checks
 * all of them again.
 */
(function($) {
    'use strict';

    var cfg = window.azureTicketsFrontend || {};
    // Smallest seat, in pixels, that can be chosen without zooming in first.
    var MIN_TAP = window.matchMedia && window.matchMedia('(pointer: coarse)').matches ? 22 : 12;
    var REFRESH_MS = 15000;

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    function money(n) {
        return (cfg.currency || '$') + Number(n || 0).toFixed(2);
    }

    function tint(hex, alpha) {
        var m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex || '');
        if (!m) return 'rgba(0,0,0,' + alpha + ')';
        return 'rgba(' + parseInt(m[1], 16) + ',' + parseInt(m[2], 16) + ',' + parseInt(m[3], 16) + ',' + alpha + ')';
    }

    function rowLabels(b) {
        var start = (b.startRow || 'A').charCodeAt(0), rows = [];
        for (var i = 0; i < b.rowCount; i++) rows.push(String.fromCharCode(start + i));
        return rows;
    }

    function seatNumbers(b) {
        var first = b.startSeat || 1, out = [];
        for (var i = 0; i < b.seatsPerRow; i++) out.push(first + i);
        return out;
    }

    /**
     * The cells of one row. A wheelchair space next to a "no seat" is drawn
     * two chairs wide. Same rule as the venue designer.
     */
    function rowCells(b, r) {
        var types = b.seatTypes || {}, nums = seatNumbers(b), out = [];
        for (var i = 0; i < nums.length; i++) {
            var key = r + '-' + nums[i], t = types[key] || '';
            var nextKey = i + 1 < nums.length ? r + '-' + nums[i + 1] : null;
            var nt = nextKey ? (types[nextKey] || '') : null;
            if (t === 'accessible' && nt === 'none') {
                out.push({ key: key, n: nums[i], type: t, span: 2 });
                i++;
            } else if (t === 'none' && nt === 'accessible') {
                out.push({ key: nextKey, n: nums[i + 1], type: 'accessible', span: 2 });
                i++;
            } else {
                out.push({ key: key, n: nums[i], type: t, span: 1 });
            }
        }
        return out;
    }

    function hasType(b, type) {
        var types = b.seatTypes || {};
        return Object.keys(types).some(function(k) { return types[k] === type; });
    }

    /** A block's bounding box on the canvas, allowing for its angle. */
    function blockBox(b) {
        var a = (Number(b.angle) || 0) * Math.PI / 180, cos = Math.abs(Math.cos(a)), sin = Math.abs(Math.sin(a));
        var w = b.width * cos + b.height * sin, h = b.width * sin + b.height * cos;
        return { x: b.x + b.width / 2 - w / 2, y: b.y + b.height / 2 - h / 2, w: w, h: h };
    }

    /**
     * The sections a buyer chooses between before picking seats. A block with
     * hidden seat numbers carries on from the block above it (its row C under
     * rows A–B), so it joins that block's section.
     */
    function sectionGroups(blocks, sections) {
        var sellable = blocks.filter(function(b) { return sections[b.id]; });
        var above = function(b) {
            var best = null;
            sellable.forEach(function(o) {
                if (o === b || o.y + o.height > b.y + 10 || o.x >= b.x + b.width || b.x >= o.x + o.width) return;
                if (!best || o.y + o.height > best.y + best.height) best = o;
            });
            return best;
        };
        var root = function(b) {
            for (var guard = 0, p; b.hideNumbers && guard < 10 && (p = above(b)); guard++) b = p;
            return b;
        };
        var groups = [], byRoot = {};
        sellable.forEach(function(b) {
            var r = root(b);
            if (!byRoot[r.id]) {
                byRoot[r.id] = { name: r.name, ids: [], onSale: false, box: null };
                groups.push(byRoot[r.id]);
            }
            var g = byRoot[r.id], bb = blockBox(b);
            g.ids.push(b.id);
            g.onSale = g.onSale || !!sections[b.id].on_sale;
            g.box = !g.box ? bb : (function(a) {
                var x = Math.min(a.x, bb.x), y = Math.min(a.y, bb.y);
                return { x: x, y: y, w: Math.max(a.x + a.w, bb.x + bb.w) - x, h: Math.max(a.y + a.h, bb.y + bb.h) - y };
            })(g.box);
        });
        return groups;
    }

    function SeatMap($root) {
        this.$root = $root;
        this.productId = $root.data('productId');
        this.requireNames = String($root.data('requireNames')) === '1';
        this.data = null;
        this.blocks = {};
        this.selected = [];      // [{section_id, seat_key, name}]
        this.general = {};       // section_id → {count, names[]}
        this.attested = false;   // buyer confirmed a wheelchair space is needed
        this.scale = 1;
        this.fitScale = 1;
        this.seq = 0;            // bumped by every hold/release; older answers are ignored
        this.busy = 0;           // hold/release requests in flight
        this.pending = {};       // "section|seat" being held right now
        this.timerOn = false;
        $root.data('seatmap', this);
        this.load();
        this.startRefreshing();
    }

    SeatMap.prototype = {

        load: function(message) {
            var self = this;
            $.post(cfg.ajaxUrl, { action: 'azure_tickets_seat_map', product_id: this.productId })
                .done(function(res) {
                    if (self.dead) return;
                    if (!res || !res.success) {
                        self.$root.html('<p class="pta-seatmap__error">' + esc((res && res.data && res.data.message) || 'The seating chart could not be loaded.') + '</p>');
                        return;
                    }
                    self.data = res.data;
                    self.blocks = {};
                    res.data.layout.blocks.forEach(function(b) { self.blocks[b.id] = b; });
                    self.selected = self.fromPicked(res.data.picked);
                    self.dropUnavailable();
                    self.build();
                    self.syncTimer();
                    if (message) self.notice(message, 'error');
                })
                .fail(function() {
                    self.$root.html('<p class="pta-seatmap__error">The seating chart could not be loaded. Please refresh the page.</p>');
                });
        },

        // ── Holding seats and keeping the map current ──────────────────

        /** The server's list of seats this buyer holds, keeping typed names. */
        fromPicked: function(picked) {
            var self = this;
            return (picked || []).map(function(p) {
                var i = self.indexOf(p.section_id, p.seat_key);
                return { section_id: p.section_id, seat_key: p.seat_key, name: i === -1 ? '' : self.selected[i].name };
            });
        },

        /** POST to azure_tickets_pick; done(ok, data, state) gets state only if no later request was sent. */
        request: function(params, done) {
            var self = this, seq = ++this.seq;
            this.busy++;
            this.tappedAt = Date.now();
            $.post(cfg.ajaxUrl, $.extend({ action: 'azure_tickets_pick', product_id: this.productId }, params))
                .done(function(res) {
                    var d = (res && res.data) || {};
                    var ok = !!(res && res.success);
                    var state = ok ? d : d.state;
                    done(ok, d, state && seq === self.seq ? state : null);
                })
                .fail(function() {
                    done(false, { message: 'Something went wrong. Please try again.' }, null);
                })
                .always(function() { self.busy--; });
        },

        hold: function(sid, key) {
            var self = this, ref = sid + '|' + key;
            this.pending[ref] = true;
            this.paintSeat(this.seatEl(sid, key));
            this.request({ op: 'hold', section_id: sid, seat_key: key, accessible_ok: this.attested ? 1 : 0 }, function(ok, d, state) {
                delete self.pending[ref];
                if (self.dead) return;
                if (state) {
                    self.applyState(state);
                } else {
                    if (ok && self.indexOf(sid, key) === -1) self.selected.push({ section_id: sid, seat_key: key, name: '' });
                    self.renderSummary();
                }
                self.paintSeat(self.seatEl(sid, key));
                if (ok) {
                    self.notice('');
                    return;
                }
                var msg = esc(d.message || 'That seat could not be held. Please try again.');
                if (d.login) msg += ' <a href="' + esc(cfg.loginUrl) + '">Sign in</a>';
                self.notice(msg, 'error');
            });
        },

        release: function(places) {
            if (!places.length) return;
            var self = this;
            this.request({ op: 'release', seats: JSON.stringify(places.map(function(p) {
                return { section_id: p.section_id, seat_key: p.seat_key };
            })) }, function(ok, d, state) {
                if (state && !self.dead) self.applyState(state);
            });
        },

        /** Lets go of every tapped seat when the buyer switches performance. */
        releaseAll: function() {
            if (this.selected.length) {
                $.post(cfg.ajaxUrl, { action: 'azure_tickets_pick', op: 'release_all', product_id: this.productId });
            }
        },

        /** Takes the server's view of seats, sections and this buyer's holds (an answer to a tap). */
        applyState: function(d) {
            var self = this;
            var before = this.selected;
            var lost = before.filter(function(s) {
                return !(d.picked || []).some(function(p) { return p.section_id === s.section_id && p.seat_key === s.seat_key; });
            });
            var next = this.fromPicked(d.picked);
            var keys = function(list) { return JSON.stringify(list.map(function(s) { return s.section_id + '|' + s.seat_key; })); };
            this.show(d.taken, d.sections, next, keys(next) !== keys(before));
            $.extend(this.data, d);
            if (lost.length && this.timerOn) {
                this.notice('Your hold on ' + esc(lost.map(function(s) { return self.seatName(s); }).join('; ')) + ' ended, so ' + (lost.length === 1 ? 'it was' : 'they were') + ' let go.', 'error');
            }
            this.syncTimer();
        },

        /**
         * Takes the shared refresh, which shows this buyer's own seats as
         * taken like everyone else's: those stay chosen (or "in your cart"),
         * and the sections' seats left count them back in.
         */
        applyShared: function(d) {
            var self = this, taken = {}, sections = $.extend(true, {}, this.data.sections);
            var mine = function(sid, k) { return (self.data.taken[sid] || {})[k] === 'mine'; };
            Object.keys(d.taken).forEach(function(sid) {
                Object.keys(d.taken[sid]).forEach(function(k) {
                    if (self.indexOf(sid, k) !== -1 || self.pending[sid + '|' + k]) return;
                    (taken[sid] = taken[sid] || {})[k] = mine(sid, k) ? 'mine' : 'taken';
                });
            });
            Object.keys(d.sections).forEach(function(sid) {
                if (!sections[sid]) return;
                sections[sid].on_sale = d.sections[sid].on_sale;
                sections[sid].left = d.sections[sid].left + self.selected.filter(function(s) { return s.section_id === sid; }).length;
            });
            this.show(taken, sections, this.selected, false);
        },

        /** Redraws only what changed. */
        show: function(taken, sections, selected, selectionChanged) {
            var self = this;
            var takenChanged = JSON.stringify(taken) !== JSON.stringify(this.data.taken);
            var sectionsChanged = JSON.stringify(sections) !== JSON.stringify(this.data.sections);
            var reshaped = Object.keys(sections).some(function(sid) {
                var was = self.data.sections[sid];
                return !was || was.on_sale !== sections[sid].on_sale || was.locked !== sections[sid].locked;
            });
            this.data.taken = taken;
            this.data.sections = sections;
            this.selected = selected;
            if (reshaped) {
                this.dropUnavailable();
                this.build();
                return;
            }
            if (takenChanged || selectionChanged) this.repaint();
            if (sectionsChanged) this.renderGeneral();
            if (selectionChanged) this.renderSummary();
        },

        /** The countdown runs while the buyer holds tapped seats. */
        syncTimer: function() {
            if (!window.ptaHoldTimer) return;
            var self = this;
            if (this.data.picked && this.data.picked.length && this.data.hold_left > 0) {
                window.ptaHoldTimer(this.data.hold_left, function() { return self.holdEnded(); });
                this.timerOn = true;
            } else if (this.timerOn) {
                window.ptaHoldTimer(0);
                this.timerOn = false;
            }
        },

        holdEnded: function() {
            var self = this;
            var msg = 'Your ' + (cfg.holdMinutes || 10) + ' minutes ran out, so your seats were let go. Please choose again.';
            this.timerOn = false;
            if (this.selected.length) {
                this.selected = [];
                this.repaint();
                this.renderSummary();
            }
            this.notice(msg, 'error');
            // A moment past the expiry, so the server sees the hold as lapsed;
            // its answer is current, unlike the shared refresh.
            setTimeout(function() {
                if (self.dead) return;
                self.request({ op: 'release_all' }, function(ok, d, state) {
                    if (state && !self.dead) self.applyState(state);
                });
            }, 2000);
            return msg;
        },

        startRefreshing: function() {
            var self = this;
            this.timer = setInterval(function() {
                if (!document.hidden) self.refresh();
            }, REFRESH_MS);
            $(document).on('visibilitychange.pta' + this.productId, function() {
                if (!document.hidden && Date.now() - (self.refreshedAt || 0) > 3000) self.refresh();
            });
        },

        /**
         * The shared refresh is fetched without cookies so the edge cache can
         * serve it, which also makes it a few seconds old. Straight after this
         * buyer's own tap the tap's answer is newer, so it waits a while.
         */
        refresh: function() {
            if (!this.data || this.dead || this.busy || this.refreshing || this.submitting || this.$root.prop('hidden') || !cfg.stateUrl || !window.fetch) return;
            if (Date.now() - (this.tappedAt || 0) < REFRESH_MS) return;
            var self = this, seq = this.seq;
            this.refreshing = true;
            this.refreshedAt = Date.now();
            var done = function() { self.refreshing = false; };
            window.fetch(cfg.stateUrl + encodeURIComponent(this.productId), { credentials: 'omit' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    done();
                    if (res && res.success && !self.dead && seq === self.seq && !self.busy && !self.submitting && Date.now() - (self.tappedAt || 0) >= REFRESH_MS) {
                        self.applyShared(res.data);
                    }
                }, done);
        },

        destroy: function() {
            this.dead = true;
            clearInterval(this.timer);
            $(document).off('visibilitychange.pta' + this.productId);
            $(window).off('resize.pta' + this.productId);
            if (this.timerOn && window.ptaHoldTimer) window.ptaHoldTimer(0);
        },

        section: function(id) {
            return this.data.sections[id] || null;
        },

        state: function(sid, key) {
            var t = this.data.taken[sid] || {};
            return t[key] || '';
        },

        seatType: function(sid, key) {
            var b = this.blocks[sid];
            return (b && b.seatTypes && b.seatTypes[key]) || 'normal';
        },

        isAda: function(s) {
            return this.seatType(s.section_id, s.seat_key) === 'accessible';
        },

        priceOf: function(s) {
            var sec = this.section(s.section_id);
            return this.isAda(s) ? sec.ada_price : sec.price;
        },

        countType: function(type) {
            var self = this;
            return this.selected.filter(function(s) { return self.seatType(s.section_id, s.seat_key) === type; }).length;
        },

        seatEl: function(sid, key) {
            return this.$root.find('.pta-seat[data-s="' + sid + '"][data-k="' + key + '"]')[0] || null;
        },

        seatName: function(s) {
            var parts = s.seat_key.split('-');
            return 'Row ' + parts[0] + ', Seat ' + parts[1];
        },

        dropUnavailable: function() {
            var self = this, gone = [];
            this.selected = this.selected.filter(function(s) {
                var sec = self.section(s.section_id);
                var keep = sec && sec.on_sale && !sec.locked && !self.state(s.section_id, s.seat_key);
                if (!keep) gone.push(s);
                return keep;
            });
            this.release(gone);
            Object.keys(this.general).forEach(function(sid) {
                var sec = self.section(sid);
                if (!sec || !sec.on_sale || sec.locked) delete self.general[sid];
                else self.general[sid].count = Math.min(self.general[sid].count, sec.left);
            });
        },

        // ── Building the page ──────────────────────────────────────────

        build: function() {
            var d = this.data;
            var html = [];
            var lockedNames = [];
            var hasAccessible = false, hasCompanion = false;
            var colors = [], prices = {};
            var self = this;
            Object.keys(d.sections).forEach(function(sid) {
                var b = self.blocks[sid], sec = d.sections[sid];
                if (sec.locked && sec.on_sale) lockedNames.push(b.name);
                if (b.type !== 'rectangle') return;
                if (sec.on_sale && !sec.locked) {
                    if (!prices[b.color]) { prices[b.color] = []; colors.push(b.color); }
                    if (prices[b.color].indexOf(sec.price) === -1) prices[b.color].push(sec.price);
                }
                hasAccessible = hasAccessible || hasType(b, 'accessible');
                hasCompanion = hasCompanion || hasType(b, 'companion');
            });

            html.push('<div class="pta-seatmap__notice" role="status" aria-live="polite"></div>');
            if (lockedNames.length) {
                html.push('<p class="pta-seatmap__family">🔒 <strong>' + esc(lockedNames.join(', ')) + '</strong> ' +
                    (d.signed_in
                        ? 'is reserved for cast and crew families. Your account isn\'t on the list for this show; if you think it should be, please contact the PTSA.'
                        : 'is reserved for cast and crew families. <a href="' + esc(cfg.loginUrl) + '">Sign in</a> to buy seats there.') + '</p>');
            }
            // Blocks carry no titles, so the legend is where each colour's price shows.
            var available = colors.length ? colors.map(function(c) {
                var p = prices[c].slice().sort(function(a, b) { return a - b; });
                return '<span><i class="pta-seat is-demo" style="--seat:' + esc(c) + '"></i>Available · ' +
                    (p.length > 1 ? 'from ' : '') + money(p[0]) + '</span>';
            }).join('') : '<span><i class="pta-seat is-demo"></i>Available</span>';
            var legend = '<div class="pta-seatmap__legend">' + available +
                '<span><i class="pta-seat is-demo is-selected"></i>Your choice</span>' +
                '<span><i class="pta-seat is-demo is-taken"></i>Taken</span>' +
                (hasAccessible ? '<span><i class="pta-seat is-demo is-accessible is-wide"></i>ADA space</span>' : '') +
                (hasCompanion ? '<span><i class="pta-seat is-demo is-companion"></i>ADA companion seat</span>' : '') +
                '</div>';
            // The product page keeps the legend beside the image; elsewhere it sits above the map.
            this.$legend = $('.pta-tickets__legend').first();
            if (this.$legend.length) this.$legend.html(legend);
            else html.push(legend);

            this.groups = sectionGroups(d.layout.blocks, d.sections);
            if (this.groups.length > 1) {
                html.push('<div class="pta-sections" role="group" aria-label="Sections">' +
                    '<button type="button" class="pta-sections__chip is-current" data-group="all" aria-pressed="true">All sections</button>' +
                    this.groups.map(function(g, i) {
                        return '<button type="button" class="pta-sections__chip' + (g.onSale ? '' : ' is-off') + '" data-group="' + i + '" aria-pressed="false">' + esc(g.name) + '</button>';
                    }).join('') + '</div>');
            }
            html.push('<div class="pta-seatmap__frame">' +
                '<div class="pta-seatmap__viewport"><div class="pta-seatmap__sizer"><div class="pta-seatmap__canvas" style="width:' + d.layout.canvas.width + 'px;height:' + d.layout.canvas.height + 'px">');
            d.layout.blocks.forEach(function(b) { html.push(self.blockHtml(b)); });
            html.push('</div></div></div>' +
                '<button type="button" class="pta-seatmap__back" data-group="all">‹ All sections</button></div>');
            html.push('<div class="pta-seatmap__bar">' +
                (this.groups.length > 1 ? '<p class="pta-seatmap__hint">Tap a section to zoom in, then tap your seats.</p>' : '') +
                '<span class="pta-seatmap__tools">' +
                    '<button type="button" class="pta-seatmap__zoom" data-zoom="out" aria-label="Zoom out">−</button>' +
                    '<button type="button" class="pta-seatmap__zoom" data-zoom="in" aria-label="Zoom in">+</button>' +
                '</span></div>');
            html.push('<div class="pta-seatmap__general"></div>');
            html.push('<div class="pta-seatmap__summary"></div>');
            this.$root.html(html.join(''));

            this.$viewport = this.$root.find('.pta-seatmap__viewport');
            this.$sizer = this.$root.find('.pta-seatmap__sizer');
            this.$canvas = this.$root.find('.pta-seatmap__canvas');
            this.hasAccessible = hasAccessible;
            this.bind();
            this.paintSelected();
            this.fit();
            this.renderGeneral();
            this.renderSummary();
        },

        /** Redraws the seats in place, keeping the zoom and keyboard focus. */
        repaint: function() {
            var self = this;
            var focus = document.activeElement && $(document.activeElement).is('.pta-seat') && this.$canvas[0].contains(document.activeElement)
                ? [document.activeElement.getAttribute('data-s'), document.activeElement.getAttribute('data-k')] : null;
            this.$canvas.html(this.data.layout.blocks.map(function(b) { return self.blockHtml(b); }).join(''));
            this.paintSelected();
            if (focus) {
                var el = this.seatEl(focus[0], focus[1]);
                if (el && !el.disabled) el.focus({ preventScroll: true });
            }
        },

        paintSelected: function() {
            var self = this;
            this.selected.forEach(function(s) { self.paintSeat(self.seatEl(s.section_id, s.seat_key)); });
            Object.keys(this.pending).forEach(function(ref) {
                var p = ref.split('|');
                self.paintSeat(self.seatEl(p[0], p[1]));
            });
        },

        blockHtml: function(b) {
            var style = 'left:' + b.x + 'px;top:' + b.y + 'px;width:' + b.width + 'px;height:' + b.height + 'px;';
            var sec = this.section(b.id);
            switch (b.type) {
                case 'rectangle':
                    return this.rectangleHtml(b, sec, style);
                case 'general_admission':
                    var cls = 'pta-block pta-block--ga' + (!sec.on_sale ? ' is-off' : '') + (sec.locked ? ' is-locked' : '');
                    return '<button type="button" class="' + cls + '" data-ga="' + esc(b.id) + '" style="' + style + 'background:' + tint(b.color, 0.25) + ';border-color:' + esc(b.color) + '">' +
                        '<strong>' + esc(b.name) + '</strong><span>' +
                        (sec.on_sale ? money(sec.price) + ' · ' + (sec.left > 0 ? sec.left + ' left' : 'Sold out') : 'Not on sale') +
                        (sec.locked ? ' · 🔒' : '') + '</span></button>';
                case 'area':
                    return '<div class="pta-block pta-block--area" style="' + style + 'background-color:' + esc(b.color) + '"><span>' + esc(b.label) + '</span></div>';
                case 'stage':
                    return '<div class="pta-block pta-block--stage" style="' + style + 'background:' + esc(b.color) + '"><span>' + esc(b.label) + '</span></div>';
                case 'label':
                    return '<div class="pta-block pta-block--label" style="' + style + 'color:' + esc(b.color) + ';font-size:' + b.fontSize + 'px"><span>' + esc(b.text) + '</span></div>';
                default:
                    return '';
            }
        },

        rectangleHtml: function(b, sec, style) {
            var self = this;
            var parts = [];
            var off = !sec.on_sale;
            var cls = 'pta-block pta-block--seats' + (off ? ' is-off' : '') + (sec.locked ? ' is-locked' : '');
            var rows = rowLabels(b);
            if (b.angle) style += 'transform:rotate(' + Number(b.angle) + 'deg);';
            // Sections not on sale take their grey from the stylesheet.
            if (!off) style += '--seat:' + esc(b.color) + ';';
            parts.push('<div class="' + cls + '" data-block="' + esc(b.id) + '" style="' + style + '">');
            parts.push('<div class="pta-rows" style="--n:' + b.seatsPerRow + '">');
            if (!b.hideNumbers) {
                parts.push('<div class="pta-rowline pta-nums' + (b.stagger ? ' stagger-a' : '') + '" aria-hidden="true"><span class="pta-row"></span><span class="pta-cells">');
                rowCells(b, rows[0]).forEach(function(cell) {
                    parts.push('<span class="pta-num" style="flex-grow:' + cell.span + '">' + (cell.type === 'none' ? '' : cell.n) + '</span>');
                });
                parts.push('</span></div>');
            }
            rows.forEach(function(r, i) {
                parts.push('<div class="pta-rowline' + (b.stagger ? (i % 2 ? ' stagger-b' : ' stagger-a') : '') + '"><span class="pta-row">' + r + '</span><span class="pta-cells">');
                rowCells(b, r).forEach(function(cell) {
                    if (cell.type === 'none') {
                        parts.push('<span class="pta-gap"></span>');
                        return;
                    }
                    var st = self.state(b.id, cell.key);
                    var c = 'pta-seat' + (cell.type === 'accessible' ? ' is-accessible' : '') + (cell.type === 'companion' ? ' is-companion' : '') + (cell.span > 1 ? ' is-wide' : '');
                    var label = b.name + ', Row ' + r + ', Seat ' + cell.n +
                        (cell.type === 'accessible' ? ', ADA space' : '') + (cell.type === 'companion' ? ', ADA companion seat' : '');
                    var title = b.name + ' · Row ' + r + ', Seat ' + cell.n + (off ? '' : ' · ' + money(cell.type === 'accessible' ? sec.ada_price : sec.price));
                    var disabled = false;
                    if (off) { c += ' is-off'; label += ', not on sale'; disabled = true; }
                    else if (st === 'taken') { c += ' is-taken'; label += ', taken'; disabled = true; }
                    else if (st === 'mine') { c += ' is-mine'; label += ', in your cart'; disabled = true; }
                    parts.push('<button type="button" class="' + c + '" style="flex-grow:' + cell.span + '" data-s="' + esc(b.id) + '" data-k="' + cell.key + '"' +
                        ' title="' + esc(title) + '" aria-label="' + esc(label) + '" aria-pressed="false"' + (disabled ? ' disabled' : '') + '></button>');
                });
                parts.push('</span></div>');
            });
            parts.push('</div></div>');
            return parts.join('');
        },

        // ── Zoom ───────────────────────────────────────────────────────

        maxHeight: function() {
            return Math.max(260, window.innerHeight * 0.75);
        },

        /** Fits the whole map, then notes whether seats are too small to tap there. */
        fit: function() {
            var w = this.$viewport.innerWidth() || 320, canvas = this.data.layout.canvas;
            this.fitScale = Math.min(1.5, w / canvas.width, this.maxHeight() / canvas.height);
            this.showGroup(this.current === undefined ? 'all' : this.current);
            var seat = this.$canvas.find('.pta-seat:not(:disabled)')[0] || this.$canvas.find('.pta-seat')[0];
            this.small = !!seat && seat.getBoundingClientRect().width / (this.scale / this.fitScale) < MIN_TAP;
            this.$root.toggleClass('is-small', this.small);
        },

        /** Zooms to a section, or to the whole map for "all". */
        showGroup: function(which) {
            var vp = this.$viewport[0];
            var g = which === 'all' ? null : this.groups[which];
            if (g) {
                var pad = 16, bb = g.box;
                this.setScale(Math.min(vp.clientWidth / (bb.w + pad * 2), this.maxHeight() / (bb.h + pad * 2)));
                vp.scrollLeft = (bb.x + bb.w / 2) * this.scale - vp.clientWidth / 2;
                vp.scrollTop = (bb.y + bb.h / 2) * this.scale - vp.clientHeight / 2;
            } else {
                this.setScale(this.fitScale);
            }
            this.current = g ? Number(which) : 'all';
            var current = String(this.current);
            this.$root.toggleClass('is-section', !!g);
            this.$root.find('.pta-sections__chip').each(function() {
                var on = $(this).attr('data-group') === current;
                $(this).toggleClass('is-current', on).attr('aria-pressed', on ? 'true' : 'false');
            });
        },

        groupOf: function(blockId) {
            for (var i = 0; i < this.groups.length; i++) {
                if (this.groups[i].ids.indexOf(blockId) !== -1) return i;
            }
            return -1;
        },

        setScale: function(s, cx, cy) {
            var vp = this.$viewport[0];
            var before = this.scale;
            cx = cx === undefined ? vp.clientWidth / 2 : cx;
            cy = cy === undefined ? vp.clientHeight / 2 : cy;
            var px = (vp.scrollLeft + cx) / before, py = (vp.scrollTop + cy) / before;
            this.scale = Math.max(this.fitScale, Math.min(4, s));
            var lw = this.data.layout.canvas.width, lh = this.data.layout.canvas.height;
            this.$canvas.css('transform', 'scale(' + this.scale + ')');
            this.$sizer.css({ width: lw * this.scale + 'px', height: lh * this.scale + 'px' });
            this.$viewport.css('height', Math.min(lh * this.scale, this.maxHeight()) + 'px');
            vp.scrollLeft = px * this.scale - cx;
            vp.scrollTop = py * this.scale - cy;
            this.$root.toggleClass('is-zoomed', this.scale > this.fitScale + 0.01);
        },

        zoomToSeat: function(el) {
            var r = el.getBoundingClientRect();
            var vr = this.$viewport[0].getBoundingClientRect();
            var factor = (MIN_TAP + 6) / Math.max(1, r.width);
            this.setScale(this.scale * factor, r.left + r.width / 2 - vr.left, r.top + r.height / 2 - vr.top);
        },

        // ── Choosing ───────────────────────────────────────────────────

        countIn: function(sid) {
            return this.selected.filter(function(s) { return s.section_id === sid; }).length + (this.general[sid] ? this.general[sid].count : 0);
        },

        total: function() {
            var n = this.selected.length;
            var self = this;
            Object.keys(this.general).forEach(function(sid) { n += self.general[sid].count; });
            return n;
        },

        toggleSeat: function(el) {
            var sid = el.getAttribute('data-s'), key = el.getAttribute('data-k');
            var sec = this.section(sid);
            if (this.pending[sid + '|' + key]) return;
            if (sec.locked) {
                this.notice(this.data.signed_in
                    ? 'Those seats are reserved for cast and crew families.'
                    : 'Those seats are reserved for cast and crew families. Please sign in to buy them.', 'info');
                return;
            }
            var i = this.indexOf(sid, key);
            var type = this.seatType(sid, key);
            var restricted = !this.data.release_accessible;
            if (i !== -1) {
                var gone = this.selected.splice(i, 1);
                this.paintSeat(el);
                var companions = type === 'accessible' && restricted ? this.dropExtraCompanions() : [];
                this.notice(companions.length ? 'An ADA companion seat was let go too, as it goes with an ADA space.' : '', 'info');
                this.release(gone.concat(companions));
                this.renderSummary();
                return;
            }
            if (this.total() + Object.keys(this.pending).length >= this.data.max) {
                this.notice(this.data.max > 0 ? 'You can choose up to ' + this.data.max + ' tickets for this performance.' : 'You already have the most tickets allowed for this performance in your cart.', 'error');
                return;
            }
            if (this.countIn(sid) >= sec.left) {
                this.notice('No more seats are available in ' + this.blocks[sid].name + '.', 'error');
                return;
            }
            if (type === 'companion' && restricted && this.countType('companion') >= this.countType('accessible')) {
                this.notice('ADA companion seats are for the companion of someone in an ADA space. Choose the ADA space first, then its companion seat.', 'info');
                return;
            }
            var self = this;
            if (type === 'accessible' && restricted && !this.attested) {
                this.attest(function() { self.attested = true; self.hold(sid, key); }, el);
                return;
            }
            this.hold(sid, key);
        },

        /** Keeps companion seats ≤ wheelchair spaces. @return {Array} the seats dropped */
        dropExtraCompanions: function() {
            var dropped = [];
            while (this.countType('companion') > this.countType('accessible')) {
                for (var i = this.selected.length - 1; i >= 0; i--) {
                    var s = this.selected[i];
                    if (this.seatType(s.section_id, s.seat_key) === 'companion') {
                        this.selected.splice(i, 1);
                        this.paintSeat(this.seatEl(s.section_id, s.seat_key));
                        dropped.push(s);
                        break;
                    }
                }
            }
            return dropped;
        },

        /** The self-attestation shown before the first wheelchair space is chosen. */
        attest: function(onConfirm, returnFocus) {
            var $m = $(
                '<div class="pta-modal" role="dialog" aria-modal="true" aria-labelledby="pta-ada-title">' +
                    '<div class="pta-modal__box">' +
                        '<h3 id="pta-ada-title">♿ ADA space</h3>' +
                        '<p>There are only a few ADA spaces, and they are kept for guests who need them. Each has an ADA companion seat beside it for someone in your party.</p>' +
                        '<label class="pta-modal__check"><input type="checkbox" class="pta-modal__ok"> I confirm that someone in my party uses a wheelchair or otherwise needs an accessible space, and meets the criteria for one of these limited seats.</label>' +
                        '<div class="pta-modal__actions">' +
                            '<button type="button" class="button pta-modal__cancel">Cancel</button>' +
                            '<button type="button" class="button alt pta-modal__confirm" disabled>Confirm</button>' +
                        '</div>' +
                    '</div>' +
                '</div>'
            ).appendTo(document.body);
            var close = function() {
                $m.remove();
                $(document).off('keydown.ptaModal');
                if (returnFocus) returnFocus.focus();
            };
            $m.find('.pta-modal__ok').on('change', function() { $m.find('.pta-modal__confirm').prop('disabled', !this.checked); }).trigger('focus');
            $m.find('.pta-modal__cancel').on('click', close);
            $m.on('click', function(e) { if (e.target === $m[0]) close(); });
            $m.find('.pta-modal__confirm').on('click', function() { close(); onConfirm(); });
            $(document).on('keydown.ptaModal', function(e) { if (e.key === 'Escape') close(); });
        },

        indexOf: function(sid, key) {
            for (var i = 0; i < this.selected.length; i++) {
                if (this.selected[i].section_id === sid && this.selected[i].seat_key === key) return i;
            }
            return -1;
        },

        paintSeat: function(el) {
            if (!el) return;
            var sid = el.getAttribute('data-s'), key = el.getAttribute('data-k');
            var on = this.indexOf(sid, key) !== -1;
            el.classList.toggle('is-selected', on);
            el.classList.toggle('is-pending', !!this.pending[sid + '|' + key]);
            el.setAttribute('aria-pressed', on ? 'true' : 'false');
        },

        stepGeneral: function(sid, delta) {
            var sec = this.section(sid);
            if (!sec || !sec.on_sale) return;
            if (sec.locked) {
                this.notice('Those places are reserved for cast and crew families.' + (this.data.signed_in ? '' : ' Please sign in to buy them.'), 'info');
                return;
            }
            var g = this.general[sid] || { count: 0, names: [] };
            var next = g.count + delta;
            if (delta > 0 && this.total() >= this.data.max) {
                this.notice('You can choose up to ' + this.data.max + ' tickets for this performance.', 'error');
                return;
            }
            if (delta > 0 && this.countIn(sid) >= sec.left) {
                this.notice('No more places are available in ' + this.blocks[sid].name + '.', 'error');
                return;
            }
            g.count = Math.max(0, next);
            if (g.count) this.general[sid] = g; else delete this.general[sid];
            this.notice('');
            this.renderGeneral();
            this.renderSummary();
        },

        renderGeneral: function() {
            var self = this;
            var rows = [];
            Object.keys(this.data.sections).forEach(function(sid) {
                var b = self.blocks[sid], sec = self.data.sections[sid];
                if (b.type !== 'general_admission' || !sec.on_sale) return;
                var n = self.general[sid] ? self.general[sid].count : 0;
                rows.push('<div class="pta-ga" data-ga-row="' + esc(sid) + '"><span class="pta-ga__name"><strong>' + esc(b.name) + '</strong> ' + money(sec.price) +
                    ' <small>' + (sec.locked ? '🔒 cast &amp; crew families' : (sec.left > 0 ? sec.left + ' left' : 'Sold out')) + '</small></span>' +
                    '<span class="pta-ga__step"><button type="button" data-step="-1" data-sid="' + esc(sid) + '" aria-label="One fewer"' + (n ? '' : ' disabled') + '>−</button>' +
                    '<output aria-live="polite">' + n + '</output>' +
                    '<button type="button" data-step="1" data-sid="' + esc(sid) + '" aria-label="One more"' + (sec.left > 0 && !sec.locked ? '' : ' disabled') + '>+</button></span></div>');
            });
            this.$root.find('.pta-seatmap__general').html(rows.length ? '<h3>General admission</h3>' + rows.join('') : '');
        },

        renderSummary: function() {
            var self = this;
            var items = [];
            var total = 0;
            var counts = { regular: 0, ada: 0 }, prices = { regular: [], ada: [] };
            this.selected.forEach(function(s, i) {
                var b = self.blocks[s.section_id];
                var parts = s.seat_key.split('-');
                var type = self.seatType(s.section_id, s.seat_key);
                var kind = type === 'accessible' ? 'ada' : 'regular';
                var price = self.priceOf(s);
                counts[kind]++;
                if (prices[kind].indexOf(price) === -1) prices[kind].push(price);
                total += price;
                items.push(self.itemHtml('seat', i, b.name + ' · Row ' + parts[0] + ', Seat ' + parts[1] +
                    (type === 'accessible' ? ' ♿' : '') + (type === 'companion' ? ' (ADA companion)' : ''), price, s.name));
            });
            Object.keys(this.general).forEach(function(sid) {
                var g = self.general[sid], b = self.blocks[sid], sec = self.section(sid);
                for (var j = 0; j < g.count; j++) {
                    counts.regular++;
                    if (prices.regular.indexOf(sec.price) === -1) prices.regular.push(sec.price);
                    total += sec.price;
                    items.push(self.itemHtml('ga', sid + ':' + j, b.name, sec.price, g.names[j] || ''));
                }
            });

            var each = function(list) {
                return list.length === 1 ? money(list[0]) + ' each' : (list.length ? 'from ' + money(Math.min.apply(null, list)) : '');
            };
            var html = ['<div class="pta-counts" aria-live="polite">' +
                '<div class="pta-count"><span class="pta-count__label">Regular seat</span><output class="pta-count__n">' + counts.regular + '</output><small>' + each(prices.regular) + '</small></div>' +
                (this.hasAccessible ? '<div class="pta-count"><span class="pta-count__label">ADA seat ♿</span><output class="pta-count__n">' + counts.ada + '</output><small>' + each(prices.ada) + '</small></div>' : '') +
                '</div>'];
            if (!items.length) {
                html.push('<p class="pta-summary__empty">Tap a seat on the map to hold it, and tap it again to let it go. You have ' + (cfg.holdMinutes || 10) + ' minutes from your first seat to check out.</p>');
            } else {
                html.push('<h3>Your tickets</h3><ul class="pta-summary__list">' + items.join('') + '</ul>');
                html.push('<p class="pta-summary__total">Total <strong>' + money(total) + '</strong></p>');
                html.push('<button type="button" class="button alt pta-summary__add">Add ' + items.length + (items.length === 1 ? ' ticket' : ' tickets') + ' to cart</button>');
            }
            this.$root.find('.pta-seatmap__summary').html(html.join(''));
        },

        itemHtml: function(kind, ref, label, price, name) {
            return '<li class="pta-summary__item"><span class="pta-summary__label">' + esc(label) + '</span><span class="pta-summary__price">' + money(price) + '</span>' +
                '<input type="text" class="pta-summary__name" maxlength="80" data-kind="' + kind + '" data-ref="' + esc(ref) + '" value="' + esc(name) + '"' +
                ' placeholder="' + (this.requireNames ? 'Name on ticket' : 'Name on ticket (optional)') + '"' + (this.requireNames ? ' required' : '') +
                ' aria-label="Name on ticket for ' + esc(label) + '">' +
                (kind === 'seat' ? '<button type="button" class="pta-summary__remove" data-ref="' + esc(ref) + '" aria-label="Remove ' + esc(label) + '">×</button>' : '') +
                '</li>';
        },

        notice: function(msg, kind) {
            this.$root.find('.pta-seatmap__notice').attr('data-kind', kind || '').html(msg);
        },

        submit: function($btn) {
            var self = this;
            var general = Object.keys(this.general).map(function(sid) {
                return { section_id: sid, count: self.general[sid].count, names: self.general[sid].names };
            });
            if (this.requireNames && this.$root.find('.pta-summary__name').filter(function() { return !$.trim(this.value); }).length) {
                this.notice('Please enter a name for each ticket.', 'error');
                return;
            }
            if (this.busy) {
                this.notice('Still holding your last seat — try again in a moment.', 'info');
                return;
            }
            $btn.prop('disabled', true).text('Adding to cart…');
            this.submitting = true;
            $.post(cfg.ajaxUrl, {
                action: 'azure_tickets_add_to_cart',
                product_id: this.productId,
                seats: JSON.stringify(this.selected),
                general: JSON.stringify(general),
                accessible_ok: this.attested ? 1 : 0
            }).done(function(res) {
                if (res && res.success) {
                    var $extras = self.$root.parent().find('.pta-extras--step');
                    if (self.data.extras && $extras.length) {
                        self.$root.prop('hidden', true);
                        $('.pta-tickets__performances, .pta-tickets__legend').prop('hidden', true);
                        $extras.prop('hidden', false);
                        $extras[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
                        self.submitting = false;
                        self.timerOn = false;
                        if (window.ptaHoldTimer) window.ptaHoldTimer(res.data.hold_left, null);
                        return;
                    }
                    window.location.href = res.data.cart_url || cfg.cartUrl;
                    return;
                }
                self.submitting = false;
                var d = (res && res.data) || {};
                var msg = esc(d.message || 'Those tickets could not be added. Please try again.');
                if (d.login) msg += ' <a href="' + esc(cfg.loginUrl) + '">Sign in</a>';
                if (d.reload) {
                    self.load(msg);
                    return;
                }
                self.notice(msg, 'error');
                self.renderSummary();
            }).fail(function() {
                self.submitting = false;
                self.notice('Something went wrong. Please try again.', 'error');
                self.renderSummary();
            });
        },

        bind: function() {
            var self = this;
            this.$root.off('.pta');
            this.$root.on('click.pta', '.pta-seat:not(.is-demo)', function() {
                if (this.disabled) return;
                if (this.getBoundingClientRect().width < MIN_TAP && self.scale < 4) {
                    var g = self.groupOf(this.getAttribute('data-s'));
                    if (self.current === 'all' && g !== -1 && self.groups.length > 1) self.showGroup(g);
                    else self.zoomToSeat(this);
                    return;
                }
                self.toggleSeat(this);
            });
            this.$root.on('click.pta', '.pta-block--seats', function(e) {
                if (!self.small || self.current !== 'all' || $(e.target).closest('.pta-seat').length) return;
                var g = self.groupOf($(this).attr('data-block'));
                if (g !== -1) self.showGroup(g);
            });
            this.$root.on('click.pta', '.pta-sections__chip, .pta-seatmap__back', function() {
                var g = $(this).attr('data-group');
                self.showGroup(g === 'all' ? 'all' : parseInt(g, 10));
            });
            this.$root.on('click.pta', '[data-ga]', function() {
                var $row = self.$root.find('[data-ga-row="' + $(this).attr('data-ga') + '"]');
                if ($row.length) {
                    $row[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    $row.addClass('is-flash');
                    setTimeout(function() { $row.removeClass('is-flash'); }, 900);
                }
                if (self.section($(this).attr('data-ga')).locked) self.stepGeneral($(this).attr('data-ga'), 1);
            });
            this.$root.on('click.pta', '[data-step]', function() {
                self.stepGeneral($(this).attr('data-sid'), parseInt($(this).attr('data-step'), 10));
            });
            this.$root.on('click.pta', '.pta-seatmap__zoom', function() {
                self.setScale(self.scale * ($(this).data('zoom') === 'in' ? 1.5 : 1 / 1.5));
            });
            this.$root.on('input.pta', '.pta-summary__name', function() {
                var ref = String($(this).data('ref'));
                if ($(this).data('kind') === 'seat') {
                    self.selected[parseInt(ref, 10)].name = this.value;
                } else {
                    var p = ref.split(':');
                    self.general[p[0]].names[parseInt(p[1], 10)] = this.value;
                }
            });
            this.$root.on('click.pta', '.pta-summary__remove', function() {
                var gone = self.selected.splice(parseInt($(this).data('ref'), 10), 1);
                self.paintSeat(self.seatEl(gone[0].section_id, gone[0].seat_key));
                var companions = self.data.release_accessible ? [] : self.dropExtraCompanions();
                if (companions.length) {
                    self.notice('An ADA companion seat was let go too, as it goes with an ADA space.', 'info');
                }
                self.release(gone.concat(companions));
                self.renderSummary();
            });
            this.$root.on('click.pta', '.pta-summary__add', function() { self.submit($(this)); });

            // Phones resize the window as the address bar slides while scrolling,
            // so only a change of width refits the map.
            var resizeTimer, width = window.innerWidth;
            $(window).off('resize.pta' + this.productId).on('resize.pta' + this.productId, function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth === width) return;
                    width = window.innerWidth;
                    self.fit();
                }, 150);
            });
        }
    };

    // Performances of the same product swap the seat map in place; seats
    // tapped on the old one are let go.
    function choosePerformance($btn) {
        var $wrap = $btn.closest('.pta-tickets');
        var $old = $('.pta-seatmap').first();
        var id = String($btn.attr('data-performance'));
        if (!$old.length || String($old.attr('data-product-id')) === id) return;
        var old = $old.data('seatmap');
        if (old) {
            old.releaseAll();
            old.destroy();
        }
        var $map = $('<div class="pta-seatmap"></div>')
            .attr({ 'data-product-id': id, 'data-require-names': $old.attr('data-require-names') })
            .html('<p class="pta-seatmap__loading">Loading seating chart…</p>');
        $old.replaceWith($map);
        $wrap.find('.pta-tickets__performance[data-performance]').removeClass('is-current').attr('aria-pressed', 'false');
        $btn.addClass('is-current').attr('aria-pressed', 'true');
        var date = $btn.attr('data-date') || '';
        $wrap.find('.pta-tickets__date-text').text(date);
        $wrap.find('.pta-tickets__date').prop('hidden', !date);
        if (window.history && history.replaceState && window.URL) {
            var url = new URL(window.location.href);
            url.searchParams.set('performance', id);
            history.replaceState(null, '', url.toString());
            cfg.loginUrl = String(cfg.loginUrl).replace(/redirect_to=[^&]*/, 'redirect_to=' + encodeURIComponent(url.toString()));
        }
        new SeatMap($map);
    }

    $(function() {
        $('.pta-seatmap').each(function() { new SeatMap($(this)); });
        $(document).on('click', '.pta-tickets__performance[data-performance]', function() {
            if (!this.disabled) choosePerformance($(this));
        });
    });

})(jQuery);
