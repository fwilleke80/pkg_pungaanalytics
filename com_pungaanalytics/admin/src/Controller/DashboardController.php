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

		$this->setRedirect(Route::_('index.php?option=com_pungaanalytics', false));
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
		$this->setRedirect(Route::_('index.php?option=com_pungaanalytics', false));
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
		$this->setRedirect(Route::_('index.php?option=com_pungaanalytics', false));
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
