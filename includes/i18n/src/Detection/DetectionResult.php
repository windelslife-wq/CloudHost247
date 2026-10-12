<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * The outcome of one detection step, with the provenance that makes it
 * explainable to a customer, to support staff and to a test.
 *
 * Carrying the source is not decoration. "We are showing you euros because
 * your account says euros" and "we are showing you euros because an IP lookup
 * guessed Germany" are very different statements, and only one of them is
 * allowed to beat an explicit choice.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Detection;

final class DetectionResult
{
    /** The customer (or their account) asked for this value. Never overridden. */
    const SOURCE_EXPLICIT = 'explicit';

    /** Saved on the customer's account record. */
    const SOURCE_ACCOUNT = 'account';

    /** Saved in the session / cookie from an earlier explicit choice. */
    const SOURCE_STORED = 'stored';

    /** Derived from the browser's Accept-Language header. */
    const SOURCE_BROWSER = 'browser';

    /** Derived from the region subtag of the browser language. */
    const SOURCE_REGION_HINT = 'region_hint';

    /** Derived from IP geolocation plus the country/currency table. */
    const SOURCE_GEOLOCATION = 'geolocation';

    /** Nothing else worked, so the platform default is in use. */
    const SOURCE_DEFAULT = 'default';

    /** @var mixed */
    private $value;

    /** @var string */
    private $source;

    /** @var string Machine-readable reason, stable for tests. */
    private $reason;

    /** @var array<string,mixed> */
    private $evidence;

    /**
     * @param mixed                $value    Locale code, Currency, or null.
     * @param string               $source   One of the SOURCE_* constants.
     * @param string               $reason   Short stable code explaining the outcome.
     * @param array<string,mixed>  $evidence Supporting detail (country, header, …).
     */
    public function __construct($value, string $source, string $reason = '', array $evidence = array())
    {
        $this->value = $value;
        $this->source = $source;
        $this->reason = $reason;
        $this->evidence = $evidence;
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @return string
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * @return string
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * @return array<string,mixed>
     */
    public function getEvidence(): array
    {
        return $this->evidence;
    }

    /**
     * Whether this value may be replaced by a later, weaker signal.
     *
     * An explicit or account value is authoritative; anything detected is a
     * suggestion that a stronger signal may still replace.
     *
     * @return bool
     */
    public function isExplicit(): bool
    {
        return $this->source === self::SOURCE_EXPLICIT
            || $this->source === self::SOURCE_ACCOUNT
            || $this->source === self::SOURCE_STORED;
    }

    /**
     * Whether the value came from automatic detection rather than a choice.
     *
     * @return bool
     */
    public function isDetected(): bool
    {
        return !$this->isExplicit() && $this->source !== self::SOURCE_DEFAULT;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'source'   => $this->source,
            'reason'   => $this->reason,
            'explicit' => $this->isExplicit(),
            'detected' => $this->isDetected(),
            'evidence' => $this->evidence,
        );
    }
}
