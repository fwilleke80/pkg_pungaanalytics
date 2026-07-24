<?php

declare(strict_types=1);

namespace Punga\Module\PungaAnalytics\Administrator\Dispatcher;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Helper\HelperFactoryAwareInterface;
use Joomla\CMS\Helper\HelperFactoryAwareTrait;

\defined('_JEXEC') or die;

/**
 * Dispatcher for the Punga Analytics administrator module.
 */
final class Dispatcher extends AbstractModuleDispatcher implements HelperFactoryAwareInterface
{
	use HelperFactoryAwareTrait;

	/**
	 * Adds statistics to the module layout data.
	 *
	 * @return array<string, mixed>
	 */
	protected function getLayoutData(): array
	{
		$data = parent::getLayoutData();
		$data['statistics'] = $this->getHelperFactory()
			->getHelper('PungaAnalyticsHelper')
			->getStatistics($data['params']);

		return $data;
	}
}
