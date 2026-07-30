(function () {
    'use strict';

    var tableSearch = document.querySelector('[data-link-table-search]');
    if (tableSearch) {
        tableSearch.addEventListener('input', function () {
            var query = tableSearch.value.trim().toLowerCase();
            document.querySelectorAll('[data-link-row]').forEach(function (row) {
                row.hidden = query !== '' && !(row.getAttribute('data-search-text') || '').includes(query);
            });
        });
    }

    document.querySelectorAll('[data-unlink-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm('Unlink this account holder and supplier? Existing financial records will not be changed.')) {
                event.preventDefault();
            }
        });
    });
}());
