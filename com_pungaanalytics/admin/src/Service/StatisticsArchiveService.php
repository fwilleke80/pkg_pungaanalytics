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
		$retentionDays = max(1, $retentionDays);
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
				$this->archiveDimensions($cutoffDate);
				$this->archiveTimeBuckets($cutoffDate);
				$this->archiveCustomEventTimeBuckets($cutoffDate);
				$this->archiveEventItems($cutoffDate);

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
			'#__pungaanalytics_daily',
			'#__pungaanalytics_daily_dimensions',
			'#__pungaanalytics_daily_time',
			'#__pungaanalytics_daily_event_time',
			'#__pungaanalytics_daily_items',
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
			. '`german_visits`, `bot_pageviews`, `custom_events`'
			. ') SELECT '
			. '`visit_date`, '
			. "COUNT(DISTINCT CASE WHEN `is_bot` = 0 AND `event_type` = 'pageview' THEN `visitor_hash` END), "
			. "SUM(CASE WHEN `is_bot` = 0 AND `event_type` = 'pageview' THEN 1 ELSE 0 END), "
			. "SUM(CASE WHEN `is_bot` = 0 AND `event_type` = 'pageview' AND `is_authenticated` = 1 THEN 1 ELSE 0 END), "
			. "COUNT(DISTINCT CASE WHEN `is_bot` = 0 AND `event_type` = 'pageview' AND `country_code` = 'DE' THEN `visitor_hash` END), "
			. "SUM(CASE WHEN `is_bot` = 1 AND `event_type` = 'pageview' THEN 1 ELSE 0 END), "
			. "SUM(CASE WHEN `is_bot` = 0 AND `event_type` <> 'pageview' THEN 1 ELSE 0 END) "
			. 'FROM ' . $eventsTable
			. ' WHERE `visit_date` < ' . $db->quote($cutoffDate)
			. ' GROUP BY `visit_date` '
			. 'ON DUPLICATE KEY UPDATE '
			. '`human_visits` = `human_visits` + VALUES(`human_visits`), '
			. '`human_pageviews` = `human_pageviews` + VALUES(`human_pageviews`), '
			. '`authenticated_pageviews` = `authenticated_pageviews` + VALUES(`authenticated_pageviews`), '
			. '`german_visits` = `german_visits` + VALUES(`german_visits`), '
			. '`bot_pageviews` = `bot_pageviews` + VALUES(`bot_pageviews`), '
			. '`custom_events` = `custom_events` + VALUES(`custom_events`)';

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
			'path',
			'path',
			"`is_bot` = 0 AND `event_type` = 'pageview'"
		);
		$this->archiveDimension(
			$cutoffDate,
			'referrer',
			'referrer_host',
			"`is_bot` = 0 AND `event_type` = 'pageview' AND `referrer_host` <> ''"
		);
		$this->archiveDimension(
			$cutoffDate,
			'country',
			'country_code',
			"`is_bot` = 0 AND `event_type` = 'pageview'",
			'COUNT(DISTINCT `visitor_hash`)'
		);
		$this->archiveDimension(
			$cutoffDate,
			'language',
			'language_code',
			"`is_bot` = 0 AND `event_type` = 'pageview' AND `language_code` <> ''"
		);
		$this->archiveDimension(
			$cutoffDate,
			'device',
			'device_type',
			"`is_bot` = 0 AND `event_type` = 'pageview' AND `device_type` <> ''"
		);
		$this->archiveDimension(
			$cutoffDate,
			'browser',
			'browser_family',
			"`is_bot` = 0 AND `event_type` = 'pageview' AND `browser_family` <> ''"
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
			"`is_bot` = 0 AND `event_type` <> 'pageview'"
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
			. '`human_pageviews`, `bot_pageviews`'
			. ') SELECT '
			. '`visit_date`, '
			. $db->quote($kind) . ', '
			. $bucket . ', '
			. "COUNT(DISTINCT CASE WHEN `is_bot` = 0 AND `event_type` = 'pageview' THEN `visitor_hash` END), "
			. "SUM(CASE WHEN `is_bot` = 0 AND `event_type` = 'pageview' THEN 1 ELSE 0 END), "
			. "SUM(CASE WHEN `is_bot` = 1 AND `event_type` = 'pageview' THEN 1 ELSE 0 END) "
			. 'FROM ' . $eventsTable
			. ' WHERE `visit_date` < ' . $db->quote($cutoffDate)
			. ' AND ' . $bucket . ' >= ' . $minimum
			. ' AND ' . $bucket . ' <= ' . $maximum
			. ' GROUP BY `visit_date`, ' . $bucket . ' '
			. 'ON DUPLICATE KEY UPDATE '
			. '`human_visits` = `human_visits` + VALUES(`human_visits`), '
			. '`human_pageviews` = `human_pageviews` + VALUES(`human_pageviews`), '
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
			. " AND `is_bot` = 0 AND `event_type` <> 'pageview'"
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
			. " AND `is_bot` = 0 AND `event_type` <> 'pageview'"
			. ' GROUP BY `visit_date`, `event_type`, `item_type`, `item_id`, `item_title`, `path` '
			. 'ON DUPLICATE KEY UPDATE '
			. '`event_count` = `event_count` + VALUES(`event_count`)';

		$db->setQuery($sql)->execute();
	}
}
