<?php
/**
 * WHMCS admin surface for linked cPanel accounts and read-only UAPI inventories.
 *
 * This page can queue domain, built-in alias, quota, or bandwidth-usage reads. It never creates or mutates accounts.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Http;

use Ch247Apps\ControlPanels\PanelAccountService;
use Ch247Apps\Core\AppsException;
use Ch247Apps\Core\Csrf;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Identity;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Deployments\JobQueue;

class PanelAccountAdmin
{
    private $actor;
    private $accounts;
    private $queue;
    private $baseUrl;

    public function __construct(array $vars = [])
    {
        $this->actor = Identity::current();
        $this->accounts = new PanelAccountService($this->actor);
        $this->queue = new JobQueue();
        $this->baseUrl = isset($vars['modulelink']) && is_scalar($vars['modulelink'])
            ? (string) $vars['modulelink'] : 'addonmodules.php?module=cloudhost247apps';
    }

    public function render()
    {
        try {
            Rbac::assert($this->actor, Rbac::PANEL_ACCOUNT_VIEW);
        } catch (\Throwable $e) {
            echo '<div class="alert alert-danger">You do not have permission to view linked control-panel accounts.</div>';
            return;
        }

        $notice = $this->handlePost();
        try {
            $accounts = array_values(array_filter($this->accounts->listing(), function ($account) {
                return isset($account['panel_key']) && (string) $account['panel_key'] === PanelAccountService::PANEL_KEY;
            }));
        } catch (\Throwable $e) {
            Logger::warning('Panel-account admin page could not load.', [
                'exception' => get_class($e), 'source' => 'panel_account_admin',
            ]);
            echo '<div class="alert alert-danger">Linked control-panel accounts are not available. Confirm that App Cloud migrations completed.</div>';
            return;
        }

        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Linked cPanel accounts</strong></div><div class="panel-body">';
        echo '<div class="alert alert-info"><strong>Read-only, queued cPanel snapshots.</strong> Staff may queue domain, cPanel-reported built-in alias, disk/inode quota, or the fixed bandwidth-usage read. Snapshots are one-time vendor-reported responses, not continuous monitoring. Quota zero values are not interpreted as a quota state; bandwidth status flags are shown only as cPanel reports them. This page cannot create, suspend, resume, or terminate an account.</div>';
        if ($notice !== '') {
            echo $notice;
        }
        echo '<p><a class="btn btn-default btn-sm" href="' . $this->e($this->baseUrl) . '">App Cloud overview</a></p>';
        if (!Settings::bool('cpanel_uapi_domains_enabled', false)) {
            echo '<div class="alert alert-warning">cPanel UAPI domain inventory is disabled. Keep it off until the dedicated cPanel staging and WHM token ACL review pass.</div>';
        }
        if (!Settings::bool('cpanel_uapi_aliases_enabled', false)) {
            echo '<div class="alert alert-warning">cPanel UAPI built-in alias inventory is disabled. Keep it off until the separate UAPI permission, response, TLS and redaction checks pass in dedicated staging.</div>';
        }
        if (!Settings::bool('cpanel_uapi_quota_usage_enabled', false)) {
            echo '<div class="alert alert-warning">cPanel UAPI quota-usage snapshots are disabled. Keep them off until the Quota::get_quota_info token permission, response normalization, TLS and audit behavior pass dedicated staging.</div>';
        }
        if (!Settings::bool('cpanel_uapi_bandwidth_usage_enabled', false)) {
            echo '<div class="alert alert-warning">cPanel bandwidth-usage snapshots are disabled. Keep them off until the fixed StatsBar::get_stats bandwidthusage permission, response projection, TLS and audit behavior pass dedicated staging.</div>';
        }
        $this->renderJobResult();
        $this->renderAccounts($accounts);
        $this->renderSnapshotOverview($accounts);
        $this->renderSnapshotHistory($accounts);
        echo '</div></div>';
    }

    private function handlePost()
    {
        if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET') !== 'POST'
            || !isset($_POST['panel_account_action'])) {
            return '';
        }

        try {
            Csrf::verify(isset($_POST['ch247_token']) && is_string($_POST['ch247_token']) ? $_POST['ch247_token'] : null);
            $operation = is_string($_POST['panel_account_action']) ? $_POST['panel_account_action'] : '';
            if (!in_array($operation, ['domains_list', 'aliases_list', 'quota_usage', 'bandwidth_usage'], true)) {
                throw new ValidationException('Unsupported panel-account operation.');
            }
            $accountId = self::positiveId(isset($_POST['panel_account_id']) ? $_POST['panel_account_id'] : null);
            $idempotencyKey = isset($_POST['idempotency_key']) && is_string($_POST['idempotency_key'])
                ? $_POST['idempotency_key'] : '';
            if ($accountId <= 0 || !preg_match('/^[a-f0-9-]{36}$/i', $idempotencyKey)) {
                throw new ValidationException('The linked account or request key is invalid.');
            }

            if ($operation === 'quota_usage') {
                $result = $this->accounts->requestQuotaUsage($accountId, $idempotencyKey);
                $label = 'quota-usage snapshot';
            } elseif ($operation === 'bandwidth_usage') {
                $result = $this->accounts->requestBandwidthUsage($accountId, $idempotencyKey);
                $label = 'bandwidth-usage snapshot';
            } elseif ($operation === 'aliases_list') {
                $result = $this->accounts->requestDomainAliases($accountId, $idempotencyKey);
                $label = 'built-in alias inventory';
            } else {
                $result = $this->accounts->requestDomainInventory($accountId, $idempotencyKey);
                $label = 'domain inventory';
            }
            $jobId = isset($result['job']['id']) ? (int) $result['job']['id'] : 0;
            if ($jobId <= 0) {
                throw new ValidationException('The read-only cPanel request did not return a queued job.');
            }
            $url = $this->pageUrl(['account_id' => $accountId, 'job_id' => $jobId]);
            return '<div class="alert alert-success">The read-only ' . $this->e($label) . ' was queued. '
                . '<a href="' . $this->e($url) . '">View job status/result</a>.</div>';
        } catch (AppsException $e) {
            return '<div class="alert alert-danger">' . $this->e($e->getMessage()) . '</div>';
        } catch (\Throwable $e) {
            Logger::error('Panel-account read-only inventory request failed in admin UI.', [
                'exception' => get_class($e), 'source' => 'panel_account_admin',
            ]);
            return '<div class="alert alert-danger">The read-only cPanel inventory could not be queued. Check the WHMCS activity log.</div>';
        }
    }

    private function renderAccounts(array $accounts)
    {
        $domainsEnabled = Settings::bool('cpanel_uapi_domains_enabled', false);
        $aliasesEnabled = Settings::bool('cpanel_uapi_aliases_enabled', false);
        $quotaUsageEnabled = Settings::bool('cpanel_uapi_quota_usage_enabled', false);
        $bandwidthUsageEnabled = Settings::bool('cpanel_uapi_bandwidth_usage_enabled', false);
        echo '<h3>Accounts</h3><div class="table-responsive"><table class="table table-striped">'
            . '<thead><tr><th>Account</th><th>WHMCS binding</th><th>State</th><th>Last verified</th><th>Request</th></tr></thead><tbody>';
        if (!$accounts) {
            echo '<tr><td colspan="5">No linked cPanel accounts are available.</td></tr>';
        }
        foreach ($accounts as $account) {
            $username = (string) $account['username'];
            $domain = (string) $account['domain'];
            $status = (string) $account['status'];
            $pendingAction = isset($account['pending_action']) ? (string) $account['pending_action'] : '';
            $pendingJobId = !empty($account['pending_job_id']) ? (int) $account['pending_job_id'] : 0;
            echo '<tr><td><strong>' . $this->e($username) . '</strong><br>' . $this->e($domain)
                . '<br><small>Package: ' . $this->e($account['package']) . ' &middot; Server #' . (int) $account['server_id']
                . '</small></td><td>Client #' . (int) $account['client_id'] . '<br>Service #'
                . (int) $account['whmcs_service_id'] . '</td><td>' . $this->e($status);
            if ($pendingAction !== '') {
                echo '<br><small>Pending: ' . $this->e($pendingAction)
                    . ($pendingJobId > 0 ? ' (job #' . $pendingJobId . ')' : '') . '</small>';
            }
            if (!empty($account['last_error_code'])) {
                echo '<br><small class="text-danger">Last error: ' . $this->e($account['last_error_code']) . '</small>';
            }
            echo '</td><td>' . $this->e(isset($account['last_verified_at']) ? $account['last_verified_at'] : 'Never') . '</td><td>';

            $canRequest = $pendingAction === '' && $pendingJobId <= 0
                && in_array($status, [PanelAccountService::STATUS_ACTIVE, PanelAccountService::STATUS_SUSPENDED], true);
            if ($canRequest && $domainsEnabled) {
                $this->renderQueueForm((int) $account['id'], 'domains_list', 'Queue domain inventory');
            }
            if ($canRequest && $aliasesEnabled) {
                $this->renderQueueForm((int) $account['id'], 'aliases_list', 'Queue built-in alias read');
            }
            if ($canRequest && $quotaUsageEnabled) {
                $this->renderQueueForm((int) $account['id'], 'quota_usage', 'Queue quota-usage snapshot');
            }
            if ($canRequest && $bandwidthUsageEnabled) {
                $this->renderQueueForm((int) $account['id'], 'bandwidth_usage', 'Queue bandwidth snapshot');
            }
            if (!$domainsEnabled && !$aliasesEnabled && !$quotaUsageEnabled && !$bandwidthUsageEnabled) {
                echo '<span class="text-muted">Features disabled</span>';
            } elseif (!$canRequest && ($pendingAction !== '' || $pendingJobId > 0)) {
                echo '<span class="text-muted">Another action is pending</span>';
            } elseif (!$canRequest) {
                echo '<span class="text-muted">Verify an active or suspended account first</span>';
            } elseif (!$domainsEnabled || !$aliasesEnabled || !$quotaUsageEnabled || !$bandwidthUsageEnabled) {
                echo '<br><span class="text-muted">One or more read-only features are disabled</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Summarize the latest retained, validated snapshots without polling cPanel. */
    private function renderSnapshotOverview(array $accounts)
    {
        $accountMap = [];
        foreach ($accounts as $account) {
            $accountMap[(int) $account['id']] = $account;
        }
        if (!$accountMap) {
            return;
        }

        $jobTypes = [JobQueue::TYPE_PANEL_ACCOUNT_QUOTA_USAGE, JobQueue::TYPE_PANEL_ACCOUNT_BANDWIDTH_USAGE];
        try {
            $rows = Db::fetch('jobs', [
                'queue' => JobQueue::QUEUE_CONTROL_PANEL,
                'panel_account_id' => ['in', array_keys($accountMap)],
                'job_type' => ['in', $jobTypes],
                'status' => JobQueue::STATUS_COMPLETED,
            ], [
                'columns' => ['id', 'queue', 'panel_account_id', 'client_id', 'whmcs_service_id', 'job_type',
                    'created_at', 'status', 'result'],
                'order' => 'id', 'dir' => 'desc', 'limit' => 100,
            ]);
        } catch (\Throwable $e) {
            Logger::warning('Panel-account snapshot overview could not be loaded.', [
                'exception' => get_class($e), 'source' => 'panel_account_admin',
            ]);
            return;
        }

        $overview = [];
        foreach ($rows as $row) {
            $accountId = isset($row['panel_account_id']) ? (int) $row['panel_account_id'] : 0;
            if (!isset($accountMap[$accountId])
                || (string) $row['queue'] !== JobQueue::QUEUE_CONTROL_PANEL
                || (string) $row['status'] !== JobQueue::STATUS_COMPLETED
                || (int) $row['client_id'] !== (int) $accountMap[$accountId]['client_id']
                || (int) $row['whmcs_service_id'] !== (int) $accountMap[$accountId]['whmcs_service_id']) {
                continue;
            }
            $jobType = (string) $row['job_type'];
            if ($jobType === JobQueue::TYPE_PANEL_ACCOUNT_QUOTA_USAGE) {
                $snapshotKey = 'quota';
            } elseif ($jobType === JobQueue::TYPE_PANEL_ACCOUNT_BANDWIDTH_USAGE) {
                $snapshotKey = 'bandwidth';
            } else {
                continue;
            }
            if (!isset($overview[$accountId])) {
                if (count($overview) >= 50) {
                    continue;
                }
                $overview[$accountId] = [
                    'account' => $accountMap[$accountId],
                    'quota' => null,
                    'bandwidth' => null,
                ];
            }
            if ($overview[$accountId][$snapshotKey] !== null) {
                continue;
            }

            $result = Str::jsonDecode(isset($row['result']) ? $row['result'] : null, []);
            $snapshot = $snapshotKey === 'quota'
                ? $this->validatedQuotaUsage($result, $accountMap[$accountId])
                : $this->validatedBandwidthUsage($result, $accountMap[$accountId]);
            $overview[$accountId][$snapshotKey] = [
                'job_id' => (int) $row['id'],
                'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : '',
                'snapshot' => $snapshot,
            ];
        }

        echo '<h3>Latest retained usage snapshots</h3>'
            . '<p class="text-muted">Shows up to 50 accounts from the latest 100 completed retained snapshot jobs. Each value is a point-in-time response reported by cPanel, not a live reading or continuous monitoring. A result is shown only after the existing account-scope and schema validation passes.</p>';
        if (!$overview) {
            echo '<p class="text-muted">No completed quota or bandwidth snapshots appear in the bounded recent-results scan.</p>';
            return;
        }

        echo '<div class="table-responsive"><table class="table table-condensed"><thead><tr>'
            . '<th>Account</th><th>Latest retained quota snapshot</th><th>Latest retained bandwidth snapshot</th>'
            . '</tr></thead><tbody>';
        foreach ($overview as $entry) {
            $account = $entry['account'];
            $accountId = (int) $account['id'];
            echo '<tr><td><strong>' . $this->e($account['username']) . '</strong><br>'
                . $this->e($account['domain']) . '</td><td>'
                . $this->renderSnapshotOverviewCell($entry['quota'], $accountId) . '</td><td>'
                . $this->renderSnapshotOverviewCell($entry['bandwidth'], $accountId) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Render only validator-projected values; invalid results remain unavailable. */
    private function renderSnapshotOverviewCell($entry, $accountId)
    {
        if (!is_array($entry)) {
            return '<span class="text-muted">No snapshot in the bounded recent-results scan.</span>';
        }
        $snapshot = isset($entry['snapshot']) && is_array($entry['snapshot']) ? $entry['snapshot'] : null;
        $jobId = isset($entry['job_id']) ? (int) $entry['job_id'] : 0;
        $url = $this->pageUrl(['account_id' => (int) $accountId, 'job_id' => $jobId]);
        if ($snapshot === null) {
            return '<small>Snapshot #' . $jobId . ' &middot; '
                . $this->e(isset($entry['created_at']) ? $entry['created_at'] : '')
                . '</small><br><span class="text-warning">Result unavailable: it did not pass snapshot validation.</span>';
        }

        $html = '<small>Snapshot #' . $jobId . ' &middot; '
            . $this->e(isset($entry['created_at']) ? $entry['created_at'] : '')
            . ' &middot; cPanel-reported</small><ul class="list-unstyled">';
        foreach ($snapshot['display_fields'] as $label => $value) {
            $html .= '<li>' . $this->e($label) . ': <code>' . $this->e($value) . '</code></li>';
        }
        $html .= '</ul><a href="' . $this->e($url) . '">View validated snapshot</a>';
        return $html;
    }

    /** Show a bounded list of retained quota/bandwidth jobs without exposing unvalidated results. */
    private function renderSnapshotHistory(array $accounts)
    {
        $accountMap = [];
        foreach ($accounts as $account) {
            $accountMap[(int) $account['id']] = $account;
        }
        if (!$accountMap) {
            return;
        }

        $jobTypes = [JobQueue::TYPE_PANEL_ACCOUNT_QUOTA_USAGE, JobQueue::TYPE_PANEL_ACCOUNT_BANDWIDTH_USAGE];
        try {
            $rows = Db::fetch('jobs', [
                'queue' => JobQueue::QUEUE_CONTROL_PANEL,
                'panel_account_id' => ['in', array_keys($accountMap)],
                'job_type' => ['in', $jobTypes],
            ], [
                'columns' => ['id', 'queue', 'panel_account_id', 'client_id', 'whmcs_service_id', 'job_type',
                    'created_at', 'status'],
                'order' => 'id', 'dir' => 'desc', 'limit' => 50,
            ]);
        } catch (\Throwable $e) {
            Logger::warning('Panel-account snapshot history could not be loaded.', [
                'exception' => get_class($e), 'source' => 'panel_account_admin',
            ]);
            return;
        }

        echo '<h3>Recent usage snapshot jobs</h3>'
            . '<p class="text-muted">Shows up to 50 retained quota and bandwidth jobs. This is an on-demand history of individual snapshots, not continuous monitoring; normal job retention applies.</p>'
            . '<div class="table-responsive"><table class="table table-condensed"><thead><tr>'
            . '<th>Account</th><th>Snapshot type</th><th>Requested</th><th>Job status</th><th>Result</th>'
            . '</tr></thead><tbody>';
        $shown = 0;
        foreach ($rows as $row) {
            $accountId = isset($row['panel_account_id']) ? (int) $row['panel_account_id'] : 0;
            if (!isset($accountMap[$accountId])
                || (string) $row['queue'] !== JobQueue::QUEUE_CONTROL_PANEL
                || (int) $row['client_id'] !== (int) $accountMap[$accountId]['client_id']
                || (int) $row['whmcs_service_id'] !== (int) $accountMap[$accountId]['whmcs_service_id']) {
                continue;
            }
            $isBandwidth = (string) $row['job_type'] === JobQueue::TYPE_PANEL_ACCOUNT_BANDWIDTH_USAGE;
            $isQuota = (string) $row['job_type'] === JobQueue::TYPE_PANEL_ACCOUNT_QUOTA_USAGE;
            if (!$isBandwidth && !$isQuota) {
                continue;
            }
            $jobId = (int) $row['id'];
            $url = $this->pageUrl(['account_id' => $accountId, 'job_id' => $jobId]);
            $shown++;
            echo '<tr><td><strong>' . $this->e($accountMap[$accountId]['username']) . '</strong><br>'
                . $this->e($accountMap[$accountId]['domain']) . '</td><td>'
                . ($isBandwidth ? 'Bandwidth usage' : 'Disk/inode quota') . '</td><td>'
                . $this->e(isset($row['created_at']) ? $row['created_at'] : '') . '</td><td>'
                . $this->e(isset($row['status']) ? $row['status'] : 'unknown') . '</td><td><a href="'
                . $this->e($url) . '">' . ((string) $row['status'] === JobQueue::STATUS_COMPLETED
                    ? 'View snapshot' : 'View job') . '</a></td></tr>';
        }
        if ($shown === 0) {
            echo '<tr><td colspan="5">No retained quota or bandwidth snapshot jobs are available.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function renderQueueForm($accountId, $action, $label)
    {
        echo '<form method="post" action="' . $this->e($this->pageUrl()) . '" class="form-inline" style="display:inline-block;margin:0 4px 4px 0">'
            . Csrf::field()
            . '<input type="hidden" name="panel_account_action" value="' . $this->e($action) . '">'
            . '<input type="hidden" name="panel_account_id" value="' . (int) $accountId . '">'
            . '<input type="hidden" name="idempotency_key" value="' . $this->e(Str::uuid4()) . '">'
            . '<button class="btn btn-sm btn-primary" type="submit">' . $this->e($label) . '</button></form>';
    }

    private function renderJobResult()
    {
        $jobId = self::positiveId(isset($_GET['job_id']) ? $_GET['job_id'] : null);
        $accountId = self::positiveId(isset($_GET['account_id']) ? $_GET['account_id'] : null);
        if ($jobId <= 0 && $accountId <= 0) {
            return;
        }
        if ($jobId <= 0 || $accountId <= 0) {
            echo '<div class="alert alert-danger">The requested cPanel inventory job is unavailable.</div>';
            return;
        }

        try {
            $account = $this->accounts->get($accountId);
            $row = $this->queue->find($jobId);
        } catch (\Throwable $e) {
            echo '<div class="alert alert-danger">The requested cPanel inventory job is unavailable.</div>';
            return;
        }
        $jobType = $row && isset($row['job_type']) ? (string) $row['job_type'] : '';
        $isDomains = $jobType === JobQueue::TYPE_PANEL_ACCOUNT_DOMAINS;
        $isAliases = $jobType === JobQueue::TYPE_PANEL_ACCOUNT_ALIASES;
        $isQuotaUsage = $jobType === JobQueue::TYPE_PANEL_ACCOUNT_QUOTA_USAGE;
        $isBandwidthUsage = $jobType === JobQueue::TYPE_PANEL_ACCOUNT_BANDWIDTH_USAGE;
        if ((string) $account['panel_key'] !== PanelAccountService::PANEL_KEY
            || !$row || (!$isDomains && !$isAliases && !$isQuotaUsage && !$isBandwidthUsage)
            || (string) $row['queue'] !== JobQueue::QUEUE_CONTROL_PANEL
            || (int) $row['panel_account_id'] !== $accountId
            || (int) $row['client_id'] !== (int) $account['client_id']
            || (int) $row['whmcs_service_id'] !== (int) $account['whmcs_service_id']) {
            echo '<div class="alert alert-danger">The requested cPanel inventory job is unavailable.</div>';
            return;
        }

        $job = $this->queue->present($row);
        $jobTitle = $isBandwidthUsage ? 'Bandwidth-usage snapshot job'
            : ($isQuotaUsage ? 'Quota-usage snapshot job'
                : ($isAliases ? 'Built-in alias inventory job' : 'Domain inventory job'));
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>' . $this->e($jobTitle) . ' #'
            . (int) $jobId . '</strong></div><div class="panel-body">'
            . '<p>cPanel account <strong>' . $this->e($account['username']) . '</strong> &middot; WHMCS service #'
            . (int) $account['whmcs_service_id'] . ' &middot; Job state: <strong>' . $this->e($job['status']) . '</strong></p>';

        if ((string) $job['status'] === JobQueue::STATUS_COMPLETED) {
            if ($isQuotaUsage) {
                $snapshot = $this->validatedQuotaUsage($job['result'], $account);
                if ($snapshot === null) {
                    Logger::warning('Panel-account quota job result failed admin presentation validation.', [
                        'job_id' => (int) $jobId, 'panel_account_id' => (int) $accountId,
                        'source' => 'panel_account_admin',
                    ]);
                    echo '<div class="alert alert-danger">The completed job did not contain a verifiable cPanel quota snapshot.</div>';
                } else {
                    echo '<div class="alert alert-warning">Snapshot reported by cPanel UAPI; values are not independently measured or continuously monitored. Zero limits or remaining values may represent unlimited or disabled quotas; zero used values may mean no usage or disabled quotas. No quota state is inferred from zero.</div>'
                        . '<p>Primary domain: <strong>' . $this->e($snapshot['main_domain']) . '</strong>'
                        . ' &middot; ' . (int) $snapshot['field_count'] . ' field(s) &middot; completeness: <code>vendor_reported</code></p>'
                        . '<div class="table-responsive"><table class="table table-condensed"><thead><tr><th>Quota field</th><th>cPanel-reported value</th></tr></thead><tbody>';
                    foreach ($snapshot['display_fields'] as $label => $value) {
                        echo '<tr><td>' . $this->e($label) . '</td><td><code>' . $this->e($value) . '</code></td></tr>';
                    }
                    echo '</tbody></table></div>';
                }
            } elseif ($isBandwidthUsage) {
                $snapshot = $this->validatedBandwidthUsage($job['result'], $account);
                if ($snapshot === null) {
                    Logger::warning('Panel-account bandwidth job result failed admin presentation validation.', [
                        'job_id' => (int) $jobId, 'panel_account_id' => (int) $accountId,
                        'source' => 'panel_account_admin',
                    ]);
                    echo '<div class="alert alert-danger">The completed job did not contain a verifiable cPanel bandwidth snapshot.</div>';
                } else {
                    echo '<div class="alert alert-warning">One-time cPanel UAPI StatsBar response; not independently measured and not continuous monitoring. Values and flags below are shown as reported; no additional usage or limit semantics are inferred.</div>'
                        . '<p>Primary domain: <strong>' . $this->e($snapshot['main_domain']) . '</strong>'
                        . ' &middot; ' . (int) $snapshot['field_count'] . ' field(s) &middot; completeness: <code>vendor_reported</code></p>'
                        . '<div class="table-responsive"><table class="table table-condensed"><thead><tr><th>Bandwidth field</th><th>cPanel-reported value</th></tr></thead><tbody>';
                    foreach ($snapshot['display_fields'] as $label => $value) {
                        echo '<tr><td>' . $this->e($label) . '</td><td><code>' . $this->e($value) . '</code></td></tr>';
                    }
                    echo '</tbody></table></div>';
                }
            } elseif ($isAliases) {
                $inventory = $this->validatedAliases($job['result'], $account);
                if ($inventory === null) {
                    Logger::warning('Panel-account built-in alias job result failed admin presentation validation.', [
                        'job_id' => (int) $jobId, 'panel_account_id' => (int) $accountId,
                        'source' => 'panel_account_admin',
                    ]);
                    echo '<div class="alert alert-danger">The completed job did not contain verifiable cPanel-reported built-in aliases.</div>';
                } else {
                    echo '<div class="alert alert-info">These are only built-in alias values reported by cPanel; they are not a complete DNS or domain-ownership inventory.</div>'
                        . '<p>Primary domain: <strong>' . $this->e($inventory['main_domain']) . '</strong>'
                        . ' &middot; ' . (int) $inventory['alias_count'] . ' reported alias value(s)'
                        . ' &middot; completeness: <code>vendor_reported</code></p>'
                        . '<div class="table-responsive"><table class="table table-condensed"><thead><tr><th>Alias</th><th>Primary-domain-scoped name</th></tr></thead><tbody>';
                    foreach ($inventory['aliases'] as $alias) {
                        echo '<tr><td>' . $this->e($alias['alias']) . '</td><td>' . $this->e($alias['domain']) . '</td></tr>';
                    }
                    echo '</tbody></table></div>';
                }
            } else {
                $inventory = $this->validatedInventory($job['result'], $account);
                if ($inventory === null) {
                    Logger::warning('Panel-account domain job result failed admin presentation validation.', [
                        'job_id' => (int) $jobId, 'panel_account_id' => (int) $accountId,
                        'source' => 'panel_account_admin',
                    ]);
                    echo '<div class="alert alert-danger">The completed job did not contain a verifiable domain inventory.</div>';
                } else {
                    echo '<p>Primary domain: <strong>' . $this->e($inventory['main_domain']) . '</strong>'
                        . ' &middot; ' . (int) $inventory['domain_count'] . ' domain(s) &middot; temporary domains excluded</p>'
                        . '<div class="table-responsive"><table class="table table-condensed"><thead><tr><th>Domain</th><th>Type</th></tr></thead><tbody>';
                    foreach ($inventory['domains'] as $domain) {
                        echo '<tr><td>' . $this->e($domain['domain']) . '</td><td>' . $this->e($domain['type']) . '</td></tr>';
                    }
                    echo '</tbody></table></div>';
                }
            }
        } elseif (in_array((string) $job['status'], [JobQueue::STATUS_FAILED, JobQueue::STATUS_DEAD,
            JobQueue::STATUS_CANCELLED], true)) {
            echo '<div class="alert alert-warning">The read-only cPanel inventory did not complete.'
                . (!empty($job['error_code']) ? ' Error code: <code>' . $this->e($job['error_code']) . '</code>.' : '')
                . ' No inventory result is available.</div>';
        } elseif (in_array((string) $job['status'], JobQueue::LIVE, true)) {
            echo '<div class="alert alert-info">The inventory is still queued or running. Refresh this page after the control-panel worker processes it.</div>';
        } else {
            echo '<div class="alert alert-warning">This job is not in a displayable state. No result was exposed.</div>';
        }
        echo '</div></div>';
    }

    private function validatedInventory($result, array $account)
    {
        if (!is_array($result) || empty($result['confirmed'])
            || (int) (isset($result['panel_account_id']) ? $result['panel_account_id'] : 0) !== (int) $account['id']
            || !isset($result['main_domain'], $result['domain_count'], $result['domains'])
            || !is_string($result['main_domain']) || !is_array($result['domains'])
            || !in_array((string) (isset($result['status']) ? $result['status'] : ''),
                [PanelAccountService::STATUS_ACTIVE, PanelAccountService::STATUS_SUSPENDED], true)
            || empty($result['temporary_domains_excluded'])) {
            return null;
        }

        $mainDomain = strtolower(trim($result['main_domain']));
        $expectedDomain = strtolower(trim((string) $account['domain']));
        if ($mainDomain === '' || $mainDomain !== $expectedDomain || count($result['domains']) > 1000
            || (int) $result['domain_count'] !== count($result['domains'])
            || filter_var($mainDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        $domains = [];
        $seen = [];
        $hasMain = false;
        $types = ['main', 'addon', 'parked', 'sub'];
        foreach ($result['domains'] as $item) {
            if (!is_array($item) || !isset($item['domain'], $item['type'])
                || !is_string($item['domain']) || !is_string($item['type'])
                || !in_array($item['type'], $types, true)) {
                return null;
            }
            $domain = strtolower(trim($item['domain']));
            if ($domain === '' || strlen($domain) > 253 || isset($seen[$domain])
                || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                return null;
            }
            if ($domain === $mainDomain && $item['type'] === 'main') {
                $hasMain = true;
            }
            $seen[$domain] = true;
            $domains[] = ['domain' => $domain, 'type' => $item['type']];
        }
        if (!$hasMain) {
            return null;
        }
        return [
            'main_domain' => $mainDomain,
            'domain_count' => count($domains),
            'domains' => $domains,
        ];
    }

    private function validatedQuotaUsage($result, array $account)
    {
        if (!is_array($result) || empty($result['confirmed'])
            || (int) (isset($result['panel_account_id']) ? $result['panel_account_id'] : 0) !== (int) $account['id']
            || !isset($result['username'], $result['main_domain'], $result['usage'], $result['fields_reported'],
                $result['field_count'], $result['completeness'])
            || !is_string($result['username']) || !is_string($result['main_domain'])
            || !is_array($result['usage']) || !is_array($result['fields_reported'])
            || $result['completeness'] !== 'vendor_reported'
            || !in_array((string) (isset($result['status']) ? $result['status'] : ''),
                [PanelAccountService::STATUS_ACTIVE, PanelAccountService::STATUS_SUSPENDED], true)) {
            return null;
        }

        $username = strtolower(trim($result['username']));
        $mainDomain = strtolower(trim($result['main_domain']));
        $expectedDomain = strtolower(trim((string) $account['domain']));
        $expectedUsername = strtolower(trim((string) $account['username']));
        $requiredFields = [
            'megabyte_limit', 'megabytes_remain', 'megabytes_used',
            'inode_limit', 'inodes_remain', 'inodes_used',
        ];
        $optionalFields = ['under_inode_limit', 'under_megabyte_limit', 'under_quota_overall'];
        if ($username !== $expectedUsername || $mainDomain === '' || $mainDomain !== $expectedDomain
            || filter_var($mainDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || count($result['usage']) > 9 || !is_int($result['field_count'])
            || $result['field_count'] !== count($result['usage'])
            || array_diff($requiredFields, array_keys($result['usage']))
            || array_diff(array_keys($result['usage']), array_merge($requiredFields, $optionalFields))
            || count($result['usage']) !== count($result['fields_reported'])) {
            return null;
        }

        $reported = [];
        foreach ($result['fields_reported'] as $field) {
            if (!is_string($field) || isset($reported[$field])) {
                return null;
            }
            $reported[$field] = true;
        }
        foreach (array_keys($result['usage']) as $field) {
            if (!isset($reported[$field])) {
                return null;
            }
        }

        $labels = [
            'megabytes_used' => 'Disk used (MB)',
            'megabyte_limit' => 'Disk quota (MB)',
            'megabytes_remain' => 'Disk remaining (MB)',
            'inodes_used' => 'Inodes used',
            'inode_limit' => 'Inode quota',
            'inodes_remain' => 'Inodes remaining',
            'under_megabyte_limit' => 'Under disk limit (as reported)',
            'under_inode_limit' => 'Under inode limit (as reported)',
            'under_quota_overall' => 'Under overall quota (as reported)',
        ];
        $displayFields = [];
        foreach ($labels as $field => $label) {
            if (!array_key_exists($field, $result['usage'])) {
                continue;
            }
            $value = $result['usage'][$field];
            if (in_array($field, $optionalFields, true)) {
                if (!is_bool($value)) {
                    return null;
                }
                $displayFields[$label] = $value ? 'true' : 'false';
                continue;
            }
            $pattern = in_array($field, ['inode_limit', 'inodes_remain', 'inodes_used'], true)
                ? '/^[0-9]{1,32}$/D' : '/^[0-9]{1,32}(?:\\.[0-9]{1,12})?$/D';
            if (!is_string($value) || !preg_match($pattern, $value)) {
                return null;
            }
            $displayFields[$label] = $value;
        }

        return [
            'main_domain' => $mainDomain,
            'field_count' => count($displayFields),
            'display_fields' => $displayFields,
        ];
    }

    private function validatedBandwidthUsage($result, array $account)
    {
        $fields = ['used', 'limit', 'percent', 'units', 'zero_is_unlimited', 'is_maxed', 'normalized'];
        if (!is_array($result) || empty($result['confirmed'])
            || (int) (isset($result['panel_account_id']) ? $result['panel_account_id'] : 0) !== (int) $account['id']
            || !isset($result['username'], $result['main_domain'], $result['usage'], $result['fields_reported'],
                $result['field_count'], $result['completeness'])
            || !is_string($result['username']) || !is_string($result['main_domain'])
            || !is_array($result['usage']) || !is_array($result['fields_reported'])
            || $result['completeness'] !== 'vendor_reported'
            || !in_array((string) (isset($result['status']) ? $result['status'] : ''),
                [PanelAccountService::STATUS_ACTIVE, PanelAccountService::STATUS_SUSPENDED], true)) {
            return null;
        }

        $username = strtolower(trim($result['username']));
        $mainDomain = strtolower(trim($result['main_domain']));
        if ($username !== strtolower(trim((string) $account['username']))
            || $mainDomain === '' || $mainDomain !== strtolower(trim((string) $account['domain']))
            || filter_var($mainDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || !is_int($result['field_count']) || $result['field_count'] !== count($fields)
            || array_keys($result['usage']) !== $fields || array_values($result['fields_reported']) !== $fields) {
            return null;
        }

        $usage = $result['usage'];
        foreach (['used', 'limit'] as $field) {
            if (!is_string($usage[$field]) || !preg_match('/^[0-9]{1,32}(?:\\.[0-9]{1,12})?$/D', $usage[$field])) {
                return null;
            }
        }
        if (!is_int($usage['percent']) || $usage['percent'] < 0 || $usage['percent'] > 100
            || !is_string($usage['units']) || !preg_match('/^[A-Za-z0-9 ._-]{1,16}$/D', $usage['units'])
            || !is_bool($usage['zero_is_unlimited']) || !is_bool($usage['is_maxed'])
            || !is_bool($usage['normalized'])) {
            return null;
        }

        $displayFields = [
            'Bandwidth used (as reported)' => $usage['used'],
            'Bandwidth limit (as reported)' => $usage['limit'],
            'Percent (as reported)' => (string) $usage['percent'],
            'Units (as reported)' => $usage['units'],
            'Zero-is-unlimited flag (as reported)' => $usage['zero_is_unlimited'] ? 'true' : 'false',
            'Is-maxed flag (as reported)' => $usage['is_maxed'] ? 'true' : 'false',
            'Normalized flag (as reported)' => $usage['normalized'] ? 'true' : 'false',
        ];

        return [
            'main_domain' => $mainDomain,
            'field_count' => count($displayFields),
            'display_fields' => $displayFields,
        ];
    }

    private function validatedAliases($result, array $account)
    {
        if (!is_array($result) || empty($result['confirmed'])
            || (int) (isset($result['panel_account_id']) ? $result['panel_account_id'] : 0) !== (int) $account['id']
            || !isset($result['main_domain'], $result['alias_count'], $result['aliases'], $result['completeness'])
            || !is_string($result['main_domain']) || !is_array($result['aliases'])
            || $result['completeness'] !== 'vendor_reported'
            || !in_array((string) (isset($result['status']) ? $result['status'] : ''),
                [PanelAccountService::STATUS_ACTIVE, PanelAccountService::STATUS_SUSPENDED], true)) {
            return null;
        }

        $mainDomain = strtolower(trim($result['main_domain']));
        $expectedDomain = strtolower(trim((string) $account['domain']));
        if ($mainDomain === '' || $mainDomain !== $expectedDomain || count($result['aliases']) > 1000
            || (int) $result['alias_count'] !== count($result['aliases'])
            || filter_var($mainDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        $aliases = [];
        $seen = [];
        foreach ($result['aliases'] as $item) {
            if (!is_array($item) || !isset($item['alias'], $item['domain'])
                || !is_string($item['alias']) || !is_string($item['domain'])) {
                return null;
            }
            $label = strtolower(trim($item['alias']));
            $domain = strtolower(trim($item['domain']));
            if ($label === '' || strlen($label) > 253
                || filter_var($label, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
                || $domain !== $label . '.' . $mainDomain || isset($seen[$domain])
                || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                return null;
            }
            $seen[$domain] = true;
            $aliases[] = ['alias' => $label, 'domain' => $domain];
        }
        return [
            'main_domain' => $mainDomain,
            'alias_count' => count($aliases),
            'aliases' => $aliases,
            'completeness' => 'vendor_reported',
        ];
    }

    private function pageUrl(array $query = [])
    {
        $url = rtrim($this->baseUrl, '&?') . '&action=panel_accounts';
        if ($query) {
            $url .= '&' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return $url;
    }

    private static function positiveId($value)
    {
        if (!is_scalar($value)) {
            return 0;
        }
        $value = (string) $value;
        if (!preg_match('/^[0-9]{1,10}$/D', $value)) {
            return 0;
        }
        $id = (int) $value;
        return $id > 0 ? $id : 0;
    }

    private function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
