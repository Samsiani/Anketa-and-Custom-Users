(function () {
    'use strict';

    function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }

    // Dismiss consent banners
    function dismissAll() {
        $$('[data-wcu-consent-banner]').forEach(function (e) { e.style.display = 'none'; });
    }

    // Club card toggle (checkbox shows/hides input)
    function bindClubCardToggle() {
        $$('[data-acu-toggle]').forEach(function (checkbox) {
            var targetSel = checkbox.getAttribute('data-target');
            if (!targetSel) return;
            var target = document.querySelector(targetSel);
            if (!target) return;

            function toggleTarget() {
                var input = target.querySelector('input');
                if (checkbox.checked) {
                    target.style.display = '';
                    if (input) input.removeAttribute('disabled');
                } else {
                    target.style.display = 'none';
                    if (input) input.setAttribute('disabled', 'disabled');
                }
            }

            toggleTarget(); // init state
            checkbox.addEventListener('change', toggleTarget);
        });
    }

    function bind() {
        // Dismiss banners
        $$('[data-wcu-dismiss-consent]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!window.acuAccount || !acuAccount.ajax_url) { dismissAll(); return; }
                var x = new XMLHttpRequest();
                x.open('POST', acuAccount.ajax_url, true);
                x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                x.onreadystatechange = function () { if (x.readyState !== 4) return; dismissAll(); };
                x.send('action=acu_dismiss_consent_notice&nonce=' + encodeURIComponent(acuAccount.nonce || ''));
            });
        });

        bindClubCardToggle();
    }

    document.addEventListener('DOMContentLoaded', bind);
}());
