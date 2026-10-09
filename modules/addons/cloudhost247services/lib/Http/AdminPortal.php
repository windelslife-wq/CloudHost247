<?php
/**
 * Admin control center.
 *
 * Console sections: overview, TLD catalogue, valuations, auctions, club
 * (plans + memberships), service requests, logo projects, AI builder, inbox
 * and settings. Renders the module's own .phtml views; every mutation is
 * POST+WHMCS-token checked, audited and RBAC-gated on top of WHMCS' own
 * addon-module permission.
 *
 * @package Chs\Http
 */

namespace Chs\Http;

use Chs\Core\Audit;
use Chs\Core\ChsException;
use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\Identity;
use Chs\Core\InvalidTransitionException;
use Chs\Core\Money;
use Chs\Core\NotFoundException;
use Chs\Core\Settings;
use Chs\Core\ValidationException;
use Chs\Services\AiBuilderService;
use Chs\Services\AuctionService;
use Chs\Services\ClubService;
use Chs\Services\DomainManagementService;
use Chs\Services\InboxService;
use Chs\Services\ServiceRequestService;
use Chs\Services\TldCatalogService;
use Chs\Services\TransferService;
use Chs\Services\ValuationService;
use Chs\Providers\Domain\ProviderRegistry;
use Chs\Workflow\DomainJobTypes;
use Chs\Workflow\DomainWorker;
use Chs\Workflow\JobQueue;

class AdminPortal
{
    /** @var array */
    private $vars;

    private $baseLink;

    public function __construct($vars)
    {
        $this->vars = is_array($vars) ? $vars : [];
        $this->baseLink = isset($vars['modulelink']) ? $vars['modulelink'] : 'addonmodules.php?module=cloudhost247services';
    }

