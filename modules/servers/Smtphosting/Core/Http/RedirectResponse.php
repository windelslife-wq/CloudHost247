<?php

namespace ModulesGarden\ProductsReseller\Server\Smtphosting\Core\Http;

use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\Helper\BuildUrl;

/**
 * Description of RedirectResponse
 *
 * @author Rafał Ossowski <rafal.os@modulesgarden.com>
 */
class RedirectResponse extends SymfonyRedirectResponse
{
    protected $lang;

    public function setLang($lang)
    {
        $this->lang = $lang;
        return $this;
    }

    public function getLang()
    {
        return $this->lang;
    }

    /**
     * Create a redirect response to a module controller action.
     *
     * Overrides the parent create() to provide module-aware URL building.
     *
     * @param string|null $controller Controller name
     * @param string|null $action Action name
     * @param array $params Query parameters
     * @param int $status HTTP status code (default 302)
     * @param array $headers Response headers
     * @return static
     */
    public static function create($controller = null, $action = null, array $params = [], $status = 302, $headers = [])
    {
        return new static(BuildUrl::getUrl($controller, $action, $params), $status, $headers);
    }

    /**
     * Create a redirect response to a module controller action.
     *
     * @deprecated Use {@see create()} instead.
     *
     * @param string|null $controller Controller name
     * @param string|null $action Action name
     * @param array $params Query parameters
     * @return static
     */
    public static function createMG($controller = null, $action = null, array $params = [])
    {
        return static::create($controller, $action, $params);
    }

    /**
     * Create a redirect response to an arbitrary URL.
     *
     * @param string $url Target URL
     * @param array $params Query parameters to append
     * @param int $status HTTP status code (default 302)
     * @param array $headers Response headers
     * @return static
     */
    public static function createByUrl($url = '', array $params = [], $status = 302, $headers = [])
    {
        return new static($url . ((count($params) . 0) ? ('?' . http_build_query($params)) : ''), $status, $headers);
    }
}
