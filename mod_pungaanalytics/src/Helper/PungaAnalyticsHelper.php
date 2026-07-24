<?php

declare(strict_types=1);

namespace Punga\Module\PungaAnalytics\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Punga\Component\PungaAnalytics\Administrator\Service\StatisticsQueryService;

\defined('_JEXEC') or die;

/**
 * Supplies compact Punga Analytics statistics to the administrator dashboard.
 */
final class PungaAnalyticsHelper
{
	/**
	 * Returns the statistics configured for one module instance.
	 *
	 * @param Registry $params Module parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function getStatistics(Registry $params): array
	{
		$app = Factory::getApplication();

		if (!$app->getIdentity()->authorise('core.manage', 'com_pungaanalytics'))
		{
			return [];
		}

		$allowedDays = [7, 30, 90, 365, 0];
		$days = (int) $params->get('days', 7);
		$days = \in_array($days, $allowedDays, true) ? $days : 7;
		$topPagesLimit = (bool) $params->get('show_top_pages', 1)
			? min(10, max(1, (int) $params->get('top_pages_limit', 5)))
			: 0;

		$app->bootComponent('com_pungaanalytics');
		$service = new StatisticsQueryService(
			Factory::getContainer()->get(DatabaseInterface::class),
			(string) $app->get('offset', 'UTC')
		);

		return $service->getModuleData($days, $topPagesLimit);
	}
}
