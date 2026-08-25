/**
 * Media-library picker for pta_event attachments.
 * Azure AD / editor / admin users with upload_files can add files.
 */
(function () {
    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function addRow(list, id, url, title) {
        if (list.querySelector('[data-id="' + id + '"]')) {
            return;
        }
        var li = document.createElement('li');
        li.className = 'pta-event-attachment-row';
        li.setAttribute('data-id', String(id));

        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'pta_event_attachment_ids[]';
        hidden.value = String(id);

        var link = document.createElement('a');
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = title || url;

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'button-link pta-event-attachment-remove';
        remove.textContent = 'Remove';

        li.appendChild(hidden);
        li.appendChild(link);
        li.appendChild(document.createTextNode(' '));
        li.appendChild(remove);
        list.appendChild(li);
    }

    ready(function () {
        var list = document.getElementById('pta-event-attachment-list');
        var addBtn = document.getElementById('pta-event-attachment-add');
        if (!list || !addBtn || typeof wp === 'undefined' || !wp.media) {
            return;
        }

        addBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var frame = wp.media({
                title: 'Attach files from the media library',
                button: { text: 'Add to event' },
                multiple: true
            });
            frame.on('select', function () {
                frame.state().get('selection').each(function (att) {
                    var data = att.toJSON();
                    var title = data.filename || data.title || data.url;
                    addRow(list, data.id, data.url, title);
                });
            });
            frame.open();
        });

        list.addEventListener('click', function (e) {
            var btn = e.target.closest('.pta-event-attachment-remove');
            if (!btn) {
                return;
            }
            e.preventDefault();
            var row = btn.closest('.pta-event-attachment-row');
            if (row) {
                row.remove();
            }
        });
    });
})();
