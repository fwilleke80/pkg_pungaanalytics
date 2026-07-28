<?php

declare(strict_types=1);

namespace Punga\Component\PungaAnalytics\Administrator\Controller;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Punga\Component\PungaAnalytics\Administrator\Service\CountryDatabaseService;

\defined('_JEXEC') or die;

/**
 * Dashboard maintenance controller.
 */
final class DashboardController extends BaseController
{
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
	 * Archives raw events older than the configured retention period.
	 *
	 * @return void
	 */
	public function purgeExpired(): void
	{
		Session::checkToken('request') or jexit(Text::_('JINVALID_TOKEN'));
		$this->assertManagePermission();

		/** @var \Punga\Component\PungaAnalytics\Administrator\Model\DashboardModel $model */
		$model = $this->getModel('Dashboard');
		$retentionDays = ComponentHelper::getParams('com_pungaanalytics')->get('retention_days', 180);
		$count = $model->purgeExpired((int) $retentionDays);

		$this->setMessage(Text::sprintf('COM_PUNGAANALYTICS_PURGED_EVENTS', $count));
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

		/** @var \Punga\Component\PungaAnalytics\Administrator\Model\DashboardModel $model */
		$model = $this->getModel('Dashboard');
		$count = $model->resetStats();

		$this->setMessage(Text::sprintf('COM_PUNGAANALYTICS_RESET_COMPLETE', $count));
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

		return Route::_(
			'index.php?option=com_pungaanalytics&dashboardview=system&days=' . rawurlencode($range),
			false
		);
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
