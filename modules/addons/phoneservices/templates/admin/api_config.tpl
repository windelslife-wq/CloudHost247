<div class="phoneservices-api-config">
    <div class="row">
        <div class="col-sm-12">
            <h2>API Configuration</h2>
            <p class="lead">Configure provider credentials and service toggles</p>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">Configuration saved successfully.</div>
    <?php endif; ?>

    <p class="help-block">
        Secrets are stored encrypted and are not echoed back into this form.
        Leave a secret field blank to keep its current value; enter a new value to replace it.
        Manage per-client REST API keys on the <a href="<?php echo htmlspecialchars($vars['modulelink'], ENT_QUOTES, 'UTF-8'); ?>&amp;action=api_keys">Client API Keys</a> screen.
    </p>

    <?php
    // Render a secret input: never pre-filled, with a "configured" hint instead.
    $psSecretField = function ($label, $name, $key) use ($config) {
        $configured = $config->get($key, '') !== '';
        echo '<div class="form-group">';
        echo '<label>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label>';
        echo '<input type="password" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" class="form-control" autocomplete="new-password">';
        echo '<small>' . ($configured ? 'Currently configured. Leave blank to keep, or enter a new value.' : 'Not configured.') . '</small>';
        echo '</div>';
    };
    ?>

    <form method="post" action="<?php echo $vars['modulelink']; ?>&action=api_config">
        <div class="panel panel-default">
            <div class="panel-heading">General Settings</div>
            <div class="panel-body">
                <div class="form-group">
                    <label>API Mode</label>
                    <select name="api_mode" class="form-control">
                        <option value="sandbox" <?php echo $config->get('api_mode') === 'sandbox' ? 'selected' : ''; ?>>Sandbox</option>
                        <option value="live" <?php echo $config->get('api_mode') === 'live' ? 'selected' : ''; ?>>Live</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Default Provider</label>
                    <select name="default_provider" class="form-control">
                        <?php foreach ($providers as $key => $name): ?>
                        <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $config->get('default_provider') === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">Twilio Credentials</div>
            <div class="panel-body">
                <?php $psSecretField('Account SID', 'twilio_account_sid', 'twilio_account_sid'); ?>
                <?php $psSecretField('Auth Token', 'twilio_auth_token', 'twilio_auth_token'); ?>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">Vonage Credentials</div>
            <div class="panel-body">
                <?php $psSecretField('API Key', 'vonage_api_key', 'vonage_api_key'); ?>
                <?php $psSecretField('API Secret', 'vonage_api_secret', 'vonage_api_secret'); ?>
                <?php $psSecretField('Signature Secret', 'vonage_signature_secret', 'vonage_signature_secret'); ?>
                <small>Vonage signature secret (Dashboard &gt; Settings). Webhooks are rejected until this is set.</small>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">eSIM Providers</div>
            <div class="panel-body">
                <?php $psSecretField('Airalo API Token', 'airalo_api_token', 'airalo_api_token'); ?>
                <?php $psSecretField('Truphone API Key', 'truphone_api_key', 'truphone_api_key'); ?>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">Messaging</div>
            <div class="panel-body">
                <?php $psSecretField('SendGrid API Key', 'sendgrid_api_key', 'sendgrid_api_key'); ?>
                <?php $psSecretField('WhatsApp Business Token', 'whatsapp_business_token', 'whatsapp_business_token'); ?>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading">Feature Toggles</div>
            <div class="panel-body">
                <div class="form-group">
                    <label class="checkbox-inline">
                        <input type="checkbox" name="enable_numbers" value="1" <?php echo $config->get('enable_numbers') === '1' ? 'checked' : ''; ?>> Enable Virtual Numbers
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-inline">
                        <input type="checkbox" name="enable_voip" value="1" <?php echo $config->get('enable_voip') === '1' ? 'checked' : ''; ?>> Enable VoIP
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-inline">
                        <input type="checkbox" name="enable_sms" value="1" <?php echo $config->get('enable_sms') === '1' ? 'checked' : ''; ?>> Enable SMS
                    </label>
                </div>
                <div class="form-group">
                    <label class="checkbox-inline">
                        <input type="checkbox" name="enable_esim" value="1" <?php echo $config->get('enable_esim') === '1' ? 'checked' : ''; ?>> Enable eSIM
                    </label>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Save Configuration</button>
    </form>
</div>
