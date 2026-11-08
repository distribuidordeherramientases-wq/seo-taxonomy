/* Open the existing Insights consent manager; never overwrite a choice. */
(function () {
    'use strict';
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-tci-open-preferences]');
        if (!button) {
            return;
        }
        var opener = document.getElementById('tci-consent-reopen');
        if (opener) {
            opener.click();
        }
    });
}());
