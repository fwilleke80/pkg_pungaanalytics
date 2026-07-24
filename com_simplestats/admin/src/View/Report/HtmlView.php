<?php

declare(strict_types=1);

namespace Willeke\Component\Simplestats\Administrator\View\Report;

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

	/** @var int */
	public int $days = 30;

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
		$allowedDays = [7, 30, 90, 365, 0];
		$allowedLimits = [25, 50, 100];
		$requestedDays = $app->input->getInt('days', 30);
		$requestedLimit = $app->input->getInt('limit', 50);
		$report = strtolower($app->input->getCmd('report', 'pages'));
		$this->eventType = strtolower($app->input->getCmd('event_type', ''));
		$this->days = \in_array($requestedDays, $allowedDays, true) ? $requestedDays : 30;
		$this->limit = \in_array($requestedLimit, $allowedLimits, true) ? $requestedLimit : 50;
		$this->start = max(0, $app->input->getInt('limitstart', 0));
		$requestedSort = strtolower($app->input->getCmd('sort', ''));
		$requestedDirection = strtolower($app->input->getCmd('direction', ''));

		/** @var \Willeke\Component\Simplestats\Administrator\Model\ReportModel $model */
		$model = $this->getModel();

		if (!$model->isSupportedReport($report, $this->eventType))
		{
			throw new \InvalidArgumentException(Text::_('COM_SIMPLESTATS_REPORT_INVALID'), 404);
		}

		$this->data = $model->getReportData(
			$report,
			$this->days,
			$this->start,
			$this->limit,
			$requestedSort,
			$requestedDirection,
			$this->eventType
		);
		$this->sort = (string) $this->data['sort'];
		$this->direction = (string) $this->data['direction'];
		$this->pagination = new Pagination((int) $this->data['total'], $this->start, $this->limit);
		$this->pagination->setAdditionalUrlParam('option', 'com_simplestats');
		$this->pagination->setAdditionalUrlParam('view', 'report');
		$this->pagination->setAdditionalUrlParam('report', $report);

		if ($this->eventType !== '')
		{
			$this->pagination->setAdditionalUrlParam('event_type', $this->eventType);
		}

		$this->pagination->setAdditionalUrlParam('days', $this->days);
		$this->pagination->setAdditionalUrlParam('limit', $this->limit);
		$this->pagination->setAdditionalUrlParam('sort', $this->sort);
		$this->pagination->setAdditionalUrlParam('direction', $this->direction);

		$app->getDocument()->getWebAssetManager()->registerAndUseStyle(
			'com_simplestats.admin.0.6.0',
			'com_simplestats/css/admin-0.6.0.css',
			['version' => '0.6.0']
		);

		ToolbarHelper::title(
			Text::sprintf('COM_SIMPLESTATS_FULL_REPORT_TITLE', Text::_((string) $this->data['title'])),
			'chart'
		);
		parent::display($tpl);
	}
}
