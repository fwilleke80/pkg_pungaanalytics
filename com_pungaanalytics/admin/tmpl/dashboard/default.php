<?php

declare(strict_types=1);

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

$summary = $this->data['summary'];
$rangeOptions = [
	'today' => Text::_('COM_PUNGAANALYTICS_RANGE_TODAY'),
	'yesterday' => Text::_('COM_PUNGAANALYTICS_RANGE_YESTERDAY'),
	'last24' => Text::_('COM_PUNGAANALYTICS_RANGE_LAST24'),
	'7' => Text::_('COM_PUNGAANALYTICS_RANGE_7'),
	'30' => Text::_('COM_PUNGAANALYTICS_RANGE_30'),
	'90' => Text::_('COM_PUNGAANALYTICS_RANGE_90'),
	'365' => Text::_('COM_PUNGAANALYTICS_RANGE_365'),
	'all' => Text::_('COM_PUNGAANALYTICS_RANGE_ALL'),
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
		return Text::_('COM_PUNGAANALYTICS_COUNTRY_UNKNOWN');
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
	$flagMarkup = $flag === '' ? '' : ' <span class="pa-country-flag" aria-hidden="true">' . $escape($flag) . '</span>';

	return $name !== $code
		? $escape($name) . $flagMarkup . ' <span class="pa-code">' . $escape($code) . '</span>'
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

	return (string) Text::_('COM_PUNGAANALYTICS_UNKNOWN_ITEM');
};
$eventItemLabel = static fn(object $row): string => $escape($eventItemSortLabel($row));
$sourceLabels = [
	'direct' => Text::_('COM_PUNGAANALYTICS_SOURCE_DIRECT'),
	'search' => Text::_('COM_PUNGAANALYTICS_SOURCE_SEARCH'),
	'social' => Text::_('COM_PUNGAANALYTICS_SOURCE_SOCIAL'),
	'ai' => Text::_('COM_PUNGAANALYTICS_SOURCE_AI'),
	'referral' => Text::_('COM_PUNGAANALYTICS_SOURCE_REFERRAL'),
];
$selectedRange = $this->range;
$selectedSortTable = $this->sortTable;
$selectedSort = $this->sort;
$selectedDirection = $this->direction;
$selectedRangeLabel = $rangeOptions[$selectedRange] ?? $rangeOptions['7'];
$exportAllUrl = Route::_(
	'index.php?option=com_pungaanalytics&task=report.exportAllCsv'
	. '&days=' . rawurlencode($selectedRange)
	. '&' . Session::getFormToken() . '=1'
);
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

foreach (['countries', 'referrers', 'sources', 'languages', 'devices', 'browsers', 'bots', 'events'] as $dimensionTable)
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

