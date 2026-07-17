<?php

declare(strict_types=1);

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

\defined('_JEXEC') or die;

$summary = $this->data['summary'];
$rangeOptions = [
	7 => Text::_('COM_SIMPLESTATS_RANGE_7'),
	30 => Text::_('COM_SIMPLESTATS_RANGE_30'),
	90 => Text::_('COM_SIMPLESTATS_RANGE_90'),
	365 => Text::_('COM_SIMPLESTATS_RANGE_365'),
	0 => Text::_('COM_SIMPLESTATS_RANGE_ALL'),
];
?>
<form action="<?php echo Route::_('index.php?option=com_simplestats'); ?>" method="get" id="adminForm" name="adminForm">
	<input type="hidden" name="option" value="com_simplestats">
	<?php echo HTMLHelper::_('form.token'); ?>

	<div class="simplestats-toolbar-row">
		<label for="simplestats-days"><?php echo Text::_('COM_SIMPLESTATS_RANGE'); ?></label>
		<select id="simplestats-days" name="days" class="form-select" onchange="this.form.submit()">
			<?php foreach ($rangeOptions as $value => $label) : ?>
				<option value="<?php echo (int) $value; ?>"<?php echo $this->days === $value ? ' selected' : ''; ?>>
					<?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<span class="text-muted">
			<?php echo htmlspecialchars($this->data['from'] . ' – ' . $this->data['to'], ENT_QUOTES, 'UTF-8'); ?>
		</span>
	</div>

	<div class="simplestats-cards">
		<div class="simplestats-card">
			<div class="simplestats-card__value"><?php echo number_format((int) $summary->human_visits); ?></div>
			<div class="simplestats-card__label"><?php echo Text::_('COM_SIMPLESTATS_HUMAN_VISITS'); ?></div>
		</div>
		<div class="simplestats-card">
			<div class="simplestats-card__value"><?php echo number_format((int) $summary->human_pageviews); ?></div>
			<div class="simplestats-card__label"><?php echo Text::_('COM_SIMPLESTATS_HUMAN_PAGEVIEWS'); ?></div>
		</div>
		<div class="simplestats-card">
			<div class="simplestats-card__value"><?php echo number_format((int) $summary->german_visits); ?></div>
			<div class="simplestats-card__label"><?php echo Text::_('COM_SIMPLESTATS_GERMAN_VISITS'); ?></div>
		</div>
		<div class="simplestats-card">
			<div class="simplestats-card__value"><?php echo number_format((int) $summary->german_language_pageviews); ?></div>
			<div class="simplestats-card__label"><?php echo Text::_('COM_SIMPLESTATS_GERMAN_LANGUAGE_PAGEVIEWS'); ?></div>
		</div>
		<div class="simplestats-card">
			<div class="simplestats-card__value"><?php echo number_format((int) $summary->bot_pageviews); ?></div>
			<div class="simplestats-card__label"><?php echo Text::_('COM_SIMPLESTATS_BOT_PAGEVIEWS'); ?></div>
		</div>
	</div>

	<div class="simplestats-grid">
		<section class="card">
			<div class="card-header"><h2><?php echo Text::_('COM_SIMPLESTATS_DAILY'); ?></h2></div>
			<div class="card-body p-0">
				<div class="table-responsive">
					<table class="table table-striped mb-0">
						<thead>
							<tr>
								<th><?php echo Text::_('JDATE'); ?></th>
								<th class="text-end"><?php echo Text::_('COM_SIMPLESTATS_VISITS'); ?></th>
								<th class="text-end"><?php echo Text::_('COM_SIMPLESTATS_PAGEVIEWS'); ?></th>
								<th class="text-end"><?php echo Text::_('COM_SIMPLESTATS_GERMAN'); ?></th>
								<th class="text-end"><?php echo Text::_('COM_SIMPLESTATS_BOTS'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($this->data['daily'] as $row) : ?>
								<tr>
									<td><?php echo htmlspecialchars((string) $row->visit_date, ENT_QUOTES, 'UTF-8'); ?></td>
									<td class="text-end"><?php echo number_format((int) $row->visits); ?></td>
									<td class="text-end"><?php echo number_format((int) $row->pageviews); ?></td>
									<td class="text-end"><?php echo number_format((int) $row->german_visits); ?></td>
									<td class="text-end"><?php echo number_format((int) $row->bots); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</section>

		<?php
		$tables = [
			[Text::_('COM_SIMPLESTATS_TOP_PAGES'), $this->data['topPages']],
			[Text::_('COM_SIMPLESTATS_REFERRERS'), $this->data['referrers']],
			[Text::_('COM_SIMPLESTATS_LANGUAGES'), $this->data['languages']],
			[Text::_('COM_SIMPLESTATS_DEVICES'), $this->data['devices']],
			[Text::_('COM_SIMPLESTATS_BROWSERS'), $this->data['browsers']],
			[Text::_('COM_SIMPLESTATS_BOT_NAMES'), $this->data['bots']],
		];
		foreach ($tables as [$title, $rows]) :
		?>
			<section class="card">
				<div class="card-header"><h2><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h2></div>
				<div class="card-body p-0">
					<table class="table table-striped mb-0">
						<tbody>
							<?php foreach ($rows as $row) : ?>
								<tr>
									<td class="text-break"><?php echo htmlspecialchars((string) $row->label, ENT_QUOTES, 'UTF-8'); ?></td>
									<td class="text-end"><?php echo number_format((int) $row->count); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>
		<?php endforeach; ?>
	</div>

	<section class="card mt-4">
		<div class="card-header"><h2><?php echo Text::_('COM_SIMPLESTATS_PRIVACY_STATUS'); ?></h2></div>
		<div class="card-body">
			<p><?php echo Text::sprintf('COM_SIMPLESTATS_RETENTION_STATUS', $this->retentionDays); ?></p>
			<p><?php echo Text::_('COM_SIMPLESTATS_PRIVACY_NOTE'); ?></p>
			<?php if ($this->rangeStatus !== []) : ?>
				<p>
					<?php echo Text::sprintf(
						'COM_SIMPLESTATS_RANGE_STATUS',
						htmlspecialchars((string) ($this->rangeStatus['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8'),
						(int) ($this->rangeStatus['ipv4_count'] ?? 0),
						(int) ($this->rangeStatus['ipv6_count'] ?? 0)
					); ?>
				</p>
			<?php else : ?>
				<div class="alert alert-warning"><?php echo Text::_('COM_SIMPLESTATS_RANGE_MISSING'); ?></div>
			<?php endif; ?>
			<p class="small text-muted mb-0">
				<?php echo Text::_('COM_SIMPLESTATS_IPDENY_ATTRIBUTION'); ?>
			</p>
		</div>
	</section>
</form>
