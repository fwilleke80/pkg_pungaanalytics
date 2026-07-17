<?php

declare(strict_types=1);

namespace FrankWilleke\Component\Simplestats\Administrator\Model;

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
	 * Removes data older than the configured retention period.
	 *
	 * @param int $retentionDays Retention period in days.
	 *
	 * @return int Number of removed rows.
	 */
	public function purgeExpired(int $retentionDays): int
	{
		$retentionDays = max(1, $retentionDays);
		$cutoff = Factory::getDate('now', 'UTC')->modify('-' . $retentionDays . ' days')->toSql();
		$db = $this->getDatabase();
		$query = $db->getQuery(true)
			->delete($db->quoteName('#__simplestats_events'))
			->where($db->quoteName('visited_at') . ' < :cutoff')
			->bind(':cutoff', $cutoff);

		$db->setQuery($query)->execute();

		return $db->getAffectedRows();
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

		return $db->loadObject() ?: (object) [];
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

		return $db->loadObjectList();
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
		$db->setQuery($query, 0, $limit);

		return $db->loadObjectList();
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
		$db->setQuery($query, 0, $limit);

		return $db->loadObjectList();
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
		$db->setQuery($query, 0, $limit);

		return $db->loadObjectList();
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
		$db->setQuery($query, 0, $limit);

		return $db->loadObjectList();
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
