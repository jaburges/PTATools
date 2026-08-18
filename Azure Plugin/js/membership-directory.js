(function () {
    function appendRedirect(link) {
        if (!link) {
            return;
        }
        var href = link.getAttribute('href') || '';
        if (!href || href.indexOf('redirect_to=') !== -1) {
            return;
        }
        link.setAttribute(
            'href',
            href + (href.indexOf('?') === -1 ? '?' : '&') +
                'redirect_to=' + encodeURIComponent(location.pathname + location.search)
        );
    }

    function bindTable(root) {
        var search = root.querySelector('.pta-parent-directory__search');
        var grade = root.querySelector('.pta-parent-directory__grade');
        var count = root.querySelector('.pta-parent-directory__count');
        var table = root.querySelector('.pta-parent-directory__table');
        if (!table) {
            return;
        }
        var tbody = table.tBodies[0];
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-name]'));
        var headers = table.querySelectorAll('th[data-sort]');
        var sortKey = 'name';
        var sortDir = 'asc';

        function apply() {
            var q = ((search && search.value) || '').toLowerCase().trim();
            var g = (grade && grade.value) || '';
            var shown = 0;
            rows.forEach(function (tr) {
                var hay = (
                    (tr.getAttribute('data-name') || '') + ' ' +
                    (tr.getAttribute('data-children') || '') + ' ' +
                    (tr.getAttribute('data-email') || '')
                ).toLowerCase();
                var ok = true;
                if (q && hay.indexOf(q) === -1) {
                    ok = false;
                }
                if (g) {
                    var grades = (tr.getAttribute('data-grades') || '').split('|');
                    if (grades.indexOf(g) === -1) {
                        ok = false;
                    }
                }
                tr.style.display = ok ? '' : 'none';
                if (ok) {
                    shown++;
                }
            });
            if (count) {
                count.textContent = shown + (shown === 1 ? ' parent' : ' parents');
            }
        }

        function sortBy(key) {
            if (sortKey === key) {
                sortDir = sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                sortKey = key;
                sortDir = 'asc';
            }
            rows.sort(function (a, b) {
                var av = (a.getAttribute('data-' + key) || '').toLowerCase();
                var bv = (b.getAttribute('data-' + key) || '').toLowerCase();
                if (av < bv) {
                    return sortDir === 'asc' ? -1 : 1;
                }
                if (av > bv) {
                    return sortDir === 'asc' ? 1 : -1;
                }
                return 0;
            });
            rows.forEach(function (tr) {
                tbody.appendChild(tr);
            });
            headers.forEach(function (th) {
                th.classList.toggle('is-asc', th.getAttribute('data-sort') === sortKey && sortDir === 'asc');
                th.classList.toggle('is-desc', th.getAttribute('data-sort') === sortKey && sortDir === 'desc');
            });
        }

        if (search) {
            search.addEventListener('input', apply);
        }
        if (grade) {
            grade.addEventListener('change', apply);
        }
        headers.forEach(function (th) {
            th.addEventListener('click', function () {
                sortBy(th.getAttribute('data-sort'));
            });
        });
        apply();
    }

    document.querySelectorAll('.pta-parent-directory__login').forEach(appendRedirect);
    document.querySelectorAll('[data-pta-parent-directory]').forEach(bindTable);
})();
