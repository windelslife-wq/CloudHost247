<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * A snapshot of exchange rates with the provenance and timestamp needed to
 * show a customer *where* a converted price came from and *how old* it is.
 *
 * Invariant: a rate is either present (and then it is real, sourced from
 * WHMCS's own configured rate feed) or absent. There is no default of 1.0 and
 * no interpolation, because silently treating an unknown rate as parity is
 * exactly how a shop ends up charging the wrong amount.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

final class RateTable
{
    /** Default: re-fetch at most every six hours. */
    const DEFAULT_TTL = 21600;

    /** @var string Currency every rate is expressed against. */
    private $base;

    /** @var array<string,float> Upper-case currency code => rate. */
    private $rates;

    /** @var string Where the numbers came from, e.g. "WHMCS tblcurrencies". */
    private $source;

    /** @var string|null ISO-8601 timestamp of the underlying feed. */
    private $asOf;

    /** @var int Unix time the snapshot was taken. */
    private $retrievedAt;

    /**
     * @param string            $base        Base currency code (rate 1.0).
     * @param array<string,float> $rates     code => rate, base included as 1.0.
     * @param string            $source      Provenance label.
     * @param string|null       $asOf        Feed timestamp (ISO-8601).
     * @param int|null          $retrievedAt Unix time; defaults to now.
     */
    public function __construct(string $base, array $rates, string $source = 'unknown', $asOf = null, $retrievedAt = null)
    {
        $this->base = strtoupper($base);
        $this->source = $source;
        $this->asOf = $asOf !== null && $asOf !== '' ? (string) $asOf : null;
        $this->retrievedAt = $retrievedAt !== null ? (int) $retrievedAt : time();

        $normalised = array();
        foreach ($rates as $code => $rate) {
            $code = strtoupper((string) $code);
            if ($code === '' || $rate === null || $rate === '') {
                continue;
            }
            $value = (float) $rate;
            // Reject the meaningless values WHMCS writes before its first
            // rate update: 0, negatives and non-finite numbers.
            if (!is_finite($value) || $value <= 0) {
                continue;
            }
            $normalised[$code] = $value;
        }
        $normalised[$this->base] = 1.0;
        $this->rates = $normalised;
    }

    public function getBase(): string
    {
        return $this->base;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getAsOf(): ?string
    {
        return $this->asOf;
    }

    public function getRetrievedAt(): int
    {
        return $this->retrievedAt;
    }

    /**
     * @return array<string,float>
     */
    public function getRates(): array
    {
        return $this->rates;
    }

    /**
     * Copy the table with a new "we cached this at" timestamp.
     *
     * The cache stamps what it stores. Measuring the TTL from the source's own
     * retrieved_at would make an already-old table look permanently stale and
     * trigger a fetch on every request.
     *
     * @param int $retrievedAt
     *
     * @return self
     */
    public function withRetrievedAt(int $retrievedAt): self
    {
        return new self($this->base, $this->rates, $this->source, $this->asOf, $retrievedAt);
    }

    /**
     * @param string $code
     *
     * @return bool
     */
    public function has(string $code): bool
    {
        return isset($this->rates[strtoupper($code)]);
    }

    /**
     * @param string $code
     *
     * @return float|null
     */
    public function rate(string $code, string $to = null)
    {
        $code = strtoupper($code);
        if ($to !== null) {
            $to = strtoupper($to);
            return isset($this->rates[$code], $this->rates[$to])
                ? $this->rates[$to] / $this->rates[$code] : null;
        }
        return isset($this->rates[$code]) ? $this->rates[$code] : null;
    }

    /**
     * How old the snapshot is, in seconds.
     *
     * @param int|null $now
     *
     * @return int
     */
    public function age(?int $now = null): int
    {
        return max(0, ($now !== null ? $now : time()) - $this->retrievedAt);
    }

    /**
     * Whether the snapshot is older than the allowed TTL.
     *
     * @param int      $ttl
     * @param int|null $now
     *
     * @return bool
     */
    public function isStale(int $ttl = self::DEFAULT_TTL, ?int $now = null): bool
    {
        return $this->age($now) >= $ttl;
    }

    /**
     * Convert minor units between two currencies.
     *
     * Returns null when either rate is unknown — the caller must then decide
     * to show the price in its own currency rather than invent a number.
     *
     * @param int    $minor
     * @param string $from
     * @param string $to
     *
     * @return int|null
     */
    public function convertMinor(int $minor, string $from, string $to)
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return $minor;
        }
        if (!isset($this->rates[$from], $this->rates[$to])) {
            return null;
        }

        $inBase = $minor / $this->rates[$from];
        $converted = $inBase * $this->rates[$to];

        // Round half away from zero so 0.5 minor units never vanish.
        return (int) ($converted >= 0 ? floor($converted + 0.5) : ceil($converted - 0.5));
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'base' => $this->base,
            'rates' => $this->rates,
            'source' => $this->source,
            'as_of' => $this->asOf,
            'retrieved_at' => $this->retrievedAt,
        );
    }
}
