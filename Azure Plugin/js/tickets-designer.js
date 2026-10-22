/**
 * Tickets Module - venue seating designer.
 *
 * Every block keeps the `id` it was saved with: holds, sales and tickets
 * refer to sections by id, so a section must never be re-numbered. New
 * blocks get a random id once, when they are created.
 */
(function($) {
    'use strict';

    var GRID = 10;
    var SEAT = 16;
    var SELLABLE = ['rectangle', 'general_admission'];

    var DEFAULTS = {
        rectangle: { name: 'Section', price: 10, startRow: 'A', rowCount: 4, seatsPerRow: 10, startSeat: 1, angle: 0, stagger: false, hideNumbers: false, color: '#4caf50', seatTypes: {} },
        general_admission: { name: 'General Admission', price: 10, capacity: 50, color: '#ff9800', width: 200, height: 100 },
        area: { label: 'Gym mat – kids floor seating', color: '#d9cdea', width: 260, height: 110 },
        stage: { label: 'STAGE', color: '#333333', width: 400, height: 60 },
        label: { text: 'Label', fontSize: 14, color: '#333333', width: 120, height: 30 },
        aisle: { orientation: 'horizontal', color: '#e0e0e0', width: 200, height: 20 }
    };

    function newId() {
        return 'sec-' + Math.random().toString(36).slice(2, 8) + Date.now().toString(36).slice(-4);
    }

    function snap(v) {
        return Math.round(v / GRID) * GRID;
    }

    function clamp(v, lo, hi) {
        v = parseInt(v, 10);
        return isNaN(v) ? lo : Math.max(lo, Math.min(hi, v));
    }

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    function rowLabels(b) {
        var start = (b.startRow || 'A').charCodeAt(0), rows = [];
        for (var i = 0; i < b.rowCount; i++) {
            rows.push(String.fromCharCode(start + i));
        }
        return rows;
    }

    function seatNumbers(b) {
        var out = [];
        for (var i = 0; i < b.seatsPerRow; i++) out.push(b.startSeat + i);
        return out;
    }

    /**
     * The cells of one row. A wheelchair space next to a "no seat" is drawn
     * two chairs wide, the way the room is set up (two chairs removed).
     * Same rule as the buyer's seat map.
     */
    function rowCells(b, r) {
        var nums = seatNumbers(b), out = [];
        for (var i = 0; i < nums.length; i++) {
            var key = r + '-' + nums[i], t = b.seatTypes[key] || '';
            var nextKey = i + 1 < nums.length ? r + '-' + nums[i + 1] : null;
            var nt = nextKey ? (b.seatTypes[nextKey] || '') : null;
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

    function seatCounts(b) {
        var total = 0, accessible = 0, companion = 0;
        rowLabels(b).forEach(function(r) {
            seatNumbers(b).forEach(function(n) {
                var t = b.seatTypes[r + '-' + n];
                if (t !== 'none') {
                    total++;
                    if (t === 'accessible') accessible++;
                    if (t === 'companion') companion++;
                }
            });
        });
        return { total: total, accessible: accessible, companion: companion };
    }

    function fitRectangle(b) {
        b.width = Math.max(80, snap(b.seatsPerRow * SEAT + 30 + (b.stagger ? SEAT / 2 : 0)));
        b.height = Math.max(50, snap(b.rowCount * SEAT + 34));
    }

    var Designer = {
        $canvas: null,
        $sizer: null,
        blocks: [],
        selectedId: null,
        zoom: 1,
        tool: 'move',
        dirty: false,
        gesture: null,

        init: function() {
            this.$canvas = $('#venue-canvas');
            if (!this.$canvas.length) return;
            this.$canvas.wrap('<div class="designer-canvas-sizer"></div>');
            this.$sizer = this.$canvas.parent();

            var layout = this.$canvas.data('layout');
            if (typeof layout === 'string') {
                try { layout = JSON.parse(layout); } catch (e) { layout = null; }
            }
            layout = layout || { canvas: { width: 800, height: 600 }, blocks: [] };
            this.load(layout);
            this.bind();
            this.dirty = false;
        },

        load: function(layout) {
            var self = this;
            this.$canvas.empty();
            this.blocks = [];
            this.selectedId = null;
            $('#canvas-width').val(layout.canvas.width);
            $('#canvas-height').val(layout.canvas.height);
            this.setCanvasSize(layout.canvas.width, layout.canvas.height);
            (layout.blocks || []).forEach(function(b) {
                self.blocks.push(self.normalise(b));
            });
            this.blocks.forEach(function(b) { self.render(b); });
            this.showSettings();
            this.updateCapacity();
        },

        normalise: function(b) {
            var d = DEFAULTS[b.type] || DEFAULTS.label;
            b = $.extend(true, {}, d, b);
            if (!b.id) b.id = newId();
            if (b.type === 'rectangle') {
                b.rowCount = clamp(b.rowCount, 1, 26 - (b.startRow.charCodeAt(0) - 65));
                b.seatsPerRow = clamp(b.seatsPerRow, 1, 100);
                b.startSeat = clamp(b.startSeat, 1, 1000 - b.seatsPerRow);
                b.angle = clamp(b.angle, -45, 45);
                b.stagger = !!b.stagger;
                b.hideNumbers = !!b.hideNumbers;
                if (Array.isArray(b.seatTypes)) b.seatTypes = {};
                if (!b.width || !b.height) fitRectangle(b);
            }
            return b;
        },

        // ── Rendering ──────────────────────────────────────────────────

        render: function(b) {
            var $el = $('#vb-' + b.id);
            if (!$el.length) {
                $el = $('<div>').attr({ id: 'vb-' + b.id, 'data-id': b.id }).appendTo(this.$canvas);
            }
            var classes = 'venue-block type-' + b.type + (b.id === this.selectedId ? ' selected' : '') +
                (b.id === this.selectedId && this.tool !== 'move' ? ' is-painting' : '');
            $el.attr('class', classes).css({
                left: b.x + 'px',
                top: b.y + 'px',
                width: b.width + 'px',
                height: b.height + 'px',
                backgroundColor: (b.type === 'label') ? 'transparent' : b.color,
                color: b.type === 'label' ? b.color : '',
                fontSize: b.type === 'label' ? b.fontSize + 'px' : '',
                transform: b.angle ? 'rotate(' + b.angle + 'deg)' : ''
            });

            var html = '';
            switch (b.type) {
                case 'rectangle':
                    var counts = seatCounts(b);
                    var last = b.startSeat + b.seatsPerRow - 1;
                    html = '<div class="block-head">' + esc(b.name) + ' <span class="block-seats">' + b.startSeat + '–' + last + ' · ' + counts.total + ' seats' +
                        (counts.accessible ? ' · ' + counts.accessible + ' ♿' : '') + (b.hideNumbers ? ' · no seat numbers' : '') + '</span></div>' + this.gridHtml(b);
                    break;
                case 'general_admission':
                    html = '<div class="block-label">' + esc(b.name) + '<div class="block-seats">' + b.capacity + ' places, unnumbered</div></div>';
                    break;
                case 'area':
                    html = '<div class="block-label">' + esc(b.label) + '<div class="block-seats">Not sold</div></div>';
                    break;
                case 'stage':
                    html = '<div class="block-label">' + esc(b.label) + '</div>';
                    break;
                case 'label':
                    html = '<div class="block-label">' + esc(b.text) + '</div>';
                    break;
            }
            $el.html(html + '<div class="resize-handle se" title="Resize"></div>');
        },

        gridHtml: function(b) {
            var parts = ['<div class="seat-rows" style="--n:' + b.seatsPerRow + '">'];
            rowLabels(b).forEach(function(r, i) {
                parts.push('<div class="seat-row' + (b.stagger ? (i % 2 ? ' stagger-b' : ' stagger-a') : '') + '"><span class="row-label">' + r + '</span><span class="seat-cells">');
                rowCells(b, r).forEach(function(c) {
                    parts.push('<span class="seat-cell' + (c.type ? ' is-' + c.type : '') + '" style="flex-grow:' + c.span + '" data-key="' + c.key + '" title="Row ' + r + ', Seat ' + c.n + '"></span>');
                });
                parts.push('</span></div>');
            });
            parts.push('</div>');
            return parts.join('');
        },

        setCanvasSize: function(w, h) {
            this.canvasW = clamp(w, 300, 3000);
            this.canvasH = clamp(h, 200, 3000);
            this.$canvas.css({ width: this.canvasW + 'px', height: this.canvasH + 'px', minWidth: 0, minHeight: 0 });
            this.applyZoom();
        },

        setZoom: function(z) {
            this.zoom = Math.max(0.3, Math.min(3, Math.round(z * 10) / 10));
            this.applyZoom();
            $('#zoom-level').text(Math.round(this.zoom * 100) + '%');
        },

        applyZoom: function() {
            this.$canvas.css({ transform: 'scale(' + this.zoom + ')', transformOrigin: '0 0' });
            this.$sizer.css({ width: this.canvasW * this.zoom + 'px', height: this.canvasH * this.zoom + 'px' });
        },

        updateCapacity: function() {
            var total = 0;
            this.blocks.forEach(function(b) {
                if (b.type === 'rectangle') total += seatCounts(b).total;
                if (b.type === 'general_admission') total += b.capacity;
            });
            $('#total-capacity').text(total);
        },

        // ── Selection and settings ─────────────────────────────────────

        get: function(id) {
            for (var i = 0; i < this.blocks.length; i++) {
                if (this.blocks[i].id === id) return this.blocks[i];
            }
            return null;
        },

        select: function(id) {
            var prev = this.selectedId;
            this.selectedId = id;
            if (prev !== id) this.tool = 'move';
            if (prev && prev !== id && this.get(prev)) this.render(this.get(prev));
            if (id) this.render(this.get(id));
            this.showSettings();
        },

        showSettings: function() {
            var $panel = $('#block-settings-panel');
            var b = this.selectedId ? this.get(this.selectedId) : null;
            if (!b) {
                $panel.html('<p class="no-selection">Select a block to edit it, or add one from the left.</p>');
                return;
            }
            var $tpl = $('#' + b.type + '-settings');
            $panel.html($tpl.length ? $tpl.html() : '');
            $panel.find('.block-setting').each(function() {
                var key = $(this).data('setting');
                if (b[key] === undefined) return;
                if (this.type === 'checkbox') $(this).prop('checked', !!b[key]);
                else $(this).val(b[key]);
            });
            this.refreshSeatTools();
        },

        refreshSeatTools: function() {
            var b = this.selectedId ? this.get(this.selectedId) : null;
            var $panel = $('#block-settings-panel');
            $panel.find('.seat-tool').each(function() {
                $(this).toggleClass('button-primary', $(this).data('tool') === Designer.tool);
            });
            if (b && b.type === 'rectangle') {
                var c = seatCounts(b);
                $panel.find('.seat-summary').text(c.total + ' seats (' + b.startSeat + '–' + (b.startSeat + b.seatsPerRow - 1) + ' in each row), ' +
                    c.accessible + ' wheelchair, ' + c.companion + ' companion');
                $panel.find('.angle-value').text(b.angle + '°');
            }
        },

        change: function(key, raw) {
            var b = this.get(this.selectedId);
            if (!b) return;
            var v = raw;
            switch (key) {
                case 'price':
                    v = Math.max(0, Math.round((parseFloat(raw) || 0) * 100) / 100);
                    break;
                case 'capacity':
                    v = clamp(raw, 1, 10000);
                    break;
                case 'fontSize':
                    v = clamp(raw, 8, 72);
                    break;
                case 'rowCount':
                case 'seatsPerRow':
                case 'startRow':
                    if (key === 'startRow') b.startRow = /^[A-Z]$/.test(raw) ? raw : 'A';
                    if (key === 'seatsPerRow') b.seatsPerRow = clamp(raw, 1, 100);
                    b.rowCount = clamp(key === 'rowCount' ? raw : b.rowCount, 1, 26 - (b.startRow.charCodeAt(0) - 65));
                    b.startSeat = clamp(b.startSeat, 1, 1000 - b.seatsPerRow);
                    this.pruneSeatTypes(b);
                    fitRectangle(b);
                    v = b[key];
                    break;
                case 'startSeat':
                    // Marked seats keep their place in the row when the numbering moves.
                    v = clamp(raw, 1, 1000 - b.seatsPerRow);
                    var shift = v - b.startSeat, moved = {};
                    Object.keys(b.seatTypes).forEach(function(k) {
                        var p = k.split('-');
                        moved[p[0] + '-' + (parseInt(p[1], 10) + shift)] = b.seatTypes[k];
                    });
                    b.seatTypes = moved;
                    break;
                case 'angle':
                    v = clamp(raw, -45, 45);
                    break;
                case 'stagger':
                    b.stagger = !!raw;
                    fitRectangle(b);
                    v = b.stagger;
                    break;
                case 'orientation':
                    var long = Math.max(b.width, b.height, 60);
                    b.width = raw === 'vertical' ? 20 : long;
                    b.height = raw === 'vertical' ? long : 20;
                    break;
            }
            b[key] = v;
            this.render(b);
            this.refreshSeatTools();
            this.updateCapacity();
            this.dirty = true;
        },

        pruneSeatTypes: function(b) {
            var keep = {};
            rowLabels(b).forEach(function(r) {
                seatNumbers(b).forEach(function(n) {
                    if (b.seatTypes[r + '-' + n]) keep[r + '-' + n] = b.seatTypes[r + '-' + n];
                });
            });
            b.seatTypes = keep;
        },

        add: function(type, x, y) {
            var b = this.normalise({ type: type });
            if (type === 'rectangle') fitRectangle(b);
            b.x = snap(Math.max(0, Math.min(x - b.width / 2, this.canvasW - b.width)));
            b.y = snap(Math.max(0, Math.min(y - b.height / 2, this.canvasH - b.height)));
            this.blocks.push(b);
            this.render(b);
            this.select(b.id);
            this.updateCapacity();
            this.dirty = true;
        },

        duplicate: function() {
            var src = this.get(this.selectedId);
            if (!src) return;
            var b = $.extend(true, {}, src, { id: newId() });
            b.x = Math.min(src.x + 20, this.canvasW - b.width);
            b.y = Math.min(src.y + 20, this.canvasH - b.height);
            if (b.name) b.name = b.name + ' (copy)';
            this.blocks.push(b);
            this.render(b);
            this.select(b.id);
            this.updateCapacity();
            this.dirty = true;
        },

        remove: function() {
            var b = this.get(this.selectedId);
            if (!b) return;
            if (SELLABLE.indexOf(b.type) !== -1 &&
                !window.confirm('Delete "' + b.name + '"? Performances using this venue will stop selling these seats. Tickets already sold are kept, but the seats will no longer appear on the map.')) {
                return;
            }
            this.blocks = this.blocks.filter(function(x) { return x.id !== b.id; });
            $('#vb-' + b.id).remove();
            this.selectedId = null;
            this.showSettings();
            this.updateCapacity();
            this.dirty = true;
        },

        paint: function(b, key) {
            var g = this.gesture;
            if (!g.paintType) {
                g.paintType = (b.seatTypes[key] === this.tool || this.tool === 'normal') ? 'normal' : this.tool;
            }
            if (g.painted[key]) return;
            g.painted[key] = true;
            if (g.paintType === 'normal') {
                delete b.seatTypes[key];
            } else {
                b.seatTypes[key] = g.paintType;
            }
            $('#vb-' + b.id + ' .seat-cell[data-key="' + key + '"]')
                .removeClass('is-accessible is-companion is-none')
                .addClass(g.paintType === 'normal' ? '' : 'is-' + g.paintType);
            this.dirty = true;
        },

        // ── Pointer gestures (mouse, pen and touch) ────────────────────

        pointerDown: function(e) {
            var $target = $(e.target);
            var $block = $target.closest('.venue-block');
            if (!$block.length) {
                if (e.target === this.$canvas[0]) this.select(null);
                return;
            }
            var b = this.get($block.attr('data-id'));
            if (!b) return;
            e.preventDefault();
            if (b.id !== this.selectedId) this.select(b.id);

            if (this.tool !== 'move' && b.type === 'rectangle' && $target.hasClass('seat-cell')) {
                this.gesture = { kind: 'paint', block: b, painted: {}, paintType: null };
                this.paint(b, $target.data('key'));
                return;
            }
            this.gesture = {
                kind: $target.hasClass('resize-handle') ? 'resize' : 'move',
                block: b,
                startX: e.clientX,
                startY: e.clientY,
                x: b.x, y: b.y, w: b.width, h: b.height
            };
        },

        pointerMove: function(e) {
            var g = this.gesture;
            if (!g) return;
            if (g.kind === 'paint') {
                var el = document.elementFromPoint(e.clientX, e.clientY);
                if (el && el.classList.contains('seat-cell') && $(el).closest('.venue-block').attr('data-id') === g.block.id) {
                    this.paint(g.block, el.getAttribute('data-key'));
                }
                return;
            }
            var dx = (e.clientX - g.startX) / this.zoom;
            var dy = (e.clientY - g.startY) / this.zoom;
            var b = g.block;
            if (g.kind === 'move') {
                b.x = snap(Math.max(0, Math.min(g.x + dx, this.canvasW - b.width)));
                b.y = snap(Math.max(0, Math.min(g.y + dy, this.canvasH - b.height)));
            } else {
                b.width = Math.max(20, snap(Math.min(g.w + dx, this.canvasW - b.x)));
                b.height = Math.max(20, snap(Math.min(g.h + dy, this.canvasH - b.y)));
            }
            $('#vb-' + b.id).css({ left: b.x + 'px', top: b.y + 'px', width: b.width + 'px', height: b.height + 'px' });
            this.dirty = true;
        },

        pointerUp: function() {
            if (this.gesture && this.gesture.kind === 'paint') {
                this.render(this.gesture.block);
                this.refreshSeatTools();
                this.updateCapacity();
            }
            this.gesture = null;
        },

        // ── Wiring ─────────────────────────────────────────────────────

        bind: function() {
            var self = this;
            var canvasEl = this.$canvas[0];

            canvasEl.addEventListener('pointerdown', function(e) { self.pointerDown(e); });
            document.addEventListener('pointermove', function(e) { self.pointerMove(e); });
            document.addEventListener('pointerup', function() { self.pointerUp(); });
            document.addEventListener('pointercancel', function() { self.pointerUp(); });

            $('.block-item').on('click', function(e) {
                e.preventDefault();
                var $c = $('.designer-canvas-container');
                var cx = ($c.scrollLeft() + $c.innerWidth() / 2 - self.$sizer.position().left) / self.zoom;
                var cy = ($c.scrollTop() + $c.innerHeight() / 2 - self.$sizer.position().top) / self.zoom;
                self.add($(this).data('type'), cx || self.canvasW / 2, cy || self.canvasH / 2);
            });
            document.querySelectorAll('.block-item').forEach(function(item) {
                item.addEventListener('dragstart', function(e) {
                    e.dataTransfer.setData('text/plain', item.getAttribute('data-type'));
                    e.dataTransfer.effectAllowed = 'copy';
                });
            });
            canvasEl.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
            });
            canvasEl.addEventListener('drop', function(e) {
                e.preventDefault();
                var type = e.dataTransfer.getData('text/plain');
                if (!DEFAULTS[type]) return;
                var r = canvasEl.getBoundingClientRect();
                self.add(type, (e.clientX - r.left) / self.zoom, (e.clientY - r.top) / self.zoom);
            });

            $(document).on('input change', '#block-settings-panel .block-setting', function() {
                self.change($(this).data('setting'), this.type === 'checkbox' ? this.checked : $(this).val());
            });
            $('#export-layout').on('click', function() { self.exportLayout(); });
            $('#import-layout').on('click', function() { $('#import-layout-file').trigger('click'); });
            $('#import-layout-file').on('change', function() {
                var file = this.files && this.files[0];
                this.value = '';
                if (file) self.importLayout(file);
            });
            $(document).on('click', '#block-settings-panel .seat-tool', function() {
                self.tool = $(this).data('tool');
                self.render(self.get(self.selectedId));
                self.refreshSeatTools();
            });
            $(document).on('click', '#block-settings-panel .duplicate-block', function() { self.duplicate(); });
            $(document).on('click', '#block-settings-panel .delete-block', function() { self.remove(); });

            $('#canvas-width, #canvas-height').on('change', function() {
                self.setCanvasSize($('#canvas-width').val(), $('#canvas-height').val());
                self.dirty = true;
            });
            $('#zoom-in').on('click', function() { self.setZoom(self.zoom + 0.1); });
            $('#zoom-out').on('click', function() { self.setZoom(self.zoom - 0.1); });
            $('#zoom-reset').on('click', function() { self.setZoom(1); });
            $('#save-venue').on('click', function() { self.save(); });

            $(document).on('keydown', function(e) {
                if ($(e.target).is('input, textarea, select') || !self.selectedId) return;
                var b = self.get(self.selectedId);
                if (e.key === 'Delete' || e.key === 'Backspace') {
                    e.preventDefault();
                    self.remove();
                } else if (e.key === 'Escape') {
                    self.select(null);
                } else if (e.key.indexOf('Arrow') === 0) {
                    e.preventDefault();
                    var step = e.shiftKey ? GRID : 1;
                    if (e.key === 'ArrowUp') b.y = Math.max(0, b.y - step);
                    if (e.key === 'ArrowDown') b.y = Math.min(self.canvasH - b.height, b.y + step);
                    if (e.key === 'ArrowLeft') b.x = Math.max(0, b.x - step);
                    if (e.key === 'ArrowRight') b.x = Math.min(self.canvasW - b.width, b.x + step);
                    self.render(b);
                    self.dirty = true;
                }
            });

            window.addEventListener('beforeunload', function(e) {
                if (self.dirty) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        },

        layoutJson: function() {
            return JSON.stringify({
                canvas: { width: this.canvasW, height: this.canvasH },
                blocks: this.blocks
            });
        },

        exportLayout: function() {
            var name = $.trim($('.venue-info-display strong').text() || $('#venue-name').val() || 'venue');
            var url = URL.createObjectURL(new Blob([this.layoutJson()], { type: 'application/json' }));
            var a = document.createElement('a');
            a.href = url;
            a.download = name.replace(/[^A-Za-z0-9]+/g, '-').toLowerCase() + '-layout.json';
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(function() { URL.revokeObjectURL(url); }, 1000);
        },

        importLayout: function(file) {
            var self = this;
            var reader = new FileReader();
            reader.onload = function() {
                var layout;
                try { layout = JSON.parse(reader.result); } catch (e) { layout = null; }
                if (!layout || !layout.canvas || !Array.isArray(layout.blocks)) {
                    window.alert('That file is not a seating layout exported from this designer.');
                    return;
                }
                var sellable = self.blocks.filter(function(b) { return SELLABLE.indexOf(b.type) !== -1; }).length;
                if (sellable && !window.confirm('Replace this venue\'s layout with the imported one? Sections that are not in the imported file will stop selling once you save.')) {
                    return;
                }
                self.load(layout);
                self.dirty = true;
            };
            reader.readAsText(file);
        },

        save: function() {
            var self = this;
            var $btn = $('#save-venue');
            var label = $btn.html();
            var venueId = parseInt(this.$canvas.attr('data-venue-id'), 10) || 0;
            if (!venueId && !$.trim($('#venue-name').val())) {
                window.alert('Please enter a venue name.');
                $('#venue-name').trigger('focus');
                return;
            }
            var data = {
                action: 'azure_tickets_save_venue',
                nonce: azureTicketsDesigner.nonce,
                venue_id: venueId,
                layout_json: this.layoutJson()
            };
            if (!venueId) {
                data.name = $.trim($('#venue-name').val());
                data.address = $.trim($('#venue-address').val());
                data.city = $.trim($('#venue-city').val());
            }
            $btn.prop('disabled', true).text('Saving…');
            $.post(azureTicketsDesigner.ajaxUrl, data).done(function(res) {
                if (!res || !res.success) {
                    window.alert((res && res.data && res.data.message) || 'Save failed.');
                    return;
                }
                self.dirty = false;
                if (!venueId) {
                    window.location.href = window.location.href.replace('action=new', 'action=edit') + '&venue_id=' + res.data.venue_id;
                    return;
                }
                var keep = self.selectedId;
                self.load(res.data.layout);
                if (keep && self.get(keep)) self.select(keep);
                $btn.text('Saved ✓');
            }).fail(function() {
                window.alert('Network error. Your changes are not saved yet; please try again.');
            }).always(function() {
                $btn.prop('disabled', false);
                setTimeout(function() { $btn.html(label); }, 1500);
            });
        }
    };

    $(function() { Designer.init(); });

})(jQuery);
