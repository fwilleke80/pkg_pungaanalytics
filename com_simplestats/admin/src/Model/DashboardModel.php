<?php

declare(strict_types=1);

namespace Willeke\Component\Simplestats\Administrator\Model;

use Willeke\Component\Simplestats\Administrator\Service\StatisticsArchiveService;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\DatabaseQuery;

\defined('_JEXEC') or die;

/**
 * Statistics dashboard model.
 */
final class DashboardModel extends BaseDatabaseModel
{
	/**
	 * Returns all dashboard data for the requested range.
	 *
	 * @param int $days Number of days, or zero for all data.
	 *
	 * @return array<string, mixed>
	 */
	public function getDashboardData(int $days): array
	{
		[$from, $to] = $this->getDateBounds($days);

		return [
			'from' => $from,
			'to' => $to,
			'summary' => $this->getSummary($from, $to),
			'daily' => $this->getDaily($from, $to),
			'topPages' => $this->getDimensionRows('path', $from, $to, 15, false, 'pageview'),
			'topPlays' => $this->getEventItems('audio.play', $from, $to, 15),
			'topDownloads' => $this->getEventItems('audio.download', $from, $to, 15),
			'eventTypes' => $this->getEventTypes($from, $to, 15),
			'countries' => $this->getCountryRows($from, $to, 20),
			'referrers' => $this->getDimensionRows('referrer_host', $from, $to, 15, false, 'pageview', true),
			'languages' => $this->getDimensionRows('language_code', $from, $to, 12, false, 'pageview', true),
			'devices' => $this->getDimensionRows('device_type', $from, $to, 12, false, 'pageview', true),
			'browsers' => $this->getDimensionRows('browser_family', $from, $to, 12, false, 'pageview', true),
			'bots' => $this->getDimensionRows('bot_name', $from, $to, 12, true, 'pageview', true),
		];
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
			->where($db->quoteName('element') . ' = ' . $db->quote('com_simplestats'));
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
	 * Returns summary metrics.
	 *
	 * @param string $from Inclusive date.
	 * @param string $to   Inclusive date.
	 *
	 * @return object
	 */
	private function getSummary(string $from, string $to): object
	{
		$db = $this->getDatabase();
		$dailyVisitor = 'CONCAT(' . $db->quoteName('visit_date') . ', ' . $db->quote(':') . ', ' . $db->quoteName('visitor_hash') . ')';
		$pageView = $db->quoteName('event_type') . ' = ' . $db->quote('pageview');
		$human = $db->quoteName('is_bot') . ' = 0';
		$query = $db->getQuery(true)
			->select([
				'COUNT(DISTINCT CASE WHEN ' . $human . ' AND ' . $pageView . ' THEN ' . $dailyVisitor . ' END) AS ' . $db->quoteName('human_visits'),
				'SUM(CASE WHEN ' . $human . ' AND ' . $pageView . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('human_pageviews'),
				'SUM(CASE WHEN ' . $human . ' AND ' . $pageView . ' AND ' . $db->quoteName('is_authenticated') . ' = 1 THEN 1 ELSE 0 END) AS ' . $db->quoteName('authenticated_pageviews'),
				'COUNT(DISTINCT CASE WHEN ' . $human . ' AND ' . $pageView . ' AND ' . $db->quoteName('country_code') . ' = ' . $db->quote('DE') . ' THEN ' . $dailyVisitor . ' END) AS ' . $db->quoteName('german_visits'),
				'SUM(CASE WHEN ' . $db->quoteName('is_bot') . ' = 1 AND ' . $pageView . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('bot_pageviews'),
				'SUM(CASE WHEN ' . $human . ' AND ' . $db->quoteName('event_type') . ' = ' . $db->quote('audio.play') . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('plays'),
				'SUM(CASE WHEN ' . $human . ' AND ' . $db->quoteName('event_type') . ' = ' . $db->quote('audio.download') . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('downloads'),
				'SUM(CASE WHEN ' . $human . ' AND ' . $db->quoteName('event_type') . ' <> ' . $db->quote('pageview') . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('custom_events'),
			])
			->from($db->quoteName('#__simplestats_events'));

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);
		$raw = $db->loadObject() ?: (object) [];
		$aggregateQuery = $db->getQuery(true)
			->select([
				'SUM(' . $db->quoteName('human_visits') . ') AS ' . $db->quoteName('human_visits'),
				'SUM(' . $db->quoteName('human_pageviews') . ') AS ' . $db->quoteName('human_pageviews'),
				'SUM(' . $db->quoteName('authenticated_pageviews') . ') AS ' . $db->quoteName('authenticated_pageviews'),
				'SUM(' . $db->quoteName('german_visits') . ') AS ' . $db->quoteName('german_visits'),
				'SUM(' . $db->quoteName('bot_pageviews') . ') AS ' . $db->quoteName('bot_pageviews'),
				'SUM(' . $db->quoteName('plays') . ') AS ' . $db->quoteName('plays'),
				'SUM(' . $db->quoteName('downloads') . ') AS ' . $db->quoteName('downloads'),
				'SUM(' . $db->quoteName('custom_events') . ') AS ' . $db->quoteName('custom_events'),
			])
			->from($db->quoteName('#__simplestats_daily'));

		$this->applyDateRange($aggregateQuery, $from, $to);
		$db->setQuery($aggregateQuery);
		$aggregate = $db->loadObject() ?: (object) [];
		$result = (object) [];

		foreach ([
			'human_visits',
			'human_pageviews',
			'authenticated_pageviews',
			'german_visits',
			'bot_pageviews',
			'plays',
			'downloads',
			'custom_events',
		] as $field)
		{
			$result->{$field} = (int) ($raw->{$field} ?? 0) + (int) ($aggregate->{$field} ?? 0);
		}

		return $result;
	}

