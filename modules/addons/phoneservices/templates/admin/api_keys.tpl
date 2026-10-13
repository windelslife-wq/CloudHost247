<div class="phoneservices-api-keys">
    <div class="row">
        <div class="col-sm-12">
            <h2>Client API Keys</h2>
            <p class="lead">
                Issue per-client keys for the REST API (<code>Authorization: ApiKey psk_...</code>).
                Each key scopes requests to exactly one client &mdash; a key can only list,
                suspend or release numbers belonging to its own client.
                The legacy shared all-tenant key is no longer accepted.
            </p>
        </div>
    </div>

    <?php if (!empty($flashSuccess)): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars((string) $flashSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if (!empty($flashError)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars((string) $flashError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if (!empty($newKey)): ?>
    <div class="alert alert-warning">
        <strong>API key for <?php echo htmlspecialchars(\PhoneServices\Core\ApiKeyStore::clientLabel((int) $newKey['client']), ENT_QUOTES, 'UTF-8'); ?>:</strong><br>
        <code style="font-size: 14px;"><?php echo htmlspecialchars((string) $newKey['key'], ENT_QUOTES, 'UTF-8'); ?></code><br>
        <small>Copy it now &mdash; it is shown only once and stored as a hash.</small>
    </div>
    <?php endif; ?>

    <form method="get" action="<?php echo htmlspecialchars($vars['modulelink'], ENT_QUOTES, 'UTF-8'); ?>&amp;action=api_keys" class="form-inline" style="margin-bottom: 15px;">
        <div class="form-group">
            <label for="ps_clientid">Client</label>
            <select name="clientid" id="ps_clientid" class="form-control">
                <option value="0">&mdash; Select a client &mdash;</option>
                <?php foreach ($clients as $client): ?>
                <option value="<?php echo (int) $client['id']; ?>" <?php echo $clientid === (int) $client['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars(trim($client['firstname'] . ' ' . $client['lastname']) . ' (' . $client['email'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-default">Show keys</button>
    </form>

    <?php if ($clientid > 0): ?>

    <div class="panel panel-default">
        <div class="panel-heading">Keys for <?php echo htmlspecialchars($clientLabel, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="panel-body">
            <?php if (empty($keys)): ?>
            <p>No API keys have been issued for this client yet.</p>
            <?php else: ?>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Key</th>
                        <th>Name</th>
                        <th>Created</th>
                        <th>Last used</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($keys as $keyRow): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($keyRow['key_prefix'], ENT_QUOTES, 'UTF-8'); ?>&hellip;</code></td>
                        <td><?php echo htmlspecialchars($keyRow['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($keyRow['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) $keyRow['last_used_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $keyRow['revoked'] ? '<span class="label label-default">Revoked</span>' : '<span class="label label-success">Active</span>'; ?></td>
                        <td>
                            <?php if (!$keyRow['revoked']): ?>
                            <form method="post" action="<?php echo htmlspecialchars($vars['modulelink'], ENT_QUOTES, 'UTF-8'); ?>&amp;action=api_keys" style="display: inline;">
                                <input type="hidden" name="key_action" value="revoke">
                                <input type="hidden" name="clientid" value="<?php echo (int) $clientid; ?>">
                                <input type="hidden" name="key_id" value="<?php echo (int) $keyRow['id']; ?>">
                                <button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Revoke this API key? Clients using it will be denied immediately.');">Revoke</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <hr>
            <form method="post" action="<?php echo htmlspecialchars($vars['modulelink'], ENT_QUOTES, 'UTF-8'); ?>&amp;action=api_keys">
                <input type="hidden" name="key_action" value="create">
                <input type="hidden" name="clientid" value="<?php echo (int) $clientid; ?>">
                <div class="form-group" style="display: inline-block; margin-right: 10px;">
                    <label for="ps_key_name">Label (optional)</label>
                    <input type="text" name="name" id="ps_key_name" class="form-control" maxlength="100" placeholder="e.g. Integration 1">
                </div>
                <button type="submit" class="btn btn-primary">Issue new key</button>
            </form>
        </div>
    </div>

    <?php else: ?>
    <p>Select a client to view or issue API keys.</p>
    <?php endif; ?>
</div>
