(function () {
    'use strict';

    function $(s, r) { return (r || document).querySelector(s); }
    function $all(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
    function show(el) { if (el) el.style.display = ''; }
    function hide(el) { if (el) el.style.display = 'none'; }
    function setHTML(el, html) { if (el) el.innerHTML = html; }

    function submit(e) {
        e.preventDefault();
        var form  = e.currentTarget;
        var root  = form.closest('.wcu-udc');
        if (!root || !window.acuUdc) return;

        var input   = $('#acu_udc_query', root);
        var query   = input ? (input.value || '').trim() : '';
        var loading = $('[data-wcu-udc-loading]', root);
        var errorBox = $('[data-wcu-udc-error]', root);
        var results = $('[data-wcu-udc-results]', root);

        if (query === '') {
            setHTML(errorBox, window.acuUdc.i18n.no_query);
            show(errorBox); hide(results); return;
        }
        hide(errorBox); setHTML(results, ''); show(loading);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.acuUdc.ajax_url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            hide(loading);
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp && resp.success && resp.data && resp.data.html) {
                    hide(errorBox); setHTML(results, resp.data.html); show(results);
                } else {
                    var msg = (resp && resp.data && resp.data.message) ? resp.data.message : (acuUdc.i18n.error || 'Error');
                    setHTML(errorBox, msg); show(errorBox); hide(results);
                }
            } catch (err) {
                setHTML(errorBox, acuUdc.i18n.error || 'Error'); show(errorBox); hide(results);
            }
        };
        var body = 'action=acu_udc_search&nonce=' + encodeURIComponent(acuUdc.nonce || '') + '&query=' + encodeURIComponent(query);
        xhr.send(body);
    }

    function init() {
        $all('.wcu-udc__form').forEach(function (f) { f.addEventListener('submit', submit); });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
