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
$countryLabel = static function (string $code) use ($escape, $displayLocale): string
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

	return $name !== '' && $name !== $code ? $escape($name) . ' <span class="ss-code">' . $escape($code) . '</span>' : $escape($code);
};
$eventItemLabel = static function (object $row) use ($escape): string
{
	$title = trim((string) ($row->item_title ?? ''));
	$id = trim((string) ($row->item_id ?? ''));
	$path = trim((string) ($row->path ?? ''));

	if ($title !== '')
	{
		return $escape($title);
	}

	if ($id !== '')
	{
		return $escape($id);
	}

	if ($path !== '')
	{
		return $escape($path);
	}

	return Text::_('COM_SIMPLESTATS_UNKNOWN_ITEM');
};
$summaryMetrics = [
	['human_visits', 'COM_SIMPLESTATS_HUMAN_VISITS', 'icon-users'],
	['human_pageviews', 'COM_SIMPLESTATS_HUMAN_PAGEVIEWS', 'icon-eye'],
	['plays', 'COM_SIMPLESTATS_AUDIO_PLAYS', 'icon-play'],
	['downloads', 'COM_SIMPLESTATS_AUDIO_DOWNLOADS', 'icon-download'],
	['authenticated_pageviews', 'COM_SIMPLESTATS_AUTHENTICATED_PAGEVIEWS', 'icon-user'],
	['bot_pageviews', 'COM_SIMPLESTATS_BOT_PAGEVIEWS', 'icon-cogs'],
];
$chartSeries = [
	['visits', Text::_('COM_SIMPLESTATS_VISITS'), '#6f42c1'],
	['pageviews', Text::_('COM_SIMPLESTATS_PAGEVIEWS'), '#2a69b8'],
	['plays', Text::_('COM_SIMPLESTATS_AUDIO_PLAYS'), '#198754'],
	['downloads', Text::_('COM_SIMPLESTATS_AUDIO_DOWNLOADS'), '#d99000'],
	['bots', Text::_('COM_SIMPLESTATS_BOTS'), '#c94b54'],
];
$piePalette = ['#2a69b8', '#6f42c1', '#198754', '#d99000', '#c94b54', '#0f8b8d', '#dd6e42', '#607d8b', '#8e6c88', '#6c8e3f', '#b36b00', '#5c6bc0'];
$dailyRows = array_reverse($this->data['daily']);
$maxDailyValue = 1;

foreach ($dailyRows as $row)
{
	foreach ($chartSeries as [$property])
	{
		$maxDailyValue = max($maxDailyValue, (int) ($row->{$property} ?? 0));
	}
}

$dailyChartWidth = 1200;
$dailyChartHeight = 240;
$dailyPlotLeft = 54.0;
$dailyPlotRight = 16.0;
$dailyPlotTop = 12.0;
$dailyPlotHeight = 190.0;
$dailyGroupWidth = ($dailyChartWidth - $dailyPlotLeft - $dailyPlotRight) / max(1, \count($dailyRows));
$dailyBarGap = 0.8;
$dailyBarWidth = max(2.8, min(7.0, (($dailyGroupWidth - 4.0) / \count($chartSeries)) - $dailyBarGap));
$dailyBarsWidth = (\count($chartSeries) * $dailyBarWidth) + ((\count($chartSeries) - 1) * $dailyBarGap);
$dailyLabelStep = max(1, (int) ceil(\count($dailyRows) / 8));
$knownCountryRows = array_filter(
	$this->data['countries'],
	static fn(object $row): bool => !\in_array(strtoupper(trim((string) $row->label)), ['', 'ZZ'], true)
);
$showCountryWarning = $this->countryStatus !== []
	&& $this->data['countries'] !== []
	&& $knownCountryRows === [];
