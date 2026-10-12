<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * The answer to "which country is this IP in", plus where that answer came
 * from.
 *
 * A GeoLocation is a *suggestion*, never a fact. IP geolocation is wrong for a
 * meaningful share of visitors — VPNs, mobile carriers, corporate egress,
 * satellite links, travel. Everything downstream treats it as one weak signal
 * that a customer can override, and never as something to store against their
 * account or to use for tax, fraud or compliance decisions.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Detection;

final class GeoLocation
{
    /**
     * Values the industry uses for "we could not tell". Cloudflare sends `XX`
     * for unknown and `T1` for Tor exit nodes; several providers send `AP` or
     * `EU` for a region rather than a country. None of them names a country,
     * so none of them may be used.
     */
    const UNKNOWN_CODES = array('XX', 'T1', 'AP', 'EU', 'ZZ', 'A1', 'A2', 'O1');

    /** @var string ISO 3166-1 alpha-2, upper case. */
    private $country;

    /** @var string Where the answer came from, for admin reporting. */
    private $source;

    /** @var float 0..1 — how much the source itself vouches for the answer. */
    private $confidence;

    /** @var int Unix time the answer was produced. */
    private $resolvedAt;

    public function __construct(string $country, string $source = 'unknown', float $confidence = 1.0, int $resolvedAt = null)
    {
        $this->country = strtoupper($country);
        $this->source = $source;
        $this->confidence = max(0.0, min(1.0, $confidence));
        $this->resolvedAt = $resolvedAt !== null ? $resolvedAt : time();
    }

    /**
     * Build from an untrusted value, returning null when it does not name a
     * real country.
     *
     * @param mixed  $value
     * @param string $source
     * @param float  $confidence
     *
     * @return self|null
     */
    public static function fromValue($value, string $source = 'unknown', float $confidence = 1.0)
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $code = strtoupper(trim((string) $value));
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            return null;
        }
        if (in_array($code, self::UNKNOWN_CODES, true)) {
            return null;
        }

        return new self($code, $source, $confidence);
    }

    /**
     * @return string
     */
    public function getCountry(): string
    {
        return $this->country;
    }

    /**
     * @return string
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * @return float
     */
    public function getConfidence(): float
    {
        return $this->confidence;
    }

    /**
     * @return int
     */
    public function getResolvedAt(): int
    {
        return $this->resolvedAt;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'country'     => $this->country,
            'source'      => $this->source,
            'confidence'  => $this->confidence,
            'resolved_at' => $this->resolvedAt,
        );
    }
}
