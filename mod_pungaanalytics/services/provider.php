<?php

declare(strict_types=1);

use Joomla\CMS\Extension\Service\Provider\HelperFactory;
use Joomla\CMS\Extension\Service\Provider\Module;
use Joomla\CMS\Extension\Service\Provider\ModuleDispatcherFactory;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

\defined('_JEXEC') or die;

/**
 * Registers the Punga Analytics administrator module services.
 */
return new class implements ServiceProviderInterface
{
	/**
	 * Registers the module dispatcher, helper factory, and extension.
	 *
	 * @param Container $container Dependency injection container.
	 *
	 * @return void
	 */
	public function register(Container $container): void
	{
		$container->registerServiceProvider(
			new ModuleDispatcherFactory('\\Punga\\Module\\PungaAnalytics')
		);
		$container->registerServiceProvider(
			new HelperFactory('\\Punga\\Module\\PungaAnalytics\\Administrator\\Helper')
		);
		$container->registerServiceProvider(new Module());
	}
};
