<?php

namespace ModulesGarden\ProductsReseller\Server\Smtphosting\Core\DependencyInjection;

/**
 * Class DependencyInjection
 * @package ModulesGarden\ProductsReseller\Server\Smtphosting\Core\DependencyInjection
 */
class DependencyInjection
{
    /**
     * Global debug flag for the module.
     * When enabled, error pages and log output include additional diagnostic detail.
     *
     * @var bool
     */
    public static $isDebug = false;
    /**
     * @param null $className
     * @param null $methodName
     * @param bool $canClone
     * @return mixed
     */
    public static function get($className = null, $methodName = null, $canClone = true)
    {
        if ($methodName)
        {
            return Container::getInstance()->call("$className@$methodName");
        }

        return Container::getInstance()->make($className);
    }

    /**
     * @param null $className
     * @param null $methodName
     * @param bool $canClone
     * @return mixed
     */
    public static function create($className = null, $methodName = null, $canClone = true)
    {
        if ($methodName)
        {
            return Container::getInstance()->call("$className@$methodName");
        }

        return Container::getInstance()->make($className);
    }

    /**
     * @param null $className
     * @param null $methodName
     * @return mixed
     */
    public static function call($className = null, $methodName = null)
    {
        if ($methodName)
        {
            return Container::getInstance()->call("$className@$methodName");
        }

        return Container::getInstance()->make($className);
    }
}