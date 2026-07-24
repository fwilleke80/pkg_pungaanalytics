<?php

declare(strict_types=1);

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

$summary = $this->data['summary'];
$rangeOptions = [
	7 => Text::_('COM_SIMPLESTATS_RANGE_7'),
	30 => Text::_('COM_SIMPLESTATS_RANGE_30'),
	90 => Text::_('COM_SIMPLESTATS_RANGE_90'),
	365 => Text::_('COM_SIMPLESTATS_RANGE_365'),
	0 => Text::_('COM_SIMPLESTATS_RANGE_ALL'),
];
$number = static fn(mixed $value): string => number_format((int) $value);
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$siteRoot = rtrim(Uri::root(), '/');
$displayLocale = str_replace('-', '_', Factory::getApplication()->getLanguage()->getTag());
$countryName = static function (string $code) use ($displayLocale): string
{
	$code = strtoupper(trim($code));

	if ($code === '' || $code === 'ZZ')
	{
		return Text::_('COM_SIMPLESTATS_COUNTRY_UNKNOWN');
	}

	$name = '';

	if (class_exists(Locale::class))
	{
		$name = (string) Locale::getDisplayRegion('-' . $code, $displayLocale);
	}

	return $name !== '' && $name !== $code ? $name : $code;
};
$countryFlag = static function (string $code): string
{
	$code = strtoupper(trim($code));

	if (preg_match('/^[A-Z]{2}$/', $code) !== 1 || $code === 'ZZ')
	{
		return '';
	}

	return mb_chr(0x1F1E6 + ord($code[0]) - ord('A'), 'UTF-8')
		. mb_chr(0x1F1E6 + ord($code[1]) - ord('A'), 'UTF-8');
};
$countryLabel = static function (string $code) use ($countryName, $countryFlag, $escape): string
{
	$code = strtoupper(trim($code));
	$name = $countryName($code);

	if ($code === '' || $code === 'ZZ')
	{
		return $escape($name);
	}

	$flag = $countryFlag($code);
	$flagMarkup = $flag === '' ? '' : ' <span class="ss-country-flag" aria-hidden="true">' . $escape($flag) . '</span>';

	return $name !== $code
		? $escape($name) . $flagMarkup . ' <span class="ss-code">' . $escape($code) . '</span>'
		: $escape($code) . $flagMarkup;
};
$eventItemSortLabel = static function (object $row): string
{
	$title = trim((string) ($row->item_title ?? ''));
	$id = trim((string) ($row->item_id ?? ''));
	$path = trim((string) ($row->path ?? ''));

	if ($title !== '')
	{
		return $title;
	}

	if ($id !== '')
	{
		return $id;
	}

	if ($path !== '')
	{
		return $path;
	}

	return (string) Text::_('COM_SIMPLESTATS_UNKNOWN_ITEM');
};
$eventItemLabel = static fn(object $row): string => $escape($eventItemSortLabel($row));
$selectedDays = $this->days;
$selectedSortTable = $this->sortTable;
$selectedSort = $this->sort;
$selectedDirection = $this->direction;
$eventDefinitions = $this->data['customEventDefinitions'] ?? [];
$trendEventDefinitions = array_values(array_filter(
	$eventDefinitions,
	static fn(array $definition): bool => (bool) $definition['show_trend']
));
$timeEventDefinitions = array_values(array_filter(
	$eventDefinitions,
	static fn(array $definition): bool => (bool) $definition['show_time']
));
$rankingDefinitions = array_values(array_filter(
	$eventDefinitions,
	static fn(array $definition): bool => (bool) $definition['show_ranking']
));
$rankingTableKeys = array_column($rankingDefinitions, 'table_key');
$dashboardSortDefinitions = [
	'activity' => [
		'defaultField' => 'period',
		'defaultDirection' => 'desc',
		'fields' => [
			'period' => ['property' => 'period_start', 'type' => 'text'],
			'visits' => ['property' => 'visits', 'type' => 'number'],
			'pageviews' => ['property' => 'pageviews', 'type' => 'number'],
			'bots' => ['property' => 'bots', 'type' => 'number'],
		],
	],
	'hours' => [
		'defaultField' => 'hour',
		'defaultDirection' => 'asc',
		'fields' => [
			'hour' => ['property' => 'bucket', 'type' => 'number'],
			'pageviews' => ['property' => 'pageviews', 'type' => 'number'],
			'visits' => ['property' => 'visits', 'type' => 'number'],
			'bots' => ['property' => 'bots', 'type' => 'number'],
		],
	],
	'weekdays' => [
		'defaultField' => 'weekday',
		'defaultDirection' => 'asc',
		'fields' => [
			'weekday' => ['property' => 'bucket', 'type' => 'number'],
			'pageviews' => ['property' => 'pageviews', 'type' => 'number'],
			'visits' => ['property' => 'visits', 'type' => 'number'],
			'bots' => ['property' => 'bots', 'type' => 'number'],
		],
	],
	'pages' => [
		'defaultField' => 'count',
		'defaultDirection' => 'desc',
		'fields' => [
			'label' => ['property' => 'label', 'type' => 'text'],
			'count' => ['property' => 'count', 'type' => 'number'],
		],
	],
];

foreach ($trendEventDefinitions as $definition)
{
	$dashboardSortDefinitions['activity']['fields'][(string) $definition['key']] = [
		'property' => 'event:' . (string) $definition['event_type'],
		'type' => 'number',
	];
}

foreach ($timeEventDefinitions as $definition)
{
	foreach (['hours', 'weekdays'] as $table)
	{
		$dashboardSortDefinitions[$table]['fields'][(string) $definition['key']] = [
			'property' => 'event:' . (string) $definition['event_type'],
			'type' => 'number',
		];
	}
}

