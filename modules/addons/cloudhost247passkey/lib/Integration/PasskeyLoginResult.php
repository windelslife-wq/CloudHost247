<?php
/** Internal result for a verified Passkey login handoff. */

namespace CloudHost247\Passkey\Integration;

class PasskeyLoginResult
{
    private $identity;
    private $handoff;

    public function __construct(WhmcsIdentity $identity, WhmcsAuthHandoff $handoff)
    {
        $this->identity = $identity;
        $this->handoff = $handoff;
    }

    public function identity()
    {
        return $this->identity;
    }

    public function handoff()
    {
        return $this->handoff;
    }

    /** Never expose the local account ID or any credential material to the browser. */
    public function toPublicArray()
    {
        return [
            'authenticated' => true,
            'handoff' => $this->handoff->toPublicArray(),
        ];
    }
}
