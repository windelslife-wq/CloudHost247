/**
 * Tools Center - Client Area JavaScript
 * Handles tool forms, API calls, and result display
 */

(function() {
    'use strict';

    // API endpoint - will be set from template
    var apiUrl = window.toolsCenterConfig && window.toolsCenterConfig.apiUrl 
        ? window.toolsCenterConfig.apiUrl 
        : 'index.php?m=tools_center';

    // Tools that run entirely in the browser. They never send anything to the server.
    var LOCAL_TOOL_HANDLERS = {
        qrScanner: decodeQrFromForm,
        qrGenerator: generateQrFromForm
    };

    var SVG_DATA_URI_PREFIX = 'data:image/svg+xml;charset=utf-8,';

    function getLocalHandler(action) {
        return Object.prototype.hasOwnProperty.call(LOCAL_TOOL_HANDLERS, action)
            ? LOCAL_TOOL_HANDLERS[action]
            : null;
    }

    /**
     * Generate a QR code in the browser from the form fields (data, level, size).
     * The data never leaves the page.
     */
    function generateQrFromForm(form, showResult) {
        if (!window.ToolsCenterQRGen) {
            showResult({ success: false, error: 'The QR generator is not loaded. Reload the page and try again.' });
            return;
        }
        var field = function (name) {
            var el = form.querySelector('[name="' + name + '"]');
            return el ? el.value : '';
        };
        var result = window.ToolsCenterQRGen.generate(field('data'), field('level'), field('size'));
        if (!result.ok) {
            showResult({ success: false, error: result.error });
            return;
        }
        showResult({
            success: true,
            data: {
                error_correction: result.error_correction,
                size_px: result.size_px,
                characters: result.characters,
                format: 'SVG'
            },
            local_image: {
                src: SVG_DATA_URI_PREFIX + encodeURIComponent(result.svg),
                filename: 'qr-code.svg'
            },
            response_time_ms: 0
        });
    }

    /**
     * Decode the QR image chosen in the form, in the browser.
     * showResult receives a response shaped like the server responses ({success, data | error}).
     */
    function decodeQrFromForm(form, showResult) {
        var input = form.querySelector('input[type="file"]');
        var file = input && input.files ? input.files[0] : null;

        if (!window.ToolsCenterQR) {
            showResult({ success: false, error: 'The QR decoder is not loaded. Reload the page and try again.' });
            return;
        }

        var problem = window.ToolsCenterQR.validateFile(file);
        if (problem) {
            showResult({ success: false, error: problem });
            return;
        }

        window.ToolsCenterQR.decodeFile(file, function(result) {
            if (!result.ok) {
                showResult({ success: false, error: result.error });
                return;
            }
            showResult({
                success: true,
                data: {
                    decoded_text: result.text,
                    characters: result.text.length,
                    looks_like_url: /^https?:\/\//i.test(result.text)
                },
                response_time_ms: 0
            });
        });
    }

    /**
     * Open tool modal
     */
    window.openTool = function(category, action) {
        var def = window.toolDefinitions && window.toolDefinitions[action];
        if (!def) {
            // Redirect to tool page
            window.location.href = 'index.php?m=tools_center&cat=' + category + '&tool=' + action;
            return;
        }

        document.getElementById('modalTitle').textContent = def.title;
        var modalBody = document.getElementById('modalBody');
        modalBody.innerHTML = '';

        // Build form
        var form = document.createElement('form');
        form.className = 'tc-tool-dynamic-form';
        form.onsubmit = function(e) {
            e.preventDefault();
            submitToolForm(category, action, form);
        };

        // Form fields
        if (def.fields && def.fields.length > 0) {
            def.fields.forEach(function(field) {
                var group = document.createElement('div');
                group.className = 'tc-form-group';

                var label = document.createElement('label');
                label.textContent = field.label;
                if (field.required) {
                    label.className = 'tc-required';
                }
                group.appendChild(label);

                var input;
                if (field.type === 'textarea') {
                    input = document.createElement('textarea');
                    input.rows = field.rows || 4;
                } else if (field.type === 'select') {
                    input = document.createElement('select');
                    if (field.options) {
                        field.options.forEach(function(opt) {
                            var option = document.createElement('option');
                            option.value = opt;
                            option.textContent = opt;
                            input.appendChild(option);
                        });
                    }
                } else {
                    input = document.createElement('input');
                    input.type = field.type || 'text';
                    if (field.min) input.min = field.min;
                    if (field.max) input.max = field.max;
                    if (field.step) input.step = field.step;
                }

                input.name = field.name;
                input.className = 'form-control';
                if (field.placeholder) input.placeholder = field.placeholder;
                if (field.accept) input.accept = field.accept;
                if (field.value) input.value = field.value;
                if (field.required) input.required = true;

                group.appendChild(input);
                form.appendChild(group);
            });
        } else if (def.note) {
            var note = document.createElement('div');
            note.className = 'tc-alert tc-alert-info';
            note.textContent = def.note;
            form.appendChild(note);
        }

        // Actions
        var actions = document.createElement('div');
        actions.className = 'tc-form-actions';

        var submitBtn = document.createElement('button');
        submitBtn.type = 'submit';
        submitBtn.className = 'btn btn-primary';
        submitBtn.innerHTML = '<i class="fa fa-play"></i> Run Tool';
        actions.appendChild(submitBtn);

        var resetBtn = document.createElement('button');
        resetBtn.type = 'button';
        resetBtn.className = 'btn btn-default';
        resetBtn.textContent = 'Reset';
        resetBtn.onclick = function() {
            form.reset();
        };
        actions.appendChild(resetBtn);

        form.appendChild(actions);
        modalBody.appendChild(form);

        // Results container
        var resultsDiv = document.createElement('div');
        resultsDiv.id = 'modalResults';
        resultsDiv.style.display = 'none';
        modalBody.appendChild(resultsDiv);

        // Show modal
        document.getElementById('toolModal').classList.add('tc-active');

        // Auto-submit for tools with no fields
        if (!def.fields || def.fields.length === 0) {
            submitToolForm(category, action, form);
        }
    };

    /**
     * Close tool modal
     */
    window.closeToolModal = function() {
        document.getElementById('toolModal').classList.remove('tc-active');
    };

    /**
     * Submit tool form via AJAX
     */
    function submitToolForm(category, action, form) {
        var resultsDiv = document.getElementById('modalResults');
        var submitBtn = form.querySelector('button[type="submit"]');

        var localHandler = getLocalHandler(action);
        if (localHandler) {
            if (resultsDiv) {
                resultsDiv.style.display = 'block';
                resultsDiv.innerHTML = '<div class="tc-loading"><div class="tc-spinner"></div> Processing...</div>';
            }
            localHandler(form, function(response) {
                displayResults(resultsDiv, response);
            });
            return;
        }

        // Collect form data
        var params = {};
        var inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(function(input) {
            if (input.name) {
                params[input.name] = input.value;
            }
        });

        // Show loading
        if (resultsDiv) {
            resultsDiv.style.display = 'block';
            resultsDiv.innerHTML = '<div class="tc-loading"><div class="tc-spinner"></div> Processing...</div>';
        }

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing...';
        }

        // Make AJAX request
        var xhr = new XMLHttpRequest();
        xhr.open('POST', apiUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;

            // Re-enable button
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa fa-play"></i> Run Tool';
            }

            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    displayResults(resultsDiv, response);
                } catch (e) {
                    displayError(resultsDiv, 'Invalid response from server');
                }
            } else {
                displayError(resultsDiv, 'Request failed (HTTP ' + xhr.status + ')');
            }
        };

        // Build POST data
        var postData = 'category=' + encodeURIComponent(category) + 
                       '&action=' + encodeURIComponent(action);
        
        for (var key in params) {
            postData += '&params[' + encodeURIComponent(key) + ']=' + encodeURIComponent(params[key]);
        }

        xhr.send(postData);
    }

    /**
     * Display results
     */
    function displayResults(container, data) {
        if (!container) return;

        if (!data.success) {
            // Escape: the API reflects user-controlled input into `error`
            // (e.g. "Tool category not found: <category>"), so this string must
            // never reach innerHTML raw. Every other path here already escapes.
            container.innerHTML = '<div class="tc-alert tc-alert-danger"><i class="fa fa-exclamation-circle"></i> ' +
                escapeHtml(data.error || 'An error occurred') + '</div>';
            return;
        }

        var html = '<div class="tc-results-content">';
        html += '<div class="tc-result-section">';
        html += '<h4><i class="fa fa-check-circle"></i> Result</h4>';

        // Images generated in the browser (QR Generator). Only the local SVG data URI is accepted.
        if (data.local_image && typeof data.local_image.src === 'string' &&
            data.local_image.src.indexOf(SVG_DATA_URI_PREFIX) === 0) {
            html += '<div class="tc-qr-preview" style="margin-bottom:10px;">';
            html += '<img alt="QR code" src="' + escapeAttr(data.local_image.src) + '" ' +
                'style="max-width:100%;background:#fff;padding:8px;border:1px solid #ddd;">';
            html += '</div>';
            html += '<p><a class="btn btn-default" href="' + escapeAttr(data.local_image.src) + '" download="' +
                escapeAttr(data.local_image.filename || 'qr-code.svg') + '"><i class="fa fa-download"></i> Download SVG</a></p>';
        }

        // Render data based on type
        if (typeof data.data === 'object' && data.data !== null) {
            html += renderObject(data.data);
        } else {
            html += '<div class="tc-result-data"><pre>' + escapeHtml(String(data.data)) + '</pre></div>';
        }

        if (data.response_time_ms) {
            html += '<div style="margin-top:10px;color:#999;font-size:0.85em;">';
            html += '<i class="fa fa-clock"></i> Completed in ' + data.response_time_ms + 'ms';
            if (data.cached) html += ' (cached)';
            html += '</div>';
        }

        html += '</div></div>';
        container.innerHTML = html;
    }

    /**
     * Render object as HTML
     */
    function renderObject(obj, level) {
        level = level || 0;
        var html = '';

        if (Array.isArray(obj)) {
            if (obj.length === 0) {
                html += '<div class="tc-result-data">(empty)</div>';
            } else if (typeof obj[0] === 'object' && obj[0] !== null) {
                // Array of objects - render as table
                html += '<div class="table-responsive"><table class="tc-result-table table">';
                // Header
                var keys = Object.keys(obj[0]).filter(function(k) {
                    return typeof obj[0][k] !== 'object' || obj[0][k] === null;
                });
                html += '<thead><tr>';
                keys.forEach(function(k) {
                    html += '<th>' + escapeHtml(k) + '</th>';
                });
                html += '</tr></thead><tbody>';
                obj.forEach(function(item) {
                    html += '<tr>';
                    keys.forEach(function(k) {
                        var val = item[k];
                        if (typeof val === 'boolean') {
                            html += '<td><span class="tc-badge-status ' + 
                                (val ? 'tc-badge-success' : 'tc-badge-danger') + '">' + 
                                (val ? 'Yes' : 'No') + '</span></td>';
                        } else {
                            html += '<td>' + escapeHtml(String(val !== null ? val : '-')) + '</td>';
                        }
                    });
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
            } else {
                // Simple array
                html += '<div class="tc-result-data"><ul>';
                obj.forEach(function(item) {
                    html += '<li>' + escapeHtml(String(item)) + '</li>';
                });
                html += '</ul></div>';
            }
        } else {
            // Object
            html += '<div class="tc-result-data">';
            for (var key in obj) {
                if (!obj.hasOwnProperty(key)) continue;
                var val = obj[key];
                var label = key.replace(/_/g, ' ').replace(/([A-Z])/g, ' $1').trim();
                label = label.charAt(0).toUpperCase() + label.slice(1);

                if (typeof val === 'object' && val !== null && level < 2) {
                    html += '<div style="margin-bottom:10px;">';
                    html += '<strong>' + escapeHtml(label) + ':</strong>';
                    html += renderObject(val, level + 1);
                    html += '</div>';
                } else if (typeof val === 'boolean') {
                    html += '<div><strong>' + escapeHtml(label) + ':</strong> ';
                    html += '<span class="tc-badge-status ' + (val ? 'tc-badge-success' : 'tc-badge-danger') + '">';
                    html += (val ? 'Yes' : 'No') + '</span></div>';
                } else if (key === 'security_score' || key === 'overall_score' || key === 'health_score' || key === 'score') {
                    var score = parseInt(val) || 0;
                    var scoreClass = score >= 80 ? 'tc-score-excellent' : (score >= 60 ? 'tc-score-good' : (score >= 40 ? 'tc-score-fair' : 'tc-score-poor'));
                    html += '<div style="display:flex;align-items:center;gap:15px;margin:10px 0;">';
                    html += '<div class="tc-score ' + scoreClass + '">' + score + '</div>';
                    html += '<div><strong>' + escapeHtml(label) + '</strong></div></div>';
                } else if (key === 'raw' || key === 'raw_output') {
                    html += '<div><strong>' + escapeHtml(label) + ':</strong></div>';
                    html += '<pre>' + escapeHtml(String(val)) + '</pre>';
                } else {
                    html += '<div><strong>' + escapeHtml(label) + ':</strong> ' + escapeHtml(String(val !== null ? val : '-')) + '</div>';
                }
            }
            html += '</div>';
        }

        return html;
    }

    /**
     * Display error
     */
    function displayError(container, message) {
        if (!container) return;
        container.innerHTML = '<div class="tc-alert tc-alert-danger"><i class="fa fa-exclamation-circle"></i> ' + 
            escapeHtml(message) + '</div>';
    }

    /**
     * Clear results
     */
    window.clearResults = function() {
        var results = document.getElementById('toolResults');
        if (results) {
            results.style.display = 'none';
            document.getElementById('resultsContent').innerHTML = '';
        }
    };

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /** Escape a value for use inside a double-quoted HTML attribute. */
    function escapeAttr(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /**
     * Render tool form on page
     */
    window.renderToolForm = function(category, action) {
        var def = window.toolDefinitions && window.toolDefinitions[action];
        if (!def) {
            document.getElementById('toolForm').innerHTML = '<div class="tc-alert tc-alert-danger">Tool not found</div>';
            return;
        }

        document.getElementById('currentToolName').textContent = def.title;

        var formDiv = document.getElementById('toolForm');
        var form = document.createElement('form');
        form.onsubmit = function(e) {
            e.preventDefault();
            runPageTool(category, action, form);
        };

        var title = document.createElement('h3');
        title.innerHTML = '<i class="fa fa-cog"></i> ' + def.title;
        form.appendChild(title);

        // Form fields
        if (def.fields && def.fields.length > 0) {
            def.fields.forEach(function(field) {
                var group = document.createElement('div');
                group.className = 'tc-form-group';

                var label = document.createElement('label');
                label.textContent = field.label;
                if (field.required) label.className = 'tc-required';
                group.appendChild(label);

                var input;
                if (field.type === 'textarea') {
                    input = document.createElement('textarea');
                    input.rows = field.rows || 4;
                } else if (field.type === 'select') {
                    input = document.createElement('select');
                    if (field.options) {
                        field.options.forEach(function(opt) {
                            var option = document.createElement('option');
                            option.value = opt;
                            option.textContent = opt;
                            input.appendChild(option);
                        });
                    }
                } else {
                    input = document.createElement('input');
                    input.type = field.type || 'text';
                    if (field.min) input.min = field.min;
                    if (field.max) input.max = field.max;
                    if (field.step) input.step = field.step;
                }

                input.name = field.name;
                input.className = 'form-control';
                if (field.placeholder) input.placeholder = field.placeholder;
                if (field.accept) input.accept = field.accept;
                if (field.value) input.value = field.value;
                if (field.required) input.required = true;

                group.appendChild(input);
                form.appendChild(group);
            });
        }

        var actions = document.createElement('div');
        actions.className = 'tc-form-actions';

        var submitBtn = document.createElement('button');
        submitBtn.type = 'submit';
        submitBtn.className = 'btn btn-primary';
        submitBtn.innerHTML = '<i class="fa fa-play"></i> Run Tool';
        actions.appendChild(submitBtn);

        var clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'btn btn-default';
        clearBtn.textContent = 'Clear';
        clearBtn.onclick = function() { form.reset(); };
        actions.appendChild(clearBtn);

        form.appendChild(actions);
        formDiv.innerHTML = '';
        formDiv.appendChild(form);

        // Auto-run for no-field tools
        if (!def.fields || def.fields.length === 0) {
            runPageTool(category, action, form);
        }
    };

    /**
     * Run tool on page
     */
    function runPageTool(category, action, form) {
        var resultsDiv = document.getElementById('toolResults');
        var resultsContent = document.getElementById('resultsContent');
        var submitBtn = form.querySelector('button[type="submit"]');

        var localHandler = getLocalHandler(action);
        if (localHandler) {
            resultsDiv.style.display = 'block';
            resultsContent.innerHTML = '<div class="tc-loading"><div class="tc-spinner"></div> Processing...</div>';
            localHandler(form, function(response) {
                var tempDiv = document.createElement('div');
                displayResults(tempDiv, response);
                resultsContent.innerHTML = tempDiv.innerHTML;
            });
            return;
        }

        var params = {};
        var inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(function(input) {
            if (input.name) {
                params[input.name] = input.value;
            }
        });

        resultsDiv.style.display = 'block';
        resultsContent.innerHTML = '<div class="tc-loading"><div class="tc-spinner"></div> Processing...</div>';

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing...';
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', apiUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;

            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa fa-play"></i> Run Tool';
            }

            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    displayResults({innerHTML: '', style: {}}, response);
                    // Render into resultsContent
                    var tempDiv = document.createElement('div');
                    displayResults(tempDiv, response);
                    resultsContent.innerHTML = tempDiv.innerHTML;
                } catch (e) {
                    resultsContent.innerHTML = '<div class="tc-alert tc-alert-danger">Invalid response</div>';
                }
            } else {
                resultsContent.innerHTML = '<div class="tc-alert tc-alert-danger">Request failed</div>';
            }
        };

        var postData = 'category=' + encodeURIComponent(category) + '&action=' + encodeURIComponent(action);
        for (var key in params) {
            postData += '&params[' + encodeURIComponent(key) + ']=' + encodeURIComponent(params[key]);
        }

        xhr.send(postData);
    }

    // Close modal on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var modal = document.getElementById('toolModal');
            if (modal && modal.classList.contains('tc-active')) {
                closeToolModal();
            }
        }
    });

    // Close modal on background click
    document.addEventListener('click', function(e) {
        var modal = document.getElementById('toolModal');
        if (e.target === modal) {
            closeToolModal();
        }
    });

})();