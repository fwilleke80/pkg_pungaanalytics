<?php

declare(strict_types=1);

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use FrankWilleke\Component\Simplestats\Administrator\Extension\SimplestatsComponent;

\defined('_JEXEC') or die;

return new class implements ServiceProviderInterface
{
	/**
	 * Registers the component services.
	 *
	 * @param Container $container Dependency injection container.
	 *
	 * @return void
	 */
	public function register(Container $container): void
	{
		$container->registerServiceProvider(new MVCFactory('\\FrankWilleke\\Component\\Simplestats'));
		$container->registerServiceProvider(new ComponentDispatcherFactory('\\FrankWilleke\\Component\\Simplestats'));

		$container->set(
			ComponentInterface::class,
			static function (Container $container): ComponentInterface
			{
				$component = new SimplestatsComponent(
					$container->get(ComponentDispatcherFactoryInterface::class)
				);
				$component->setMVCFactory($container->get(MVCFactoryInterface::class));

				return $component;
			}
		);
	}
};
