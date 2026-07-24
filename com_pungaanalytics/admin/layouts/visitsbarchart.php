<?php

declare(strict_types=1);

use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

$rows = array_values($displayData['rows'] ?? []);
$labels = $displayData['labels'] ?? [];
$tickLabels = $displayData['tickLabels'] ?? [];
$ariaLabel = (string) ($displayData['ariaLabel'] ?? '');
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$number = static fn(mixed $value): string => number_format((int) $value);
$chartWidth = 1200;
$chartHeight = 240;
$plotLeft = 62.0;
$plotRight = 62.0;
$plotTop = 12.0;
$plotHeight = 190.0;
$groupWidth = ($chartWidth - $plotLeft - $plotRight) / max(1, \count($rows));
$barWidth = max(0.5, min(7.0, $groupWidth - 2.0));
$maxVisits = 1;

foreach ($rows as $row)
{
	$maxVisits = max($maxVisits, (int) ($row->visits ?? 0));
}
?>
<div class="pa-daily-chart">
	<div class="pa-chart-legend" aria-hidden="true">
		<span class="pa-series-visits">
			<svg class="pa-chart-legend__swatch" width="11" height="11" viewBox="0 0 11 11" focusable="false">
				<circle cx="5.5" cy="5.5" r="5" fill="#6f42c1"></circle>
			</svg>
			<?php echo Text::_('COM_PUNGAANALYTICS_VISITS'); ?>
		</span>
	</div>
	<div class="pa-chart-scroll">
		<svg class="pa-daily-chart__svg"
			viewBox="0 0 <?php echo $chartWidth; ?> <?php echo $chartHeight; ?>"
			preserveAspectRatio="xMidYMid meet"
			role="img"
			aria-label="<?php echo $escape($ariaLabel); ?>">
			<?php for ($line = 0; $line <= 4; $line++) :
				$lineY = $plotTop + ($line * ($plotHeight / 4.0));
				$lineValue = (int) round($maxVisits * ((4 - $line) / 4));
			?>
				<line class="pa-chart-gridline"
					x1="<?php echo $plotLeft; ?>"
					y1="<?php echo number_format($lineY, 1, '.', ''); ?>"
					x2="<?php echo $chartWidth - $plotRight; ?>"
					y2="<?php echo number_format($lineY, 1, '.', ''); ?>"></line>
				<text class="pa-chart-axis-label"
					x="<?php echo $plotLeft - 8; ?>"
					y="<?php echo number_format($lineY + 4, 1, '.', ''); ?>"
					text-anchor="end"><?php echo $lineValue; ?></text>
			<?php endfor; ?>

			<?php foreach ($rows as $index => $row) :
				$bucket = (int) ($row->bucket ?? $index);
				$value = (int) ($row->visits ?? 0);
				$height = $value > 0 ? max(1.0, ($value / $maxVisits) * $plotHeight) : 0.0;
				$groupCenter = $plotLeft + (($index + 0.5) * $groupWidth);
				$x = $groupCenter - ($barWidth / 2.0);
				$y = $plotTop + $plotHeight - $height;
				$label = (string) ($labels[$bucket] ?? $bucket);
				$tickLabel = (string) ($tickLabels[$bucket] ?? '');
			?>
				<rect x="<?php echo number_format($x, 1, '.', ''); ?>"
					y="<?php echo number_format($y, 1, '.', ''); ?>"
					width="<?php echo number_format($barWidth, 1, '.', ''); ?>"
					height="<?php echo number_format($height, 1, '.', ''); ?>"
					rx="1.2"
					fill="#6f42c1">
					<title><?php echo $escape($label . ' · ' . Text::_('COM_PUNGAANALYTICS_VISITS') . ': ' . $number($value)); ?></title>
				</rect>
				<?php if ($tickLabel !== '') : ?>
					<line class="pa-chart-date-tick"
						x1="<?php echo number_format($groupCenter, 1, '.', ''); ?>"
						y1="<?php echo $plotTop + $plotHeight + 3; ?>"
						x2="<?php echo number_format($groupCenter, 1, '.', ''); ?>"
						y2="<?php echo $plotTop + $plotHeight + 8; ?>"></line>
					<text class="pa-chart-date-label"
						x="<?php echo number_format($groupCenter, 1, '.', ''); ?>"
						y="<?php echo $plotTop + $plotHeight + 25; ?>"
						text-anchor="middle"><?php echo $escape($tickLabel); ?></text>
				<?php endif; ?>
			<?php endforeach; ?>
		</svg>
	</div>
</div>
