<?php

declare(strict_types=1);

namespace Punga\Component\PungaAnalytics\Administrator\View\Report;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Pagination\Pagination;
use Joomla\CMS\Toolbar\ToolbarHelper;

\defined('_JEXEC') or die;

/**
 * Full statistics report view.
 */
final class HtmlView extends BaseHtmlView
{
	/** @var array<string, mixed> */
	public array $data = [];

	/** @var string */
	public string $range = '7';

	/** @var int */
	public int $limit = 50;

	/** @var int */
	public int $start = 0;

	/** @var string */
	public string $sort = '';

	/** @var string */
	public string $direction = '';

	/** @var string */
	public string $eventType = '';

	/** @var array<string, string> */
	public array $history = [];

	/** @var Pagination */
	public Pagination $pagination;

	/**
	 * Displays a full report.
	 *
	 * @param string|null $tpl Layout name.
	 *
	 * @return void
	 */
	public function display($tpl = null): void
	{
		$app = Factory::getApplication();
		$allowedRanges = ['today', 'yesterday', 'last24', '7', '30', '90', '365', 'all', '0'];
		$allowedLimits = [25, 50, 100];
		$requestedRange = strtolower($app->input->getCmd('days', '7'));
		$requestedLimit = $app->input->getInt('limit', 50);
		$report = strtolower($app->input->getCmd('report', 'pages'));
		$this->eventType = strtolower($app->input->getCmd('event_type', ''));
		$this->range = \in_array($requestedRange, $allowedRanges, true) ? $requestedRange : '7';
		$this->range = $this->range === '0' ? 'all' : $this->range;
		$this->history = $this->getHistoryArguments();
		$this->limit = \in_array($requestedLimit, $allowedLimits, true) ? $requestedLimit : 50;
		$this->start = max(0, $app->input->getInt('limitstart', 0));
		$requestedSort = strtolower($app->input->getCmd('sort', ''));
		$requestedDirection = strtolower($app->input->getCmd('direction', ''));

		/** @var \Punga\Component\PungaAnalytics\Administrator\Model\ReportModel $model */
		$model = $this->getModel();

		if (!$model->isSupportedReport($report, $this->eventType, $this->history))
		{
			throw new \InvalidArgumentException(Text::_('COM_PUNGAANALYTICS_REPORT_INVALID'), 404);
		}

		$this->data = $model->getReportData(
			$report,
			$this->range,
			$this->start,
			$this->limit,
			$requestedSort,
			$requestedDirection,
			$this->eventType,
			$this->history
		);
		$this->sort = (string) $this->data['sort'];
		$this->direction = (string) $this->data['direction'];
		$this->pagination = new Pagination((int) $this->data['total'], $this->start, $this->limit);
		$this->pagination->setAdditionalUrlParam('option', 'com_pungaanalytics');
		$this->pagination->setAdditionalUrlParam('view', 'report');
		$this->pagination->setAdditionalUrlParam('report', $report);

		if ($this->eventType !== '')
		{
			$this->pagination->setAdditionalUrlParam('event_type', $this->eventType);
		}

		foreach ($this->history as $name => $value)
		{
			if ($value !== '')
			{
				$this->pagination->setAdditionalUrlParam(
					$name === 'event_type' ? 'history_event_type' : $name,
					$value
				);
			}
		}

		$this->pagination->setAdditionalUrlParam('days', $this->range);
		$this->pagination->setAdditionalUrlParam('limit', $this->limit);
		$this->pagination->setAdditionalUrlParam('sort', $this->sort);
		$this->pagination->setAdditionalUrlParam('direction', $this->direction);

		$app->getDocument()->getWebAssetManager()->registerAndUseStyle(
			'com_pungaanalytics.admin.0.8.11',
			'com_pungaanalytics/admin-0.8.11.css',
			['version' => '0.8.11']
		);

		$reportTitle = $report === 'history'
			? Text::sprintf(
				'COM_PUNGAANALYTICS_HISTORY_TITLE',
				(string) (($this->data['history'] ?? [])['value'] ?? '')
			)
			: Text::_((string) $this->data['title']);
		ToolbarHelper::title(
			Text::sprintf('COM_PUNGAANALYTICS_FULL_REPORT_TITLE', $reportTitle),
			'chart'
		);
		parent::display($tpl);
	}

	/**
	 * Returns the history arguments accepted by the reporting service.
	 *
	 * @return array<string, string>
	 */
	private function getHistoryArguments(): array
	{
		$input = Factory::getApplication()->input;

		return [
			'dimension' => strtolower($input->getCmd('dimension', '')),
			'value' => $input->getString('value', ''),
			'event_type' => strtolower($input->getCmd('history_event_type', '')),
			'item_type' => $input->getString('item_type', ''),
			'item_id' => $input->getString('item_id', ''),
			'item_title' => $input->getString('item_title', ''),
			'path' => $input->getString('path', ''),
		];
	}
}
