/* [pta_form] front end. No dependencies. */
(function () {
    'use strict';

    var cfg = window.ptaForms || {};
    var strings = cfg.strings || {};
    var PREFIX = 'pta_f[';
    var loadedAt = Date.now();
    var session = null;

    function signedIn() {
        return /(?:^|;\s*)pta_signed_in=/.test(document.cookie);
    }

    function getSession() {
        if (session) {
            return session;
        }
        if (!signedIn() || !cfg.ajaxUrl || !window.fetch) {
            session = Promise.resolve({ logged_in: false });
            return session;
        }
        session = fetch(cfg.ajaxUrl + '?action=pta_forms_session', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { return (j && j.success && j.data) ? j.data : { logged_in: false }; })
            .catch(function () { return { logged_in: false }; });
        return session;
    }

    function prefill(form, data) {
        if (!data || !data.logged_in) {
            return;
        }
        var inputs = form.querySelectorAll('[data-prefill]');
        for (var i = 0; i < inputs.length; i++) {
            var input = inputs[i];
            var value = data.prefill ? data.prefill[input.getAttribute('data-prefill')] : '';
            if (value && !input.value) {
                input.value = value;
            }
        }
        var children = data.children || [];
        var pickers = form.querySelectorAll('input[data-children]');
        for (var j = 0; j < pickers.length; j++) {
            var list = document.getElementById(pickers[j].getAttribute('list'));
            if (!list || list.options.length) {
                continue;
            }
            children.forEach(function (name) {
                var opt = document.createElement('option');
                opt.value = name;
                list.appendChild(opt);
            });
            if (children.length === 1 && !pickers[j].value) {
                pickers[j].value = children[0];
            }
        }
        applyVisibility(form);
    }

    // ─── Show-if ───────────────────────────────────────────────────────

    function fieldValue(form, name) {
        var wrap = form.querySelector('.pta-form__field[data-name="' + name.replace(/"/g, '') + '"]');
        if (!wrap || wrap.hidden) {
            return '';
        }
        var values = [];
        var els = wrap.querySelectorAll('input, select, textarea');
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) {
                continue;
            }
            values.push(el.value);
        }
        return values;
    }

    function matches(values, want) {
        want = String(want || '').trim().toLowerCase();
        for (var i = 0; i < values.length; i++) {
            var v = String(values[i] || '').trim().toLowerCase();
            if (want === '' ? v !== '' : v === want) {
                return true;
            }
        }
        return false;
    }

    function applyVisibility(form) {
        var nodes = form.querySelectorAll('[data-show-if]');
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            var show = matches(fieldValue(form, node.getAttribute('data-show-if')), node.getAttribute('data-show-value'));
            if (node.hidden === !show) {
                continue;
            }
            node.hidden = !show;
            var controls = node.querySelectorAll('input, select, textarea');
            for (var j = 0; j < controls.length; j++) {
                controls[j].disabled = !show;
            }
        }
    }

    function collect(form) {
        var fields = {};
        var elements = form.elements;
        for (var i = 0; i < elements.length; i++) {
            var el = elements[i];
            if (!el.name || el.name.indexOf(PREFIX) !== 0 || el.disabled) {
                continue;
            }
            var multi = el.name.slice(-2) === '[]';
            var name = el.name.slice(PREFIX.length, el.name.indexOf(']'));
            var nested = el.name.slice(PREFIX.length - 1).match(/^\[[^\]]+\]\[(\d+)\]\[([a-z_]+)\]$/);
            if (nested) {
                var rows = fields[name] = Array.isArray(fields[name]) ? fields[name] : [];
                var row = rows[+nested[1]] = rows[+nested[1]] || {};
                row[nested[2]] = el.value;
                continue;
            }
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) {
                if (multi && !fields[name]) {
                    fields[name] = [];
                }
                continue;
            }
            if (multi) {
                (fields[name] = fields[name] || []).push(el.value);
            } else {
                fields[name] = el.value;
            }
        }
        return fields;
    }

    function clearErrors(form) {
        var fields = form.querySelectorAll('.pta-form__field.has-error');
        for (var i = 0; i < fields.length; i++) {
            fields[i].classList.remove('has-error');
            var p = fields[i].querySelector('.pta-form__error');
            if (p) {
                p.hidden = true;
                p.textContent = '';
            }
        }
    }

    function showErrors(form, errors) {
        var first = null;
        Object.keys(errors || {}).forEach(function (name) {
            var field = form.querySelector('.pta-form__field[data-name="' + name.replace(/"/g, '') + '"]');
            if (!field) {
                return;
            }
            field.classList.add('has-error');
            var p = field.querySelector('.pta-form__error');
            if (p) {
                p.textContent = errors[name];
                p.hidden = false;
            }
            if (!first) {
                first = field;
            }
        });
        if (first) {
            var control = first.querySelector('input, select, textarea');
            if (control) {
                control.focus();
            }
        }
    }

    function setStatus(form, text, isError) {
        var box = form.querySelector('.pta-form__status');
        if (!box) {
            return;
        }
        box.textContent = text || '';
        box.hidden = !text;
        box.classList.toggle('is-error', !!isError);
    }

    // ─── Repeating children ────────────────────────────────────────────

    function wireChildren(field) {
        var fieldset = field.querySelector('.pta-form__children');
        var addBtn = field.querySelector('.pta-form__child-add');
        var first = field.querySelector('.pta-form__child');
        if (!fieldset || !addBtn || !first) {
            return;
        }
        var name = field.getAttribute('data-name');
        var max = parseInt(field.getAttribute('data-max'), 10) || 6;
        var template = first.cloneNode(true);

        function blocks() {
            return fieldset.querySelectorAll('.pta-form__child');
        }

        function renumber() {
            var list = blocks();
            for (var i = 0; i < list.length; i++) {
                var block = list[i];
                block.setAttribute('data-index', i);
                var title = block.querySelector('.pta-form__child-title');
                if (title) {
                    title.textContent = (strings.childN || 'Child %d').replace('%d', i + 1);
                }
                var remove = block.querySelector('.pta-form__child-remove');
                if (remove) {
                    remove.hidden = list.length < 2;
                }
                var controls = block.querySelectorAll('input, select, textarea');
                for (var j = 0; j < controls.length; j++) {
                    var el = controls[j];
                    var key = (el.name.match(/\[([a-z_]+)\]$/) || [])[1];
                    if (!key) {
                        continue;
                    }
                    el.name = PREFIX + name + '][' + i + '][' + key + ']';
                    var oldId = el.id;
                    el.id = oldId.replace(/-\d+-([a-z_]+)$/, '-' + i + '-$1');
                    var label = block.querySelector('label[for="' + oldId + '"]');
                    if (label) {
                        label.setAttribute('for', el.id);
                    }
                }
            }
            addBtn.hidden = list.length >= max;
        }

        addBtn.addEventListener('click', function () {
            if (blocks().length >= max) {
                return;
            }
            var block = template.cloneNode(true);
            var controls = block.querySelectorAll('input, select, textarea');
            for (var i = 0; i < controls.length; i++) {
                controls[i].value = '';
            }
            fieldset.insertBefore(block, addBtn);
            renumber();
            var focus = block.querySelector('input, select');
            if (focus) {
                focus.focus();
            }
        });

        fieldset.addEventListener('click', function (ev) {
            var remove = ev.target.closest('.pta-form__child-remove');
            if (!remove || blocks().length < 2) {
                return;
            }
            remove.closest('.pta-form__child').remove();
            renumber();
            addBtn.focus();
        });

        renumber();
    }

    function showSignedIn(form, wrap) {
        var box = wrap ? wrap.querySelector('.pta-form__success') : null;
        if (!box) {
            return;
        }
        var p = document.createElement('p');
        p.appendChild(document.createTextNode((strings.signedIn || "You're already signed in.") + ' '));
        var a = document.createElement('a');
        a.href = cfg.accountUrl || '/';
        a.textContent = strings.myAccount || 'My Account';
        p.appendChild(a);
        p.appendChild(document.createTextNode('.'));
        box.innerHTML = '';
        box.appendChild(p);
        box.hidden = false;
        form.hidden = true;
    }

    function wire(form) {
        var wrap = form.closest('.pta-form-wrap');
        var button = form.querySelector('.pta-form__submit');
        var busy = false;

        var childFields = form.querySelectorAll('.pta-form__field--children');
        for (var c = 0; c < childFields.length; c++) {
            wireChildren(childFields[c]);
        }

        getSession().then(function (data) {
            if (data && data.logged_in && form.getAttribute('data-registration')) {
                showSignedIn(form, wrap);
                return;
            }
            prefill(form, data);
        });

        if (form.querySelector('[data-show-if]')) {
            applyVisibility(form);
            form.addEventListener('input', function () { applyVisibility(form); });
            form.addEventListener('change', function () { applyVisibility(form); });
        }

        function resetCaptcha() {
            var widget = form.querySelector('.cf-turnstile');
            if (widget && window.turnstile && typeof window.turnstile.reset === 'function') {
                try { window.turnstile.reset(widget); } catch (e) { /* widget not rendered yet */ }
            }
        }

        form.addEventListener('submit', function (ev) {
            if (!window.fetch || !form.getAttribute('data-endpoint')) {
                return;
            }
            ev.preventDefault();
            if (busy) {
                return;
            }
            busy = true;
            clearErrors(form);
            setStatus(form, '', false);
            var label = button ? button.textContent : '';
            if (button) {
                button.disabled = true;
                button.textContent = strings.sending || 'Sending…';
            }

            var payload = {
                fields: collect(form),
                website: (form.elements.website && form.elements.website.value) || '',
                _pta_ts: (form.elements._pta_ts && form.elements._pta_ts.value) || '',
                _pta_elapsed: Math.round((Date.now() - loadedAt) / 1000),
                _pta_turnstile: (form.elements['cf-turnstile-response'] && form.elements['cf-turnstile-response'].value) || ''
            };

            getSession().then(function (data) {
                var headers = { 'Content-Type': 'application/json' };
                if (data && data.nonce) {
                    headers['X-WP-Nonce'] = data.nonce;
                }
                return fetch(form.getAttribute('data-endpoint'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: headers,
                    body: JSON.stringify(payload)
                });
            }).then(function (r) {
                return r.json().then(function (j) { return { ok: r.ok, body: j || {} }; }, function () { return { ok: false, body: {} }; });
            }).then(function (res) {
                if (res.ok && res.body.success) {
                    var redirect = res.body.redirect || form.getAttribute('data-redirect');
                    if (redirect) {
                        window.location.href = redirect;
                        return;
                    }
                    var success = wrap ? wrap.querySelector('.pta-form__success') : null;
                    if (success) {
                        success.innerHTML = res.body.message || form.getAttribute('data-success') || '';
                        success.hidden = false;
                        form.hidden = true;
                        success.setAttribute('tabindex', '-1');
                        success.focus();
                    }
                    return;
                }
                if (res.body.errors && Object.keys(res.body.errors).length) {
                    showErrors(form, res.body.errors);
                }
                setStatus(form, res.body.message || strings.error || 'Something went wrong.', true);
                resetCaptcha();
            }).catch(function () {
                setStatus(form, strings.network || 'Network error.', true);
            }).then(function () {
                busy = false;
                if (button) {
                    button.disabled = false;
                    button.textContent = label;
                }
            });
        });
    }

    function init() {
        var forms = document.querySelectorAll('form.pta-form[data-form-id]');
        for (var i = 0; i < forms.length; i++) {
            var elapsed = forms[i].elements._pta_elapsed;
            if (elapsed) {
                elapsed.value = '';
            }
            wire(forms[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
