<?php

declare(strict_types=1);

namespace Willeke\Component\Simplestats\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Willeke\Component\Simplestats\Administrator\Service\StatisticsQueryService;

\defined('_JEXEC') or die;

/**
 * Full statistics report model.
 */
final class ReportModel extends BaseDatabaseModel
{
	/**
	 * Allowed sort keys, backing row properties, and report defaults.
	 *
	 * @var array<string, array{
	 *   default:string,
	 *   direction:string,
	 *   fields:array<string, string>
	 * }>
	 */
	private const REPORT_SORTS = [
		'activity' => [
			'default' => 'period',
			'direction' => 'desc',
			'fields' => [
				'period' => 'period_start',
				'visits' => 'visits',
				'pageviews' => 'pageviews',
				'plays' => 'plays',
				'downloads' => 'downloads',
				'bots' => 'bots',
			],
		],
		'hours' => [
			'default' => 'hour',
			'direction' => 'asc',
			'fields' => [
				'hour' => 'bucket',
				'visits' => 'visits',
				'pageviews' => 'pageviews',
				'plays' => 'plays',
				'downloads' => 'downloads',
				'bots' => 'bots',
			],
		],
		'weekdays' => [
			'default' => 'weekday',
			'direction' => 'asc',
			'fields' => [
				'weekday' => 'bucket',
				'visits' => 'visits',
				'pageviews' => 'pageviews',
				'plays' => 'plays',
				'downloads' => 'downloads',
				'bots' => 'bots',
			],
		],
		'plays' => [
			'default' => 'count',
			'direction' => 'desc',
			'fields' => [
				'title' => 'item_label',
				'item_id' => 'item_id',
				'item_type' => 'item_type',
				'path' => 'path',
				'count' => 'count',
			],
		],
		'downloads' => [
			'default' => 'count',
			'direction' => 'desc',
			'fields' => [
				'title' => 'item_label',
				'item_id' => 'item_id',
				'item_type' => 'item_type',
				'path' => 'path',
				'count' => 'count',
			],
		],
	];

	/** @var array<string, string> */
	private const DIMENSION_SORTS = [
		'label' => 'label',
		'count' => 'count',
	];

	/** @var array<int, string> */
	private const NUMERIC_PROPERTIES = [
		'bucket',
		'visits',
		'pageviews',
		'plays',
		'downloads',
		'bots',
		'count',
	];

	/**
	 * Returns one paginated full report.
	 *
	 * @param string $report    Report identifier.
	 * @param int    $days      Number of days, or zero for all data.
	 * @param int    $start     First row offset.
	 * @param int    $limit     Rows per page.
	 * @param string $sort      Requested sort key.
	 * @param string $direction asc or desc.
	 *
	 * @return array<string, mixed>
	 */
	public function getReportData(
		string $report,
		int $days,
		int $start,
		int $limit,
		string $sort,
		string $direction
	): array
	{
		$data = $this->sortReportData(
			$this->getQueryService()->getReportData($report, $days),
			$sort,
			$direction
		);
		$data['total'] = \count($data['rows']);
		$data['rows'] = array_slice($data['rows'], max(0, $start), max(1, $limit));

		return $data;
	}

	/**
	 * Returns one complete report for export.
	 *
	 * @param string $report    Report identifier.
	 * @param int    $days      Number of days, or zero for all data.
	 * @param string $sort      Requested sort key.
	 * @param string $direction asc or desc.
	 *
	 * @return array<string, mixed>
	 */
	public function getExportData(string $report, int $days, string $sort, string $direction): array
	{
		return $this->sortReportData(
			$this->getQueryService()->getReportData($report, $days),
			$sort,
			$direction
		);
	}

	/**
	 * Returns whether a report identifier is supported.
	 *
	 * @param string $report Report identifier.
	 *
	 * @return bool
	 */
	public function isSupportedReport(string $report): bool
	{
		return $this->getQueryService()->isSupportedReport($report);
	}

	/**
	 * Sorts a complete report before pagination or CSV generation.
	 *
	 * @param array<string, mixed> $data      Complete report data.
	 * @param string               $sort      Requested sort key.
	 * @param string               $direction asc or desc.
	 *
	 * @return array<string, mixed>
	 */
	private function sortReportData(array $data, string $sort, string $direction): array
	{
		$report = (string) $data['report'];
		$configuration = self::REPORT_SORTS[$report] ?? [
			'default' => 'count',
			'direction' => 'desc',
			'fields' => self::DIMENSION_SORTS,
		];
		$fields = $configuration['fields'];

		if (!isset($fields[$sort]))
		{
			$sort = $configuration['default'];
			$direction = $configuration['direction'];
		}
		elseif (!\in_array($direction, ['asc', 'desc'], true))
		{
			$direction = $this->getDefaultDirection((string) $fields[$sort]);
		}

		$property = (string) $fields[$sort];
		$numeric = \in_array($property, self::NUMERIC_PROPERTIES, true);
		$multiplier = $direction === 'desc' ? -1 : 1;

		usort(
			$data['rows'],
			static function (object $left, object $right) use ($property, $numeric, $multiplier): int
			{
				$leftValue = self::getSortValue($left, $property);
				$rightValue = self::getSortValue($right, $property);
				$comparison = $numeric
					? ((int) $leftValue <=> (int) $rightValue)
					: strnatcasecmp((string) $leftValue, (string) $rightValue);

				return $comparison * $multiplier;
			}
		);

		$data['sort'] = $sort;
		$data['direction'] = $direction;

		return $data;
	}

	/**
	 * Returns the natural direction for a newly selected row property.
	 *
	 * @param string $property Backing row property.
	 *
	 * @return string
	 */
	private function getDefaultDirection(string $property): string
	{
		if ($property === 'period_start' || \in_array($property, self::NUMERIC_PROPERTIES, true))
		{
			return $property === 'bucket' ? 'asc' : 'desc';
		}

		return 'asc';
	}

	/**
	 * Extracts one comparable row value.
	 *
	 * @param object $row      Report row.
	 * @param string $property Backing row property.
	 *
	 * @return int|string
	 */
	private static function getSortValue(object $row, string $property): int|string
	{
		if ($property === 'item_label')
		{
			foreach (['item_title', 'item_id', 'path'] as $candidate)
			{
				$value = trim((string) ($row->{$candidate} ?? ''));

				if ($value !== '')
				{
					return $value;
				}
			}

			return '';
		}

		return $row->{$property} ?? '';
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