?>
<form action="<?php echo Route::_('index.php?option=com_simplestats'); ?>" method="post" id="adminForm" name="adminForm">
	<input type="hidden" name="option" value="com_simplestats">
	<input type="hidden" name="task" value="">
	<?php echo HTMLHelper::_('form.token'); ?>

	<div class="ss-dashboard">
		<header class="ss-hero">
			<div>
				<div class="ss-eyebrow"><?php echo Text::_('COM_SIMPLESTATS_DASHBOARD_EYEBROW'); ?></div>
				<h2><?php echo Text::_('COM_SIMPLESTATS_DASHBOARD_TITLE'); ?></h2>
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

		<section class="ss-panel ss-summary" aria-label="<?php echo Text::_('COM_SIMPLESTATS_OVERVIEW'); ?>">
			<div class="table-responsive">
				<table class="table mb-0">
					<thead>
						<tr>
						<?php foreach ($summaryMetrics as [, $label, $icon]) : ?>
							<th><span class="<?php echo $escape($icon); ?>" aria-hidden="true"></span><?php echo Text::_($label); ?></th>
						<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<tr>
						<?php foreach ($summaryMetrics as [$property]) : ?>
							<td><?php echo $number($summary->{$property} ?? 0); ?></td>
						<?php endforeach; ?>
						</tr>
					</tbody>
				</table>
			</div>
		</section>

		<section class="ss-panel ss-panel--wide">
			<header class="ss-panel__header">
				<div><span class="ss-panel__kicker"><?php echo Text::_('COM_SIMPLESTATS_TRAFFIC'); ?></span><h3><?php echo Text::_('COM_SIMPLESTATS_RECENT_DAILY'); ?></h3></div>
				<span class="ss-panel__meta"><?php echo Text::_('COM_SIMPLESTATS_RECENT_DAILY_LIMIT'); ?></span>
			</header>
			<div class="ss-panel__body ss-panel__body--flush">
				<?php if ($dailyRows === []) : ?>
					<div class="ss-empty"><?php echo Text::_('COM_SIMPLESTATS_NO_DATA'); ?></div>
				<?php else : ?>
					<div class="ss-daily-chart">
						<div class="ss-chart-legend" aria-hidden="true">
							<?php foreach ($chartSeries as $seriesIndex => [, $label]) : ?>
								<span class="ss-series-<?php echo $seriesIndex; ?>"><i></i><?php echo $escape($label); ?></span>
							<?php endforeach; ?>
						</div>
						<div class="ss-chart-scroll">
							<svg class="ss-daily-chart__svg"
								viewBox="0 0 <?php echo $dailyChartWidth; ?> <?php echo $dailyChartHeight; ?>"
								preserveAspectRatio="xMidYMid meet"
								role="img"
								aria-label="<?php echo Text::_('COM_SIMPLESTATS_DAILY_CHART_LABEL'); ?>">
								<?php for ($line = 0; $line <= 4; $line++) :
									$lineY = $dailyPlotTop + ($line * ($dailyPlotHeight / 4.0));
									$lineValue = (int) round($maxDailyValue * ((4 - $line) / 4));
								?>
									<line class="ss-chart-gridline"
										x1="<?php echo $dailyPlotLeft; ?>"
										y1="<?php echo number_format($lineY, 1, '.', ''); ?>"
										x2="<?php echo $dailyChartWidth - $dailyPlotRight; ?>"
										y2="<?php echo number_format($lineY, 1, '.', ''); ?>"></line>
									<text class="ss-chart-axis-label"
										x="<?php echo $dailyPlotLeft - 8; ?>"
										y="<?php echo number_format($lineY + 4, 1, '.', ''); ?>"
										text-anchor="end"><?php echo $lineValue; ?></text>
								<?php endfor; ?>
								<?php foreach ($dailyRows as $rowIndex => $row) :
									$groupStart = $dailyPlotLeft + ($rowIndex * $dailyGroupWidth);
									$groupX = $groupStart + (($dailyGroupWidth - $dailyBarsWidth) / 2.0);
								?>
									<?php foreach ($chartSeries as $seriesIndex => [$property, $label, $color]) :
										$value = (int) ($row->{$property} ?? 0);
										$height = $value > 0 ? max(1.0, ($value / $maxDailyValue) * $dailyPlotHeight) : 0.0;
										$x = $groupX + ($seriesIndex * ($dailyBarWidth + $dailyBarGap));
										$y = $dailyPlotTop + $dailyPlotHeight - $height;
									?>
										<rect x="<?php echo number_format($x, 1, '.', ''); ?>"
											y="<?php echo number_format($y, 1, '.', ''); ?>"
											width="<?php echo number_format($dailyBarWidth, 1, '.', ''); ?>"
											height="<?php echo number_format($height, 1, '.', ''); ?>"
											rx="1.2"
											fill="<?php echo $escape($color); ?>">
											<title><?php echo $escape($row->visit_date . ' · ' . $label . ': ' . $number($value)); ?></title>
										</rect>
									<?php endforeach; ?>
									<?php if ($rowIndex % $dailyLabelStep === 0 || $rowIndex === \count($dailyRows) - 1) : ?>
										<text class="ss-chart-date-label"
											x="<?php echo number_format($groupStart + ($dailyGroupWidth / 2.0), 1, '.', ''); ?>"
											y="<?php echo $dailyPlotTop + $dailyPlotHeight + 23; ?>"
											text-anchor="middle"><?php echo $escape(substr((string) $row->visit_date, 5)); ?></text>
									<?php endif; ?>
								<?php endforeach; ?>
							</svg>
						</div>
					</div>
					<div class="table-responsive">
						<table class="table ss-table mb-0">
							<thead><tr>
								<th><?php echo Text::_('JDATE'); ?></th>
								<th class="text-end"><?php echo Text::_('COM_SIMPLESTATS_VISITS'); ?></th>
								<th class="text-end"><?php echo Text::_('COM_SIMPLESTATS_PAGEVIEWS'); ?></th>
								<th class="text-end"><?php echo Text::_('COM_SIMPLESTATS_AUDIO_PLAYS'); ?></th>
								<th class="text-end"><?php echo Text::_('COM_SIMPLESTATS_AUDIO_DOWNLOADS'); ?></th>
								<th class="text-end"><?php echo Text::_('COM_SIMPLESTATS_BOTS'); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ($dailyRows as $row) : ?>
								<tr>
									<td class="text-nowrap"><?php echo $escape($row->visit_date); ?></td>
									<td class="text-end"><?php echo $number($row->visits); ?></td>
									<td class="text-end"><?php echo $number($row->pageviews); ?></td>
									<td class="text-end"><?php echo $number($row->plays); ?></td>
									<td class="text-end"><?php echo $number($row->downloads); ?></td>
									<td class="text-end"><?php echo $number($row->bots); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</section>

		<div class="ss-grid ss-grid--content">
			<section class="ss-panel">
				<header class="ss-panel__header"><div><span class="ss-panel__kicker"><?php echo Text::_('COM_SIMPLESTATS_CONTENT'); ?></span><h3><?php echo Text::_('COM_SIMPLESTATS_TOP_PAGES'); ?></h3></div></header>
				<div class="ss-panel__body ss-panel__body--flush">
					<?php if ($this->data['topPages'] === []) : ?><div class="ss-empty"><?php echo Text::_('COM_SIMPLESTATS_NO_DATA'); ?></div><?php else : ?>
					<table class="table ss-table mb-0"><tbody>
					<?php foreach ($this->data['topPages'] as $row) : ?>
						<tr><td class="text-break"><a href="<?php echo $escape($siteRoot . (string) $row->label); ?>" target="_blank" rel="noopener noreferrer"><?php echo $escape($row->label); ?></a></td><td class="text-end ss-count"><?php echo $number($row->count); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
					<?php endif; ?>
				</div>
			</section>

			<section class="ss-panel">
				<header class="ss-panel__header"><div><span class="ss-panel__kicker"><?php echo Text::_('COM_SIMPLESTATS_ENGAGEMENT'); ?></span><h3><?php echo Text::_('COM_SIMPLESTATS_TOP_PLAYS'); ?></h3></div></header>
				<div class="ss-panel__body ss-panel__body--flush">
					<?php if ($this->data['topPlays'] === []) : ?><div class="ss-empty"><?php echo Text::_('COM_SIMPLESTATS_NO_CUSTOM_EVENTS'); ?></div><?php else : ?>
					<table class="table ss-table mb-0"><tbody>
					<?php foreach ($this->data['topPlays'] as $row) : ?>
						<tr><td><div class="ss-item-title"><?php echo $eventItemLabel($row); ?></div><div class="ss-item-meta"><?php echo $escape($row->item_type); ?><?php echo $row->item_id !== '' ? ' · ' . $escape($row->item_id) : ''; ?></div></td><td class="text-end ss-count"><?php echo $number($row->count); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
					<?php endif; ?>
				</div>
			</section>

			<section class="ss-panel">
				<header class="ss-panel__header"><div><span class="ss-panel__kicker"><?php echo Text::_('COM_SIMPLESTATS_ENGAGEMENT'); ?></span><h3><?php echo Text::_('COM_SIMPLESTATS_TOP_DOWNLOADS'); ?></h3></div></header>
				<div class="ss-panel__body ss-panel__body--flush">
					<?php if ($this->data['topDownloads'] === []) : ?><div class="ss-empty"><?php echo Text::_('COM_SIMPLESTATS_NO_CUSTOM_EVENTS'); ?></div><?php else : ?>
					<table class="table ss-table mb-0"><tbody>
					<?php foreach ($this->data['topDownloads'] as $row) : ?>
						<tr><td><div class="ss-item-title"><?php echo $eventItemLabel($row); ?></div><div class="ss-item-meta"><?php echo $escape($row->item_type); ?><?php echo $row->item_id !== '' ? ' · ' . $escape($row->item_id) : ''; ?></div></td><td class="text-end ss-count"><?php echo $number($row->count); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
					<?php endif; ?>
				</div>
			</section>
		</div>

		<div class="ss-grid">
			<?php
			$dimensionTables = [
				[Text::_('COM_SIMPLESTATS_COUNTRIES'), $this->data['countries'], 'country'],
				[Text::_('COM_SIMPLESTATS_REFERRERS'), $this->data['referrers'], 'plain'],
				[Text::_('COM_SIMPLESTATS_LANGUAGES'), $this->data['languages'], 'pie'],
				[Text::_('COM_SIMPLESTATS_DEVICES'), $this->data['devices'], 'pie'],
				[Text::_('COM_SIMPLESTATS_BROWSERS'), $this->data['browsers'], 'pie'],
				[Text::_('COM_SIMPLESTATS_BOT_NAMES'), $this->data['bots'], 'plain'],
				[Text::_('COM_SIMPLESTATS_CUSTOM_EVENTS'), $this->data['eventTypes'], 'plain'],
			];
			foreach ($dimensionTables as [$title, $rows, $mode]) :
			?>
				<section class="ss-panel ss-panel--compact">
					<header class="ss-panel__header"><h3><?php echo $escape($title); ?></h3></header>
					<div class="ss-panel__body ss-panel__body--flush">
						<?php if ($rows === []) : ?><div class="ss-empty"><?php echo Text::_('COM_SIMPLESTATS_NO_DATA'); ?></div><?php else : ?>
						<?php if ($mode === 'country' && $showCountryWarning) : ?>
							<div class="alert alert-warning ss-country-warning"><?php echo Text::_('COM_SIMPLESTATS_COUNTRY_ONLY_UNKNOWN'); ?></div>
						<?php endif; ?>
						<?php if ($mode === 'country') : ?>
							<p class="ss-panel-note"><?php echo Text::_('COM_SIMPLESTATS_COUNTRY_NEW_EVENTS_ONLY'); ?></p>
						<?php endif; ?>
						<?php if ($mode === 'pie') :
							$pieTotal = array_sum(array_map(static fn(object $row): int => (int) $row->count, $rows));
							$pieOffset = 0.0;
						?>
							<div class="ss-pie-wrap">
								<div class="ss-donut">
									<svg viewBox="0 0 120 120" role="img" aria-label="<?php echo $escape($title); ?>">
										<circle class="ss-donut__track" cx="60" cy="60" r="46" pathLength="100"></circle>
										<?php foreach ($rows as $rowIndex => $row) :
											$piePercentage = $pieTotal > 0 ? (((int) $row->count / $pieTotal) * 100.0) : 0.0;
											$pieRemainder = max(0.0, 100.0 - $piePercentage);
										?>
											<circle cx="60"
												cy="60"
												r="46"
												pathLength="100"
												fill="none"
												stroke="<?php echo $escape($piePalette[$rowIndex % \count($piePalette)]); ?>"
												stroke-width="20"
												stroke-dasharray="<?php echo number_format($piePercentage, 4, '.', ''); ?> <?php echo number_format($pieRemainder, 4, '.', ''); ?>"
												stroke-dashoffset="<?php echo number_format(-$pieOffset, 4, '.', ''); ?>"
												transform="rotate(-90 60 60)">
												<title><?php echo $escape($row->label . ': ' . $number($row->count)); ?></title>
											</circle>
										<?php
											$pieOffset += $piePercentage;
										endforeach;
										?>
									</svg>
									<div><strong><?php echo $number($pieTotal); ?></strong><span><?php echo Text::_('COM_SIMPLESTATS_TOTAL'); ?></span></div>
								</div>
							</div>
						<?php endif; ?>
						<table class="table ss-table mb-0"><tbody>
						<?php foreach ($rows as $rowIndex => $row) : ?>
							<tr>
								<td class="text-break">
									<?php if ($mode === 'pie') : ?><i class="ss-legend-dot ss-pie-color-<?php echo $rowIndex % \count($piePalette); ?>"></i><?php endif; ?>
									<?php echo $mode === 'country' ? $countryLabel((string) $row->label) : $escape($row->label); ?>
								</td>
								<td class="text-end ss-count"><?php echo $number($row->count); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody></table>
						<?php endif; ?>
					</div>
				</section>
			<?php endforeach; ?>
		</div>

		<section class="ss-panel ss-system">
			<header class="ss-panel__header"><div><span class="ss-panel__kicker"><?php echo Text::_('COM_SIMPLESTATS_SYSTEM'); ?></span><h3><?php echo Text::_('COM_SIMPLESTATS_PRIVACY_STATUS'); ?></h3></div></header>
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
</form>