	/**
	 * Returns recent daily metrics.
	 *
	 * @param string $from Inclusive date.
	 * @param string $to   Inclusive date.
	 *
	 * @return array<int, object>
	 */
	private function getDaily(string $from, string $to): array
	{
		$db = $this->getDatabase();
		$dailyVisitor = 'CONCAT(' . $db->quoteName('visit_date') . ', ' . $db->quote(':') . ', ' . $db->quoteName('visitor_hash') . ')';
		$pageView = $db->quoteName('event_type') . ' = ' . $db->quote('pageview');
		$human = $db->quoteName('is_bot') . ' = 0';
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('visit_date'),
				'COUNT(DISTINCT CASE WHEN ' . $human . ' AND ' . $pageView . ' THEN ' . $dailyVisitor . ' END) AS ' . $db->quoteName('visits'),
				'SUM(CASE WHEN ' . $human . ' AND ' . $pageView . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('pageviews'),
				'SUM(CASE WHEN ' . $human . ' AND ' . $db->quoteName('event_type') . ' = ' . $db->quote('audio.play') . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('plays'),
				'SUM(CASE WHEN ' . $human . ' AND ' . $db->quoteName('event_type') . ' = ' . $db->quote('audio.download') . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('downloads'),
				'SUM(CASE WHEN ' . $db->quoteName('is_bot') . ' = 1 AND ' . $pageView . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('bots'),
			])
			->from($db->quoteName('#__simplestats_events'))
			->group($db->quoteName('visit_date'))
			->order($db->quoteName('visit_date') . ' DESC');

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query, 0, 31);
		$raw = $db->loadObjectList();
		$aggregateQuery = $db->getQuery(true)
			->select([
				$db->quoteName('visit_date'),
				$db->quoteName('human_visits') . ' AS ' . $db->quoteName('visits'),
				$db->quoteName('human_pageviews') . ' AS ' . $db->quoteName('pageviews'),
				$db->quoteName('plays'),
				$db->quoteName('downloads'),
				$db->quoteName('bot_pageviews') . ' AS ' . $db->quoteName('bots'),
			])
			->from($db->quoteName('#__simplestats_daily'))
			->order($db->quoteName('visit_date') . ' DESC');

		$this->applyDateRange($aggregateQuery, $from, $to);
		$db->setQuery($aggregateQuery, 0, 31);

		return $this->mergeDailyRows($raw, $db->loadObjectList(), 31);
	}

	/**
	 * Returns human visitor-days grouped by country.
	 *
	 * @param string $from  Inclusive date.
	 * @param string $to    Inclusive date.
	 * @param int    $limit Maximum rows.
	 *
	 * @return array<int, object>
	 */
	private function getCountryRows(string $from, string $to, int $limit): array
	{
		$db = $this->getDatabase();
		$dailyVisitor = 'CONCAT(' . $db->quoteName('visit_date') . ', ' . $db->quote(':') . ', ' . $db->quoteName('visitor_hash') . ')';
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('country_code') . ' AS ' . $db->quoteName('label'),
				'COUNT(DISTINCT ' . $dailyVisitor . ') AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__simplestats_events'))
			->where($db->quoteName('is_bot') . ' = 0')
			->where($db->quoteName('event_type') . ' = ' . $db->quote('pageview'))
			->group($db->quoteName('country_code'))
			->order($db->quoteName('count') . ' DESC');

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);

		return $this->mergeCountRows(
			$db->loadObjectList(),
			$this->getArchivedDimensionRows('country', $from, $to),
			$limit
		);
	}

	/**
	 * Returns grouped page-view dimensions.
	 *
	 * @param string $field        Database field.
	 * @param string $from         Inclusive date.
	 * @param string $to           Inclusive date.
	 * @param int    $limit        Maximum rows.
	 * @param bool   $bots         Whether to return bot rather than human rows.
	 * @param string $eventType    Event type.
	 * @param bool   $excludeEmpty Whether blank values should be omitted.
	 *
	 * @return array<int, object>
	 */
	private function getDimensionRows(
		string $field,
		string $from,
		string $to,
		int $limit,
		bool $bots,
		string $eventType,
		bool $excludeEmpty = false
	): array
	{
		$allowed = ['path', 'referrer_host', 'country_code', 'language_code', 'device_type', 'browser_family', 'bot_name'];

		if (!\in_array($field, $allowed, true))
		{
			throw new \InvalidArgumentException('Unsupported statistics dimension.');
		}

		$db = $this->getDatabase();
		$query = $db->getQuery(true)
			->select([
				$db->quoteName($field) . ' AS ' . $db->quoteName('label'),
				'COUNT(*) AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__simplestats_events'))
			->where($db->quoteName('is_bot') . ' = ' . ($bots ? '1' : '0'))
			->where($db->quoteName('event_type') . ' = :eventType')
			->group($db->quoteName($field))
			->order($db->quoteName('count') . ' DESC')
			->bind(':eventType', $eventType);

		if ($excludeEmpty)
		{
			$query->where($db->quoteName($field) . ' <> ' . $db->quote(''));
		}

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);
		$dimensionKeys = [
			'path' => 'path',
			'referrer_host' => 'referrer',
			'country_code' => 'country',
			'language_code' => 'language',
			'device_type' => 'device',
			'browser_family' => 'browser',
			'bot_name' => 'bot',
		];

		return $this->mergeCountRows(
			$db->loadObjectList(),
			$this->getArchivedDimensionRows($dimensionKeys[$field], $from, $to),
			$limit
		);
	}

	/**
	 * Returns the most frequent entities for one custom event type.
	 *
	 * @param string $eventType Event type.
	 * @param string $from      Inclusive date.
	 * @param string $to        Inclusive date.
	 * @param int    $limit     Maximum rows.
	 *
	 * @return array<int, object>
	 */
	private function getEventItems(string $eventType, string $from, string $to, int $limit): array
	{
		$db = $this->getDatabase();
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('item_title'),
				$db->quoteName('item_id'),
				$db->quoteName('item_type'),
				$db->quoteName('path'),
				'COUNT(*) AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__simplestats_events'))
			->where($db->quoteName('is_bot') . ' = 0')
			->where($db->quoteName('event_type') . ' = :eventType')
			->group([
				$db->quoteName('item_title'),
				$db->quoteName('item_id'),
				$db->quoteName('item_type'),
				$db->quoteName('path'),
			])
			->order($db->quoteName('count') . ' DESC')
			->bind(':eventType', $eventType);

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);
		$raw = $db->loadObjectList();
		$aggregateQuery = $db->getQuery(true)
			->select([
				$db->quoteName('item_title'),
				$db->quoteName('item_id'),
				$db->quoteName('item_type'),
				$db->quoteName('path'),
				'SUM(' . $db->quoteName('event_count') . ') AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__simplestats_daily_items'))
			->where($db->quoteName('event_type') . ' = :archivedEventType')
			->group([
				$db->quoteName('item_title'),
				$db->quoteName('item_id'),
				$db->quoteName('item_type'),
				$db->quoteName('path'),
			])
			->bind(':archivedEventType', $eventType);

		$this->applyDateRange($aggregateQuery, $from, $to);
		$db->setQuery($aggregateQuery);

		return $this->mergeItemRows($raw, $db->loadObjectList(), $limit);
	}

	/**
	 * Returns custom event totals by type.
	 *
	 * @param string $from  Inclusive date.
	 * @param string $to    Inclusive date.
	 * @param int    $limit Maximum rows.
	 *
	 * @return array<int, object>
	 */
	private function getEventTypes(string $from, string $to, int $limit): array
	{
		$db = $this->getDatabase();
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('event_type') . ' AS ' . $db->quoteName('label'),
				'COUNT(*) AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__simplestats_events'))
			->where($db->quoteName('is_bot') . ' = 0')
			->where($db->quoteName('event_type') . ' <> ' . $db->quote('pageview'))
			->group($db->quoteName('event_type'))
			->order($db->quoteName('count') . ' DESC');

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);

		return $this->mergeCountRows(
			$db->loadObjectList(),
			$this->getArchivedDimensionRows('event_type', $from, $to),
			$limit
		);
	}

	/**
	 * Returns archived counts for one named dashboard dimension.
	 *
	 * @param string $dimensionKey Aggregate dimension identifier.
	 * @param string $from         Inclusive date.
	 * @param string $to           Inclusive date.
	 *
	 * @return array<int, object>
	 */
	private function getArchivedDimensionRows(string $dimensionKey, string $from, string $to): array
	{
		$db = $this->getDatabase();
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('label'),
				'SUM(' . $db->quoteName('event_count') . ') AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__simplestats_daily_dimensions'))
			->where($db->quoteName('dimension_key') . ' = :dimensionKey')
			->group($db->quoteName('label'))
			->bind(':dimensionKey', $dimensionKey);

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);

		return $db->loadObjectList();
	}

	/**
	 * Merges raw and archived daily counters.
	 *
	 * @param array<int, object> $raw       Raw-event rows.
	 * @param array<int, object> $archived  Archived report rows.
	 * @param int                $limit     Maximum result rows.
	 *
	 * @return array<int, object>
	 */
	private function mergeDailyRows(array $raw, array $archived, int $limit): array
	{
		$rows = [];
		$fields = ['visits', 'pageviews', 'plays', 'downloads', 'bots'];

		foreach (array_merge($raw, $archived) as $source)
		{
			$date = (string) ($source->visit_date ?? '');

			if ($date === '')
			{
				continue;
			}

			if (!isset($rows[$date]))
			{
				$rows[$date] = (object) ['visit_date' => $date];

				foreach ($fields as $field)
				{
					$rows[$date]->{$field} = 0;
				}
			}

			foreach ($fields as $field)
			{
				$rows[$date]->{$field} += (int) ($source->{$field} ?? 0);
			}
		}

		krsort($rows, SORT_STRING);

		return array_slice(array_values($rows), 0, $limit);
	}

	/**
	 * Merges rows containing a label and count.
	 *
	 * @param array<int, object> $raw       Raw-event rows.
	 * @param array<int, object> $archived  Archived report rows.
	 * @param int                $limit     Maximum result rows.
	 *
	 * @return array<int, object>
	 */
	private function mergeCountRows(array $raw, array $archived, int $limit): array
	{
		$rows = [];

		foreach (array_merge($raw, $archived) as $row)
		{
			$label = (string) ($row->label ?? '');
			$key = mb_strtolower($label, 'UTF-8');

			if (!isset($rows[$key]))
			{
				$rows[$key] = (object) [
					'label' => $label,
					'count' => 0,
				];
			}

			$rows[$key]->count += (int) ($row->count ?? 0);
		}

		$rows = array_values($rows);
		usort(
			$rows,
			static fn(object $left, object $right): int =>
				((int) $right->count <=> (int) $left->count)
				?: strnatcasecmp((string) $left->label, (string) $right->label)
		);

		return array_slice($rows, 0, $limit);
	}

	/**
	 * Merges raw and archived custom-event item rows.
	 *
	 * @param array<int, object> $raw       Raw-event rows.
	 * @param array<int, object> $archived  Archived report rows.
	 * @param int                $limit     Maximum result rows.
	 *
	 * @return array<int, object>
	 */
	private function mergeItemRows(array $raw, array $archived, int $limit): array
	{
		$rows = [];

		foreach (array_merge($raw, $archived) as $source)
		{
			$values = [
				(string) ($source->item_title ?? ''),
				(string) ($source->item_id ?? ''),
				(string) ($source->item_type ?? ''),
				(string) ($source->path ?? ''),
			];
			$keyValues = array_map(
				static fn(string $value): string => mb_strtolower($value, 'UTF-8'),
				$values
			);
			$key = json_encode($keyValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
				?: implode("\0", $keyValues);

			if (!isset($rows[$key]))
			{
				$rows[$key] = (object) [
					'item_title' => $values[0],
					'item_id' => $values[1],
					'item_type' => $values[2],
					'path' => $values[3],
					'count' => 0,
				];
			}

			$rows[$key]->count += (int) ($source->count ?? 0);
		}

		$rows = array_values($rows);
		usort(
			$rows,
			static fn(object $left, object $right): int =>
				((int) $right->count <=> (int) $left->count)
				?: strnatcasecmp((string) $left->item_title, (string) $right->item_title)
		);

		return array_slice($rows, 0, $limit);
	}

	/**
	 * Applies an inclusive date range to a query.
	 *
	 * @param DatabaseQuery $query Query object.
	 * @param string        $from  Inclusive date.
	 * @param string        $to    Inclusive date.
	 *
	 * @return void
	 */
	private function applyDateRange(DatabaseQuery $query, string $from, string $to): void
	{
		$query
			->where($this->getDatabase()->quoteName('visit_date') . ' >= :from')
			->where($this->getDatabase()->quoteName('visit_date') . ' <= :to')
			->bind(':from', $from)
			->bind(':to', $to);
	}

	/**
	 * Calculates dashboard date bounds in the site timezone.
	 *
	 * @param int $days Number of days, or zero for all data.
	 *
	 * @return array{0:string, 1:string}
	 */
	private function getDateBounds(int $days): array
	{
		$timezone = Factory::getApplication()->get('offset', 'UTC');
		$to = new Date('now', $timezone);
		$from = clone $to;

		if ($days <= 0)
		{
			$from->setDate(2000, 1, 1);
		}
		else
		{
			$from->modify('-' . max(0, $days - 1) . ' days');
		}

		return [$from->format('Y-m-d'), $to->format('Y-m-d')];
	}
}
