<?php

declare(strict_types=1);

namespace FrankWilleke\Component\Simplestats\Administrator\Model;

use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

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
			'topPages' => $this->getGroupedRows('path', $from, $to, 15, true),
			'referrers' => $this->getGroupedRows('referrer_host', $from, $to, 15, true, true),
			'languages' => $this->getGroupedRows('language_code', $from, $to, 12, true, true),
			'devices' => $this->getGroupedRows('device_type', $from, $to, 12, true, true),
			'browsers' => $this->getGroupedRows('browser_family', $from, $to, 12, true, true),
			'bots' => $this->getGroupedRows('bot_name', $from, $to, 12, false, true),
		];
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
	 * Returns aggregate summary values.
	 *
	 * @param string $from Inclusive date.
	 * @param string $to   Inclusive date.
	 *
	 * @return object
	 */
	private function getSummary(string $from, string $to): object
	{
		$db = $this->getDatabase();
		$dailyVisitor = 'CONCAT(' . $db->quoteName('visit_date') . ', ' . $db->quoteName('visitor_hash') . ')';

		$query = $db->getQuery(true)
			->select([
				'SUM(CASE WHEN ' . $db->quoteName('is_bot') . ' = 0 THEN 1 ELSE 0 END) AS ' . $db->quoteName('human_pageviews'),
				'COUNT(DISTINCT CASE WHEN ' . $db->quoteName('is_bot') . ' = 0 THEN ' . $dailyVisitor . ' END) AS ' . $db->quoteName('human_visits'),
				'COUNT(DISTINCT CASE WHEN ' . $db->quoteName('is_bot') . ' = 0 AND ' . $db->quoteName('country_code') . ' = ' . $db->quote('DE') . ' THEN ' . $dailyVisitor . ' END) AS ' . $db->quoteName('german_visits'),
				'SUM(CASE WHEN ' . $db->quoteName('is_bot') . ' = 1 THEN 1 ELSE 0 END) AS ' . $db->quoteName('bot_pageviews'),
				'SUM(CASE WHEN ' . $db->quoteName('is_bot') . ' = 0 AND ' . $db->quoteName('language_code') . ' LIKE ' . $db->quote('de%') . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('german_language_pageviews'),
			])
			->from($db->quoteName('#__simplestats_events'));

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);
		$result = $db->loadObject();

		return $result ?: (object) [
			'human_pageviews' => 0,
			'human_visits' => 0,
			'german_visits' => 0,
			'bot_pageviews' => 0,
			'german_language_pageviews' => 0,
		];
	}

	/**
	 * Returns daily human and bot counts.
	 *
	 * @param string $from Inclusive date.
	 * @param string $to   Inclusive date.
	 *
	 * @return array<int, object>
	 */
	private function getDaily(string $from, string $to): array
	{
		$db = $this->getDatabase();
		$dailyVisitor = 'CONCAT(' . $db->quoteName('visit_date') . ', ' . $db->quoteName('visitor_hash') . ')';

		$query = $db->getQuery(true)
			->select([
				$db->quoteName('visit_date'),
				'SUM(CASE WHEN ' . $db->quoteName('is_bot') . ' = 0 THEN 1 ELSE 0 END) AS ' . $db->quoteName('pageviews'),
				'COUNT(DISTINCT CASE WHEN ' . $db->quoteName('is_bot') . ' = 0 THEN ' . $dailyVisitor . ' END) AS ' . $db->quoteName('visits'),
				'COUNT(DISTINCT CASE WHEN ' . $db->quoteName('is_bot') . ' = 0 AND ' . $db->quoteName('country_code') . ' = ' . $db->quote('DE') . ' THEN ' . $dailyVisitor . ' END) AS ' . $db->quoteName('german_visits'),
				'SUM(CASE WHEN ' . $db->quoteName('is_bot') . ' = 1 THEN 1 ELSE 0 END) AS ' . $db->quoteName('bots'),
			])
			->from($db->quoteName('#__simplestats_events'))
			->group($db->quoteName('visit_date'))
			->order($db->quoteName('visit_date') . ' DESC');

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query);

		return $db->loadObjectList();
	}

	/**
	 * Returns grouped counts for a dimension.
	 *
	 * @param string $field         Database field.
	 * @param string $from          Inclusive date.
	 * @param string $to            Inclusive date.
	 * @param int    $limit         Maximum rows.
	 * @param bool   $humans        True for human traffic, false for bots.
	 * @param bool   $excludeEmpty  Whether blank values should be omitted.
	 *
	 * @return array<int, object>
	 */
	private function getGroupedRows(
		string $field,
		string $from,
		string $to,
		int $limit,
		bool $humans,
		bool $excludeEmpty = false
	): array
	{
		$allowed = ['path', 'referrer_host', 'language_code', 'device_type', 'browser_family', 'bot_name'];

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
			->where($db->quoteName('is_bot') . ' = ' . ($humans ? '0' : '1'))
			->group($db->quoteName($field))
			->order($db->quoteName('count') . ' DESC');

		if ($excludeEmpty)
		{
			$query->where($db->quoteName($field) . ' <> ' . $db->quote(''));
		}

		$this->applyDateRange($query, $from, $to);
		$db->setQuery($query, 0, $limit);

		return $db->loadObjectList();
	}

	/**
	 * Applies an inclusive date range to a query.
	 *
	 * @param \Joomla\Database\DatabaseQuery $query Query object.
	 * @param string                         $from  Inclusive date.
	 * @param string                         $to    Inclusive date.
	 *
	 * @return void
	 */
	private function applyDateRange(\Joomla\Database\DatabaseQuery $query, string $from, string $to): void
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
