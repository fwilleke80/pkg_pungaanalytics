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
		$this->retentionDays = (int) ComponentHelper::getParams('com_simplestats')->get('retention_days', 180);

		/** @var \Willeke\Component\Simplestats\Administrator\Model\DashboardModel $model */
		$model = $this->getModel();
		$this->data = $model->getDashboardData($this->days);
		$this->countryStatus = (new CountryDatabaseService())->getStatus();
		$this->version = $model->getInstalledVersion();

		$document = $app->getDocument();
		$document->getWebAssetManager()->registerAndUseStyle(
			'com_simplestats.admin.0.4.0',
			'com_simplestats/css/admin-0.4.0.css',
			['version' => '0.4.0']
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
