(function ($) {
    'use strict';

    var cfg = window.azureRestoreWizard || {};
    var logPollTimer = null;

    function ajax(action, data, opts) {
        opts = opts || {};
        data = data || {};
        data.action = 'azure_restore_wizard_' + action;
        data.nonce = cfg.nonce;

        return $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            data: data,
            timeout: opts.timeout || 300000
        });
    }

    function showResult(selector, html, type) {
        $(selector).html(
            '<div class="notice notice-' + (type || 'info') + '" style="padding:12px;">' + html + '</div>'
        ).show();
    }

    function goToStep(step) {
        window.location.href = cfg.pageUrl + '&step=' + step;
    }

    function esc(s) {
        if (!s) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(s));
        return div.innerHTML;
    }

    // ---- Activity log rendering ----

    var lastLogLen = 0;

    function appendLog(target, entries) {
        var $log = $(target);
        if (!entries || !entries.length) return;

        var newEntries = entries.slice(lastLogLen);
        lastLogLen = entries.length;

        newEntries.forEach(function (e) {
            var cls = e.type || 'info';
            var line = '<div class="' + cls + '">';
            line += '<span class="time">' + esc(e.time) + '</span>';
            line += esc(e.message);
            line += '</div>';
            $log.append(line);
        });

        // Auto-scroll
        $log.scrollTop($log[0].scrollHeight);
    }

    function startLogPolling(logTarget) {
        lastLogLen = 0;
        if (logPollTimer) clearInterval(logPollTimer);
        logPollTimer = setInterval(function () {
            ajax('get_progress').done(function (r) {
                if (r.success && r.data.log) {
                    appendLog(logTarget, r.data.log);
                }
            });
        }, 2000);
    }

    function stopLogPolling() {
        if (logPollTimer) {
            clearInterval(logPollTimer);
            logPollTimer = null;
        }
    }

    // ---- Sidebar step marking ----

    function markStep(listSelector, entityOrId, status) {
        var $li = $(listSelector + ' li[data-entity="' + entityOrId + '"], ' + listSelector + ' #rw-dbstep-' + entityOrId);
        $li.removeClass('active done error');
        var icon = 'minus';
        if (status === 'active') { $li.addClass('active'); icon = 'update'; }
        if (status === 'done')   { $li.addClass('done');   icon = 'yes'; }
        if (status === 'error')  { $li.addClass('error');  icon = 'no'; }
        $li.find('.dashicons').attr('class', 'dashicons dashicons-' + icon);
    }

    // ================================================================
    // Step 1: Validate Storage
    // ================================================================

    $(document).on('click', '#rw-validate-storage', function () {
        var $btn = $(this).prop('disabled', true).text('Validating...');
        $('#rw-connect-result').hide();

        ajax('validate_storage', {
            storage_account: $('#rw-storage-account').val(),
            storage_key: $('#rw-storage-key').val(),
            container_name: $('#rw-container-name').val()
        }).done(function (r) {
            if (r.success) {
                showResult('#rw-connect-result', r.data.message, 'success');
                setTimeout(function () { goToStep(2); }, 1000);
            } else {
                showResult('#rw-connect-result', r.data || 'Connection failed.', 'error');
                $btn.prop('disabled', false).text('Validate & Connect');
            }
        }).fail(function () {
            showResult('#rw-connect-result', 'Network error.', 'error');
            $btn.prop('disabled', false).text('Validate & Connect');
        });
    });

    // ================================================================
    // Step 2: List & Select Backups
    // ================================================================

    if ($('#rw-backups-table').length) {
        ajax('list_backups').done(function (r) {
            $('#rw-backups-loading').hide();
            if (!r.success || !r.data.backups.length) {
                $('#rw-no-backups').show();
                return;
            }
            var html = '';
            r.data.backups.forEach(function (b) {
                html += '<tr>';
                html += '<td><input type="radio" name="rw-backup" value="' + esc(b.prefix) + '" /></td>';
                html += '<td>' + esc(b.site || '(legacy)') + '</td>';
                html += '<td>' + esc(b.date || '-') + '</td>';
                html += '<td>' + (b.has_manifest ? '<span style="color:#00a32a;">Split (v2)</span>' : 'Legacy') + '</td>';
                html += '<td>' + b.files.length + '</td>';
                html += '</tr>';
            });
            $('#rw-backups-body').html(html);
            $('#rw-backups-table').show();
        }).fail(function () {
            $('#rw-backups-loading').hide();
            $('#rw-no-backups').text('Failed to load backups.').show();
        });

        $(document).on('change', 'input[name="rw-backup"]', function () {
            $('#rw-select-backup').prop('disabled', false);
        });
    }

    $(document).on('click', '#rw-select-backup', function () {
        var prefix = $('input[name="rw-backup"]:checked').val();
        if (!prefix) return;

        var $btn = $(this).prop('disabled', true).text('Selecting...');
        ajax('select_backup', { backup_prefix: prefix }).done(function (r) {
            if (r.success) {
                goToStep(3);
            } else {
                alert(r.data || 'Failed to select backup.');
                $btn.prop('disabled', false).text('Next');
            }
        });
    });

    // ================================================================
    // Step 3: Database Restore (with manifest warnings)
    // ================================================================

    if ($('#rw-manifest-loading').length) {
        ajax('get_manifest').done(function (r) {
            $('#rw-manifest-loading').hide();

            if (!r.success) {
                showResult('#rw-db-result', r.data || 'Failed to read backup manifest.', 'error');
                return;
            }

            var d = r.data;
            var detailsHtml = '<table class="form-table" style="margin:0;">';
            if (d.site_url)    detailsHtml += '<tr><th style="padding:6px 10px 6px 0;">Backup of:</th><td>' + esc(d.site_url) + '</td></tr>';
            if (d.wp_version)  detailsHtml += '<tr><th style="padding:6px 10px 6px 0;">WordPress:</th><td>' + esc(d.wp_version) + '</td></tr>';
            if (d.php_version) detailsHtml += '<tr><th style="padding:6px 10px 6px 0;">PHP:</th><td>' + esc(d.php_version) + '</td></tr>';
            if (d.timestamp)   detailsHtml += '<tr><th style="padding:6px 10px 6px 0;">Created:</th><td>' + esc(d.timestamp) + '</td></tr>';
            detailsHtml += '<tr><th style="padding:6px 10px 6px 0;">Format:</th><td>' + (d.format === 'v2' ? 'Split (v2)' : 'Legacy') + '</td></tr>';
            detailsHtml += '</table>';
            $('#rw-manifest-details').html(detailsHtml);

            // Warnings
            if (d.warnings && d.warnings.length) {
                var wHtml = '<div class="notice notice-warning" style="padding:12px;"><strong>Warnings:</strong><ul style="margin:8px 0 0 16px; list-style:disc;">';
                d.warnings.forEach(function (w) {
                    wHtml += '<li>' + w.message + '</li>';
                });
                wHtml += '</ul></div>';
                $('#rw-warnings').html(wHtml);
            }

            // Components
            if (d.components && d.components.length) {
                var cHtml = '<ul style="list-style:disc; margin-left:24px;">';
                d.components.forEach(function (c) {
                    var label = { database: 'Database', plugins: 'Plugins', themes: 'Themes', others: 'Other Content', content: 'Other Content' }[c.entity] || c.entity;
                    cHtml += '<li>' + esc(label) + ' (' + c.file_count + ' file' + (c.file_count !== 1 ? 's' : '') + ')</li>';
                });
                cHtml += '</ul>';
                $('#rw-component-preview').html(cHtml);
            }

            $('#rw-manifest-info').show();
            $('#rw-run-db-restore').show();
        }).fail(function () {
            $('#rw-manifest-loading').hide();
            // If manifest fails to load, still allow proceeding
            $('#rw-manifest-info').html('<div class="notice notice-info" style="padding:12px;">Could not load manifest. You can still proceed.</div>').show();
            $('#rw-run-db-restore').show();
        });
    }

    $(document).on('click', '#rw-run-db-restore', function () {
        if (!confirm('This will overwrite the current database with the backup. Are you sure?')) return;

        $(this).hide();
        $('#rw-db-back').hide();
        $('#rw-manifest-info').hide();
        $('#rw-db-restore-area').show();

        markStep('#rw-db-steps', 'download', 'active');
        startLogPolling('#rw-db-log');

        ajax('run_db', {}, { timeout: 1800000 }).done(function (r) {
            stopLogPolling();
            // Final log poll
            ajax('get_progress').done(function (pr) {
                if (pr.success && pr.data.log) appendLog('#rw-db-log', pr.data.log);
            });

            markStep('#rw-db-steps', 'download', 'done');
            markStep('#rw-db-steps', 'restore', 'done');
            markStep('#rw-db-steps', 'urls', 'done');
            markStep('#rw-db-steps', 'fixup', 'done');

            if (r.success) {
                var msg = '<p><strong>Database restored!</strong></p>';
                if (r.data.temp_login) {
                    msg += '<p>A temporary admin account has been created:</p>';
                    msg += '<table style="margin:8px 0;">';
                    msg += '<tr><td><strong>Username:</strong></td><td><code>' + esc(r.data.temp_login) + '</code></td></tr>';
                    msg += '<tr><td><strong>Password:</strong></td><td><code>' + esc(r.data.temp_password) + '</code></td></tr>';
                    msg += '</table>';
                    msg += '<p><strong>Copy these credentials now</strong>, then click below to log in and continue.</p>';
                    msg += '<p style="margin-top:16px;"><a href="' + esc(r.data.login_url) + '" class="button button-primary">Log In &amp; Continue</a></p>';
                } else {
                    msg += '<p><a href="' + esc(r.data.login_url) + '" class="button button-primary">Log In &amp; Continue</a></p>';
                }
                showResult('#rw-db-result', msg, 'success');
            } else {
                showResult('#rw-db-result', r.data || 'Database restore failed.', 'error');
                markStep('#rw-db-steps', 'restore', 'error');
                $('#rw-run-db-restore').show();
                $('#rw-db-back').show();
            }
        }).fail(function () {
            stopLogPolling();
            showResult('#rw-db-result', 'Network error during restore. The restore may still be running. ' +
                'Wait a minute, then <a href="' + cfg.pageUrl + '&step=4">check if you can log in</a>.', 'warning');
        });
    });

    // ================================================================
    // Step 5: Files Restore (with progress sidebar + activity log)
    // ================================================================

    $(document).on('click', '#rw-run-files-restore', function () {
        $(this).hide();
        $('#rw-files-preinfo').hide();
        $('#rw-files-restore-area').show();

        markStep('#rw-files-steps', 'plugins', 'active');
        startLogPolling('#rw-files-log');

        // Poll progress to update sidebar steps
        var stepPoll = setInterval(function () {
            ajax('get_progress').done(function (r) {
                if (!r.success) return;
                var msg = (r.data.progress && r.data.progress.message) || '';
                var msgLower = msg.toLowerCase();

                // Update sidebar based on current entity being restored
                var order = ['mu-plugins', 'plugins', 'themes', 'others'];
                var activeIdx = -1;
                for (var i = 0; i < order.length; i++) {
                    if (msgLower.indexOf(order[i]) !== -1) {
                        activeIdx = i;
                    }
                }
                if (activeIdx >= 0) {
                    markStep('#rw-files-steps', order[activeIdx], 'active');
                    for (var j = 0; j < activeIdx; j++) {
                        markStep('#rw-files-steps', order[j], 'done');
                    }
                }
            });
        }, 3000);

        ajax('run_files', {}, { timeout: 1800000 }).done(function (r) {
            clearInterval(stepPoll);
            stopLogPolling();

            // Final log poll
            ajax('get_progress').done(function (pr) {
                if (pr.success && pr.data.log) appendLog('#rw-files-log', pr.data.log);
            });

            if (r.success) {
                ['mu-plugins', 'plugins', 'themes', 'others'].forEach(function (e) {
                    markStep('#rw-files-steps', e, 'done');
                });
                markStep('#rw-files-steps', 'cleaning', 'done');
                markStep('#rw-files-steps', 'finished', 'done');

                showResult('#rw-files-result',
                    '<p>' + (r.data.message || 'Files restored!') + '</p>' +
                    '<p><a href="' + cfg.pageUrl + '&step=6" class="button button-primary">Next: Media Sync</a></p>',
                    'success');
            } else {
                showResult('#rw-files-result', r.data || 'File restore failed.', 'error');
                markStep('#rw-files-steps', 'finished', 'error');
                $('#rw-run-files-restore').show().text('Retry');
            }
        }).fail(function () {
            clearInterval(stepPoll);
            stopLogPolling();
            showResult('#rw-files-result', 'Network error.', 'error');
            $('#rw-run-files-restore').show().text('Retry');
        });
    });

    // ================================================================
    // Step 6: Media Sync
    // ================================================================

    $(document).on('click', '#rw-start-media-sync', function () {
        var $btn = $(this).prop('disabled', true).text('Syncing...');
        $('#rw-skip-media').hide();
        $('#rw-media-progress').show();
        $('#rw-media-bar').css('width', '20%');
        $('#rw-media-status').text('Pulling media from SharePoint... This may take a while.');

        ajax('start_media_sync', {}, { timeout: 3600000 }).done(function (r) {
            $('#rw-media-bar').css('width', '100%');
            if (r.success) {
                showResult('#rw-media-result',
                    '<p>' + (r.data.message || 'Media sync complete!') + '</p>' +
                    '<p><a href="' + cfg.pageUrl + '&step=7" class="button button-primary">Next: Complete</a></p>',
                    'success');
            } else {
                showResult('#rw-media-result', r.data || 'Media sync failed. You can sync manually later.', 'warning');
                $('#rw-skip-media').show().text('Continue');
            }
            $('#rw-media-progress').hide();
        }).fail(function () {
            showResult('#rw-media-result', 'Network error. You can sync manually later from OneDrive Media settings.', 'warning');
            $('#rw-media-progress').hide();
            $('#rw-skip-media').show().text('Continue');
        });
    });

    $(document).on('click', '#rw-skip-media', function () {
        goToStep(7);
    });

    // ================================================================
    // Step 7: Finish
    // ================================================================

    $(document).on('click', '#rw-finish', function () {
        var $btn = $(this).prop('disabled', true).text('Finishing...');
        ajax('complete').done(function (r) {
            if (r.success && r.data.redirect) {
                window.location.href = r.data.redirect;
            } else {
                alert(r.data || 'Error completing restore.');
                $btn.prop('disabled', false).text('Finish');
            }
        });
    });

    // ================================================================
    // Cancel
    // ================================================================

    $(document).on('click', '#rw-cancel', function () {
        if (!confirm('Cancel the restore wizard?')) return;
        ajax('cancel').done(function (r) {
            if (r.success && r.data.redirect) {
                window.location.href = r.data.redirect;
            }
        });
    });

})(jQuery);
