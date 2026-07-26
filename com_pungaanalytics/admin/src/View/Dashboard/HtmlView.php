<?php

declare(strict_types=1);

namespace Punga\Component\PungaAnalytics\Administrator\View\Dashboard;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Punga\Component\PungaAnalytics\Administrator\Service\CountryDatabaseService;
use Punga\Component\PungaAnalytics\Administrator\Service\CustomEventDefinitionService;

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

	/** @var string */
	public string $range = '7';

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
		$allowedRanges = ['today', 'yesterday', 'last24', '7', '30', '90', '365', 'all', '0'];
		$requestedRange = strtolower($app->input->getCmd('days', '7'));
		$this->range = \in_array($requestedRange, $allowedRanges, true) ? $requestedRange : '7';
		$this->range = $this->range === '0' ? 'all' : $this->range;
		$params = ComponentHelper::getParams('com_pungaanalytics');
		$this->retentionDays = (int) $params->get('retention_days', 180);
		$this->activityTableRows = min(100, max(0, (int) $params->get('dashboard_activity_rows', 8)));
		$allowedSortTables = [
			'activity',
			'hours',
			'weekdays',
			'pages',
			'notfound',
			'countries',
			'referrers',
			'sources',
			'languages',
			'devices',
			'browsers',
			'bots',
			'events',
		];

		foreach ((new CustomEventDefinitionService())->getDefinitions($params) as $definition)
		{
			if ((bool) $definition['show_ranking'])
			{
				$allowedSortTables[] = (string) $definition['table_key'];
			}
		}
		$requestedSortTable = strtolower($app->input->getCmd('sort_table', ''));
		$this->sortTable = \in_array($requestedSortTable, $allowedSortTables, true)
			? $requestedSortTable
			: '';
		$this->sort = strtolower($app->input->getCmd('sort', ''));
		$requestedDirection = strtolower($app->input->getCmd('direction', ''));
		$this->direction = \in_array($requestedDirection, ['asc', 'desc'], true)
			? $requestedDirection
			: '';

		/** @var \Punga\Component\PungaAnalytics\Administrator\Model\DashboardModel $model */
		$model = $this->getModel();
		$this->data = $model->getDashboardData($this->range, $this->activityTableRows);
		$this->countryStatus = (new CountryDatabaseService())->getStatus();
		$this->version = $model->getInstalledVersion();

		$document = $app->getDocument();
		$webAssetManager = $document->getWebAssetManager();
		$webAssetManager->registerAndUseStyle(
			'com_pungaanalytics.admin.0.8.8',
			'com_pungaanalytics/admin-0.8.8.css',
			['version' => '0.8.8']
		);
		$webAssetManager->registerAndUseScript(
			'com_pungaanalytics.dashboard.0.8.8',
			'com_pungaanalytics/admin-0.8.8.js',
			['version' => '0.8.8'],
			['defer' => true]
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
		ToolbarHelper::title(Text::_('COM_PUNGAANALYTICS'), 'chart');
		ToolbarHelper::custom(
			'dashboard.updateCountryDatabase',
			'refresh',
			'refresh',
			'COM_PUNGAANALYTICS_UPDATE_COUNTRY_DATABASE',
			false
		);
		ToolbarHelper::custom(
			'dashboard.purgeExpired',
			'delete',
			'delete',
			'COM_PUNGAANALYTICS_PURGE_EXPIRED',
			false
		);
		Toolbar::getInstance('toolbar')
			->confirmButton('resetStats', 'COM_PUNGAANALYTICS_RESET_STATS', 'dashboard.resetStats')
			->icon('trash')
			->buttonClass('btn btn-danger')
			->message(Text::_('COM_PUNGAANALYTICS_RESET_CONFIRM'))
			->listCheck(false);

		if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_pungaanalytics'))
		{
			ToolbarHelper::preferences('com_pungaanalytics');
		}
	}
}