$dashboardSortDefinitions['notfound'] = [
	'defaultField' => 'total',
	'defaultDirection' => 'desc',
	'fields' => [
		'path' => ['property' => 'path', 'type' => 'text'],
		'human' => ['property' => 'human', 'type' => 'number'],
		'bots' => ['property' => 'bots', 'type' => 'number'],
		'total' => ['property' => 'total', 'type' => 'number'],
		'last_seen' => ['property' => 'last_seen', 'type' => 'text'],
	],
];

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
) use ($dashboardSortDefinitions, $rankingTableKeys, $countryName, $eventItemSortLabel, $sourceLabels): int|string
{
	if (\in_array($table, $rankingTableKeys, true) && $field === 'title')
	{
		return $eventItemSortLabel($row);
	}

	if ($table === 'countries' && $field === 'label')
	{
		return $countryName((string) ($row->label ?? ''));
	}

	if ($table === 'sources' && $field === 'label')
	{
		$value = (string) ($row->label ?? '');

		return $sourceLabels[$value] ?? $value;
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
) use ($escape, $getDashboardSortState, $selectedRange, $rankingTableKeys): string
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
		? '<span class="pa-sort-indicator" aria-hidden="true">'
			. ($currentDirection === 'desc' ? '▼' : '▲')
			. '</span>'
		: '';
	$classAttribute = $className === '' ? '' : ' class="' . $escape($className) . '"';
	$tableView = match (true)
	{
		\in_array($table, ['activity', 'hours', 'weekdays'], true) => 'traffic',
		\in_array($table, ['pages', 'notfound'], true) => 'content',
		\in_array($table, $rankingTableKeys, true) => 'engagement',
		\in_array($table, ['sources', 'referrers'], true) => 'acquisition',
		\in_array($table, ['countries', 'languages', 'devices', 'browsers', 'bots', 'events'], true) => 'audience',
		default => 'overview',
	};
	$url = Route::_(
		'index.php?option=com_pungaanalytics'
		. '&days=' . rawurlencode($selectedRange)
		. '&sort_table=' . rawurlencode($table)
		. '&sort=' . rawurlencode($field)
		. '&direction=' . rawurlencode($nextDirection)
		. '&dashboardview=' . rawurlencode($tableView)
		. ($tableView === 'audience' ? '&audienceview=' . rawurlencode($table) : ''),
		false
	) . '#pa-table-' . rawurlencode($table);

	return '<th scope="col"' . $classAttribute . $ariaAttribute
		. '><a href="' . $escape($url) . '" class="pa-sort-link"'
		. ' title="' . $escape(Text::sprintf('COM_PUNGAANALYTICS_SORT_BY', $label)) . '">'
		. $escape($label) . $indicator . '</a></th>';
};
$summaryMetrics = [
	[(int) ($summary->human_visits ?? 0), Text::_('COM_PUNGAANALYTICS_HUMAN_VISITS'), 'icon-users'],
	[(int) ($summary->human_pageviews ?? 0), Text::_('COM_PUNGAANALYTICS_HUMAN_PAGEVIEWS'), 'icon-eye'],
];
$chartSeries = [
	['visits', Text::_('COM_PUNGAANALYTICS_VISITS'), '#6f42c1', ''],
	['pageviews', Text::_('COM_PUNGAANALYTICS_PAGEVIEWS'), '#2a69b8', ''],
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

$summaryMetrics[] = [(int) ($summary->authenticated_pageviews ?? 0), Text::_('COM_PUNGAANALYTICS_AUTHENTICATED_PAGEVIEWS'), 'icon-user'];
$summaryMetrics[] = [(int) ($summary->bot_pageviews ?? 0), Text::_('COM_PUNGAANALYTICS_BOT_PAGEVIEWS'), 'icon-cogs'];
$chartSeries[] = ['bots', Text::_('COM_PUNGAANALYTICS_BOTS'), '#c94b54', ''];
$piePalette = ['#2a69b8', '#6f42c1', '#198754', '#d99000', '#c94b54', '#0f8b8d', '#dd6e42', '#607d8b', '#8e6c88', '#6c8e3f', '#b36b00', '#5c6bc0'];
$trendRows = $this->data['trend'];
$maxTrendValue = 1;
$trendGranularityKey = 'COM_PUNGAANALYTICS_GRANULARITY_' . strtoupper((string) $this->data['trendGranularity']);
$reportUrl = static fn(string $report, string $eventType = ''): string => Route::_(
	'index.php?option=com_pungaanalytics&view=report&report=' . rawurlencode($report)
	. ($eventType === '' ? '' : '&event_type=' . rawurlencode($eventType))
	. '&days=' . rawurlencode($selectedRange)
);
$historyUrl = static function (
	string $dimension,
	string $value,
	array $arguments = []
) use ($selectedRange): string
{
	$query = [
		'option' => 'com_pungaanalytics',
		'view' => 'report',
		'report' => 'history',
		'dimension' => $dimension,
		'value' => $value,
		'days' => $selectedRange,
	] + $arguments;

	return Route::_('index.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
};
$hourLabel = static fn(int $hour): string => sprintf('%02d:00–%02d:00', $hour, ($hour + 1) % 24);
$weekdayLabels = [
	1 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_1'),
	2 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_2'),
	3 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_3'),
	4 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_4'),
	5 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_5'),
	6 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_6'),
	7 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_7'),
];
$hourLabels = [];
$hourTickLabels = [];

for ($hour = 0; $hour < 24; $hour++)
{
	$hourLabels[$hour] = $hourLabel($hour);
	$hourTickLabels[$hour] = $hour % 3 === 0 ? sprintf('%02d', $hour) : '';
}

$weekdayTickLabels = [
	1 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_SHORT_1'),
	2 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_SHORT_2'),
	3 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_SHORT_3'),
	4 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_SHORT_4'),
	5 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_SHORT_5'),
	6 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_SHORT_6'),
	7 => Text::_('COM_PUNGAANALYTICS_WEEKDAY_SHORT_7'),
];
$dashboardTrendRows = $this->activityTableRows > 0
	? array_slice($trendRows, -$this->activityTableRows)
	: $trendRows;
$dashboardTrendRows = $sortDashboardRows($dashboardTrendRows, 'activity');
$hourRows = $sortDashboardRows($this->data['hours'], 'hours');
$weekdayRows = $sortDashboardRows($this->data['weekdays'], 'weekdays');
$topPagesRows = $sortDashboardRows($this->data['topPages'], 'pages');
$notFoundRows = $sortDashboardRows($this->data['notFound'], 'notfound');
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
$acquisitionSortTables = ['sources', 'referrers'];
$audienceSortTables = ['countries', 'languages', 'devices', 'browsers', 'bots', 'events'];
$systemNeedsAttention = $this->countryStatus === []
	|| !(bool) ($this->countryStatus['files_ready'] ?? false);
$dashboardViewIds = [
	'overview' => 'pa-dashboard-overview',
	'traffic' => 'pa-dashboard-traffic',
	'content' => 'pa-dashboard-content',
	'engagement' => 'pa-dashboard-engagement',
	'acquisition' => 'pa-dashboard-acquisition',
	'audience' => 'pa-dashboard-audience',
	'system' => 'pa-dashboard-system',
];
$sortTableViews = [
	'activity' => 'traffic',
	'hours' => 'traffic',
	'weekdays' => 'traffic',
	'pages' => 'content',
	'notfound' => 'content',
	'sources' => 'acquisition',
	'referrers' => 'acquisition',
	'countries' => 'audience',
	'languages' => 'audience',
	'devices' => 'audience',
	'browsers' => 'audience',
	'bots' => 'audience',
	'events' => 'audience',
];

foreach ($rankingTableKeys as $rankingTableKey)
{
	$sortTableViews[(string) $rankingTableKey] = 'engagement';
}

$requestedDashboardView = strtolower(Factory::getApplication()->input->getCmd('dashboardview', ''));
$activeDashboardView = isset($dashboardViewIds[$requestedDashboardView])
	? $requestedDashboardView
	: ($sortTableViews[$this->sortTable] ?? 'overview');

if ($activeDashboardView === 'engagement' && $rankingDefinitions === [])
{
	$activeDashboardView = 'overview';
}

$audienceViews = ['countries', 'languages', 'devices', 'browsers', 'bots', 'events'];
$requestedAudienceView = strtolower(Factory::getApplication()->input->getCmd('audienceview', ''));
$activeAudienceView = \in_array($this->sortTable, $audienceViews, true)
	? $this->sortTable
	: (\in_array($requestedAudienceView, $audienceViews, true) ? $requestedAudienceView : 'countries');
$dashboardBaseUrl = static function (string $view, string $audienceView = '') use ($selectedRange): string
{
	$query = [
		'option' => 'com_pungaanalytics',
		'days' => $selectedRange,
		'dashboardview' => $view,
	];

	if ($view === 'audience' && $audienceView !== '')
	{
		$query['audienceview'] = $audienceView;
	}

	return Route::_('index.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
};
$topPage = $this->data['topPages'][0] ?? null;
$topSource = $this->data['trafficSources'][0] ?? null;
$topCountry = $this->data['countries'][0] ?? null;
$topEngagement = null;
$topEngagementTitle = '';

foreach ($this->data['customEventRankings'] ?? [] as $ranking)
{
	$rows = $ranking['rows'] ?? [];

	if ($rows !== [] && ($topEngagement === null || (int) $rows[0]->count > (int) $topEngagement->count))
	{
		$topEngagement = $rows[0];
		$topEngagementTitle = (string) ($ranking['definition']['title'] ?? '');
	}
}
?>
<form action="<?php echo Route::_('index.php?option=com_pungaanalytics'); ?>" method="post" id="adminForm" name="adminForm">
	<input type="hidden" name="option" value="com_pungaanalytics">
	<input type="hidden" name="task" value="">
	<input type="hidden" name="days" value="<?php echo $escape($selectedRange); ?>">
	<?php echo HTMLHelper::_('form.token'); ?>

		<div class="pa-dashboard">
			<header class="pa-hero">
				<div class="pa-hero__copy">
					<h1 class="pa-hero__title"><?php echo Text::_('COM_PUNGAANALYTICS_DASHBOARD_TITLE'); ?></h1>
					<h2 class="pa-section-heading pa-section-heading--hero"><?php echo Text::_('COM_PUNGAANALYTICS_DASHBOARD_EYEBROW'); ?></h2>
					<p><?php echo Text::sprintf('COM_PUNGAANALYTICS_DATE_RANGE', $escape($this->data['displayFrom']), $escape($this->data['displayTo'])); ?></p>
				</div>
			</header>

			<div class="pa-report-controls">
				<details class="pa-range-picker">
					<summary>
						<span class="icon-calendar" aria-hidden="true"></span>
						<span class="pa-range-picker__label"><?php echo Text::_('COM_PUNGAANALYTICS_RANGE'); ?></span>
						<strong><?php echo $escape($selectedRangeLabel); ?></strong>
						<span class="pa-range-picker__chevron" aria-hidden="true"></span>
					</summary>
					<div class="pa-range-picker__menu">
						<?php foreach ($rangeOptions as $value => $label) : ?>
							<a class="<?php echo $selectedRange === $value ? 'is-active' : ''; ?>"
								<?php echo $selectedRange === $value ? 'aria-current="page"' : ''; ?>
								href="<?php echo Route::_(
								'index.php?option=com_pungaanalytics&days=' . rawurlencode((string) $value)
								. '&dashboardview=' . rawurlencode($activeDashboardView)
								. ($activeDashboardView === 'audience' ? '&audienceview=' . rawurlencode($activeAudienceView) : '')
							); ?>">
								<span><?php echo $escape($label); ?></span>
								<?php if ($selectedRange === $value) : ?>
									<span class="icon-check" aria-hidden="true"></span>
								<?php endif; ?>
							</a>
						<?php endforeach; ?>
					</div>
				</details>
				<a class="btn btn-primary pa-export-all" href="<?php echo $exportAllUrl; ?>">
					<span class="icon-download" aria-hidden="true"></span>
					<?php echo Text::_('COM_PUNGAANALYTICS_EXPORT_ALL_CSV_ZIP'); ?>
				</a>
			</div>

			<div class="pa-dashboard-tabs">
				<?php echo HTMLHelper::_('uitab.startTabSet', 'pungaAnalyticsDashboardTabs', [
					'active' => $dashboardViewIds[$activeDashboardView],
					'breakpoint' => 768,
				]); ?>

				<?php echo HTMLHelper::_('uitab.addTab', 'pungaAnalyticsDashboardTabs', $dashboardViewIds['overview'], Text::_('COM_PUNGAANALYTICS_OVERVIEW')); ?>
				<div class="pa-dashboard-tab">
					<section class="pa-summary-metrics" aria-label="<?php echo Text::_('COM_PUNGAANALYTICS_OVERVIEW'); ?>">
						<?php foreach ($summaryMetrics as [$value, $label, $icon]) : ?>
							<article class="pa-summary-metric">
								<span class="pa-summary-metric__label"><span class="<?php echo $escape($icon); ?>" aria-hidden="true"></span><?php echo $escape($label); ?></span>
								<strong><?php echo $number($value); ?></strong>
							</article>
						<?php endforeach; ?>
					</section>
					<div class="pa-overview-highlights">
						<a class="pa-highlight" href="<?php echo $dashboardBaseUrl('content'); ?>">
							<span class="pa-highlight__label"><?php echo Text::_('COM_PUNGAANALYTICS_TOP_PAGES'); ?></span>
							<strong><?php echo $topPage === null ? Text::_('COM_PUNGAANALYTICS_NO_DATA') : $escape($topPage->label); ?></strong>
							<span><?php echo $topPage === null ? '0' : $number($topPage->count); ?></span>
						</a>
						<a class="pa-highlight" href="<?php echo $dashboardBaseUrl('acquisition'); ?>">
							<span class="pa-highlight__label"><?php echo Text::_('COM_PUNGAANALYTICS_TRAFFIC_SOURCES'); ?></span>
							<strong><?php echo $topSource === null ? Text::_('COM_PUNGAANALYTICS_NO_DATA') : $escape($sourceLabels[(string) $topSource->label] ?? (string) $topSource->label); ?></strong>
							<span><?php echo $topSource === null ? '0' : $number($topSource->count); ?></span>
						</a>
						<a class="pa-highlight" href="<?php echo $dashboardBaseUrl('audience', 'countries'); ?>">
							<span class="pa-highlight__label"><?php echo Text::_('COM_PUNGAANALYTICS_COUNTRIES'); ?></span>
							<strong><?php echo $topCountry === null ? Text::_('COM_PUNGAANALYTICS_NO_DATA') : $escape($countryName((string) $topCountry->label)); ?></strong>
							<span><?php echo $topCountry === null ? '0' : $number($topCountry->count); ?></span>
						</a>
						<?php if ($rankingDefinitions !== []) : ?>
							<a class="pa-highlight" href="<?php echo $dashboardBaseUrl('engagement'); ?>">
								<span class="pa-highlight__label"><?php echo Text::_('COM_PUNGAANALYTICS_ENGAGEMENT'); ?></span>
								<strong><?php echo $topEngagement === null ? Text::_('COM_PUNGAANALYTICS_NO_DATA') : $eventItemLabel($topEngagement); ?></strong>
								<span><?php echo $topEngagement === null ? '0' : $escape($topEngagementTitle . ' · ' . $number($topEngagement->count)); ?></span>
							</a>
						<?php endif; ?>
					</div>
				</div>
				<?php echo HTMLHelper::_('uitab.endTab'); ?>

				<?php echo HTMLHelper::_('uitab.addTab', 'pungaAnalyticsDashboardTabs', $dashboardViewIds['traffic'], Text::_('COM_PUNGAANALYTICS_TRAFFIC')); ?>
				<div class="pa-dashboard-tab">
					<section class="pa-panel pa-panel--wide">
						<header class="pa-panel__header">
							<h3><?php echo Text::_('COM_PUNGAANALYTICS_ACTIVITY_TREND'); ?></h3>
							<div class="pa-panel__tools">
								<span class="pa-panel__meta"><?php echo Text::sprintf('COM_PUNGAANALYTICS_TREND_GRANULARITY', Text::_($trendGranularityKey)); ?></span>
								<a class="pa-view-all" href="<?php echo $reportUrl('activity'); ?>"><?php echo Text::_('COM_PUNGAANALYTICS_VIEW_ALL'); ?></a>
							</div>
						</header>
						<div class="pa-panel__body pa-panel__body--flush">
							<?php if ($trendRows === []) : ?>
								<div class="pa-empty"><?php echo Text::_('COM_PUNGAANALYTICS_NO_DATA'); ?></div>
							<?php else : ?>
								<div class="pa-daily-chart">
									<div class="pa-chart-legend" aria-hidden="true">
										<?php foreach ($chartSeries as [$property, $label, $color]) : ?>
											<span class="pa-series-<?php echo $escape($property); ?>">
												<svg class="pa-chart-legend__swatch" width="11" height="11" viewBox="0 0 11 11" focusable="false">
													<circle cx="5.5" cy="5.5" r="5" fill="<?php echo $escape($color); ?>"></circle>
												</svg>
												<?php echo $escape($label); ?>
											</span>
										<?php endforeach; ?>
									</div>
									<div class="pa-chart-scroll">
										<svg class="pa-daily-chart__svg"
											viewBox="0 0 <?php echo $trendChartWidth; ?> <?php echo $trendChartHeight; ?>"
											preserveAspectRatio="xMidYMid meet"
											role="img"
											aria-label="<?php echo Text::_('COM_PUNGAANALYTICS_TREND_CHART_LABEL'); ?>">
											<?php for ($line = 0; $line <= 4; $line++) :
												$lineY = $trendPlotTop + ($line * ($trendPlotHeight / 4.0));
												$lineValue = (int) round($maxTrendValue * ((4 - $line) / 4));
											?>
												<line class="pa-chart-gridline"
													x1="<?php echo $trendPlotLeft; ?>"
													y1="<?php echo number_format($lineY, 1, '.', ''); ?>"
													x2="<?php echo $trendChartWidth - $trendPlotRight; ?>"
													y2="<?php echo number_format($lineY, 1, '.', ''); ?>"></line>
												<text class="pa-chart-axis-label"
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
													<line class="pa-chart-date-tick"
														x1="<?php echo number_format($groupCenter, 1, '.', ''); ?>"
														y1="<?php echo $trendPlotTop + $trendPlotHeight + 3; ?>"
														x2="<?php echo number_format($groupCenter, 1, '.', ''); ?>"
														y2="<?php echo $trendPlotTop + $trendPlotHeight + 8; ?>"></line>
													<text class="pa-chart-date-label"
														x="<?php echo number_format($groupCenter, 1, '.', ''); ?>"
														y="<?php echo $trendPlotTop + $trendPlotHeight + 23; ?>"
														text-anchor="middle"><?php echo $escape((string) $row->period_label); ?></text>
												<?php endif; ?>
											<?php endforeach; ?>
										</svg>
									</div>
								</div>
								<div class="table-responsive">
									<table class="table pa-table mb-0" id="pa-table-activity">
										<thead>
											<tr>
												<?php echo $sortableHeading('activity', 'period', Text::_('COM_PUNGAANALYTICS_PERIOD'), 'desc'); ?>
												<?php echo $sortableHeading('activity', 'visits', Text::_('COM_PUNGAANALYTICS_VISITS'), 'desc', 'text-end'); ?>
												<?php echo $sortableHeading('activity', 'pageviews', Text::_('COM_PUNGAANALYTICS_PAGEVIEWS'), 'desc', 'text-end'); ?>
												<?php foreach ($trendEventDefinitions as $definition) : ?>
													<?php echo $sortableHeading('activity', (string) $definition['key'], (string) $definition['title'], 'desc', 'text-end'); ?>
												<?php endforeach; ?>
												<?php echo $sortableHeading('activity', 'bots', Text::_('COM_PUNGAANALYTICS_BOTS'), 'desc', 'text-end'); ?>
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
					<section class="pa-view-section" aria-labelledby="pa-time-of-day-heading">
						<h2 class="pa-view-heading" id="pa-time-of-day-heading"><span class="icon-clock" aria-hidden="true"></span><?php echo Text::_('COM_PUNGAANALYTICS_TIME_OF_DAY'); ?></h2>
						<div class="pa-grid pa-grid--time">
					<section class="pa-panel">
							<header class="pa-panel__header">
								<h3><?php echo Text::_('COM_PUNGAANALYTICS_BY_HOUR'); ?></h3>
							<div class="pa-panel__tools">
								<span class="pa-panel__meta"><?php echo Text::_('COM_PUNGAANALYTICS_SITE_LOCAL_TIME'); ?></span>
								<a class="pa-view-all" href="<?php echo $reportUrl('hours'); ?>"><?php echo Text::_('COM_PUNGAANALYTICS_VIEW_ALL'); ?></a>
							</div>
						</header>
						<p class="pa-panel-note"><?php echo Text::_('COM_PUNGAANALYTICS_HOUR_HISTORY_NOTE'); ?></p>
						<?php echo LayoutHelper::render(
							'visitsbarchart',
							[
								'rows' => $this->data['hours'],
								'labels' => $hourLabels,
								'tickLabels' => $hourTickLabels,
								'ariaLabel' => Text::_('COM_PUNGAANALYTICS_HOURLY_VISITORS_CHART_LABEL'),
							],
							JPATH_COMPONENT_ADMINISTRATOR . '/layouts'
						); ?>
						<div class="table-responsive pa-time-table">
						<table class="table pa-table mb-0" id="pa-table-hours">
							<thead>
								<tr>
									<?php echo $sortableHeading('hours', 'hour', Text::_('COM_PUNGAANALYTICS_HOUR'), 'asc'); ?>
									<?php echo $sortableHeading('hours', 'pageviews', Text::_('COM_PUNGAANALYTICS_PAGEVIEWS'), 'desc', 'text-end'); ?>
									<?php echo $sortableHeading('hours', 'visits', Text::_('COM_PUNGAANALYTICS_VISITS'), 'desc', 'text-end'); ?>
									<?php foreach ($timeEventDefinitions as $definition) : ?>
										<?php echo $sortableHeading('hours', (string) $definition['key'], (string) $definition['title'], 'desc', 'text-end'); ?>
									<?php endforeach; ?>
									<?php echo $sortableHeading('hours', 'bots', Text::_('COM_PUNGAANALYTICS_BOTS'), 'desc', 'text-end'); ?>
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

					<section class="pa-panel">
							<header class="pa-panel__header">
								<h3><?php echo Text::_('COM_PUNGAANALYTICS_BY_WEEKDAY'); ?></h3>
							<span class="pa-panel__meta"><?php echo Text::_('COM_PUNGAANALYTICS_SITE_LOCAL_TIME'); ?></span>
						</header>
						<?php echo LayoutHelper::render(
							'visitsbarchart',
							[
								'rows' => $this->data['weekdays'],
								'labels' => $weekdayLabels,
								'tickLabels' => $weekdayTickLabels,
								'ariaLabel' => Text::_('COM_PUNGAANALYTICS_WEEKDAY_VISITORS_CHART_LABEL'),
							],
							JPATH_COMPONENT_ADMINISTRATOR . '/layouts'
						); ?>
						<div class="table-responsive pa-time-table">
							<table class="table pa-table mb-0" id="pa-table-weekdays">
								<thead>
									<tr>
										<?php echo $sortableHeading('weekdays', 'weekday', Text::_('COM_PUNGAANALYTICS_WEEKDAY'), 'asc'); ?>
										<?php echo $sortableHeading('weekdays', 'pageviews', Text::_('COM_PUNGAANALYTICS_PAGEVIEWS'), 'desc', 'text-end'); ?>
										<?php echo $sortableHeading('weekdays', 'visits', Text::_('COM_PUNGAANALYTICS_VISITS'), 'desc', 'text-end'); ?>
										<?php foreach ($timeEventDefinitions as $definition) : ?>
											<?php echo $sortableHeading('weekdays', (string) $definition['key'], (string) $definition['title'], 'desc', 'text-end'); ?>
										<?php endforeach; ?>
										<?php echo $sortableHeading('weekdays', 'bots', Text::_('COM_PUNGAANALYTICS_BOTS'), 'desc', 'text-end'); ?>
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
					</section>
				</div>
				<?php echo HTMLHelper::_('uitab.endTab'); ?>

				<?php echo HTMLHelper::_('uitab.addTab', 'pungaAnalyticsDashboardTabs', $dashboardViewIds['content'], Text::_('COM_PUNGAANALYTICS_CONTENT')); ?>
				<div class="pa-dashboard-tab">
					<div class="pa-grid pa-grid--content">
				<section class="pa-panel">
					<header class="pa-panel__header">
						<h3><?php echo Text::_('COM_PUNGAANALYTICS_TOP_PAGES'); ?></h3>
						<a class="pa-view-all" href="<?php echo $reportUrl('pages'); ?>"><?php echo Text::_('COM_PUNGAANALYTICS_VIEW_ALL'); ?></a>
				</header>
				<div class="pa-panel__body pa-panel__body--flush">
					<?php if ($topPagesRows === []) : ?><div class="pa-empty"><?php echo Text::_('COM_PUNGAANALYTICS_NO_DATA'); ?></div><?php else : ?>
					<table class="table pa-table mb-0" id="pa-table-pages">
						<thead>
							<tr>
								<?php echo $sortableHeading('pages', 'label', Text::_('COM_PUNGAANALYTICS_CSV_LABEL'), 'asc'); ?>
								<?php echo $sortableHeading('pages', 'count', Text::_('COM_PUNGAANALYTICS_CSV_COUNT'), 'desc', 'text-end'); ?>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($topPagesRows as $row) : ?>
							<tr>
								<td class="text-break" data-sort-value="<?php echo $escape($row->label); ?>">
									<a href="<?php echo $escape($siteRoot . (string) $row->label); ?>" target="_blank" rel="noopener noreferrer"><?php echo $escape($row->label); ?></a>
									<a class="pa-history-link"
										href="<?php echo $historyUrl('page', (string) $row->label); ?>"
										title="<?php echo $escape(Text::_('COM_PUNGAANALYTICS_VIEW_HISTORY')); ?>">
										<span class="icon-chart" aria-hidden="true"></span>
										<span class="visually-hidden"><?php echo Text::_('COM_PUNGAANALYTICS_VIEW_HISTORY'); ?></span>
									</a>
								</td>
								<td class="text-end pa-count" data-sort-value="<?php echo (int) $row->count; ?>"><?php echo $number($row->count); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php endif; ?>
				</div>
			</section>
				<section class="pa-panel">
					<header class="pa-panel__header">
						<h3><?php echo Text::_('COM_PUNGAANALYTICS_NOT_FOUND'); ?></h3>
						<a class="pa-view-all" href="<?php echo $reportUrl('notfound'); ?>"><?php echo Text::_('COM_PUNGAANALYTICS_VIEW_ALL'); ?></a>
					</header>
					<div class="pa-panel__body pa-panel__body--flush">
						<?php if ($notFoundRows === []) : ?>
							<div class="pa-empty"><?php echo Text::_('COM_PUNGAANALYTICS_NO_404_DATA'); ?></div>
						<?php else : ?>
							<table class="table pa-table mb-0" id="pa-table-notfound">
								<thead>
									<tr>
										<?php echo $sortableHeading('notfound', 'path', Text::_('COM_PUNGAANALYTICS_CSV_PATH'), 'asc'); ?>
										<?php echo $sortableHeading('notfound', 'human', Text::_('COM_PUNGAANALYTICS_HUMAN_REQUESTS'), 'desc', 'text-end'); ?>
										<?php echo $sortableHeading('notfound', 'bots', Text::_('COM_PUNGAANALYTICS_BOT_REQUESTS'), 'desc', 'text-end'); ?>
										<?php echo $sortableHeading('notfound', 'total', Text::_('COM_PUNGAANALYTICS_REQUESTS'), 'desc', 'text-end'); ?>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($notFoundRows as $row) : ?>
										<tr>
											<td class="text-break" data-sort-value="<?php echo $escape($row->path); ?>">
												<a href="<?php echo $escape($siteRoot . (string) $row->path); ?>" target="_blank" rel="noopener noreferrer"><?php echo $escape($row->path); ?></a>
												<a class="pa-history-link"
													href="<?php echo $historyUrl('notfound', (string) $row->path); ?>"
													title="<?php echo $escape(Text::_('COM_PUNGAANALYTICS_VIEW_HISTORY')); ?>">
													<span class="icon-chart" aria-hidden="true"></span>
													<span class="visually-hidden"><?php echo Text::_('COM_PUNGAANALYTICS_VIEW_HISTORY'); ?></span>
												</a>
											</td>
											<td class="text-end" data-sort-value="<?php echo (int) $row->human; ?>"><?php echo $number($row->human); ?></td>
											<td class="text-end" data-sort-value="<?php echo (int) $row->bots; ?>"><?php echo $number($row->bots); ?></td>
											<td class="text-end pa-count" data-sort-value="<?php echo (int) $row->total; ?>"><?php echo $number($row->total); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				</section>
					</div>
				</div>
				<?php echo HTMLHelper::_('uitab.endTab'); ?>

				<?php if ($rankingDefinitions !== []) : ?>
					<?php echo HTMLHelper::_('uitab.addTab', 'pungaAnalyticsDashboardTabs', $dashboardViewIds['engagement'], Text::_('COM_PUNGAANALYTICS_ENGAGEMENT')); ?>
					<div class="pa-dashboard-tab">
						<div class="pa-grid pa-grid--content">
							<?php foreach ($rankingDefinitions as $definition) :
								$tableKey = (string) $definition['table_key'];
								$rankingRows = $eventRankingRows[$tableKey] ?? [];
							?>
								<section class="pa-panel">
									<header class="pa-panel__header">
										<h3><?php echo $escape($definition['ranking_title']); ?></h3>
										<a class="pa-view-all" href="<?php echo $reportUrl('event', (string) $definition['event_type']); ?>"><?php echo Text::_('COM_PUNGAANALYTICS_VIEW_ALL'); ?></a>
									</header>
									<div class="pa-panel__body pa-panel__body--flush">
										<?php if ($rankingRows === []) : ?>
											<div class="pa-empty"><?php echo Text::_('COM_PUNGAANALYTICS_NO_CUSTOM_EVENTS'); ?></div>
										<?php else : ?>
											<table class="table pa-table mb-0" id="pa-table-<?php echo $escape($tableKey); ?>">
												<thead>
													<tr>
														<?php echo $sortableHeading($tableKey, 'title', Text::_('COM_PUNGAANALYTICS_CSV_TITLE'), 'asc'); ?>
														<?php echo $sortableHeading($tableKey, 'count', Text::_('COM_PUNGAANALYTICS_CSV_COUNT'), 'desc', 'text-end'); ?>
													</tr>
												</thead>
												<tbody>
													<?php foreach ($rankingRows as $row) : ?>
														<tr>
															<td data-sort-value="<?php echo $escape($eventItemSortLabel($row)); ?>">
																<div class="pa-item-title">
																	<?php echo $eventItemLabel($row); ?>
																	<a class="pa-history-link"
																		href="<?php echo $historyUrl(
																			'event_item',
																			$eventItemSortLabel($row),
																			[
																				'history_event_type' => (string) $definition['event_type'],
																				'item_type' => (string) $row->item_type,
																				'item_id' => (string) $row->item_id,
																				'item_title' => (string) $row->item_title,
																				'path' => (string) $row->path,
																			]
																		); ?>"
																		title="<?php echo $escape(Text::_('COM_PUNGAANALYTICS_VIEW_HISTORY')); ?>">
																		<span class="icon-chart" aria-hidden="true"></span>
																		<span class="visually-hidden"><?php echo Text::_('COM_PUNGAANALYTICS_VIEW_HISTORY'); ?></span>
																	</a>
																</div>
																<div class="pa-item-meta"><?php echo $escape($row->item_type); ?><?php echo $row->item_id !== '' ? ' · ' . $escape($row->item_id) : ''; ?></div>
															</td>
															<td class="text-end pa-count" data-sort-value="<?php echo (int) $row->count; ?>"><?php echo $number($row->count); ?></td>
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
					<?php echo HTMLHelper::_('uitab.endTab'); ?>
				<?php endif; ?>

				<?php echo HTMLHelper::_('uitab.addTab', 'pungaAnalyticsDashboardTabs', $dashboardViewIds['acquisition'], Text::_('COM_PUNGAANALYTICS_ACQUISITION')); ?>
				<div class="pa-dashboard-tab">
					<div class="pa-grid">
						<?php
						$acquisitionTables = [
							[
								Text::_('COM_PUNGAANALYTICS_TRAFFIC_SOURCES'),
								$sortDashboardRows($this->data['trafficSources'], 'sources'),
								'sources',
								'source',
							],
							[
								Text::_('COM_PUNGAANALYTICS_REFERRERS'),
								$sortDashboardRows($this->data['referrers'], 'referrers'),
								'referrers',
								'referrer',
							],
						];
						foreach ($acquisitionTables as [$title, $rows, $report, $historyDimension]) :
						?>
							<section class="pa-panel pa-panel--compact">
								<header class="pa-panel__header">
									<h3><?php echo $escape($title); ?></h3>
									<a class="pa-view-all" href="<?php echo $reportUrl($report); ?>"><?php echo Text::_('COM_PUNGAANALYTICS_VIEW_ALL'); ?></a>
								</header>
								<div class="pa-panel__body pa-panel__body--flush">
									<?php if ($report === 'sources') : ?>
										<p class="pa-panel-note"><?php echo Text::_('COM_PUNGAANALYTICS_TRAFFIC_SOURCES_NOTE'); ?></p>
									<?php endif; ?>
									<?php if ($rows === []) : ?>
										<div class="pa-empty"><?php echo Text::_('COM_PUNGAANALYTICS_NO_DATA'); ?></div>
									<?php else : ?>
										<table class="table pa-table mb-0" id="pa-table-<?php echo $escape($report); ?>">
											<thead>
												<tr>
													<?php echo $sortableHeading($report, 'label', $title, 'asc'); ?>
													<?php echo $sortableHeading($report, 'count', Text::_('COM_PUNGAANALYTICS_CSV_COUNT'), 'desc', 'text-end'); ?>
												</tr>
											</thead>
											<tbody>
												<?php foreach ($rows as $row) :
													$rowLabel = $report === 'sources'
														? ($sourceLabels[(string) $row->label] ?? (string) $row->label)
														: (string) $row->label;
												?>
													<tr>
														<td class="text-break" data-sort-value="<?php echo $escape($rowLabel); ?>">
															<?php echo $escape($rowLabel); ?>
															<a class="pa-history-link"
																href="<?php echo $historyUrl($historyDimension, (string) $row->label); ?>"
																title="<?php echo $escape(Text::_('COM_PUNGAANALYTICS_VIEW_HISTORY')); ?>">
																<span class="icon-chart" aria-hidden="true"></span>
																<span class="visually-hidden"><?php echo Text::_('COM_PUNGAANALYTICS_VIEW_HISTORY'); ?></span>
															</a>
														</td>
														<td class="text-end pa-count" data-sort-value="<?php echo (int) $row->count; ?>"><?php echo $number($row->count); ?></td>
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
				<?php echo HTMLHelper::_('uitab.endTab'); ?>

				<?php echo HTMLHelper::_('uitab.addTab', 'pungaAnalyticsDashboardTabs', $dashboardViewIds['audience'], Text::_('COM_PUNGAANALYTICS_AUDIENCE_TECHNOLOGY')); ?>
				<div class="pa-dashboard-tab pa-dashboard-tab--audience">
				<?php
				$historyDimensions = [
					'countries' => 'country',
					'languages' => 'language',
					'devices' => 'device',
					'browsers' => 'browser',
					'bots' => 'bot',
					'events' => 'event',
				];
				$dimensionTables = [
					[Text::_('COM_PUNGAANALYTICS_COUNTRIES'), $this->data['countries'], 'country-pie', 'countries'],
					[Text::_('COM_PUNGAANALYTICS_LANGUAGES'), $this->data['languages'], 'pie', 'languages'],
					[Text::_('COM_PUNGAANALYTICS_DEVICES'), $this->data['devices'], 'pie', 'devices'],
					[Text::_('COM_PUNGAANALYTICS_BROWSERS'), $this->data['browsers'], 'pie', 'browsers'],
					[Text::_('COM_PUNGAANALYTICS_BOT_NAMES'), $this->data['bots'], 'plain', 'bots'],
					[Text::_('COM_PUNGAANALYTICS_CUSTOM_EVENTS'), $this->data['eventTypes'], 'plain', 'events'],
				];
				echo HTMLHelper::_('uitab.startTabSet', 'pungaAnalyticsAudienceTabs', [
					'active' => 'pa-audience-' . $activeAudienceView,
					'breakpoint' => 768,
				]);
				foreach ($dimensionTables as [$title, $rows, $mode, $report]) :
					$rows = $sortDashboardRows($rows, $report);
					$isCountry = $mode === 'country-pie';
					$isPie = \in_array($mode, ['pie', 'country-pie'], true);
					$audienceTitle = $title;
				?>
					<?php echo HTMLHelper::_('uitab.addTab', 'pungaAnalyticsAudienceTabs', 'pa-audience-' . $report, $audienceTitle); ?>
					<div class="pa-audience-tab">
					<section class="pa-panel pa-panel--compact">
						<header class="pa-panel__header">
							<h3><?php echo $escape($title); ?></h3>
							<a class="pa-view-all" href="<?php echo $reportUrl($report); ?>"><?php echo Text::_('COM_PUNGAANALYTICS_VIEW_ALL'); ?></a>
						</header>
					<div class="pa-panel__body pa-panel__body--flush">
						<?php if ($rows === []) : ?><div class="pa-empty"><?php echo Text::_('COM_PUNGAANALYTICS_NO_DATA'); ?></div><?php else : ?>
						<?php if ($isCountry && $showCountryWarning) : ?>
							<div class="alert alert-warning pa-country-warning"><?php echo Text::_('COM_PUNGAANALYTICS_COUNTRY_ONLY_UNKNOWN'); ?></div>
						<?php endif; ?>
						<?php if ($isCountry) : ?>
							<p class="pa-panel-note"><?php echo Text::_('COM_PUNGAANALYTICS_COUNTRY_NEW_EVENTS_ONLY'); ?></p>
						<?php endif; ?>
						<?php if ($isPie) :
							$pieTotal = array_sum(array_map(static fn(object $row): int => (int) $row->count, $rows));
							$pieOffset = 0.0;
						?>
							<div class="pa-pie-wrap">
								<div class="pa-pie-chart">
									<svg width="200" height="200" viewBox="0 0 120 120" role="img" aria-label="<?php echo $escape($title); ?>">
										<circle class="pa-pie-chart__track" cx="60" cy="60" r="30" pathLength="100"></circle>
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
								<ul class="pa-pie-legend">
									<?php foreach ($rows as $rowIndex => $row) :
										$piePercentage = $pieTotal > 0 ? (((int) $row->count / $pieTotal) * 100.0) : 0.0;
										$pieColor = $piePalette[$rowIndex % \count($piePalette)];
										$rowLabel = $isCountry ? $countryName((string) $row->label) : (string) $row->label;
									?>
										<li>
											<svg class="pa-pie-legend__swatch" width="11" height="11" viewBox="0 0 11 11" aria-hidden="true" focusable="false">
												<circle cx="5.5" cy="5.5" r="5" fill="<?php echo $escape($pieColor); ?>"></circle>
											</svg>
											<span><?php echo $escape($rowLabel); ?></span>
											<strong><?php echo number_format($piePercentage, 1); ?>%</strong>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>
							<table class="table pa-table mb-0" id="pa-table-<?php echo $escape($report); ?>">
								<thead>
									<tr>
										<?php echo $sortableHeading($report, 'label', $title, 'asc'); ?>
										<?php echo $sortableHeading($report, 'count', Text::_('COM_PUNGAANALYTICS_CSV_COUNT'), 'desc', 'text-end'); ?>
									</tr>
								</thead>
								<tbody>
								<?php foreach ($rows as $rowIndex => $row) : ?>
									<tr>
										<td class="text-break" data-sort-value="<?php echo $escape($isCountry ? $countryName((string) $row->label) : $row->label); ?>">
											<?php if ($isPie) : ?>
												<svg class="pa-legend-dot" width="11" height="11" viewBox="0 0 11 11" aria-hidden="true" focusable="false">
													<circle cx="5.5" cy="5.5" r="5" fill="<?php echo $escape($piePalette[$rowIndex % \count($piePalette)]); ?>"></circle>
												</svg>
											<?php endif; ?>
											<?php echo $isCountry ? $countryLabel((string) $row->label) : $escape($row->label); ?>
											<a class="pa-history-link"
												href="<?php echo $historyUrl($historyDimensions[$report], (string) $row->label); ?>"
												title="<?php echo $escape(Text::_('COM_PUNGAANALYTICS_VIEW_HISTORY')); ?>">
												<span class="icon-chart" aria-hidden="true"></span>
												<span class="visually-hidden"><?php echo Text::_('COM_PUNGAANALYTICS_VIEW_HISTORY'); ?></span>
											</a>
										</td>
										<td class="text-end pa-count" data-sort-value="<?php echo (int) $row->count; ?>"><?php echo $number($row->count); ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
					</section>
					</div>
					<?php echo HTMLHelper::_('uitab.endTab'); ?>
				<?php endforeach; ?>
				<?php echo HTMLHelper::_('uitab.endTabSet'); ?>
				</div>
				<?php echo HTMLHelper::_('uitab.endTab'); ?>

				<?php echo HTMLHelper::_('uitab.addTab', 'pungaAnalyticsDashboardTabs', $dashboardViewIds['system'], Text::_('COM_PUNGAANALYTICS_SYSTEM')); ?>
				<div class="pa-dashboard-tab">
					<section class="pa-panel pa-system">
				<header class="pa-panel__header"><h3><?php echo Text::_('COM_PUNGAANALYTICS_PRIVACY_STATUS'); ?></h3></header>
			<div class="pa-system__grid">
				<div><span><?php echo Text::_('COM_PUNGAANALYTICS_VERSION'); ?></span><strong><?php echo $escape($this->version); ?></strong></div>
				<div><span><?php echo Text::_('COM_PUNGAANALYTICS_RETENTION'); ?></span><strong><?php echo Text::sprintf('COM_PUNGAANALYTICS_DAYS_VALUE', $this->retentionDays); ?></strong></div>
				<div><span><?php echo Text::_('COM_PUNGAANALYTICS_GERMAN_VISITS'); ?></span><strong><?php echo $number($summary->german_visits ?? 0); ?></strong></div>
				<div><span><?php echo Text::_('COM_PUNGAANALYTICS_COUNTRY_DATABASE'); ?></span><strong><?php echo $this->countryStatus === [] ? Text::_('COM_PUNGAANALYTICS_NOT_INSTALLED') : $escape($this->countryStatus['release'] ?? ''); ?></strong></div>
			</div>
			<div class="pa-system__notes">
				<p><?php echo Text::_('COM_PUNGAANALYTICS_PRIVACY_NOTE'); ?></p>
				<?php if ($this->countryStatus !== []) : ?>
					<p><?php echo Text::sprintf('COM_PUNGAANALYTICS_COUNTRY_DATABASE_STATUS', $escape($this->countryStatus['updated_at'] ?? ''), (int) ($this->countryStatus['ipv4_count'] ?? 0), (int) ($this->countryStatus['ipv6_count'] ?? 0)); ?></p>
					<?php if (!(bool) ($this->countryStatus['files_ready'] ?? false)) : ?>
						<div class="alert alert-danger mb-3"><?php echo Text::_('COM_PUNGAANALYTICS_COUNTRY_DATABASE_UNREADABLE'); ?></div>
					<?php endif; ?>
				<?php else : ?>
					<div class="alert alert-warning mb-3"><?php echo Text::_('COM_PUNGAANALYTICS_COUNTRY_DATABASE_MISSING'); ?></div>
				<?php endif; ?>
				<p><?php echo Text::_('COM_PUNGAANALYTICS_COUNTRY_DATABASE_FORWARD_ONLY'); ?></p>
				<p class="small text-muted mb-0"><?php echo Text::_('COM_PUNGAANALYTICS_DBIP_ATTRIBUTION'); ?></p>
			</div>
			<div class="pa-system__actions">
				<div class="pa-system__actions-copy">
					<h4><?php echo Text::_('COM_PUNGAANALYTICS_SYSTEM_MAINTENANCE'); ?></h4>
					<p><?php echo Text::_('COM_PUNGAANALYTICS_SYSTEM_MAINTENANCE_DESC'); ?></p>
				</div>
				<div class="pa-system__action-buttons">
					<button type="button" class="btn btn-primary" data-pa-dashboard-task="dashboard.updateCountryDatabase">
						<span class="icon-refresh" aria-hidden="true"></span>
						<?php echo Text::_('COM_PUNGAANALYTICS_UPDATE_COUNTRY_DATABASE'); ?>
					</button>
					<button type="button" class="btn btn-secondary" data-pa-dashboard-task="dashboard.purgeExpired">
						<span class="icon-archive" aria-hidden="true"></span>
						<?php echo Text::_('COM_PUNGAANALYTICS_PURGE_EXPIRED'); ?>
					</button>
					<button type="button" class="btn btn-danger" data-pa-dashboard-task="dashboard.resetStats" data-pa-confirm="<?php echo $escape(Text::_('COM_PUNGAANALYTICS_RESET_CONFIRM')); ?>">
						<span class="icon-trash" aria-hidden="true"></span>
						<?php echo Text::_('COM_PUNGAANALYTICS_RESET_STATS'); ?>
					</button>
				</div>
			</div>
					</section>
				</div>
				<?php echo HTMLHelper::_('uitab.endTab'); ?>
				<?php echo HTMLHelper::_('uitab.endTabSet'); ?>
			</div>
		</div>
</form>
