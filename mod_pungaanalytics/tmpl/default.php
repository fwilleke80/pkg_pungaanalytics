<?php

declare(strict_types=1);

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

if ($statistics === [])
{
	return;
}

Factory::getApplication()->getDocument()->getWebAssetManager()->registerAndUseStyle(
	'mod_pungaanalytics.admin',
	'mod_pungaanalytics/admin.css',
	['version' => '0.8.1']
);

$summary = $statistics['summary'];
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$number = static fn(mixed $value): string => number_format((int) $value);
$range = (string) $statistics['range'];
$dashboardUrl = Route::_('index.php?option=com_pungaanalytics&days=' . rawurlencode($range));
$siteRoot = rtrim(Uri::root(), '/');
$rangeLabels = [
	'today' => 'MOD_PUNGAANALYTICS_RANGE_TODAY',
	'yesterday' => 'MOD_PUNGAANALYTICS_RANGE_YESTERDAY',
	'last24' => 'MOD_PUNGAANALYTICS_RANGE_LAST24',
	'7' => 'MOD_PUNGAANALYTICS_RANGE_7',
	'30' => 'MOD_PUNGAANALYTICS_RANGE_30',
	'90' => 'MOD_PUNGAANALYTICS_RANGE_90',
	'365' => 'MOD_PUNGAANALYTICS_RANGE_365',
	'all' => 'MOD_PUNGAANALYTICS_RANGE_ALL',
];
$metrics = [
	[
		'value' => (int) ($summary->human_visits ?? 0),
		'label' => Text::_('MOD_PUNGAANALYTICS_VISITORS'),
		'icon' => 'icon-users',
	],
	[
		'value' => (int) ($summary->human_pageviews ?? 0),
		'label' => Text::_('MOD_PUNGAANALYTICS_PAGEVIEWS'),
		'icon' => 'icon-eye',
	],
];

foreach ($statistics['moduleEventDefinitions'] as $definition)
{
	$eventType = (string) $definition['event_type'];
	$metrics[] = [
		'value' => (int) (($summary->events ?? [])[$eventType] ?? 0),
		'label' => (string) $definition['title'],
		'icon' => (string) $definition['icon'],
	];
}

if ((bool) $params->get('show_bots', 1))
{
	$metrics[] = [
		'value' => (int) ($summary->bot_pageviews ?? 0),
		'label' => Text::_('MOD_PUNGAANALYTICS_BOTS'),
		'icon' => 'icon-cogs',
	];
}
?>
<div class="pa-admin-module">
	<div class="pa-admin-module__range">
		<span class="icon-calendar" aria-hidden="true"></span>
		<?php echo Text::_($rangeLabels[$range] ?? $rangeLabels['7']); ?>
		<span aria-hidden="true">·</span>
		<?php echo Text::sprintf(
			'MOD_PUNGAANALYTICS_DATE_RANGE',
			$escape($statistics['displayFrom']),
			$escape($statistics['displayTo'])
		); ?>
	</div>

	<div class="pa-admin-module__metrics">
		<?php foreach ($metrics as $metric) : ?>
			<div class="pa-admin-module__metric">
				<span class="<?php echo $escape($metric['icon']); ?>" aria-hidden="true"></span>
				<div>
					<strong><?php echo $number($metric['value']); ?></strong>
					<span><?php echo $escape($metric['label']); ?></span>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ((bool) $params->get('show_top_pages', 1)) : ?>
		<div class="pa-admin-module__pages">
			<h3><?php echo Text::_('MOD_PUNGAANALYTICS_TOP_PAGES'); ?></h3>
			<?php if ($statistics['topPages'] === []) : ?>
				<p class="pa-admin-module__empty">
					<?php echo Text::_('MOD_PUNGAANALYTICS_NO_DATA'); ?>
				</p>
			<?php else : ?>
				<ol>
					<?php foreach ($statistics['topPages'] as $row) : ?>
						<?php
						$pageLabel = (string) ($row->label ?? '');
						$pageUrl = $siteRoot . '/' . ltrim($pageLabel, '/');
						?>
						<li>
							<a href="<?php echo $escape($pageUrl); ?>"
								title="<?php echo $escape($pageLabel); ?>"
								target="_blank"
								rel="noopener noreferrer">
								<?php echo $escape($pageLabel); ?>
							</a>
							<strong><?php echo $number($row->count ?? 0); ?></strong>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<a class="btn btn-sm btn-primary" href="<?php echo $dashboardUrl; ?>">
		<span class="icon-chart" aria-hidden="true"></span>
		<?php echo Text::_('MOD_PUNGAANALYTICS_OPEN_DASHBOARD'); ?>
	</a>
</div>
