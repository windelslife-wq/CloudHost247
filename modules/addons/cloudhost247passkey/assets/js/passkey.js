/* CloudHost247 Passkey — WebAuthn browser boundary (no dependencies).
 *
 * This script only moves WebAuthn options and responses between the browser
 * API and the addon JSON boundary. It never logs credential bytes and never
 * synthesizes authentication: every ceremony is verified server-side.
 */
(function () {
    'use strict';

    function base64UrlToBuffer(value) {
        var padding = '='.repeat((4 - (value.length % 4)) % 4);
        var base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = window.atob(base64);
        var buffer = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) {
            buffer[i] = raw.charCodeAt(i);
        }
        return buffer;
    }

    function bufferToBase64Url(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function preformatCreateOptions(options) {
        var out = JSON.parse(JSON.stringify(options));
        out.challenge = base64UrlToBuffer(out.challenge);
        out.user.id = base64UrlToBuffer(out.user.id);
        (out.excludeCredentials || []).forEach(function (credential) {
            credential.id = base64UrlToBuffer(credential.id);
        });
        return out;
    }

    function preformatGetOptions(options) {
        var out = JSON.parse(JSON.stringify(options));
        out.challenge = base64UrlToBuffer(out.challenge);
        (out.allowCredentials || []).forEach(function (credential) {
            credential.id = base64UrlToBuffer(credential.id);
        });
        return out;
    }

    function credentialToJson(credential) {
        var response = { clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON) };
        if (credential.response.attestationObject) {
            response.attestationObject = bufferToBase64Url(credential.response.attestationObject);
        }
        if (credential.response.authenticatorData) {
            response.authenticatorData = bufferToBase64Url(credential.response.authenticatorData);
        }
        if (credential.response.signature) {
            response.signature = bufferToBase64Url(credential.response.signature);
        }
        if (credential.response.userHandle) {
            response.userHandle = bufferToBase64Url(credential.response.userHandle);
        } else {
            response.userHandle = null;
        }
        return {
            id: credential.id,
            rawId: bufferToBase64Url(credential.rawId),
            type: credential.type,
            response: response
        };
    }

    function supported() {
        return !!(window.PublicKeyCredential
            && window.navigator
            && window.navigator.credentials
            && window.crypto);
    }

    function postJson(ajaxUrl, action, payload, csrfToken) {
        var separator = ajaxUrl.indexOf('?') === -1 ? '?' : '&';
        var url = ajaxUrl + separator + 'passkey_action=' + encodeURIComponent(action);
        if (csrfToken) {
            url += '&token=' + encodeURIComponent(csrfToken);
        }
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken || '',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload || {})
        }).then(function (response) {
            return response.json().then(function (data) {
                return { status: response.status, data: data };
            });
        });
    }

    function setStatus(element, message, isError) {
        if (!element) {
            return;
        }
        element.hidden = false;
        element.textContent = message;
        element.classList.toggle('alert-danger', !!isError);
        element.classList.toggle('alert-info', !isError);
    }

    function findLoginForm() {
        var forms = document.querySelectorAll('form');
        for (var i = 0; i < forms.length; i++) {
            var form = forms[i];
            if (form.querySelector('input[name="username"]') && form.querySelector('input[type="password"]')) {
                return form;
            }
        }
        return null;
    }

    function genericMessage(data) {
        if (data && data.error_code === 'RATE_LIMITED') {
            return 'Too many Passkey requests. Please wait and try again.';
        }
        if (data && data.error_code === 'CONFIGURATION_REQUIRED') {
            return 'Passkey sign-in is not available right now.';
        }
        if (data && data.message) {
            return data.message;
        }
        return 'Sign-in with Passkey failed. Please try again.';
    }

    /* ------------------------------------------------------------------ */
    /* Login (client + admin share the public ceremony endpoints).         */
    /* ------------------------------------------------------------------ */

    function mountLoginButton(userType, isAdmin) {
        if (!supported()) {
            return;
        }
        var form = findLoginForm();
        if (!form || form.querySelector('[data-ch247pk-login]')) {
            return;
        }
        var config = window.CH247PK || {};
        if (!config.ajaxUrl) {
            return;
        }
        var button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('data-ch247pk-login', '1');
        button.className = 'ch247pk-login-btn';
        button.textContent = 'Sign in with Passkey';
        var status = document.createElement('div');
        status.className = 'ch247pk-login-status text-muted';
        status.setAttribute('role', 'status');
        var submit = form.querySelector('[type="submit"]');
        if (submit && submit.parentNode) {
            submit.parentNode.appendChild(button);
            submit.parentNode.appendChild(status);
        } else {
            form.appendChild(button);
            form.appendChild(status);
        }

        button.addEventListener('click', function () {
            button.disabled = true;
            status.textContent = 'Waiting for your device…';
            var optionsResponse;
            postJson(config.ajaxUrl, 'auth_options', { user_type: userType }, config.csrfToken)
                .then(function (result) {
                    if (!result.data || !result.data.success) {
                        throw new Error(genericMessage(result.data));
                    }
                    optionsResponse = result.data.options;
                    return navigator.credentials.get({ publicKey: preformatGetOptions(optionsResponse.publicKey) });
                })
                .then(function (credential) {
                    return postJson(config.ajaxUrl, 'auth_verify', {
                        user_type: userType,
                        response: credentialToJson(credential)
                    }, config.csrfToken);
                })
                .then(function (result) {
                    var data = result.data || {};
                    if (!data.success) {
                        throw new Error(genericMessage(data));
                    }
                    if (data.authenticated && data.next_step === 'admin_area') {
                        window.location = 'index.php';
                    } else if (data.authenticated) {
                        window.location = 'clientarea.php';
                    } else if (data.next_step === 'two_factor') {
                        status.textContent = data.message || 'Complete the standard login to continue.';
                        button.disabled = false;
                    } else {
                        throw new Error(genericMessage(data));
                    }
                })
                .catch(function (error) {
                    if (error && error.name === 'NotAllowedError') {
                        status.textContent = 'Passkey request was cancelled or timed out.';
                    } else {
                        status.textContent = error && error.message ? error.message : genericMessage(null);
                    }
                    button.disabled = false;
                });
        });

        if (!isAdmin) {
            mountResetLink(form, config, status);
        }
    }

    function mountResetLink(form, config, loginStatus) {
        var link = document.createElement('a');
        link.className = 'ch247pk-reset-link';
        link.textContent = 'Reset password using Passkey';
        var container = document.createElement('div');
        container.className = 'ch247pk-reset-form';
        var submit = form.querySelector('[type="submit"]');
        if (submit && submit.parentNode) {
            submit.parentNode.appendChild(link);
            submit.parentNode.appendChild(container);
        } else {
            form.appendChild(link);
            form.appendChild(container);
        }
        link.addEventListener('click', function () {
            container.innerHTML = '';
            loginStatus.textContent = 'Waiting for your device…';
            var ticketId = null;
            postJson(config.ajaxUrl, 'pwreset_options', {}, config.csrfToken)
                .then(function (result) {
                    if (!result.data || !result.data.success) {
                        throw new Error(genericMessage(result.data));
                    }
                    ticketId = result.data.ticket_id;
                    return navigator.credentials.get({ publicKey: preformatGetOptions(result.data.options.publicKey) });
                })
                .then(function (credential) {
                    return postJson(config.ajaxUrl, 'pwreset_verify', {
                        ticket_id: ticketId,
                        response: credentialToJson(credential)
                    }, config.csrfToken);
                })
                .then(function (result) {
                    var data = result.data || {};
                    if (!data.success || !data.reset_authorized) {
                        throw new Error(genericMessage(data));
                    }
                    loginStatus.textContent = 'Passkey verified. Choose a new password (12+ characters).';
                    renderResetForm(container, config, loginStatus);
                })
                .catch(function (error) {
                    if (error && error.name === 'NotAllowedError') {
                        loginStatus.textContent = 'Passkey request was cancelled or timed out.';
                    } else {
                        loginStatus.textContent = error && error.message ? error.message : genericMessage(null);
                    }
                });
        });
    }

    function renderResetForm(container, config, loginStatus) {
        var first = document.createElement('input');
        first.type = 'password';
        first.className = 'form-control';
        first.placeholder = 'New password';
        first.autocomplete = 'new-password';
        var second = document.createElement('input');
        second.type = 'password';
        second.className = 'form-control';
        second.placeholder = 'Confirm new password';
        second.autocomplete = 'new-password';
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-primary btn-block';
        button.textContent = 'Set new password';
        container.appendChild(first);
        container.appendChild(second);
        container.appendChild(button);
        button.addEventListener('click', function () {
            button.disabled = true;
            postJson(config.ajaxUrl, 'pwreset_complete', {
                new_password: first.value,
                confirm_password: second.value
            }, config.csrfToken).then(function (result) {
                var data = result.data || {};
                if (!data.success) {
                    throw new Error(genericMessage(data));
                }
                loginStatus.textContent = 'Password changed. You can now sign in.';
                container.innerHTML = '';
            }).catch(function (error) {
                loginStatus.textContent = error && error.message ? error.message : genericMessage(null);
                button.disabled = false;
            });
        });
    }

    window.CH247PKLogin = {
        mountLoginButton: function () { mountLoginButton('client', false); },
        mountAdminLoginButton: function () { mountLoginButton('admin', true); }
    };

    /* ------------------------------------------------------------------ */
    /* Client management page.                                            */
    /* ------------------------------------------------------------------ */

    function manageRequest(page, action, payload, statusElement, retryWithConfirmation) {
        return postJson(page.ajaxUrl, action, payload, page.csrfToken).then(function (result) {
            var data = result.data || {};
            if (!data.success && data.error_code === 'CONFIRMATION_REQUIRED' && retryWithConfirmation) {
                setStatus(statusElement, 'Confirm with your passkey to continue…', false);
                return navigator.credentials.get({ publicKey: preformatGetOptions(data.options.publicKey) })
                    .then(function (credential) {
                        payload.ticket_id = data.ticket_id;
                        payload.confirmation_response = credentialToJson(credential);
                        return postJson(page.ajaxUrl, action, payload, page.csrfToken);
                    })
                    .then(function (retry) {
                        return retry.data || {};
                    });
            }
            return data;
        });
    }

    function initManagePage() {
        var page = window.CH247PK_PAGE;
        var addButton = document.getElementById('ch247pk-add');
        if (!page || !addButton) {
            return;
        }
        var statusElement = document.getElementById('ch247pk-status');
        if (!supported()) {
            setStatus(statusElement, 'This browser does not support Passkeys. Password sign-in continues to work.', true);
            addButton.disabled = true;
            return;
        }
        addButton.addEventListener('click', function () {
            var deviceName = window.prompt('Name this Passkey (for example: My iPhone)', 'My device');
            if (deviceName === null) {
                return;
            }
            addButton.disabled = true;
            setStatus(statusElement, 'Waiting for your device…', false);
            postJson(page.ajaxUrl, 'register_options', {}, page.csrfToken)
                .then(function (result) {
                    if (!result.data || !result.data.success) {
                        throw new Error(genericMessage(result.data));
                    }
                    return navigator.credentials.create({ publicKey: preformatCreateOptions(result.data.options.publicKey) });
                })
                .then(function (credential) {
                    return postJson(page.ajaxUrl, 'register_verify', {
                        device_name: deviceName,
                        response: credentialToJson(credential)
                    }, page.csrfToken);
                })
                .then(function (result) {
                    var data = result.data || {};
                    if (!data.success) {
                        throw new Error(genericMessage(data));
                    }
                    setStatus(statusElement, 'Passkey registered.', false);
                    window.location.reload();
                })
                .catch(function (error) {
                    if (error && error.name === 'NotAllowedError') {
                        setStatus(statusElement, 'Passkey request was cancelled or timed out.', true);
                    } else {
                        setStatus(statusElement, error && error.message ? error.message : genericMessage(null), true);
                    }
                    addButton.disabled = false;
                });
        });

        document.querySelectorAll('[data-ch247pk-rename]').forEach(function (button) {
            button.addEventListener('click', function () {
                var name = window.prompt('Rename this Passkey');
                if (name === null) {
                    return;
                }
                manageRequest(page, 'credential_rename', {
                    id: button.getAttribute('data-ch247pk-rename'),
                    device_name: name
                }, statusElement, false).then(function (data) {
                    if (!data.success) {
                        throw new Error(genericMessage(data));
                    }
                    window.location.reload();
                }).catch(function (error) {
                    setStatus(statusElement, error.message || genericMessage(null), true);
                });
            });
        });

        document.querySelectorAll('[data-ch247pk-revoke]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (!window.confirm('Remove this Passkey?')) {
                    return;
                }
                setStatus(statusElement, 'Working…', false);
                manageRequest(page, 'credential_revoke', { id: button.getAttribute('data-ch247pk-revoke') }, statusElement, true)
                    .then(function (data) {
                        if (!data.success) {
                            throw new Error(genericMessage(data));
                        }
                        window.location.reload();
                    })
                    .catch(function (error) {
                        setStatus(statusElement, error.message || genericMessage(null), true);
                    });
            });
        });
        document.querySelectorAll('[data-ch247pk-disable]').forEach(function (button) {
            button.addEventListener('click', function () {
                setStatus(statusElement, 'Working…', false);
                manageRequest(page, 'credential_disable', { id: button.getAttribute('data-ch247pk-disable') }, statusElement, true)
                    .then(function (data) {
                        if (!data.success) {
                            throw new Error(genericMessage(data));
                        }
                        window.location.reload();
                    })
                    .catch(function (error) {
                        setStatus(statusElement, error.message || genericMessage(null), true);
                    });
            });
        });
        document.querySelectorAll('[data-ch247pk-enable]').forEach(function (button) {
            button.addEventListener('click', function () {
                setStatus(statusElement, 'Working…', false);
                manageRequest(page, 'credential_enable', { id: button.getAttribute('data-ch247pk-enable') }, statusElement, false)
                    .then(function (data) {
                        if (!data.success) {
                            throw new Error(genericMessage(data));
                        }
                        window.location.reload();
                    })
                    .catch(function (error) {
                        setStatus(statusElement, error.message || genericMessage(null), true);
                    });
            });
        });
    }

    /* ------------------------------------------------------------------ */
    /* Admin "My Passkeys" tab + step-up form interception.               */
    /* ------------------------------------------------------------------ */

    function initAdmin() {
        var admin = window.CH247PK_ADMIN;
        if (!admin || !admin.ajaxUrl) {
            return;
        }
        var addButton = document.getElementById('ch247pk-admin-add');
        var statusElement = document.getElementById('ch247pk-admin-status');
        function adminStatus(message) {
            if (statusElement) {
                statusElement.textContent = message;
            }
        }
        if (addButton) {
            if (!supported()) {
                addButton.disabled = true;
                adminStatus('This browser does not support Passkeys.');
            } else {
                addButton.addEventListener('click', function () {
                    var deviceName = window.prompt('Name this Passkey (for example: Office laptop)', 'My device');
                    if (deviceName === null) {
                        return;
                    }
                    addButton.disabled = true;
                    adminStatus('Waiting for your device…');
                    postJson(admin.ajaxUrl, 'admin_register_options', {}, admin.csrfToken)
                        .then(function (result) {
                            if (!result.data || !result.data.success) {
                                throw new Error(genericMessage(result.data));
                            }
                            return navigator.credentials.create({ publicKey: preformatCreateOptions(result.data.options.publicKey) });
                        })
                        .then(function (credential) {
                            return postJson(admin.ajaxUrl, 'admin_register_verify', {
                                device_name: deviceName,
                                response: credentialToJson(credential)
                            }, admin.csrfToken);
                        })
                        .then(function (result) {
                            var data = result.data || {};
                            if (!data.success) {
                                throw new Error(genericMessage(data));
                            }
                            window.location.reload();
                        })
                        .catch(function (error) {
                            adminStatus(error && error.message ? error.message : genericMessage(null));
                            addButton.disabled = false;
                        });
                });
            }
        }

        function adminAction(action, id, extraAttempt) {
            var payload = { id: id };
            if (extraAttempt) {
                payload.ticket_id = extraAttempt.ticket_id;
                payload.confirmation_response = extraAttempt.confirmation_response;
            }
            return postJson(admin.ajaxUrl, action, payload, admin.csrfToken).then(function (result) {
                var data = result.data || {};
                if (!data.success && data.error_code === 'CONFIRMATION_REQUIRED' && !extraAttempt) {
                    adminStatus('Confirm with your passkey to continue…');
                    return navigator.credentials.get({ publicKey: preformatGetOptions(data.options.publicKey) })
                        .then(function (credential) {
                            return adminAction(action, id, {
                                ticket_id: data.ticket_id,
                                confirmation_response: credentialToJson(credential)
                            });
                        });
                }
                return data;
            });
        }

        document.querySelectorAll('[data-ch247pk-admin-rename]').forEach(function (button) {
            button.addEventListener('click', function () {
                var name = window.prompt('Rename this Passkey');
                if (name === null) {
                    return;
                }
                postJson(admin.ajaxUrl, 'admin_credential_rename', {
                    id: button.getAttribute('data-ch247pk-admin-rename'),
                    device_name: name
                }, admin.csrfToken).then(function (result) {
                    if (!result.data || !result.data.success) {
                        throw new Error(genericMessage(result.data));
                    }
                    window.location.reload();
                }).catch(function (error) {
                    adminStatus(error.message || genericMessage(null));
                });
            });
        });
        [['[data-ch247pk-admin-revoke]', 'admin_credential_revoke', 'Revoke this Passkey?'],
         ['[data-ch247pk-admin-disable]', 'admin_credential_disable', null],
         ['[data-ch247pk-admin-enable]', 'admin_credential_enable', null]].forEach(function (binding) {
            document.querySelectorAll(binding[0]).forEach(function (button) {
                button.addEventListener('click', function () {
                    if (binding[2] && !window.confirm(binding[2])) {
                        return;
                    }
                    adminStatus('Working…');
                    var id = button.getAttribute(binding[0].replace(/[\[\]]/g, ''));
                    adminAction(binding[1], id, null).then(function (data) {
                        if (!data.success) {
                            throw new Error(genericMessage(data));
                        }
                        window.location.reload();
                    }).catch(function (error) {
                        adminStatus(error.message || genericMessage(null));
                    });
                });
            });
        });

        // Step-up interception for privileged admin forms.
        document.querySelectorAll('form[data-confirm-required="1"]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (form.getAttribute('data-ch247pk-confirmed') === '1') {
                    return;
                }
                event.preventDefault();
                if (!supported()) {
                    window.alert('This browser does not support Passkeys, but step-up confirmation is required.');
                    return;
                }
                var actionCode = form.getAttribute('data-ch247pk-confirm') || 'auth.policy';
                var ticketId = null;
                postJson(admin.ajaxUrl, 'admin_action_options', { action_code: actionCode }, admin.csrfToken)
                    .then(function (result) {
                        if (!result.data || !result.data.success) {
                            throw new Error(genericMessage(result.data));
                        }
                        ticketId = result.data.ticket_id;
                        return navigator.credentials.get({ publicKey: preformatGetOptions(result.data.options.publicKey) });
                    })
                    .then(function (credential) {
                        var ticketField = document.createElement('input');
                        ticketField.type = 'hidden';
                        ticketField.name = 'ticket_id';
                        ticketField.value = ticketId;
                        var responseField = document.createElement('input');
                        responseField.type = 'hidden';
                        responseField.name = 'confirmation_response';
                        responseField.value = JSON.stringify(credentialToJson(credential));
                        form.appendChild(ticketField);
                        form.appendChild(responseField);
                        form.setAttribute('data-ch247pk-confirmed', '1');
                        form.submit();
                    })
                    .catch(function (error) {
                        if (error && error.name === 'NotAllowedError') {
                            window.alert('Passkey request was cancelled or timed out. No change was applied.');
                        } else {
                            window.alert(error && error.message ? error.message : genericMessage(null));
                        }
                    });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initManagePage();
            initAdmin();
        });
    } else {
        initManagePage();
        initAdmin();
    }
})();
