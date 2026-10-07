<?php
/**
 * Admin portal (WHMCS addon output).
 *
 * Follows the house pattern used by cloudhost247ai / digitalproducts:
 * ob_start + echo with escaping helpers, ?action= routing, every POST gated by
 * CSRF + Rbac. No Smarty templates — addon modules in this repository render
 * from PHP.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Http;

use Ch247Mkt\Audience\ImportService;
use Ch247Mkt\Automation\AutomationService;
use Ch247Mkt\Audience\ListService;
use Ch247Mkt\Audience\SegmentService;
use Ch247Mkt\Audience\SubscriberService;
use Ch247Mkt\Campaign\BlockLibrary;
use Ch247Mkt\Campaign\CampaignService;
use Ch247Mkt\Campaign\Designer;
use Ch247Mkt\Campaign\Personalizer;
use Ch247Mkt\Campaign\TemplateService;
use Ch247Mkt\Core\Audit;
use Ch247Mkt\Core\Clock;
use Ch247Mkt\Core\Csrf;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\Identity;
use Ch247Mkt\Core\Logger;
use Ch247Mkt\Core\Rbac;
use Ch247Mkt\Core\Settings;
use Ch247Mkt\Core\Str;
use Ch247Mkt\Core\Whmcs;
use Ch247Mkt\Delivery\AnalyticsService;
use Ch247Mkt\Delivery\ComplianceService;
use Ch247Mkt\Delivery\DeliveryService;
use Ch247Mkt\Delivery\QueueService;
use Ch247Mkt\Transport\TransportFactory;

class AdminPortal
{
    protected $vars;
    protected $moduleLink;
    protected $action;
    protected $error = '';
    protected $success = '';
    protected $notice = '';

    public function __construct(array $vars)
    {
        $this->vars = $vars;
        $this->moduleLink = isset($vars['modulelink']) ? $vars['modulelink'] : 'addonmodules.php?module=' . CH247M_MODULE_NAME;
        $this->action = preg_replace('/[^a-z_-]/', '', (string) ($_GET['action'] ?? 'dashboard'));
    }

    public function render()
    {
        if (!Identity::adminId()) {
            return '<div class="alert alert-danger">Admin session required.</div>';
        }
        if (!Rbac::adminCan(Rbac::MKT_READ)) {
            return '<div class="alert alert-danger"><strong>No access.</strong> Your admin role has not been granted any Email Marketing permissions. '
                . 'A super administrator can grant them under Settings &rarr; Permissions.</div>';
        }

        try {
            $this->handlePost();
        } catch (\Ch247Mkt\Core\ValidationException $e) {
            $this->error = $e->getMessage();
        } catch (\Ch247Mkt\Core\ForbiddenException $e) {
            $this->error = $e->getMessage();
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            Logger::error('admin portal POST failed', ['action' => $this->action, 'reason' => get_class($e), 'message' => $e->getMessage()]);
        }

        ob_start();
        echo $this->styles();
        echo '<div class="ch247m-admin">';
        $this->notices();
        try {
            switch ($this->action) {
                case 'campaigns':     $this->campaignsPage(); break;
                case 'campaign':      $this->campaignPage(); break;
                case 'builder':       $this->builderPage(); break;
                case 'preview':       $this->previewPage(); break;
                case 'templates':     $this->templatesPage(); break;
                case 'automations':   $this->automationsPage(); break;
                case 'automation':    $this->automationPage(); break;
                case 'subscribers':   $this->subscribersPage(); break;
                case 'subscriber':    $this->subscriberPage(); break;
                case 'import':        $this->importPage(); break;
                case 'lists':         $this->listsPage(); break;
                case 'segments':      $this->segmentsPage(); break;
                case 'segment':       $this->segmentPage(); break;
                case 'queue':         $this->queuePage(); break;
                case 'suppressions':  $this->suppressionsPage(); break;
                case 'settings':      $this->settingsPage(); break;
                default:              $this->dashboardPage(); break;
            }
        } catch (\Throwable $e) {
            echo '<div class="alert alert-danger">Unable to load this section: ' . ch247m_h($e->getMessage()) . '</div>';
            Logger::error('admin portal render failed', ['action' => $this->action, 'reason' => get_class($e)]);
        }
        echo '</div>';
        return ob_get_clean();
    }

    /* ============================================================ POST == */

    protected function handlePost()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        Csrf::verifyRequest();
        $action = (string) ($_POST['ch247m_action'] ?? '');
        $adminId = (int) Identity::adminId();

        switch ($action) {
            /* -------------------------------------------------- settings */
            case 'save_settings':
                Rbac::requireAdmin(Rbac::MKT_MANAGE);
                $this->saveSettings();
                break;

            case 'save_permissions':
                Rbac::requireAdmin(Rbac::MKT_MANAGE);
                foreach ((array) ($_POST['roles'] ?? []) as $roleId => $groups) {
                    Rbac::setRoleGroups((int) $roleId, array_keys((array) $groups));
                }
                Audit::admin($adminId, 'permissions.saved', []);
                $this->success = 'Permissions saved.';
                break;

            case 'verify_transport':
                Rbac::requireAdmin(Rbac::MKT_MANAGE);
                $check = TransportFactory::verify();
                if ($check['ok']) {
                    $this->success = 'Transport "' . $check['driver'] . '": ' . $check['message'];
                } else {
                    $this->error = 'Transport "' . $check['driver'] . '" is not usable: ' . $check['message'];
                }
                break;

            case 'send_probe':
                Rbac::requireAdmin(Rbac::MKT_MANAGE);
                $to = Str::normalizeEmail($_POST['probe_email'] ?? '');
                if (!Str::isEmail($to)) {
                    throw new \Ch247Mkt\Core\ValidationException('Enter a valid address to send the probe to.');
                }
                $result = DeliveryService::sendNow([
                    'to_email' => $to,
                    'subject'  => 'CloudHost247 Marketing — transport test',
                    'html'     => '<p>This is a transport test from CloudHost247 Marketing.</p><p>If you received it, your sender identity and transport are working.</p>',
                    'text'     => "This is a transport test from CloudHost247 Marketing.\n\nIf you received it, your sender identity and transport are working.",
                ]);
                if ($result->ok) {
                    $this->success = 'Probe accepted by the transport. Check ' . ch247m_h($to) . '.';
                } else {
                    $this->error = 'Probe failed: ' . $result->error;
                }
                break;

            /* ------------------------------------------------- campaigns */
            case 'create_campaign':
                Rbac::requireAdmin(Rbac::MKT_COMPOSE);
                $campaign = CampaignService::create([
                    'name'        => $_POST['name'] ?? '',
                    'type'        => $_POST['type'] ?? 'campaign',
                    'subject'     => $_POST['subject'] ?? '',
                    'template_id' => (int) ($_POST['template_id'] ?? 0),
                ], $adminId);
                $this->redirect('builder', ['id' => (int) $campaign['id'], 'created' => 1]);
                break;

            case 'save_campaign':
                Rbac::requireAdmin(Rbac::MKT_COMPOSE);
                $id = (int) ($_POST['id'] ?? 0);
                CampaignService::update($id, [
                    'name'       => $_POST['name'] ?? null,
                    'subject'    => $_POST['subject'] ?? null,
                    'preheader'  => $_POST['preheader'] ?? null,
                    'from_name'  => $_POST['from_name'] ?? null,
                    'from_email' => $_POST['from_email'] ?? null,
                    'reply_to'   => $_POST['reply_to'] ?? null,
                ], $adminId);
                $this->success = 'Campaign details saved.';
                break;

            case 'save_design':
                Rbac::requireAdmin(Rbac::MKT_COMPOSE);
                $id = (int) ($_POST['id'] ?? 0);
                $design = json_decode((string) ($_POST['design'] ?? ''), true);
                if (!is_array($design)) {
                    throw new \Ch247Mkt\Core\ValidationException('The design could not be read. Try saving again.');
                }
                CampaignService::update($id, ['design' => $design], $adminId);
                $this->success = 'Design saved.';
                break;

            case 'save_audience':
                Rbac::requireAdmin(Rbac::MKT_COMPOSE);
                $id = (int) ($_POST['id'] ?? 0);
                CampaignService::update($id, ['audience' => [
                    'lists'            => (array) ($_POST['lists'] ?? []),
                    'segments'         => (array) ($_POST['segments'] ?? []),
                    'exclude_lists'    => (array) ($_POST['exclude_lists'] ?? []),
                    'exclude_segments' => (array) ($_POST['exclude_segments'] ?? []),
                ]], $adminId);
                $this->success = 'Audience saved.';
                break;

            case 'duplicate_campaign':
                Rbac::requireAdmin(Rbac::MKT_COMPOSE);
                $copy = CampaignService::duplicate((int) ($_POST['id'] ?? 0), $adminId);
                $this->redirect('builder', ['id' => (int) $copy['id']]);
                break;

            case 'delete_campaign':
                Rbac::requireAdmin(Rbac::MKT_COMPOSE);
                CampaignService::delete((int) ($_POST['id'] ?? 0), $adminId);
                $this->redirect('campaigns', ['deleted' => 1]);
                break;

            case 'send_test':
                Rbac::requireAdmin(Rbac::MKT_COMPOSE);
                $emails = preg_split('/[\s,;]+/', (string) ($_POST['test_emails'] ?? ''));
                $result = CampaignService::sendTest((int) ($_POST['id'] ?? 0), array_filter($emails), $adminId);
                $this->success = $result['queued'] . ' test message(s) queued. They go out on the next cron run — or run the worker manually.';
                break;

            case 'build_audience':
                Rbac::requireAdmin(Rbac::MKT_COMPOSE);
                $result = CampaignService::buildRecipients((int) ($_POST['id'] ?? 0), $adminId);
                $this->success = 'Audience frozen: ' . number_format($result['count']) . ' recipients ('
                    . $result['stats']['suppressed'] . ' suppressed, ' . $result['stats']['deduplicated'] . ' duplicates removed).';
                break;

            case 'schedule_campaign':
                Rbac::requireAdmin(Rbac::MKT_SEND);
                CampaignService::schedule(
                    (int) ($_POST['id'] ?? 0),
                    $_POST['scheduled_at'] ?? '',
                    $_POST['timezone'] ?? '',
                    $adminId
                );
                $this->success = 'Campaign scheduled.';
                break;

            case 'send_campaign':
                Rbac::requireAdmin(Rbac::MKT_SEND);
                $result = CampaignService::send((int) ($_POST['id'] ?? 0), $adminId);
                $this->success = number_format($result['queued']) . ' messages queued. Delivery runs on the cron worker.';
                break;

            case 'pause_campaign':
                Rbac::requireAdmin(Rbac::MKT_SEND);
                CampaignService::pause((int) ($_POST['id'] ?? 0), $adminId);
                $this->success = 'Campaign paused. The worker will skip it until you resume.';
                break;

            case 'resume_campaign':
                Rbac::requireAdmin(Rbac::MKT_SEND);
                CampaignService::resume((int) ($_POST['id'] ?? 0), $adminId);
                $this->success = 'Campaign resumed.';
                break;

            case 'cancel_campaign':
                Rbac::requireAdmin(Rbac::MKT_SEND);
                $result = CampaignService::cancel((int) ($_POST['id'] ?? 0), $adminId);
                $this->success = 'Campaign cancelled. ' . number_format($result['cancelled']) . ' queued message(s) stopped.';
                break;

            case 'retry_failed':
                Rbac::requireAdmin(Rbac::MKT_SEND);
                $count = QueueService::retryFailed((int) ($_POST['id'] ?? 0));
                $this->success = number_format($count) . ' failed message(s) re-queued.';
                break;

            case 'run_worker':
                Rbac::requireAdmin(Rbac::MKT_SEND);
                $summary = DeliveryService::processBatch();
                $this->success = 'Worker run: ' . $summary['sent'] . ' sent, ' . $summary['failed'] . ' failed, '
                    . $summary['retried'] . ' retrying, ' . $summary['skipped'] . ' skipped'
                    . ($summary['stopped'] !== '' ? ' (stopped: ' . str_replace('_', ' ', $summary['stopped']) . ')' : '') . '.';
                break;

            /* ------------------------------------------------- templates */
            case 'save_as_template':
                Rbac::requireAdmin(Rbac::MKT_COMPOSE);
                $campaign = CampaignService::find((int) ($_POST['id'] ?? 0));
                if ($campaign === null) {
                    throw new \Ch247Mkt\Core\NotFoundException('Campaign not found.');
                }
                TemplateService::saveFromCampaign($campaign, (string) ($_POST['template_name'] ?? $campaign['name']), $adminId);
                $this->success = 'Saved as a reusable template.';
                break;

            case 'duplicate_template':
                Rbac::requireAdmin(Rbac::MKT_COMPOSE);
                TemplateService::duplicate((int) ($_POST['id'] ?? 0), $adminId);
                $this->success = 'Template duplicated — the copy is editable.';
                break;

            case 'archive_template':
                Rbac::requireAdmin(Rbac::MKT_COMPOSE);
                TemplateService::archive((int) ($_POST['id'] ?? 0), $adminId);
                $this->success = 'Template archived.';
                break;

            case 'reseed_templates':
                Rbac::requireAdmin(Rbac::MKT_MANAGE);
                $result = TemplateService::seed();
                $this->success = 'Template library refreshed (' . $result['installed'] . ' installed, ' . $result['refreshed'] . ' updated).';
                break;

            /* ------------------------------------------------ automations */
            case 'create_automation':
                Rbac::requireAdmin(Rbac::MKT_COMPOSE);
                $automation = AutomationService::create($_POST, $adminId);
                $this->redirect('automation', ['id' => (int) $automation['id'], 'created' => 1]);
                break;

            case 'save_automation':
                Rbac::requireAdmin(Rbac::MKT_COMPOSE);
                $filter = [];
                foreach (preg_split('/\r?\n/', (string) ($_POST['trigger_filter'] ?? '')) as $line) {
                    if (strpos($line, '=') === false) {
                        continue;
                    }
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    if ($key !== '') {
                        $filter[$key] = trim($value);
                    }
                }
                AutomationService::update((int) ($_POST['id'] ?? 0), [
                    'name'            => $_POST['name'] ?? '',
                    'description'     => $_POST['description'] ?? '',
                    'trigger_event'   => $_POST['trigger_event'] ?? '',
                    'trigger_filter'  => $filter,
                    'reentry_allowed' => !empty($_POST['reentry_allowed']),
                ], $adminId);
                $this->success = 'Automation saved.';
                break;

            case 'toggle_automation':
                Rbac::requireAdmin(Rbac::MKT_SEND);
                $enable = !empty($_POST['enable']);
                AutomationService::update((int) ($_POST['id'] ?? 0), ['enabled' => $enable], $adminId);
                $this->success = $enable
                    ? 'Automation enabled. It will enrol people from the next matching event onwards — it does not back-fill.'
                    : 'Automation disabled. People already part-way through will stop at their next step.';
                break;

            case 'add_step':
                Rbac::requireAdmin(Rbac::MKT_COMPOSE);
                $config = [];
                if (($_POST['tag'] ?? '') !== '') {
                    $config['tag'] = $_POST['tag'];
                }
                if ((int) ($_POST['list_id'] ?? 0) > 0) {
                    $config['list_id'] = (int) $_POST['list_id'];
                }
                AutomationService::addStep((int) ($_POST['id'] ?? 0), [
                    'action'       => $_POST['step_action'] ?? '',
                    'wait_seconds' => ((int) ($_POST['wait_amount'] ?? 0)) * max(1, (int) ($_POST['wait_unit'] ?? 3600)),
                    'campaign_id'  => (int) ($_POST['campaign_id'] ?? 0),
                    'config'       => $config,
                ], $adminId);
                $this->success = 'Step added.';
                break;

            case 'remove_step':
                Rbac::requireAdmin(Rbac::MKT_COMPOSE);
                AutomationService::removeStep((int) ($_POST['step_id'] ?? 0), $adminId);
                $this->success = 'Step removed.';
                break;

            case 'delete_automation':
                Rbac::requireAdmin(Rbac::MKT_COMPOSE);
                AutomationService::delete((int) ($_POST['id'] ?? 0), $adminId);
                $this->redirect('automations', ['deleted' => 1]);
                break;

            /* ----------------------------------------------- subscribers */
            case 'save_subscriber':
                Rbac::requireAdmin(Rbac::MKT_AUDIENCE);
                $subscriber = SubscriberService::upsert([
                    'email'          => $_POST['email'] ?? '',
                    'first_name'     => $_POST['first_name'] ?? '',
                    'last_name'      => $_POST['last_name'] ?? '',
                    'company'        => $_POST['company'] ?? '',
                    'client_id'      => (int) ($_POST['client_id'] ?? 0),
                    'tags'           => $_POST['tags'] ?? '',
                    'consent_source' => $_POST['consent_source'] ?? 'admin',
                    'custom_fields'  => $this->customFieldsFromPost(),
                ], ['lists' => (array) ($_POST['lists'] ?? []), 'actor' => 'admin', 'actor_id' => $adminId]);
                // Reconcile list membership: remove any that were unticked.
                $keep = array_map('intval', (array) ($_POST['lists'] ?? []));
                foreach (ListService::listsFor((int) $subscriber['id']) as $listId) {
                    if (!in_array($listId, $keep, true)) {
                        ListService::removeMember($listId, (int) $subscriber['id']);
                    }
                }
                $this->success = 'Subscriber saved.';
                break;

            case 'unsubscribe_subscriber':
                Rbac::requireAdmin(Rbac::MKT_AUDIENCE);
                SubscriberService::unsubscribe((int) ($_POST['id'] ?? 0), ['source' => 'admin', 'actor' => 'admin', 'actor_id' => $adminId]);
                $this->success = 'Subscriber unsubscribed and added to the suppression list.';
                break;

            case 'resubscribe_subscriber':
                Rbac::requireAdmin(Rbac::MKT_AUDIENCE);
                SubscriberService::resubscribe((int) ($_POST['id'] ?? 0), [
                    'consent_source' => $_POST['consent_source'] ?? '',
                    'actor_id'       => $adminId,
                ]);
                $this->success = 'Subscriber re-subscribed and removed from the suppression list.';
                break;

            case 'delete_subscriber':
                Rbac::requireAdmin(Rbac::MKT_AUDIENCE);
                SubscriberService::delete((int) ($_POST['id'] ?? 0), ['actor_id' => $adminId]);
                $this->redirect('subscribers', ['deleted' => 1]);
                break;

            case 'import_subscribers':
                Rbac::requireAdmin(Rbac::MKT_AUDIENCE);
                $this->handleImport($adminId);
                break;

            case 'export_subscribers':
                Rbac::requireAdmin(Rbac::MKT_AUDIENCE);
                $this->streamExport();
                break;

            /* ----------------------------------------------------- lists */
            case 'create_list':
                Rbac::requireAdmin(Rbac::MKT_AUDIENCE);
                ListService::create($_POST, $adminId);
                $this->success = 'List created.';
                break;

            case 'update_list':
                Rbac::requireAdmin(Rbac::MKT_AUDIENCE);
                ListService::update((int) ($_POST['id'] ?? 0), $_POST, $adminId);
                $this->success = 'List updated.';
                break;

            case 'archive_list':
                Rbac::requireAdmin(Rbac::MKT_AUDIENCE);
                ListService::archive((int) ($_POST['id'] ?? 0), $adminId);
                $this->success = 'List archived.';
                break;

            /* -------------------------------------------------- segments */
            case 'save_segment':
                Rbac::requireAdmin(Rbac::MKT_AUDIENCE);
                $definition = ['match' => $_POST['match'] ?? 'all', 'rules' => $this->rulesFromPost()];
                $id = (int) ($_POST['id'] ?? 0);
                if ($id > 0) {
                    SegmentService::update($id, [
                        'name' => $_POST['name'] ?? '', 'description' => $_POST['description'] ?? '',
                        'source' => $_POST['source'] ?? '', 'definition' => $definition,
                    ], $adminId);
                } else {
                    $segment = SegmentService::create([
                        'name' => $_POST['name'] ?? '', 'description' => $_POST['description'] ?? '',
                        'source' => $_POST['source'] ?? '', 'definition' => $definition,
                    ], $adminId);
                    $id = (int) $segment['id'];
                }
                SegmentService::refreshCount($id);
                $this->redirect('segment', ['id' => $id, 'saved' => 1]);
                break;

            case 'refresh_segment':
                Rbac::requireAdmin(Rbac::MKT_AUDIENCE);
                $count = SegmentService::refreshCount((int) ($_POST['id'] ?? 0));
                $this->success = 'Segment matches ' . number_format($count) . ' recipient(s) right now.';
                break;

            case 'delete_segment':
                Rbac::requireAdmin(Rbac::MKT_AUDIENCE);
                SegmentService::delete((int) ($_POST['id'] ?? 0), $adminId);
                $this->redirect('segments', ['deleted' => 1]);
                break;

            /* ---------------------------------------------- suppressions */
            case 'add_suppression':
                Rbac::requireAdmin(Rbac::MKT_MANAGE);
                $email = (string) ($_POST['email'] ?? '');
                ComplianceService::assertValidEmail($email);
                ComplianceService::suppress($email, 'manual', ['source' => 'admin', 'notes' => $_POST['notes'] ?? '']);
                $this->success = ch247m_h($email) . ' added to the suppression list.';
                break;

            case 'remove_suppression':
                Rbac::requireAdmin(Rbac::MKT_MANAGE);
                ComplianceService::unsuppress((string) ($_POST['email'] ?? ''), $adminId);
                $this->success = 'Suppression removed. That address can receive marketing email again.';
                break;

            default:
                if ($action !== '') {
                    throw new \Ch247Mkt\Core\ValidationException('Unknown action.');
                }
        }
    }

    protected function saveSettings()
    {
        $saved = 0;
        $skipped = [];
        foreach (Settings::editable() as $key) {
            if (!array_key_exists($key, $_POST)) {
                // Unchecked checkboxes do not post; treat known booleans as 0.
                if (in_array(Settings::DEFAULTS[$key], ['0', '1'], true) && isset($_POST['ch247m_bools']) && in_array($key, (array) $_POST['ch247m_bools'], true)) {
                    $value = '0';
                } else {
                    continue;
                }
            } else {
                $value = (string) $_POST[$key];
            }
            // An empty secret field means "leave it alone", not "clear it".
            if (in_array($key, Settings::SECRET_KEYS, true) && trim($value) === '') {
                continue;
            }
            try {
                Settings::put($key, $value);
                $saved++;
            } catch (\Throwable $e) {
                $skipped[] = $key;
            }
        }
        Audit::admin((int) Identity::adminId(), 'settings.saved', ['saved' => $saved, 'skipped' => $skipped]);
        $this->success = $saved . ' setting(s) saved.'
            . ($skipped !== [] ? ' Skipped (environment-controlled): ' . implode(', ', $skipped) . '.' : '');
    }

    protected function handleImport($adminId)
    {
        if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \Ch247Mkt\Core\ValidationException('Choose a CSV or TSV file to upload.');
        }
        $name = strtolower((string) ($_FILES['file']['name'] ?? ''));
        if (preg_match('/\.(xlsx|xls)$/', $name) === 1) {
            throw new \Ch247Mkt\Core\ValidationException('Excel files are not supported directly. In Excel or Google Sheets choose "Save as" / "Download as" CSV, then upload that.');
        }
        $parsed = ImportService::parse($_FILES['file']['tmp_name']);
        $mapping = [];
        foreach ((array) ($_POST['map'] ?? []) as $index => $field) {
            $field = (string) $field;
            if ($field !== '') {
                $mapping[(int) $index] = $field;
            }
        }
        if ($mapping === []) {
            $mapping = ImportService::suggestMapping($parsed['headers']);
        }
        $result = ImportService::import($parsed['rows'], $mapping, [
            'lists'          => (array) ($_POST['lists'] ?? []),
            'consent_source' => $_POST['consent_source'] ?? '',
            'status'         => $_POST['status'] ?? SubscriberService::STATUS_SUBSCRIBED,
            'dry_run'        => !empty($_POST['dry_run']),
            'actor_id'       => $adminId,
        ]);
        $prefix = !empty($_POST['dry_run']) ? 'Dry run — nothing was written. ' : '';
        $this->success = $prefix . number_format($result['imported']) . ' added, ' . number_format($result['updated']) . ' updated, '
            . number_format($result['invalid']) . ' invalid, ' . number_format($result['suppressed']) . ' skipped (suppressed), '
            . number_format($result['skipped']) . ' duplicate rows.';
        if ($result['errors'] !== []) {
            $this->notice = implode(' • ', array_slice($result['errors'], 0, 10));
        }
    }

    protected function streamExport()
    {
        $csv = ImportService::exportCsv([
            'status'  => $_POST['status'] ?? '',
            'list_id' => (int) ($_POST['list_id'] ?? 0),
            'q'       => $_POST['q'] ?? '',
        ]);
        if (headers_sent()) {
            $this->error = 'Export could not start because output already began. Try again from a fresh page load.';
            return;
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="subscribers-' . gmdate('Ymd-His', Clock::time()) . '.csv"');
        header('Content-Length: ' . strlen($csv));
        echo $csv;
        exit;
    }

    /* ======================================================= pages ===== */

    protected function dashboardPage()
    {
        $d = AnalyticsService::dashboard();
        $p = $d['performance'];

        echo $this->header('Dashboard', 'An overview of your audience, campaigns and delivery health.');

        if (!Settings::bool('sending_enabled', false)) {
            echo '<div class="alert alert-warning"><strong>Sending is disabled.</strong> '
                . 'Campaigns can be built and tested, but nothing leaves the queue. '
                . '<a href="' . $this->url('settings') . '">Finish setup in Settings</a> to turn it on.</div>';
        }

        echo '<div class="row">';
        echo $this->statCard('Subscribers', number_format($d['subscribers']['subscribed']), number_format($d['subscribers']['total']) . ' total records', 'fa-users', $this->url('subscribers'));
        echo $this->statCard('Active lists', number_format($d['lists']), number_format($d['segments']) . ' segments', 'fa-list', $this->url('lists'));
        echo $this->statCard('Drafts', number_format($d['campaigns']['draft']), number_format($d['campaigns']['scheduled']) . ' scheduled', 'fa-pencil', $this->url('campaigns', ['status' => 'draft']));
        echo $this->statCard('Sent campaigns', number_format($d['campaigns']['sent']), number_format($d['campaigns']['sending']) . ' sending now', 'fa-paper-plane', $this->url('campaigns', ['status' => 'sent']));
        echo '</div>';

        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Lifetime delivery performance</strong></div><div class="panel-body">';
        if ($p['sent'] === 0) {
            echo '<p class="text-muted" style="margin:0;">Nothing has been sent yet. Rates appear here after your first campaign.</p>';
        } else {
            echo '<div class="ch247m-metrics">';
            echo $this->metric('Delivery rate', $p['delivery_rate'] . '%', number_format($p['delivered']) . ' of ' . number_format($p['sent']));
            echo $this->metric('Open rate', $p['open_rate'] . '%', number_format($p['unique_opens']) . ' unique opens');
            echo $this->metric('Click rate', $p['click_rate'] . '%', number_format($p['unique_clicks']) . ' unique clicks');
            echo $this->metric('Bounce rate', $p['bounce_rate'] . '%', number_format($p['bounced']) . ' bounced', $p['bounce_rate'] > 2 ? 'danger' : '');
            echo $this->metric('Unsubscribe rate', $p['unsubscribe_rate'] . '%', number_format($p['unsubscribed']) . ' opted out', $p['unsubscribe_rate'] > 0.5 ? 'warning' : '');
            echo $this->metric('Complaint rate', $p['complaint_rate'] . '%', number_format($p['complained']) . ' complaints', $p['complaint_rate'] > 0.1 ? 'danger' : '');
            echo '</div>';
            echo '<p class="text-muted small" style="margin:12px 0 0;">Open and click rates are calculated against <em>delivered</em>, not sent. '
                . 'Open tracking depends on images loading, so treat it as a floor, not a precise count.</p>';
        }
        echo '</div></div>';

        echo '<div class="row"><div class="col-md-7">';
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Recent campaign activity</strong></div>';
        $recent = AnalyticsService::recentActivity(8);
        if ($recent === []) {
            echo '<div class="panel-body text-muted">No campaigns yet. <a href="' . $this->url('campaigns') . '">Create your first one.</a></div>';
        } else {
            echo '<table class="table table-striped" style="margin:0;"><thead><tr><th>Campaign</th><th>Status</th><th>Recipients</th><th>Opens</th><th>Updated</th></tr></thead><tbody>';
            foreach ($recent as $c) {
                echo '<tr><td><a href="' . $this->url('campaign', ['id' => (int) $c['id']]) . '">' . ch247m_h($c['name']) . '</a></td>'
                    . '<td>' . ch247m_pill($c['status']) . '</td>'
                    . '<td>' . number_format((int) $c['total_recipients']) . '</td>'
                    . '<td>' . number_format((int) $c['count_opened']) . '</td>'
                    . '<td class="text-muted small">' . ch247m_dt($c['updated_at']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div></div>';

        echo '<div class="col-md-5">';
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Delivery queue</strong></div><div class="panel-body">';
        $q = $d['queue'];
        echo '<div class="ch247m-metrics">';
        echo $this->metric('Pending', number_format($q['pending']), 'waiting for the worker');
        echo $this->metric('Sent', number_format($q['sent']), 'accepted by transport');
        echo $this->metric('Failed', number_format($q['failed']), 'gave up after retries', $q['failed'] > 0 ? 'danger' : '');
        echo '</div>';
        echo '<p style="margin:12px 0 0;"><a class="btn btn-default btn-sm" href="' . $this->url('queue') . '">Open the queue</a></p>';
        echo '</div></div>';

        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Suppression list</strong></div><div class="panel-body">';
        $s = $d['suppressions'];
        echo '<p class="text-muted small" style="margin-top:0;">Addresses that can never receive marketing email again.</p>';
        echo '<ul class="list-unstyled" style="margin:0;">';
        foreach (['unsubscribe' => 'Unsubscribed', 'hard_bounce' => 'Hard bounced', 'complaint' => 'Spam complaints', 'manual' => 'Added manually', 'invalid' => 'Invalid'] as $key => $label) {
            echo '<li>' . ch247m_h($label) . ': <strong>' . number_format($s[$key]) . '</strong></li>';
        }
        echo '</ul></div></div>';
        echo '</div></div>';
    }

    protected function campaignsPage()
    {
        $status = (string) ($_GET['status'] ?? '');
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $result = CampaignService::search(['status' => $status, 'q' => $_GET['q'] ?? ''], $page, 25);

        echo $this->header('Campaigns', 'One-time sends, automations and transactional messages.');

        if (Rbac::adminCan(Rbac::MKT_COMPOSE)) {
            echo '<div class="panel panel-default"><div class="panel-heading"><strong>New campaign</strong></div><div class="panel-body">';
            echo '<form method="post" class="form-inline">' . Csrf::field()
                . '<input type="hidden" name="ch247m_action" value="create_campaign">'
                . '<input class="form-control" type="text" name="name" placeholder="Campaign name" required style="min-width:220px;"> '
                . '<input class="form-control" type="text" name="subject" placeholder="Subject line (optional)" style="min-width:240px;"> '
                . '<select class="form-control" name="template_id">'
                . '<option value="">Start from blank</option>';
            foreach (TemplateService::all() as $template) {
                echo '<option value="' . (int) $template['id'] . '">' . ch247m_h($template['name']) . ($template['is_system'] ? '' : ' (custom)') . '</option>';
            }
            echo '</select> <button class="btn btn-primary" type="submit">Create &amp; open builder</button>';
            echo '</form></div></div>';
        }

        echo '<form method="get" class="form-inline" style="margin-bottom:12px;">';
        echo $this->hiddenRouting('campaigns');
        echo '<input class="form-control" type="text" name="q" value="' . ch247m_h($_GET['q'] ?? '') . '" placeholder="Search name or subject"> ';
        echo '<select class="form-control" name="status"><option value="">All statuses</option>';
        foreach (CampaignService::STATUSES as $s) {
            echo '<option value="' . ch247m_h($s) . '"' . ($status === $s ? ' selected' : '') . '>' . ch247m_h(ucfirst($s)) . '</option>';
        }
        echo '</select> <button class="btn btn-default" type="submit">Filter</button></form>';

        if ($result['rows'] === []) {
            echo '<div class="alert alert-info">No campaigns match. Create one above to get started.</div>';
            return;
        }

        echo '<table class="table table-striped"><thead><tr>'
            . '<th>Campaign</th><th>Type</th><th>Status</th><th class="text-right">Recipients</th>'
            . '<th class="text-right">Delivered</th><th class="text-right">Opens</th><th class="text-right">Clicks</th><th>When</th><th></th>'
            . '</tr></thead><tbody>';
        foreach ($result['rows'] as $c) {
            $when = $c['status'] === CampaignService::STATUS_SCHEDULED
                ? CampaignService::toLocal($c['scheduled_at'], $c['timezone']) . ' ' . ch247m_h($c['timezone'])
                : ch247m_dt($c['finished_at'] ?: $c['started_at'] ?: $c['created_at']);
            echo '<tr>'
                . '<td><a href="' . $this->url('campaign', ['id' => (int) $c['id']]) . '"><strong>' . ch247m_h($c['name']) . '</strong></a>'
                . ($c['subject'] !== '' ? '<br><span class="text-muted small">' . ch247m_h(Str::clip($c['subject'], 70)) . '</span>' : '') . '</td>'
                . '<td>' . ch247m_h(ucfirst($c['type'])) . '</td>'
                . '<td>' . ch247m_pill($c['status']) . '</td>'
                . '<td class="text-right">' . number_format((int) $c['total_recipients']) . '</td>'
                . '<td class="text-right">' . number_format((int) $c['count_delivered']) . '</td>'
                . '<td class="text-right">' . number_format((int) $c['count_opened']) . '</td>'
                . '<td class="text-right">' . number_format((int) $c['count_clicked']) . '</td>'
                . '<td class="small text-muted">' . $when . '</td>'
                . '<td class="text-right">';
            if (in_array($c['status'], CampaignService::EDITABLE, true) && Rbac::adminCan(Rbac::MKT_COMPOSE)) {
                echo '<a class="btn btn-xs btn-default" href="' . $this->url('builder', ['id' => (int) $c['id']]) . '">Edit</a> ';
            }
            echo '<a class="btn btn-xs btn-default" href="' . $this->url('campaign', ['id' => (int) $c['id']]) . '">Report</a>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo $this->pagination('campaigns', $page, $result['total'], 25, ['status' => $status, 'q' => $_GET['q'] ?? '']);
    }

    protected function campaignPage()
    {
        $campaign = CampaignService::find((int) ($_GET['id'] ?? 0));
        if ($campaign === null) {
            echo '<div class="alert alert-danger">Campaign not found.</div>';
            return;
        }
        $stats = AnalyticsService::campaignStats((int) $campaign['id']);
        $queue = QueueService::stats((int) $campaign['id']);

        echo $this->header(ch247m_h($campaign['name']), ch247m_h($campaign['subject'] ?: 'No subject set yet'));

        echo '<p>' . ch247m_pill($campaign['status'])
            . ' <span class="text-muted small">' . ch247m_h(CampaignService::describeAudience($campaign)) . '</span></p>';

        /* Controls */
        echo '<div class="panel panel-default"><div class="panel-body">';
        if (in_array($campaign['status'], CampaignService::EDITABLE, true) && Rbac::adminCan(Rbac::MKT_COMPOSE)) {
            echo '<a class="btn btn-primary" href="' . $this->url('builder', ['id' => (int) $campaign['id']]) . '">Open builder</a> ';
        }
        echo '<a class="btn btn-default" href="' . $this->url('preview', ['id' => (int) $campaign['id']]) . '" target="_blank">Preview</a> ';
        echo $this->postButton('duplicate_campaign', ['id' => (int) $campaign['id']], 'Duplicate', 'btn-default', Rbac::MKT_COMPOSE);
        if ($campaign['status'] === CampaignService::STATUS_SENDING) {
            echo ' ' . $this->postButton('pause_campaign', ['id' => (int) $campaign['id']], 'Pause', 'btn-warning', Rbac::MKT_SEND);
        }
        if ($campaign['status'] === CampaignService::STATUS_PAUSED) {
            echo ' ' . $this->postButton('resume_campaign', ['id' => (int) $campaign['id']], 'Resume', 'btn-success', Rbac::MKT_SEND);
        }
        if (!in_array($campaign['status'], [CampaignService::STATUS_SENT, CampaignService::STATUS_CANCELLED], true)) {
            echo ' ' . $this->postButton('cancel_campaign', ['id' => (int) $campaign['id']], 'Cancel', 'btn-danger', Rbac::MKT_SEND, 'Cancel this campaign and stop all queued messages?');
        }
        if ($queue['failed'] > 0) {
            echo ' ' . $this->postButton('retry_failed', ['id' => (int) $campaign['id']], 'Retry ' . $queue['failed'] . ' failed', 'btn-default', Rbac::MKT_SEND);
        }
        echo '</div></div>';

        /* Headline numbers */
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Results</strong></div><div class="panel-body">';
        echo '<div class="ch247m-metrics">';
        echo $this->metric('Recipients', number_format($stats['recipients']), 'in the frozen audience');
        echo $this->metric('Sent', number_format($stats['sent']), 'handed to the transport');
        echo $this->metric('Delivered', number_format($stats['delivered']), $stats['delivery_rate'] . '% of sent');
        echo $this->metric('Bounced', number_format($stats['bounced']), $stats['bounce_rate'] . '% of sent', $stats['bounce_rate'] > 2 ? 'danger' : '');
        echo '</div><div class="ch247m-metrics">';
        echo $this->metric('Unique opens', number_format($stats['unique_opens']), $stats['open_rate'] . '% open rate');
        echo $this->metric('Unique clicks', number_format($stats['unique_clicks']), $stats['click_rate'] . '% click rate');
        echo $this->metric('Click-to-open', $stats['click_to_open_rate'] . '%', 'of those who opened');
        echo $this->metric('Unsubscribed', number_format($stats['unsubscribed']), $stats['unsubscribe_rate'] . '%', $stats['unsubscribe_rate'] > 0.5 ? 'warning' : '');
        echo '</div>';
        if ($stats['failed'] > 0 || $stats['skipped'] > 0) {
            echo '<p class="text-muted small" style="margin:10px 0 0;">'
                . number_format($stats['failed']) . ' failed · ' . number_format($stats['skipped']) . ' skipped (suppressed before dispatch) · '
                . number_format($stats['complained']) . ' complaints</p>';
        }
        echo '</div></div>';

        /* Click map */
        $clickMap = AnalyticsService::clickMap((int) $campaign['id']);
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Click map</strong></div>';
        if ($clickMap === []) {
            echo '<div class="panel-body text-muted">No trackable links in this campaign yet.</div>';
        } else {
            echo '<table class="table table-striped" style="margin:0;"><thead><tr><th>Link</th><th class="text-right">Unique</th><th class="text-right">Total</th><th style="width:200px;">Share</th></tr></thead><tbody>';
            foreach ($clickMap as $link) {
                echo '<tr><td class="small"><span title="' . ch247m_h($link['url']) . '">' . ch247m_h(Str::clip($link['url'], 80)) . '</span></td>'
                    . '<td class="text-right">' . number_format($link['unique_click_count']) . '</td>'
                    . '<td class="text-right">' . number_format($link['click_count']) . '</td>'
                    . '<td><div class="ch247m-bar"><span style="width:' . (float) $link['share'] . '%"></span></div> '
                    . '<span class="small text-muted">' . $link['share'] . '%</span></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        /* Recipients */
        $filter = preg_replace('/[^a-z_]/', '', (string) ($_GET['f'] ?? 'all'));
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $recipients = AnalyticsService::recipients((int) $campaign['id'], $filter, $page, 50);

        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Recipients</strong>';
        echo '<div class="pull-right">';
        foreach (['all' => 'All', 'opened' => 'Opened', 'not_opened' => 'Not opened', 'clicked' => 'Clicked', 'bounced' => 'Bounced', 'unsubscribed' => 'Unsubscribed', 'failed' => 'Failed'] as $key => $label) {
            $cls = $filter === $key ? 'btn-primary' : 'btn-default';
            echo '<a class="btn btn-xs ' . $cls . '" href="' . $this->url('campaign', ['id' => (int) $campaign['id'], 'f' => $key]) . '">' . ch247m_h($label) . '</a> ';
        }
        echo '</div><div class="clearfix"></div></div>';

        if ($recipients['rows'] === []) {
            echo '<div class="panel-body text-muted">No recipients match this filter.'
                . ($stats['recipients'] === 0 ? ' The audience has not been frozen yet — do that from the builder.' : '') . '</div>';
        } else {
            echo '<table class="table table-striped" style="margin:0;"><thead><tr><th>Email</th><th>Status</th><th>Opened</th><th>Clicked</th><th>Detail</th></tr></thead><tbody>';
            foreach ($recipients['rows'] as $r) {
                $detail = '';
                if (!empty($r['bounced_at'])) {
                    $detail = '<span class="text-danger small">' . ch247m_h(Str::clip($r['failed_reason'] ?: 'Bounced', 90)) . '</span>';
                } elseif (!empty($r['failed_reason'])) {
                    $detail = '<span class="text-muted small">' . ch247m_h(Str::clip($r['failed_reason'], 90)) . '</span>';
                } elseif (!empty($r['unsubscribed_at'])) {
                    $detail = '<span class="text-warning small">Unsubscribed ' . ch247m_dt($r['unsubscribed_at']) . '</span>';
                }
                echo '<tr><td>' . ch247m_h($r['email']) . '</td>'
                    . '<td>' . ch247m_pill($r['status']) . '</td>'
                    . '<td>' . (!empty($r['first_opened_at']) ? 'Yes <span class="text-muted small">(' . (int) $r['open_count'] . ')</span>' : '<span class="text-muted">—</span>') . '</td>'
                    . '<td>' . (!empty($r['first_clicked_at']) ? 'Yes <span class="text-muted small">(' . (int) $r['click_count'] . ')</span>' : '<span class="text-muted">—</span>') . '</td>'
                    . '<td>' . $detail . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        echo $this->pagination('campaign', $page, $recipients['total'], 50, ['id' => (int) $campaign['id'], 'f' => $filter]);
    }

    protected function builderPage()
    {
        $campaign = CampaignService::find((int) ($_GET['id'] ?? 0));
        if ($campaign === null) {
            echo '<div class="alert alert-danger">Campaign not found.</div>';
            return;
        }
        if (!in_array($campaign['status'], CampaignService::EDITABLE, true)) {
            echo '<div class="alert alert-warning"><strong>This campaign is ' . ch247m_h($campaign['status']) . ' and can no longer be edited.</strong> '
                . 'Duplicate it to make changes. <a href="' . $this->url('campaign', ['id' => (int) $campaign['id']]) . '">View the report</a>.</div>';
            return;
        }

        $preflight = null;
        try {
            $preflight = CampaignService::preflight((int) $campaign['id']);
        } catch (\Throwable $e) {
            $preflight = ['blockers' => [$e->getMessage()], 'warnings' => [], 'content' => ['score' => 0, 'notes' => []], 'merge' => ['used' => []], 'audience' => ['count' => 0]];
        }

        echo $this->header(ch247m_h($campaign['name']), 'Build, preview, test and send.');

        echo '<ul class="nav nav-tabs ch247m-tabs">'
            . '<li class="active"><a href="#ch247m-tab-design" data-toggle="tab">1. Design</a></li>'
            . '<li><a href="#ch247m-tab-details" data-toggle="tab">2. Subject &amp; sender</a></li>'
            . '<li><a href="#ch247m-tab-audience" data-toggle="tab">3. Audience</a></li>'
            . '<li><a href="#ch247m-tab-send" data-toggle="tab">4. Review &amp; send</a></li>'
            . '</ul><div class="tab-content ch247m-tab-content">';

        /* ---- 1. Design */
        echo '<div class="tab-pane active" id="ch247m-tab-design">';
        $this->renderBuilder($campaign);
        echo '</div>';

        /* ---- 2. Details */
        echo '<div class="tab-pane" id="ch247m-tab-details"><div class="panel panel-default"><div class="panel-body">';
        echo '<form method="post">' . Csrf::field()
            . '<input type="hidden" name="ch247m_action" value="save_campaign">'
            . '<input type="hidden" name="id" value="' . (int) $campaign['id'] . '">';
        echo '<div class="row"><div class="col-md-6">';
        echo $this->field('name', 'Campaign name', $campaign['name'], 'Internal only — recipients never see this.');
        echo $this->field('subject', 'Subject line', $campaign['subject'], 'Merge tags work here too, e.g. "{{first_name}}, your renewal is due".');
        echo $this->field('preheader', 'Preview text', $campaign['preheader'], 'The grey line inboxes show after the subject. Leave blank and clients pull the first body line.');
        echo '</div><div class="col-md-6">';
        echo $this->field('from_name', 'From name', $campaign['from_name'], 'Falls back to the module default.');
        echo $this->field('from_email', 'From address', $campaign['from_email'], 'Must be on a domain whose SPF/DKIM you control.');
        echo $this->field('reply_to', 'Reply-to', $campaign['reply_to'], 'Where replies land. A monitored address builds trust.');
        echo '</div></div>';
        echo '<button class="btn btn-primary" type="submit">Save details</button>';
        echo '</form>';

        echo '<hr><h5>Available merge tags</h5><div class="ch247m-tags">';
        foreach (Personalizer::TAGS as $tag => $description) {
            echo '<code class="ch247m-tag" title="' . ch247m_h($description) . '">{{' . ch247m_h($tag) . '}}</code> ';
        }
        echo '</div>';
        echo '</div></div></div>';

        /* ---- 3. Audience */
        echo '<div class="tab-pane" id="ch247m-tab-audience"><div class="panel panel-default"><div class="panel-body">';
        echo '<form method="post">' . Csrf::field()
            . '<input type="hidden" name="ch247m_action" value="save_audience">'
            . '<input type="hidden" name="id" value="' . (int) $campaign['id'] . '">';
        echo '<div class="row">';
        echo '<div class="col-md-6"><h5>Send to</h5>';
        echo $this->checkboxGroup('lists[]', ListService::all(), $campaign['audience']['lists'], 'name', 'subscriber_count', 'No lists yet.');
        echo $this->checkboxGroup('segments[]', SegmentService::all(), $campaign['audience']['segments'], 'name', 'cached_count', 'No segments yet.');
        echo '</div>';
        echo '<div class="col-md-6"><h5>Exclude</h5><p class="text-muted small">Anyone here is removed even if they match above.</p>';
        echo $this->checkboxGroup('exclude_lists[]', ListService::all(), $campaign['audience']['exclude_lists'], 'name', 'subscriber_count', 'No lists yet.');
        echo $this->checkboxGroup('exclude_segments[]', SegmentService::all(), $campaign['audience']['exclude_segments'], 'name', 'cached_count', 'No segments yet.');
        echo '</div></div>';
        echo '<button class="btn btn-primary" type="submit">Save audience</button> ';
        echo '</form>';
        echo '<hr><p class="text-muted">Resolving right now: <strong>' . number_format((int) $preflight['audience']['count']) . '</strong> mailable recipients'
            . (isset($preflight['audience']['suppressed']) ? ' (' . (int) $preflight['audience']['suppressed'] . ' suppressed, ' . (int) $preflight['audience']['deduplicated'] . ' duplicates removed)' : '')
            . '.</p>';
        echo '</div></div></div>';

        /* ---- 4. Review & send */
        echo '<div class="tab-pane" id="ch247m-tab-send">';
        echo '<div class="row"><div class="col-md-7">';

        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Pre-flight</strong></div><div class="panel-body">';
        if ($preflight['blockers'] === []) {
            echo '<div class="alert alert-success" style="margin-bottom:10px;"><strong>Clear to send.</strong> All compliance and configuration checks passed.</div>';
        } else {
            echo '<div class="alert alert-danger" style="margin-bottom:10px;"><strong>Cannot send yet:</strong><ul style="margin:8px 0 0 18px;">';
            foreach ($preflight['blockers'] as $b) {
                echo '<li>' . ch247m_h($b) . '</li>';
            }
            echo '</ul></div>';
        }
        if ($preflight['warnings'] !== []) {
            echo '<div class="alert alert-warning" style="margin-bottom:10px;"><strong>Worth checking:</strong><ul style="margin:8px 0 0 18px;">';
            foreach ($preflight['warnings'] as $w) {
                echo '<li>' . ch247m_h($w) . '</li>';
            }
            echo '</ul></div>';
        }
        echo '<h5>Content check <span class="text-muted small">(advisory)</span></h5><ul class="small">';
        foreach ($preflight['content']['notes'] as $note) {
            echo '<li>' . ch247m_h($note) . '</li>';
        }
        echo '</ul>';
        echo '</div></div>';

        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Send</strong></div><div class="panel-body">';
        echo '<form method="post" style="margin-bottom:14px;">' . Csrf::field()
            . '<input type="hidden" name="ch247m_action" value="build_audience">'
            . '<input type="hidden" name="id" value="' . (int) $campaign['id'] . '">'
            . '<button class="btn btn-default" type="submit">Freeze audience now</button> '
            . '<span class="text-muted small">Optional — sending does this for you. Useful to see the exact list first.</span>'
            . '</form>';

        $disabled = $preflight['blockers'] !== [] ? ' disabled' : '';
        echo '<form method="post" class="form-inline" style="margin-bottom:14px;" onsubmit="return confirm(\'Send this campaign to '
            . number_format((int) $preflight['audience']['count']) . ' recipients now? This cannot be undone.\');">' . Csrf::field()
            . '<input type="hidden" name="ch247m_action" value="send_campaign">'
            . '<input type="hidden" name="id" value="' . (int) $campaign['id'] . '">'
            . '<button class="btn btn-danger" type="submit"' . $disabled . '>Send now to ' . number_format((int) $preflight['audience']['count']) . ' recipients</button>'
            . '</form>';

        echo '<form method="post" class="form-inline">' . Csrf::field()
            . '<input type="hidden" name="ch247m_action" value="schedule_campaign">'
            . '<input type="hidden" name="id" value="' . (int) $campaign['id'] . '">'
            . '<input class="form-control" type="datetime-local" name="scheduled_at" required> '
            . '<select class="form-control" name="timezone">';
        $currentTz = CampaignService::safeTimezone($campaign['timezone']);
        foreach ($this->commonTimezones($currentTz) as $tz) {
            echo '<option value="' . ch247m_h($tz) . '"' . ($tz === $currentTz ? ' selected' : '') . '>' . ch247m_h($tz) . '</option>';
        }
        echo '</select> <button class="btn btn-primary" type="submit"' . $disabled . '>Schedule</button>'
            . '</form>';
        echo '</div></div>';

        echo '</div><div class="col-md-5">';

        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Send a test</strong></div><div class="panel-body">';
        echo '<form method="post">' . Csrf::field()
            . '<input type="hidden" name="ch247m_action" value="send_test">'
            . '<input type="hidden" name="id" value="' . (int) $campaign['id'] . '">'
            . '<div class="form-group" style="width:100%;"><input class="form-control" type="text" name="test_emails" placeholder="you@example.com, colleague@example.com"></div>'
            . '<button class="btn btn-default" type="submit">Queue test send</button>'
            . '</form>';
        echo '<p class="text-muted small" style="margin:10px 0 0;">Tests render with a real recipient\'s merge data and are prefixed <code>[TEST]</code>. Tracking links point at a dummy token.</p>';
        echo '</div></div>';

        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Save as template</strong></div><div class="panel-body">';
        echo '<form method="post" class="form-inline">' . Csrf::field()
            . '<input type="hidden" name="ch247m_action" value="save_as_template">'
            . '<input type="hidden" name="id" value="' . (int) $campaign['id'] . '">'
            . '<input class="form-control" type="text" name="template_name" value="' . ch247m_h($campaign['name']) . '"> '
            . '<button class="btn btn-default" type="submit">Save</button>'
            . '</form></div></div>';

        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Preview</strong></div><div class="panel-body">';
        echo '<a class="btn btn-default btn-block" href="' . $this->url('preview', ['id' => (int) $campaign['id']]) . '" target="_blank">Open rendered preview in a new tab</a>';
        echo '</div></div>';

        echo '</div></div></div>';

        echo '</div>'; // tab-content
    }

    /** The drag-and-drop editor shell. All interaction lives in builder.js. */
    protected function renderBuilder(array $campaign)
    {
        $manifest = BlockLibrary::manifest();
        $manifest['tags'] = Personalizer::TAGS;

        echo '<div class="ch247m-builder" '
            . 'data-manifest="' . ch247m_h(ch247m_json($manifest)) . '" '
            . 'data-design="' . ch247m_h(ch247m_json($campaign['design'])) . '">';

        echo '<div class="ch247m-builder-bar">'
            . '<span class="ch247m-builder-status" data-role="status">Ready</span>'
            . '<div class="pull-right">'
            . '<button type="button" class="btn btn-xs btn-default" data-role="undo" title="Undo">&#8630; Undo</button> '
            . '<button type="button" class="btn btn-xs btn-default" data-role="toggle-preview">Preview mode</button> '
            . '<button type="button" class="btn btn-xs btn-primary" data-role="save">Save design</button>'
            . '</div><div class="clearfix"></div></div>';

        echo '<div class="ch247m-builder-body">';

        /* Palette */
        echo '<div class="ch247m-palette">';
        echo '<div class="ch247m-palette-tabs">'
            . '<button type="button" class="active" data-palette="content">Content</button>'
            . '<button type="button" data-palette="layout">Layout</button>'
            . '<button type="button" data-palette="settings">Style</button>'
            . '</div>';

        echo '<div class="ch247m-palette-pane active" data-pane="content">';
        foreach ($manifest['blocks'] as $type => $block) {
            echo '<div class="ch247m-chip" draggable="true" data-block="' . ch247m_h($type) . '">'
                . '<i class="fa ' . ch247m_h($block['icon']) . '"></i> ' . ch247m_h($block['label']) . '</div>';
        }
        echo '</div>';

        echo '<div class="ch247m-palette-pane" data-pane="layout">';
        foreach ($manifest['layouts'] as $key => $layout) {
            echo '<div class="ch247m-chip ch247m-chip-layout" draggable="true" data-layout="' . ch247m_h($key) . '">'
                . '<span class="ch247m-layout-icon">';
            foreach ($layout['widths'] as $w) {
                echo '<i style="flex:' . (float) $w . '"></i>';
            }
            echo '</span> ' . ch247m_h($layout['label']) . '</div>';
        }
        echo '</div>';

        echo '<div class="ch247m-palette-pane" data-pane="settings"><div data-role="design-settings"></div></div>';
        echo '</div>'; // palette

        /* Canvas */
        echo '<div class="ch247m-canvas-wrap"><div class="ch247m-canvas" data-role="canvas">'
            . '<div class="ch247m-empty" data-role="empty">Drag a layout or a content block here to begin.</div>'
            . '</div></div>';

        /* Inspector */
        echo '<div class="ch247m-inspector" data-role="inspector">'
            . '<div class="ch247m-inspector-empty">Select a block to edit its content and styling.</div>'
            . '</div>';

        echo '</div>'; // body

        echo '<form method="post" data-role="save-form">' . Csrf::field()
            . '<input type="hidden" name="ch247m_action" value="save_design">'
            . '<input type="hidden" name="id" value="' . (int) $campaign['id'] . '">'
            . '<input type="hidden" name="design" data-role="design-input">'
            . '</form>';

        echo '</div>';
        echo '<script src="' . ch247m_h($this->assetUrl('js/builder.js')) . '"></script>';
    }

    protected function previewPage()
    {
        $campaign = CampaignService::find((int) ($_GET['id'] ?? 0));
        if ($campaign === null) {
            echo '<div class="alert alert-danger">Campaign not found.</div>';
            return;
        }
        $html = CampaignService::preview($campaign);
        echo $this->header('Preview: ' . ch247m_h($campaign['name']), 'Rendered with a real recipient\'s merge data.');
        echo '<p><a class="btn btn-default btn-sm" href="' . $this->url('builder', ['id' => (int) $campaign['id']]) . '">&larr; Back to builder</a></p>';
        echo '<div class="ch247m-preview-frame">';
        echo '<iframe sandbox="allow-same-origin" srcdoc="' . ch247m_h($html) . '"></iframe>';
        echo '</div>';
        echo '<div class="panel panel-default" style="margin-top:14px;"><div class="panel-heading"><strong>Plain-text alternative</strong></div>'
            . '<div class="panel-body"><pre class="ch247m-pre">' . ch247m_h($campaign['text_body']) . '</pre></div></div>';
    }

    protected function templatesPage()
    {
        echo $this->header('Templates', 'Starting points for new campaigns. Shipped templates are read-only — duplicate to edit.');
        echo $this->postButton('reseed_templates', [], 'Refresh shipped templates', 'btn-default', Rbac::MKT_MANAGE);
        echo '<hr>';

        $byCategory = [];
        foreach (TemplateService::all() as $template) {
            $byCategory[$template['category']][] = $template;
        }
        if ($byCategory === []) {
            echo '<div class="alert alert-info">No templates installed. Use "Refresh shipped templates" above.</div>';
            return;
        }
        foreach ($byCategory as $category => $templates) {
            echo '<h4>' . ch247m_h(ucfirst($category)) . '</h4><div class="row">';
            foreach ($templates as $template) {
                echo '<div class="col-md-4"><div class="panel panel-default ch247m-template-card"><div class="panel-body">'
                    . '<h5 style="margin-top:0;">' . ch247m_h($template['name'])
                    . ($template['is_system'] ? ' <span class="label label-default">shipped</span>' : '') . '</h5>'
                    . '<p class="text-muted small">' . ch247m_h($template['description']) . '</p>'
                    . '<form method="post" class="form-inline" style="display:inline;">' . Csrf::field()
                    . '<input type="hidden" name="ch247m_action" value="create_campaign">'
                    . '<input type="hidden" name="template_id" value="' . (int) $template['id'] . '">'
                    . '<input type="hidden" name="name" value="' . ch247m_h($template['name'] . ' — ' . gmdate('j M Y', Clock::time())) . '">'
                    . '<button class="btn btn-xs btn-primary" type="submit">Use</button></form> '
                    . $this->postButton('duplicate_template', ['id' => (int) $template['id']], 'Duplicate', 'btn-default btn-xs', Rbac::MKT_COMPOSE);
                if (!$template['is_system']) {
                    echo ' ' . $this->postButton('archive_template', ['id' => (int) $template['id']], 'Archive', 'btn-default btn-xs', Rbac::MKT_COMPOSE);
                }
                echo '</div></div></div>';
            }
            echo '</div>';
        }
    }

    protected function automationsPage()
    {
        echo $this->header('Automations', 'Event-triggered journeys. A WHMCS event enrols someone; the cron walks them through the steps.');

        echo '<div class="alert alert-info"><strong>How this differs from a campaign.</strong> '
            . 'A campaign is one send to a frozen list. An automation runs per person, starting whenever their trigger fires, '
            . 'so two people can be at completely different steps. Nothing is back-filled: enabling an automation affects '
            . 'future events only.</div>';

        if (Rbac::adminCan(Rbac::MKT_COMPOSE)) {
            echo '<div class="panel panel-default"><div class="panel-heading"><strong>New automation</strong></div><div class="panel-body">';
            echo '<form method="post" class="form-inline">' . Csrf::field()
                . '<input type="hidden" name="ch247m_action" value="create_automation">'
                . '<input class="form-control" type="text" name="name" placeholder="Automation name" required style="min-width:240px;"> '
                . '<select class="form-control" name="trigger_event">';
            foreach (AutomationService::TRIGGERS as $key => $label) {
                echo '<option value="' . ch247m_h($key) . '">' . ch247m_h($label) . '</option>';
            }
            echo '</select> <button class="btn btn-primary" type="submit">Create</button></form></div></div>';
        }

        $automations = AutomationService::all();
        if ($automations === []) {
            echo '<div class="alert alert-info">No automations yet. A good first one: <em>service.activated &rarr; wait 1 day &rarr; send the welcome email</em>.</div>';
            return;
        }
        echo '<table class="table table-striped"><thead><tr><th>Name</th><th>Trigger</th><th>Steps</th><th class="text-right">Enrolled</th><th class="text-right">Completed</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($automations as $a) {
            $steps = AutomationService::steps((int) $a['id']);
            echo '<tr><td><a href="' . $this->url('automation', ['id' => (int) $a['id']]) . '"><strong>' . ch247m_h($a['name']) . '</strong></a>'
                . ($a['description'] !== '' ? '<br><span class="text-muted small">' . ch247m_h(Str::clip($a['description'], 90)) . '</span>' : '') . '</td>'
                . '<td class="small">' . ch247m_h($a['trigger_event']) . '</td>'
                . '<td>' . count($steps) . '</td>'
                . '<td class="text-right">' . number_format((int) $a['enrolled_count']) . '</td>'
                . '<td class="text-right">' . number_format((int) $a['completed_count']) . '</td>'
                . '<td>' . ($a['enabled'] ? '<span class="label label-success">Live</span>' : '<span class="label label-default">Off</span>') . '</td>'
                . '<td class="text-right">' . $this->postButton('toggle_automation', ['id' => (int) $a['id'], 'enable' => $a['enabled'] ? 0 : 1], $a['enabled'] ? 'Disable' : 'Enable', 'btn-default btn-xs', Rbac::MKT_SEND) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    protected function automationPage()
    {
        $automation = AutomationService::find((int) ($_GET['id'] ?? 0));
        if ($automation === null) {
            echo '<div class="alert alert-danger">Automation not found.</div>';
            return;
        }
        $steps = AutomationService::steps((int) $automation['id']);

        echo $this->header(ch247m_h($automation['name']), 'Trigger: ' . ch247m_h(AutomationService::TRIGGERS[$automation['trigger_event']] ?? $automation['trigger_event']));

        echo '<div class="row"><div class="col-md-6"><div class="panel panel-default"><div class="panel-heading"><strong>Settings</strong></div><div class="panel-body">';
        echo '<form method="post">' . Csrf::field()
            . '<input type="hidden" name="ch247m_action" value="save_automation">'
            . '<input type="hidden" name="id" value="' . (int) $automation['id'] . '">';
        echo $this->field('name', 'Name', $automation['name']);
        echo $this->field('description', 'Description', $automation['description']);
        echo '<div class="form-group"><label>Trigger event</label><select class="form-control" name="trigger_event">';
        foreach (AutomationService::TRIGGERS as $key => $label) {
            echo '<option value="' . ch247m_h($key) . '"' . ($automation['trigger_event'] === $key ? ' selected' : '') . '>' . ch247m_h($label) . '</option>';
        }
        echo '</select></div>';
        $filterText = '';
        foreach ($automation['trigger_filter'] as $key => $value) {
            $filterText .= $key . ' = ' . $value . "\n";
        }
        echo '<div class="form-group"><label>Trigger filter</label>'
            . '<textarea class="form-control" rows="3" name="trigger_filter" placeholder="packageid = 14">' . ch247m_h(trim($filterText)) . '</textarea>'
            . '<p class="help-block">One <code>key = value</code> per line. Separate alternatives with commas. Leave blank to fire for every event.</p></div>';
        echo '<div class="checkbox"><label><input type="checkbox" name="reentry_allowed" value="1"' . ($automation['reentry_allowed'] ? ' checked' : '') . '> '
            . 'Allow the same person to go through more than once</label>'
            . '<p class="help-block" style="margin-left:20px;">Off by default. Leave it off for welcome sequences; turn it on for things like renewal reminders.</p></div>';
        echo '<button class="btn btn-primary" type="submit">Save</button> ';
        echo '</form><hr>';
        echo $this->postButton('toggle_automation', ['id' => (int) $automation['id'], 'enable' => $automation['enabled'] ? 0 : 1], $automation['enabled'] ? 'Disable automation' : 'Enable automation', $automation['enabled'] ? 'btn-warning' : 'btn-success', Rbac::MKT_SEND) . ' ';
        echo $this->postButton('delete_automation', ['id' => (int) $automation['id']], 'Delete', 'btn-danger', Rbac::MKT_COMPOSE, 'Delete this automation and all enrollment history?');
        echo '</div></div></div>';

        echo '<div class="col-md-6"><div class="panel panel-default"><div class="panel-heading"><strong>Steps</strong></div>';
        if ($steps === []) {
            echo '<div class="panel-body text-muted">No steps yet. Add a wait, then a send.</div>';
        } else {
            echo '<table class="table" style="margin:0;"><tbody>';
            foreach ($steps as $i => $step) {
                $detail = '';
                if ($step['action'] === 'wait') {
                    $detail = $this->humanDuration((int) $step['wait_seconds']);
                } elseif ($step['action'] === 'send_email') {
                    $campaign = CampaignService::find((int) $step['campaign_id']);
                    $detail = $campaign === null ? '<span class="text-danger">missing campaign</span>' : ch247m_h($campaign['name']);
                } else {
                    $config = json_decode((string) $step['config'], true);
                    $detail = ch247m_h(is_array($config) ? implode(', ', array_map(function ($k, $v) {
                        return $k . '=' . $v;
                    }, array_keys($config), $config)) : '');
                }
                echo '<tr><td style="width:30px;" class="text-muted">' . ($i + 1) . '</td>'
                    . '<td><strong>' . ch247m_h(AutomationService::ACTIONS[$step['action']] ?? $step['action']) . '</strong><br>'
                    . '<span class="text-muted small">' . $detail . '</span></td>'
                    . '<td class="text-right">' . $this->postButton('remove_step', ['step_id' => (int) $step['id'], 'id' => (int) $automation['id']], '✕', 'btn-default btn-xs', Rbac::MKT_COMPOSE) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<div class="panel-body" style="border-top:1px solid #e3e8ee;">';
        echo '<form method="post">' . Csrf::field()
            . '<input type="hidden" name="ch247m_action" value="add_step">'
            . '<input type="hidden" name="id" value="' . (int) $automation['id'] . '">';
        echo '<div class="form-group"><label>Add a step</label><select class="form-control" name="step_action">';
        foreach (AutomationService::ACTIONS as $key => $label) {
            echo '<option value="' . ch247m_h($key) . '">' . ch247m_h($label) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="row"><div class="col-xs-6"><div class="form-group"><label class="small">Wait for</label>'
            . '<input class="form-control" type="number" name="wait_amount" value="1" min="0"></div></div>'
            . '<div class="col-xs-6"><div class="form-group"><label class="small">&nbsp;</label><select class="form-control" name="wait_unit">'
            . '<option value="3600">hours</option><option value="86400" selected>days</option><option value="60">minutes</option>'
            . '</select></div></div></div>';
        echo '<div class="form-group"><label class="small">Campaign to send</label><select class="form-control" name="campaign_id">'
            . '<option value="0">—</option>';
        foreach (CampaignService::search(['type' => 'automation'], 1, 100)['rows'] as $campaign) {
            echo '<option value="' . (int) $campaign['id'] . '">' . ch247m_h($campaign['name']) . '</option>';
        }
        foreach (CampaignService::search(['status' => 'draft'], 1, 100)['rows'] as $campaign) {
            echo '<option value="' . (int) $campaign['id'] . '">' . ch247m_h($campaign['name']) . ' (draft)</option>';
        }
        echo '</select></div>';
        echo '<div class="row"><div class="col-xs-6"><div class="form-group"><label class="small">Tag</label>'
            . '<input class="form-control" type="text" name="tag" placeholder="for tag steps"></div></div>'
            . '<div class="col-xs-6"><div class="form-group"><label class="small">List</label><select class="form-control" name="list_id"><option value="0">—</option>';
        foreach (ListService::all() as $list) {
            echo '<option value="' . (int) $list['id'] . '">' . ch247m_h($list['name']) . '</option>';
        }
        echo '</select></div></div></div>';
        echo '<button class="btn btn-default" type="submit">Add step</button>';
        echo '</form></div></div></div></div>';
    }

    protected function humanDuration($seconds)
    {
        $seconds = (int) $seconds;
        if ($seconds >= 86400) {
            return round($seconds / 86400, 1) . ' day(s)';
        }
        if ($seconds >= 3600) {
            return round($seconds / 3600, 1) . ' hour(s)';
        }
        return max(1, (int) round($seconds / 60)) . ' minute(s)';
    }

    protected function subscribersPage()
    {
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $filters = [
            'status'  => (string) ($_GET['status'] ?? ''),
            'list_id' => (int) ($_GET['list_id'] ?? 0),
            'q'       => (string) ($_GET['q'] ?? ''),
            'tag'     => (string) ($_GET['tag'] ?? ''),
        ];
        $result = SubscriberService::search($filters, $page, 50);
        $counts = SubscriberService::statusCounts();

        echo $this->header('Subscribers', 'Everyone who can receive marketing email, and everyone who no longer can.');

        echo '<div class="row">';
        echo $this->statCard('Subscribed', number_format($counts['subscribed']), 'mailable right now', 'fa-check-circle', $this->url('subscribers', ['status' => 'subscribed']));
        echo $this->statCard('Unconfirmed', number_format($counts['unconfirmed']), 'awaiting double opt-in', 'fa-clock-o', $this->url('subscribers', ['status' => 'unconfirmed']));
        echo $this->statCard('Unsubscribed', number_format($counts['unsubscribed']), 'opted out', 'fa-ban', $this->url('subscribers', ['status' => 'unsubscribed']));
        echo $this->statCard('Bounced', number_format($counts['bounced'] + $counts['suppressed']), 'bounced or suppressed', 'fa-exclamation-triangle', $this->url('subscribers', ['status' => 'bounced']));
        echo '</div>';

        echo '<form method="get" class="form-inline" style="margin-bottom:12px;">';
        echo $this->hiddenRouting('subscribers');
        echo '<input class="form-control" type="text" name="q" value="' . ch247m_h($filters['q']) . '" placeholder="Search email, name, company"> ';
        echo '<select class="form-control" name="status"><option value="">Any status</option>';
        foreach (SubscriberService::STATUSES as $s) {
            echo '<option value="' . ch247m_h($s) . '"' . ($filters['status'] === $s ? ' selected' : '') . '>' . ch247m_h(ucfirst($s)) . '</option>';
        }
        echo '</select> <select class="form-control" name="list_id"><option value="0">Any list</option>';
        foreach (ListService::all() as $list) {
            echo '<option value="' . (int) $list['id'] . '"' . ($filters['list_id'] === (int) $list['id'] ? ' selected' : '') . '>' . ch247m_h($list['name']) . '</option>';
        }
        echo '</select> <button class="btn btn-default" type="submit">Filter</button> ';
        echo '<a class="btn btn-default" href="' . $this->url('import') . '">Import CSV</a> ';
        echo '<a class="btn btn-default" href="' . $this->url('subscriber') . '">Add subscriber</a>';
        echo '</form>';

        if (Rbac::adminCan(Rbac::MKT_AUDIENCE)) {
            echo '<form method="post" style="display:inline;">' . Csrf::field()
                . '<input type="hidden" name="ch247m_action" value="export_subscribers">'
                . '<input type="hidden" name="status" value="' . ch247m_h($filters['status']) . '">'
                . '<input type="hidden" name="list_id" value="' . (int) $filters['list_id'] . '">'
                . '<input type="hidden" name="q" value="' . ch247m_h($filters['q']) . '">'
                . '<button class="btn btn-default btn-sm" type="submit">Export this view as CSV</button></form>';
        }

        if ($result['rows'] === []) {
            echo '<div class="alert alert-info" style="margin-top:12px;">No subscribers match.</div>';
            return;
        }

        echo '<table class="table table-striped" style="margin-top:12px;"><thead><tr>'
            . '<th>Email</th><th>Name</th><th>Status</th><th>Lists</th><th>Consent</th><th>Added</th><th></th></tr></thead><tbody>';
        foreach ($result['rows'] as $s) {
            $lists = count(ListService::listsFor((int) $s['id']));
            echo '<tr><td>' . ch247m_h($s['email'])
                . ((int) $s['client_id'] > 0 ? ' <span class="label label-default" title="Linked WHMCS client">#' . (int) $s['client_id'] . '</span>' : '') . '</td>'
                . '<td>' . ch247m_h(trim($s['first_name'] . ' ' . $s['last_name'])) . '</td>'
                . '<td>' . ch247m_pill($s['status']) . '</td>'
                . '<td>' . $lists . '</td>'
                . '<td class="small text-muted">' . ch247m_h($s['consent_source'] ?: '—') . '<br>' . ch247m_dt($s['consent_at']) . '</td>'
                . '<td class="small text-muted">' . ch247m_dt($s['created_at']) . '</td>'
                . '<td class="text-right"><a class="btn btn-xs btn-default" href="' . $this->url('subscriber', ['id' => (int) $s['id']]) . '">Edit</a></td></tr>';
        }
        echo '</tbody></table>';
        echo $this->pagination('subscribers', $page, $result['total'], 50, $filters);
    }

    protected function subscriberPage()
    {
        $id = (int) ($_GET['id'] ?? 0);
        $subscriber = $id > 0 ? SubscriberService::find($id) : null;
        $isNew = $subscriber === null;
        $memberOf = $isNew ? [] : ListService::listsFor($id);

        echo $this->header($isNew ? 'Add subscriber' : ch247m_h($subscriber['email']), $isNew ? 'Record consent as you add them.' : 'Edit details, list membership and consent.');

        echo '<div class="row"><div class="col-md-7"><div class="panel panel-default"><div class="panel-body">';
        echo '<form method="post">' . Csrf::field()
            . '<input type="hidden" name="ch247m_action" value="save_subscriber">';
        echo $this->field('email', 'Email address', $isNew ? '' : $subscriber['email'], '', 'email', $isNew ? '' : 'readonly');
        echo '<div class="row"><div class="col-md-6">' . $this->field('first_name', 'First name', $isNew ? '' : $subscriber['first_name']) . '</div>'
            . '<div class="col-md-6">' . $this->field('last_name', 'Last name', $isNew ? '' : $subscriber['last_name']) . '</div></div>';
        echo '<div class="row"><div class="col-md-6">' . $this->field('company', 'Company', $isNew ? '' : $subscriber['company']) . '</div>'
            . '<div class="col-md-6">' . $this->field('client_id', 'WHMCS client ID', $isNew ? '' : (string) $subscriber['client_id'], 'Links this subscriber to live WHMCS data for merge tags and segments.') . '</div></div>';
        echo $this->field('tags', 'Tags', $isNew ? '' : implode(', ', (array) $subscriber['tags']), 'Comma separated.');
        echo $this->field('consent_source', 'Consent source', $isNew ? '' : $subscriber['consent_source'], 'Where this permission came from. Required for new records.');

        echo '<div class="form-group"><label>Lists</label><div>';
        foreach (ListService::all() as $list) {
            $checked = in_array((int) $list['id'], $memberOf, true) ? ' checked' : '';
            echo '<label class="checkbox-inline"><input type="checkbox" name="lists[]" value="' . (int) $list['id'] . '"' . $checked . '> ' . ch247m_h($list['name']) . '</label> ';
        }
        echo '</div></div>';
        echo '<button class="btn btn-primary" type="submit">Save subscriber</button> ';
        echo '<a class="btn btn-default" href="' . $this->url('subscribers') . '">Back</a>';
        echo '</form>';
        echo '</div></div></div>';

        echo '<div class="col-md-5">';
        if (!$isNew) {
            echo '<div class="panel panel-default"><div class="panel-heading"><strong>Status &amp; consent</strong></div><div class="panel-body">';
            echo '<p>Status: ' . ch247m_pill($subscriber['status']) . '</p>';
            echo '<ul class="list-unstyled small text-muted">';
            echo '<li>Consent recorded: ' . ch247m_dt($subscriber['consent_at']) . '</li>';
            echo '<li>Source: ' . ch247m_h($subscriber['consent_source'] ?: 'not recorded') . '</li>';
            echo '<li>Consent IP: ' . ch247m_h($subscriber['consent_ip'] ?: '—') . '</li>';
            echo '<li>Confirmed: ' . ch247m_dt($subscriber['confirmed_at']) . '</li>';
            echo '<li>Last emailed: ' . ch247m_dt($subscriber['last_sent_at']) . '</li>';
            echo '<li>Bounces: ' . (int) $subscriber['bounce_count'] . ' hard, ' . (int) $subscriber['soft_bounce_count'] . ' soft</li>';
            echo '</ul>';
            if ($subscriber['status'] === SubscriberService::STATUS_SUBSCRIBED) {
                echo $this->postButton('unsubscribe_subscriber', ['id' => $id], 'Unsubscribe', 'btn-warning', Rbac::MKT_AUDIENCE, 'Unsubscribe and suppress this address?');
            } else {
                echo '<form method="post" class="form-inline">' . Csrf::field()
                    . '<input type="hidden" name="ch247m_action" value="resubscribe_subscriber">'
                    . '<input type="hidden" name="id" value="' . $id . '">'
                    . '<input class="form-control input-sm" type="text" name="consent_source" placeholder="New consent source" required> '
                    . '<button class="btn btn-sm btn-success" type="submit">Re-subscribe</button></form>'
                    . '<p class="text-muted small" style="margin-top:6px;">Re-subscribing needs fresh evidence of permission. It is recorded in the audit log.</p>';
            }
            echo '<hr>';
            echo $this->postButton('delete_subscriber', ['id' => $id], 'Delete permanently (GDPR erasure)', 'btn-danger btn-xs', Rbac::MKT_AUDIENCE, 'Permanently delete this subscriber? The suppression entry is kept so they are never re-added.');
            echo '</div></div>';
        }
        echo '</div></div>';
    }

    protected function importPage()
    {
        echo $this->header('Import subscribers', 'CSV or TSV. The first row must be a header row.');

        echo '<div class="alert alert-info"><strong>Before you import:</strong> you must have permission to email these people. '
            . 'Record where that permission came from — it is stored with every subscriber and is the first thing anyone will ask for if a complaint lands.</div>';

        echo '<div class="panel panel-default"><div class="panel-body">';
        echo '<form method="post" enctype="multipart/form-data">' . Csrf::field()
            . '<input type="hidden" name="ch247m_action" value="import_subscribers">';
        echo '<div class="form-group"><label>File</label><input class="form-control" type="file" name="file" accept=".csv,.tsv,.txt" required>'
            . '<p class="help-block">Excel files: use "Save as CSV" first. Columns named <code>email</code>, <code>first name</code>, <code>last name</code>, <code>company</code>, <code>tags</code> and <code>client id</code> are detected automatically.</p></div>';
        echo $this->field('consent_source', 'Consent source', '', 'Required. E.g. "website signup form", "existing customers (contract clause 7)".');
        echo '<div class="form-group"><label>Add to lists</label><div>';
        foreach (ListService::all() as $list) {
            echo '<label class="checkbox-inline"><input type="checkbox" name="lists[]" value="' . (int) $list['id'] . '"> ' . ch247m_h($list['name']) . '</label> ';
        }
        echo '</div></div>';
        echo '<div class="form-group"><label>Initial status</label><select class="form-control" name="status">'
            . '<option value="subscribed">Subscribed (they already opted in)</option>'
            . '<option value="unconfirmed">Unconfirmed (send them a confirmation first)</option>'
            . '</select></div>';
        echo '<div class="checkbox"><label><input type="checkbox" name="dry_run" value="1" checked> Dry run — report what would happen without writing anything</label></div>';
        echo '<button class="btn btn-primary" type="submit">Upload and process</button>';
        echo '</form></div></div>';

        echo '<p class="text-muted small">Suppressed addresses are always skipped, even on a fresh import. '
            . 'Re-importing the same file updates existing subscribers rather than duplicating them.</p>';
    }

    protected function listsPage()
    {
        echo $this->header('Lists', 'Static groupings that people join and leave.');

        if (Rbac::adminCan(Rbac::MKT_AUDIENCE)) {
            echo '<div class="panel panel-default"><div class="panel-heading"><strong>New list</strong></div><div class="panel-body">';
            echo '<form method="post" class="form-inline">' . Csrf::field()
                . '<input type="hidden" name="ch247m_action" value="create_list">'
                . '<input class="form-control" type="text" name="name" placeholder="List name" required> '
                . '<input class="form-control" type="text" name="description" placeholder="What is it for?" style="min-width:280px;"> '
                . '<label class="checkbox-inline"><input type="checkbox" name="double_optin" value="1"> Double opt-in</label> '
                . '<button class="btn btn-primary" type="submit">Create</button></form></div></div>';
        }

        $lists = ListService::all(true);
        if ($lists === []) {
            echo '<div class="alert alert-info">No lists yet.</div>';
            return;
        }
        echo '<table class="table table-striped"><thead><tr><th>Name</th><th>Description</th><th class="text-right">Mailable</th><th>Opt-in</th><th>Created</th><th></th></tr></thead><tbody>';
        foreach ($lists as $list) {
            echo '<tr' . ($list['archived_at'] ? ' class="text-muted"' : '') . '>'
                . '<td><strong>' . ch247m_h($list['name']) . '</strong>' . ($list['archived_at'] ? ' <span class="label label-default">archived</span>' : '')
                . '<br><span class="text-muted small">' . ch247m_h($list['slug']) . '</span></td>'
                . '<td class="small">' . ch247m_h(Str::clip($list['description'], 120)) . '</td>'
                . '<td class="text-right">' . number_format((int) $list['subscriber_count']) . '</td>'
                . '<td>' . ((int) $list['double_optin'] === 1 ? 'Double' : 'Single') . '</td>'
                . '<td class="small text-muted">' . ch247m_dt($list['created_at']) . '</td>'
                . '<td class="text-right">'
                . '<a class="btn btn-xs btn-default" href="' . $this->url('subscribers', ['list_id' => (int) $list['id']]) . '">View members</a> ';
            if (!$list['archived_at']) {
                echo $this->postButton('archive_list', ['id' => (int) $list['id']], 'Archive', 'btn-default btn-xs', Rbac::MKT_AUDIENCE);
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    protected function segmentsPage()
    {
        echo $this->header('Segments', 'Dynamic audiences. Re-resolved every time a campaign is built — no stale CSV exports.');
        echo '<p><a class="btn btn-primary" href="' . $this->url('segment') . '">New segment</a></p>';

        $segments = SegmentService::all();
        if ($segments === []) {
            echo '<div class="alert alert-info">No segments yet. A segment can query your WHMCS clients directly — '
                . 'for example <em>active clients in Nigeria with a web hosting product who have never bought a VPS</em>.</div>';
            return;
        }
        echo '<table class="table table-striped"><thead><tr><th>Name</th><th>Source</th><th>Rules</th><th class="text-right">Matches</th><th>Checked</th><th></th></tr></thead><tbody>';
        foreach ($segments as $segment) {
            echo '<tr><td><a href="' . $this->url('segment', ['id' => (int) $segment['id']]) . '"><strong>' . ch247m_h($segment['name']) . '</strong></a>'
                . ($segment['description'] !== '' ? '<br><span class="text-muted small">' . ch247m_h(Str::clip($segment['description'], 90)) . '</span>' : '') . '</td>'
                . '<td>' . ($segment['source'] === SegmentService::SOURCE_WHMCS ? '<span class="label label-info">WHMCS clients</span>' : '<span class="label label-default">Subscribers</span>') . '</td>'
                . '<td class="small">' . ch247m_h(Str::clip(SegmentService::describe($segment), 110)) . '</td>'
                . '<td class="text-right">' . number_format((int) $segment['cached_count']) . '</td>'
                . '<td class="small text-muted">' . ch247m_dt($segment['cached_at']) . '</td>'
                . '<td class="text-right">' . $this->postButton('refresh_segment', ['id' => (int) $segment['id']], 'Recount', 'btn-default btn-xs', Rbac::MKT_AUDIENCE) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    protected function segmentPage()
    {
        $id = (int) ($_GET['id'] ?? 0);
        $segment = $id > 0 ? SegmentService::find($id) : null;
        $source = $segment !== null ? $segment['source'] : (string) ($_GET['source'] ?? SegmentService::SOURCE_SUBSCRIBERS);
        $source = in_array($source, SegmentService::SOURCES, true) ? $source : SegmentService::SOURCE_SUBSCRIBERS;
        $fields = SegmentService::fields($source);
        $rules = $segment !== null ? $segment['definition']['rules'] : [];

        echo $this->header($segment === null ? 'New segment' : ch247m_h($segment['name']), 'Rules are combined with AND or OR and resolved live.');

        echo '<div class="btn-group" style="margin-bottom:12px;">';
        foreach ([SegmentService::SOURCE_SUBSCRIBERS => 'Subscribers', SegmentService::SOURCE_WHMCS => 'WHMCS clients'] as $key => $label) {
            $cls = $source === $key ? 'btn-primary' : 'btn-default';
            $href = $this->url('segment', $id > 0 ? ['id' => $id, 'source' => $key] : ['source' => $key]);
            echo '<a class="btn ' . $cls . '" href="' . $href . '">' . ch247m_h($label) . '</a>';
        }
        echo '</div>';

        echo '<div class="panel panel-default"><div class="panel-body">';
        echo '<form method="post">' . Csrf::field()
            . '<input type="hidden" name="ch247m_action" value="save_segment">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<input type="hidden" name="source" value="' . ch247m_h($source) . '">';
        echo $this->field('name', 'Segment name', $segment === null ? '' : $segment['name']);
        echo $this->field('description', 'Description', $segment === null ? '' : $segment['description']);

        echo '<div class="form-group"><label>Match</label> <select class="form-control" name="match" style="width:auto;display:inline-block;">'
            . '<option value="all"' . (($segment['definition']['match'] ?? 'all') === 'all' ? ' selected' : '') . '>ALL rules (AND)</option>'
            . '<option value="any"' . (($segment['definition']['match'] ?? 'all') === 'any' ? ' selected' : '') . '>ANY rule (OR)</option>'
            . '</select></div>';

        echo '<table class="table table-condensed"><thead><tr><th style="width:35%">Field</th><th style="width:25%">Operator</th><th>Value</th></tr></thead><tbody>';
        $rowCount = max(4, count($rules) + 2);
        for ($i = 0; $i < $rowCount; $i++) {
            $rule = $rules[$i] ?? ['field' => '', 'op' => '', 'value' => ''];
            echo '<tr><td><select class="form-control input-sm" name="rule_field[]"><option value="">—</option>';
            foreach ($fields as $key => $spec) {
                echo '<option value="' . ch247m_h($key) . '"' . ($rule['field'] === $key ? ' selected' : '') . '>' . ch247m_h($spec['label']) . '</option>';
            }
            echo '</select></td><td><select class="form-control input-sm" name="rule_op[]">';
            foreach (SegmentService::OPERATORS as $op) {
                echo '<option value="' . ch247m_h($op) . '"' . ($rule['op'] === $op ? ' selected' : '') . '>' . ch247m_h(str_replace('_', ' ', $op)) . '</option>';
            }
            echo '</select></td><td><input class="form-control input-sm" type="text" name="rule_value[]" value="'
                . ch247m_h(is_array($rule['value']) ? implode(', ', $rule['value']) : $rule['value']) . '"></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="text-muted small">Operators that do not apply to the chosen field are ignored on save. '
            . 'Leave a row\'s field blank to skip it.</p>';
        echo '<button class="btn btn-primary" type="submit">Save segment</button> ';
        echo '<a class="btn btn-default" href="' . $this->url('segments') . '">Back</a>';
        echo '</form>';

        if ($segment !== null) {
            echo '<hr>';
            echo $this->postButton('refresh_segment', ['id' => $id], 'Recount now', 'btn-default', Rbac::MKT_AUDIENCE) . ' ';
            echo $this->postButton('delete_segment', ['id' => $id], 'Delete segment', 'btn-danger', Rbac::MKT_AUDIENCE, 'Delete this segment? Campaigns referencing it will lose that audience.');
            echo '<p class="text-muted" style="margin-top:10px;">Currently matches <strong>' . number_format((int) $segment['cached_count'])
                . '</strong> recipient(s), last checked ' . ch247m_dt($segment['cached_at']) . '.</p>';
        }
        echo '</div></div>';

        if ($source === SegmentService::SOURCE_WHMCS) {
            echo '<div class="alert alert-info"><strong>WHMCS-backed segment.</strong> This queries <code>tblclients</code> and related tables live. '
                . 'Clients matched here get a subscriber record created automatically when a campaign is built, so unsubscribe and suppression work the same way.</div>';
        }
    }

    protected function queuePage()
    {
        $stats = QueueService::stats();
        echo $this->header('Delivery queue', 'What the cron worker is about to send, and what went wrong.');

        echo '<div class="row">';
        echo $this->statCard('Pending', number_format($stats['pending']), 'waiting', 'fa-hourglass-half');
        echo $this->statCard('Processing', number_format($stats['processing']), 'claimed by a worker', 'fa-cog');
        echo $this->statCard('Sent', number_format($stats['sent']), 'accepted', 'fa-check');
        echo $this->statCard('Failed', number_format($stats['failed']), 'gave up', 'fa-times');
        echo '</div>';

        echo '<div class="panel panel-default"><div class="panel-body">';
        echo $this->postButton('run_worker', [], 'Run the worker once now', 'btn-primary', Rbac::MKT_SEND);
        echo ' <span class="text-muted small">Normally the cron does this. Useful for testing.</span>';
        echo '<p class="text-muted small" style="margin:12px 0 0;">Add to crontab: '
            . '<code>*/5 * * * * php ' . ch247m_h(CH247M_ROOT) . '/modules/addons/' . ch247m_h(CH247M_MODULE_NAME) . '/cron/' . ch247m_h(CH247M_MODULE_NAME) . '.php --quiet</code></p>';
        echo '</div></div>';

        $rows = Db::query(
            'SELECT q.*, c.name AS campaign_name FROM ' . Db::t('email_queue') . ' q
               LEFT JOIN ' . Db::t('campaigns') . ' c ON c.id = q.campaign_id
           ORDER BY CASE q.status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END, q.id DESC LIMIT 100',
            ['failed', 'pending']
        );
        if ($rows === []) {
            echo '<div class="alert alert-info">The queue is empty.</div>';
            return;
        }
        echo '<table class="table table-striped"><thead><tr><th>To</th><th>Campaign</th><th>Status</th><th class="text-right">Attempts</th><th>Next attempt</th><th>Last error</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td class="small">' . ch247m_h($row['to_email']) . '</td>'
                . '<td class="small">' . ((int) $row['campaign_id'] > 0 ? ch247m_h($row['campaign_name'] ?: '#' . (int) $row['campaign_id']) : '<span class="text-muted">one-off</span>') . '</td>'
                . '<td>' . ch247m_pill($row['status']) . '</td>'
                . '<td class="text-right">' . (int) $row['attempts'] . '</td>'
                . '<td class="small text-muted">' . ch247m_dt($row['available_at']) . '</td>'
                . '<td class="small text-danger">' . ch247m_h(Str::clip($row['last_error'] ?? '', 90)) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    protected function suppressionsPage()
    {
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $result = ComplianceService::search((string) ($_GET['q'] ?? ''), (string) ($_GET['reason'] ?? ''), $page, 50);

        echo $this->header('Suppression list', 'Addresses that can never receive marketing email from this install again.');
        echo '<div class="alert alert-info">This list outranks everything — imports, segments, automations and manual sends. '
            . 'Removing an entry is possible but audited, and should only happen with fresh, documented consent.</div>';

        if (Rbac::adminCan(Rbac::MKT_MANAGE)) {
            echo '<div class="panel panel-default"><div class="panel-body">';
            echo '<form method="post" class="form-inline">' . Csrf::field()
                . '<input type="hidden" name="ch247m_action" value="add_suppression">'
                . '<input class="form-control" type="email" name="email" placeholder="address@example.com" required> '
                . '<input class="form-control" type="text" name="notes" placeholder="Reason / note" style="min-width:260px;"> '
                . '<button class="btn btn-default" type="submit">Suppress address</button></form>';
            echo '</div></div>';
        }

        echo '<form method="get" class="form-inline" style="margin-bottom:12px;">';
        echo $this->hiddenRouting('suppressions');
        echo '<input class="form-control" type="text" name="q" value="' . ch247m_h($_GET['q'] ?? '') . '" placeholder="Search address"> ';
        echo '<select class="form-control" name="reason"><option value="">Any reason</option>';
        foreach (ComplianceService::REASONS as $reason) {
            echo '<option value="' . ch247m_h($reason) . '"' . (($_GET['reason'] ?? '') === $reason ? ' selected' : '') . '>' . ch247m_h(ucwords(str_replace('_', ' ', $reason))) . '</option>';
        }
        echo '</select> <button class="btn btn-default" type="submit">Filter</button></form>';

        if ($result['rows'] === []) {
            echo '<div class="alert alert-success">Nothing suppressed. That is a good sign.</div>';
            return;
        }
        echo '<table class="table table-striped"><thead><tr><th>Address</th><th>Reason</th><th>Source</th><th>When</th><th>Note</th><th></th></tr></thead><tbody>';
        foreach ($result['rows'] as $row) {
            echo '<tr><td>' . ch247m_h($row['email']) . '</td>'
                . '<td>' . ch247m_pill($row['reason']) . '</td>'
                . '<td class="small text-muted">' . ch247m_h($row['source'] ?: '—') . '</td>'
                . '<td class="small text-muted">' . ch247m_dt($row['created_at']) . '</td>'
                . '<td class="small">' . ch247m_h(Str::clip($row['notes'] ?? '', 70)) . '</td>'
                . '<td class="text-right">' . $this->postButton('remove_suppression', ['email' => $row['email']], 'Remove', 'btn-default btn-xs', Rbac::MKT_MANAGE, 'Remove this suppression? Only do this with documented fresh consent.') . '</td></tr>';
        }
        echo '</tbody></table>';
        echo $this->pagination('suppressions', $page, $result['total'], 50, ['q' => $_GET['q'] ?? '', 'reason' => $_GET['reason'] ?? '']);
    }

    protected function settingsPage()
    {
        if (!Rbac::adminCan(Rbac::MKT_MANAGE)) {
            echo '<div class="alert alert-danger">You need the "Module settings" permission to view this page.</div>';
            return;
        }
        $transportCheck = TransportFactory::verify();

        echo $this->header('Settings', 'Sender identity, transport, throughput and compliance.');

        echo '<form method="post">' . Csrf::field() . '<input type="hidden" name="ch247m_action" value="save_settings">';
        foreach (['service_enabled', 'sending_enabled', 'kill_switch', 'track_opens', 'track_clicks', 'require_unsubscribe', 'list_unsubscribe_header', 'double_optin', 'suppress_on_bounce', 'suppress_on_complaint'] as $boolKey) {
            echo '<input type="hidden" name="ch247m_bools[]" value="' . ch247m_h($boolKey) . '">';
        }

        /* Master switches */
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Master switches</strong></div><div class="panel-body">';
        echo $this->toggle('sending_enabled', 'Sending enabled', 'Off means campaigns can be built and queued but nothing leaves. This is the setting to turn on last.');
        echo $this->toggle('kill_switch', 'Emergency kill switch', 'Immediately stops the worker mid-campaign. Queued messages stay queued.');
        echo '</div></div>';

        /* Sender identity */
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Sender identity &amp; compliance</strong></div><div class="panel-body">';
        echo '<div class="row"><div class="col-md-6">';
        echo $this->setting('from_name', 'Default from name');
        echo $this->setting('from_email', 'Default from address', 'Use a domain whose SPF and DKIM you control. A mismatch here is the single biggest cause of spam foldering.');
        echo $this->setting('reply_to', 'Default reply-to');
        echo $this->setting('company_name', 'Company name', 'Rendered by the {{company_name}} merge tag.');
        echo '</div><div class="col-md-6">';
        echo $this->settingTextarea('physical_address', 'Physical postal address', 'Legally required on marketing email in most jurisdictions. Pre-flight blocks sending without it.');
        echo $this->setting('account_timezone', 'Scheduling timezone', 'Default timezone shown when scheduling.');
        echo '</div></div>';
        echo '<hr>';
        echo $this->toggle('require_unsubscribe', 'Require an unsubscribe link', 'Pre-flight refuses to send marketing email without {{unsubscribe_url}} in the body.');
        echo $this->toggle('list_unsubscribe_header', 'Send List-Unsubscribe headers', 'Adds the RFC 8058 one-click header Gmail and Outlook surface natively. Strongly recommended.');
        echo $this->toggle('double_optin', 'Double opt-in for new subscribers', 'New subscribers start as "unconfirmed" until they click a confirmation link.');
        echo '</div></div>';

        /* Transport */
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Delivery transport</strong></div><div class="panel-body">';
        echo '<div class="' . ($transportCheck['ok'] ? 'alert alert-success' : 'alert alert-warning') . '">'
            . '<strong>' . ch247m_h($transportCheck['driver']) . ':</strong> ' . ch247m_h($transportCheck['message'] ?: ($transportCheck['ok'] ? 'Ready.' : 'Not usable.')) . '</div>';

        echo '<div class="form-group"><label>Transport</label><select class="form-control" name="transport">';
        $current = Settings::string('transport', 'whmcs');
        foreach (TransportFactory::LABELS as $key => $label) {
            echo '<option value="' . ch247m_h($key) . '"' . ($current === $key ? ' selected' : '') . '>' . ch247m_h($label) . '</option>';
        }
        echo '</select><p class="help-block">The WHMCS option reuses whatever Setup &rarr; General Settings &rarr; Mail is configured with — no new credentials. '
            . 'A dedicated provider gives you bounce and complaint webhooks, which WHMCS cannot.</p></div>';

        echo '<div class="row"><div class="col-md-6"><h5>Dedicated SMTP</h5>';
        echo $this->setting('smtp_host', 'Host');
        echo $this->setting('smtp_port', 'Port');
        echo '<div class="form-group"><label>Encryption</label><select class="form-control" name="smtp_encryption">';
        foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'Implicit TLS (465)', 'none' => 'None (25)'] as $key => $label) {
            echo '<option value="' . ch247m_h($key) . '"' . (Settings::string('smtp_encryption', 'tls') === $key ? ' selected' : '') . '>' . ch247m_h($label) . '</option>';
        }
        echo '</select></div>';
        echo $this->setting('smtp_username', 'Username');
        echo $this->secretSetting('smtp_password', 'Password');
        echo '</div><div class="col-md-6"><h5>Provider HTTP API</h5>';
        echo '<div class="form-group"><label>Provider</label><select class="form-control" name="provider">'
            . '<option value="">— choose —</option>';
        foreach (['sendgrid' => 'SendGrid', 'mailgun' => 'Mailgun', 'postmark' => 'Postmark', 'generic' => 'Generic JSON relay'] as $key => $label) {
            echo '<option value="' . ch247m_h($key) . '"' . (Settings::string('provider', '') === $key ? ' selected' : '') . '>' . ch247m_h($label) . '</option>';
        }
        echo '</select></div>';
        echo $this->secretSetting('provider_api_key', 'API key');
        echo $this->setting('provider_region', 'Region / sending domain / stream', 'Mailgun: your sending domain. Postmark: the message stream. Others: leave blank.');
        echo $this->setting('provider_endpoint', 'Custom endpoint', 'Leave blank to use the provider default.');
        echo '</div></div>';

        echo '<div class="alert alert-warning" style="margin-top:10px;"><strong>About stored credentials.</strong> '
            . 'Secrets typed here are sealed with a key derived from your WHMCS encryption hash before being stored. '
            . 'That protects a database-only leak, but someone with both the database and <code>configuration.php</code> can still read them. '
            . 'For production, set <code>CH247M_SMTP_PASSWORD</code> / <code>CH247M_PROVIDER_API_KEY</code> as environment variables instead — '
            . 'they take precedence and are never written to the database.</div>';
        echo '</div></div>';

        /* Throughput */
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Throughput &amp; retries</strong></div><div class="panel-body"><div class="row">';
        echo '<div class="col-md-4">';
        echo $this->setting('queue_batch_size', 'Messages per worker run');
        echo $this->setting('send_rate_per_minute', 'Max sends per minute', 'Stay under your provider\'s limit. 0 = unlimited.');
        echo $this->setting('queue_wall_clock_seconds', 'Worker time budget (seconds)', 'Keep below your cron interval so runs never overlap.');
        echo '</div><div class="col-md-4">';
        echo $this->setting('max_attempts', 'Max attempts per message');
        echo $this->setting('retry_base_seconds', 'Retry base delay (seconds)');
        echo $this->setting('retry_max_seconds', 'Retry max delay (seconds)');
        echo '</div><div class="col-md-4">';
        echo $this->setting('hard_bounce_threshold', 'Hard bounces before suppression');
        echo $this->setting('soft_bounce_threshold', 'Soft bounces before suppression');
        echo $this->setting('lock_seconds', 'Worker lock lease (seconds)');
        echo '</div></div>';
        echo $this->toggle('suppress_on_bounce', 'Suppress on bounce');
        echo $this->toggle('suppress_on_complaint', 'Suppress on spam complaint');
        echo '</div></div>';

        /* Tracking & retention */
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Tracking &amp; retention</strong></div><div class="panel-body"><div class="row">';
        echo '<div class="col-md-6">';
        echo $this->toggle('track_opens', 'Track opens', 'Injects a 1×1 pixel. Blocked images mean this always undercounts.');
        echo $this->toggle('track_clicks', 'Track clicks', 'Rewrites links through a redirector.');
        echo $this->setting('tracking_base_url', 'Tracking base URL', 'Leave blank to use the WHMCS SystemURL.');
        echo '</div><div class="col-md-6">';
        echo $this->setting('retention_days_events', 'Keep events for (days)');
        echo $this->setting('retention_days_queue', 'Keep finished queue rows for (days)');
        echo '</div></div></div></div>';

        echo '<p><button class="btn btn-primary btn-lg" type="submit">Save settings</button></p>';
        echo '</form>';

        /* Verify + probe */
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Test your setup</strong></div><div class="panel-body">';
        echo $this->postButton('verify_transport', [], 'Verify transport connection', 'btn-default', Rbac::MKT_MANAGE);
        echo ' <form method="post" class="form-inline" style="display:inline;">' . Csrf::field()
            . '<input type="hidden" name="ch247m_action" value="send_probe">'
            . '<input class="form-control" type="email" name="probe_email" placeholder="you@example.com"> '
            . '<button class="btn btn-default" type="submit">Send a probe email</button></form>';
        echo '</div></div>';

        /* Permissions */
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Permissions</strong></div><div class="panel-body">';
        $roles = Whmcs::adminRoles();
        if ($roles === []) {
            echo '<p class="text-muted">Admin roles could not be read from WHMCS.</p>';
        } else {
            echo '<form method="post">' . Csrf::field() . '<input type="hidden" name="ch247m_action" value="save_permissions">';
            echo '<table class="table table-condensed"><thead><tr><th>Role</th>';
            foreach (Rbac::ALL_GROUPS as $group) {
                echo '<th class="small">' . ch247m_h(Rbac::LABELS[$group]) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($roles as $role) {
                $granted = Rbac::groupsForRole($role['id']);
                $isSuper = (int) $role['id'] === Rbac::ROLE_SUPER;
                echo '<tr><td><strong>' . ch247m_h($role['name']) . '</strong>' . ($isSuper ? ' <span class="label label-default">always all</span>' : '') . '</td>';
                foreach (Rbac::ALL_GROUPS as $group) {
                    $checked = in_array($group, $granted, true) ? ' checked' : '';
                    $disabled = $isSuper ? ' disabled' : '';
                    echo '<td class="text-center"><input type="checkbox" name="roles[' . (int) $role['id'] . '][' . ch247m_h($group) . ']" value="1"' . $checked . $disabled . '></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table><button class="btn btn-primary" type="submit">Save permissions</button></form>';
        }
        echo '</div></div>';
    }

    /* ==================================================== view helpers == */

    protected function notices()
    {
        if (isset($_GET['created'])) {
            $this->success = $this->success ?: 'Campaign created. Design it below.';
        }
        if (isset($_GET['saved'])) {
            $this->success = $this->success ?: 'Saved.';
        }
        if (isset($_GET['deleted'])) {
            $this->success = $this->success ?: 'Deleted.';
        }
        if ($this->error !== '') {
            echo '<div class="alert alert-danger">' . ch247m_h($this->error) . '</div>';
        }
        if ($this->success !== '') {
            echo '<div class="alert alert-success">' . ch247m_h($this->success) . '</div>';
        }
        if ($this->notice !== '') {
            echo '<div class="alert alert-warning">' . ch247m_h($this->notice) . '</div>';
        }
    }

    protected function header($title, $subtitle = '')
    {
        return '<div class="ch247m-header"><h3>' . $title . '</h3>'
            . ($subtitle !== '' ? '<p class="text-muted">' . $subtitle . '</p>' : '') . '</div>';
    }

    protected function statCard($label, $value, $sub, $icon, $href = '')
    {
        $inner = '<div class="ch247m-stat"><i class="fa ' . ch247m_h($icon) . '"></i>'
            . '<div class="ch247m-stat-value">' . $value . '</div>'
            . '<div class="ch247m-stat-label">' . ch247m_h($label) . '</div>'
            . '<div class="ch247m-stat-sub">' . ch247m_h($sub) . '</div></div>';
        if ($href !== '') {
            $inner = '<a href="' . $href . '" class="ch247m-stat-link">' . $inner . '</a>';
        }
        return '<div class="col-md-3 col-sm-6">' . $inner . '</div>';
    }

    protected function metric($label, $value, $sub, $tone = '')
    {
        return '<div class="ch247m-metric ' . ($tone !== '' ? 'ch247m-metric-' . ch247m_h($tone) : '') . '">'
            . '<div class="ch247m-metric-value">' . ch247m_h($value) . '</div>'
            . '<div class="ch247m-metric-label">' . ch247m_h($label) . '</div>'
            . '<div class="ch247m-metric-sub">' . ch247m_h($sub) . '</div></div>';
    }

    protected function field($name, $label, $value, $help = '', $type = 'text', $extra = '')
    {
        return '<div class="form-group"><label>' . ch247m_h($label) . '</label>'
            . '<input class="form-control" type="' . ch247m_h($type) . '" name="' . ch247m_h($name) . '" value="' . ch247m_h($value) . '" ' . $extra . '>'
            . ($help !== '' ? '<p class="help-block">' . ch247m_h($help) . '</p>' : '') . '</div>';
    }

    protected function setting($key, $label, $help = '')
    {
        $fromEnv = Settings::fromEnv($key);
        $value = Settings::string($key, '');
        return '<div class="form-group"><label>' . ch247m_h($label) . '</label>'
            . '<input class="form-control" type="text" name="' . ch247m_h($key) . '" value="' . ch247m_h($value) . '"' . ($fromEnv ? ' readonly' : '') . '>'
            . ($fromEnv ? '<p class="help-block text-info">Set by the environment variable CH247M_' . ch247m_h(strtoupper($key)) . ' — edit it there.</p>' : '')
            . ($help !== '' ? '<p class="help-block">' . ch247m_h($help) . '</p>' : '') . '</div>';
    }

    protected function settingTextarea($key, $label, $help = '')
    {
        $fromEnv = Settings::fromEnv($key);
        return '<div class="form-group"><label>' . ch247m_h($label) . '</label>'
            . '<textarea class="form-control" rows="4" name="' . ch247m_h($key) . '"' . ($fromEnv ? ' readonly' : '') . '>' . ch247m_h(Settings::string($key, '')) . '</textarea>'
            . ($help !== '' ? '<p class="help-block">' . ch247m_h($help) . '</p>' : '') . '</div>';
    }

    protected function secretSetting($key, $label)
    {
        $fromEnv = Settings::fromEnv($key);
        $hasValue = Settings::hasSecret($key);
        $placeholder = $fromEnv ? 'Provided by the environment' : ($hasValue ? '•••••••• (leave blank to keep)' : '');
        return '<div class="form-group"><label>' . ch247m_h($label) . '</label>'
            . '<input class="form-control" type="password" autocomplete="new-password" name="' . ch247m_h($key) . '" value="" placeholder="' . ch247m_h($placeholder) . '"' . ($fromEnv ? ' readonly' : '') . '>'
            . ($fromEnv ? '<p class="help-block text-info">Set by CH247M_' . ch247m_h(strtoupper($key)) . '.</p>' : '')
            . '</div>';
    }

    protected function toggle($key, $label, $help = '')
    {
        $checked = Settings::bool($key, false) ? ' checked' : '';
        $fromEnv = Settings::fromEnv($key);
        return '<div class="checkbox"><label><input type="checkbox" name="' . ch247m_h($key) . '" value="1"' . $checked . ($fromEnv ? ' disabled' : '') . '> <strong>' . ch247m_h($label) . '</strong></label>'
            . ($help !== '' ? '<p class="help-block" style="margin-left:20px;">' . ch247m_h($help) . '</p>' : '') . '</div>';
    }

    protected function checkboxGroup($name, array $items, array $selected, $labelKey, $countKey, $emptyText)
    {
        if ($items === []) {
            return '<p class="text-muted small">' . ch247m_h($emptyText) . '</p>';
        }
        $out = '<div class="ch247m-checklist">';
        foreach ($items as $item) {
            $checked = in_array((int) $item['id'], $selected, true) ? ' checked' : '';
            $out .= '<label class="ch247m-check"><input type="checkbox" name="' . ch247m_h($name) . '" value="' . (int) $item['id'] . '"' . $checked . '> '
                . ch247m_h($item[$labelKey]) . ' <span class="text-muted small">(' . number_format((int) $item[$countKey]) . ')</span></label>';
        }
        return $out . '</div>';
    }

    protected function postButton($action, array $params, $label, $class = 'btn-default', $permission = null, $confirm = '')
    {
        if ($permission !== null && !Rbac::adminCan($permission)) {
            return '';
        }
        $onsubmit = $confirm !== '' ? ' onsubmit="return confirm(\'' . ch247m_h(str_replace("'", "\\'", $confirm)) . '\');"' : '';
        $html = '<form method="post" style="display:inline;"' . $onsubmit . '>' . Csrf::field()
            . '<input type="hidden" name="ch247m_action" value="' . ch247m_h($action) . '">';
        foreach ($params as $key => $value) {
            $html .= '<input type="hidden" name="' . ch247m_h($key) . '" value="' . ch247m_h($value) . '">';
        }
        return $html . '<button class="btn ' . ch247m_h($class) . '" type="submit">' . ch247m_h($label) . '</button></form>';
    }

    protected function pagination($action, $page, $total, $perPage, array $params = [])
    {
        $pages = (int) ceil($total / max(1, $perPage));
        if ($pages <= 1) {
            return '';
        }
        $out = '<nav><ul class="pagination pagination-sm">';
        $start = max(1, $page - 3);
        $end = min($pages, $page + 3);
        if ($start > 1) {
            $out .= '<li><a href="' . $this->url($action, $params + ['p' => 1]) . '">1</a></li><li class="disabled"><span>…</span></li>';
        }
        for ($i = $start; $i <= $end; $i++) {
            $out .= '<li' . ($i === $page ? ' class="active"' : '') . '><a href="' . $this->url($action, array_merge($params, ['p' => $i])) . '">' . $i . '</a></li>';
        }
        if ($end < $pages) {
            $out .= '<li class="disabled"><span>…</span></li><li><a href="' . $this->url($action, array_merge($params, ['p' => $pages])) . '">' . $pages . '</a></li>';
        }
        $out .= '</ul> <span class="text-muted small">' . number_format($total) . ' total</span></nav>';
        return $out;
    }

    protected function url($action, array $params = [])
    {
        $url = $this->moduleLink . '&action=' . urlencode($action);
        foreach ($params as $key => $value) {
            if ($value === '' || $value === 0 || $value === null) {
                continue;
            }
            $url .= '&' . urlencode((string) $key) . '=' . urlencode((string) $value);
        }
        return ch247m_h($url);
    }

    /** Hidden inputs that keep WHMCS's own query parameters on a GET form. */
    protected function hiddenRouting($action)
    {
        $out = '';
        $query = [];
        parse_str((string) parse_url($this->moduleLink, PHP_URL_QUERY), $query);
        foreach ($query as $key => $value) {
            $out .= '<input type="hidden" name="' . ch247m_h($key) . '" value="' . ch247m_h((string) $value) . '">';
        }
        return $out . '<input type="hidden" name="action" value="' . ch247m_h($action) . '">';
    }

    protected function assetUrl($path)
    {
        return 'modules/addons/' . CH247M_MODULE_NAME . '/assets/' . ltrim($path, '/') . '?v=' . (defined('CH247M_VERSION') ? CH247M_VERSION : '1');
    }

    protected function commonTimezones($current)
    {
        $common = [
            'UTC', 'Africa/Lagos', 'Africa/Johannesburg', 'Africa/Nairobi', 'Africa/Cairo',
            'Europe/London', 'Europe/Zurich', 'Europe/Berlin', 'Europe/Paris', 'Europe/Moscow',
            'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'America/Sao_Paulo',
            'Asia/Dubai', 'Asia/Karachi', 'Asia/Kolkata', 'Asia/Singapore', 'Asia/Tokyo',
            'Australia/Sydney',
        ];
        if ($current !== '' && !in_array($current, $common, true)) {
            array_unshift($common, $current);
        }
        return $common;
    }

    protected function styles()
    {
        return '<link rel="stylesheet" href="' . ch247m_h($this->assetUrl('css/admin.css')) . '">';
    }
}
