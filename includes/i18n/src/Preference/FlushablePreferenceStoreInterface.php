<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * A preference store that holds cookie writes until the request is ready to
 * send them.
 *
 * Cookies can only be set while the response headers are still open, and a
 * preference may be written from deep inside a page render. Stores that
 * implement this queue their writes and are flushed once, by the hook, before
 * any output is produced.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Preference;

use CloudHost247\I18n\Contracts\PreferenceStoreInterface;

interface FlushablePreferenceStoreInterface extends PreferenceStoreInterface
{
    /**
     * Send every queued cookie. Safe to call more than once: the second call
     * has nothing left to send.
     *
     * @return array<string,array<string,mixed>> What was sent (name => details).
     */
    public function flush(): array;
}