    /**
     * @return string full HTML for the WHMCS admin module page
     */
    public function render()
    {
        $staff = Identity::adminId();
        if (!$staff) {
            return '<div class="alert alert-danger">Administrator session required.</div>';
        }

        $action = isset($_GET['action']) ? preg_replace('/[^a-z_]/', '', (string) $_GET['action']) : 'overview';
        if ($action === '') {
            $action = 'overview';
        }

        $notice = isset($_GET['notice']) ? (string) $_GET['notice'] : '';
        $errorBox = '';

        // Mutations
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $this->checkToken();
                $notice = $this->handlePost($action, $staff);
            } catch (ValidationException $e) {
                $errorBox = '<div class="alert alert-danger"><strong>Check the form:</strong><br>'
                    . implode('<br>', array_map('chs_h', $e->fieldErrors())) . '</div>';
            } catch (ChsException $e) {
                $errorBox = '<div class="alert alert-danger">' . chs_h($e->getMessage()) . '</div>';
            } catch (\Throwable $e) {
                \Chs\Core\Logger::error('Admin portal error', ['message' => $e->getMessage()]);
                $errorBox = '<div class="alert alert-danger">Unexpected error: ' . chs_h($e->getMessage()) . '</div>';
            }
        }

        $nav = $this->renderNav($action);
        $body = '';
        try {
            $body = $this->renderSection($action, $staff);
        } catch (ChsException $e) {
            $body = '<div class="alert alert-warning">' . chs_h($e->getMessage()) . '</div>';
        } catch (\Throwable $e) {
            \Chs\Core\Logger::error('Admin render error', ['message' => $e->getMessage(), 'action' => $action]);
            $body = '<div class="alert alert-danger">Could not render this section: ' . chs_h($e->getMessage()) . '</div>';
        }

        $noticeBox = $notice !== ''
            ? '<div class="alert alert-success">' . chs_h($notice) . '</div>' : '';

        return $this->layout($nav . $noticeBox . $errorBox . $body);
    }

    /* ------------------------------------------------------------ mutations -- */

    protected function handlePost($action, $staff)
    {
        $do = isset($_POST['do']) ? (string) $_POST['do'] : '';

        switch ($action) {
            case 'tlds':
                if ($do === 'save_meta') {
                    $service = new TldCatalogService();
                    $service->saveMeta(isset($_POST['tld']) ? $_POST['tld'] : '', [
                        'category'    => isset($_POST['category']) ? $_POST['category'] : '',
                        'region'      => isset($_POST['region']) ? $_POST['region'] : '',
                        'is_featured' => !empty($_POST['is_featured']),
                        'is_popular'  => !empty($_POST['is_popular']),
                        'is_new'      => !empty($_POST['is_new']),
                        'is_trending' => !empty($_POST['is_trending']),
                        'badge'       => isset($_POST['badge']) ? $_POST['badge'] : '',
                        'tagline'     => isset($_POST['tagline']) ? $_POST['tagline'] : '',
                        'sort_order'  => isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 100,
                        'visible'     => !empty($_POST['visible']) ? 1 : 0,
                    ]);
                    Audit::admin($staff, 'tld.meta_saved', ['tld' => isset($_POST['tld']) ? $_POST['tld'] : '']);
                    return 'TLD metadata saved.';
                }
                break;

            case 'auctions':
                $service = new AuctionService();
                if ($do === 'create') {
                    $service->createListing([
                        'domain'        => isset($_POST['domain']) ? $_POST['domain'] : '',
                        'start_minor'   => Money::fromDecimal(isset($_POST['start']) ? $_POST['start'] : '0'),
                        'reserve_minor' => !empty($_POST['reserve']) ? Money::fromDecimal($_POST['reserve']) : null,
                        'bin_minor'     => !empty($_POST['bin']) ? Money::fromDecimal($_POST['bin']) : null,
                        'duration_days' => isset($_POST['duration']) ? (int) $_POST['duration'] : 7,
                        'starts_at'     => !empty($_POST['starts_at']) ? ($_POST['starts_at'] . ' 00:00:00') : '',
                        'description'   => isset($_POST['description']) ? $_POST['description'] : '',
                        'currency'      => isset($_POST['currency']) ? $_POST['currency'] : '',
                    ], null, true);
                    return 'Auction created.';
                }
                if ($do === 'cancel') {
                    $service->adminCancel((int) $_POST['id'], $staff, isset($_POST['reason']) ? (string) $_POST['reason'] : '');
                    return 'Auction cancelled.';
                }
                if ($do === 'transferred') {
                    $service->adminMarkTransferred((int) $_POST['id'], $staff, isset($_POST['note']) ? (string) $_POST['note'] : '');
                    return 'Marked as transferred.';
                }
                if ($do === 'heartbeat') {
                    $result = $service->heartbeat();
                    return 'Heartbeat: ' . $result['opened'] . ' opened, ' . $result['closed'] . ' closed.';
                }
                break;

            case 'club':
                $service = new ClubService();
                if ($do === 'save_plan') {
                    $tlds = [];
                    if (!empty($_POST['tlds']) && is_array($_POST['tlds'])) {
                        foreach ($_POST['tlds'] as $tld => $on) {
                            $tlds[$tld] = null;
                        }
                    }
                    $service->savePlan(isset($_POST['plan_id']) && (int) $_POST['plan_id'] ? (int) $_POST['plan_id'] : null, [
                        'name'             => isset($_POST['name']) ? $_POST['name'] : '',
                        'description'      => isset($_POST['description']) ? $_POST['description'] : '',
                        'price_minor'      => Money::fromDecimal(isset($_POST['price']) ? $_POST['price'] : '0'),
                        'currency'         => isset($_POST['currency']) ? $_POST['currency'] : '',
                        'period_months'    => isset($_POST['period_months']) ? (int) $_POST['period_months'] : 12,
                        'discount_percent' => isset($_POST['discount_percent']) ? $_POST['discount_percent'] : 0,
                        'applies_register' => !empty($_POST['applies_register']),
                        'applies_renew'    => !empty($_POST['applies_renew']),
                        'applies_transfer' => !empty($_POST['applies_transfer']),
                        'max_domains'      => isset($_POST['max_domains']) ? (int) $_POST['max_domains'] : 0,
                        'status'           => isset($_POST['status']) ? $_POST['status'] : 'active',
                        'sort_order'       => isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 100,
                        'tlds'             => $tlds,
                    ], $staff);
                    return 'Plan saved.';
                }
                break;

            case 'requests':
                $service = new ServiceRequestService();
                $requestId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
                if ($do === 'reply' || $do === 'note') {
                    $service->post($requestId, 'admin', $staff,
                        isset($_POST['body']) ? $_POST['body'] : '',
                        isset($_POST['status_to']) ? (string) $_POST['status_to'] : '',
                        $do === 'note');
                    return $do === 'note' ? 'Internal note added.' : 'Reply posted.';
                }
                if ($do === 'quote') {
                    $service->quote($requestId, $staff,
                        Money::fromDecimal(isset($_POST['quote']) ? $_POST['quote'] : '0'),
                        isset($_POST['body']) ? (string) $_POST['body'] : '');
                    return 'Quote published to the customer.';
                }
                if ($do === 'assign') {
                    $service->adminAssign($requestId, $staff, isset($_POST['assignee']) ? (int) $_POST['assignee'] : 0);
                    return 'Assignee updated.';
                }
                break;

            case 'inbox':
                $service = new InboxService();
                if ($do === 'assign') {
                    $service->adminAssign((int) $_POST['ticket_id'], $staff, (int) $_POST['assignee']);
                    return 'Conversation assigned.';
                }
                if ($do === 'status') {
                    $service->adminSetStatus((int) $_POST['ticket_id'], $staff, (string) $_POST['status']);
                    return 'Status updated.';
                }
                if ($do === 'save_label') {
                    $service->saveLabel($staff, isset($_POST['label_id']) ? (int) $_POST['label_id'] : 0,
                        isset($_POST['name']) ? $_POST['name'] : '',
                        isset($_POST['colour']) ? $_POST['colour'] : '');
                    return 'Label saved.';
                }
                if ($do === 'delete_label') {
                    $service->deleteLabel($staff, (int) $_POST['label_id']);
                    return 'Label deleted.';
                }
                if ($do === 'attach_label') {
                    $service->attachLabel($staff, (int) $_POST['ticket_id'], (int) $_POST['label_id']);
                    return 'Label attached.';
                }
                if ($do === 'detach_label') {
                    $service->detachLabel($staff, (int) $_POST['ticket_id'], (int) $_POST['label_id']);
                    return 'Label removed.';
                }
                break;

            case 'payments':
                if (!\Chs\Admin\BlockonomicsFactory::available()) {
                    throw new ChsException('Blockonomics governance layer missing.');
                }
                $bn = \Chs\Admin\BlockonomicsFactory::admin();
                $ipHash = \CloudHost247\Blockonomics\Bridge::ipHash();
                if ($do === 'save_settings') {
                    return $bn->saveSettings([
                        'gateway_enabled' => !empty($_POST['gateway_enabled']),
                        'btc_enabled'     => !empty($_POST['btc_enabled']),
                        'bch_enabled'     => !empty($_POST['bch_enabled']),
                        'usdt_enabled'    => !empty($_POST['usdt_enabled']),
                        'confirmations'   => isset($_POST['confirmations']) ? (int) $_POST['confirmations'] : 2,
                        'usdt_network'    => isset($_POST['usdt_network']) ? (string) $_POST['usdt_network'] : '',
                    ], $staff, $ipHash);
                }
                if ($do === 'replace_api_key') {
                    return $bn->replaceApiKey(isset($_POST['api_key']) ? (string) $_POST['api_key'] : '', $staff, $ipHash);
                }
                if ($do === 'replace_etherscan_key') {
                    return $bn->replaceEtherscanKey(isset($_POST['etherscan_key']) ? (string) $_POST['etherscan_key'] : '', $staff, $ipHash);
                }
                if ($do === 'update_usdt_address') {
                    return $bn->updateUsdtAddress(isset($_POST['usdt_address']) ? (string) $_POST['usdt_address'] : '', $staff, $ipHash);
                }
                if ($do === 'test_connection') {
                    $r = $bn->testConnection($staff, $ipHash);
                    return 'Connection test: ' . $r['label'] . ' [' . $r['category'] . ']';
                }
                throw new ChsException('Unknown payments action.');

            case 'settings':
                if ($do === 'save') {
                    $this->saveSettings($staff);
                    return 'Settings saved.';
                }
                break;

            case 'providers':
                $registry = new ProviderRegistry();
                if ($do === 'save') {
                    $endpoints = isset($_POST['endpoints']) ? (string) $_POST['endpoints'] : '{}';
                    $id = $registry->save(isset($_POST['provider_id']) ? (int) $_POST['provider_id'] : 0, [
                        'name'        => isset($_POST['name']) ? $_POST['name'] : '',
                        'type'        => isset($_POST['type']) ? $_POST['type'] : 'http',
                        'base_url'    => isset($_POST['base_url']) ? $_POST['base_url'] : '',
                        'endpoints'   => $endpoints,
                        'auth_header' => isset($_POST['auth_header']) ? $_POST['auth_header'] : 'Authorization',
                        'auth_prefix' => isset($_POST['auth_prefix']) ? $_POST['auth_prefix'] : 'Bearer ',
                        'token_field' => isset($_POST['token_field']) ? $_POST['token_field'] : 'api_key',
                        'is_enabled'  => !empty($_POST['is_enabled']),
                        'is_default'  => !empty($_POST['is_default']),
                    ], isset($_POST['credentials']) ? (string) $_POST['credentials'] : '');
                    Audit::admin($staff, 'domain.provider_saved', ['provider' => $id]);
                    return 'Provider saved. Credentials are sealed with AES-256-GCM (CHS_CREDENTIALS_KEY) and never displayed.';
                }
                if ($do === 'delete') {
                    $registry->delete((int) $_POST['id']);
                    Audit::admin($staff, 'domain.provider_deleted', ['provider' => (int) $_POST['id']]);
                    return 'Provider deleted.';
                }
                if ($do === 'health') {
                    $result = $registry->healthCheck((int) $_POST['id']);
                    return 'Health: ' . $result['status'] . ' — ' . $result['detail'];
                }
                if ($do === 'map') {
                    $registry->setMapping(isset($_POST['tld']) ? $_POST['tld'] : '', (int) $_POST['provider_id']);
                    Audit::admin($staff, 'domain.provider_mapped', [
                        'tld' => isset($_POST['tld']) ? $_POST['tld'] : '', 'provider' => (int) $_POST['provider_id'],
                    ]);
                    return 'TLD mapping saved.';
                }
                if ($do === 'unmap') {
                    $registry->clearMapping(isset($_POST['tld']) ? $_POST['tld'] : '');
                    return 'TLD mapping removed.';
                }
                break;

            case 'transfers':
                $transfers = new TransferService();
                if ($do === 'retry') {
                    $transfers->adminRetry((int) $_POST['id'], $staff);
                    return 'Transfer submission re-queued.';
                }
                if ($do === 'submitted') {
                    $transfers->adminMarkSubmitted((int) $_POST['id'], $staff, isset($_POST['provider_ref']) ? (string) $_POST['provider_ref'] : '');
                    return 'Transfer marked as submitted.';
                }
                if ($do === 'completed') {
                    $transfers->adminMarkCompleted((int) $_POST['id'], $staff, isset($_POST['note']) ? (string) $_POST['note'] : '');
                    return 'Transfer marked completed.';
                }
                if ($do === 'sync') {
                    $count = $transfers->syncFromPlatform();
                    return 'Platform reconciliation complete: ' . $count . ' transfer(s) updated.';
                }
                break;

            case 'operations':
                $queue = new JobQueue();
                if ($do === 'retry') {
                    $queue->retry((int) $_POST['id']);
                    Audit::admin($staff, 'domain.job_retried', ['job' => (int) $_POST['id']]);
                    return 'Job re-queued.';
                }
                if ($do === 'run') {
                    $result = DomainWorker::run();
                    return 'Worker run: ' . $result['ran'] . ' job(s), ' . $result['completed'] . ' completed, '
                        . $result['failed'] . ' failed.';
                }
                if ($do === 'enqueue_expiration') {
                    $queue->enqueue(DomainJobTypes::EXPIRATION_CHECK, [], ['idempotency_key' => 'manual:expiration:' . Clock::time()]);
                    return 'Expiration check enqueued.';
                }
                if ($do === 'enqueue_sync') {
                    $queue->enqueue(DomainJobTypes::PROVIDER_SYNC, [], ['idempotency_key' => 'manual:sync:' . Clock::time()]);
                    $queue->enqueue(DomainJobTypes::RECONCILIATION, [], ['idempotency_key' => 'manual:recon:' . Clock::time()]);
                    return 'Provider sync + reconciliation enqueued.';
                }
                break;

            case 'domains':
                if ($do === 'sync') {
                    $count = (new DomainManagementService())->syncFromPlatform();
                    Audit::admin($staff, 'domain.sync_run', ['synced' => $count]);
                    return 'Platform sync complete: ' . $count . ' domain record(s) refreshed.';
                }
                if ($do === 'dns_sync') {
                    (new JobQueue())->enqueue(DomainJobTypes::DNS_SYNC, [
                        'domain_service_id' => (int) $_POST['id'],
                    ], ['idempotency_key' => 'dns-sync:' . (int) $_POST['id']]);
                    return 'DNS sync job enqueued.';
                }
                break;

            case 'oses':
                $catalog = new \Chs\Services\OsCatalogService();
                if ($do === 'os_save') {
                    $id = $catalog->saveOs((int) $_POST['os_id'], [
                        'name'                   => isset($_POST['name']) ? $_POST['name'] : '',
                        'slug'                   => isset($_POST['slug']) ? $_POST['slug'] : '',
                        'vendor'                 => isset($_POST['vendor']) ? $_POST['vendor'] : '',
                        'description'            => isset($_POST['description']) ? $_POST['description'] : '',
                        'logo_url'               => isset($_POST['logo_url']) ? $_POST['logo_url'] : '',
                        'status'                 => isset($_POST['status']) ? $_POST['status'] : 'ACTIVE',
                        'sort_order'             => isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0,
                        'is_vps_supported'       => !empty($_POST['is_vps_supported']),
                        'is_dedicated_supported' => !empty($_POST['is_dedicated_supported']),
                        'is_cloud_supported'     => !empty($_POST['is_cloud_supported']),
                        'is_reinstall_supported' => !empty($_POST['is_reinstall_supported']),
                    ]);
                    Audit::admin($staff, 'os.saved', ['os_id' => $id]);
                    return 'Operating system saved. Add versions under "Versions".';
                }
                if ($do === 'os_delete') {
                    $catalog->deleteOs((int) $_POST['id']);
                    Audit::admin($staff, 'os.deleted', ['os_id' => (int) $_POST['id']]);
                    return 'Operating system deleted.';
                }
                if ($do === 'rule_save') {
                    $catalog->saveProductRule((int) $_POST['product_id'], [
                        'server_type' => isset($_POST['server_type']) ? $_POST['server_type'] : 'vps',
                        'provider_id' => (int) (isset($_POST['provider_id']) ? $_POST['provider_id'] : 0),
                        'region_id'   => (int) (isset($_POST['region_id']) ? $_POST['region_id'] : 0),
                    ]);
                    Audit::admin($staff, 'os.product_rule_saved', ['product_id' => (int) $_POST['product_id']]);
                    return 'Product rule saved.';
                }
                if ($do === 'rule_delete') {
                    $catalog->deleteProductRule((int) $_POST['product_id']);
                    Audit::admin($staff, 'os.product_rule_deleted', ['product_id' => (int) $_POST['product_id']]);
                    return 'Product rule removed — every enabled provider/region applies.';
                }
                break;

            case 'osversions':
                $catalog = new \Chs\Services\OsCatalogService();
                $osId = (int) (isset($_POST['os_id']) ? $_POST['os_id'] : $_GET['os_id']);
                if ($do === 'version_save') {
                    $id = $catalog->saveVersion($osId, (int) $_POST['version_id'], [
                        'version'          => isset($_POST['version']) ? $_POST['version'] : '',
                        'display_name'     => isset($_POST['display_name']) ? $_POST['display_name'] : '',
                        'release_name'     => isset($_POST['release_name']) ? $_POST['release_name'] : '',
                        'architectures'    => isset($_POST['architectures']) ? (array) $_POST['architectures'] : ['x86_64'],
                        'status'           => isset($_POST['status']) ? $_POST['status'] : 'ACTIVE',
                        'is_default'       => !empty($_POST['is_default']),
                        'is_lts'           => !empty($_POST['is_lts']),
                        'release_date'     => isset($_POST['release_date']) ? $_POST['release_date'] : '',
                        'end_of_life_date' => isset($_POST['end_of_life_date']) ? $_POST['end_of_life_date'] : '',
                    ]);
                    Audit::admin($staff, 'os.version_saved', ['os_id' => $osId, 'version_id' => $id]);
                    return 'OS version saved.';
                }
                if ($do === 'version_delete') {
                    $catalog->deleteVersion((int) $_POST['id']);
                    Audit::admin($staff, 'os.version_deleted', ['version_id' => (int) $_POST['id']]);
                    return 'OS version deleted.';
                }
                if ($do === 'version_retire') {
                    $catalog->retireVersion((int) $_POST['id']);
                    Audit::admin($staff, 'os.version_retired', ['version_id' => (int) $_POST['id']]);
                    return 'Version retired (EOL) — it no longer appears for new deployments; existing servers keep it.';
                }
                if ($do === 'version_archive') {
                    $catalog->archiveVersion((int) $_POST['id']);
                    Audit::admin($staff, 'os.version_archived', ['version_id' => (int) $_POST['id']]);
                    return 'Version archived.';
                }
                break;

            case 'osimages':
                $catalog = new \Chs\Services\OsCatalogService();
                if ($do === 'image_save') {
                    $id = $catalog->saveImage((int) $_POST['image_id'], [
                        'provider_id'                 => (int) $_POST['provider_id'],
                        'operating_system_version_id' => (int) $_POST['operating_system_version_id'],
                        'provider_image_id'           => isset($_POST['provider_image_id']) ? $_POST['provider_image_id'] : '',
                        'provider_template_id'        => isset($_POST['provider_template_id']) ? $_POST['provider_template_id'] : '',
                        'architecture'                => isset($_POST['architecture']) ? $_POST['architecture'] : 'x86_64',
                        'region_id'                   => (int) (isset($_POST['region_id']) ? $_POST['region_id'] : 0),
                        'metadata'                    => ['notes' => isset($_POST['notes']) ? $_POST['notes'] : ''],
                    ]);
                    Audit::admin($staff, 'os.image_saved', ['image_id' => $id]);
                    return 'Image mapping saved (disabled). Use "Test image", then enable it.';
                }
                if ($do === 'image_delete') {
                    $catalog->deleteImage((int) $_POST['id']);
                    Audit::admin($staff, 'os.image_deleted', ['image_id' => (int) $_POST['id']]);
                    return 'Image mapping deleted.';
                }
                if ($do === 'image_test') {
                    $result = $catalog->testImage((int) $_POST['id']);
                    Audit::admin($staff, 'os.image_tested', ['image_id' => (int) $_POST['id'], 'result' => $result['status']]);
                    return 'Image test: ' . $result['status'] . ' — ' . $result['detail'];
                }
                if ($do === 'image_enable') {
                    $catalog->setImageStatus((int) $_POST['id'], 'active');
                    Audit::admin($staff, 'os.image_enabled', ['image_id' => (int) $_POST['id']]);
                    return 'Image mapping enabled — it can now serve provisioning.';
                }
                if ($do === 'image_disable') {
                    $catalog->setImageStatus((int) $_POST['id'], 'disabled');
                    Audit::admin($staff, 'os.image_disabled', ['image_id' => (int) $_POST['id']]);
                    return 'Image mapping disabled.';
                }
                break;

            case 'infraproviders':
                $registry = new \Chs\Providers\Infrastructure\InfraProviderRegistry();
                if ($do === 'provider_save') {
                    $caps = [];
                    foreach (\Chs\Providers\Infrastructure\InfraProviderRegistry::CAPABILITY_KEYS as $key) {
                        $caps[$key] = !empty($_POST['cap_' . $key]);
                    }
                    $id = $registry->save((int) $_POST['provider_id'], [
                        'name'        => isset($_POST['name']) ? $_POST['name'] : '',
                        'type'        => 'http',
                        'base_url'    => isset($_POST['base_url']) ? $_POST['base_url'] : '',
                        'endpoints'   => isset($_POST['endpoints']) ? $_POST['endpoints'] : '{}',
                        'auth_header' => isset($_POST['auth_header']) ? $_POST['auth_header'] : 'Authorization',
                        'auth_prefix' => isset($_POST['auth_prefix']) ? $_POST['auth_prefix'] : 'Bearer ',
                        'token_field' => isset($_POST['token_field']) ? $_POST['token_field'] : 'api_key',
                        'capabilities' => $caps,
                        'is_enabled'  => !empty($_POST['is_enabled']),
                        'is_default'  => !empty($_POST['is_default']),
                    ], isset($_POST['credentials']) ? (string) $_POST['credentials'] : '');
                    Audit::admin($staff, 'infra.provider_saved', ['provider_id' => $id]);
                    return 'Provider saved. Credentials are sealed with AES-256-GCM (CHS_CREDENTIALS_KEY) and never displayed.';
                }
                if ($do === 'provider_delete') {
                    $registry->delete((int) $_POST['id']);
                    Audit::admin($staff, 'infra.provider_deleted', ['provider_id' => (int) $_POST['id']]);
                    return 'Provider deleted.';
                }
                if ($do === 'provider_health') {
                    $result = $registry->healthCheck((int) $_POST['id']);
                    return 'Health: ' . $result['status'] . ' — ' . $result['detail'];
                }
                if ($do === 'region_save') {
                    $registry->saveRegion((int) $_POST['region_id'], (int) $_POST['provider_id'], [
                        'code'       => isset($_POST['code']) ? $_POST['code'] : '',
                        'name'       => isset($_POST['name']) ? $_POST['name'] : '',
                        'datacenter' => isset($_POST['datacenter']) ? $_POST['datacenter'] : '',
                        'is_active'  => !empty($_POST['is_active']),
                        'sort_order' => isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0,
                    ]);
                    Audit::admin($staff, 'infra.region_saved', ['provider_id' => (int) $_POST['provider_id']]);
                    return 'Region saved.';
                }
                if ($do === 'region_delete') {
                    $registry->deleteRegion((int) $_POST['id']);
                    Audit::admin($staff, 'infra.region_deleted', ['region_id' => (int) $_POST['id']]);
                    return 'Region deleted.';
                }
                break;

            case 'provisioning':
                $provisioning = new \Chs\Services\ServerProvisioningService();
                if ($do === 'job_retry') {
                    $provisioning->adminRetry((int) $_POST['id'], $staff);
                    return 'Provisioning job re-queued.';
                }
                if ($do === 'job_cancel') {
                    $provisioning->adminCancel((int) $_POST['id'], $staff);
                    return 'Provisioning job cancelled.';
                }
                if ($do === 'run') {
                    $result = \Chs\Workflow\Worker::run();
                    return 'Worker run: ' . $result['ran'] . ' job(s), ' . $result['completed'] . ' completed, ' . $result['failed'] . ' failed.';
                }
                break;
        }

        return $notice = isset($_POST['notice']) ? (string) $_POST['notice'] : '';
    }

    protected function saveSettings($staff)
    {
        $booleans = [
            'service_enabled', 'public_pages_enabled', 'valuation_enabled', 'whois_enabled',
            'auction_enabled', 'auction_public_browse', 'auction_client_listing',
            'club_enabled', 'club_allow_registrations', 'club_allow_renewals', 'club_allow_transfers',
            'requests_enabled', 'logo_enabled', 'inbox_enabled', 'ai_enabled',
            'notifications_email', 'notifications_inapp', 'auction_cancel_unpaid_invoices',
            'debug_logging',
            'domain_search_enabled', 'bulk_search_enabled', 'transfer_enabled',
            'domains_dashboard_enabled', 'domain_dns_enabled', 'domain_sync_enabled',
            'server_order_enabled', 'server_provisioning_enabled', 'server_actions_enabled',
            'server_health_check_enabled', 'server_notifications_enabled',
        ];
        $ints = [
            'valuation_guest_daily_limit', 'valuation_client_daily_limit',
            'whois_timeout_seconds', 'whois_cache_minutes', 'whois_daily_limit_per_ip',
            'anti_snipe_window_seconds', 'anti_snipe_extend_seconds', 'anti_snipe_max_extensions',
            'auction_bid_daily_limit', 'auction_invoice_due_days',
            'club_invoice_due_days', 'requests_daily_limit', 'logo_projects_limit',
            'ai_timeout_seconds', 'ai_daily_limit_per_client',
            'lookup_cache_minutes', 'cache_retention_days', 'consent_retention_days', 'audit_retention_days',
            'search_daily_limit_per_client', 'search_daily_limit_per_ip',
            'bulk_max_domains', 'bulk_daily_limit_per_client', 'bulk_daily_limit_per_ip',
            'bulk_chunk_size', 'bulk_sync_threshold',
            'transfer_invoice_due_days', 'domain_auto_renew_lead_days',
            'jobs_per_run', 'jobs_lease_seconds', 'jobs_max_attempts', 'jobs_retention_days',
            'provider_http_timeout_seconds',
        ];
        $strings = [
            'valuation_engine', 'valuation_api_url', 'ai_provider', 'ai_endpoint', 'ai_model',
            'default_currency', 'domain_renewal_notice_days',
        ];

        foreach ($booleans as $key) {
            Settings::put($key, !empty($_POST[$key]) ? '1' : '0');
        }
        foreach ($ints as $key) {
            if (isset($_POST[$key]) && \Chs\Core\Validator::isInt($_POST[$key])) {
                Settings::put($key, (string) max(0, (int) $_POST[$key]));
            }
        }
        foreach ($strings as $key) {
            if (isset($_POST[$key])) {
                $value = trim((string) $_POST[$key]);
                if ($key === 'valuation_engine' && !in_array($value, ['rules', 'http'], true)) {
                    continue;
                }
                if ($key === 'default_currency' && $value !== '' && preg_match('/^[A-Z]{3}$/', strtoupper($value)) !== 1) {
                    continue;
                }
                if (strlen($value) > 500) {
                    continue;
                }
                Settings::put($key, $value);
            }
        }
        Audit::admin($staff, 'settings.saved');
    }

    protected function checkToken()
    {
        if (function_exists('check_token')) {
            check_token('WHMCS.admin.default');
            return;
        }
        // Fallback for environments without WHMCS token plumbing (tests).
        \Chs\Core\Csrf::verifyRequest();
    }


    /* ------------------------------------------------------------ sections -- */

    protected function renderSection($action, $staff)
    {
        switch ($action) {
            case 'overview':
                return $this->view('overview', $this->overviewData());
            case 'tlds':
                return $this->view('tlds', [
                    'rows'      => (new TldCatalogService())->adminList(),
                    'messages'  => [],
                    'baseLink'  => $this->baseLink,
                ]);
            case 'valuations':
                return $this->view('valuations', [
                    'rows'   => (new ValuationService())->recentForAdmin(100),
                    'engine' => (new ValuationService())->engineInfo(),
                ]);
            case 'auctions':
                return $this->view('auctions', [
                    'rows' => (new AuctionService())->adminList(isset($_GET['status']) ? (string) $_GET['status'] : ''),
                    'status' => isset($_GET['status']) ? (string) $_GET['status'] : '',
                    'baseLink' => $this->baseLink,
                ]);
            case 'auction':
                $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
                $service = new AuctionService();
                $row = Db::first('auctions', ['id' => $id]);
                if (!$row) {
                    throw new NotFoundException('Auction not found.');
                }
                return $this->view('auction_detail', [
                    'auction' => $row,
                    'journal' => $service->journalFor($id),
                    'bids'    => Db::all('auction_bids', ['auction_id' => $id], 'id DESC', 200),
                    'invoice' => Db::first('auction_invoices', ['auction_id' => $id]),
                    'baseLink' => $this->baseLink,
                ]);
            case 'club':
                return $this->view('club', [
                    'plans'       => (new ClubService())->allPlans(),
                    'memberships' => Db::all('club_memberships', [], 'id DESC', 100),
                    'baseLink'    => $this->baseLink,
                    'tld_choices' => array_map(function ($r) {
                        return $r['tld'];
                    }, (new TldCatalogService())->adminList()),
                ]);
            case 'requests':
                return $this->view('requests', [
                    'rows'  => (new ServiceRequestService())->adminList(isset($_GET['status']) ? (string) $_GET['status'] : ''),
                    'status' => isset($_GET['status']) ? (string) $_GET['status'] : '',
                    'types' => ServiceRequestService::types(),
                    'baseLink' => $this->baseLink,
                ]);
            case 'request':
                $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
                $service = new ServiceRequestService();
                $detail = $service->detailForAdmin($id);
                return $this->view('request_detail', [
                    'request'  => $detail,
                    'statuses' => \Chs\Workflow\RequestStatus::all(),
                    'admins'   => $this->adminDirectory(),
                    'baseLink' => $this->baseLink,
                ]);
            case 'logos':
                return $this->view('logos', [
                    'rows' => Db::all('logo_projects', [], 'id DESC', 200),
                ]);
            case 'ai':
                return $this->view('ai', [
                    'status' => (new AiBuilderService())->status(),
                    'rows'   => Db::all('ai_generations', [], 'id DESC', 100),
                ]);
            case 'inbox':
                $service = new InboxService();
                return $this->view('inbox', [
                    'rows'    => $service->adminQueue(200, isset($_GET['search']) ? (string) $_GET['search'] : '', isset($_GET['label']) ? (int) $_GET['label'] : 0, !empty($_GET['unassigned'])),
                    'labels'  => $service->labels(),
                    'admins'  => $this->adminDirectory(),
                    'search'  => isset($_GET['search']) ? (string) $_GET['search'] : '',
                    'label_filter' => isset($_GET['label']) ? (int) $_GET['label'] : 0,
                    'statuses' => ['Open', 'Answered', 'Customer-Reply', 'In Progress', 'On Hold', 'Closed'],
                    'baseLink' => $this->baseLink,
                ]);
            case 'payments':
                if (!\Chs\Admin\BlockonomicsFactory::available()) {
                    return '<div class="alert alert-warning">Blockonomics governance layer not found. '
                        . 'Deploy modules/gateways/blockonomics/cloudhost247/ first.</div>';
                }
                $bn = \Chs\Admin\BlockonomicsFactory::admin();
                return $this->view('blockonomics', ['state' => $bn->panelState()]);
            case 'crypto_tx':
                if (!\Chs\Admin\BlockonomicsFactory::available()) {
                    return '<div class="alert alert-warning">Blockonomics governance layer not found.</div>';
                }
                $legacyLoader = function () {
                    if (!function_exists('getGatewayVariables')) {
                        require_once dirname(__DIR__, 4) . '/includes/gatewayfunctions.php';
                    }
                    $params = getGatewayVariables('blockonomics');
                    return is_array($params) ? $params : [];
                };
                $policy = \Chs\Admin\BlockonomicsFactory::displayPolicy($legacyLoader);
                $filters = [
                    'search'       => isset($_GET['search']) ? (string) $_GET['search'] : '',
                    'currency'     => isset($_GET['currency']) ? (string) $_GET['currency'] : '',
                    'network'      => isset($_GET['network']) ? (string) $_GET['network'] : '',
                    'status'       => isset($_GET['status']) ? (string) $_GET['status'] : '',
                    'from'         => isset($_GET['from']) ? (string) $_GET['from'] : '',
                    'to'           => isset($_GET['to']) ? (string) $_GET['to'] : '',
                    'page'         => isset($_GET['page']) ? (int) $_GET['page'] : 1,
                    'confirmations'  => $policy['confirmations'],
                    'time_period_min' => $policy['time_period_min'],
                ];
                $result = \Chs\Admin\BlockonomicsFactory::transactions()->query($filters);
                return $this->view('crypto_tx', [
                    'rows' => $result['rows'], 'total' => $result['total'],
                    'page' => $result['page'], 'pages' => $result['pages'],
                    'filters' => $filters,
                    'statuses' => ['Pending', 'Confirming', 'Paid', 'Failed', 'Expired', 'Cancelled', 'Refunded'],
                ]);
            case 'domains':
                $management = new DomainManagementService();
                return $this->view('domains', [
                    'report'      => $management->reconciliationReport(),
                    'domains'     => Db::all('domain_services', [], 'id DESC', 100),
                    'providers'   => (new ProviderRegistry())->all(),
                    'mappings'    => (new ProviderRegistry())->mappings(),
                    'transfers'   => Db::query(
                        'SELECT COUNT(*) AS c FROM ' . Db::t('domain_transfers') . " WHERE status IN ('PENDING','INITIATED','AWAITING_AUTH_CODE','PROCESSING','PENDING_REGISTRY')"
                    ),
                    'baseLink'    => $this->baseLink,
                ]);
            case 'providers':
                $registry = new ProviderRegistry();
                $editing = null;
                if (isset($_GET['edit'])) {
                    $editing = $registry->find((int) $_GET['edit']);
                }
                return $this->view('providers', [
                    'providers' => $registry->all(),
                    'mappings'  => $registry->mappings(),
                    'editing'   => $editing,
                    'tld_choices' => array_map(function ($r) {
                        return $r['tld'];
                    }, (new TldCatalogService())->adminList()),
                    'baseLink'  => $this->baseLink,
                ]);
            case 'transfers':
                $status = isset($_GET['status']) ? (string) $_GET['status'] : '';
                $service = new TransferService();
                $rows = $service->adminList($status, 100);
                foreach ($rows as &$row) {
                    $row['history'] = json_decode((string) $row['history'], true) ?: [];
                    unset($row['epp_enc']); // never render the sealed credential
                }
                unset($row);
                return $this->view('transfers', [
                    'rows'     => $rows,
                    'status'   => $status,
                    'statuses' => TransferService::statuses(),
                    'baseLink' => $this->baseLink,
                ]);
            case 'operations':
                $queue = new JobQueue();
                $list = $queue->list([
                    'status' => isset($_GET['status']) ? (string) $_GET['status'] : '',
                    'type'   => isset($_GET['type']) ? (string) $_GET['type'] : '',
                    'search' => isset($_GET['search']) ? (string) $_GET['search'] : '',
                ], isset($_GET['page']) ? (int) $_GET['page'] : 1, 50);
                return $this->view('operations', [
                    'jobs'     => $list['rows'],
                    'total'    => $list['total'],
                    'page'     => $list['page'],
                    'pages'    => max(1, (int) ceil($list['total'] / $list['per_page'])),
                    'stats'    => $queue->stats(),
                    'types'    => DomainJobTypes::labels(),
                    'filters'  => [
                        'status' => isset($_GET['status']) ? (string) $_GET['status'] : '',
                        'type'   => isset($_GET['type']) ? (string) $_GET['type'] : '',
                        'search' => isset($_GET['search']) ? (string) $_GET['search'] : '',
                    ],
                    'baseLink' => $this->baseLink,
                ]);
            case 'oses':
                $catalog = new \Chs\Services\OsCatalogService();
                $editing = null;
                if (isset($_GET['edit'])) {
                    $editing = $catalog->findOs((int) $_GET['edit']);
                }
                return $this->view('oses', [
                    'oses'        => $catalog->listAdmin(),
                    'editing'     => $editing,
                    'statuses'    => \Chs\Services\OsCatalogService::OS_STATUSES,
                    'rules'       => $catalog->productRules(),
                    'products'    => \Chs\Core\Platform::gateway()->serverProducts(),
                    'providers'   => (new \Chs\Providers\Infrastructure\InfraProviderRegistry())->all(),
                    'regions'     => (new \Chs\Providers\Infrastructure\InfraProviderRegistry())->activeRegions(),
                    'server_types' => \Chs\Services\OsCatalogService::SERVER_TYPES,
                    'baseLink'    => $this->baseLink,
                ]);
            case 'osversions':
                $catalog = new \Chs\Services\OsCatalogService();
                $osId = isset($_GET['os_id']) ? (int) $_GET['os_id'] : 0;
                $os = $catalog->findOs($osId);
                if (!$os) {
                    throw new NotFoundException('Operating system not found.');
                }
                $editing = null;
                if (isset($_GET['edit'])) {
                    $editing = $catalog->versionRow((int) $_GET['edit']);
                }
                return $this->view('osversions', [
                    'os'         => $os,
                    'versions'   => $catalog->versions($osId),
                    'editing'    => $editing,
                    'statuses'   => \Chs\Services\OsCatalogService::VERSION_STATUSES,
                    'archs'      => \Chs\Services\OsCatalogService::ARCHITECTURES,
                    'images'     => $catalog->images(['os_id' => $osId]),
                    'baseLink'   => $this->baseLink,
                ]);
            case 'osimages':
                $catalog = new \Chs\Services\OsCatalogService();
                $editing = null;
                if (isset($_GET['edit'])) {
                    $editing = $catalog->imageRow((int) $_GET['edit']);
                }
                $registry = new \Chs\Providers\Infrastructure\InfraProviderRegistry();
                return $this->view('osimages', [
                    'images'    => $catalog->images(),
                    'editing'   => $editing,
                    'providers' => $registry->all(),
                    'oses'      => $catalog->listAdmin(),
                    'archs'     => \Chs\Services\OsCatalogService::ARCHITECTURES,
                    'baseLink'  => $this->baseLink,
                ]);
            case 'infraproviders':
                $registry = new \Chs\Providers\Infrastructure\InfraProviderRegistry();
                $editing = null;
                if (isset($_GET['edit'])) {
                    $editing = $registry->find((int) $_GET['edit']);
                }
                $regions = [];
                foreach ($registry->all() as $provider) {
                    $regions[(int) $provider['id']] = $registry->regions((int) $provider['id']);
                }
                return $this->view('infraproviders', [
                    'providers'    => $registry->all(),
                    'regions'      => $regions,
                    'editing'      => $editing,
                    'capabilities' => \Chs\Providers\Infrastructure\InfraProviderRegistry::CAPABILITY_KEYS,
                    'baseLink'     => $this->baseLink,
                ]);
            case 'provisioning':
                $provisioning = new \Chs\Services\ServerProvisioningService();
                $filters = [
                    'status' => isset($_GET['status']) ? (string) $_GET['status'] : '',
                    'type'   => isset($_GET['type']) ? (string) $_GET['type'] : '',
                    'search' => isset($_GET['search']) ? (string) $_GET['search'] : '',
                ];
                $list = $provisioning->listJobs($filters, isset($_GET['page']) ? (int) $_GET['page'] : 1, 50);
                $detail = null;
                if (isset($_GET['id'])) {
                    $detail = $provisioning->jobDetail((int) $_GET['id']);
                }
                return $this->view('provisioning', [
                    'jobs'        => $list['rows'],
                    'total'       => $list['total'],
                    'page'        => $list['page'],
                    'pages'       => max(1, (int) ceil($list['total'] / $list['per_page'])),
                    'stats'       => $provisioning->stats(),
                    'filters'     => $filters,
                    'detail'      => $detail,
                    'statuses'    => array_merge(
                        \Chs\Services\ServerProvisioningService::ACTIVE_STATUSES,
                        \Chs\Services\ServerProvisioningService::TERMINAL_STATUSES
                    ),
                    'types'       => ['PROVISION', 'REINSTALL', 'ACTION'],
                    'servers'     => Db::count('module_servers', ['status' => 'active']),
                    'baseLink'    => $this->baseLink,
                ]);
            case 'audit':
                return $this->view('audit', [
                    'rows' => Audit::recent(200),
                ]);
            case 'settings':
                return $this->view('settings', [
                    'values'   => Settings::editable(),
                    'secretKeys' => Settings::SECRET_KEYS,
                    'baseLink' => $this->baseLink,
                ]);
            case 'overview':
            default:
                return $this->view('overview', $this->overviewData());
        }
    }

    protected function overviewData()
    {
        return [
            'counts' => [
                'active_auctions' => Db::count('auctions', ['status' => 'active']),
                'open_requests'   => Db::query(
                    'SELECT COUNT(*) AS c FROM ' . Db::t('service_requests')
                    . " WHERE status IN ('requested','reviewing','quoted','accepted','in_progress')"
                ) ? (int) Db::query('SELECT COUNT(*) AS c FROM ' . Db::t('service_requests')
                    . " WHERE status IN ('requested','reviewing','quoted','accepted','in_progress')")[0]['c'] : 0,
                'active_memberships' => Db::count('club_memberships', ['status' => 'active']),
                'valuations_today' => Db::query(
                    'SELECT COUNT(*) AS c FROM ' . Db::t('valuations') . ' WHERE created_at >= ?',
                    [Clock::ago(86400)]
                ) ? (int) Db::query('SELECT COUNT(*) AS c FROM ' . Db::t('valuations') . ' WHERE created_at >= ?', [Clock::ago(86400)])[0]['c'] : 0,
                'tlds_merchandised' => Db::count('tld_meta'),
                'ai_generations'     => Db::count('ai_generations'),
                'active_domains'     => Db::tableExists('domain_services')
                    ? Db::count('domain_services', ['status' => 'active']) : 0,
                'expiring_30d'       => Db::tableExists('domain_services')
                    ? (int) (Db::query(
                        'SELECT COUNT(*) AS c FROM ' . Db::t('domain_services')
                        . " WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= ?",
                        [Clock::in(30 * 86400)]
                    )[0]['c'] ?? 0) : 0,
                'open_transfers'     => Db::tableExists('domain_transfers')
                    ? (int) (Db::query(
                        'SELECT COUNT(*) AS c FROM ' . Db::t('domain_transfers')
                        . " WHERE status IN ('PENDING','INITIATED','AWAITING_AUTH_CODE','PROCESSING','PENDING_REGISTRY')"
                    )[0]['c'] ?? 0) : 0,
                'failed_jobs'        => Db::tableExists('jobs')
                    ? Db::count('jobs', ['status' => 'failed']) : 0,
                'searches_24h'       => Db::tableExists('domain_searches')
                    ? (int) (Db::query(
                        'SELECT COUNT(*) AS c FROM ' . Db::t('domain_searches') . ' WHERE created_at >= ?',
                        [Clock::ago(86400)]
                    )[0]['c'] ?? 0) : 0,
                'active_oses'        => Db::tableExists('operating_systems')
                    ? Db::count('operating_systems', ['status' => 'ACTIVE']) : 0,
                'active_os_images'   => Db::tableExists('server_os_images')
                    ? Db::count('server_os_images', ['status' => 'active']) : 0,
                'infra_providers'    => Db::tableExists('infrastructure_providers')
                    ? Db::count('infrastructure_providers', ['is_enabled' => 1]) : 0,
                'active_servers'     => Db::tableExists('module_servers')
                    ? Db::count('module_servers', ['status' => 'active']) : 0,
                'provisioning_failed' => Db::tableExists('provisioning_jobs')
                    ? Db::count('provisioning_jobs', ['status' => 'FAILED']) : 0,
                'provisioning_active' => Db::tableExists('provisioning_jobs')
                    ? (int) (Db::query(
                        'SELECT COUNT(*) AS c FROM ' . Db::t('provisioning_jobs')
                        . " WHERE status IN ('QUEUED','ALLOCATING','CREATING','INSTALLING_OS','CONFIGURING','NETWORK_CONFIGURING','SECURITY_CONFIGURING','HEALTH_CHECK')"
                    )[0]['c'] ?? 0) : 0,
            ],
            'ai_status' => (new AiBuilderService())->status(),
            'engine'    => (new ValuationService())->engineInfo(),
            'providers' => Db::tableExists('domain_providers') ? (new ProviderRegistry())->all() : [],
            'baseLink'  => $this->baseLink,
        ];
    }

    /** id => display name, for assignment pickers. */
    protected function adminDirectory()
    {
        try {
            $rows = Db::query('SELECT id, username, firstname, lastname FROM tbladmins ORDER BY firstname, username');
            $out = [];
            foreach ($rows as $row) {
                $name = trim($row['firstname'] . ' ' . $row['lastname']);
                $out[(int) $row['id']] = $name !== '' ? $name : $row['username'];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /* -------------------------------------------------------------- nav -- */

    protected function renderNav($current)
    {
        $items = [
            'overview'   => 'Overview',
            'domains'    => 'Domains',
            'tlds'       => 'TLD Catalogue',
            'providers'  => 'Domain Providers',
            'transfers'  => 'Transfers',
            'operations' => 'Operations (Jobs)',
            'valuations' => 'Valuations',
            'auctions'   => 'Auctions',
            'club'       => 'Domain Club',
            'requests'   => 'Service Requests',
            'logos'      => 'Logo Projects',
            'ai'         => 'AI Builder',
            'inbox'      => 'Unified Inbox',
            'payments'   => 'Payments · Blockonomics',
            'crypto_tx'  => 'Crypto Transactions',
            'audit'      => 'Audit Log',
            'settings'   => 'Settings',
        ];
        $html = '<ul class="nav nav-tabs chs-admin-nav">';
        foreach ($items as $key => $label) {
            $active = ($key === $current || ($key === 'auctions' && $current === 'auction') || ($key === 'requests' && $current === 'request')) ? ' class="active"' : '';
            $html .= '<li' . $active . '><a href="' . chs_h($this->baseLink . '&action=' . $key) . '">' . chs_h($label) . '</a></li>';
        }
        $html .= '</ul>';
        return $html;
    }

    /* -------------------------------------------------------------- view -- */

    protected function view($template, array $vars)
    {
        $file = dirname(dirname(__DIR__)) . '/templates/admin/' . $template . '.phtml';
        if (!is_file($file)) {
            return '<div class="alert alert-danger">Missing admin view: ' . chs_h($template) . '</div>';
        }
        $vars['baseLink'] = $this->baseLink;
        $vars['tokenField'] = function_exists('generate_token')
            ? generate_token('input') : \Chs\Core\Csrf::field();
        extract($vars, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }

    protected function layout($content)
    {
        $cssPath = '../modules/addons/cloudhost247services/assets/css/admin.css';
        return '<link rel="stylesheet" href="' . $cssPath . '">'
            . '<div class="chs-admin">' . $content . '</div>';
    }
}
