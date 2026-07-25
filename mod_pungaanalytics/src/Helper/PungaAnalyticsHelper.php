<?php

declare(strict_types=1);

namespace Punga\Module\PungaAnalytics\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Punga\Component\PungaAnalytics\Administrator\Service\StatisticsQueryService;

\defined('_JEXEC') or die;

/**
 * Supplies compact Punga Analytics statistics to the administrator dashboard.
 */
final class PungaAnalyticsHelper
{
	/**
	 * Returns the statistics configured for one module instance.
	 *
	 * @param Registry $params Module parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function getStatistics(Registry $params): array
	{
		$app = Factory::getApplication();

		if (!$app->getIdentity()->authorise('core.manage', 'com_pungaanalytics'))
		{
			return [];
		}

		$allowedRanges = ['today', 'yesterday', 'last24', '7', '30', '90', '365', 'all', '0'];
		$range = strtolower(trim((string) $params->get('days', '7')));
		$range = \in_array($range, $allowedRanges, true) ? $range : '7';
		$range = $range === '0' ? 'all' : $range;
		$topPagesLimit = (bool) $params->get('show_top_pages', 1)
			? min(10, max(1, (int) $params->get('top_pages_limit', 5)))
			: 0;

		$app->bootComponent('com_pungaanalytics');
		$service = new StatisticsQueryService(
			Factory::getContainer()->get(DatabaseInterface::class),
			(string) $app->get('offset', 'UTC')
		);
		$statistics = $service->getModuleData($range, $topPagesLimit);
		$statistics['moduleEventDefinitions'] = $this->getModuleEventDefinitions(
			$params,
			$statistics['customEventDefinitions'],
			$statistics['summaryDefinitions']
		);

		return $statistics;
	}

	/**
	 * Resolves the custom events selected for one module instance.
	 *
	 * Instances saved before 0.7.5 retain the former behaviour: when their
	 * legacy switch is enabled, every event enabled for the component Overview
	 * remains visible until an explicit per-module selection is saved.
	 *
	 * @param Registry                         $params             Module parameters.
	 * @param array<int, array<string, mixed>> $definitions        All definitions.
	 * @param array<int, array<string, mixed>> $summaryDefinitions Overview definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function getModuleEventDefinitions(
		Registry $params,
		array $definitions,
		array $summaryDefinitions
	): array
	{
		if (!(bool) $params->get('custom_events_configured', 0))
		{
			return (bool) $params->get('show_custom_events', 1)
				? $summaryDefinitions
				: [];
		}

		$selected = $this->normaliseEventTypes($params->get('custom_events', []));

		if ($selected === [])
		{
			return [];
		}

		$selectedMap = array_fill_keys($selected, true);

		return array_values(array_filter(
			$definitions,
			static fn(array $definition): bool => isset(
				$selectedMap[(string) $definition['event_type']]
			)
		));
	}

	/**
	 * Normalises stored checkbox values into event identifiers.
	 *
	 * @param mixed $value Stored module value.
	 *
	 * @return array<int, string>
	 */
	private function normaliseEventTypes(mixed $value): array
	{
		if ($value instanceof Registry)
		{
			$value = $value->toArray();
		}
		elseif (\is_object($value))
		{
			$value = get_object_vars($value);
		}
		elseif (\is_string($value))
		{
			$decoded = json_decode($value, true);
			$value = \is_array($decoded) ? $decoded : ($value === '' ? [] : [$value]);
		}

		if (!\is_array($value))
		{
			return [];
		}

		$eventTypes = [];

		foreach ($value as $eventType)
		{
			$eventType = strtolower(trim((string) $eventType));

			if (
				$eventType !== ''
				&& preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $eventType) === 1
			)
			{
				$eventTypes[$eventType] = true;
			}
		}

		return array_keys($eventTypes);
	}
}
