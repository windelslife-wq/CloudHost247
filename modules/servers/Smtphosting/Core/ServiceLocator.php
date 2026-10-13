<?php

namespace ModulesGarden\ProductsReseller\Server\Smtphosting\Core;

use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\DependencyInjection\DependencyInjection;

/**
 * Class ServiceLocator
 *
 * @package ModulesGarden\ProductsReseller\Server\Smtphosting\Core
 *
 * @deprecated Use {@see DependencyInjection} directly. This class is retained
 *             only as a backward-compatible alias so that the many existing
 *             ServiceLocator::call() / ServiceLocator::create() / ServiceLocator::get()
 *             call-sites across the module continue to resolve.
 *             The $isDebug flag has been moved to {@see DependencyInjection::$isDebug}.
 */
class ServiceLocator extends DependencyInjection
{
}