foreach ($rankingDefinitions as $definition)
{
	$dashboardSortDefinitions[(string) $definition['table_key']] = [
		'defaultField' => 'count',
		'defaultDirection' => 'desc',
		'fields' => [
			'title' => ['property' => 'item_title', 'type' => 'text'],
			'count' => ['property' => 'count', 'type' => 'number'],
		],
	];
}

foreach (['countries', 'referrers', 'languages', 'devices', 'browsers', 'bots', 'events'] as $dimensionTable)
{
	$dashboardSortDefinitions[$dimensionTable] = [
		'defaultField' => 'count',
		'defaultDirection' => 'desc',
		'fields' => [
			'label' => ['property' => 'label', 'type' => 'text'],
			'count' => ['property' => 'count', 'type' => 'number'],
		],
	];
}

$getDashboardSortState = static function (string $table) use (
	$dashboardSortDefinitions,
	$selectedSortTable,
	$selectedSort,
	$selectedDirection
): array
{
	$definition = $dashboardSortDefinitions[$table];
	$field = (string) $definition['defaultField'];
	$direction = (string) $definition['defaultDirection'];

	if ($selectedSortTable === $table && isset($definition['fields'][$selectedSort]))
	{
		$field = $selectedSort;
		$direction = \in_array($selectedDirection, ['asc', 'desc'], true)
			? $selectedDirection
			: $direction;
	}

	return [$field, $direction];
};
$getDashboardSortValue = static function (
	string $table,
	string $field,
	object $row
) use ($dashboardSortDefinitions, $rankingTableKeys, $countryName, $eventItemSortLabel): int|string
{
	if (\in_array($table, $rankingTableKeys, true) && $field === 'title')
	{
		return $eventItemSortLabel($row);
	}

	if ($table === 'countries' && $field === 'label')
	{
		return $countryName((string) ($row->label ?? ''));
	}

	$property = (string) $dashboardSortDefinitions[$table]['fields'][$field]['property'];

	if (str_starts_with($property, 'event:'))
	{
		return (int) (($row->events ?? [])[substr($property, 6)] ?? 0);
	}

	$value = $row->{$property} ?? '';

	return $dashboardSortDefinitions[$table]['fields'][$field]['type'] === 'number'
		? (int) $value
		: (string) $value;
};
$sortDashboardRows = static function (array $rows, string $table) use (
	$dashboardSortDefinitions,
	$getDashboardSortState,
	$getDashboardSortValue
): array
{
	[$field, $direction] = $getDashboardSortState($table);
	$type = (string) $dashboardSortDefinitions[$table]['fields'][$field]['type'];
	$decoratedRows = [];

	foreach (array_values($rows) as $index => $row)
	{
		$decoratedRows[] = ['index' => $index, 'row' => $row];
	}

	usort(
		$decoratedRows,
		static function (array $left, array $right) use (
			$table,
			$field,
			$direction,
			$type,
			$getDashboardSortValue
		): int
		{
			$leftValue = $getDashboardSortValue($table, $field, $left['row']);
			$rightValue = $getDashboardSortValue($table, $field, $right['row']);
			$comparison = $type === 'number'
				? ((int) $leftValue <=> (int) $rightValue)
				: strnatcasecmp((string) $leftValue, (string) $rightValue);

			if ($comparison === 0)
			{
				return $left['index'] <=> $right['index'];
			}

			return $direction === 'desc' ? -$comparison : $comparison;
		}
	);

	return array_map(
		static fn(array $decoratedRow): object => $decoratedRow['row'],
		$decoratedRows
	);
};
$sortableHeading = static function (
	string $table,
	string $field,
	string $label,
	string $defaultDirection,
	string $className = ''
) use ($escape, $getDashboardSortState, $selectedDays): string
{
	[$currentField, $currentDirection] = $getDashboardSortState($table);
	$active = $currentField === $field;
	$nextDirection = $active
		? ($currentDirection === 'asc' ? 'desc' : 'asc')
		: $defaultDirection;
	$ariaAttribute = $active
		? ' aria-sort="' . ($currentDirection === 'desc' ? 'descending' : 'ascending') . '"'
		: '';
	$indicator = $active
		? '<span class="ss-sort-indicator" aria-hidden="true">'
			. ($currentDirection === 'desc' ? '▼' : '▲')
			. '</span>'
		: '';
	$classAttribute = $className === '' ? '' : ' class="' . $escape($className) . '"';
	$url = Route::_(
		'index.php?option=com_simplestats'
		. '&days=' . $selectedDays
		. '&sort_table=' . rawurlencode($table)
		. '&sort=' . rawurlencode($field)
		. '&direction=' . rawurlencode($nextDirection),
		false
	) . '#ss-table-' . rawurlencode($table);

	return '<th scope="col"' . $classAttribute . $ariaAttribute
		. '><a href="' . $escape($url) . '" class="ss-sort-link"'
		. ' title="' . $escape(Text::sprintf('COM_SIMPLESTATS_SORT_BY', $label)) . '">'
		. $escape($label) . $indicator . '</a></th>';
};
$summaryMetrics = [
	[(int) ($summary->human_visits ?? 0), Text::_('COM_SIMPLESTATS_HUMAN_VISITS'), 'icon-users'],
	[(int) ($summary->human_pageviews ?? 0), Text::_('COM_SIMPLESTATS_HUMAN_PAGEVIEWS'), 'icon-eye'],
];
$chartSeries = [
	['visits', Text::_('COM_SIMPLESTATS_VISITS'), '#6f42c1', ''],
	['pageviews', Text::_('COM_SIMPLESTATS_PAGEVIEWS'), '#2a69b8', ''],
];

foreach ($eventDefinitions as $definition)
{
	if ((bool) $definition['show_summary'])
	{
		$summaryMetrics[] = [
			(int) (($summary->events ?? [])[(string) $definition['event_type']] ?? 0),
			(string) $definition['title'],
			(string) $definition['icon'],
		];
	}
}

