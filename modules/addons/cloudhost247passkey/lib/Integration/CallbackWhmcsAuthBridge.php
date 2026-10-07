<?php
/** Explicit callback adapter for a deployment's supported WHMCS auth handoff. */

namespace CloudHost247\Passkey\Integration;

class CallbackWhmcsAuthBridge implements WhmcsAuthBridgeInterface
{
    private $handoff;

    /**
     * The callback receives an existing identity object and non-secret login
     * context. It must delegate to WHMCS's own session and 2FA transition.
     */
    public function __construct(callable $handoff)
    {
        $this->handoff = $handoff;
    }

    public function handoff(WhmcsIdentity $identity, PasskeyLoginContext $context)
    {
        $result = call_user_func($this->handoff, $identity, $context);
        if ($result instanceof WhmcsAuthHandoff) {
            return $result;
        }
        if (!is_array($result)) {
            throw new \RuntimeException('WHMCS authentication handoff did not return a valid result.');
        }
        return WhmcsAuthHandoff::fromArray($result);
    }
}
