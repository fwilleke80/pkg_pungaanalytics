<?php

declare(strict_types=1);

namespace Punga\Component\PungaAnalytics\Administrator\Service;

use Joomla\CMS\Date\Date;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Archives expired raw events into permanent daily reports.
 */
final class StatisticsArchiveService
{
	/** Inactivity gap that starts a new cookieless session. */
	private const SESSION_TIMEOUT_SECONDS = 1800;

	/**
	 * Creates the archive service.
	 *
	 * @param DatabaseInterface $database Database connection.
	 */
	public function __construct(private DatabaseInterface $database)
	{
	}

	/**
	 * Archives complete local calendar days outside the raw-event retention window.
	 *
	 * @param int    $retentionDays Number of local calendar days to retain as raw events.
	 * @param string $timezone      Site timezone.
	 *
	 * @return int Number of raw events archived and removed.
	 */
	public function archiveExpired(int $retentionDays, string $timezone): int
	{
		$retentionDays = max(2, $retentionDays);
		$cutoff = new Date('now', $timezone);
		$cutoff->modify('-' . max(0, $retentionDays - 1) . ' days');

		return $this->archiveBefore($cutoff->format('Y-m-d'));
	}

	/**
	 * Archives raw events whose local visit date precedes the supplied date.
	 *
	 * @param string $cutoffDate First local date that remains in the raw table.
	 *
	 * @return int Number of raw events archived and removed.
	 */
	public function archiveBefore(string $cutoffDate): int
	{
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoffDate) !== 1)
		{
			throw new \InvalidArgumentException('Invalid statistics archive cutoff date.');
		}

		$db = $this->database;
		$lockName = $this->acquireLock();

		try
		{
			$db->transactionStart();

			try
			{
				$this->archiveDailyTotals($cutoffDate);
				$this->archivePageViews($cutoffDate);
				$this->archiveDimensions($cutoffDate);
				$this->archiveNetworks($cutoffDate);
				$this->archiveAutomationDimensions($cutoffDate);
				$this->archiveTimeBuckets($cutoffDate);
				$this->archiveCustomEventTimeBuckets($cutoffDate);
				$this->archiveEventItems($cutoffDate);
				$this->archiveNotFoundRequests($cutoffDate);
				$this->archiveSessions($cutoffDate);
				$this->archiveSessionEvents($cutoffDate);

				$eventsTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_events'));
				$db->setQuery(
					'DELETE FROM ' . $eventsTable
					. ' WHERE `visit_date` < ' . $db->quote($cutoffDate)
				)->execute();
				$removed = $db->getAffectedRows();
				$db->transactionCommit();

				return $removed;
			}
			catch (\Throwable $exception)
			{
				$db->transactionRollback();

				throw $exception;
			}
		}
		finally
		{
			$this->releaseLock($lockName);
		}
	}

	/**
	 * Permanently removes raw events and all archived reports.
	 *
	 * @return int Number of removed database rows.
	 */
	public function resetAll(): int
	{
		$db = $this->database;
		$tables = [
			'#__pungaanalytics_events',
			'#__pungaanalytics_page_status',
			'#__pungaanalytics_daily',
			'#__pungaanalytics_daily_pages',
			'#__pungaanalytics_daily_dimensions',
			'#__pungaanalytics_daily_networks',
			'#__pungaanalytics_daily_automation',
			'#__pungaanalytics_daily_time',
			'#__pungaanalytics_daily_event_time',
			'#__pungaanalytics_daily_items',
			'#__pungaanalytics_daily_404',
			'#__pungaanalytics_daily_session_events',
			'#__pungaanalytics_daily_sessions',
		];
		$removed = 0;
		$lockName = $this->acquireLock();

		try
		{
			$db->transactionStart();

			try
			{
				foreach ($tables as $table)
				{
					$tableName = $db->quoteName($db->replacePrefix($table));
					$db->setQuery('DELETE FROM ' . $tableName)->execute();
					$removed += $db->getAffectedRows();
				}

				$db->transactionCommit();

				return $removed;
			}
			catch (\Throwable $exception)
			{
				$db->transactionRollback();

				throw $exception;
			}
		}
		finally
		{
			$this->releaseLock($lockName);
		}
	}

	/**
	 * Acquires the site-specific database advisory lock.
	 *
	 * @return string Acquired lock name.
	 */
	private function acquireLock(): string
	{
		$db = $this->database;
		$table = $db->replacePrefix('#__pungaanalytics_events');
		$lockName = 'pungaanalytics_' . substr(hash('sha256', $table), 0, 48);
		$db->setQuery('SELECT GET_LOCK(' . $db->quote($lockName) . ', 10)');

		if ((int) $db->loadResult() !== 1)
		{
			throw new \RuntimeException('Could not acquire the Punga Analytics maintenance lock.');
		}

		return $lockName;
	}

	/**
	 * Releases the statistics maintenance lock.
	 *
	 * @param string $lockName Acquired lock name.
	 *
	 * @return void
	 */
	private function releaseLock(string $lockName): void
	{
		$db = $this->database;
		$db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($lockName) . ')')->execute();
	}

	/**
	 * Archives the primary daily counters.
	 *
	 * @param string $cutoffDate First retained raw-event date.
	 *
	 * @return void
	 */
	private function archiveDailyTotals(string $cutoffDate): void
	{
		$db = $this->database;
		$eventsTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_events'));
		$dailyTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_daily'));
		$sql = 'INSERT INTO ' . $dailyTable . ' ('
			. '`visit_date`, `human_visits`, `human_pageviews`, `authenticated_pageviews`, '
			. '`german_visits`, `automated_visits`, `automated_pageviews`, `bot_pageviews`, `custom_events`'
			. ') SELECT '
			. '`visit_date`, '
			. "COUNT(DISTINCT CASE WHEN `is_bot` = 0 AND `is_automated` = 0 AND `event_type` = 'pageview' THEN `visitor_hash` END), "
			. "SUM(CASE WHEN `is_bot` = 0 AND `is_automated` = 0 AND `event_type` = 'pageview' THEN 1 ELSE 0 END), "
			. "SUM(CASE WHEN `is_bot` = 0 AND `is_automated` = 0 AND `event_type` = 'pageview' AND `is_authenticated` = 1 THEN 1 ELSE 0 END), "
			. "COUNT(DISTINCT CASE WHEN `is_bot` = 0 AND `is_automated` = 0 AND `event_type` = 'pageview' AND `country_code` = 'DE' THEN `visitor_hash` END), "
			. "COUNT(DISTINCT CASE WHEN `is_bot` = 0 AND `is_automated` = 1 AND `event_type` = 'pageview' THEN `visitor_hash` END), "
			. "SUM(CASE WHEN `is_bot` = 0 AND `is_automated` = 1 AND `event_type` = 'pageview' THEN 1 ELSE 0 END), "
			. "SUM(CASE WHEN `is_bot` = 1 AND `event_type` = 'pageview' THEN 1 ELSE 0 END), "
			. "SUM(CASE WHEN `is_bot` = 0 AND `is_automated` = 0 AND `event_type` <> 'pageview' THEN 1 ELSE 0 END) "
			. 'FROM ' . $eventsTable
			. ' WHERE `visit_date` < ' . $db->quote($cutoffDate)
			. ' GROUP BY `visit_date` '
			. 'ON DUPLICATE KEY UPDATE '
			. '`human_visits` = `human_visits` + VALUES(`human_visits`), '
			. '`human_pageviews` = `human_pageviews` + VALUES(`human_pageviews`), '
			. '`authenticated_pageviews` = `authenticated_pageviews` + VALUES(`authenticated_pageviews`), '
			. '`german_visits` = `german_visits` + VALUES(`german_visits`), '
			. '`automated_visits` = `automated_visits` + VALUES(`automated_visits`), '
			. '`automated_pageviews` = `automated_pageviews` + VALUES(`automated_pageviews`), '
			. '`bot_pageviews` = `bot_pageviews` + VALUES(`bot_pageviews`), '
			. '`custom_events` = `custom_events` + VALUES(`custom_events`)';

		$db->setQuery($sql)->execute();
	}

	/**
	 * Archives successful page-view counts in a dedicated status-safe table.
	 *
	 * @param string $cutoffDate First retained raw-event date.
	 *
	 * @return void
	 */
	private function archivePageViews(string $cutoffDate): void
	{
		$db = $this->database;
		$eventsTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_events'));
		$pagesTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_daily_pages'));
		$sql = 'INSERT INTO ' . $pagesTable . ' ('
			. '`visit_date`, `path_hash`, `path`, `pageview_count`'
			. ') SELECT '
			. '`visit_date`, SHA2(`path`, 256), `path`, COUNT(*) '
			. 'FROM ' . $eventsTable
			. ' WHERE `visit_date` < ' . $db->quote($cutoffDate)
			. " AND `is_bot` = 0 AND `is_automated` = 0 AND `event_type` = 'pageview'"
			. ' AND `http_status` >= 200 AND `http_status` < 400'
			. ' GROUP BY `visit_date`, `path` '
			. 'ON DUPLICATE KEY UPDATE '
			. '`path` = VALUES(`path`), '
			. '`pageview_count` = `pageview_count` + VALUES(`pageview_count`)';

		$db->setQuery($sql)->execute();
	}

	/**
	 * Archives all dashboard breakdowns.
	 *
	 * @param string $cutoffDate First retained raw-event date.
	 *
	 * @return void
	 */
	private function archiveDimensions(string $cutoffDate): void
	{
		$this->archiveDimension(
			$cutoffDate,
			'referrer',
			'referrer_host',
			"`is_bot` = 0 AND `is_automated` = 0 AND `event_type` = 'pageview' AND `referrer_host` <> ''"
		);
		$this->archiveDimension(
			$cutoffDate,
			'country',
			'country_code',
			"`is_bot` = 0 AND `is_automated` = 0 AND `event_type` = 'pageview'",
			'COUNT(DISTINCT `visitor_hash`)'
		);
		$this->archiveDimension(
			$cutoffDate,
			'language',
			'language_code',
			"`is_bot` = 0 AND `is_automated` = 0 AND `event_type` = 'pageview' AND `language_code` <> ''"
		);
		$this->archiveDimension(
			$cutoffDate,
			'device',
			'device_type',
			"`is_bot` = 0 AND `is_automated` = 0 AND `event_type` = 'pageview' AND `device_type` <> ''"
		);
		$this->archiveDimension(
			$cutoffDate,
			'browser',
			'browser_family',
			"`is_bot` = 0 AND `is_automated` = 0 AND `event_type` = 'pageview' AND `browser_family` <> ''"
		);
		$this->archiveDimension(
			$cutoffDate,
			'bot',
			'bot_name',
			"`is_bot` = 1 AND `event_type` = 'pageview' AND `bot_name` <> ''"
		);
		$this->archiveDimension(
			$cutoffDate,
			'event_type',
			'event_type',
			"`is_bot` = 0 AND `is_automated` = 0 AND `event_type` <> 'pageview'"
		);
		$this->archiveDimension(
			$cutoffDate,
			'traffic_source',
			'traffic_source',
			"`is_bot` = 0 AND `is_automated` = 0 AND `event_type` = 'pageview' AND `http_status` >= 200 AND `http_status` < 400 "
				. "AND `traffic_source` IN ('direct', 'search', 'social', 'ai', 'referral')"
		);
	}

	/**
	 * Archives one named dimension.
	 *
	 * @param string $cutoffDate     First retained raw-event date.
	 * @param string $dimensionKey   Aggregate dimension identifier.
	 * @param string $labelColumn    Raw event label column.
	 * @param string $filters        Trusted SQL filters.
	 * @param string $countExpression Trusted SQL count expression.
	 *
	 * @return void
	 */
	private function archiveDimension(
		string $cutoffDate,
		string $dimensionKey,
		string $labelColumn,
		string $filters,
		string $countExpression = 'COUNT(*)'
	): void
	{
		$allowedColumns = [
			'path',
			'referrer_host',
			'country_code',
			'language_code',
			'device_type',
			'browser_family',
			'bot_name',
			'event_type',
			'traffic_source',
		];

		if (!\in_array($labelColumn, $allowedColumns, true))
		{
			throw new \InvalidArgumentException('Unsupported statistics archive dimension.');
		}

		$db = $this->database;
		$eventsTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_events'));
		$dimensionsTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_daily_dimensions'));
		$label = '`' . $labelColumn . '`';
		$sql = 'INSERT INTO ' . $dimensionsTable . ' ('
			. '`visit_date`, `dimension_key`, `label_hash`, `label`, `event_count`'
			. ') SELECT '
			. '`visit_date`, '
			. $db->quote($dimensionKey) . ', '
			. 'SHA2(' . $label . ', 256), '
			. $label . ', '
			. $countExpression . ' '
			. 'FROM ' . $eventsTable
			. ' WHERE `visit_date` < ' . $db->quote($cutoffDate)
			. ' AND ' . $filters
			. ' GROUP BY `visit_date`, ' . $label . ' '
			. 'ON DUPLICATE KEY UPDATE '
			. '`label` = VALUES(`label`), '
			. '`event_count` = `event_count` + VALUES(`event_count`)';

		$db->setQuery($sql)->execute();
	}

	/**
	 * Archives page-view traffic by Autonomous System number.
	 *
	 * @param string $cutoffDate First retained raw-event date.
	 *
	 * @return void
	 */
	private function archiveNetworks(string $cutoffDate): void
	{
		$db = $this->database;
		$eventsTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_events'));
		$networkTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_daily_networks'));
		$sql = 'INSERT INTO ' . $networkTable . ' ('
			. '`visit_date`, `asn_number`, `human_visits`, `human_pageviews`, `automated_visits`, `automated_pageviews`, `bot_pageviews`'
			. ') SELECT '
			. '`visit_date`, `asn_number`, '
			. "COUNT(DISTINCT CASE WHEN `is_bot` = 0 AND `is_automated` = 0 THEN `visitor_hash` END), "
			. "SUM(CASE WHEN `is_bot` = 0 AND `is_automated` = 0 THEN 1 ELSE 0 END), "
			. "COUNT(DISTINCT CASE WHEN `is_bot` = 0 AND `is_automated` = 1 THEN `visitor_hash` END), "
			. "SUM(CASE WHEN `is_bot` = 0 AND `is_automated` = 1 THEN 1 ELSE 0 END), "
			. "SUM(CASE WHEN `is_bot` = 1 THEN 1 ELSE 0 END) "
			. 'FROM ' . $eventsTable
			. ' WHERE `visit_date` < ' . $db->quote($cutoffDate)
			. " AND `event_type` = 'pageview' AND `asn_number` > 0"
			. ' GROUP BY `visit_date`, `asn_number` '
			. 'ON DUPLICATE KEY UPDATE '
			. '`human_visits` = `human_visits` + VALUES(`human_visits`), '
			. '`human_pageviews` = `human_pageviews` + VALUES(`human_pageviews`), '
			. '`automated_visits` = `automated_visits` + VALUES(`automated_visits`), '
			. '`automated_pageviews` = `automated_pageviews` + VALUES(`automated_pageviews`), '
			. '`bot_pageviews` = `bot_pageviews` + VALUES(`bot_pageviews`)';

		$db->setQuery($sql)->execute();
	}


	/**
	 * Archives explainable suspected-automation breakdowns.
	 *
	 * @param string $cutoffDate First retained raw-event date.
	 *
	 * @return void
	 */
	private function archiveAutomationDimensions(string $cutoffDate): void
	{
		$this->archiveAutomationDimension($cutoffDate, 'country', 'country_code');
		$this->archiveAutomationDimension($cutoffDate, 'path', 'path');
		$this->archiveAutomationDimension($cutoffDate, 'reason', 'automation_reason');
	}

	/**
	 * Archives one suspected-automation dimension with visitor-day and page-view totals.
	 *
	 * @param string $cutoffDate   First retained raw-event date.
	 * @param string $dimensionKey Aggregate dimension identifier.
	 * @param string $labelColumn  Raw event label column.
	 *
	 * @return void
	 */
	private function archiveAutomationDimension(string $cutoffDate, string $dimensionKey, string $labelColumn): void
	{
		if (!\in_array($labelColumn, ['country_code', 'path', 'automation_reason'], true))
		{
			throw new \InvalidArgumentException('Unsupported automation archive dimension.');
		}

		$db = $this->database;
		$eventsTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_events'));
		$automationTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_daily_automation'));
		$label = $db->quoteName($labelColumn);
		$sql = 'INSERT INTO ' . $automationTable . ' ('
			. '`visit_date`, `dimension_key`, `label_hash`, `label`, `visitor_count`, `pageview_count`'
			. ') SELECT `visit_date`, ' . $db->quote($dimensionKey) . ', SHA2(' . $label . ', 256), '
			. $label . ', COUNT(DISTINCT `visitor_hash`), COUNT(*) '
			. 'FROM ' . $eventsTable
			. ' WHERE `visit_date` < ' . $db->quote($cutoffDate)
			. " AND `is_bot` = 0 AND `is_automated` = 1 AND `event_type` = 'pageview'"
			. ' AND ' . $label . " <> ''"
			. ' GROUP BY `visit_date`, ' . $label . ' '
			. 'ON DUPLICATE KEY UPDATE '
			. '`label` = VALUES(`label`), '
			. '`visitor_count` = `visitor_count` + VALUES(`visitor_count`), '
			. '`pageview_count` = `pageview_count` + VALUES(`pageview_count`)';

		$db->setQuery($sql)->execute();
	}

	/**
	 * Archives local hour and weekday activity reports.
	 *
	 * @param string $cutoffDate First retained raw-event date.
	 *
	 * @return void
	 */
	private function archiveTimeBuckets(string $cutoffDate): void
	{
		$this->archiveTimeBucket($cutoffDate, 'hour', 'visit_hour', 0, 23);
		$this->archiveTimeBucket($cutoffDate, 'weekday', 'visit_weekday', 1, 7);
	}

	/**
	 * Archives one time-bucket family.
	 *
	 * @param string $cutoffDate First retained raw-event date.
	 * @param string $kind       Aggregate bucket kind.
	 * @param string $column     Raw event bucket column.
	 * @param int    $minimum    Lowest valid bucket.
	 * @param int    $maximum    Highest valid bucket.
	 *
	 * @return void
	 */
	private function archiveTimeBucket(
		string $cutoffDate,
		string $kind,
		string $column,
		int $minimum,
		int $maximum
	): void
	{
		if (!\in_array($kind, ['hour', 'weekday'], true)
			|| !\in_array($column, ['visit_hour', 'visit_weekday'], true))
		{
			throw new \InvalidArgumentException('Unsupported statistics time archive bucket.');
		}

		$db = $this->database;
		$eventsTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_events'));
		$timeTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_daily_time'));
		$bucket = '`' . $column . '`';
		$sql = 'INSERT INTO ' . $timeTable . ' ('
			. '`visit_date`, `bucket_kind`, `bucket_value`, `human_visits`, '
			. '`human_pageviews`, `automated_visits`, `automated_pageviews`, `bot_pageviews`'
			. ') SELECT '
			. '`visit_date`, '
			. $db->quote($kind) . ', '
			. $bucket . ', '
			. "COUNT(DISTINCT CASE WHEN `is_bot` = 0 AND `is_automated` = 0 AND `event_type` = 'pageview' THEN `visitor_hash` END), "
			. "SUM(CASE WHEN `is_bot` = 0 AND `is_automated` = 0 AND `event_type` = 'pageview' THEN 1 ELSE 0 END), "
			. "COUNT(DISTINCT CASE WHEN `is_bot` = 0 AND `is_automated` = 1 AND `event_type` = 'pageview' THEN `visitor_hash` END), "
			. "SUM(CASE WHEN `is_bot` = 0 AND `is_automated` = 1 AND `event_type` = 'pageview' THEN 1 ELSE 0 END), "
			. "SUM(CASE WHEN `is_bot` = 1 AND `event_type` = 'pageview' THEN 1 ELSE 0 END) "
			. 'FROM ' . $eventsTable
			. ' WHERE `visit_date` < ' . $db->quote($cutoffDate)
			. ' AND ' . $bucket . ' >= ' . $minimum
			. ' AND ' . $bucket . ' <= ' . $maximum
			. ' GROUP BY `visit_date`, ' . $bucket . ' '
			. 'ON DUPLICATE KEY UPDATE '
			. '`human_visits` = `human_visits` + VALUES(`human_visits`), '
			. '`human_pageviews` = `human_pageviews` + VALUES(`human_pageviews`), '
			. '`automated_visits` = `automated_visits` + VALUES(`automated_visits`), '
			. '`automated_pageviews` = `automated_pageviews` + VALUES(`automated_pageviews`), '
			. '`bot_pageviews` = `bot_pageviews` + VALUES(`bot_pageviews`)';

		$db->setQuery($sql)->execute();
	}

	/**
	 * Archives every custom event by local hour and weekday.
	 *
	 * @param string $cutoffDate First retained raw-event date.
	 *
	 * @return void
	 */
	private function archiveCustomEventTimeBuckets(string $cutoffDate): void
	{
		$this->archiveCustomEventTimeBucket($cutoffDate, 'hour', 'visit_hour', 0, 23);
		$this->archiveCustomEventTimeBucket($cutoffDate, 'weekday', 'visit_weekday', 1, 7);
	}

	/**
	 * Archives one generic custom-event time-bucket family.
	 *
	 * @param string $cutoffDate First retained raw-event date.
	 * @param string $kind       Aggregate bucket kind.
	 * @param string $column     Raw event bucket column.
	 * @param int    $minimum    Lowest valid bucket.
	 * @param int    $maximum    Highest valid bucket.
	 *
	 * @return void
	 */
	private function archiveCustomEventTimeBucket(
		string $cutoffDate,
		string $kind,
		string $column,
		int $minimum,
		int $maximum
	): void
	{
		if (!\in_array($kind, ['hour', 'weekday'], true)
			|| !\in_array($column, ['visit_hour', 'visit_weekday'], true))
		{
			throw new \InvalidArgumentException('Unsupported custom-event time archive bucket.');
		}

		$db = $this->database;
		$eventsTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_events'));
		$timeTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_daily_event_time'));
		$bucket = '`' . $column . '`';
		$sql = 'INSERT INTO ' . $timeTable . ' ('
			. '`visit_date`, `event_type`, `bucket_kind`, `bucket_value`, `event_count`'
			. ') SELECT '
			. '`visit_date`, `event_type`, ' . $db->quote($kind) . ', ' . $bucket . ', COUNT(*) '
			. 'FROM ' . $eventsTable
			. ' WHERE `visit_date` < ' . $db->quote($cutoffDate)
			. " AND `is_bot` = 0 AND `is_automated` = 0 AND `event_type` <> 'pageview'"
			. ' AND ' . $bucket . ' >= ' . $minimum
			. ' AND ' . $bucket . ' <= ' . $maximum
			. ' GROUP BY `visit_date`, `event_type`, ' . $bucket . ' '
			. 'ON DUPLICATE KEY UPDATE '
			. '`event_count` = `event_count` + VALUES(`event_count`)';

		$db->setQuery($sql)->execute();
	}

	/**
	 * Archives custom-event item totals.
	 *
	 * @param string $cutoffDate First retained raw-event date.
	 *
	 * @return void
	 */
	private function archiveEventItems(string $cutoffDate): void
	{
		$db = $this->database;
		$eventsTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_events'));
		$itemsTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_daily_items'));
		$sql = 'INSERT INTO ' . $itemsTable . ' ('
			. '`visit_date`, `row_hash`, `event_type`, `item_type`, `item_id`, `item_title`, `path`, `event_count`'
			. ') SELECT '
			. '`visit_date`, '
			. 'SHA2(CONCAT_WS(CHAR(31), `event_type`, `item_type`, `item_id`, `item_title`, `path`), 256), '
			. '`event_type`, `item_type`, `item_id`, `item_title`, `path`, COUNT(*) '
			. 'FROM ' . $eventsTable
			. ' WHERE `visit_date` < ' . $db->quote($cutoffDate)
			. " AND `is_bot` = 0 AND `is_automated` = 0 AND `event_type` <> 'pageview'"
			. ' GROUP BY `visit_date`, `event_type`, `item_type`, `item_id`, `item_title`, `path` '
			. 'ON DUPLICATE KEY UPDATE '
			. '`event_count` = `event_count` + VALUES(`event_count`)';

		$db->setQuery($sql)->execute();
	}


	/**
	 * Archives cookieless daily session aggregates before raw events are removed.
	 *
	 * @param string $cutoffDate First retained raw-event date.
	 *
	 * @return void
	 */
	private function archiveSessions(string $cutoffDate): void
	{
		$db = $this->database;
		$eventsTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_events'));
		$sessionsTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_daily_sessions'));
		$timeout = self::SESSION_TIMEOUT_SECONDS;
		$sessionised = $this->getSessionisedEventsSql($eventsTable, $cutoffDate, $timeout);
		$sql = 'INSERT INTO ' . $sessionsTable . ' ('
			. '`visit_date`, `session_count`, `duration_seconds`, `pageview_count`, '
			. '`custom_event_count`, `page_session_count`, `single_page_sessions`'
			. ') SELECT sessions.`visit_date`, COUNT(*), '
			. 'COALESCE(SUM(TIMESTAMPDIFF(SECOND, sessions.`started_at`, sessions.`ended_at`)), 0), '
			. 'COALESCE(SUM(sessions.`pageviews`), 0), '
			. 'COALESCE(SUM(sessions.`custom_events`), 0), '
			. 'SUM(CASE WHEN sessions.`pageviews` > 0 THEN 1 ELSE 0 END), '
			. 'SUM(CASE WHEN sessions.`pageviews` = 1 THEN 1 ELSE 0 END) '
			. 'FROM ('
			. ' SELECT sessionised.`visit_date`, sessionised.`visitor_hash`, sessionised.`session_number`, '
			. 'MIN(sessionised.`visited_at`) AS `started_at`, MAX(sessionised.`visited_at`) AS `ended_at`, '
			. "SUM(CASE WHEN sessionised.`event_type` = 'pageview' THEN 1 ELSE 0 END) AS `pageviews`, "
			. "SUM(CASE WHEN sessionised.`event_type` <> 'pageview' THEN 1 ELSE 0 END) AS `custom_events` "
			. 'FROM (' . $sessionised . ') AS sessionised '
			. 'GROUP BY sessionised.`visit_date`, sessionised.`visitor_hash`, sessionised.`session_number`'
			. ') AS sessions GROUP BY sessions.`visit_date` '
			. 'ON DUPLICATE KEY UPDATE '
			. '`session_count` = `session_count` + VALUES(`session_count`), '
			. '`duration_seconds` = `duration_seconds` + VALUES(`duration_seconds`), '
			. '`pageview_count` = `pageview_count` + VALUES(`pageview_count`), '
			. '`custom_event_count` = `custom_event_count` + VALUES(`custom_event_count`), '
			. '`page_session_count` = `page_session_count` + VALUES(`page_session_count`), '
			. '`single_page_sessions` = `single_page_sessions` + VALUES(`single_page_sessions`)';

		$db->setQuery($sql)->execute();
	}

	/**
	 * Archives session participation for every recorded custom event identifier.
	 *
	 * @param string $cutoffDate First retained raw-event date.
	 *
	 * @return void
	 */
	private function archiveSessionEvents(string $cutoffDate): void
	{
		$db = $this->database;
		$eventsTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_events'));
		$sessionEventsTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_daily_session_events'));
		$timeout = self::SESSION_TIMEOUT_SECONDS;
		$sessionised = $this->getSessionisedEventsSql($eventsTable, $cutoffDate, $timeout);
		$sql = 'INSERT INTO ' . $sessionEventsTable . ' ('
			. '`visit_date`, `event_type`, `event_count`, `session_count`, `multi_event_sessions`'
			. ') SELECT event_sessions.`visit_date`, event_sessions.`event_type`, '
			. 'SUM(event_sessions.`event_count`), COUNT(*), '
			. 'SUM(CASE WHEN event_sessions.`event_count` >= 2 THEN 1 ELSE 0 END) '
			. 'FROM ('
			. ' SELECT sessionised.`visit_date`, sessionised.`event_type`, '
			. 'sessionised.`visitor_hash`, sessionised.`session_number`, COUNT(*) AS `event_count` '
			. 'FROM (' . $sessionised . ') AS sessionised '
			. "WHERE sessionised.`event_type` <> 'pageview' "
			. 'GROUP BY sessionised.`visit_date`, sessionised.`event_type`, '
			. 'sessionised.`visitor_hash`, sessionised.`session_number`'
			. ') AS event_sessions '
			. 'GROUP BY event_sessions.`visit_date`, event_sessions.`event_type` '
			. 'ON DUPLICATE KEY UPDATE '
			. '`event_count` = `event_count` + VALUES(`event_count`), '
			. '`session_count` = `session_count` + VALUES(`session_count`), '
			. '`multi_event_sessions` = `multi_event_sessions` + VALUES(`multi_event_sessions`)';

		$db->setQuery($sql)->execute();
	}

	/**
	 * Builds a derived-table query assigning a session number to every human event.
	 *
	 * @param string $eventsTable Quoted raw event table name.
	 * @param string $cutoffDate  First retained raw-event date.
	 * @param int    $timeout     Session inactivity timeout in seconds.
	 *
	 * @return string SQL SELECT statement suitable for use as a derived table.
	 */
	private function getSessionisedEventsSql(string $eventsTable, string $cutoffDate, int $timeout): string
	{
		$db = $this->database;

		return 'SELECT marked_events.*, '
			. 'SUM(marked_events.`new_session`) OVER ('
			. 'PARTITION BY marked_events.`visit_date`, marked_events.`visitor_hash` '
			. 'ORDER BY marked_events.`visited_at`, marked_events.`id` ROWS UNBOUNDED PRECEDING'
			. ') AS `session_number` '
			. 'FROM ('
			. ' SELECT ordered_events.*, '
			. 'CASE WHEN ordered_events.`previous_at` IS NULL '
			. 'OR TIMESTAMPDIFF(SECOND, ordered_events.`previous_at`, ordered_events.`visited_at`) > ' . $timeout
			. ' THEN 1 ELSE 0 END AS `new_session` '
			. 'FROM ('
			. ' SELECT e.`id`, e.`visit_date`, e.`visitor_hash`, e.`visited_at`, e.`event_type`, '
			. 'LAG(e.`visited_at`) OVER ('
			. 'PARTITION BY e.`visit_date`, e.`visitor_hash` ORDER BY e.`visited_at`, e.`id`'
			. ') AS `previous_at` '
			. 'FROM ' . $eventsTable . ' AS e '
			. 'WHERE e.`is_bot` = 0 AND `is_automated` = 0 AND e.`visit_date` < ' . $db->quote($cutoffDate)
			. ') AS ordered_events'
			. ') AS marked_events';
	}

	/**
	 * Archives 404 request details needed by the permanent maintenance report.
	 *
	 * @param string $cutoffDate First retained raw-event date.
	 *
	 * @return void
	 */
	private function archiveNotFoundRequests(string $cutoffDate): void
	{
		$db = $this->database;
		$eventsTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_events'));
		$notFoundTable = $db->quoteName($db->replacePrefix('#__pungaanalytics_daily_404'));
		$sql = 'INSERT INTO ' . $notFoundTable . ' ('
			. '`visit_date`, `row_hash`, `path`, `referrer_host`, `is_bot`, `is_automated`, '
			. '`request_count`, `first_seen`, `last_seen`'
			. ') SELECT '
			. '`visit_date`, '
			. 'SHA2(CONCAT_WS(CHAR(31), `path`, `referrer_host`, `is_bot`, `is_automated`), 256), '
			. '`path`, `referrer_host`, `is_bot`, `is_automated`, COUNT(*), MIN(`visited_at`), MAX(`visited_at`) '
			. 'FROM ' . $eventsTable
			. ' WHERE `visit_date` < ' . $db->quote($cutoffDate)
			. " AND `event_type` = 'pageview' AND `http_status` = 404"
			. ' GROUP BY `visit_date`, `path`, `referrer_host`, `is_bot`, `is_automated` '
			. 'ON DUPLICATE KEY UPDATE '
			. '`request_count` = `request_count` + VALUES(`request_count`), '
			. '`first_seen` = LEAST(`first_seen`, VALUES(`first_seen`)), '
			. '`last_seen` = GREATEST(`last_seen`, VALUES(`last_seen`))';

		$db->setQuery($sql)->execute();
	}
}
