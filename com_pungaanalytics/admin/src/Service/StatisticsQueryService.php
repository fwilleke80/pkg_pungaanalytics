<?php

declare(strict_types=1);

namespace Punga\Component\PungaAnalytics\Administrator\Service;

use DateTimeImmutable;
use DateTimeZone;
use Joomla\CMS\Component\ComponentHelper;
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
		'notfound' => ['title' => 'COM_PUNGAANALYTICS_NOT_FOUND', 'kind' => 'notfound'],
		'countries' => ['title' => 'COM_PUNGAANALYTICS_COUNTRIES', 'kind' => 'country'],
		'referrers' => ['title' => 'COM_PUNGAANALYTICS_REFERRERS', 'kind' => 'dimension'],
		'sources' => ['title' => 'COM_PUNGAANALYTICS_TRAFFIC_SOURCES', 'kind' => 'source'],
		'languages' => ['title' => 'COM_PUNGAANALYTICS_LANGUAGES', 'kind' => 'dimension'],
		'devices' => ['title' => 'COM_PUNGAANALYTICS_DEVICES', 'kind' => 'dimension'],
		'browsers' => ['title' => 'COM_PUNGAANALYTICS_BROWSERS', 'kind' => 'dimension'],
		'bots' => ['title' => 'COM_PUNGAANALYTICS_BOT_NAMES', 'kind' => 'dimension'],
		'events' => ['title' => 'COM_PUNGAANALYTICS_CUSTOM_EVENTS', 'kind' => 'dimension'],
	];

	/** @var array<int, array<string, mixed>> */
	private array $customEventDefinitions;

	/** @var string */
	private string $activeRange = '7';

	/** @var string */
	private string $rawFromUtc = '';

	/** @var string */
	private string $rawToUtc = '';

	/** @var string */
	private string $displayFrom = '';

	/** @var string */
	private string $displayTo = '';

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
	 * @param int|string $range     Reporting range identifier.
	 * @param int $tableRows Maximum activity and event rows on the dashboard, or zero for all.
	 *
	 * @return array<string, mixed>
	 */
	public function getDashboardData(int|string $range, int $tableRows): array
	{
		[$from, $to] = $this->setReportingRange($range);
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
			'displayFrom' => $this->displayFrom,
			'displayTo' => $this->displayTo,
			'range' => $this->activeRange,
			'customEventDefinitions' => $this->customEventDefinitions,
			'customEventRankings' => $rankings,
			'trendGranularity' => $granularity,
			'summary' => $this->getSummary($from, $to),
			'trend' => $this->getTrend($from, $to, $granularity),
			'hours' => $this->getTimeRows('hour', $from, $to),
			'weekdays' => $this->getTimeRows('weekday', $from, $to),
			'topPages' => $this->getDimensionRows('path', $from, $to, 15, false, 'pageview'),
			'notFound' => $this->getNotFoundRows($from, $to, 15),
			'eventTypes' => $this->getEventTypes($from, $to, 15),
			'countries' => $this->getCountryRows($from, $to, 20),
			'referrers' => $this->getDimensionRows('referrer_host', $from, $to, 15, false, 'pageview', true),
			'trafficSources' => $this->getTrafficSourceRows($from, $to),
			'languages' => $this->getDimensionRows('language_code', $from, $to, 12, false, 'pageview', true),
			'devices' => $this->getDimensionRows('device_type', $from, $to, 12, false, 'pageview', true),
			'browsers' => $this->getDimensionRows('browser_family', $from, $to, 12, false, 'pageview', true),
			'bots' => $this->getDimensionRows('bot_name', $from, $to, 12, true, 'pageview', true),
		];
	}

	/**
	 * Returns the compact data needed by an administrator dashboard module.
	 *
	 * @param int|string $range         Reporting range identifier.
	 * @param int $topPagesLimit Maximum number of top pages.
	 *
	 * @return array<string, mixed>
	 */
	public function getModuleData(int|string $range, int $topPagesLimit): array
	{
		[$from, $to] = $this->setReportingRange($range);

		return [
			'range' => $this->activeRange,
			'from' => $from,
			'to' => $to,
			'displayFrom' => $this->displayFrom,
			'displayTo' => $this->displayTo,
			'summary' => $this->getSummary($from, $to),
			'customEventDefinitions' => $this->customEventDefinitions,
			'summaryDefinitions' => $this->getDefinitionsFor('show_summary'),
			'topPages' => $topPagesLimit > 0
				? $this->getDimensionRows(
					'path',
					$from,
					$to,
					$topPagesLimit,
					false,
					'pageview'
				)
				: [],
		];
	}

	/**
	 * Returns a complete named report without pagination.
	 *
	 * @param string $report    Report identifier.
	 * @param int|string $range     Reporting range identifier.
	 * @param string $eventType Custom event identifier for the generic event report.
	 * @param array<string, string> $history History dimension arguments.
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
	public function getReportData(
		string $report,
		int|string $range,
		string $eventType = '',
		array $history = []
	): array
	{
		$report = strtolower(trim($report));
		$eventType = strtolower(trim($eventType));
		$eventDefinition = $report === 'event'
			? $this->getDefinition($eventType)
			: null;

		$historyDefinition = $report === 'history'
			? $this->normaliseHistoryDefinition($history)
			: null;

		if (!isset(self::REPORTS[$report]) && $eventDefinition === null && $historyDefinition === null)
		{
			throw new \InvalidArgumentException('Unsupported Punga Analytics report.');
		}

		[$from, $to] = $this->setReportingRange($range);
		$rows = match ($report)
		{
			'activity' => $this->getTrend($from, $to, $this->getTrendGranularity($from, $to)),
			'hours' => $this->getTimeRows('hour', $from, $to),
			'weekdays' => $this->getTimeRows('weekday', $from, $to),
			'pages' => $this->getDimensionRows('path', $from, $to, 0, false, 'pageview'),
			'notfound' => $this->getNotFoundRows($from, $to, 0),
			'event' => $this->getEventItems($eventType, $from, $to, 0),
			'countries' => $this->getCountryRows($from, $to, 0),
			'referrers' => $this->getDimensionRows('referrer_host', $from, $to, 0, false, 'pageview', true),
			'sources' => $this->getTrafficSourceRows($from, $to),
			'languages' => $this->getDimensionRows('language_code', $from, $to, 0, false, 'pageview', true),
			'devices' => $this->getDimensionRows('device_type', $from, $to, 0, false, 'pageview', true),
			'browsers' => $this->getDimensionRows('browser_family', $from, $to, 0, false, 'pageview', true),
			'bots' => $this->getDimensionRows('bot_name', $from, $to, 0, true, 'pageview', true),
			'events' => $this->getEventTypes($from, $to, 0),
			'history' => $this->getHistoryRows($historyDefinition, $from, $to),
		};

		return [
			'report' => $report,
			'title' => $historyDefinition !== null
				? 'COM_PUNGAANALYTICS_HISTORY'
				: ($eventDefinition !== null
					? (string) $eventDefinition['report_title']
					: self::REPORTS[$report]['title']),
			'kind' => $historyDefinition !== null
				? 'history'
				: ($eventDefinition !== null ? 'items' : self::REPORTS[$report]['kind']),
			'from' => $from,
			'to' => $to,
			'displayFrom' => $this->displayFrom,
			'displayTo' => $this->displayTo,
			'range' => $this->activeRange,
			'rows' => $rows,
			'event_type' => $eventType,
			'history' => $historyDefinition,
			'trendGranularity' => $this->getTrendGranularity($from, $to),
			'customEventDefinitions' => $this->customEventDefinitions,
		];
	}

	/**
	 * Returns whether a full report identifier is supported.
	 *
	 * @param string $report    Report identifier.
	 * @param string $eventType Custom event identifier for the generic event report.
	 * @param array<string, string> $history History dimension arguments.
	 *
	 * @return bool
	 */
	public function isSupportedReport(
		string $report,
		string $eventType = '',
		array $history = []
	): bool
	{
		$report = strtolower(trim($report));

		return isset(self::REPORTS[$report])
			|| ($report === 'event' && $this->getDefinition(strtolower(trim($eventType))) !== null)
			|| ($report === 'history' && $this->normaliseHistoryDefinition($history) !== null);
	}

	/**
	 * Normalises and activates reporting bounds in the site timezone.
	 *
	 * @param int|string $range Reporting range identifier.
	 *
	 * @return array{0:string, 1:string}
	 */
	private function setReportingRange(int|string $range): array
	{
		$range = strtolower(trim((string) $range));
		$allowed = ['today', 'yesterday', 'last24', '7', '30', '90', '365', 'all', '0'];
		$this->activeRange = \in_array($range, $allowed, true) ? $range : '7';
		$this->activeRange = $this->activeRange === '0' ? 'all' : $this->activeRange;
		$timezone = new DateTimeZone($this->timezone);
		$now = new DateTimeImmutable('now', $timezone);
		$from = $now;
		$to = $now;
		$this->rawFromUtc = '';
		$this->rawToUtc = '';

		if ($this->activeRange === 'all')
		{
			$earliest = $this->getEarliestDate();

			if ($earliest !== '')
			{
				$from = new DateTimeImmutable($earliest . ' 00:00:00', $timezone);
			}
		}
		elseif ($this->activeRange === 'today')
		{
			$from = $now->setTime(0, 0);
		}
		elseif ($this->activeRange === 'yesterday')
		{
			$from = $now->modify('-1 day')->setTime(0, 0);
			$to = $from->setTime(23, 59, 59);
		}
		elseif ($this->activeRange === 'last24')
		{
			$from = $now->modify('-24 hours');
			$this->rawFromUtc = $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
			$this->rawToUtc = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		}
		else
		{
			$days = max(1, (int) $this->activeRange);
			$from = $now->modify('-' . max(0, $days - 1) . ' days');
		}

		$this->displayFrom = $this->activeRange === 'last24'
			? $from->format('Y-m-d H:i')
			: $from->format('Y-m-d');
		$this->displayTo = $this->activeRange === 'last24'
			? $to->format('Y-m-d H:i')
			: $to->format('Y-m-d');

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

		$this->applyDateRange($aggregateQuery, $from, $to, false);
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

		$this->applyDateRange($aggregateQuery, $from, $to, false);
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

		$this->applyDateRange($archiveQuery, $from, $to, false);
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
		$this->applyDateRange($archiveQuery, $from, $to, false);
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
		$this->applyDateRange($archiveQuery, $from, $to, false);
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
	 * Returns human landing-page requests grouped by broad traffic source.
	 *
	 * Internal navigation and legacy rows whose origin cannot be reconstructed
	 * are deliberately omitted.
	 *
	 * @param string $from Inclusive date.
	 * @param string $to   Inclusive date.
	 *
	 * @return array<int, object>
	 */
	private function getTrafficSourceRows(string $from, string $to): array
	{
		$db = $this->database;
		$categories = ['direct', 'search', 'social', 'ai', 'referral'];
		$categoryList = implode(', ', array_map([$db, 'quote'], $categories));
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('traffic_source') . ' AS ' . $db->quoteName('label'),
				'COUNT(*) AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__pungaanalytics_events'))
			->where($db->quoteName('is_bot') . ' = 0')
			->where($db->quoteName('event_type') . ' = ' . $db->quote('pageview'))
			->where($db->quoteName('http_status') . ' >= 200')
			->where($db->quoteName('http_status') . ' < 400')
			->where($db->quoteName('traffic_source') . ' IN (' . $categoryList . ')')
			->group($db->quoteName('traffic_source'));

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);

		return $this->mergeCountRows(
			$db->loadObjectList(),
			$this->getArchivedDimensionRows('traffic_source', $from, $to),
			0
		);
	}

	/**
	 * Returns missing public paths with human/bot totals and timing details.
	 *
	 * @param string $from  Inclusive date.
	 * @param string $to    Inclusive date.
	 * @param int    $limit Maximum rows, or zero for all.
	 *
	 * @return array<int, object>
	 */
	private function getNotFoundRows(string $from, string $to, int $limit): array
	{
		$db = $this->database;
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('path'),
				$db->quoteName('referrer_host'),
				$db->quoteName('is_bot'),
				'COUNT(*) AS ' . $db->quoteName('count'),
				'MIN(' . $db->quoteName('visited_at') . ') AS ' . $db->quoteName('first_seen'),
				'MAX(' . $db->quoteName('visited_at') . ') AS ' . $db->quoteName('last_seen'),
			])
			->from($db->quoteName('#__pungaanalytics_events'))
			->where($db->quoteName('event_type') . ' = ' . $db->quote('pageview'))
			->where($db->quoteName('http_status') . ' = 404')
			->group([
				$db->quoteName('path'),
				$db->quoteName('referrer_host'),
				$db->quoteName('is_bot'),
			]);

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);
		$raw = $db->loadObjectList();
		$archiveQuery = $db->getQuery(true)
			->select([
				$db->quoteName('path'),
				$db->quoteName('referrer_host'),
				$db->quoteName('is_bot'),
				'SUM(' . $db->quoteName('request_count') . ') AS ' . $db->quoteName('count'),
				'MIN(' . $db->quoteName('first_seen') . ') AS ' . $db->quoteName('first_seen'),
				'MAX(' . $db->quoteName('last_seen') . ') AS ' . $db->quoteName('last_seen'),
			])
			->from($db->quoteName('#__pungaanalytics_daily_404'))
			->group([
				$db->quoteName('path'),
				$db->quoteName('referrer_host'),
				$db->quoteName('is_bot'),
			]);

		$this->applyDateRange($archiveQuery, $from, $to, false);
		$db->setQuery($archiveQuery);

		return $this->mergeNotFoundRows($raw, $db->loadObjectList(), $limit);
	}

	/**
	 * Merges raw and archived 404 rows by missing path.
	 *
	 * @param array<int, object> $raw      Raw rows.
	 * @param array<int, object> $archived Archived rows.
	 * @param int                $limit    Maximum rows, or zero for all.
	 *
	 * @return array<int, object>
	 */
	private function mergeNotFoundRows(array $raw, array $archived, int $limit): array
	{
		$rows = [];
		$referrers = [];

		foreach (array_merge($raw, $archived) as $source)
		{
			$path = (string) ($source->path ?? '');

			if ($path === '')
			{
				continue;
			}

			$key = mb_strtolower($path, 'UTF-8');

			if (!isset($rows[$key]))
			{
				$rows[$key] = (object) [
					'path' => $path,
					'human' => 0,
					'bots' => 0,
					'total' => 0,
					'top_referrer' => '',
					'first_seen' => '',
					'last_seen' => '',
				];
				$referrers[$key] = [];
			}

			$count = (int) ($source->count ?? 0);
			$field = (int) ($source->is_bot ?? 0) === 1 ? 'bots' : 'human';
			$rows[$key]->{$field} += $count;
			$rows[$key]->total += $count;
			$firstSeen = (string) ($source->first_seen ?? '');
			$lastSeen = (string) ($source->last_seen ?? '');

			if ($firstSeen !== '' && ($rows[$key]->first_seen === '' || $firstSeen < $rows[$key]->first_seen))
			{
				$rows[$key]->first_seen = $firstSeen;
			}

			if ($lastSeen !== '' && ($rows[$key]->last_seen === '' || $lastSeen > $rows[$key]->last_seen))
			{
				$rows[$key]->last_seen = $lastSeen;
			}

			$referrer = (string) ($source->referrer_host ?? '');

			if ($referrer !== '')
			{
				$referrers[$key][$referrer] = ($referrers[$key][$referrer] ?? 0) + $count;
			}
		}

		foreach ($rows as $key => $row)
		{
			if ($referrers[$key] !== [])
			{
				arsort($referrers[$key], SORT_NUMERIC);
				$row->top_referrer = (string) array_key_first($referrers[$key]);
			}
		}

		$rows = array_values($rows);
		usort(
			$rows,
			static fn(object $left, object $right): int =>
				((int) $right->total <=> (int) $left->total)
				?: ((string) $right->last_seen <=> (string) $left->last_seen)
				?: strnatcasecmp((string) $left->path, (string) $right->path)
		);

		return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
	}

	/**
	 * Validates and completes one row-history request.
	 *
	 * @param array<string, string> $history Untrusted history arguments.
	 *
	 * @return array<string, string>|null
	 */
	private function normaliseHistoryDefinition(array $history): ?array
	{
		$dimension = strtolower(trim((string) ($history['dimension'] ?? '')));
		$value = trim((string) ($history['value'] ?? ''));
		$definitions = [
			'page' => ['field' => 'path', 'archive' => 'path', 'metric' => 'COM_PUNGAANALYTICS_PAGEVIEWS'],
			'country' => ['field' => 'country_code', 'archive' => 'country', 'metric' => 'COM_PUNGAANALYTICS_HUMAN_VISITS'],
			'referrer' => ['field' => 'referrer_host', 'archive' => 'referrer', 'metric' => 'COM_PUNGAANALYTICS_PAGEVIEWS'],
			'source' => ['field' => 'traffic_source', 'archive' => 'traffic_source', 'metric' => 'COM_PUNGAANALYTICS_PAGEVIEWS'],
			'language' => ['field' => 'language_code', 'archive' => 'language', 'metric' => 'COM_PUNGAANALYTICS_PAGEVIEWS'],
			'device' => ['field' => 'device_type', 'archive' => 'device', 'metric' => 'COM_PUNGAANALYTICS_PAGEVIEWS'],
			'browser' => ['field' => 'browser_family', 'archive' => 'browser', 'metric' => 'COM_PUNGAANALYTICS_PAGEVIEWS'],
			'bot' => ['field' => 'bot_name', 'archive' => 'bot', 'metric' => 'COM_PUNGAANALYTICS_PAGEVIEWS'],
			'event' => ['field' => 'event_type', 'archive' => 'event_type', 'metric' => 'COM_PUNGAANALYTICS_EVENTS'],
			'notfound' => ['field' => 'path', 'archive' => 'notfound', 'metric' => 'COM_PUNGAANALYTICS_REQUESTS'],
		];

		if ($dimension === 'event_item')
		{
			$eventType = strtolower(trim((string) ($history['event_type'] ?? '')));
			$itemType = trim((string) ($history['item_type'] ?? ''));
			$itemId = trim((string) ($history['item_id'] ?? ''));
			$itemTitle = trim((string) ($history['item_title'] ?? ''));
			$path = trim((string) ($history['path'] ?? ''));

			if (
				preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $eventType) !== 1
				|| ($itemType === '' && $itemId === '' && $itemTitle === '' && $path === '')
			)
			{
				return null;
			}

			return [
				'dimension' => 'event_item',
				'value' => $itemTitle !== '' ? $itemTitle : ($itemId !== '' ? $itemId : $path),
				'field' => '',
				'archive' => 'event_item',
				'metric' => 'COM_PUNGAANALYTICS_EVENTS',
				'event_type' => mb_substr($eventType, 0, 64),
				'item_type' => mb_substr($itemType, 0, 64),
				'item_id' => mb_substr($itemId, 0, 128),
				'item_title' => mb_substr($itemTitle, 0, 255),
				'path' => mb_substr($path, 0, 1024),
			];
		}

		if (!isset($definitions[$dimension]) || $value === '')
		{
			return null;
		}

		return [
			'dimension' => $dimension,
			'value' => mb_substr($value, 0, 1024),
			'field' => $definitions[$dimension]['field'],
			'archive' => $definitions[$dimension]['archive'],
			'metric' => $definitions[$dimension]['metric'],
			'event_type' => '',
			'item_type' => '',
			'item_id' => '',
			'item_title' => '',
			'path' => '',
		];
	}

	/**
	 * Returns a permanent daily/weekly/monthly trend for one selected row.
	 *
	 * @param array<string, string>|null $definition Validated history definition.
	 * @param string                     $from       Inclusive date.
	 * @param string                     $to         Inclusive date.
	 *
	 * @return array<int, object>
	 */
	private function getHistoryRows(?array $definition, string $from, string $to): array
	{
		if ($definition === null)
		{
			return [];
		}

		$dailyRows = match ($definition['dimension'])
		{
			'event_item' => $this->getEventItemHistoryDailyRows($definition, $from, $to),
			'notfound' => $this->getNotFoundHistoryDailyRows($definition['value'], $from, $to),
			default => $this->getDimensionHistoryDailyRows($definition, $from, $to),
		};

		return $this->aggregateHistoryRows(
			$dailyRows,
			$from,
			$to,
			$this->getTrendGranularity($from, $to)
		);
	}

	/**
	 * Returns raw and archived daily counts for one ordinary dimension row.
	 *
	 * @param array<string, string> $definition Validated history definition.
	 * @param string                $from       Inclusive date.
	 * @param string                $to         Inclusive date.
	 *
	 * @return array<int, object>
	 */
	private function getDimensionHistoryDailyRows(array $definition, string $from, string $to): array
	{
		$db = $this->database;
		$field = $definition['field'];
		$allowedFields = [
			'path',
			'country_code',
			'referrer_host',
			'traffic_source',
			'language_code',
			'device_type',
			'browser_family',
			'bot_name',
			'event_type',
		];

		if (!\in_array($field, $allowedFields, true))
		{
			return [];
		}

		$isCountry = $definition['dimension'] === 'country';
		$isBot = $definition['dimension'] === 'bot';
		$isEvent = $definition['dimension'] === 'event';
		$count = $isCountry
			? 'COUNT(DISTINCT ' . $db->quoteName('visitor_hash') . ')'
			: 'COUNT(*)';
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('visit_date'),
				$count . ' AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__pungaanalytics_events'))
			->where($db->quoteName($field) . ' = :historyValue')
			->where($db->quoteName('is_bot') . ' = ' . ($isBot ? '1' : '0'))
			->group($db->quoteName('visit_date'))
			->bind(':historyValue', $definition['value']);

		if ($isEvent)
		{
			$query->where($db->quoteName('event_type') . ' <> ' . $db->quote('pageview'));
		}
		else
		{
			$query->where($db->quoteName('event_type') . ' = ' . $db->quote('pageview'));
		}

		if ($definition['dimension'] === 'page' || $definition['dimension'] === 'source')
		{
			$query->where($db->quoteName('http_status') . ' >= 200');
			$query->where($db->quoteName('http_status') . ' < 400');
		}

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);
		$raw = $db->loadObjectList();
		$archiveQuery = $db->getQuery(true)
			->select([
				$db->quoteName('visit_date'),
				'SUM(' . $db->quoteName('event_count') . ') AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__pungaanalytics_daily_dimensions'))
			->where($db->quoteName('dimension_key') . ' = :historyDimension')
			->where($db->quoteName('label') . ' = :archivedHistoryValue')
			->group($db->quoteName('visit_date'))
			->bind(':historyDimension', $definition['archive'])
			->bind(':archivedHistoryValue', $definition['value']);

		$this->applyDateRange($archiveQuery, $from, $to, false);
		$db->setQuery($archiveQuery);

		return array_merge($raw, $db->loadObjectList());
	}

	/**
	 * Returns daily history for one configured custom-event item.
	 *
	 * @param array<string, string> $definition Validated item identity.
	 * @param string                $from       Inclusive date.
	 * @param string                $to         Inclusive date.
	 *
	 * @return array<int, object>
	 */
	private function getEventItemHistoryDailyRows(array $definition, string $from, string $to): array
	{
		$db = $this->database;
		$eventType = $definition['event_type'];
		$itemType = $definition['item_type'];
		$itemId = $definition['item_id'];
		$itemTitle = $definition['item_title'];
		$path = $definition['path'];
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('visit_date'),
				'COUNT(*) AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__pungaanalytics_events'))
			->where($db->quoteName('is_bot') . ' = 0')
			->group($db->quoteName('visit_date'));
		$archiveQuery = $db->getQuery(true)
			->select([
				$db->quoteName('visit_date'),
				'SUM(' . $db->quoteName('event_count') . ') AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__pungaanalytics_daily_items'))
			->group($db->quoteName('visit_date'));

		$query
			->where($db->quoteName('event_type') . ' = :history_event_type')
			->where($db->quoteName('item_type') . ' = :history_item_type')
			->where($db->quoteName('item_id') . ' = :history_item_id')
			->where($db->quoteName('item_title') . ' = :history_item_title')
			->where($db->quoteName('path') . ' = :history_path')
			->bind(':history_event_type', $eventType)
			->bind(':history_item_type', $itemType)
			->bind(':history_item_id', $itemId)
			->bind(':history_item_title', $itemTitle)
			->bind(':history_path', $path);
		$archiveQuery
			->where($db->quoteName('event_type') . ' = :archive_history_event_type')
			->where($db->quoteName('item_type') . ' = :archive_history_item_type')
			->where($db->quoteName('item_id') . ' = :archive_history_item_id')
			->where($db->quoteName('item_title') . ' = :archive_history_item_title')
			->where($db->quoteName('path') . ' = :archive_history_path')
			->bind(':archive_history_event_type', $eventType)
			->bind(':archive_history_item_type', $itemType)
			->bind(':archive_history_item_id', $itemId)
			->bind(':archive_history_item_title', $itemTitle)
			->bind(':archive_history_path', $path);

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);
		$raw = $db->loadObjectList();
		$this->applyDateRange($archiveQuery, $from, $to, false);
		$db->setQuery($archiveQuery);

		return array_merge($raw, $db->loadObjectList());
	}

	/**
	 * Returns daily totals for one missing path.
	 *
	 * @param string $path Missing public path.
	 * @param string $from Inclusive date.
	 * @param string $to   Inclusive date.
	 *
	 * @return array<int, object>
	 */
	private function getNotFoundHistoryDailyRows(string $path, string $from, string $to): array
	{
		$db = $this->database;
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('visit_date'),
				'COUNT(*) AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__pungaanalytics_events'))
			->where($db->quoteName('event_type') . ' = ' . $db->quote('pageview'))
			->where($db->quoteName('http_status') . ' = 404')
			->where($db->quoteName('path') . ' = :history404Path')
			->group($db->quoteName('visit_date'))
			->bind(':history404Path', $path);

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);
		$raw = $db->loadObjectList();
		$archiveQuery = $db->getQuery(true)
			->select([
				$db->quoteName('visit_date'),
				'SUM(' . $db->quoteName('request_count') . ') AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__pungaanalytics_daily_404'))
			->where($db->quoteName('path') . ' = :archiveHistory404Path')
			->group($db->quoteName('visit_date'))
			->bind(':archiveHistory404Path', $path);

		$this->applyDateRange($archiveQuery, $from, $to, false);
		$db->setQuery($archiveQuery);

		return array_merge($raw, $db->loadObjectList());
	}

	/**
	 * Fills and aggregates daily history rows for a readable long-term trend.
	 *
	 * @param array<int, object> $dailyRows   Daily raw/archive rows.
	 * @param string             $from        Inclusive date.
	 * @param string             $to          Inclusive date.
	 * @param string             $granularity day, week, or month.
	 *
	 * @return array<int, object>
	 */
	private function aggregateHistoryRows(
		array $dailyRows,
		string $from,
		string $to,
		string $granularity
	): array
	{
		$buckets = [];
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
				'count' => 0,
			];
			$cursor = $cursor->modify($step);
		}

		foreach ($dailyRows as $row)
		{
			$date = (string) ($row->visit_date ?? '');

			if ($date === '')
			{
				continue;
			}

			$key = match ($granularity)
			{
				'month' => substr($date, 0, 7) . '-01',
				'week' => (new DateTimeImmutable($date))->modify('monday this week')->format('Y-m-d'),
				default => $date,
			};

			if (isset($buckets[$key]))
			{
				$buckets[$key]->count += (int) ($row->count ?? 0);
			}
		}

		ksort($buckets, SORT_STRING);

		return array_values($buckets);
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
	 * Returns successful page views whose latest known response is still successful.
	 *
	 * Raw rows and the dedicated page archive are merged. The obsolete generic
	 * path dimension is intentionally ignored because it did not preserve status.
	 *
	 * @param string $from  Inclusive date.
	 * @param string $to    Inclusive date.
	 * @param int    $limit Maximum rows, or zero for all.
	 *
	 * @return array<int, object>
	 */
	private function getPageRows(string $from, string $to, int $limit): array
	{
		$db = $this->database;
		$eventsAlias = 'page_event';
		$statusAlias = 'page_status';
		$rawQuery = $db->getQuery(true)
			->select([
				$db->quoteName($eventsAlias . '.path') . ' AS ' . $db->quoteName('label'),
				'COUNT(*) AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__pungaanalytics_events', $eventsAlias))
			->join(
				'INNER',
				$db->quoteName('#__pungaanalytics_page_status', $statusAlias)
					. ' ON UNHEX(' . $db->quoteName($statusAlias . '.path_hash') . ')'
					. ' = UNHEX(SHA2(' . $db->quoteName($eventsAlias . '.path') . ', 256))'
					. ' AND CAST(' . $db->quoteName($statusAlias . '.path') . ' AS BINARY)'
					. ' = CAST(' . $db->quoteName($eventsAlias . '.path') . ' AS BINARY)'
			)
			->where($db->quoteName($eventsAlias . '.is_bot') . ' = 0')
			->where($db->quoteName($eventsAlias . '.event_type') . ' = ' . $db->quote('pageview'))
			->where($db->quoteName($eventsAlias . '.http_status') . ' >= 200')
			->where($db->quoteName($eventsAlias . '.http_status') . ' < 400')
			->where($db->quoteName($statusAlias . '.last_status') . ' >= 200')
			->where($db->quoteName($statusAlias . '.last_status') . ' < 400')
			->group($db->quoteName($eventsAlias . '.path'));

		$this->applyDateRange($rawQuery, $from, $to, true, $eventsAlias);
		$db->setQuery($rawQuery);
		$raw = $db->loadObjectList();

		$pagesAlias = 'daily_page';
		$archiveStatusAlias = 'archive_page_status';
		$archiveQuery = $db->getQuery(true)
			->select([
				$db->quoteName($pagesAlias . '.path') . ' AS ' . $db->quoteName('label'),
				'SUM(' . $db->quoteName($pagesAlias . '.pageview_count') . ') AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__pungaanalytics_daily_pages', $pagesAlias))
			->join(
				'INNER',
				$db->quoteName('#__pungaanalytics_page_status', $archiveStatusAlias)
					. ' ON ' . $db->quoteName($archiveStatusAlias . '.path_hash')
					. ' = ' . $db->quoteName($pagesAlias . '.path_hash')
			)
			->where($db->quoteName($archiveStatusAlias . '.last_status') . ' >= 200')
			->where($db->quoteName($archiveStatusAlias . '.last_status') . ' < 400')
			->group($db->quoteName($pagesAlias . '.path'));

		$this->applyDateRange($archiveQuery, $from, $to, false, $pagesAlias);
		$db->setQuery($archiveQuery);

		return $this->mergeCountRows($raw, $db->loadObjectList(), $limit);
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

		if ($field === 'path' && $eventType === 'pageview' && !$bots)
		{
			return $this->getPageRows($from, $to, $limit);
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

		$this->applyDateRange($aggregateQuery, $from, $to, false);
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
		$dimensionsAlias = 'dimensions';
		$labelColumn = $db->quoteName($dimensionsAlias . '.label');
		$query = $db->getQuery(true)
			->select([
				$labelColumn . ' AS ' . $db->quoteName('label'),
				'SUM(' . $db->quoteName($dimensionsAlias . '.event_count') . ') AS ' . $db->quoteName('count'),
			])
			->from($db->quoteName('#__pungaanalytics_daily_dimensions', $dimensionsAlias))
			->where($db->quoteName($dimensionsAlias . '.dimension_key') . ' = :dimensionKey')
			->group($labelColumn)
			->bind(':dimensionKey', $dimensionKey);

		if ($dimensionKey === 'path')
		{
			$rawNotFound = 'EXISTS (SELECT 1 FROM '
				. $db->quoteName('#__pungaanalytics_events', 'raw_not_found')
				. ' WHERE ' . $db->quoteName('raw_not_found.event_type') . ' = ' . $db->quote('pageview')
				. ' AND ' . $db->quoteName('raw_not_found.http_status') . ' = 404'
				. ' AND ' . $db->quoteName('raw_not_found.path') . ' = ' . $labelColumn . ')';
			$archivedNotFound = 'EXISTS (SELECT 1 FROM '
				. $db->quoteName('#__pungaanalytics_daily_404', 'archived_not_found')
				. ' WHERE ' . $db->quoteName('archived_not_found.path') . ' = ' . $labelColumn . ')';
			$knownSuccess = 'EXISTS (SELECT 1 FROM '
				. $db->quoteName('#__pungaanalytics_events', 'successful_page')
				. ' WHERE ' . $db->quoteName('successful_page.event_type') . ' = ' . $db->quote('pageview')
				. ' AND ' . $db->quoteName('successful_page.http_status') . ' >= 200'
				. ' AND ' . $db->quoteName('successful_page.http_status') . ' < 400'
				. ' AND ' . $db->quoteName('successful_page.path') . ' = ' . $labelColumn . ')';

			$query->where(
				'NOT ((' . $rawNotFound . ' OR ' . $archivedNotFound . ') AND NOT ' . $knownSuccess . ')'
			);
		}

		$this->applyDateRange($query, $from, $to, false);
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
	 * @param bool          $raw   Whether the query reads timestamped raw events.
	 * @param string        $alias Optional table alias.
	 *
	 * @return void
	 */
	private function applyDateRange(
		DatabaseQuery $query,
		string $from,
		string $to,
		bool $raw = true,
		string $alias = ''
	): void
	{
		$column = static function (string $name) use ($alias): string
		{
			return $alias !== '' ? $alias . '.' . $name : $name;
		};
		if ($this->activeRange === 'last24')
		{
			if (!$raw)
			{
				$query->where('1 = 0');

				return;
			}

			$query
				->where($this->database->quoteName($column('visited_at')) . ' >= :fromUtc')
				->where($this->database->quoteName($column('visited_at')) . ' <= :toUtc')
				->bind(':fromUtc', $this->rawFromUtc)
				->bind(':toUtc', $this->rawToUtc);

			return;
		}

		$query
			->where($this->database->quoteName($column('visit_date')) . ' >= :from')
			->where($this->database->quoteName($column('visit_date')) . ' <= :to')
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
