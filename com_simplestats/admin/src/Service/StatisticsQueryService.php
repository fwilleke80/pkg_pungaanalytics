<?php

declare(strict_types=1);

namespace Willeke\Component\Simplestats\Administrator\Service;

use DateTimeImmutable;
use Joomla\CMS\Date\Date;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\DatabaseQuery;

\defined('_JEXEC') or die;

/**
 * Queries raw and archived statistics through one consistent reporting API.
 */
final class StatisticsQueryService
{
	/**
	 * Supported full-report definitions.
	 *
	 * @var array<string, array{title:string, kind:string}>
	 */
	private const REPORTS = [
		'activity' => ['title' => 'COM_SIMPLESTATS_ACTIVITY_TREND', 'kind' => 'trend'],
		'hours' => ['title' => 'COM_SIMPLESTATS_BY_HOUR', 'kind' => 'hour'],
		'weekdays' => ['title' => 'COM_SIMPLESTATS_BY_WEEKDAY', 'kind' => 'weekday'],
		'pages' => ['title' => 'COM_SIMPLESTATS_TOP_PAGES', 'kind' => 'dimension'],
		'plays' => ['title' => 'COM_SIMPLESTATS_TOP_PLAYS', 'kind' => 'items'],
		'downloads' => ['title' => 'COM_SIMPLESTATS_TOP_DOWNLOADS', 'kind' => 'items'],
		'countries' => ['title' => 'COM_SIMPLESTATS_COUNTRIES', 'kind' => 'country'],
		'referrers' => ['title' => 'COM_SIMPLESTATS_REFERRERS', 'kind' => 'dimension'],
		'languages' => ['title' => 'COM_SIMPLESTATS_LANGUAGES', 'kind' => 'dimension'],
		'devices' => ['title' => 'COM_SIMPLESTATS_DEVICES', 'kind' => 'dimension'],
		'browsers' => ['title' => 'COM_SIMPLESTATS_BROWSERS', 'kind' => 'dimension'],
		'bots' => ['title' => 'COM_SIMPLESTATS_BOT_NAMES', 'kind' => 'dimension'],
		'events' => ['title' => 'COM_SIMPLESTATS_CUSTOM_EVENTS', 'kind' => 'dimension'],
	];

	/**
	 * Creates the query service.
	 *
	 * @param DatabaseInterface $database Database connection.
	 * @param string            $timezone Site timezone.
	 */
	public function __construct(
		private DatabaseInterface $database,
		private string $timezone
	)
	{
	}

