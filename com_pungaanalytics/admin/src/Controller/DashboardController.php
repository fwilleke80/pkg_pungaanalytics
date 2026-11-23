<?php

declare(strict_types=1);

namespace Punga\Component\PungaAnalytics\Administrator\Controller;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Punga\Component\PungaAnalytics\Administrator\Service\AsnDatabaseService;
use Punga\Component\PungaAnalytics\Administrator\Service\CountryDatabaseService;

\defined('_JEXEC') or die;

/**
 * Dashboard maintenance controller.
 */
final class DashboardController extends BaseController
{

	/**
	 * Stores the selected reporting range and returns to the canonical dashboard URL.
	 *
	 * Keeping transient dashboard state out of the final URL lets Joomla recognise
	 * the active administrator submenu item and keep the component sidebar expanded.
	 *
	 * @return void
	 */
	public function setRange(): void
	{
		Session::checkToken('request') or jexit(Text::_('JINVALID_TOKEN'));
		$this->assertManagePermission();

		$allowedRanges = ['today', 'yesterday', 'last24', '7', '30', '90', '365', 'all', '0'];
		$range = strtolower($this->input->getCmd('days', '7'));
		$range = \in_array($range, $allowedRanges, true) ? $range : '7';
		$range = $range === '0' ? 'all' : $range;

		$allowedViews = ['overview', 'traffic', 'content', 'audience', 'quality', 'system'];
		$view = strtolower($this->input->getCmd('dashboardview', 'overview'));
		$view = \in_array($view, $allowedViews, true) ? $view : 'overview';

		$childView = strtolower($this->input->getCmd('childview', ''));
		$allowedChildViews = [
			'traffic' => ['activity', 'sessions', 'acquisition'],
			'content' => ['pages', 'engagement', 'events'],
			'audience' => ['countries', 'networks', 'languages', 'devices', 'browsers'],
			'quality' => ['automated', 'bots'],
		];

		$this->app->setUserState('com_pungaanalytics.dashboard.days', $range);

		if (isset($allowedChildViews[$view]) && \in_array($childView, $allowedChildViews[$view], true))
		{
			$this->app->setUserState('com_pungaanalytics.dashboard.' . $view . 'view', $childView);
		}

		$this->setRedirect(
			Route::_('index.php?option=com_pungaanalytics&dashboardview=' . rawurlencode($view), false)
		);
	}

	/**
	 * Downloads and compiles the current DB-IP Lite country database.
	 *
	 * @return void
	 */
	public function updateCountryDatabase(): void
	{
		Session::checkToken('request') or jexit(Text::_('JINVALID_TOKEN'));
		$this->assertManagePermission();

		try
		{
			$result = (new CountryDatabaseService())->update();
			$this->setMessage(
				Text::sprintf(
					'COM_PUNGAANALYTICS_COUNTRY_DATABASE_UPDATED',
					$result['ipv4_count'],
					$result['ipv6_count']
				)
			);
		}
		catch (\Throwable $exception)
		{
			$this->setMessage(Text::sprintf('COM_PUNGAANALYTICS_COUNTRY_DATABASE_UPDATE_FAILED', $exception->getMessage()), 'error');
		}

		$this->setRedirect($this->getSystemRedirect());
	}

	/**
	 * Downloads and compiles the current DB-IP ASN Lite database.
	 *
	 * @return void
	 */
	public function updateAsnDatabase(): void
	{
		Session::checkToken('request') or jexit(Text::_('JINVALID_TOKEN'));
		$this->assertManagePermission();

		try
		{
			$result = (new AsnDatabaseService())->update();
			$this->setMessage(
				Text::sprintf(
					'COM_PUNGAANALYTICS_ASN_DATABASE_UPDATED',
					$result['ipv4_count'],
					$result['ipv6_count'],
					$result['organization_count']
				)
			);
		}
		catch (\Throwable $exception)
		{
			$this->setMessage(Text::sprintf('COM_PUNGAANALYTICS_ASN_DATABASE_UPDATE_FAILED', $exception->getMessage()), 'error');
		}

		$this->setRedirect($this->getSystemRedirect());
	}

