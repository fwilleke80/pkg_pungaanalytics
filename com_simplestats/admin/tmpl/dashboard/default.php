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
$dailyRows = array_reverse($this->data['daily']);
$maxDailyPageviews = 1;

foreach ($dailyRows as $row)
{
	$maxDailyPageviews = max($maxDailyPageviews, (int) $row->pageviews);
}
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

		<section class="ss-metrics" aria-label="<?php echo Text::_('COM_SIMPLESTATS_OVERVIEW'); ?>">
			<article class="ss-metric">
				<span class="icon-users" aria-hidden="true"></span>
				<div><strong><?php echo $number($summary->human_visits ?? 0); ?></strong><span><?php echo Text::_('COM_SIMPLESTATS_HUMAN_VISITS'); ?></span></div>
			</article>
			<article class="ss-metric">
				<span class="icon-eye" aria-hidden="true"></span>
				<div><strong><?php echo $number($summary->human_pageviews ?? 0); ?></strong><span><?php echo Text::_('COM_SIMPLESTATS_HUMAN_PAGEVIEWS'); ?></span></div>
			</article>
			<article class="ss-metric">
				<span class="icon-play" aria-hidden="true"></span>
				<div><strong><?php echo $number($summary->plays ?? 0); ?></strong><span><?php echo Text::_('COM_SIMPLESTATS_AUDIO_PLAYS'); ?></span></div>
			</article>
			<article class="ss-metric">
				<span class="icon-download" aria-hidden="true"></span>
				<div><strong><?php echo $number($summary->downloads ?? 0); ?></strong><span><?php echo Text::_('COM_SIMPLESTATS_AUDIO_DOWNLOADS'); ?></span></div>
			</article>
			<article class="ss-metric">
				<span class="icon-user" aria-hidden="true"></span>
				<div><strong><?php echo $number($summary->authenticated_pageviews ?? 0); ?></strong><span><?php echo Text::_('COM_SIMPLESTATS_AUTHENTICATED_PAGEVIEWS'); ?></span></div>
			</article>
			<article class="ss-metric">
				<span class="icon-cogs" aria-hidden="true"></span>
				<div><strong><?php echo $number($summary->bot_pageviews ?? 0); ?></strong><span><?php echo Text::_('COM_SIMPLESTATS_BOT_PAGEVIEWS'); ?></span></div>
			</article>
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
					<div class="table-responsive">
						<table class="table ss-table mb-0">
							<thead><tr>
								<th><?php echo Text::_('JDATE'); ?></th>
								<th><?php echo Text::_('COM_SIMPLESTATS_ACTIVITY'); ?></th>
								<th class="text-end"><?php echo Text::_('COM_SIMPLESTATS_VISITS'); ?></th>
								<th class="text-end"><?php echo Text::_('COM_SIMPLESTATS_PAGEVIEWS'); ?></th>
								<th class="text-end"><?php echo Text::_('COM_SIMPLESTATS_AUDIO_PLAYS'); ?></th>
								<th class="text-end"><?php echo Text::_('COM_SIMPLESTATS_AUDIO_DOWNLOADS'); ?></th>
								<th class="text-end"><?php echo Text::_('COM_SIMPLESTATS_BOTS'); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ($dailyRows as $row) :
								$width = max(2, min(100, (int) round(((int) $row->pageviews / $maxDailyPageviews) * 100)));
							?>
								<tr>
									<td class="text-nowrap"><?php echo $escape($row->visit_date); ?></td>
									<td class="ss-activity-cell"><span class="ss-bar"><span style="width: <?php echo $width; ?>%"></span></span></td>
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
				[Text::_('COM_SIMPLESTATS_LANGUAGES'), $this->data['languages'], 'plain'],
				[Text::_('COM_SIMPLESTATS_DEVICES'), $this->data['devices'], 'plain'],
				[Text::_('COM_SIMPLESTATS_BROWSERS'), $this->data['browsers'], 'plain'],
				[Text::_('COM_SIMPLESTATS_BOT_NAMES'), $this->data['bots'], 'plain'],
				[Text::_('COM_SIMPLESTATS_CUSTOM_EVENTS'), $this->data['eventTypes'], 'plain'],
			];
			foreach ($dimensionTables as [$title, $rows, $mode]) :
			?>
				<section class="ss-panel ss-panel--compact">
					<header class="ss-panel__header"><h3><?php echo $escape($title); ?></h3></header>
					<div class="ss-panel__body ss-panel__body--flush">
						<?php if ($rows === []) : ?><div class="ss-empty"><?php echo Text::_('COM_SIMPLESTATS_NO_DATA'); ?></div><?php else : ?>
						<table class="table ss-table mb-0"><tbody>
						<?php foreach ($rows as $row) : ?>
							<tr><td class="text-break"><?php echo $mode === 'country' ? $countryLabel((string) $row->label) : $escape($row->label); ?></td><td class="text-end ss-count"><?php echo $number($row->count); ?></td></tr>
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
				<?php else : ?>
					<div class="alert alert-warning mb-3"><?php echo Text::_('COM_SIMPLESTATS_COUNTRY_DATABASE_MISSING'); ?></div>
				<?php endif; ?>
				<p class="small text-muted mb-0"><?php echo Text::_('COM_SIMPLESTATS_DBIP_ATTRIBUTION'); ?></p>
			</div>
		</section>
	</div>
</form>
