<?php

declare(strict_types=1);

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Punga\Plugin\System\PungaAnalytics\Extension\PungaAnalytics;

\defined('_JEXEC') or die;

return new class implements ServiceProviderInterface
{
	/**
	 * Registers the system plugin.
	 *
	 * @param Container $container Dependency injection container.
	 *
	 * @return void
	 */
	public function register(Container $container): void
	{
		$container->set(
			PluginInterface::class,
			static function (Container $container): PluginInterface
			{
				$plugin = new PungaAnalytics(
					$container->get(DispatcherInterface::class),
					(array) PluginHelper::getPlugin('system', 'pungaanalytics')
				);
				$plugin->setApplication(Factory::getApplication());
				$plugin->setDatabase($container->get(DatabaseInterface::class));

				return $plugin;
			}
		);
	}
};
