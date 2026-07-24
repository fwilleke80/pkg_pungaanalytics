<?php

declare(strict_types=1);

namespace Punga\Component\PungaAnalytics\Administrator\Service;

use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Normalises configured custom-event definitions for recording and reporting.
 */
final class CustomEventDefinitionService
{
	/** @var array<int, string> */
	private const COLORS = [
		'#198754',
		'#d99000',
		'#6f42c1',
		'#0f8b8d',
		'#c94b54',
		'#2a69b8',
		'#dd6e42',
		'#607d8b',
	];

	/**
	 * Returns all valid configured custom-event definitions.
	 *
	 * @param Registry $params Component parameters.
	 *
	 * @return array<int, array{
	 *   event_type:string,
	 *   key:string,
	 *   table_key:string,
	 *   title:string,
	 *   ranking_title:string,
	 *   report_title:string,
	 *   source_component:string,
	 *   record:bool,
	 *   show_summary:bool,
	 *   show_trend:bool,
	 *   show_time:bool,
	 *   show_ranking:bool,
	 *   color:string,
	 *   icon:string
	 * }>
	 */
	public function getDefinitions(Registry $params): array
	{
		$rows = $this->normaliseRows($params->get('custom_event_definitions', []));
		$definitions = [];
		$seen = [];

		foreach ($rows as $index => $row)
		{
			if (\count($definitions) >= 100)
			{
				break;
			}

			$eventType = strtolower(trim((string) ($row['event_type'] ?? '')));

			if (!$this->isValidEventType($eventType) || isset($seen[$eventType]))
			{
				continue;
			}

			$seen[$eventType] = true;
			$title = $this->normaliseText(
				(string) ($row['title'] ?? ''),
				$this->humaniseEventType($eventType) . ' events',
				100
			);
			$key = 'event_' . substr(hash('sha256', $eventType), 0, 12);
			$color = strtolower(trim((string) ($row['color'] ?? '')));

			if (preg_match('/^#[0-9a-f]{6}$/', $color) !== 1)
			{
				$color = self::COLORS[$index % \count(self::COLORS)];
			}

			$icon = strtolower(trim((string) ($row['icon'] ?? '')));

			if (preg_match('/^icon-[a-z0-9-]+$/', $icon) !== 1)
			{
				$icon = 'icon-chart';
			}

			$sourceComponent = strtolower(trim((string) ($row['source_component'] ?? '')));

			if ($sourceComponent !== '' && preg_match('/^com_[a-z0-9_]+$/', $sourceComponent) !== 1)
			{
				$sourceComponent = '';
			}

			$definitions[] = [
				'event_type' => $eventType,
				'key' => $key,
				'table_key' => $key,
				'title' => $title,
				'ranking_title' => $this->normaliseText(
					(string) ($row['ranking_title'] ?? ''),
					$title . ' by item',
					150
				),
				'report_title' => $this->normaliseText(
					(string) ($row['report_title'] ?? ''),
					$title . ' — full report',
					150
				),
				'source_component' => $sourceComponent,
				'record' => $this->toBool($row['record'] ?? 1),
				'show_summary' => $this->toBool($row['show_summary'] ?? 0),
				'show_trend' => $this->toBool($row['show_trend'] ?? 0),
				'show_time' => $this->toBool($row['show_time'] ?? 0),
				'show_ranking' => $this->toBool($row['show_ranking'] ?? 1),
				'color' => $color,
				'icon' => $icon,
			];
		}

		return $definitions;
	}

	/**
	 * Returns definitions keyed by event identifier.
	 *
	 * @param Registry $params Component parameters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function getDefinitionsByType(Registry $params): array
	{
		$definitions = [];

		foreach ($this->getDefinitions($params) as $definition)
		{
			$definitions[$definition['event_type']] = $definition;
		}

		return $definitions;
	}

	/**
	 * Returns whether a bridge event is permitted by the recording policy.
	 *
	 * @param Registry $params          Component parameters.
	 * @param string   $eventType       Event identifier.
	 * @param string   $sourceComponent Source component option.
	 *
	 * @return bool
	 */
	public function isRecordingAllowed(
		Registry $params,
		string $eventType,
		string $sourceComponent
	): bool
	{
		$definitions = $this->getDefinitionsByType($params);
		$definition = $definitions[$eventType] ?? null;

		if ($definition === null)
		{
			return (string) $params->get('custom_event_policy', 'all') === 'all';
		}

		if (!(bool) $definition['record'])
		{
			return false;
		}

		$requiredComponent = (string) $definition['source_component'];

		return $requiredComponent === '' || $requiredComponent === strtolower(trim($sourceComponent));
	}

	/**
	 * Returns whether an event identifier satisfies the bridge contract.
	 *
	 * @param string $eventType Event identifier.
	 *
	 * @return bool
	 */
	public function isValidEventType(string $eventType): bool
	{
		return $eventType !== ''
			&& $eventType !== 'pageview'
			&& preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $eventType) === 1;
	}

	/**
	 * Normalises Joomla subform storage into simple associative rows.
	 *
	 * @param mixed $value Stored subform value.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normaliseRows(mixed $value): array
	{
		if (\is_string($value))
		{
			$value = json_decode($value, true);
		}
		elseif (\is_object($value))
		{
			$value = get_object_vars($value);
		}

		if (!\is_array($value))
		{
			return [];
		}

		$rows = [];

		foreach ($value as $row)
		{
			if (\is_object($row))
			{
				$row = get_object_vars($row);
			}

			if (\is_array($row))
			{
				$flatRow = $row;

				foreach ($row as $value)
				{
					if (\is_object($value))
					{
						$value = get_object_vars($value);
					}

					if (\is_array($value))
					{
						$flatRow = array_replace($flatRow, $value);
					}
				}

				$rows[] = $flatRow;
			}
		}

		return $rows;
	}

	/**
	 * Creates a readable fallback label from an event identifier.
	 *
	 * @param string $eventType Event identifier.
	 *
	 * @return string
	 */
	private function humaniseEventType(string $eventType): string
	{
		$words = preg_replace('/[._-]+/', ' ', $eventType) ?: $eventType;

		return ucwords($words);
	}

	/**
	 * Normalises one administrator-provided display string.
	 *
	 * @param string $value    User-provided value.
	 * @param string $fallback Fallback value.
	 * @param int    $maximum  Maximum character count.
	 *
	 * @return string
	 */
	private function normaliseText(string $value, string $fallback, int $maximum): string
	{
		$value = preg_replace('/\s+/u', ' ', strip_tags(trim($value))) ?: '';
		$value = $value !== '' ? $value : $fallback;

		return mb_substr($value, 0, $maximum, 'UTF-8');
	}

	/**
	 * Converts Joomla form values to booleans.
	 *
	 * @param mixed $value Form value.
	 *
	 * @return bool
	 */
	private function toBool(mixed $value): bool
	{
		return \in_array($value, [1, '1', true, 'true', 'yes', 'on'], true);
	}
}
