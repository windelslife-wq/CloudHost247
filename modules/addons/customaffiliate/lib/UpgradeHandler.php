<?php

/**
 * Custom Affiliate Commission - Upgrade/Downgrade Handler
 *
 * This file provides additional handling for package changes
 * that may not be fully captured by the AfterModuleUpgrade/Downgrade hooks.
 * It can be called manually or integrated into custom workflows.
 */

namespace CustomAffiliate;

use WHMCS\Database\Capsule;
use Exception;

class UpgradeHandler
{
    /** @var CommissionManager */
    private $manager;

    public function __construct()
    {
        $this->manager = new CommissionManager();
    }

    /**
     * Process a package change for an existing service.
     *
     * Thin wrapper kept for manual callers: the rules live in
     * CommissionManager::processPackageChange(), which the upgrade/downgrade
     * hooks now run automatically.
     *
     * @param int $serviceId
     * @param int $oldProductId
     * @param int $newProductId
     * @return array Result details
     */
    public function processPackageChange($serviceId, $oldProductId, $newProductId)
    {
        return $this->manager->processPackageChange($serviceId, $oldProductId, $newProductId);
    }

    /**
     * Update notes on commission record
     *
     * @param int $serviceId
     * @param string $note
     * @return void
     */
    private function updateNotes($serviceId, $note)
    {
        try {
            foreach (Capsule::table('mod_customaffiliate_commissions')->where('service_id', $serviceId)->get() as $row) {
                Capsule::table('mod_customaffiliate_commissions')
                    ->where('id', $row->id)
                    ->update(['notes' => CommissionManager::appendNote($row->notes, (string) $note)]);
            }
        } catch (Exception $e) {
            $this->manager->logDebug('Error updating notes', [
                'error' => $e->getMessage(),
                'service_id' => $serviceId,
            ]);
        }
    }

    /**
     * Audit all services and fix commission records
     * Can be called periodically or after bulk product group changes
     *
     * @return array Statistics of fixes applied
     */
    public function auditAllServices()
    {
        $stats = [
            'checked' => 0,
            'fixed' => 0,
            'errors' => [],
        ];

        try {
            $records = Capsule::table('mod_customaffiliate_commissions')->get();

            foreach ($records as $record) {
                $stats['checked']++;
                $serviceId = $record->service_id;

                // Get current service product
                $service = Capsule::table('tblhosting')
                    ->where('id', $serviceId)
                    ->first();

                if (!$service) {
                    // Service no longer exists - mark record
                    $this->updateNotes($serviceId, "Service ID {$serviceId} no longer exists in tblhosting");
                    $stats['fixed']++;
                    continue;
                }

                // Regroup stale records onto the live product (data hygiene
                // only: flags and totals are never touched by the audit).
                if ((int) $record->product_id !== (int) $service->packageid) {
                    Capsule::table('mod_customaffiliate_commissions')
                        ->where('id', $record->id)
                        ->update(['product_id' => (int) $service->packageid]);
                    $this->updateNotes($serviceId, "Audit: regrouped product {$record->product_id} -> {$service->packageid}");
                    $stats['fixed']++;
                }

                // Verify product is still in hosting group
                if (!$this->manager->isWebHostingProduct($service->packageid)) {
                    $this->updateNotes($serviceId, "Audit: Product {$service->packageid} is not in hosting group. Future commissions blocked.");
                    $stats['fixed']++;
                }
            }
        } catch (Exception $e) {
            $stats['errors'][] = $e->getMessage();
        }

        $this->manager->logDebug('Audit completed', $stats);
        return $stats;
    }
}
