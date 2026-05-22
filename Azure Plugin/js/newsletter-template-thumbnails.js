/**
 * Newsletter Template Thumbnails
 *
 * On the Newsletter > Templates tab, every card that is missing a cached
 * thumbnail PNG gets a one-time, off-screen render + html2canvas snapshot.
 * The PNG is uploaded to the server and persisted on the template row so
 * future page loads only render a static <img>.
 *
 * Snapshots are processed serially (one iframe alive at a time) to keep
 * memory usage low even when many templates are pending generation.
 */
(function ($) {
    'use strict';

    if (typeof window.azureNewsletterThumbnails === 'undefined') {
        return;
    }

    var settings = window.azureNewsletterThumbnails;
    var queue = [];

    // Logical iframe size used for the snapshot (matches typical email width)
    var RENDER_WIDTH  = 600;
    var RENDER_HEIGHT = 800;

    // Output PNG dimensions (small enough to keep the upload payload tiny)
    var OUTPUT_WIDTH  = 600;
    var OUTPUT_HEIGHT = 800;

    function readTemplateHtml($card) {
        var encoded = $card.find('script[type="text/template-html-b64"]').text() || '';
        if (!encoded) {
            return '';
        }
        try {
            // atob handles the base64 the server emitted; falls back gracefully on bad data
            return decodeURIComponent(escape(window.atob(encoded.replace(/\s+/g, ''))));
        } catch (e) {
            console.warn('[Newsletter Thumbnails] could not decode template HTML', e);
            return '';
        }
    }

    function init() {
        $('.template-card[data-pending-snapshot="1"]').each(function () {
            var $card = $(this);
            queue.push({
                id: parseInt($card.data('template-id'), 10),
                html: readTemplateHtml($card),
                $card: $card
            });
        });

        if (queue.length === 0) {
            return;
        }

        if (typeof window.html2canvas !== 'function') {
            console.warn('[Newsletter Thumbnails] html2canvas not loaded; aborting.');
            return;
        }

        // Kick off the queue
        processNext();

        // Manual "Regenerate" button per card
        $(document).on('click', '.regenerate-thumbnail', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var templateId = parseInt($btn.data('template-id'), 10);
            var $card = $btn.closest('.template-card');
            var html = readTemplateHtml($card);
            // If the HTML wasn't embedded (already cached), fetch via existing AJAX
            if (!templateId) {
                return;
            }
            if (!html) {
                return fetchTemplateHtml(templateId, $btn).then(function (fetched) {
                    if (!fetched) { return; }
                    runRegenerate($btn, templateId, fetched, $card);
                });
            }
            runRegenerate($btn, templateId, html, $card);
        });

        function runRegenerate($btn, templateId, html, $card) {
            $btn.prop('disabled', true).text(settings.strings.regenerating);
            clearServerThumbnail(templateId).always(function () {
                renderAndUpload({
                    id: templateId,
                    html: html,
                    $card: $card
                }, function () {
                    $btn.prop('disabled', false).text(settings.strings.regenerate);
                });
            });
        }

        // "Regenerate all" button
        $(document).on('click', '#regenerate-all-thumbnails', function (e) {
            e.preventDefault();
            if (!window.confirm(settings.strings.confirmRegenerateAll)) {
                return;
            }
            var $btn = $(this);
            $btn.prop('disabled', true);
            $.post(settings.ajaxUrl, {
                action: 'azure_newsletter_regenerate_all_thumbnails',
                nonce: settings.nonce
            }).always(function () {
                location.reload();
            });
        });
    }

    function processNext() {
        if (queue.length === 0) {
            return;
        }
        var task = queue.shift();
        renderAndUpload(task, processNext);
    }

    /**
     * Render the template HTML in an off-screen iframe and snapshot it.
     */
    function renderAndUpload(task, done) {
        if (!task.html) {
            done();
            return;
        }

        var $card = task.$card;
        var $preview = $card.find('.template-preview');
        $preview.addClass('is-generating');

        var iframe = document.createElement('iframe');
        // Position offscreen so the user never sees a flash of the live render
        iframe.style.cssText = [
            'position:fixed',
            'left:-10000px',
            'top:0',
            'width:' + RENDER_WIDTH + 'px',
            'height:' + RENDER_HEIGHT + 'px',
            'border:0',
            'visibility:hidden',
            'pointer-events:none'
        ].join(';');
        iframe.setAttribute('aria-hidden', 'true');
        iframe.setAttribute('tabindex', '-1');
        document.body.appendChild(iframe);

        var cleanup = function () {
            try { iframe.parentNode && iframe.parentNode.removeChild(iframe); } catch (e) {}
            $preview.removeClass('is-generating');
        };

        // Failsafe: if anything hangs, clean up after 15s
        var timeout = setTimeout(function () {
            cleanup();
            done();
        }, 15000);

        try {
            var doc = iframe.contentDocument || iframe.contentWindow.document;
            doc.open();
            doc.write(task.html);
            doc.close();

            // Wait for the iframe document + images to settle
            waitForReady(iframe).then(function () {
                return window.html2canvas(doc.body, {
                    width: RENDER_WIDTH,
                    height: RENDER_HEIGHT,
                    windowWidth: RENDER_WIDTH,
                    windowHeight: RENDER_HEIGHT,
                    backgroundColor: '#ffffff',
                    useCORS: true,
                    allowTaint: false,
                    logging: false,
                    scale: 1
                });
            }).then(function (canvas) {
                var resized = downscale(canvas, OUTPUT_WIDTH, OUTPUT_HEIGHT);
                var dataUrl = resized.toDataURL('image/png');
                return uploadThumbnail(task.id, dataUrl);
            }).then(function (response) {
                clearTimeout(timeout);
                if (response && response.success && response.data && response.data.url) {
                    swapInImage(task.$card, response.data.url);
                }
            }).catch(function (err) {
                console.warn('[Newsletter Thumbnails] snapshot failed for #' + task.id, err);
            }).then(function () {
                cleanup();
                done();
            });
        } catch (err) {
            console.warn('[Newsletter Thumbnails] render error for #' + task.id, err);
            clearTimeout(timeout);
            cleanup();
            done();
        }
    }

    /**
     * Resolve once the iframe has loaded its HTML and all images have either
     * loaded or errored. We bail after a short timeout so a single broken
     * remote image doesn't block snapshotting forever.
     */
    function waitForReady(iframe) {
        return new Promise(function (resolve) {
            var doc = iframe.contentDocument;
            var imgs = doc ? Array.prototype.slice.call(doc.images || []) : [];

            if (imgs.length === 0) {
                // Two RAFs to give layout a chance to settle
                requestAnimationFrame(function () {
                    requestAnimationFrame(resolve);
                });
                return;
            }

            var pending = imgs.length;
            var settled = false;
            var settle = function () {
                if (settled) return;
                settled = true;
                requestAnimationFrame(function () {
                    requestAnimationFrame(resolve);
                });
            };

            var done = function () {
                pending--;
                if (pending <= 0) settle();
            };

            imgs.forEach(function (img) {
                if (img.complete) {
                    done();
                } else {
                    img.addEventListener('load', done, { once: true });
                    img.addEventListener('error', done, { once: true });
                }
            });

            // Hard cap so cross-origin / 404 images can't stall us
            setTimeout(settle, 4000);
        });
    }

    /**
     * Downscale a canvas to the given target size, preserving aspect.
     */
    function downscale(srcCanvas, targetW, targetH) {
        if (srcCanvas.width <= targetW && srcCanvas.height <= targetH) {
            return srcCanvas;
        }
        var out = document.createElement('canvas');
        out.width = targetW;
        out.height = targetH;
        var ctx = out.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, targetW, targetH);
        ctx.drawImage(srcCanvas, 0, 0, targetW, targetH);
        return out;
    }

    function uploadThumbnail(templateId, dataUrl) {
        return $.ajax({
            url: settings.ajaxUrl,
            method: 'POST',
            data: {
                action: 'azure_newsletter_save_template_thumbnail',
                template_id: templateId,
                image: dataUrl,
                nonce: settings.nonce
            },
            timeout: 20000
        });
    }

    function clearServerThumbnail(templateId) {
        return $.ajax({
            url: settings.ajaxUrl,
            method: 'POST',
            data: {
                action: 'azure_newsletter_regenerate_template_thumbnail',
                template_id: templateId,
                nonce: settings.nonce
            },
            timeout: 10000
        });
    }

    /**
     * Fallback: if a card is already showing the cached <img> (so its HTML
     * is not embedded), pull the HTML from the existing get_template endpoint.
     */
    function fetchTemplateHtml(templateId, $btn) {
        if (!settings.getTemplateNonce) {
            return Promise.resolve('');
        }
        return $.ajax({
            url: settings.ajaxUrl,
            method: 'POST',
            data: {
                action: 'azure_newsletter_get_template',
                template_id: templateId,
                nonce: settings.getTemplateNonce
            },
            timeout: 10000
        }).then(function (response) {
            if (response && response.success && response.data && response.data.content_html) {
                return response.data.content_html;
            }
            return '';
        }, function () { return ''; });
    }

    function swapInImage($card, url) {
        var $preview = $card.find('.template-preview');
        $preview.empty();
        var $img = $('<img>', {
            src: url + '?v=' + Date.now(),
            alt: $card.find('.template-info h4').text() || 'Template preview',
            'class': 'template-thumbnail-img'
        });
        $preview.append($img);
        $card.attr('data-pending-snapshot', '0');
    }

    $(init);
}(jQuery));
