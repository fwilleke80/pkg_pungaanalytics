<?php

declare(strict_types=1);

namespace Punga\Component\PungaAnalytics\Administrator\Service;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Classifies retained non-bot traffic conservatively as suspected automation.
 *
 * Classification is visitor-day based because Punga Analytics deliberately
 * rotates its privacy-preserving visitor hash at local midnight. A hosting or
 * cloud network is only a signal; it is never sufficient by itself.
 */
final class AutomationClassificationService
{
	private const AUTOMATION_THRESHOLD = 4;

	/** @var array<int, true>|null */
	private static ?array $hostingAsns = null;

	/**
	 * Creates the classifier.
	 *
	 * @param DatabaseInterface $database Database connection.
	 */
	public function __construct(private DatabaseInterface $database)
	{
	}

	/**
	 * Re-evaluates one anonymous visitor-day and updates all of its raw events.
	 *
	 * @param string $visitDate   Site-local visit date.
	 * @param string $visitorHash Daily anonymous visitor hash.
	 *
	 * @return void
	 */
	public function refreshVisitor(string $visitDate, string $visitorHash): void
	{
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate) !== 1
			|| preg_match('/^[a-f0-9]{32}$/', $visitorHash) !== 1)
		{
			return;
		}

		$params = ComponentHelper::getParams('com_pungaanalytics');

		if (!(bool) $params->get('automation_detection', 1))
		{
			$this->applyClassification($visitDate, $visitorHash, 0, false, '');

			return;
		}

		$db = $this->database;
		$definitions = (new CustomEventDefinitionService())->getDefinitions($params);
		$engagementTypes = [];

		foreach ($definitions as $definition)
		{
			if ((bool) ($definition['record'] ?? false))
			{
				$engagementTypes[] = (string) $definition['event_type'];
			}
		}

		$engagementExpression = '0';

		if ($engagementTypes !== [])
		{
			$quotedTypes = implode(', ', array_map(
				static fn(string $eventType): string => $db->quote($eventType),
				$engagementTypes
			));
			$engagementExpression = 'SUM(CASE WHEN ' . $db->quoteName('event_type')
				. ' IN (' . $quotedTypes . ') THEN 1 ELSE 0 END)';
		}

		$query = $db->getQuery(true)
			->select([
				'MAX(' . $db->quoteName('asn_number') . ') AS ' . $db->quoteName('asn_number'),
				'SUM(CASE WHEN ' . $db->quoteName('event_type') . ' = ' . $db->quote('pageview')
					. ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('pageviews'),
				'COUNT(DISTINCT CASE WHEN ' . $db->quoteName('event_type') . ' = ' . $db->quote('pageview')
					. ' THEN ' . $db->quoteName('path') . ' END) AS ' . $db->quoteName('unique_paths'),
				$engagementExpression . ' AS ' . $db->quoteName('engagement_events'),
				'SUM(' . $db->quoteName('is_bot') . ') AS ' . $db->quoteName('bot_events'),
				'MIN(' . $db->quoteName('visited_at') . ') AS ' . $db->quoteName('first_seen'),
				'MAX(' . $db->quoteName('visited_at') . ') AS ' . $db->quoteName('last_seen'),
			])
			->from($db->quoteName('#__pungaanalytics_events'))
			->where($db->quoteName('visit_date') . ' = :visitDate')
			->where($db->quoteName('visitor_hash') . ' = :visitorHash')
			->bind(':visitDate', $visitDate)
			->bind(':visitorHash', $visitorHash);
		$db->setQuery($query);
		$row = $db->loadObject();

		if ($row === null || (int) ($row->bot_events ?? 0) > 0)
		{
			$this->applyClassification($visitDate, $visitorHash, 0, false, '');

			return;
		}

		$pageviews = (int) ($row->pageviews ?? 0);
		$uniquePaths = (int) ($row->unique_paths ?? 0);
		$engagementEvents = (int) ($row->engagement_events ?? 0);
		$firstSeen = strtotime((string) ($row->first_seen ?? ''));
		$lastSeen = strtotime((string) ($row->last_seen ?? ''));
		$elapsedSeconds = $firstSeen !== false && $lastSeen !== false
			? max(0, $lastSeen - $firstSeen)
			: 0;
		$score = 0;
		$reasons = [];

		if ($this->isHostingAsn((int) ($row->asn_number ?? 0)))
		{
			$score += 2;
			$reasons[] = 'hosting_network';
		}

		if ($pageviews >= 8 && $uniquePaths >= 6 && $elapsedSeconds <= 120)
		{
			$score += 3;
			$reasons[] = 'rapid_enumeration';
		}

		if ($pageviews >= 12 && $elapsedSeconds <= 300)
		{
			$score += 2;
			$reasons[] = 'high_request_rate';
		}

		if ($pageviews >= 40)
		{
			$score += 2;
			$reasons[] = 'high_volume';
		}

		if ($pageviews >= 8 && $engagementEvents === 0)
		{
			$score += 1;
			$reasons[] = 'no_engagement';
		}

		if ($engagementEvents > 0)
		{
			$score -= 3;
		}

		$score = max(0, min(9, $score));
		$isAutomated = $score >= self::AUTOMATION_THRESHOLD;
		$reason = $isAutomated ? implode(',', array_unique($reasons)) : '';
		$this->applyClassification($visitDate, $visitorHash, $score, $isAutomated, $reason);
	}

	/**
	 * Reclassifies every retained non-bot visitor-day.
	 *
	 * @return int Number of visitor-days evaluated.
	 */
	public function reclassifyRetained(): int
	{
		$db = $this->database;
		$query = $db->getQuery(true)
			->select([
				$db->quoteName('visit_date'),
				$db->quoteName('visitor_hash'),
			])
			->from($db->quoteName('#__pungaanalytics_events'))
			->where($db->quoteName('is_bot') . ' = 0')
			->group([
				$db->quoteName('visit_date'),
				$db->quoteName('visitor_hash'),
			]);
		$db->setQuery($query);
		$rows = $db->loadObjectList();

		foreach ($rows as $row)
		{
			$this->refreshVisitor((string) $row->visit_date, (string) $row->visitor_hash);
		}

		return count($rows);
	}

	/**
	 * Applies one classification to all events of a visitor-day.
	 *
	 * @param string $visitDate   Site-local visit date.
	 * @param string $visitorHash Daily anonymous visitor hash.
	 * @param int    $score       Automation score.
	 * @param bool   $automated   Whether the threshold was reached.
	 * @param string $reason      Comma-separated positive reason codes.
	 *
	 * @return void
	 */
	private function applyClassification(
		string $visitDate,
		string $visitorHash,
		int $score,
		bool $automated,
		string $reason
	): void
	{
		$db = $this->database;
		$query = $db->getQuery(true)
			->update($db->quoteName('#__pungaanalytics_events'))
			->set($db->quoteName('is_automated') . ' = ' . ($automated ? '1' : '0'))
			->set($db->quoteName('automation_score') . ' = ' . max(0, min(9, $score)))
			->set($db->quoteName('automation_reason') . ' = :reason')
			->where($db->quoteName('visit_date') . ' = :visitDate')
			->where($db->quoteName('visitor_hash') . ' = :visitorHash')
			->where($db->quoteName('is_bot') . ' = 0')
			->bind(':reason', $reason)
			->bind(':visitDate', $visitDate)
			->bind(':visitorHash', $visitorHash);
		$db->setQuery($query)->execute();
	}

	/**
	 * Returns whether an ASN has been identified as hosting/cloud infrastructure.
	 *
	 * @param int $asn Autonomous System number.
	 *
	 * @return bool
	 */
	private function isHostingAsn(int $asn): bool
	{
		if ($asn <= 0)
		{
			return false;
		}

		if (self::$hostingAsns === null)
		{
			self::$hostingAsns = [];
			$path = JPATH_ROOT . '/cache/com_pungaanalytics/asn-automation-signals.json';

			if (is_file($path) && is_readable($path))
			{
				$data = json_decode((string) file_get_contents($path), true);

				if (is_array($data))
				{
					foreach ($data as $key => $value)
					{
						if ($value && ctype_digit((string) $key))
						{
							self::$hostingAsns[(int) $key] = true;
						}
					}
				}
			}

			foreach ([8075, 14061, 14618, 16276, 16509, 20473, 24940, 31898, 45102, 51167, 63949, 132203, 396982] as $knownAsn)
			{
				self::$hostingAsns[$knownAsn] = true;
			}
		}

		return isset(self::$hostingAsns[$asn]);
	}
}
