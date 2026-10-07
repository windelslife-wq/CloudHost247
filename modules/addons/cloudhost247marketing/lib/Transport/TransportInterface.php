<?php
/**
 * One way of getting a Message onto the internet.
 *
 * Implementations must never throw for a delivery failure — they return a
 * Result so the queue can decide about retries. Throwing is reserved for
 * programmer error.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Transport;

interface TransportInterface
{
    /** Stable machine name, stored in delivery logs. */
    public function name();

    /** Is this transport usable right now (credentials present, etc.)? */
    public function isConfigured();

    /** Human explanation of what is missing when isConfigured() is false. */
    public function configurationProblem();

    /** @return Result */
    public function send(Message $message);
}
