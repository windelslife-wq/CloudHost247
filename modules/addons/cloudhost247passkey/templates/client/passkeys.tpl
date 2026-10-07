{* CloudHost247 Passkey — client management page. No credential secrets are rendered. *}
<div class="container-fluid ch247pk">
    <div class="row align-items-center mb-4">
        <div class="col-md-8">
            <h1 class="h2 mb-1"><i class="fas fa-key mr-2" aria-hidden="true"></i>Passkeys</h1>
            <p class="text-muted mb-0">Use your device&rsquo;s secure authentication to sign in without entering your password.</p>
        </div>
        <div class="col-md-4 text-right">
            {if $service_available && !$blocked_masquerade}
                <button class="btn btn-primary" id="ch247pk-add" type="button"><i class="fas fa-plus mr-1" aria-hidden="true"></i>Add Passkey</button>
            {/if}
        </div>
    </div>

    {if $notice}
        <div class="alert alert-success" role="alert">{$notice|escape}</div>
    {/if}
    {if $error}
        <div class="alert alert-danger" role="alert">{$error|escape}</div>
    {/if}

    {if $blocked_masquerade}
        <div class="alert alert-warning" role="alert">Passkey management is unavailable while an administrator is logged in as this client.</div>
    {elseif !$service_available}
        <div class="alert alert-info" role="alert">Passkey sign-in is not enabled for your account right now. Password sign-in continues to work.</div>
    {else}
        <div class="alert alert-info ch247pk-status" id="ch247pk-status" hidden></div>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h4">Registered devices</h2>
                <p class="text-muted small">Policy: <strong>{$policy|escape}</strong> &middot; Maximum Passkeys: <strong>{$max_credentials}</strong></p>
                {if !$credentials}
                    <p class="text-muted mb-0">No Passkeys registered yet. Add one to enable passwordless sign-in on this account.</p>
                {else}
                    <div class="row" id="ch247pk-list">
                    {foreach from=$credentials item=c}
                        <div class="col-12 col-lg-6 mb-3" data-credential="{$c.id}">
                            <article class="card h-100">
                                <div class="card-body">
                                    <h3 class="h5 mt-0 mb-1">{$c.device_name|escape}</h3>
                                    <p class="text-muted small mb-2">ID: <code>{$c.credential_id_display|escape}</code></p>
                                    <dl class="row small mb-0">
                                        <dt class="col-5 text-muted">Registered</dt><dd class="col-7 text-right">{$c.created_at|escape}</dd>
                                        <dt class="col-5 text-muted">Last used</dt><dd class="col-7 text-right">{if $c.last_used_at}{$c.last_used_at|escape}{else}Never{/if}</dd>
                                        <dt class="col-5 text-muted">Status</dt><dd class="col-7 text-right">{$c.status|escape}</dd>
                                    </dl>
                                </div>
                                <div class="card-footer bg-transparent">
                                    <button class="btn btn-sm btn-default" type="button" data-ch247pk-rename="{$c.id}">Rename</button>
                                    {if $c.status == 'disabled'}
                                        <button class="btn btn-sm btn-default" type="button" data-ch247pk-enable="{$c.id}">Enable</button>
                                    {elseif $c.status == 'active'}
                                        <button class="btn btn-sm btn-default" type="button" data-ch247pk-disable="{$c.id}">Disable</button>
                                    {/if}
                                    {if $c.status != 'revoked'}
                                        <button class="btn btn-sm btn-danger" type="button" data-ch247pk-revoke="{$c.id}">Remove</button>
                                    {/if}
                                </div>
                            </article>
                        </div>
                    {/foreach}
                    </div>
                {/if}
            </div>
        </div>

        <div class="row">
            <div class="col-12 col-lg-6 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h4">Login notifications</h2>
                        <form method="post" action="{$modulelink|escape}" id="ch247pk-prefs">
                            {$csrf_field nofilter}
                            <input type="hidden" name="ch247pk_action" value="preferences">
                            <div class="checkbox">
                                <label><input type="checkbox" name="login_notification_enabled" value="1" {if $preferences.login_notification_enabled}checked{/if}> Email me on Passkey sign-in</label>
                            </div>
                            <div class="checkbox">
                                <label><input type="checkbox" name="security_event_notification_enabled" value="1" {if $preferences.security_event_notification_enabled}checked{/if}> Email me on security events</label>
                            </div>
                            <button class="btn btn-default btn-sm" type="submit">Save preferences</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h4">Recent Passkey activity</h2>
                        {if !$events}
                            <p class="text-muted mb-0">No Passkey activity recorded yet.</p>
                        {else}
                            <ul class="list-unstyled mb-0">
                            {foreach from=$events item=e}
                                <li class="small mb-1">
                                    <span class="badge {if $e.success}badge-success{else}badge-danger{/if}">{if $e.success}OK{else}Failed{/if}</span>
                                    {$e.event_type|escape} <span class="text-muted">{$e.created_at|escape}</span>
                                </li>
                            {/foreach}
                            </ul>
                        {/if}
                    </div>
                </div>
            </div>
        </div>

        <script>
        window.CH247PK_PAGE = {
            ajaxUrl: {$ajax_url|json_encode},
            csrfToken: {$csrf_token|json_encode},
            csrfField: 'ch247pk_csrf'
        };
        </script>
    {/if}
</div>