foreach ($trendEventDefinitions as $definition)
{
	$chartSeries[] = [
		(string) $definition['key'],
		(string) $definition['title'],
		(string) $definition['color'],
		(string) $definition['event_type'],
	];
}

$summaryMetrics[] = [(int) ($summary->authenticated_pageviews ?? 0), Text::_('COM_SIMPLESTATS_AUTHENTICATED_PAGEVIEWS'), 'icon-user'];
$summaryMetrics[] = [(int) ($summary->bot_pageviews ?? 0), Text::_('COM_SIMPLESTATS_BOT_PAGEVIEWS'), 'icon-cogs'];
$chartSeries[] = ['bots', Text::_('COM_SIMPLESTATS_BOTS'), '#c94b54', ''];
$piePalette = ['#2a69b8', '#6f42c1', '#198754', '#d99000', '#c94b54', '#0f8b8d', '#dd6e42', '#607d8b', '#8e6c88', '#6c8e3f', '#b36b00', '#5c6bc0'];
$trendRows = $this->data['trend'];
$maxTrendValue = 1;
$trendGranularityKey = 'COM_SIMPLESTATS_GRANULARITY_' . strtoupper((string) $this->data['trendGranularity']);
$reportUrl = static fn(string $report, string $eventType = ''): string => Route::_(
	'index.php?option=com_simplestats&view=report&report=' . rawurlencode($report)
	. ($eventType === '' ? '' : '&event_type=' . rawurlencode($eventType))
	. '&days=' . $selectedDays
);
$hourLabel = static fn(int $hour): string => sprintf('%02d:00–%02d:00', $hour, ($hour + 1) % 24);
$weekdayLabels = [
	1 => Text::_('COM_SIMPLESTATS_WEEKDAY_1'),
	2 => Text::_('COM_SIMPLESTATS_WEEKDAY_2'),
	3 => Text::_('COM_SIMPLESTATS_WEEKDAY_3'),
	4 => Text::_('COM_SIMPLESTATS_WEEKDAY_4'),
	5 => Text::_('COM_SIMPLESTATS_WEEKDAY_5'),
	6 => Text::_('COM_SIMPLESTATS_WEEKDAY_6'),
	7 => Text::_('COM_SIMPLESTATS_WEEKDAY_7'),
];
$dashboardTrendRows = $this->activityTableRows > 0
	? array_slice($trendRows, -$this->activityTableRows)
	: $trendRows;
$dashboardTrendRows = $sortDashboardRows($dashboardTrendRows, 'activity');
$hourRows = $sortDashboardRows($this->data['hours'], 'hours');
$weekdayRows = $sortDashboardRows($this->data['weekdays'], 'weekdays');
$topPagesRows = $sortDashboardRows($this->data['topPages'], 'pages');
$eventRankingRows = [];

foreach ($this->data['customEventRankings'] ?? [] as $ranking)
{
	$tableKey = (string) $ranking['definition']['table_key'];
	$eventRankingRows[$tableKey] = $sortDashboardRows($ranking['rows'], $tableKey);
}

foreach ($trendRows as $row)
{
	foreach ($chartSeries as [$property, , , $eventType])
	{
		$value = $eventType === ''
			? (int) ($row->{$property} ?? 0)
			: (int) (($row->events ?? [])[$eventType] ?? 0);
		$maxTrendValue = max($maxTrendValue, $value);
	}
}

$trendChartWidth = 1200;
$trendChartHeight = 240;
$trendPlotLeft = 62.0;
$trendPlotRight = 62.0;
$trendPlotTop = 12.0;
$trendPlotHeight = 190.0;
$trendGroupWidth = ($trendChartWidth - $trendPlotLeft - $trendPlotRight) / max(1, \count($trendRows));
$trendBarGap = min(0.8, max(0.15, $trendGroupWidth * 0.04));
$trendBarWidth = max(
	0.5,
	min(
		7.0,
		($trendGroupWidth - 2.0 - ((\count($chartSeries) - 1) * $trendBarGap)) / \count($chartSeries)
	)
);
$trendBarsWidth = (\count($chartSeries) * $trendBarWidth) + ((\count($chartSeries) - 1) * $trendBarGap);
$trendLabelIndexes = [];
$trendRowCount = \count($trendRows);
$trendLabelCount = min(8, $trendRowCount);

if ($trendLabelCount === 1)
{
	$trendLabelIndexes[0] = true;
}
elseif ($trendLabelCount > 1)
{
	for ($labelIndex = 0; $labelIndex < $trendLabelCount; $labelIndex++)
	{
		$rowIndex = (int) round(($labelIndex * ($trendRowCount - 1)) / ($trendLabelCount - 1));
		$trendLabelIndexes[$rowIndex] = true;
	}
}

$knownCountryRows = array_filter(
	$this->data['countries'],
	static fn(object $row): bool => !\in_array(strtoupper(trim((string) $row->label)), ['', 'ZZ'], true)
);
$showCountryWarning = $this->countryStatus !== []
	&& $this->data['countries'] !== []
	&& $knownCountryRows === [];
$timeSortTables = ['hours', 'weekdays'];
$engagementSortTables = $rankingTableKeys;
$audienceSortTables = ['countries', 'referrers', 'languages', 'devices', 'browsers', 'bots', 'events'];
$systemNeedsAttention = $this->countryStatus === []
	|| !(bool) ($this->countryStatus['files_ready'] ?? false);
