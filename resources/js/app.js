// resources/js/app.js
import './bootstrap';
import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.start();

// Stop double-submits: after the first submit, lock the form's buttons and block a second send.
// A form can opt out with the data-no-lock attribute.
(function () {
    var UNLOCK_AFTER_MS = 20000;

    function unlock(form) {
        if (form.dataset.locked !== '1') { return; }
        form.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (btn) {
            if (btn.dataset.originalHtml !== undefined) {
                btn.innerHTML = btn.dataset.originalHtml;
                delete btn.dataset.originalHtml;
            }
            btn.disabled = false;
        });
        delete form.dataset.locked;
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-no-lock')) { return; }
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') { return; }

        if (form.dataset.locked === '1') {
            event.preventDefault();
            return;
        }

        if (event.defaultPrevented) { return; }

        form.dataset.locked = '1';

        // Wait one tick so the browser has already read the form before buttons are disabled.
        setTimeout(function () {
            form.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (btn) {
                btn.dataset.originalHtml = btn.innerHTML;
                btn.innerHTML = 'Sending\u2026';
                btn.disabled = true;
            });
        }, 0);

        setTimeout(function () { unlock(form); }, UNLOCK_AFTER_MS);
    });

    // Coming back with the Back button should not leave a locked form behind.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            document.querySelectorAll('form[data-locked="1"]').forEach(unlock);
        }
    });
})();