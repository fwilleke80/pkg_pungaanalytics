<?php

declare(strict_types=1);

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$number = static fn(mixed $value): string => number_format((int) $value);
$siteRoot = rtrim(Uri::root(), '/');
$rangeOptions = [
	7 => Text::_('COM_PUNGAANALYTICS_RANGE_7'),
	30 => Text::_('COM_PUNGAANALYTICS_RANGE_30'),
	90 => Text::_('COM_PUNGAANALYTICS_RANGE_90'),
	365 => Text::_('COM_PUNGAANALYTICS_RANGE_365'),
	0 => Text::_('COM_PUNGAANALYTICS_RANGE_ALL'),
];
$displayLocale = str_replace('-', '_', Factory::getApplication()->getLanguage()->getTag());
$countryName = static function (string $code) use ($displayLocale): string
{
	$code = strtoupper(trim($code));

	if ($code === '' || $code === 'ZZ')
	{
		return Text::_('COM_PUNGAANALYTICS_COUNTRY_UNKNOWN');
	}

	$name = class_exists(Locale::class)
		? (string) Locale::getDisplayRegion('-' . $code, $displayLocale)
		: '';

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
$report = (string) $this->data['report'];
$kind = (string) $this->data['kind'];
$eventType = $this->eventType;
$definitionFlag = $kind === 'trend' ? 'show_trend' : 'show_time';
$eventDefinitions = \in_array($kind, ['trend', 'hour', 'weekday'], true)
	? array_values(array_filter(
		$this->data['customEventDefinitions'] ?? [],
		static fn(array $definition): bool => (bool) ($definition[$definitionFlag] ?? false)
	))
	: [];
$eventQuery = $eventType === '' ? '' : '&event_type=' . rawurlencode($eventType);
$token = Session::getFormToken();
$exportUrl = Route::_(
	'index.php?option=com_pungaanalytics&task=report.exportCsv&report='
	. rawurlencode($report)
	. $eventQuery
	. '&days=' . $this->days
	. '&sort=' . rawurlencode($this->sort)
	. '&direction=' . rawurlencode($this->direction)
	. '&' . $token . '=1'
);
$currentSort = $this->sort;
$currentDirection = $this->direction;
$currentDays = $this->days;
$currentLimit = $this->limit;
$selectedRangeLabel = $rangeOptions[$currentDays] ?? $rangeOptions[30];
$sortHeading = static function (
	string $field,
	string $label,
	string $defaultDirection,
	string $className = ''
) use ($escape, $report, $eventQuery, $currentSort, $currentDirection, $currentDays, $currentLimit): string
{
	$active = $currentSort === $field;
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
	$url = Route::_(
		'index.php?option=com_pungaanalytics&view=report&report=' . rawurlencode($report)
		. $eventQuery
		. '&days=' . $currentDays
		. '&limit=' . $currentLimit
		. '&sort=' . rawurlencode($field)
		. '&direction=' . rawurlencode($nextDirection),
		false
	) . '#pa-report-table';

	return '<th scope="col"' . $classAttribute . $ariaAttribute
		. '><a class="pa-sort-link" href="' . $escape($url)
		. '" title="' . $escape(Text::sprintf('COM_PUNGAANALYTICS_SORT_BY', $label)) . '">'
		. $escape($label) . $indicator . '</a></th>';
};
?>
	<div class="pa-dashboard pa-report">
		<header class="pa-hero">
			<div class="pa-hero__copy">
				<h1 class="pa-hero__title"><?php echo Text::_((string) $this->data['title']); ?></h1>
				<h2 class="pa-section-heading pa-section-heading--hero"><?php echo Text::_('COM_PUNGAANALYTICS_FULL_REPORT'); ?></h2>
				<p><?php echo Text::sprintf('COM_PUNGAANALYTICS_DATE_RANGE', $escape($this->data['from']), $escape($this->data['to'])); ?></p>
			</div>
		<div class="pa-report__actions">
			<a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_pungaanalytics&days=' . $this->days); ?>">
				<span class="icon-arrow-left" aria-hidden="true"></span>
				<?php echo Text::_('COM_PUNGAANALYTICS_BACK_TO_DASHBOARD'); ?>
			</a>
			<a class="btn btn-primary" href="<?php echo $exportUrl; ?>">
				<span class="icon-download" aria-hidden="true"></span>
				<?php echo Text::_('COM_PUNGAANALYTICS_EXPORT_CSV'); ?>
			</a>
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
					<a class="<?php echo $currentDays === $value ? 'is-active' : ''; ?>"
						<?php echo $currentDays === $value ? 'aria-current="page"' : ''; ?>
						href="<?php echo Route::_(
							'index.php?option=com_pungaanalytics&view=report&report=' . rawurlencode($report)
							. $eventQuery
							. '&days=' . (int) $value
							. '&limit=' . $this->limit
							. '&sort=' . rawurlencode($this->sort)
							. '&direction=' . rawurlencode($this->direction)
						); ?>">
						<span><?php echo $escape($label); ?></span>
						<?php if ($currentDays === $value) : ?>
							<span class="icon-check" aria-hidden="true"></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>
		</details>
	</div>

	<section class="pa-panel">
			<header class="pa-panel__header">
				<h3><?php echo Text::_((string) $this->data['title']); ?></h3>
				<span class="pa-panel__meta"><?php echo Text::sprintf('COM_PUNGAANALYTICS_REPORT_ROWS', (int) $this->data['total']); ?></span>
			</header>
			<?php if ($kind === 'hour') : ?>
				<p class="pa-panel-note"><?php echo Text::_('COM_PUNGAANALYTICS_HOUR_HISTORY_NOTE'); ?></p>
			<?php endif; ?>
			<div class="table-responsive">
			<?php if ($this->data['rows'] === []) : ?>
				<div class="pa-empty"><?php echo Text::_('COM_PUNGAANALYTICS_NO_DATA'); ?></div>
			<?php elseif (\in_array($kind, ['trend', 'hour', 'weekday'], true)) : ?>
				<table class="table pa-table mb-0" id="pa-report-table">
					<thead>
						<tr>
							<?php
							$bucketSort = match ($kind)
							{
								'hour' => ['hour', Text::_('COM_PUNGAANALYTICS_HOUR')],
								'weekday' => ['weekday', Text::_('COM_PUNGAANALYTICS_WEEKDAY')],
								default => ['period', Text::_('COM_PUNGAANALYTICS_PERIOD')],
							};
							echo $sortHeading(
								$bucketSort[0],
								$bucketSort[1],
								$kind === 'trend' ? 'desc' : 'asc'
							);
							?>
							<?php echo $sortHeading('visits', Text::_('COM_PUNGAANALYTICS_VISITS'), 'desc', 'text-end'); ?>
							<?php echo $sortHeading('pageviews', Text::_('COM_PUNGAANALYTICS_PAGEVIEWS'), 'desc', 'text-end'); ?>
							<?php foreach ($eventDefinitions as $definition) : ?>
								<?php echo $sortHeading((string) $definition['key'], (string) $definition['title'], 'desc', 'text-end'); ?>
							<?php endforeach; ?>
							<?php echo $sortHeading('bots', Text::_('COM_PUNGAANALYTICS_BOTS'), 'desc', 'text-end'); ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($this->data['rows'] as $row) :
							$label = match ($kind)
							{
								'hour' => $hourLabel((int) $row->bucket),
								'weekday' => $weekdayLabels[(int) $row->bucket] ?? (string) $row->bucket,
								default => (string) $row->period_label,
							};
						?>
							<tr>
									<td class="text-nowrap"><?php echo $escape($label); ?></td>
									<td class="text-end"><?php echo $number($row->visits); ?></td>
									<td class="text-end"><?php echo $number($row->pageviews); ?></td>
									<?php foreach ($eventDefinitions as $definition) : ?>
										<td class="text-end"><?php echo $number(($row->events ?? [])[(string) $definition['event_type']] ?? 0); ?></td>
									<?php endforeach; ?>
									<td class="text-end"><?php echo $number($row->bots); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php elseif ($kind === 'items') : ?>
				<table class="table pa-table mb-0" id="pa-report-table">
					<thead>
						<tr>
							<?php echo $sortHeading('title', Text::_('COM_PUNGAANALYTICS_CSV_TITLE'), 'asc'); ?>
							<?php echo $sortHeading('item_id', Text::_('COM_PUNGAANALYTICS_CSV_ITEM_ID'), 'asc'); ?>
							<?php echo $sortHeading('item_type', Text::_('COM_PUNGAANALYTICS_CSV_ITEM_TYPE'), 'asc'); ?>
							<?php echo $sortHeading('path', Text::_('COM_PUNGAANALYTICS_CSV_PATH'), 'asc'); ?>
							<?php echo $sortHeading('count', Text::_('COM_PUNGAANALYTICS_CSV_COUNT'), 'desc', 'text-end'); ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($this->data['rows'] as $row) : ?>
							<tr>
								<td class="text-break"><?php echo $escape($row->item_title !== '' ? $row->item_title : Text::_('COM_PUNGAANALYTICS_UNKNOWN_ITEM')); ?></td>
								<td class="text-break"><?php echo $escape($row->item_id); ?></td>
								<td class="text-break"><?php echo $escape($row->item_type); ?></td>
								<td class="text-break"><?php echo $escape($row->path); ?></td>
								<td class="text-end pa-count"><?php echo $number($row->count); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<table class="table pa-table mb-0" id="pa-report-table">
					<thead>
						<tr>
							<?php echo $sortHeading(
								'label',
								$kind === 'country' ? Text::_('COM_PUNGAANALYTICS_COUNTRIES') : Text::_('COM_PUNGAANALYTICS_CSV_LABEL'),
								'asc'
							); ?>
							<?php echo $sortHeading('count', Text::_('COM_PUNGAANALYTICS_CSV_COUNT'), 'desc', 'text-end'); ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($this->data['rows'] as $row) : ?>
							<tr>
								<td class="text-break">
									<?php if ($kind === 'country') : ?>
										<?php echo $escape($countryName((string) $row->label)); ?>
										<?php $flag = $countryFlag((string) $row->label); ?>
										<?php if ($flag !== '') : ?><span class="pa-country-flag" aria-hidden="true"><?php echo $escape($flag); ?></span><?php endif; ?>
										<span class="pa-code"><?php echo $escape(strtoupper((string) $row->label)); ?></span>
									<?php elseif ($report === 'pages') : ?>
										<a href="<?php echo $escape($siteRoot . (string) $row->label); ?>" target="_blank" rel="noopener noreferrer"><?php echo $escape($row->label); ?></a>
									<?php else : ?>
										<?php echo $escape($row->label); ?>
									<?php endif; ?>
								</td>
								<td class="text-end pa-count"><?php echo $number($row->count); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php if ((int) $this->data['total'] > $this->limit) : ?>
			<footer class="pa-report__pagination">
				<?php echo $this->pagination->getPagesLinks(); ?>
			</footer>
		<?php endif; ?>
	</section>
</div>
