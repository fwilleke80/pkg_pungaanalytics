<?php

declare(strict_types=1);

namespace Willeke\Component\Simplestats\Administrator\View\Dashboard;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Willeke\Component\Simplestats\Administrator\Service\CountryDatabaseService;

\defined('_JEXEC') or die;

/**
 * Statistics dashboard view.
 */
final class HtmlView extends BaseHtmlView
{
	/** @var array<string, mixed> */
	public array $data = [];

	/** @var array<string, mixed> */
	public array $countryStatus = [];

	/** @var string */
	public string $version = 'unknown';

	/** @var int */
	public int $days = 30;

	/** @var int */
	public int $retentionDays = 180;

	/** @var int */
	public int $activityTableRows = 8;

	/** @var string */
	public string $sortTable = '';

	/** @var string */
	public string $sort = '';

	/** @var string */
	public string $direction = '';

	/**
	 * Displays the administrator dashboard.
	 *
	 * @param string|null $tpl Layout name.
	 *
	 * @return void
	 */
	public function display($tpl = null): void
	{
		$app = Factory::getApplication();
		$allowedDays = [7, 30, 90, 365, 0];
		$requestedDays = $app->input->getInt('days', 30);
		$this->days = \in_array($requestedDays, $allowedDays, true) ? $requestedDays : 30;
		$params = ComponentHelper::getParams('com_simplestats');
		$this->retentionDays = (int) $params->get('retention_days', 180);
		$this->activityTableRows = min(100, max(0, (int) $params->get('dashboard_activity_rows', 8)));
		$allowedSortTables = [
			'activity',
			'hours',
			'weekdays',
			'pages',
			'plays',
			'downloads',
			'countries',
			'referrers',
			'languages',
			'devices',
			'browsers',
			'bots',
			'events',
		];
		$requestedSortTable = strtolower($app->input->getCmd('sort_table', ''));
		$this->sortTable = \in_array($requestedSortTable, $allowedSortTables, true)
			? $requestedSortTable
			: '';
		$this->sort = strtolower($app->input->getCmd('sort', ''));
		$requestedDirection = strtolower($app->input->getCmd('direction', ''));
		$this->direction = \in_array($requestedDirection, ['asc', 'desc'], true)
			? $requestedDirection
			: '';

		/** @var \Willeke\Component\Simplestats\Administrator\Model\DashboardModel $model */
		$model = $this->getModel();
		$this->data = $model->getDashboardData($this->days, $this->activityTableRows);
		$this->countryStatus = (new CountryDatabaseService())->getStatus();
		$this->version = $model->getInstalledVersion();

		$document = $app->getDocument();
		$document->getWebAssetManager()->registerAndUseStyle(
			'com_simplestats.admin.0.5.6',
			'com_simplestats/css/admin-0.5.6.css',
			['version' => '0.5.6']
		);

		$this->addToolbar();
		parent::display($tpl);
	}

	/**
	 * Configures the administrator toolbar.
	 *
	 * @return void
	 */
	private function addToolbar(): void
	{
		ToolbarHelper::title(Text::_('COM_SIMPLESTATS'), 'chart');
		ToolbarHelper::custom(
			'dashboard.updateCountryDatabase',
			'refresh',
			'refresh',
			'COM_SIMPLESTATS_UPDATE_COUNTRY_DATABASE',
			false
		);
		ToolbarHelper::custom(
			'dashboard.purgeExpired',
			'delete',
			'delete',
			'COM_SIMPLESTATS_PURGE_EXPIRED',
			false
		);
		Toolbar::getInstance('toolbar')
			->confirmButton('resetStats', 'COM_SIMPLESTATS_RESET_STATS', 'dashboard.resetStats')
			->icon('trash')
			->buttonClass('btn btn-danger')
			->message(Text::_('COM_SIMPLESTATS_RESET_CONFIRM'))
			->listCheck(false);

		if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_simplestats'))
		{
			ToolbarHelper::preferences('com_simplestats');
		}
	}
}