	/**
	 * Returns all dashboard data for a selected range.
	 *
	 * @param int $days      Number of days, or zero for all data.
	 * @param int $tableRows Maximum audio rows on the dashboard, or zero for all.
	 *
	 * @return array<string, mixed>
	 */
	public function getDashboardData(int $days, int $tableRows): array
	{
		[$from, $to] = $this->getDateBounds($days);
		$granularity = $this->getTrendGranularity($from, $to);
		$features = $this->getOptionalFeatures();
		$tableRows = max(0, $tableRows);

		return [
			'from' => $from,
			'to' => $to,
			'features' => $features,
			'trendGranularity' => $granularity,
			'summary' => $this->getSummary($from, $to),
			'trend' => $this->getTrend($from, $to, $granularity),
			'hours' => $this->getTimeRows('hour', $from, $to),
			'weekdays' => $this->getTimeRows('weekday', $from, $to),
			'topPages' => $this->getDimensionRows('path', $from, $to, 15, false, 'pageview'),
			'topPlays' => $features['audioPlays'] ? $this->getEventItems('audio.play', $from, $to, $tableRows) : [],
			'topDownloads' => $features['audioDownloads'] ? $this->getEventItems('audio.download', $from, $to, $tableRows) : [],
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
	 * Returns a complete named report without pagination.
	 *
	 * @param string $report Report identifier.
	 * @param int    $days   Number of days, or zero for all data.
	 *
	 * @return array{
	 *   report:string,
	 *   title:string,
	 *   kind:string,
	 *   features:array{audioPlays:bool,audioDownloads:bool},
	 *   from:string,
	 *   to:string,
	 *   rows:array<int, object>
	 * }
	 */
	public function getReportData(string $report, int $days): array
	{
		$report = strtolower(trim($report));

		if (!isset(self::REPORTS[$report]))
		{
			throw new \InvalidArgumentException('Unsupported Simple Stats report.');
		}

		[$from, $to] = $this->getDateBounds($days);
		$features = $this->getOptionalFeatures();
		$rows = match ($report)
		{
			'activity' => $this->getTrend($from, $to, $this->getTrendGranularity($from, $to)),
			'hours' => $this->getTimeRows('hour', $from, $to),
			'weekdays' => $this->getTimeRows('weekday', $from, $to),
			'pages' => $this->getDimensionRows('path', $from, $to, 0, false, 'pageview'),
			'plays' => $this->getEventItems('audio.play', $from, $to, 0),
			'downloads' => $this->getEventItems('audio.download', $from, $to, 0),
			'countries' => $this->getCountryRows($from, $to, 0),
			'referrers' => $this->getDimensionRows('referrer_host', $from, $to, 0, false, 'pageview', true),
			'languages' => $this->getDimensionRows('language_code', $from, $to, 0, false, 'pageview', true),
			'devices' => $this->getDimensionRows('device_type', $from, $to, 0, false, 'pageview', true),
			'browsers' => $this->getDimensionRows('browser_family', $from, $to, 0, false, 'pageview', true),
			'bots' => $this->getDimensionRows('bot_name', $from, $to, 0, true, 'pageview', true),
			'events' => $this->getEventTypes($from, $to, 0),
		};

		return [
			'report' => $report,
			'title' => self::REPORTS[$report]['title'],
			'kind' => self::REPORTS[$report]['kind'],
			'features' => $features,
			'from' => $from,
			'to' => $to,
			'rows' => $rows,
		];
	}

	/**
	 * Detects optional report families from recorded custom event types.
	 *
	 * Detection is all-time so an optional report does not disappear merely
	 * because the currently selected range contains no matching events.
	 *
	 * @return array{audioPlays:bool, audioDownloads:bool}
	 */
	private function getOptionalFeatures(): array
	{
		$db = $this->database;
		$eventTypes = ['audio.play', 'audio.download'];
		$quotedEventTypes = implode(', ', array_map(
			fn(string $eventType): string => $db->quote($eventType),
			$eventTypes
		));
		$query = $db->getQuery(true)
			->select('DISTINCT ' . $db->quoteName('event_type'))
			->from($db->quoteName('#__simplestats_events'))
			->where($db->quoteName('is_bot') . ' = 0')
			->where($db->quoteName('event_type') . ' IN (' . $quotedEventTypes . ')');
		$db->setQuery($query);
		$foundEventTypes = array_fill_keys(array_map('strval', $db->loadColumn()), true);
		$archiveQuery = $db->getQuery(true)
			->select('DISTINCT ' . $db->quoteName('label'))
			->from($db->quoteName('#__simplestats_daily_dimensions'))
			->where($db->quoteName('dimension_key') . ' = ' . $db->quote('event_type'))
			->where($db->quoteName('label') . ' IN (' . $quotedEventTypes . ')');
		$db->setQuery($archiveQuery);

		foreach ($db->loadColumn() as $eventType)
		{
			$foundEventTypes[(string) $eventType] = true;
		}

		return [
			'audioPlays' => isset($foundEventTypes['audio.play']),
			'audioDownloads' => isset($foundEventTypes['audio.download']),
		];
	}

	/**
	 * Returns whether a full report identifier is supported.
	 *
	 * @param string $report Report identifier.
	 *
	 * @return bool
	 */
	public function isSupportedReport(string $report): bool
	{
		return isset(self::REPORTS[strtolower(trim($report))]);
	}

	/**
	 * Calculates reporting date bounds in the site timezone.
	 *
	 * @param int $days Number of days, or zero for all data.
	 *
	 * @return array{0:string, 1:string}
	 */
	public function getDateBounds(int $days): array
	{
		$to = new Date('now', $this->timezone);
		$from = clone $to;

		if ($days <= 0)
		{
			$earliest = $this->getEarliestDate();

			if ($earliest !== '')
			{
				$from = new Date($earliest . ' 00:00:00', $this->timezone);
			}
		}
		else
		{
			$from->modify('-' . max(0, $days - 1) . ' days');
		}

		return [$from->format('Y-m-d'), $to->format('Y-m-d')];
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
		$db = $this->database;
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
	 * Returns adaptive day, week, or month trend rows.
	 *
	 * @param string $from        Inclusive date.
	 * @param string $to          Inclusive date.
	 * @param string $granularity day, week, or month.
	 *
	 * @return array<int, object>
	 */
	private function getTrend(string $from, string $to, string $granularity): array
	{
		$dailyRows = $this->getDailyRows($from, $to);

		if ($dailyRows === [])
		{
			return [];
		}

		$buckets = [];
		$fields = ['visits', 'pageviews', 'plays', 'downloads', 'bots'];
		$cursor = match ($granularity)
		{
			'month' => new DateTimeImmutable(substr($from, 0, 7) . '-01'),
			'week' => (new DateTimeImmutable($from))->modify('monday this week'),
			default => new DateTimeImmutable($from),
		};
		$end = new DateTimeImmutable($to);
		$step = match ($granularity)
		{
			'month' => '+1 month',
			'week' => '+1 week',
			default => '+1 day',
		};

		while ($cursor <= $end)
		{
			$key = $cursor->format('Y-m-d');
			$buckets[$key] = (object) [
				'period_start' => $key,
				'period_label' => match ($granularity)
				{
					'month' => $cursor->format('Y-m'),
					'week' => $cursor->format('o-\WW'),
					default => $key,
				},
			];

			foreach ($fields as $field)
			{
				$buckets[$key]->{$field} = 0;
			}

			$cursor = $cursor->modify($step);
		}

		foreach ($dailyRows as $row)
		{
			$date = (string) $row->visit_date;
			$periodStart = match ($granularity)
			{
				'month' => substr($date, 0, 7) . '-01',
				'week' => (new DateTimeImmutable($date))->modify('monday this week')->format('Y-m-d'),
				default => $date,
			};
			$key = $periodStart;

			foreach ($fields as $field)
			{
				$buckets[$key]->{$field} += (int) ($row->{$field} ?? 0);
			}
		}

		ksort($buckets, SORT_STRING);

		return array_values($buckets);
	}

	/**
	 * Returns merged daily rows without truncation.
	 *
	 * @param string $from Inclusive date.
	 * @param string $to   Inclusive date.
	 *
	 * @return array<int, object>
	 */
	private function getDailyRows(string $from, string $to): array
	{
		$db = $this->database;
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
			->group($db->quoteName('visit_date'));

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);
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
			->from($db->quoteName('#__simplestats_daily'));

		$this->applyDateRange($aggregateQuery, $from, $to);
		$db->setQuery($aggregateQuery);

		return $this->mergeDailyRows($raw, $db->loadObjectList());
	}

	/**
	 * Returns activity grouped by local hour or ISO weekday.
	 *
	 * @param string $kind hour or weekday.
	 * @param string $from Inclusive date.
	 * @param string $to   Inclusive date.
	 *
	 * @return array<int, object>
	 */
	private function getTimeRows(string $kind, string $from, string $to): array
	{
		if (!\in_array($kind, ['hour', 'weekday'], true))
		{
			throw new \InvalidArgumentException('Unsupported statistics time bucket.');
		}

		$db = $this->database;
		$field = $kind === 'hour' ? 'visit_hour' : 'visit_weekday';
		$minimum = $kind === 'hour' ? 0 : 1;
		$maximum = $kind === 'hour' ? 23 : 7;
		$dailyVisitor = 'CONCAT(' . $db->quoteName('visit_date') . ', ' . $db->quote(':') . ', ' . $db->quoteName('visitor_hash') . ')';
		$pageView = $db->quoteName('event_type') . ' = ' . $db->quote('pageview');
		$human = $db->quoteName('is_bot') . ' = 0';
		$query = $db->getQuery(true)
			->select([
				$db->quoteName($field) . ' AS ' . $db->quoteName('bucket'),
				'COUNT(DISTINCT CASE WHEN ' . $human . ' AND ' . $pageView . ' THEN ' . $dailyVisitor . ' END) AS ' . $db->quoteName('visits'),
				'SUM(CASE WHEN ' . $human . ' AND ' . $pageView . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('pageviews'),
				'SUM(CASE WHEN ' . $human . ' AND ' . $db->quoteName('event_type') . ' = ' . $db->quote('audio.play') . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('plays'),
				'SUM(CASE WHEN ' . $human . ' AND ' . $db->quoteName('event_type') . ' = ' . $db->quote('audio.download') . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('downloads'),
				'SUM(CASE WHEN ' . $db->quoteName('is_bot') . ' = 1 AND ' . $pageView . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('bots'),
			])
			->from($db->quoteName('#__simplestats_events'))
			->where($db->quoteName($field) . ' >= ' . $minimum)
			->where($db->quoteName($field) . ' <= ' . $maximum)
			->group($db->quoteName($field));

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);
		$raw = $db->loadObjectList();
		$archiveQuery = $db->getQuery(true)
			->select([
				$db->quoteName('bucket_value') . ' AS ' . $db->quoteName('bucket'),
				'SUM(' . $db->quoteName('human_visits') . ') AS ' . $db->quoteName('visits'),
				'SUM(' . $db->quoteName('human_pageviews') . ') AS ' . $db->quoteName('pageviews'),
				'SUM(' . $db->quoteName('plays') . ') AS ' . $db->quoteName('plays'),
				'SUM(' . $db->quoteName('downloads') . ') AS ' . $db->quoteName('downloads'),
				'SUM(' . $db->quoteName('bot_pageviews') . ') AS ' . $db->quoteName('bots'),
			])
			->from($db->quoteName('#__simplestats_daily_time'))
			->where($db->quoteName('bucket_kind') . ' = :bucketKind')
			->group($db->quoteName('bucket_value'))
			->bind(':bucketKind', $kind);

		$this->applyDateRange($archiveQuery, $from, $to);
		$db->setQuery($archiveQuery);

		return $this->mergeTimeRows($raw, $db->loadObjectList(), $minimum, $maximum);
	}

	/**
	 * Returns human visitor-days grouped by country.
	 *
	 * @param string $from  Inclusive date.
	 * @param string $to    Inclusive date.
	 * @param int    $limit Maximum rows, or zero for all.
	 *
	 * @return array<int, object>
	 */
	private function getCountryRows(string $from, string $to, int $limit): array
	{
		$db = $this->database;
		$dailyVisitor = 'CONCAT(' . $db->quoteName('visit_date') . ', ' . $db->quote(':') . ', ' . $db->quoteName('visitor_hash') . ')';
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('country_code') . ' AS ' . $db->quoteName('label'),
				'COUNT(DISTINCT ' . $dailyVisitor . ') AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__simplestats_events'))
			->where($db->quoteName('is_bot') . ' = 0')
			->where($db->quoteName('event_type') . ' = ' . $db->quote('pageview'))
			->group($db->quoteName('country_code'));

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);

		return $this->mergeCountRows(
			$db->loadObjectList(),
			$this->getArchivedDimensionRows('country', $from, $to),
			$limit
		);
	}

	/**
	 * Returns grouped event dimensions.
	 *
	 * @param string $field        Database field.
	 * @param string $from         Inclusive date.
	 * @param string $to           Inclusive date.
	 * @param int    $limit        Maximum rows, or zero for all.
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
		$allowed = ['path', 'referrer_host', 'language_code', 'device_type', 'browser_family', 'bot_name'];

		if (!\in_array($field, $allowed, true))
		{
			throw new \InvalidArgumentException('Unsupported statistics dimension.');
		}

		$db = $this->database;
		$query = $db->getQuery(true)
			->select([
				$db->quoteName($field) . ' AS ' . $db->quoteName('label'),
				'COUNT(*) AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__simplestats_events'))
			->where($db->quoteName('is_bot') . ' = ' . ($bots ? '1' : '0'))
			->where($db->quoteName('event_type') . ' = :eventType')
			->group($db->quoteName($field))
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
	 * @param int    $limit     Maximum rows, or zero for all.
	 *
	 * @return array<int, object>
	 */
	private function getEventItems(string $eventType, string $from, string $to, int $limit): array
	{
		$db = $this->database;
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
	 * @param int    $limit Maximum rows, or zero for all.
	 *
	 * @return array<int, object>
	 */
	private function getEventTypes(string $from, string $to, int $limit): array
	{
		$db = $this->database;
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('event_type') . ' AS ' . $db->quoteName('label'),
				'COUNT(*) AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__simplestats_events'))
			->where($db->quoteName('is_bot') . ' = 0')
			->where($db->quoteName('event_type') . ' <> ' . $db->quote('pageview'))
			->group($db->quoteName('event_type'));

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);

		return $this->mergeCountRows(
			$db->loadObjectList(),
			$this->getArchivedDimensionRows('event_type', $from, $to),
			$limit
		);
	}

	/**
	 * Returns archived counts for one dashboard dimension.
	 *
	 * @param string $dimensionKey Aggregate dimension identifier.
	 * @param string $from         Inclusive date.
	 * @param string $to           Inclusive date.
	 *
	 * @return array<int, object>
	 */
	private function getArchivedDimensionRows(string $dimensionKey, string $from, string $to): array
	{
		$db = $this->database;
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
	 * Merges raw and archived daily rows.
	 *
	 * @param array<int, object> $raw      Raw rows.
	 * @param array<int, object> $archived Archived rows.
	 *
	 * @return array<int, object>
	 */
	private function mergeDailyRows(array $raw, array $archived): array
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

		ksort($rows, SORT_STRING);

		return array_values($rows);
	}

	/**
	 * Merges raw and archived time buckets and fills empty buckets.
	 *
	 * @param array<int, object> $raw      Raw rows.
	 * @param array<int, object> $archived Archived rows.
	 * @param int                $minimum  First bucket.
	 * @param int                $maximum  Last bucket.
	 *
	 * @return array<int, object>
	 */
	private function mergeTimeRows(array $raw, array $archived, int $minimum, int $maximum): array
	{
		$rows = [];
		$fields = ['visits', 'pageviews', 'plays', 'downloads', 'bots'];

		for ($bucket = $minimum; $bucket <= $maximum; $bucket++)
		{
			$rows[$bucket] = (object) ['bucket' => $bucket];

			foreach ($fields as $field)
			{
				$rows[$bucket]->{$field} = 0;
			}
		}

		foreach (array_merge($raw, $archived) as $source)
		{
			$bucket = (int) ($source->bucket ?? -1);

			if (!isset($rows[$bucket]))
			{
				continue;
			}

			foreach ($fields as $field)
			{
				$rows[$bucket]->{$field} += (int) ($source->{$field} ?? 0);
			}
		}

		return array_values($rows);
	}

	/**
	 * Merges rows containing a label and count.
	 *
	 * @param array<int, object> $raw      Raw rows.
	 * @param array<int, object> $archived Archived rows.
	 * @param int                $limit    Maximum rows, or zero for all.
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

		return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
	}

	/**
	 * Merges raw and archived custom-event item rows.
	 *
	 * @param array<int, object> $raw      Raw rows.
	 * @param array<int, object> $archived Archived rows.
	 * @param int                $limit    Maximum rows, or zero for all.
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

		return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
	}

	/**
	 * Applies an inclusive visit-date range to a query.
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
			->where($this->database->quoteName('visit_date') . ' >= :from')
			->where($this->database->quoteName('visit_date') . ' <= :to')
			->bind(':from', $from)
			->bind(':to', $to);
	}

	/**
	 * Returns the earliest raw or archived visit date.
	 *
	 * @return string
	 */
	private function getEarliestDate(): string
	{
		$db = $this->database;
		$dates = [];

		foreach (['#__simplestats_events', '#__simplestats_daily'] as $table)
		{
			$query = $db->getQuery(true)
				->select('MIN(' . $db->quoteName('visit_date') . ')')
				->from($db->quoteName($table));
			$db->setQuery($query);
			$value = (string) $db->loadResult();

			if ($value !== '')
			{
				$dates[] = $value;
			}
		}

		sort($dates, SORT_STRING);

		return $dates[0] ?? '';
	}

	/**
	 * Chooses a readable trend granularity for the selected span.
	 *
	 * @param string $from Inclusive date.
	 * @param string $to   Inclusive date.
	 *
	 * @return string day, week, or month.
	 */
	private function getTrendGranularity(string $from, string $to): string
	{
		$span = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;

		if ($span <= 90)
		{
			return 'day';
		}

		if ($span <= 366)
		{
			return 'week';
		}

		return 'month';
	}
}
