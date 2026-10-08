<?php
/**
 * Separate contract for operating control-panel products.
 *
 * This is deliberately not the application deployment AdapterInterface. A
 * control-panel integration owns the panel's account/service API; deploying an
 * application into one of those accounts is a distinct future adapter/workflow.
 *
 * Secrets in $connection are transient worker inputs. Implementations must not
 * persist or log them. The contract intentionally has no panel-install or
 * license-activation method: those capabilities must be added only after their
 * vendor-supported workflows are independently verified.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\ControlPanels;

interface ControlPanelAdapterInterface
{
    /** Stable adapter key, e.g. cpanel_whm. */
    public function key();

    /** Human-readable product name. */
    public function name();

    /** Boolean capability map; only implemented and tested operations are true. */
    public function capabilities();

    /** Verify the authenticated panel API and return vendor-reported version data. */
    public function verify(array $connection);

    /** Read one account by its panel username, or return null only when confirmed absent. */
    public function getAccount(array $connection, $username);

    /** Create or recover the account identified by username/domain/package. */
    public function createAccount(array $connection, array $account, $idempotencyKey);

    /** Suspend an account and read back its state before reporting completion. */
    public function suspendAccount(array $connection, $username, $reason);

    /** Unsuspend an account and read back its state before reporting completion. */
    public function unsuspendAccount(array $connection, $username);

    /** Permanently remove an account; requires a username-bound confirmation phrase. */
    public function terminateAccount(array $connection, $username, $confirmation);
}
