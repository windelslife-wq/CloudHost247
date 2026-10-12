<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Reads the country a *trusted* edge has already put on the request —
 * Cloudflare's `CF-IPCountry`, or whatever header an administrator configures
 * for their own CDN or load balancer.
 *
 * This is the preferred geolocation source when it is available: it adds no
 * latency, needs no outbound call and no API key, and the header is set by the
 * edge rather than by the visitor.
 *
 * It is only ever believed when the connection actually arrived from a
 * trusted proxy. On a direct connection `CF-IPCountry` is just another
 * client-supplied string, and believing it would let any visitor claim any
 * country.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Detection;

final class HeaderGeoLocator implements GeoLocatorInterface
{
    /** Header Cloudflare sets on every request when IP Geolocation is on. */
    const DEFAULT_HEADER = 'CF-IPCountry';

    /** @var string */
    private $header;

    /** @var RequestContext */
    private $request;

    /** @var string */
    private $source;

    /**
     * @param RequestContext $request
     * @param string|null    $header  Header name, e.g. 'CF-IPCountry'.
     */
    public function __construct(RequestContext $request, string $header = null)
    {
        $this->request = $request;
        $this->header = $header !== null && $header !== '' ? $header : self::DEFAULT_HEADER;
        $this->source = 'edge header ' . $this->header;
    }

    /**
     * The IP argument is accepted for interface compatibility but is unused:
     * the header describes the connection as the edge saw it, which is
     * authoritative for this request.
     *
     * @param string $ip
     *
     * @return GeoLocation|null
     */
    public function locate(string $ip)
    {
        if (!$this->request->cameFromTrustedProxy()) {
            return null;
        }

        $value = $this->request->header($this->header);
        if ($value === null) {
            return null;
        }

        // A header the edge set is more trustworthy than a database lookup,
        // but IP geolocation is still IP geolocation: 0.9, not 1.0.
        return GeoLocation::fromValue($value, $this->source, 0.9);
    }

    /**
     * @return string
     */
    public function sourceName(): string
    {
        return $this->source;
    }

    /**
     * @return string
     */
    public function getHeader(): string
    {
        return $this->header;
    }
}