	/**
	 * Re-evaluates all retained anonymous visitor-days for suspected automation.
	 *
	 * @return void
	 */
	public function reclassifyAutomation(): void
	{
		Session::checkToken('request') or jexit(Text::_('JINVALID_TOKEN'));
		$this->assertManagePermission();

		try
		{
			/** @var \Punga\Component\PungaAnalytics\Administrator\Model\DashboardModel $model */
			$model = $this->getModel('Dashboard');
			$count = $model->reclassifyAutomation();
			$this->setMessage(Text::sprintf('COM_PUNGAANALYTICS_AUTOMATION_RECLASSIFIED', $count));
		}
		catch (\Throwable $exception)
		{
			$this->setMessage(Text::sprintf('COM_PUNGAANALYTICS_AUTOMATION_RECLASSIFY_FAILED', $exception->getMessage()), 'error');
		}

		$this->setRedirect($this->getSystemRedirect());
	}

	/**
	 * Archives raw events older than the configured retention period.
	 *
	 * @return void
	 */
	public function purgeExpired(): void
	{
		Session::checkToken('request') or jexit(Text::_('JINVALID_TOKEN'));
		$this->assertManagePermission();

		try
		{
			/** @var \Punga\Component\PungaAnalytics\Administrator\Model\DashboardModel $model */
			$model = $this->getModel('Dashboard');
			$retentionDays = ComponentHelper::getParams('com_pungaanalytics')->get('retention_days', 180);
			$count = $model->purgeExpired((int) $retentionDays);
			$this->setMessage(Text::sprintf('COM_PUNGAANALYTICS_PURGED_EVENTS', $count));
		}
		catch (\Throwable $exception)
		{
			$this->setMessage(Text::sprintf('COM_PUNGAANALYTICS_PURGE_FAILED', $exception->getMessage()), 'error');
		}

		$this->setRedirect($this->getSystemRedirect());
	}

	/**
	 * Permanently removes raw events and archived reports.
	 *
	 * @return void
	 */
	public function resetStats(): void
	{
		Session::checkToken('request') or jexit(Text::_('JINVALID_TOKEN'));
		$this->assertManagePermission();

		try
		{
			/** @var \Punga\Component\PungaAnalytics\Administrator\Model\DashboardModel $model */
			$model = $this->getModel('Dashboard');
			$count = $model->resetStats();
			$this->setMessage(Text::sprintf('COM_PUNGAANALYTICS_RESET_COMPLETE', $count));
		}
		catch (\Throwable $exception)
		{
			$this->setMessage(Text::sprintf('COM_PUNGAANALYTICS_RESET_FAILED', $exception->getMessage()), 'error');
		}

		$this->setRedirect($this->getSystemRedirect());
	}

	/**
	 * Returns the dashboard System-tab URL while preserving the selected range.
	 *
	 * @return string
	 */
	private function getSystemRedirect(): string
	{
		$allowedRanges = ['today', 'yesterday', 'last24', '7', '30', '90', '365', 'all', '0'];
		$range = strtolower($this->input->getCmd('days', '7'));
		$range = \in_array($range, $allowedRanges, true) ? $range : '7';
		$range = $range === '0' ? 'all' : $range;
		$this->app->setUserState('com_pungaanalytics.dashboard.days', $range);

		return Route::_('index.php?option=com_pungaanalytics&dashboardview=system', false);
	}

	/**
	 * Verifies component management permission.
	 *
	 * @return void
	 */
	private function assertManagePermission(): void
	{
		if (!$this->app->getIdentity()->authorise('core.manage', 'com_pungaanalytics'))
		{
			throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}
	}
}
