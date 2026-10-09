/* WHMCS is the checkout; this script only queues one paid-service VM request. */
(function () {
    'use strict';
    var root = document.querySelector('.ch247-vps[data-endpoint]');
    if (!root || !root.dataset.endpoint || !root.dataset.csrf) return;

    root.addEventListener('click', function (event) {
        var button = event.target.closest('.ch247-vps-request');
        if (!button || !root.contains(button) || button.disabled) return;
        var serviceId = Number(button.dataset.serviceId);
        var key = button.dataset.requestKey;
        var message = button.parentElement.querySelector('.ch247-vps-feedback');
        if (!Number.isSafeInteger(serviceId) || serviceId <= 0 || !key || !message) return;
        button.disabled = true;
        message.textContent = 'Submitting request…';
        fetch(root.dataset.endpoint, {
            method: 'POST', credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': root.dataset.csrf,
                'Idempotency-Key': key
            },
            body: JSON.stringify({service_id: serviceId})
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) throw new Error(body && body.error && body.error.message
                    ? body.error.message : 'The request could not be submitted.');
                return body;
            });
        }).then(function () {
            message.textContent = 'Request queued. Check your VPS service status shortly.';
        }).catch(function (error) {
            message.textContent = error.message || 'The request could not be submitted.';
            // Reuse the same idempotency key on retry; the server prevents duplicates.
            button.disabled = false;
        });
    });
}());
