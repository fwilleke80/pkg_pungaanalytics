<?php

declare(strict_types=1);

namespace Punga\Component\PungaAnalytics\Administrator\Service;

use DateTimeImmutable;
use Joomla\CMS\Component\ComponentHelper;
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
		'activity' => ['title' => 'COM_PUNGAANALYTICS_ACTIVITY_TREND', 'kind' => 'trend'],
		'hours' => ['title' => 'COM_PUNGAANALYTICS_BY_HOUR', 'kind' => 'hour'],
		'weekdays' => ['title' => 'COM_PUNGAANALYTICS_BY_WEEKDAY', 'kind' => 'weekday'],
		'pages' => ['title' => 'COM_PUNGAANALYTICS_TOP_PAGES', 'kind' => 'dimension'],
		'countries' => ['title' => 'COM_PUNGAANALYTICS_COUNTRIES', 'kind' => 'country'],
		'referrers' => ['title' => 'COM_PUNGAANALYTICS_REFERRERS', 'kind' => 'dimension'],
		'languages' => ['title' => 'COM_PUNGAANALYTICS_LANGUAGES', 'kind' => 'dimension'],
		'devices' => ['title' => 'COM_PUNGAANALYTICS_DEVICES', 'kind' => 'dimension'],
		'browsers' => ['title' => 'COM_PUNGAANALYTICS_BROWSERS', 'kind' => 'dimension'],
		'bots' => ['title' => 'COM_PUNGAANALYTICS_BOT_NAMES', 'kind' => 'dimension'],
		'events' => ['title' => 'COM_PUNGAANALYTICS_CUSTOM_EVENTS', 'kind' => 'dimension'],
	];

	/** @var array<int, array<string, mixed>> */
	private array $customEventDefinitions;

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
		$this->customEventDefinitions = (new CustomEventDefinitionService())->getDefinitions(
			ComponentHelper::getParams('com_pungaanalytics')
		);
	}

	/**
	 * Returns all dashboard data for a selected range.
	 *
	 * @param int $days      Number of days, or zero for all data.
	 * @param int $tableRows Maximum activity and event rows on the dashboard, or zero for all.
	 *
	 * @return array<string, mixed>
	 */
	public function getDashboardData(int $days, int $tableRows): array
	{
		[$from, $to] = $this->getDateBounds($days);
		$granularity = $this->getTrendGranularity($from, $to);
		$tableRows = max(0, $tableRows);
		$rankings = [];

		foreach ($this->getDefinitionsFor('show_ranking') as $definition)
		{
			$rankings[] = [
				'definition' => $definition,
				'rows' => $this->getEventItems(
					(string) $definition['event_type'],
					$from,
					$to,
					$tableRows
				),
			];
		}

		return [
			'from' => $from,
			'to' => $to,
			'customEventDefinitions' => $this->customEventDefinitions,
			'customEventRankings' => $rankings,
			'trendGranularity' => $granularity,
			'summary' => $this->getSummary($from, $to),
			'trend' => $this->getTrend($from, $to, $granularity),
			'hours' => $this->getTimeRows('hour', $from, $to),
			'weekdays' => $this->getTimeRows('weekday', $from, $to),
			'topPages' => $this->getDimensionRows('path', $from, $to, 15, false, 'pageview'),
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
	 * @param string $report    Report identifier.
	 * @param int    $days      Number of days, or zero for all data.
	 * @param string $eventType Custom event identifier for the generic event report.
	 *
	 * @return array{
	 *   report:string,
	 *   title:string,
	 *   kind:string,
	 *   customEventDefinitions:array<int, array<string, mixed>>,
	 *   from:string,
	 *   to:string,
	 *   rows:array<int, object>
	 * }
	 */
	public function getReportData(string $report, int $days, string $eventType = ''): array
	{
		$report = strtolower(trim($report));
		$eventType = strtolower(trim($eventType));
		$eventDefinition = $report === 'event'
			? $this->getDefinition($eventType)
			: null;

		if (!isset(self::REPORTS[$report]) && $eventDefinition === null)
		{
			throw new \InvalidArgumentException('Unsupported Punga Analytics report.');
		}

		[$from, $to] = $this->getDateBounds($days);
		$rows = match ($report)
		{
			'activity' => $this->getTrend($from, $to, $this->getTrendGranularity($from, $to)),
			'hours' => $this->getTimeRows('hour', $from, $to),
			'weekdays' => $this->getTimeRows('weekday', $from, $to),
			'pages' => $this->getDimensionRows('path', $from, $to, 0, false, 'pageview'),
			'event' => $this->getEventItems($eventType, $from, $to, 0),
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
			'title' => $eventDefinition !== null
				? (string) $eventDefinition['report_title']
				: self::REPORTS[$report]['title'],
			'kind' => $eventDefinition !== null ? 'items' : self::REPORTS[$report]['kind'],
			'from' => $from,
			'to' => $to,
			'rows' => $rows,
			'event_type' => $eventType,
			'customEventDefinitions' => $this->customEventDefinitions,
		];
	}

	/**
	 * Returns whether a full report identifier is supported.
	 *
	 * @param string $report    Report identifier.
	 * @param string $eventType Custom event identifier for the generic event report.
	 *
	 * @return bool
	 */
	public function isSupportedReport(string $report, string $eventType = ''): bool
	{
		$report = strtolower(trim($report));

		return isset(self::REPORTS[$report])
			|| ($report === 'event' && $this->getDefinition(strtolower(trim($eventType))) !== null);
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
				'SUM(CASE WHEN ' . $human . ' AND ' . $db->quoteName('event_type') . ' <> ' . $db->quote('pageview') . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('custom_events'),
			])
			->from($db->quoteName('#__pungaanalytics_events'));

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
				'SUM(' . $db->quoteName('custom_events') . ') AS ' . $db->quoteName('custom_events'),
			])
			->from($db->quoteName('#__pungaanalytics_daily'));

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
			'custom_events',
		] as $field)
		{
			$result->{$field} = (int) ($raw->{$field} ?? 0) + (int) ($aggregate->{$field} ?? 0);
		}

		$result->events = $this->getEventTotals($from, $to);

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
		$fields = ['visits', 'pageviews', 'bots'];
		$eventTypes = array_column($this->getDefinitionsFor('show_trend'), 'event_type');
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

			$buckets[$key]->events = array_fill_keys($eventTypes, 0);
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

		foreach ($this->getEventDailyRows($eventTypes, $from, $to) as $row)
		{
			$date = (string) $row->visit_date;
			$periodStart = match ($granularity)
			{
				'month' => substr($date, 0, 7) . '-01',
				'week' => (new DateTimeImmutable($date))->modify('monday this week')->format('Y-m-d'),
				default => $date,
			};
			$eventType = (string) $row->event_type;

			if (isset($buckets[$periodStart]->events[$eventType]))
			{
				$buckets[$periodStart]->events[$eventType] += (int) $row->count;
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
				'SUM(CASE WHEN ' . $db->quoteName('is_bot') . ' = 1 AND ' . $pageView . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('bots'),
			])
			->from($db->quoteName('#__pungaanalytics_events'))
			->group($db->quoteName('visit_date'));

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);
		$raw = $db->loadObjectList();
		$aggregateQuery = $db->getQuery(true)
			->select([
				$db->quoteName('visit_date'),
				$db->quoteName('human_visits') . ' AS ' . $db->quoteName('visits'),
				$db->quoteName('human_pageviews') . ' AS ' . $db->quoteName('pageviews'),
				$db->quoteName('bot_pageviews') . ' AS ' . $db->quoteName('bots'),
			])
			->from($db->quoteName('#__pungaanalytics_daily'));

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
				'SUM(CASE WHEN ' . $db->quoteName('is_bot') . ' = 1 AND ' . $pageView . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('bots'),
			])
			->from($db->quoteName('#__pungaanalytics_events'))
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
				'SUM(' . $db->quoteName('bot_pageviews') . ') AS ' . $db->quoteName('bots'),
			])
			->from($db->quoteName('#__pungaanalytics_daily_time'))
			->where($db->quoteName('bucket_kind') . ' = :bucketKind')
			->group($db->quoteName('bucket_value'))
			->bind(':bucketKind', $kind);

		$this->applyDateRange($archiveQuery, $from, $to);
		$db->setQuery($archiveQuery);

		$rows = $this->mergeTimeRows($raw, $db->loadObjectList(), $minimum, $maximum);
		$eventTypes = array_column($this->getDefinitionsFor('show_time'), 'event_type');
		$rowsByBucket = [];

		foreach ($rows as $row)
		{
			$row->events = array_fill_keys($eventTypes, 0);
			$rowsByBucket[(int) $row->bucket] = $row;
		}

		foreach ($this->getEventTimeRows($eventTypes, $kind, $field, $from, $to) as $eventRow)
		{
			$bucket = (int) $eventRow->bucket;
			$eventType = (string) $eventRow->event_type;

			if (isset($rowsByBucket[$bucket]->events[$eventType]))
			{
				$rowsByBucket[$bucket]->events[$eventType] += (int) $eventRow->count;
			}
		}

		return $rows;
	}

	/**
	 * Returns configured definitions enabled for one presentation surface.
	 *
	 * @param string $flag Definition boolean flag.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function getDefinitionsFor(string $flag): array
	{
		return array_values(array_filter(
			$this->customEventDefinitions,
			static fn(array $definition): bool => (bool) ($definition[$flag] ?? false)
		));
	}

	/**
	 * Returns one configured definition by event identifier.
	 *
	 * @param string $eventType Event identifier.
	 *
	 * @return array<string, mixed>|null
	 */
	private function getDefinition(string $eventType): ?array
	{
		foreach ($this->customEventDefinitions as $definition)
		{
			if ((string) $definition['event_type'] === $eventType)
			{
				return $definition;
			}
		}

		return null;
	}

	/**
	 * Returns totals for every configured custom event.
	 *
	 * @param string $from Inclusive date.
	 * @param string $to   Inclusive date.
	 *
	 * @return array<string, int>
	 */
	private function getEventTotals(string $from, string $to): array
	{
		$eventTypes = array_column($this->customEventDefinitions, 'event_type');
		$totals = array_fill_keys($eventTypes, 0);

		foreach ($this->getEventDailyRows($eventTypes, $from, $to) as $row)
		{
			$eventType = (string) $row->event_type;

			if (isset($totals[$eventType]))
			{
				$totals[$eventType] += (int) $row->count;
			}
		}

		return $totals;
	}

	/**
	 * Returns raw and archived daily totals for selected custom events.
	 *
	 * @param array<int, string> $eventTypes Event identifiers.
	 * @param string             $from       Inclusive date.
	 * @param string             $to         Inclusive date.
	 *
	 * @return array<int, object>
	 */
	private function getEventDailyRows(array $eventTypes, string $from, string $to): array
	{
		if ($eventTypes === [])
		{
			return [];
		}

		$db = $this->database;
		$eventList = implode(', ', array_map(
			static fn(string $eventType): string => $db->quote($eventType),
			$eventTypes
		));
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('visit_date'),
				$db->quoteName('event_type'),
				'COUNT(*) AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__pungaanalytics_events'))
			->where($db->quoteName('is_bot') . ' = 0')
			->where($db->quoteName('event_type') . ' IN (' . $eventList . ')')
			->group([$db->quoteName('visit_date'), $db->quoteName('event_type')]);
		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);
		$raw = $db->loadObjectList();
		$archiveQuery = $db->getQuery(true)
			->select([
				$db->quoteName('visit_date'),
				$db->quoteName('label') . ' AS ' . $db->quoteName('event_type'),
				'SUM(' . $db->quoteName('event_count') . ') AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__pungaanalytics_daily_dimensions'))
			->where($db->quoteName('dimension_key') . ' = ' . $db->quote('event_type'))
			->where($db->quoteName('label') . ' IN (' . $eventList . ')')
			->group([$db->quoteName('visit_date'), $db->quoteName('label')]);
		$this->applyDateRange($archiveQuery, $from, $to);
		$db->setQuery($archiveQuery);
		$rows = [];

		foreach (array_merge($raw, $db->loadObjectList()) as $source)
		{
			$key = (string) $source->visit_date . "\0" . (string) $source->event_type;

			if (!isset($rows[$key]))
			{
				$rows[$key] = (object) [
					'visit_date' => (string) $source->visit_date,
					'event_type' => (string) $source->event_type,
					'count' => 0,
				];
			}

			$rows[$key]->count += (int) $source->count;
		}

		return array_values($rows);
	}

	/**
	 * Returns raw and archived time totals for selected custom events.
	 *
	 * @param array<int, string> $eventTypes Event identifiers.
	 * @param string             $kind       hour or weekday.
	 * @param string             $field      Raw bucket field.
	 * @param string             $from       Inclusive date.
	 * @param string             $to         Inclusive date.
	 *
	 * @return array<int, object>
	 */
	private function getEventTimeRows(
		array $eventTypes,
		string $kind,
		string $field,
		string $from,
		string $to
	): array
	{
		if ($eventTypes === [])
		{
			return [];
		}

		$db = $this->database;
		$eventList = implode(', ', array_map(
			static fn(string $eventType): string => $db->quote($eventType),
			$eventTypes
		));
		$query = $db->getQuery(true)
			->select([
				$db->quoteName($field) . ' AS ' . $db->quoteName('bucket'),
				$db->quoteName('event_type'),
				'COUNT(*) AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__pungaanalytics_events'))
			->where($db->quoteName('is_bot') . ' = 0')
			->where($db->quoteName('event_type') . ' IN (' . $eventList . ')')
			->group([$db->quoteName($field), $db->quoteName('event_type')]);
		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);
		$raw = $db->loadObjectList();
		$archiveQuery = $db->getQuery(true)
			->select([
				$db->quoteName('bucket_value') . ' AS ' . $db->quoteName('bucket'),
				$db->quoteName('event_type'),
				'SUM(' . $db->quoteName('event_count') . ') AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__pungaanalytics_daily_event_time'))
			->where($db->quoteName('bucket_kind') . ' = :eventBucketKind')
			->where($db->quoteName('event_type') . ' IN (' . $eventList . ')')
			->group([$db->quoteName('bucket_value'), $db->quoteName('event_type')])
			->bind(':eventBucketKind', $kind);
		$this->applyDateRange($archiveQuery, $from, $to);
		$db->setQuery($archiveQuery);
		$rows = [];

		foreach (array_merge($raw, $db->loadObjectList()) as $source)
		{
			$key = (int) $source->bucket . "\0" . (string) $source->event_type;

			if (!isset($rows[$key]))
			{
				$rows[$key] = (object) [
					'bucket' => (int) $source->bucket,
					'event_type' => (string) $source->event_type,
					'count' => 0,
				];
			}

			$rows[$key]->count += (int) $source->count;
		}

		return array_values($rows);
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
			->from($db->quoteName('#__pungaanalytics_events'))
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
			->from($db->quoteName('#__pungaanalytics_events'))
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
			->from($db->quoteName('#__pungaanalytics_events'))
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
			->from($db->quoteName('#__pungaanalytics_daily_items'))
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
			->from($db->quoteName('#__pungaanalytics_events'))
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
			->from($db->quoteName('#__pungaanalytics_daily_dimensions'))
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
		$fields = ['visits', 'pageviews', 'bots'];

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
		$fields = ['visits', 'pageviews', 'bots'];

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

		foreach (['#__pungaanalytics_events', '#__pungaanalytics_daily'] as $table)
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
