<?php
/**
 * Download authorisation: version rule (regression for the inverted check that
 * denied the current release and allowed superseded releases).
 * Database-backed cases (limits, expiry, single use) are covered by later suites.
 */
require_once __DIR__ . '/bootstrap.php';

use DigitalProducts\Security\DownloadAuthorizer;

class VersionRuleProbe extends DownloadAuthorizer
{
    public function notAllowed($record) { return $this->versionNotAllowed($record); }
}

$probe = new VersionRuleProbe();
$rec = function ($mode, $version, $current, $purchase = null) {
    return (object) ['access_mode' => $mode, 'version_id' => $version,
                     'current_version_id' => $current, 'purchase_version_id' => $purchase];
};

// current_version mode (default)
DPTest::ok('current release is allowed', $probe->notAllowed($rec('current_version', 12, 12)) === false);
DPTest::ok('superseded release is refused', $probe->notAllowed($rec('current_version', 7, 12)) === true);
DPTest::ok('missing current id fails closed', $probe->notAllowed($rec('current_version', 12, null)) === true);

// purchase_version mode: locked to the release bought
DPTest::ok('purchased release is allowed', $probe->notAllowed($rec('purchase_version', 5, 12, 5)) === false);
DPTest::ok('newer release refused when locked', $probe->notAllowed($rec('purchase_version', 12, 12, 5)) === true);
DPTest::ok('missing purchase id fails closed', $probe->notAllowed($rec('purchase_version', 5, 12, null)) === true);

// String ids from the database must compare numerically.
DPTest::ok('string ids compare as ints', $probe->notAllowed($rec('current_version', '12', '12')) === false);

exit(DPTest::summary());
