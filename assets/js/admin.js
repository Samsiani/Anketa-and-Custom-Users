(function () {
    'use strict';

    if (!window.acuAdmin) return;

    var cfg = window.acuAdmin;

    // ---- Bulk link club cards ----
    var bulkBtn    = document.getElementById('acu-bulk-link-btn');
    var statusBox  = document.getElementById('acu-bulk-link-status');
    var progressEl = document.getElementById('acu-bulk-link-progress');
    var barEl      = document.getElementById('acu-bulk-link-bar');
    var statsEl    = document.getElementById('acu-bulk-link-stats');

    if (bulkBtn) {
        bulkBtn.addEventListener('click', function () {
            bulkBtn.disabled = true;
            statusBox.style.display = 'block';
            barEl.style.width = '0';
            var totalLinked = 0;

            function runBatch(offset) {
                var data = new FormData();
                data.append('action', 'acu_bulk_link');
                data.append('_nonce', cfg.bulk_nonce);
                data.append('offset', offset);

                progressEl.textContent = cfg.i18n.processing;

                fetch(cfg.ajax_url, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        if (!resp.success) {
                            progressEl.textContent = cfg.i18n.error;
                            bulkBtn.disabled = false;
                            return;
                        }
                        var d = resp.data;
                        totalLinked += d.linked;
                        var pct = d.total > 0 ? Math.round((d.processed / d.total) * 100) : 100;
                        barEl.style.width = pct + '%';
                        statsEl.textContent = d.processed + ' / ' + d.total + ' ' +
                            cfg.i18n.users_processed + ', ' +
                            totalLinked + ' ' + cfg.i18n.coupons_linked;

                        if (d.done) {
                            progressEl.textContent = cfg.i18n.completed;
                            bulkBtn.disabled = false;
                        } else {
                            runBatch(d.processed);
                        }
                    })
                    .catch(function () {
                        progressEl.textContent = cfg.i18n.request_failed;
                        bulkBtn.disabled = false;
                    });
            }

            runBatch(0);
        });
    }
}());