?>
<form action="<?php echo Route::_('index.php?option=com_simplestats'); ?>" method="post" id="adminForm" name="adminForm">
	<input type="hidden" name="option" value="com_simplestats">
	<input type="hidden" name="task" value="">
	<?php echo HTMLHelper::_('form.token'); ?>

		<div class="ss-dashboard">
			<header class="ss-hero">
				<div class="ss-hero__copy">
					<h1 class="ss-hero__title"><?php echo Text::_('COM_SIMPLESTATS_DASHBOARD_TITLE'); ?></h1>
					<h2 class="ss-section-heading ss-section-heading--hero"><?php echo Text::_('COM_SIMPLESTATS_DASHBOARD_EYEBROW'); ?></h2>
					<p><?php echo Text::sprintf('COM_SIMPLESTATS_DATE_RANGE', $escape($this->data['from']), $escape($this->data['to'])); ?></p>
				</div>
			<div class="ss-version" title="<?php echo Text::_('COM_SIMPLESTATS_VERSION'); ?>">
				<span><?php echo Text::_('COM_SIMPLESTATS_VERSION'); ?></span>
				<strong><?php echo $escape($this->version); ?></strong>
			</div>
		</header>

		<nav class="ss-range" aria-label="<?php echo Text::_('COM_SIMPLESTATS_RANGE'); ?>">
			<?php foreach ($rangeOptions as $value => $label) : ?>
				<a class="ss-range__item<?php echo $this->days === $value ? ' is-active' : ''; ?>"
					href="<?php echo Route::_('index.php?option=com_simplestats&days=' . (int) $value); ?>">
					<?php echo $escape($label); ?>
				</a>
			<?php endforeach; ?>
			</nav>

			<details class="ss-dashboard-section" id="ss-section-overview" open>
				<summary class="ss-dashboard-section__summary">
					<h2><span class="icon-dashboard" aria-hidden="true"></span><?php echo Text::_('COM_SIMPLESTATS_OVERVIEW'); ?></h2>
				</summary>
				<div class="ss-dashboard-section__content">
					<section class="ss-panel ss-summary" aria-label="<?php echo Text::_('COM_SIMPLESTATS_OVERVIEW'); ?>">
						<div class="table-responsive">
							<table class="table mb-0">
								<thead>
									<tr>
									<?php foreach ($summaryMetrics as [, $label, $icon]) : ?>
										<th><span class="<?php echo $escape($icon); ?>" aria-hidden="true"></span><?php echo $escape($label); ?></th>
									<?php endforeach; ?>
									</tr>
								</thead>
								<tbody>
									<tr>
									<?php foreach ($summaryMetrics as [$value]) : ?>
										<td><?php echo $number($value); ?></td>
									<?php endforeach; ?>
									</tr>
								</tbody>
							</table>
						</div>
					</section>
				</div>
			</details>

			<details class="ss-dashboard-section" id="ss-section-traffic" open>
				<summary class="ss-dashboard-section__summary">
					<h2><span class="icon-chart" aria-hidden="true"></span><?php echo Text::_('COM_SIMPLESTATS_TRAFFIC'); ?></h2>
				</summary>
				<div class="ss-dashboard-section__content">
					<section class="ss-panel ss-panel--wide">
						<header class="ss-panel__header">
							<h3><?php echo Text::_('COM_SIMPLESTATS_ACTIVITY_TREND'); ?></h3>
							<div class="ss-panel__tools">
								<span class="ss-panel__meta"><?php echo Text::sprintf('COM_SIMPLESTATS_TREND_GRANULARITY', Text::_($trendGranularityKey)); ?></span>
								<a class="ss-view-all" href="<?php echo $reportUrl('activity'); ?>"><?php echo Text::_('COM_SIMPLESTATS_VIEW_ALL'); ?></a>
							</div>
						</header>
						<div class="ss-panel__body ss-panel__body--flush">
							<?php if ($trendRows === []) : ?>
								<div class="ss-empty"><?php echo Text::_('COM_SIMPLESTATS_NO_DATA'); ?></div>
							<?php else : ?>
								<div class="ss-daily-chart">
									<div class="ss-chart-legend" aria-hidden="true">
										<?php foreach ($chartSeries as [$property, $label, $color]) : ?>
											<span class="ss-series-<?php echo $escape($property); ?>">
												<svg class="ss-chart-legend__swatch" width="11" height="11" viewBox="0 0 11 11" focusable="false">
													<circle cx="5.5" cy="5.5" r="5" fill="<?php echo $escape($color); ?>"></circle>
												</svg>
												<?php echo $escape($label); ?>
											</span>
										<?php endforeach; ?>
									</div>
									<div class="ss-chart-scroll">
										<svg class="ss-daily-chart__svg"
											viewBox="0 0 <?php echo $trendChartWidth; ?> <?php echo $trendChartHeight; ?>"
											preserveAspectRatio="xMidYMid meet"
											role="img"
											aria-label="<?php echo Text::_('COM_SIMPLESTATS_TREND_CHART_LABEL'); ?>">
											<?php for ($line = 0; $line <= 4; $line++) :
												$lineY = $trendPlotTop + ($line * ($trendPlotHeight / 4.0));
												$lineValue = (int) round($maxTrendValue * ((4 - $line) / 4));
											?>
												<line class="ss-chart-gridline"
													x1="<?php echo $trendPlotLeft; ?>"
													y1="<?php echo number_format($lineY, 1, '.', ''); ?>"
													x2="<?php echo $trendChartWidth - $trendPlotRight; ?>"
													y2="<?php echo number_format($lineY, 1, '.', ''); ?>"></line>
												<text class="ss-chart-axis-label"
													x="<?php echo $trendPlotLeft - 8; ?>"
													y="<?php echo number_format($lineY + 4, 1, '.', ''); ?>"
													text-anchor="end"><?php echo $lineValue; ?></text>
											<?php endfor; ?>
											<?php foreach ($trendRows as $rowIndex => $row) :
												$groupStart = $trendPlotLeft + ($rowIndex * $trendGroupWidth);
												$groupCenter = $groupStart + ($trendGroupWidth / 2.0);
												$groupX = $groupCenter - ($trendBarsWidth / 2.0);
											?>
												<?php foreach ($chartSeries as $seriesIndex => [$property, $label, $color, $eventType]) :
													$value = $eventType === ''
														? (int) ($row->{$property} ?? 0)
														: (int) (($row->events ?? [])[$eventType] ?? 0);
													$height = $value > 0 ? max(1.0, ($value / $maxTrendValue) * $trendPlotHeight) : 0.0;
													$x = $groupX + ($seriesIndex * ($trendBarWidth + $trendBarGap));
													$y = $trendPlotTop + $trendPlotHeight - $height;
												?>
													<rect x="<?php echo number_format($x, 1, '.', ''); ?>"
														y="<?php echo number_format($y, 1, '.', ''); ?>"
														width="<?php echo number_format($trendBarWidth, 1, '.', ''); ?>"
														height="<?php echo number_format($height, 1, '.', ''); ?>"
														rx="1.2"
														fill="<?php echo $escape($color); ?>">
														<title><?php echo $escape($row->period_label . ' · ' . $label . ': ' . $number($value)); ?></title>
													</rect>
												<?php endforeach; ?>
												<?php if (isset($trendLabelIndexes[$rowIndex])) : ?>
													<line class="ss-chart-date-tick"
														x1="<?php echo number_format($groupCenter, 1, '.', ''); ?>"
														y1="<?php echo $trendPlotTop + $trendPlotHeight + 3; ?>"
														x2="<?php echo number_format($groupCenter, 1, '.', ''); ?>"
														y2="<?php echo $trendPlotTop + $trendPlotHeight + 8; ?>"></line>
													<text class="ss-chart-date-label"
														x="<?php echo number_format($groupCenter, 1, '.', ''); ?>"
														y="<?php echo $trendPlotTop + $trendPlotHeight + 23; ?>"
														text-anchor="middle"><?php echo $escape((string) $row->period_label); ?></text>
												<?php endif; ?>
											<?php endforeach; ?>
										</svg>
									</div>
								</div>
								<div class="table-responsive">
									<table class="table ss-table mb-0" id="ss-table-activity">
										<thead>
											<tr>
												<?php echo $sortableHeading('activity', 'period', Text::_('COM_SIMPLESTATS_PERIOD'), 'desc'); ?>
												<?php echo $sortableHeading('activity', 'visits', Text::_('COM_SIMPLESTATS_VISITS'), 'desc', 'text-end'); ?>
												<?php echo $sortableHeading('activity', 'pageviews', Text::_('COM_SIMPLESTATS_PAGEVIEWS'), 'desc', 'text-end'); ?>
												<?php foreach ($trendEventDefinitions as $definition) : ?>
													<?php echo $sortableHeading('activity', (string) $definition['key'], (string) $definition['title'], 'desc', 'text-end'); ?>
												<?php endforeach; ?>
												<?php echo $sortableHeading('activity', 'bots', Text::_('COM_SIMPLESTATS_BOTS'), 'desc', 'text-end'); ?>
											</tr>
										</thead>
										<tbody>
										<?php foreach ($dashboardTrendRows as $row) : ?>
											<tr>
												<td class="text-nowrap" data-sort-value="<?php echo $escape($row->period_start); ?>"><?php echo $escape($row->period_label); ?></td>
												<td class="text-end" data-sort-value="<?php echo (int) $row->visits; ?>"><?php echo $number($row->visits); ?></td>
												<td class="text-end" data-sort-value="<?php echo (int) $row->pageviews; ?>"><?php echo $number($row->pageviews); ?></td>
												<?php foreach ($trendEventDefinitions as $definition) :
													$eventValue = (int) (($row->events ?? [])[(string) $definition['event_type']] ?? 0);
												?>
													<td class="text-end" data-sort-value="<?php echo $eventValue; ?>"><?php echo $number($eventValue); ?></td>
												<?php endforeach; ?>
												<td class="text-end" data-sort-value="<?php echo (int) $row->bots; ?>"><?php echo $number($row->bots); ?></td>
											</tr>
										<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							<?php endif; ?>
						</div>
					</section>
				</div>
			</details>

			<details class="ss-dashboard-section" id="ss-section-time"<?php echo \in_array($this->sortTable, $timeSortTables, true) ? ' open' : ''; ?>>
				<summary class="ss-dashboard-section__summary">
					<h2><span class="icon-clock" aria-hidden="true"></span><?php echo Text::_('COM_SIMPLESTATS_TIME_OF_DAY'); ?></h2>
				</summary>
				<div class="ss-dashboard-section__content">
					<div class="ss-grid ss-grid--time">
					<section class="ss-panel">
							<header class="ss-panel__header">
								<h3><?php echo Text::_('COM_SIMPLESTATS_BY_HOUR'); ?></h3>
							<div class="ss-panel__tools">
								<span class="ss-panel__meta"><?php echo Text::_('COM_SIMPLESTATS_SITE_LOCAL_TIME'); ?></span>
								<a class="ss-view-all" href="<?php echo $reportUrl('hours'); ?>"><?php echo Text::_('COM_SIMPLESTATS_VIEW_ALL'); ?></a>
							</div>
						</header>
						<p class="ss-panel-note"><?php echo Text::_('COM_SIMPLESTATS_HOUR_HISTORY_NOTE'); ?></p>
						<div class="table-responsive ss-time-table">
						<table class="table ss-table mb-0" id="ss-table-hours">
							<thead>
								<tr>
									<?php echo $sortableHeading('hours', 'hour', Text::_('COM_SIMPLESTATS_HOUR'), 'asc'); ?>
									<?php echo $sortableHeading('hours', 'pageviews', Text::_('COM_SIMPLESTATS_PAGEVIEWS'), 'desc', 'text-end'); ?>
									<?php echo $sortableHeading('hours', 'visits', Text::_('COM_SIMPLESTATS_VISITS'), 'desc', 'text-end'); ?>
									<?php foreach ($timeEventDefinitions as $definition) : ?>
										<?php echo $sortableHeading('hours', (string) $definition['key'], (string) $definition['title'], 'desc', 'text-end'); ?>
									<?php endforeach; ?>
									<?php echo $sortableHeading('hours', 'bots', Text::_('COM_SIMPLESTATS_BOTS'), 'desc', 'text-end'); ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($hourRows as $row) : ?>
									<tr>
										<td class="text-nowrap" data-sort-value="<?php echo (int) $row->bucket; ?>"><?php echo $escape($hourLabel((int) $row->bucket)); ?></td>
										<td class="text-end" data-sort-value="<?php echo (int) $row->pageviews; ?>"><?php echo $number($row->pageviews); ?></td>
										<td class="text-end" data-sort-value="<?php echo (int) $row->visits; ?>"><?php echo $number($row->visits); ?></td>
										<?php foreach ($timeEventDefinitions as $definition) :
											$eventValue = (int) (($row->events ?? [])[(string) $definition['event_type']] ?? 0);
										?>
											<td class="text-end" data-sort-value="<?php echo $eventValue; ?>"><?php echo $number($eventValue); ?></td>
										<?php endforeach; ?>
										<td class="text-end" data-sort-value="<?php echo (int) $row->bots; ?>"><?php echo $number($row->bots); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>

					<section class="ss-panel">
							<header class="ss-panel__header">
								<h3><?php echo Text::_('COM_SIMPLESTATS_BY_WEEKDAY'); ?></h3>
							<span class="ss-panel__meta"><?php echo Text::_('COM_SIMPLESTATS_SITE_LOCAL_TIME'); ?></span>
						</header>
						<div class="table-responsive ss-time-table">
							<table class="table ss-table mb-0" id="ss-table-weekdays">
								<thead>
									<tr>
										<?php echo $sortableHeading('weekdays', 'weekday', Text::_('COM_SIMPLESTATS_WEEKDAY'), 'asc'); ?>
										<?php echo $sortableHeading('weekdays', 'pageviews', Text::_('COM_SIMPLESTATS_PAGEVIEWS'), 'desc', 'text-end'); ?>
										<?php echo $sortableHeading('weekdays', 'visits', Text::_('COM_SIMPLESTATS_VISITS'), 'desc', 'text-end'); ?>
										<?php foreach ($timeEventDefinitions as $definition) : ?>
											<?php echo $sortableHeading('weekdays', (string) $definition['key'], (string) $definition['title'], 'desc', 'text-end'); ?>
										<?php endforeach; ?>
										<?php echo $sortableHeading('weekdays', 'bots', Text::_('COM_SIMPLESTATS_BOTS'), 'desc', 'text-end'); ?>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($weekdayRows as $row) : ?>
									<tr>
										<td data-sort-value="<?php echo (int) $row->bucket; ?>"><?php echo $escape($weekdayLabels[(int) $row->bucket] ?? (string) $row->bucket); ?></td>
										<td class="text-end" data-sort-value="<?php echo (int) $row->pageviews; ?>"><?php echo $number($row->pageviews); ?></td>
										<td class="text-end" data-sort-value="<?php echo (int) $row->visits; ?>"><?php echo $number($row->visits); ?></td>
										<?php foreach ($timeEventDefinitions as $definition) :
											$eventValue = (int) (($row->events ?? [])[(string) $definition['event_type']] ?? 0);
										?>
											<td class="text-end" data-sort-value="<?php echo $eventValue; ?>"><?php echo $number($eventValue); ?></td>
										<?php endforeach; ?>
										<td class="text-end" data-sort-value="<?php echo (int) $row->bots; ?>"><?php echo $number($row->bots); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>
					</div>
				</div>
			</details>

			<details class="ss-dashboard-section" id="ss-section-content"<?php echo $this->sortTable === 'pages' ? ' open' : ''; ?>>
				<summary class="ss-dashboard-section__summary">
					<h2><span class="icon-file" aria-hidden="true"></span><?php echo Text::_('COM_SIMPLESTATS_CONTENT'); ?></h2>
				</summary>
				<div class="ss-dashboard-section__content">
					<div class="ss-grid ss-grid--content">
				<section class="ss-panel">
					<header class="ss-panel__header">
						<h3><?php echo Text::_('COM_SIMPLESTATS_TOP_PAGES'); ?></h3>
						<a class="ss-view-all" href="<?php echo $reportUrl('pages'); ?>"><?php echo Text::_('COM_SIMPLESTATS_VIEW_ALL'); ?></a>
				</header>
				<div class="ss-panel__body ss-panel__body--flush">
					<?php if ($topPagesRows === []) : ?><div class="ss-empty"><?php echo Text::_('COM_SIMPLESTATS_NO_DATA'); ?></div><?php else : ?>
					<table class="table ss-table mb-0" id="ss-table-pages">
						<thead>
							<tr>
								<?php echo $sortableHeading('pages', 'label', Text::_('COM_SIMPLESTATS_CSV_LABEL'), 'asc'); ?>
								<?php echo $sortableHeading('pages', 'count', Text::_('COM_SIMPLESTATS_CSV_COUNT'), 'desc', 'text-end'); ?>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($topPagesRows as $row) : ?>
							<tr>
								<td class="text-break" data-sort-value="<?php echo $escape($row->label); ?>"><a href="<?php echo $escape($siteRoot . (string) $row->label); ?>" target="_blank" rel="noopener noreferrer"><?php echo $escape($row->label); ?></a></td>
								<td class="text-end ss-count" data-sort-value="<?php echo (int) $row->count; ?>"><?php echo $number($row->count); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php endif; ?>
				</div>
			</section>
					</div>
				</div>
			</details>

			<?php if ($rankingDefinitions !== []) : ?>
				<details class="ss-dashboard-section" id="ss-section-engagement"<?php echo \in_array($this->sortTable, $engagementSortTables, true) ? ' open' : ''; ?>>
					<summary class="ss-dashboard-section__summary">
						<h2><span class="icon-play" aria-hidden="true"></span><?php echo Text::_('COM_SIMPLESTATS_ENGAGEMENT'); ?></h2>
					</summary>
					<div class="ss-dashboard-section__content">
						<div class="ss-grid ss-grid--content">
							<?php foreach ($rankingDefinitions as $definition) :
								$tableKey = (string) $definition['table_key'];
								$rankingRows = $eventRankingRows[$tableKey] ?? [];
							?>
								<section class="ss-panel">
									<header class="ss-panel__header">
										<h3><?php echo $escape($definition['ranking_title']); ?></h3>
										<a class="ss-view-all" href="<?php echo $reportUrl('event', (string) $definition['event_type']); ?>"><?php echo Text::_('COM_SIMPLESTATS_VIEW_ALL'); ?></a>
									</header>
									<div class="ss-panel__body ss-panel__body--flush">
										<?php if ($rankingRows === []) : ?>
											<div class="ss-empty"><?php echo Text::_('COM_SIMPLESTATS_NO_CUSTOM_EVENTS'); ?></div>
										<?php else : ?>
											<table class="table ss-table mb-0" id="ss-table-<?php echo $escape($tableKey); ?>">
												<thead>
													<tr>
														<?php echo $sortableHeading($tableKey, 'title', Text::_('COM_SIMPLESTATS_CSV_TITLE'), 'asc'); ?>
														<?php echo $sortableHeading($tableKey, 'count', Text::_('COM_SIMPLESTATS_CSV_COUNT'), 'desc', 'text-end'); ?>
													</tr>
												</thead>
												<tbody>
													<?php foreach ($rankingRows as $row) : ?>
														<tr>
															<td data-sort-value="<?php echo $escape($eventItemSortLabel($row)); ?>"><div class="ss-item-title"><?php echo $eventItemLabel($row); ?></div><div class="ss-item-meta"><?php echo $escape($row->item_type); ?><?php echo $row->item_id !== '' ? ' · ' . $escape($row->item_id) : ''; ?></div></td>
															<td class="text-end ss-count" data-sort-value="<?php echo (int) $row->count; ?>"><?php echo $number($row->count); ?></td>
														</tr>
													<?php endforeach; ?>
												</tbody>
											</table>
										<?php endif; ?>
									</div>
								</section>
							<?php endforeach; ?>
						</div>
					</div>
				</details>
			<?php endif; ?>

			<details class="ss-dashboard-section" id="ss-section-audience"<?php echo \in_array($this->sortTable, $audienceSortTables, true) ? ' open' : ''; ?>>
				<summary class="ss-dashboard-section__summary">
					<h2><span class="icon-users" aria-hidden="true"></span><?php echo Text::_('COM_SIMPLESTATS_AUDIENCE_TECHNOLOGY'); ?></h2>
				</summary>
				<div class="ss-dashboard-section__content">
					<div class="ss-grid">
				<?php
				$dimensionTables = [
					[Text::_('COM_SIMPLESTATS_COUNTRIES'), $this->data['countries'], 'country-pie', 'countries'],
					[Text::_('COM_SIMPLESTATS_REFERRERS'), $this->data['referrers'], 'plain', 'referrers'],
					[Text::_('COM_SIMPLESTATS_LANGUAGES'), $this->data['languages'], 'pie', 'languages'],
					[Text::_('COM_SIMPLESTATS_DEVICES'), $this->data['devices'], 'pie', 'devices'],
					[Text::_('COM_SIMPLESTATS_BROWSERS'), $this->data['browsers'], 'pie', 'browsers'],
					[Text::_('COM_SIMPLESTATS_BOT_NAMES'), $this->data['bots'], 'plain', 'bots'],
					[Text::_('COM_SIMPLESTATS_CUSTOM_EVENTS'), $this->data['eventTypes'], 'plain', 'events'],
				];
				foreach ($dimensionTables as [$title, $rows, $mode, $report]) :
					$rows = $sortDashboardRows($rows, $report);
					$isCountry = $mode === 'country-pie';
					$isPie = \in_array($mode, ['pie', 'country-pie'], true);
				?>
					<section class="ss-panel ss-panel--compact">
						<header class="ss-panel__header">
							<h3><?php echo $escape($title); ?></h3>
							<a class="ss-view-all" href="<?php echo $reportUrl($report); ?>"><?php echo Text::_('COM_SIMPLESTATS_VIEW_ALL'); ?></a>
						</header>
					<div class="ss-panel__body ss-panel__body--flush">
						<?php if ($rows === []) : ?><div class="ss-empty"><?php echo Text::_('COM_SIMPLESTATS_NO_DATA'); ?></div><?php else : ?>
						<?php if ($isCountry && $showCountryWarning) : ?>
							<div class="alert alert-warning ss-country-warning"><?php echo Text::_('COM_SIMPLESTATS_COUNTRY_ONLY_UNKNOWN'); ?></div>
						<?php endif; ?>
						<?php if ($isCountry) : ?>
							<p class="ss-panel-note"><?php echo Text::_('COM_SIMPLESTATS_COUNTRY_NEW_EVENTS_ONLY'); ?></p>
						<?php endif; ?>
						<?php if ($isPie) :
							$pieTotal = array_sum(array_map(static fn(object $row): int => (int) $row->count, $rows));
							$pieOffset = 0.0;
						?>
							<div class="ss-pie-wrap">
								<div class="ss-pie-chart">
									<svg width="200" height="200" viewBox="0 0 120 120" role="img" aria-label="<?php echo $escape($title); ?>">
										<circle class="ss-pie-chart__track" cx="60" cy="60" r="30" pathLength="100"></circle>
										<?php foreach ($rows as $rowIndex => $row) :
											$piePercentage = $pieTotal > 0 ? (((int) $row->count / $pieTotal) * 100.0) : 0.0;
											$pieRemainder = max(0.0, 100.0 - $piePercentage);
											$rowLabel = $isCountry ? $countryName((string) $row->label) : (string) $row->label;
										?>
											<circle cx="60"
												cy="60"
												r="30"
												pathLength="100"
												fill="none"
												stroke="<?php echo $escape($piePalette[$rowIndex % \count($piePalette)]); ?>"
												stroke-width="60"
												stroke-dasharray="<?php echo number_format($piePercentage, 4, '.', ''); ?> <?php echo number_format($pieRemainder, 4, '.', ''); ?>"
												stroke-dashoffset="<?php echo number_format(-$pieOffset, 4, '.', ''); ?>"
												stroke-linecap="butt"
												transform="rotate(-90 60 60)">
												<title><?php echo $escape($rowLabel . ': ' . $number($row->count)); ?></title>
											</circle>
										<?php
											$pieOffset += $piePercentage;
										endforeach;
										?>
									</svg>
								</div>
								<ul class="ss-pie-legend">
									<?php foreach ($rows as $rowIndex => $row) :
										$piePercentage = $pieTotal > 0 ? (((int) $row->count / $pieTotal) * 100.0) : 0.0;
										$pieColor = $piePalette[$rowIndex % \count($piePalette)];
										$rowLabel = $isCountry ? $countryName((string) $row->label) : (string) $row->label;
									?>
										<li>
											<svg class="ss-pie-legend__swatch" width="11" height="11" viewBox="0 0 11 11" aria-hidden="true" focusable="false">
												<circle cx="5.5" cy="5.5" r="5" fill="<?php echo $escape($pieColor); ?>"></circle>
											</svg>
											<span><?php echo $escape($rowLabel); ?></span>
											<strong><?php echo number_format($piePercentage, 1); ?>%</strong>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>
							<table class="table ss-table mb-0" id="ss-table-<?php echo $escape($report); ?>">
								<thead>
									<tr>
										<?php echo $sortableHeading($report, 'label', $title, 'asc'); ?>
										<?php echo $sortableHeading($report, 'count', Text::_('COM_SIMPLESTATS_CSV_COUNT'), 'desc', 'text-end'); ?>
									</tr>
								</thead>
								<tbody>
								<?php foreach ($rows as $rowIndex => $row) : ?>
									<tr>
										<td class="text-break" data-sort-value="<?php echo $escape($isCountry ? $countryName((string) $row->label) : $row->label); ?>">
											<?php if ($isPie) : ?>
												<svg class="ss-legend-dot" width="11" height="11" viewBox="0 0 11 11" aria-hidden="true" focusable="false">
													<circle cx="5.5" cy="5.5" r="5" fill="<?php echo $escape($piePalette[$rowIndex % \count($piePalette)]); ?>"></circle>
												</svg>
											<?php endif; ?>
											<?php echo $isCountry ? $countryLabel((string) $row->label) : $escape($row->label); ?>
										</td>
										<td class="text-end ss-count" data-sort-value="<?php echo (int) $row->count; ?>"><?php echo $number($row->count); ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				</section>
				<?php endforeach; ?>
					</div>
				</div>
			</details>

			<details class="ss-dashboard-section" id="ss-section-system"<?php echo $systemNeedsAttention ? ' open' : ''; ?>>
				<summary class="ss-dashboard-section__summary">
					<h2><span class="icon-cog" aria-hidden="true"></span><?php echo Text::_('COM_SIMPLESTATS_SYSTEM'); ?></h2>
				</summary>
				<div class="ss-dashboard-section__content">
					<section class="ss-panel ss-system">
				<header class="ss-panel__header"><h3><?php echo Text::_('COM_SIMPLESTATS_PRIVACY_STATUS'); ?></h3></header>
			<div class="ss-system__grid">
				<div><span><?php echo Text::_('COM_SIMPLESTATS_VERSION'); ?></span><strong><?php echo $escape($this->version); ?></strong></div>
				<div><span><?php echo Text::_('COM_SIMPLESTATS_RETENTION'); ?></span><strong><?php echo Text::sprintf('COM_SIMPLESTATS_DAYS_VALUE', $this->retentionDays); ?></strong></div>
				<div><span><?php echo Text::_('COM_SIMPLESTATS_GERMAN_VISITS'); ?></span><strong><?php echo $number($summary->german_visits ?? 0); ?></strong></div>
				<div><span><?php echo Text::_('COM_SIMPLESTATS_COUNTRY_DATABASE'); ?></span><strong><?php echo $this->countryStatus === [] ? Text::_('COM_SIMPLESTATS_NOT_INSTALLED') : $escape($this->countryStatus['release'] ?? ''); ?></strong></div>
			</div>
			<div class="ss-system__notes">
				<p><?php echo Text::_('COM_SIMPLESTATS_PRIVACY_NOTE'); ?></p>
				<?php if ($this->countryStatus !== []) : ?>
					<p><?php echo Text::sprintf('COM_SIMPLESTATS_COUNTRY_DATABASE_STATUS', $escape($this->countryStatus['updated_at'] ?? ''), (int) ($this->countryStatus['ipv4_count'] ?? 0), (int) ($this->countryStatus['ipv6_count'] ?? 0)); ?></p>
					<?php if (!(bool) ($this->countryStatus['files_ready'] ?? false)) : ?>
						<div class="alert alert-danger mb-3"><?php echo Text::_('COM_SIMPLESTATS_COUNTRY_DATABASE_UNREADABLE'); ?></div>
					<?php endif; ?>
				<?php else : ?>
					<div class="alert alert-warning mb-3"><?php echo Text::_('COM_SIMPLESTATS_COUNTRY_DATABASE_MISSING'); ?></div>
				<?php endif; ?>
				<p><?php echo Text::_('COM_SIMPLESTATS_COUNTRY_DATABASE_FORWARD_ONLY'); ?></p>
				<p class="small text-muted mb-0"><?php echo Text::_('COM_SIMPLESTATS_DBIP_ATTRIBUTION'); ?></p>
			</div>
					</section>
				</div>
			</details>
	</div>
</form>
