(function () {
    'use strict';

    const statusUrl = OC.generateUrl('/apps/file_checksum_search/settings/status');
    const compatUrl = OC.generateUrl('/apps/file_checksum_search/settings/compatibility');
    const purgeUrl = OC.generateUrl('/apps/file_checksum_search/settings/purge');
    const rebuildUrl = OC.generateUrl('/apps/file_checksum_search/settings/rebuild');
    const teardownUrl = OC.generateUrl('/apps/file_checksum_search/settings/teardown');
    const removeTableUrl = OC.generateUrl('/apps/file_checksum_search/settings/remove-table');

    function setText(id, text) {
        const el = document.getElementById(id);
        if (el) { el.textContent = text; }
    }

    function setHtml(id, html) {
        const el = document.getElementById(id);
        if (el) { el.innerHTML = html; }
    }

    function loadStatus() {
        fetch(statusUrl)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                setText('fcias-status-version', data.version || '—');
                setText('fcias-status-dbversion', data.dbVersion || '—');
                setText('fcias-status-rowcount', String(data.rowCount || 0));
                setHtml('fcias-status-triggers', data.triggersOk
                    ? '<span class="fcias-compat-pass">OK</span>'
                    : '<span class="fcias-compat-fail">MISSING</span>');
                setHtml('fcias-status-sp', data.spOk
                    ? '<span class="fcias-compat-pass">OK</span>'
                    : '<span class="fcias-compat-fail">MISSING</span>');
            })
            .catch(function () {
                setHtml('fcias-msg', '<p class="fcias-error">Failed to load status.</p>');
            });
    }

    function runCompat() {
        setHtml('fcias-compat-results', '<p>Running…</p>');
        fetch(compatUrl)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                let html = '';
                const checks = data.checks || {};
                Object.keys(checks).forEach(function (key) {
                    const c = checks[key];
                    const cls = c.pass ? 'fcias-compat-pass' : 'fcias-compat-fail';
                    html += '<p class="' + cls + '">' + escapeHtml(c.label) + ': ' + escapeHtml(c.value) + '</p>';
                });
                html += '<p><strong>' + (data.allPass ? 'All checks passed.' : 'Some checks failed.') + '</strong></p>';
                setHtml('fcias-compat-results', html);
            })
            .catch(function () {
                setHtml('fcias-compat-results', '<p class="fcias-error">Compatibility test failed.</p>');
            });
    }

    function postAction(url, callback) {
        fetch(url, {
            method: 'POST',
            headers: {
                'requesttoken': OC.requestToken,
                'Content-Type': 'application/json',
            },
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    OC.Notification.showTemporary('Action completed successfully.');
                } else {
                    OC.Notification.showTemporary(data.error || 'Action failed.', { type: 'error' });
                }
                if (callback) { callback(); }
            })
            .catch(function () {
                OC.Notification.showTemporary('Request failed.', { type: 'error' });
            });
    }

    function confirmAndPost(url, message, callback) {
        OC.dialogs.confirm(
            message,
            'Confirm',
            function (confirmed) {
                if (confirmed) { postAction(url, callback); }
            },
            true
        );
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    document.addEventListener('DOMContentLoaded', function () {
        loadStatus();

        const btnCompat = document.getElementById('fcias-btn-compat');
        if (btnCompat) { btnCompat.addEventListener('click', runCompat); }

        const btnPurge = document.getElementById('fcias-btn-purge');
        if (btnPurge) {
            btnPurge.addEventListener('click', function () {
                confirmAndPost(purgeUrl, 'This will delete ALL checksum index data. Continue?', loadStatus);
            });
        }

        const btnRebuild = document.getElementById('fcias-btn-rebuild');
        if (btnRebuild) {
            btnRebuild.addEventListener('click', function () {
                confirmAndPost(rebuildUrl, 'This will repopulate the hash table from existing filecache checksums. Continue?', loadStatus);
            });
        }

        const btnTeardown = document.getElementById('fcias-btn-teardown');
        if (btnTeardown) {
            btnTeardown.addEventListener('click', function () {
                confirmAndPost(teardownUrl, 'This will remove FCIAS triggers and stored procedure. Hash table is preserved. Continue?', loadStatus);
            });
        }

        const btnRemoveTable = document.getElementById('fcias-btn-removetable');
        if (btnRemoveTable) {
            btnRemoveTable.addEventListener('click', function () {
                confirmAndPost(removeTableUrl, 'This will permanently delete the hash table. Run teardown first. Continue?', loadStatus);
            });
        }
    });
})();
