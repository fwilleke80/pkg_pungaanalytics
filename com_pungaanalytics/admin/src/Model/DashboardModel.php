<?php

declare(strict_types=1);

namespace Punga\Component\PungaAnalytics\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Punga\Component\PungaAnalytics\Administrator\Service\StatisticsArchiveService;
use Punga\Component\PungaAnalytics\Administrator\Service\StatisticsQueryService;

\defined('_JEXEC') or die;

/**
 * Statistics dashboard model.
 */
final class DashboardModel extends BaseDatabaseModel
{
	/**
	 * Returns all dashboard data for the requested range.
	 *
	 * @param int|string $range     Reporting range identifier.
	 * @param int $tableRows Maximum audio rows on the dashboard, or zero for all.
	 *
	 * @return array<string, mixed>
	 */
	public function getDashboardData(int|string $range, int $tableRows): array
	{
		return $this->getQueryService()->getDashboardData($range, $tableRows);
	}

	/**
	 * Returns the installed component/package version.
	 *
	 * @return string
	 */
	public function getInstalledVersion(): string
	{
		$db = $this->getDatabase();
		$query = $db->getQuery(true)
			->select($db->quoteName('manifest_cache'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('component'))
			->where($db->quoteName('element') . ' = ' . $db->quote('com_pungaanalytics'));
		$db->setQuery($query, 0, 1);
		$manifest = json_decode((string) $db->loadResult(), true);

		return is_array($manifest) && isset($manifest['version']) ? (string) $manifest['version'] : 'unknown';
	}

	/**
	 * Archives raw events older than the configured retention period.
	 *
	 * @param int $retentionDays Retention period in days.
	 *
	 * @return int Number of archived and removed raw events.
	 */
	public function purgeExpired(int $retentionDays): int
	{
		return (new StatisticsArchiveService($this->getDatabase()))->archiveExpired(
			$retentionDays,
			(string) Factory::getApplication()->get('offset', 'UTC')
		);
	}

	/**
	 * Permanently removes all raw events and archived reports.
	 *
	 * @return int Number of removed database rows.
	 */
	public function resetStats(): int
	{
		return (new StatisticsArchiveService($this->getDatabase()))->resetAll();
	}

	/**
	 * Creates the shared statistics query service.
	 *
	 * @return StatisticsQueryService
	 */
	private function getQueryService(): StatisticsQueryService
	{
		return new StatisticsQueryService(
			$this->getDatabase(),
			(string) Factory::getApplication()->get('offset', 'UTC')
		);
	}
}
