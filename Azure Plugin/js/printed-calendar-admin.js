/**
 * Calendar → Printed Calendars: poster preview, print, header picture and
 * per-calendar poster text. Settings come from wp_localize_script as
 * window.azurePrintedCalendar.
 */
(function ($) {
    'use strict';

    var cfg = window.azurePrintedCalendar;
    if (!cfg || !document.getElementById('pta-print')) {
        return;
    }

    var MM_TO_PX = 96 / 25.4;
    var FIELDS = ['kicker', 'subtitle', 'usual_location', 'footer', 'link'];
    var STORE_KEY = 'ptaPrintedCalendar';

    var $calendar = $('#pta-print-calendar');
    var $month = $('#pta-print-month');
    var $qr = $('#pta-print-qr');
    var $frame = $('#pta-print-frame');
    var iframe = document.getElementById('pta-print-iframe');
    var $cap = $('#pta-print-cap');
    var $status = $('#pta-print-save-status');

    var working = {};
    var showHeader = true;
    var timer = null;
    var mediaFrame = null;

    function fmt(str, n) {
        return String(str).replace('%d', n);
    }

    function radio(name) {
        return $('input[name="' + name + '"]:checked').val();
    }

    function remember() {
        try {
            localStorage.setItem(STORE_KEY, JSON.stringify({
                calendar: $calendar.val(),
                days: radio('days'),
                paper: radio('paper')
            }));
        } catch (e) { /* private browsing */ }
    }

    function restore() {
        var saved = null;
        try {
            saved = JSON.parse(localStorage.getItem(STORE_KEY) || 'null');
        } catch (e) { /* ignore */ }
        if (!saved) {
            return;
        }
        if (saved.calendar && cfg.settings[saved.calendar]) {
            $calendar.val(saved.calendar);
        }
        if (saved.days) {
            $('input[name="days"][value="' + saved.days + '"]').prop('checked', true);
        }
        if (saved.paper && cfg.papers[saved.paper]) {
            $('input[name="paper"][value="' + saved.paper + '"]').prop('checked', true);
        }
    }

    function loadCalendar() {
        working = $.extend({}, cfg.settings[$calendar.val()] || {});
        FIELDS.forEach(function (key) {
            $('[data-setting="' + key + '"]').val(working[key] || '');
        });
        showHeader = !!working.header_id;
        $status.text('');
        renderHeader();
    }

    function renderHeader() {
        var has = !!working.header_id;
        $('#pta-print-thumb')
            .css('background-image', has && working.header_url ? 'url("' + working.header_url + '")' : '')
            .prop('disabled', !has)
            .toggleClass('on', has && showHeader);
        $('#pta-print-thumb-none').toggleClass('on', !has || !showHeader);

        var $hint = $('#pta-print-header-hint').removeClass('warn');
        if (has && working.header_width && working.header_width < 2500) {
            $hint.addClass('warn').text(fmt(cfg.strings.lowRes, working.header_width));
        } else {
            $hint.text('');
        }

        var link = working.link || working.live_link || '';
        $('#pta-print-qr-hint').text($qr.is(':checked') && !link ? cfg.strings.noLink : '');
    }

    function posterUrl(extra) {
        var params = {
            action: cfg.action,
            _wpnonce: cfg.posterNonce,
            calendar: $calendar.val(),
            month: $month.val(),
            days: radio('days'),
            paper: radio('paper'),
            header: showHeader ? '1' : '0',
            qr: $qr.is(':checked') ? '1' : '0'
        };
        params['s[header_id]'] = working.header_id || 0;
        FIELDS.forEach(function (key) {
            params['s[' + key + ']'] = working[key] || '';
        });
        return cfg.posterUrl + '?' + $.param($.extend(params, extra || {}));
    }

    function fitPreview() {
        var paper = cfg.papers[radio('paper')] || cfg.papers.a3;
        var w = paper.w * MM_TO_PX;
        var h = paper.h * MM_TO_PX;
        var $box = $frame.parent();
        var room = Math.max(320, $box.width() - 8);
        var scale = Math.min(room / w, 1);
        $(iframe).css({ width: w + 'px', height: h + 'px', transform: 'scale(' + scale + ')' });
        $frame.css({ width: Math.round(w * scale) + 'px', height: Math.round(h * scale) + 'px' });
    }

    function refresh() {
        clearTimeout(timer);
        renderHeader();
        fitPreview();
        $frame.addClass('loading');
        iframe.src = posterUrl();
        $('#pta-print-open').attr('href', posterUrl());
        remember();
    }

    function refreshSoon() {
        clearTimeout(timer);
        timer = setTimeout(refresh, 500);
    }

    iframe.addEventListener('load', function () {
        $frame.removeClass('loading');
        var doc;
        try {
            doc = iframe.contentDocument;
        } catch (e) {
            return;
        }
        var sheet = doc && doc.querySelector('.sheet');
        if (!sheet) {
            $cap.text('');
            return;
        }
        var count = parseInt(sheet.getAttribute('data-events'), 10) || 0;
        var paper = cfg.papers[radio('paper')] || cfg.papers.a3;
        var parts = [
            $calendar.find('option:selected').text(),
            $month.find('option:selected').text(),
            paper.label + ' ' + paper.note,
            count === 1 ? cfg.strings.oneEvent : fmt(cfg.strings.events, count)
        ];
        $cap.text(parts.join(' · '));
        if (sheet.getAttribute('data-overflow')) {
            $cap.append($('<span class="warn">').text(cfg.strings.overflow));
        }
    });

    $calendar.on('change', function () {
        loadCalendar();
        refresh();
    });
    $month.add($qr).on('change', refresh);
    $('input[name="days"], input[name="paper"]').on('change', refresh);

    $('[data-setting]').on('input', function () {
        working[$(this).data('setting')] = $(this).val();
        $status.text('');
        refreshSoon();
    });

    $('#pta-print-thumb').on('click', function () {
        if (working.header_id) {
            showHeader = true;
            refresh();
        }
    });
    $('#pta-print-thumb-none').on('click', function () {
        showHeader = false;
        refresh();
    });

    $('#pta-print-choose').on('click', function () {
        if (!window.wp || !wp.media) {
            return;
        }
        if (!mediaFrame) {
            mediaFrame = wp.media({
                title: cfg.strings.chooseHeader,
                button: { text: cfg.strings.useHeader },
                library: { type: 'image' },
                multiple: false
            });
            mediaFrame.on('select', function () {
                var img = mediaFrame.state().get('selection').first().toJSON();
                working.header_id = img.id;
                working.header_url = (img.sizes && img.sizes.medium) ? img.sizes.medium.url : img.url;
                working.header_width = img.width || 0;
                showHeader = true;
                $status.text('');
                $('#pta-print-details').prop('open', true);
                refresh();
            });
        }
        mediaFrame.open();
    });

    $('#pta-print-save').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        var calendarId = $calendar.val();
        var data = {
            action: cfg.saveAction,
            nonce: cfg.saveNonce,
            calendar: calendarId,
            'settings[header_id]': working.header_id || 0
        };
        FIELDS.forEach(function (key) {
            data['settings[' + key + ']'] = working[key] || '';
        });
        $status.text(cfg.strings.saving);
        $.post(cfg.ajaxUrl, data).done(function (res) {
            if (res && res.success) {
                cfg.settings[calendarId] = $.extend({}, cfg.settings[calendarId], res.data.settings, {
                    header_url: working.header_id ? working.header_url : '',
                    header_width: working.header_id ? working.header_width : 0
                });
                $status.text(cfg.strings.saved);
            } else {
                $status.text((res && res.data && res.data.message) || cfg.strings.saveFailed);
            }
        }).fail(function () {
            $status.text(cfg.strings.saveFailed);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $('#pta-print-print').on('click', function () {
        if (iframe.contentWindow && !$frame.hasClass('loading')) {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        }
    });

    $(window).on('resize', fitPreview);

    restore();
    loadCalendar();
    refresh();
})(jQuery);
