/* Communications > Forms admin: builder, list, entries, themes. */
(function ($) {
    'use strict';

    var cfg = window.azureForms || {};
    var S = cfg.strings || {};
    var TYPES = cfg.types || {};
    var PREFILL = {
        '': '— None —',
        first_name: 'First name',
        last_name: 'Last name',
        full_name: 'Full name',
        email: 'Email',
        phone: 'Phone'
    };

    function post(action, data) {
        return $.post(cfg.ajaxUrl, $.extend({ action: action, nonce: cfg.nonce }, data || {}));
    }

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    function makeName(text) {
        var name = String(text || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40).replace(/_+$/, '');
        if (!name) {
            name = 'field';
        }
        if (/^[0-9]/.test(name) || ['website', 'form_id', 'action', 'id'].indexOf(name) !== -1) {
            name = 'f_' + name;
        }
        return name;
    }

    function copyText(text, $btn) {
        var done = function () {
            var old = $btn.text();
            $btn.text(S.copied || 'Copied');
            setTimeout(function () { $btn.text(old); }, 1400);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done);
        } else {
            var $t = $('<textarea>').val(text).appendTo('body').select();
            document.execCommand('copy');
            $t.remove();
            done();
        }
    }

    // ─── List ─────────────────────────────────────────────────────────
    function initList() {
        var $list = $('.azure-forms-list');
        if (!$list.length) {
            return;
        }
        $list.on('click', '.azure-forms-copy', function () {
            copyText($(this).data('copy'), $(this));
        });
        $list.on('click', '.azure-forms-delete', function () {
            var $row = $(this).closest('tr');
            if (!window.confirm(S.confirmDelete)) {
                return;
            }
            post('azure_forms_delete', { form_id: $row.data('form') }).done(function (r) {
                if (r && r.success) {
                    $row.fadeOut(200, function () { $row.remove(); });
                }
            });
        });
        $list.on('click', '.azure-forms-duplicate', function () {
            var $row = $(this).closest('tr');
            post('azure_forms_duplicate', { form_id: $row.data('form') }).done(function (r) {
                if (r && r.success) {
                    window.location.href = r.data.edit_url;
                }
            });
        });
    }

    // ─── Entries ──────────────────────────────────────────────────────
    function initEntries() {
        var $table = $('.azure-forms-entries');
        var $focus = $('.azure-forms-focus-entry');
        if ($focus.length && $focus.data('status') === 'new') {
            post('azure_forms_entry', { entry_id: $focus.data('entry'), op: 'read' });
        }
        if (!$table.length) {
            return;
        }
        $table.on('click', '.azure-forms-entry-view', function () {
            var $row = $(this).closest('tr');
            var id = $row.data('entry');
            var $detail = $table.find('tr.azure-forms-entry-detail[data-entry="' + id + '"]');
            var open = $detail.prop('hidden');
            $detail.prop('hidden', !open);
            $(this).attr('aria-expanded', open ? 'true' : 'false');
            if (open && $row.data('status') === 'new') {
                $row.data('status', 'read').removeClass('status-new').addClass('status-read').find('.azure-forms-dot').remove();
                post('azure_forms_entry', { entry_id: id, op: 'read' });
            }
        });
        $table.on('click', '.azure-forms-entry-op', function () {
            var op = $(this).data('op');
            var $row = $(this).closest('tr');
            var id = $row.data('entry');
            if (op === 'delete' && !window.confirm(S.confirmEntry)) {
                return;
            }
            post('azure_forms_entry', { entry_id: id, op: op }).done(function (r) {
                if (r && r.success) {
                    $table.find('tr[data-entry="' + id + '"]').fadeOut(200, function () { $(this).remove(); });
                }
            });
        });
    }

    // ─── Builder ──────────────────────────────────────────────────────
    function initBuilder() {
        var $root = $('#azure-forms-builder');
        if (!$root.length) {
            return;
        }
        var boot = JSON.parse($('#azure-forms-boot').text() || '{}');
        var state = {
            id: boot.id || 0,
            title: boot.title || '',
            slug: boot.slug || '',
            status: boot.status || 'draft',
            schema: (boot.schema || []).map(function (f) { return $.extend({}, f); }),
            settings: $.extend({}, boot.settings || {}),
            legacy: boot.legacy_forminator_id || 0,
            selected: null,
            dirty: false,
            seq: 0
        };
        state.schema.forEach(function (f) {
            f._auto = !f.name || f.name === makeName(f.label);
        });

        var $canvas = $root.find('.azure-forms-canvas');
        var $inspector = $root.find('.azure-forms-inspector');
        var $palette = $root.find('.azure-forms-palette-list');
        var $saveState = $root.find('.azure-forms-save-state');

        function markDirty() {
            state.dirty = true;
            $saveState.text(S.unsaved || 'Unsaved changes');
            renderRegistrationCheck();
        }

        var PROFILE = cfg.profileTargets || {};
        var REG_LABELS = { children: 'A Children (repeating) field' };

        function profileTaken(target, selfId) {
            return state.schema.some(function (g) {
                return g.id !== selfId && g.profile === target;
            });
        }

        function renderRegistrationCheck() {
            var $box = $root.find('.azure-forms-registration-check');
            if (!$box.length) {
                return;
            }
            $box.prop('hidden', !state.settings.registration).empty();
            if (!state.settings.registration) {
                return;
            }
            var have = {};
            state.schema.forEach(function (g) {
                if (g.profile) {
                    have[g.profile] = true;
                }
                if (g.type === 'children') {
                    have.children = true;
                }
            });
            var $list = $('<ul>');
            (cfg.registrationRequires || []).forEach(function (key) {
                var label = REG_LABELS[key] || ('A field saved as “' + (PROFILE[key] ? PROFILE[key].label : key) + '”');
                $list.append($('<li>').addClass(have[key] ? 'is-ok' : 'is-missing').text((have[key] ? '✓ ' : '✗ ') + label));
            });
            $box.append($('<p>').text('A registration form needs:')).append($list);
        }

        function nextId() {
            var taken = {};
            state.schema.forEach(function (f) { taken[f.id] = true; });
            var id;
            do {
                state.seq++;
                id = 'fld_' + Date.now().toString(36).slice(-4) + state.seq;
            } while (taken[id]);
            return id;
        }

        function newField(type) {
            var meta = TYPES[type] || {};
            var f = { id: nextId(), type: type, label: meta.label || type, width: 'full' };
            if (meta.input) {
                f.required = false;
                f.placeholder = '';
                f.help = '';
                f.name = '';
                f._auto = true;
            }
            if (meta.options) {
                f.options = ['Option 1', 'Option 2'];
            }
            if (meta.prefill) {
                f.prefill = '';
            }
            if (type === 'paragraph') {
                f.label = 'Paragraph';
                f.content = '<p>Add some text here.</p>';
            }
            if (type === 'heading') {
                f.label = 'Section heading';
            }
            if (type === 'consent') {
                f.label = 'I agree to the terms.';
                f.required = true;
            }
            if (type === 'child') {
                f.label = 'Child\'s name';
            }
            if (type === 'children') {
                f.label = 'Your children';
                f.required = true;
                f.max_children = 6;
                f.details_required = true;
            }
            if (meta.input) {
                f.name = uniqueName(makeName(f.label), f.id);
            }
            return f;
        }

        function uniqueName(name, selfId) {
            var taken = {};
            state.schema.forEach(function (f) {
                if (f.id !== selfId && f.name) {
                    taken[f.name] = true;
                }
            });
            if (!taken[name]) {
                return name;
            }
            for (var i = 2; i < 1000; i++) {
                var candidate = name.slice(0, 36) + '_' + i;
                if (!taken[candidate]) {
                    return candidate;
                }
            }
            return name;
        }

        function renderPalette() {
            var html = '';
            Object.keys(TYPES).forEach(function (type) {
                html += '<li><button type="button" class="button azure-forms-palette-item" data-type="' + esc(type) + '">' + esc(TYPES[type].label) + '</button></li>';
            });
            html += '<li><button type="button" class="button azure-forms-palette-item" data-type="__row">Two fields side by side</button></li>';
            $palette.html(html);
            $palette.find('.azure-forms-palette-item').draggable({
                connectToSortable: $canvas,
                helper: 'clone',
                revert: 'invalid',
                appendTo: 'body',
                zIndex: 100000
            });
        }

        function insertFields(type, index) {
            var fields = type === '__row'
                ? [$.extend(newField('text'), { width: 'half' }), null]
                : [newField(type)];
            if (type === '__row') {
                state.schema.splice(index, 0, fields[0]);
                var second = $.extend(newField('text'), { width: 'half' });
                second.label = 'Text';
                second.name = uniqueName(makeName(second.label), second.id);
                state.schema.splice(index + 1, 0, second);
            } else {
                state.schema.splice(index, 0, fields[0]);
            }
            state.selected = index;
            markDirty();
            renderCanvas();
            renderInspector();
        }

        function cardSummary(f) {
            var meta = TYPES[f.type] || {};
            var bits = '<span class="afb-type">' + esc(meta.label || f.type) + '</span>';
            if (f.width === 'half') {
                bits += '<span class="afb-badge">½</span>';
            }
            if (f.required) {
                bits += '<span class="afb-badge afb-badge--req">Required</span>';
            }
            if (f.profile && state.settings.registration && PROFILE[f.profile]) {
                bits += '<span class="afb-badge" title="Saved to the new account">' + esc(PROFILE[f.profile].label) + '</span>';
            }
            if (f.show_if && f.show_if.field) {
                bits += '<span class="afb-badge" title="Shown only when ' + esc(f.show_if.field) + (f.show_if.value ? ' = ' + esc(f.show_if.value) : ' is answered') + '">Conditional</span>';
            }
            if (f.name) {
                bits += '<code class="afb-name">' + esc(f.name) + '</code>';
            }
            var label = f.type === 'paragraph'
                ? $('<div>').html(f.content || '').text().slice(0, 80)
                : $('<div>').html(f.label || '').text();
            return '<div class="afb-card-main"><div class="afb-label">' + esc(label || '(no label)') + '</div><div class="afb-meta">' + bits + '</div></div>';
        }

        function renderCanvas() {
            var html = '';
            state.schema.forEach(function (f, i) {
                html += '<li class="afb-card' + (f.width === 'half' ? ' afb-card--half' : '') + (state.selected === i ? ' is-selected' : '') + '" data-index="' + i + '" tabindex="0">'
                    + '<span class="afb-handle dashicons dashicons-menu" aria-hidden="true"></span>'
                    + cardSummary(f)
                    + '<span class="afb-card-actions">'
                    + '<button type="button" class="button-link afb-dup" title="Duplicate"><span class="dashicons dashicons-admin-page"></span><span class="screen-reader-text">Duplicate</span></button>'
                    + '<button type="button" class="button-link afb-del" title="Delete"><span class="dashicons dashicons-trash"></span><span class="screen-reader-text">Delete</span></button>'
                    + '</span></li>';
            });
            $canvas.html(html);
            $root.find('.azure-forms-empty').text(state.schema.length ? '' : (S.noFields || ''));
        }

        function syncOrderFromDom() {
            var next = [];
            var selectedField = state.selected !== null ? state.schema[state.selected] : null;
            $canvas.children('li.afb-card').each(function () {
                next.push(state.schema[$(this).data('index')]);
            });
            state.schema = next;
            state.selected = selectedField ? state.schema.indexOf(selectedField) : null;
            markDirty();
            renderCanvas();
        }

        $canvas.sortable({
            items: '> li',
            handle: '.afb-handle',
            placeholder: 'afb-placeholder',
            tolerance: 'pointer',
            receive: function () {
                // handled in update via the helper marker
            },
            update: function (ev, ui) {
                var $item = ui.item;
                if ($item.hasClass('azure-forms-palette-item') || $item.find('.azure-forms-palette-item').length || $item.is('[data-type]')) {
                    var type = $item.data('type') || $item.find('[data-type]').data('type');
                    var index = $canvas.children().index($item);
                    $item.remove();
                    // rebuild indices from current DOM before inserting
                    var order = [];
                    $canvas.children('li.afb-card').each(function () {
                        order.push(state.schema[$(this).data('index')]);
                    });
                    state.schema = order;
                    insertFields(type, Math.max(0, index));
                    return;
                }
                syncOrderFromDom();
            }
        });

        $palette.on('click', '.azure-forms-palette-item', function () {
            var at = state.selected !== null ? state.selected + 1 : state.schema.length;
            insertFields($(this).data('type'), at);
        });

        $canvas.on('click keydown', 'li.afb-card', function (ev) {
            if (ev.type === 'keydown' && ev.key !== 'Enter' && ev.key !== ' ') {
                return;
            }
            if ($(ev.target).closest('.afb-card-actions').length) {
                return;
            }
            ev.preventDefault();
            state.selected = $(this).data('index');
            renderCanvas();
            renderInspector();
        });
        $canvas.on('click', '.afb-dup', function () {
            var i = $(this).closest('li').data('index');
            var copy = $.extend(true, {}, state.schema[i]);
            copy.id = nextId();
            if (copy.name) {
                copy.name = uniqueName(copy.name, copy.id);
                copy._auto = false;
            }
            state.schema.splice(i + 1, 0, copy);
            state.selected = i + 1;
            markDirty();
            renderCanvas();
            renderInspector();
        });
        $canvas.on('click', '.afb-del', function () {
            var i = $(this).closest('li').data('index');
            if (!window.confirm(S.confirmField)) {
                return;
            }
            state.schema.splice(i, 1);
            state.selected = null;
            markDirty();
            renderCanvas();
            renderInspector();
        });

        function row(label, control, help) {
            return '<div class="afb-row"><label>' + esc(label) + '</label>' + control + (help ? '<p class="description">' + esc(help) + '</p>' : '') + '</div>';
        }

        function renderInspector() {
            if (state.selected === null || !state.schema[state.selected]) {
                $inspector.html('<p class="description">Select a field to edit it.</p>');
                return;
            }
            var f = state.schema[state.selected];
            var meta = TYPES[f.type] || {};
            var html = '<h3>' + esc(meta.label || f.type) + '</h3>';

            if (f.type === 'paragraph') {
                html += row('Text', '<textarea class="large-text" rows="6" data-prop="content">' + esc(f.content || '') + '</textarea>', 'Links, bold and lists are allowed.');
            } else if (f.type === 'consent') {
                html += row('Consent text', '<textarea class="large-text" rows="3" data-prop="label">' + esc(f.label || '') + '</textarea>', 'Links and bold text are allowed.');
            } else {
                html += row(f.type === 'heading' ? 'Heading text' : 'Label', '<input type="text" class="widefat" data-prop="label" value="' + esc(f.label || '') + '">');
            }

            if (meta.input) {
                html += row('Field name', '<input type="text" class="widefat code" data-prop="name" value="' + esc(f.name || '') + '"><span class="afb-name-warn" hidden>' + esc(S.nameTaken || '') + '</span>',
                    'Used in rules and emails as {field:' + (f.name || 'name') + '}. Lowercase letters, numbers and underscores.');
                html += '<div class="afb-row"><label><input type="checkbox" data-prop="required"' + (f.required ? ' checked' : '') + '> Required</label></div>';
            }
            if (meta.input && !meta.group && f.type !== 'consent' && f.type !== 'checkboxes' && f.type !== 'radio') {
                html += row(f.type === 'select' || meta.dynamic ? 'Empty choice text' : 'Placeholder', '<input type="text" class="widefat" data-prop="placeholder" value="' + esc(f.placeholder || '') + '">');
            }
            if (meta.dynamic) {
                html += '<p class="description">' + (f.type === 'grade'
                    ? 'Choices come from the Grade product field, so they always match the store.'
                    : 'Choices come from the class roster. If there is no roster, people type the name.') + '</p>';
            }
            if (f.type === 'child') {
                html += '<p class="description">Signed-in parents can pick from their saved children or type a name.</p>';
            }
            if (f.type === 'children') {
                html += '<p class="description">Each child gets a name, grade and teacher, with an “Add another child” button. Grade and teacher use the same lists as the store.</p>';
                html += row('Most children', '<input type="number" min="1" max="10" data-prop="max_children" value="' + esc(f.max_children || 6) + '">');
                html += '<div class="afb-row"><label><input type="checkbox" data-prop="details_required"' + (f.details_required !== false ? ' checked' : '') + '> Grade and teacher are required for each child</label></div>';
            }
            var targets = Object.keys(PROFILE).filter(function (k) {
                return PROFILE[k].types.indexOf(f.type) !== -1;
            });
            if (state.settings.registration && targets.length) {
                var popts = '<option value="">Don’t save</option>';
                targets.forEach(function (k) {
                    var taken = profileTaken(k, f.id);
                    popts += '<option value="' + esc(k) + '"' + (f.profile === k ? ' selected' : '') + (taken ? ' disabled' : '') + '>'
                        + esc(PROFILE[k].label) + (taken ? ' (used)' : '') + '</option>';
                });
                html += row('Save to the new account as', '<select class="widefat" data-prop="profile">' + popts + '</select>',
                    'Where this answer goes in the parent’s account and Family Info.');
            }
            if (meta.input) {
                html += row('Help text', '<input type="text" class="widefat" data-prop="help" value="' + esc(f.help || '') + '">');
            }
            if (meta.options) {
                html += row('Options', '<textarea class="widefat" rows="6" data-prop="options">' + esc((f.options || []).join('\n')) + '</textarea>', 'One per line.');
            }
            if (meta.prefill) {
                var opts = '';
                Object.keys(PREFILL).forEach(function (k) {
                    opts += '<option value="' + esc(k) + '"' + ((f.prefill || '') === k ? ' selected' : '') + '>' + esc(PREFILL[k]) + '</option>';
                });
                html += row('Fill in for signed-in members', '<select data-prop="prefill">' + opts + '</select>');
            }
            if (f.type === 'number') {
                html += row('Minimum', '<input type="number" step="any" data-prop="min" value="' + esc(f.min == null ? '' : f.min) + '">');
                html += row('Maximum', '<input type="number" step="any" data-prop="max" value="' + esc(f.max == null ? '' : f.max) + '">');
            }
            if (meta.max && !meta.dynamic && f.type !== 'number' && f.type !== 'date') {
                html += row('Maximum length', '<input type="number" min="1" max="' + meta.max + '" data-prop="maxlength" value="' + esc(f.maxlength || meta.max) + '">');
            }
            if (f.type !== 'heading' && f.type !== 'paragraph' && !meta.group) {
                html += row('Width', '<select data-prop="width"><option value="full"' + (f.width !== 'half' ? ' selected' : '') + '>Full width</option><option value="half"' + (f.width === 'half' ? ' selected' : '') + '>Half width</option></select>');
            }
            html += showIfRow(f);
            $inspector.html(html);
        }

        function earlierInputs(index) {
            return state.schema.slice(0, index).filter(function (g) {
                return g.name && TYPES[g.type] && TYPES[g.type].input;
            });
        }

        function showIfRow(f) {
            var earlier = earlierInputs(state.selected);
            if (!earlier.length) {
                return '';
            }
            var cond = f.show_if || {};
            var opts = '<option value="">Always</option>';
            earlier.forEach(function (g) {
                var label = $('<div>').html(g.label || '').text() || g.name;
                opts += '<option value="' + esc(g.name) + '"' + (cond.field === g.name ? ' selected' : '') + '>' + esc(label) + '</option>';
            });
            var value = '<input type="text" class="widefat" data-prop="show_if_value" placeholder="Any answer" value="' + esc(cond.value || '') + '"' + (cond.field ? '' : ' hidden') + '>';
            return row('Show only if', '<select class="widefat" data-prop="show_if_field">' + opts + '</select>' + value,
                'Pick an earlier question and the answer that reveals this one. Leave the answer empty to show it once the question is answered.');
        }

        function renameReferences(oldName, newName) {
            if (!oldName || oldName === newName) {
                return;
            }
            state.schema.forEach(function (g) {
                if (g.show_if && g.show_if.field === oldName) {
                    g.show_if.field = newName;
                }
            });
        }

        $inspector.on('input change', '[data-prop]', function (ev) {
            var f = state.schema[state.selected];
            if (!f) {
                return;
            }
            var prop = $(this).data('prop');
            var val = this.type === 'checkbox' ? this.checked : $(this).val();
            var oldName = f.name;
            if (prop === 'show_if_field' || prop === 'show_if_value') {
                if (prop === 'show_if_field') {
                    if (val) {
                        f.show_if = { field: val, value: (f.show_if && f.show_if.value) || '' };
                    } else {
                        delete f.show_if;
                    }
                    $inspector.find('[data-prop="show_if_value"]').prop('hidden', !val);
                } else if (f.show_if) {
                    f.show_if.value = val;
                }
                markDirty();
                $canvas.children('li[data-index="' + state.selected + '"]').find('.afb-card-main').replaceWith(cardSummary(f));
                return;
            }
            if (prop === 'options') {
                val = String(val).split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
            }
            if (prop === 'min' || prop === 'max') {
                val = val === '' ? null : Number(val);
            }
            if (prop === 'maxlength') {
                val = val === '' ? null : parseInt(val, 10);
            }
            if (prop === 'max_children') {
                val = Math.max(1, Math.min(10, parseInt(val, 10) || 6));
            }
            if (prop === 'name') {
                var clean = makeName(val);
                f._auto = false;
                var unique = uniqueName(clean, f.id);
                $inspector.find('.afb-name-warn').prop('hidden', unique === clean);
                if (ev.type === 'change') {
                    $(this).val(unique);
                }
                val = unique;
            }
            f[prop] = val;
            if (prop === 'label' && f._auto && TYPES[f.type] && TYPES[f.type].input) {
                f.name = uniqueName(makeName($('<div>').html(val).text()), f.id);
                $inspector.find('[data-prop="name"]').val(f.name);
            }
            if (f.name !== oldName) {
                renameReferences(oldName, f.name);
            }
            markDirty();
            var $card = $canvas.children('li[data-index="' + state.selected + '"]');
            $card.toggleClass('afb-card--half', f.width === 'half');
            $card.find('.afb-card-main').replaceWith(cardSummary(f));
        });

        // Tabs
        $root.on('click', '.azure-forms-tabs .nav-tab', function () {
            var pane = $(this).data('pane');
            $root.find('.azure-forms-tabs .nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $root.find('.azure-forms-pane').prop('hidden', true).filter('[data-pane="' + pane + '"]').prop('hidden', false);
            if (pane === 'preview') {
                loadPreview();
            }
        });

        function cleanSchema() {
            return state.schema.map(function (f) {
                var out = {};
                Object.keys(f).forEach(function (k) {
                    if (k.charAt(0) !== '_') {
                        out[k] = f[k];
                    }
                });
                return out;
            });
        }

        function loadPreview() {
            var $box = $root.find('[data-pane="preview"] .azure-forms-preview-box').html('<p>…</p>');
            post('azure_forms_preview', {
                schema: JSON.stringify(cleanSchema()),
                settings: JSON.stringify(state.settings)
            }).done(function (r) {
                $box.html(r && r.success ? r.data.html : '<p>Preview failed.</p>');
            });
        }

        // Settings
        var $themeSelect = $root.find('#afs-theme');
        (cfg.themes || []).forEach(function (t) {
            $themeSelect.append($('<option>').val(t.slug).text(t.label));
        });
        $root.find('[data-setting]').each(function () {
            var key = $(this).data('setting');
            var val = state.settings[key];
            if (this.type === 'checkbox') {
                this.checked = !!val;
            } else {
                $(this).val(val == null ? '' : val);
            }
        });
        $root.on('input change', '[data-setting]', function () {
            var key = $(this).data('setting');
            state.settings[key] = this.type === 'checkbox' ? this.checked : $(this).val();
            markDirty();
            if (key === 'registration') {
                renderCanvas();
                renderInspector();
            }
        });
        renderRegistrationCheck();

        var $title = $root.find('#azure-forms-title').val(state.title);
        var $status = $root.find('#azure-forms-status').val(state.status);
        var $slug = $root.find('#azure-forms-slug').val(state.slug);
        var $legacy = $root.find('#azure-forms-legacy').val(state.legacy || '');
        $title.on('input', function () { state.title = $(this).val(); markDirty(); });
        $status.on('change', function () { state.status = $(this).val(); markDirty(); });
        $slug.on('input', function () { state.slug = $(this).val(); markDirty(); });
        $legacy.on('input', function () { state.legacy = parseInt($(this).val(), 10) || 0; markDirty(); });
        $root.find('.azure-forms-turnstile-missing').prop('hidden', !!cfg.turnstileReady);

        var $notify = $root.find('#azure-forms-notify');
        state.notifyDirty = false;
        function renderNotify(n) {
            var $row = $root.find('.azure-forms-notify-row').prop('hidden', !n);
            if (!n) {
                return;
            }
            $notify.val(n.to || '').prop('readonly', !n.can_edit);
            $row.find('.azure-forms-notify-readonly').prop('hidden', !!n.can_edit);
            var $paused = $row.find('.azure-forms-notify-paused').empty().prop('hidden', !n.paused);
            if (n.paused) {
                $paused.append(document.createTextNode('This email is paused, so nothing is being sent. '))
                    .append($('<a>').attr('href', n.rule_url).text('Turn it back on in Rules'));
            }
            var $links = $row.find('.azure-forms-notify-links').empty().prop('hidden', !n.email_url);
            if (n.email_url) {
                $links.append($('<a>').attr('href', n.email_url).text('Edit the email’s wording'));
            }
            var $others = $row.find('.azure-forms-notify-others').empty().prop('hidden', !(n.others && n.others.length));
            if (n.others && n.others.length) {
                $others.append($('<p>').text('This form also has these rules:'));
                var $ul = $('<ul>').css({ margin: '0 0 0 1.5em', listStyle: 'disc' });
                n.others.forEach(function (o) {
                    var text = o.name + ': ' + (o.to || 'no recipients');
                    if (o.condition) {
                        text += ', only when “' + o.condition.field + '” is ' + (o.condition.value === '' ? 'answered' : '“' + o.condition.value + '”');
                    }
                    if (!o.enabled) {
                        text += ' (paused)';
                    }
                    $ul.append($('<li>').append(document.createTextNode(text + ' ')).append($('<a>').attr('href', o.edit_url).text('Edit')));
                });
                $others.append($ul);
            }
        }
        renderNotify(boot.notify || null);
        $notify.on('input', function () { state.notifyDirty = true; markDirty(); });

        function showShortcode() {
            if (!state.id) {
                return;
            }
            var code = '[pta_form id="' + state.id + '"]';
            $root.find('.azure-forms-shortcode').text(code);
            $root.find('.azure-forms-shortcode-wrap').prop('hidden', false);
        }
        $root.on('click', '.azure-forms-copy', function () {
            copyText($root.find('.azure-forms-shortcode').text(), $(this));
        });

        $root.on('click', '.azure-forms-save', function () {
            var $btn = $(this).prop('disabled', true);
            $saveState.text(S.saving || 'Saving…');
            var payload = {
                form_id: state.id,
                title: state.title,
                slug: state.slug,
                status: state.status,
                schema: JSON.stringify(cleanSchema()),
                settings: JSON.stringify(state.settings),
                legacy_forminator_id: state.legacy
            };
            if (state.notifyDirty && !$notify.prop('readonly')) {
                payload.notify_to = $notify.val();
            }
            post('azure_forms_save', payload).done(function (r) {
                if (!r || !r.success) {
                    $saveState.text((r && r.data && r.data.message) || S.error);
                    return;
                }
                var wasNew = !state.id;
                state.id = r.data.id;
                state.slug = r.data.slug;
                $slug.val(state.slug);
                var selectedId = state.selected !== null && state.schema[state.selected] ? state.schema[state.selected].id : null;
                state.schema = r.data.schema.map(function (f) { return $.extend({ _auto: false }, f); });
                state.selected = null;
                state.schema.forEach(function (f, i) {
                    if (f.id === selectedId) {
                        state.selected = i;
                    }
                });
                state.dirty = false;
                state.notifyDirty = false;
                if (r.data.notify_error) {
                    state.notifyDirty = true;
                    state.dirty = true;
                    $saveState.text(r.data.notify_error);
                } else {
                    $saveState.text(S.saved || 'Saved.');
                    renderNotify(r.data.notify || null);
                }
                renderCanvas();
                renderInspector();
                showShortcode();
                if (wasNew && window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', cfg.listUrl + '&form=' + state.id);
                }
            }).fail(function () {
                $saveState.text(S.error);
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });

        $(window).on('beforeunload', function () {
            if (state.dirty) {
                return S.unsaved || 'You have unsaved changes.';
            }
        });

        renderPalette();
        renderCanvas();
        renderInspector();
        showShortcode();
    }

    // ─── Themes ───────────────────────────────────────────────────────
    var THEME_FIELDS = [
        { group: 'Name' },
        { key: 'label', label: 'Theme name', type: 'text' },
        { key: 'slug', label: 'Shortcode name', type: 'text', help: 'Used as theme="…" in the shortcode.' },
        { group: 'Colours' },
        { key: 'bg_color', label: 'Form background', type: 'color' },
        { key: 'text_color', label: 'Text', type: 'color' },
        { key: 'accent_color', label: 'Button and focus', type: 'color' },
        { key: 'accent_text_color', label: 'Button text', type: 'color' },
        { key: 'muted_color', label: 'Help text', type: 'color' },
        { key: 'border_color', label: 'Input borders', type: 'color' },
        { group: 'Shape and size' },
        { key: 'border_width', label: 'Input border width (px)', type: 'number', min: 0, max: 6 },
        { key: 'border_radius', label: 'Corner radius (px)', type: 'number', min: 0, max: 32 },
        { key: 'title_size', label: 'Base font size (px)', type: 'number', min: 12, max: 24 },
        { group: 'Outer frame' },
        { key: 'outer_bg_color', label: 'Frame background', type: 'color' },
        { key: 'outer_border_color', label: 'Frame border colour', type: 'color' },
        { key: 'outer_border_width', label: 'Frame border width (px)', type: 'number', min: 0, max: 24 },
        { key: 'outer_border_radius', label: 'Frame corner radius (px)', type: 'number', min: 0, max: 48 },
        { key: 'outer_padding', label: 'Frame padding (px)', type: 'number', min: 0, max: 96 },
        { key: 'outer_max_width', label: 'Maximum width (px, 0 for none)', type: 'number', min: 0, max: 1200 },
        { group: 'Header' },
        { key: 'header_text', label: 'Header text', type: 'text' },
        { key: 'header_color', label: 'Header colour', type: 'color' },
        { key: 'header_size', label: 'Header size (px)', type: 'number', min: 12, max: 72 },
        { key: 'header_align', label: 'Header alignment', type: 'select', options: ['left', 'center', 'right'] },
        { key: 'header_font', label: 'Header font', type: 'select', options: ['default', 'serif', 'display', 'mono'] },
        { key: 'header_underline', label: 'Underline the header', type: 'checkbox' },
        { group: 'Footer' },
        { key: 'footer_html', label: 'Footer (links allowed)', type: 'textarea' },
        { key: 'footer_color', label: 'Footer colour', type: 'color' },
        { key: 'footer_size', label: 'Footer size (px)', type: 'number', min: 10, max: 28 },
        { key: 'footer_align', label: 'Footer alignment', type: 'select', options: ['left', 'center', 'right'] }
    ];

    var SAMPLE_SCHEMA = [
        { id: 's1', type: 'heading', label: 'Your details' },
        { id: 's2', type: 'text', label: 'First name', name: 'first_name', required: true, width: 'half' },
        { id: 's3', type: 'text', label: 'Last name', name: 'last_name', required: true, width: 'half' },
        { id: 's4', type: 'email', label: 'Email', name: 'email', required: true, help: 'We only use this to reply.' },
        { id: 's5', type: 'select', label: 'Grade', name: 'grade', options: ['K', '1', '2', '3', '4', '5'] },
        { id: 's6', type: 'checkboxes', label: 'I can help with', name: 'help_with', options: ['Setup', 'Clean up'] },
        { id: 's7', type: 'textarea', label: 'Anything else?', name: 'notes' },
        { id: 's8', type: 'consent', label: 'I agree to be contacted by the PTSA.', name: 'consent', required: true }
    ];

    function hex6(v) {
        var s = String(v || '#000000');
        if (/^#[0-9a-f]{3}$/i.test(s)) {
            return '#' + s.charAt(1) + s.charAt(1) + s.charAt(2) + s.charAt(2) + s.charAt(3) + s.charAt(3);
        }
        return s.slice(0, 7);
    }

    function initThemes() {
        var $root = $('#azure-forms-themes');
        if (!$root.length) {
            return;
        }
        var themes = cfg.themes || [];
        var current = null;
        var previewTimer = null;
        var $list = $root.find('.azure-forms-theme-list');
        var $editor = $root.find('.azure-forms-themes__editor');
        var $preview = $root.find('.azure-forms-preview-box');

        var $source = $root.find('#azure-forms-upnext-source');
        (cfg.upnextThemes || []).forEach(function (t) {
            $source.append($('<option>').val(t.slug).text(t.label + ' (' + t.slug + ')'));
        });

        function renderList() {
            var html = '';
            themes.forEach(function (t) {
                html += '<li><button type="button" class="button-link azure-forms-theme-pick' + (current && current.slug === t.slug ? ' is-current' : '') + '" data-slug="' + esc(t.slug) + '">'
                    + '<span class="afb-swatch" style="background:' + esc(t.accent_color) + '"></span>' + esc(t.label) + (t.is_builtin ? ' <em>(built in)</em>' : '') + '</button></li>';
            });
            $list.html(html);
        }

        function field(def, t) {
            var v = t[def.key];
            var id = 'aft-' + def.key;
            var disabled = t.is_builtin ? ' disabled' : '';
            var control;
            if (def.type === 'color') {
                control = '<input type="color" id="' + id + '" data-key="' + def.key + '" value="' + esc(hex6(v)) + '"' + disabled + '>';
            } else if (def.type === 'number') {
                control = '<input type="number" class="small-text" id="' + id + '" data-key="' + def.key + '" min="' + def.min + '" max="' + def.max + '" value="' + esc(v) + '"' + disabled + '>';
            } else if (def.type === 'select') {
                control = '<select id="' + id + '" data-key="' + def.key + '"' + disabled + '>' + def.options.map(function (o) {
                    return '<option value="' + o + '"' + (v === o ? ' selected' : '') + '>' + o + '</option>';
                }).join('') + '</select>';
            } else if (def.type === 'checkbox') {
                control = '<input type="checkbox" id="' + id + '" data-key="' + def.key + '"' + (v ? ' checked' : '') + disabled + '>';
            } else if (def.type === 'textarea') {
                control = '<textarea class="widefat" rows="3" id="' + id + '" data-key="' + def.key + '"' + disabled + '>' + esc(v) + '</textarea>';
            } else {
                var ro = (def.key === 'slug' && t._saved) ? ' readonly' : '';
                control = '<input type="text" class="regular-text" id="' + id + '" data-key="' + def.key + '" value="' + esc(v) + '"' + disabled + ro + '>';
            }
            return '<div class="aft-row"><label for="' + id + '">' + esc(def.label) + '</label>' + control + (def.help ? '<p class="description">' + esc(def.help) + '</p>' : '') + '</div>';
        }

        function renderEditor() {
            if (!current) {
                $editor.html('<p class="description">Choose a theme to edit, or create a new one.</p>');
                return;
            }
            var html = current.is_builtin ? '<p class="notice notice-info inline" style="padding:8px">The built-in Default theme cannot be edited. Create a new theme to customise.</p>' : '';
            THEME_FIELDS.forEach(function (def) {
                html += def.group ? '<h3>' + esc(def.group) + '</h3>' : field(def, current);
            });
            if (!current.is_builtin) {
                html += '<p class="aft-actions"><button type="button" class="button button-primary aft-save">Save theme</button> '
                    + (current._saved ? '<button type="button" class="button-link button-link-delete aft-delete">Delete</button>' : '')
                    + ' <span class="aft-state" role="status"></span></p>';
            }
            $editor.html(html);
        }

        function preview() {
            if (!current) {
                return;
            }
            clearTimeout(previewTimer);
            previewTimer = setTimeout(function () {
                var data = $.extend({}, current, { slug: 'preview' });
                post('azure_forms_preview', {
                    schema: JSON.stringify(SAMPLE_SCHEMA),
                    settings: JSON.stringify({ submit_label: 'Send' }),
                    theme_data: JSON.stringify(data)
                }).done(function (r) {
                    if (r && r.success) {
                        $preview.html(r.data.html);
                    }
                });
            }, 250);
        }

        function select(slug) {
            themes.forEach(function (t) {
                if (t.slug === slug) {
                    current = $.extend({}, t, { _saved: !t.is_builtin });
                }
            });
            renderList();
            renderEditor();
            preview();
        }

        $list.on('click', '.azure-forms-theme-pick', function () {
            select($(this).data('slug'));
        });

        $root.on('click', '.azure-forms-theme-new', function () {
            var base = themes[0] || {};
            current = $.extend({}, base, { slug: '', label: 'New theme', is_builtin: false, _saved: false });
            renderList();
            renderEditor();
            preview();
        });

        $editor.on('input change', '[data-key]', function () {
            var key = $(this).data('key');
            current[key] = this.type === 'checkbox' ? this.checked : (this.type === 'number' ? Number($(this).val()) : $(this).val());
            preview();
        });

        $editor.on('click', '.aft-save', function () {
            var $state = $editor.find('.aft-state').text(S.saving || 'Saving…');
            var payload = $.extend({}, current);
            delete payload._saved;
            delete payload.is_builtin;
            post('azure_forms_theme_save', { theme: JSON.stringify(payload) }).done(function (r) {
                if (!r || !r.success) {
                    $state.text((r && r.data && r.data.message) || S.error);
                    return;
                }
                themes = r.data.themes;
                select(r.data.theme.slug);
                $editor.find('.aft-state').text(S.saved || 'Saved.');
            });
        });

        $editor.on('click', '.aft-delete', function () {
            if (!window.confirm(S.confirmTheme)) {
                return;
            }
            post('azure_forms_theme_delete', { slug: current.slug }).done(function (r) {
                if (r && r.success) {
                    themes = r.data.themes;
                    current = null;
                    renderList();
                    renderEditor();
                    $preview.empty();
                }
            });
        });

        $root.on('click', '.azure-forms-theme-copy-btn', function () {
            var src = $source.val();
            if (!src) {
                return;
            }
            post('azure_forms_theme_copy', { source: src }).done(function (r) {
                if (r && r.success) {
                    themes = r.data.themes;
                    select(r.data.theme.slug);
                } else {
                    window.alert((r && r.data && r.data.message) || S.error);
                }
            });
        });

        renderList();
        if (themes.length) {
            select(themes[themes.length - 1].slug);
        } else {
            renderEditor();
        }
    }

    $(function () {
        initList();
        initEntries();
        initBuilder();
        initThemes();
    });
})(jQuery);
