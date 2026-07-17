<?php

declare(strict_types=1);

namespace FrankWilleke\Component\Simplestats\Administrator\Controller;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use FrankWilleke\Component\Simplestats\Administrator\Service\GermanyRangesService;

\defined('_JEXEC') or die;

/**
 * Dashboard maintenance controller.
 */
final class DashboardController extends BaseController
{
	/**
	 * Downloads and compiles the current German IPv4 and IPv6 ranges.
	 *
	 * @return void
	 */
	public function updateRanges(): void
	{
		Session::checkToken('request') or jexit(Text::_('JINVALID_TOKEN'));
		$this->assertManagePermission();

		try
		{
			$result = (new GermanyRangesService())->update();
			$this->setMessage(
				Text::sprintf(
					'COM_SIMPLESTATS_RANGES_UPDATED',
					$result['ipv4_count'],
					$result['ipv6_count']
				)
			);
		}
		catch (\Throwable $exception)
		{
			$this->setMessage(Text::sprintf('COM_SIMPLESTATS_RANGES_UPDATE_FAILED', $exception->getMessage()), 'error');
		}

		$this->setRedirect(Route::_('index.php?option=com_simplestats', false));
	}

	/**
	 * Removes events older than the configured retention period.
	 *
	 * @return void
	 */
	public function purgeExpired(): void
	{
		Session::checkToken('request') or jexit(Text::_('JINVALID_TOKEN'));
		$this->assertManagePermission();

		/** @var \FrankWilleke\Component\Simplestats\Administrator\Model\DashboardModel $model */
		$model = $this->getModel('Dashboard');
		$retentionDays = ComponentHelper::getParams('com_simplestats')->get('retention_days', 180);
		$count = $model->purgeExpired((int) $retentionDays);

		$this->setMessage(Text::sprintf('COM_SIMPLESTATS_PURGED_EVENTS', $count));
		$this->setRedirect(Route::_('index.php?option=com_simplestats', false));
	}

	/**
	 * Verifies component management permission.
	 *
	 * @return void
	 */
	private function assertManagePermission(): void
	{
		if (!$this->app->getIdentity()->authorise('core.manage', 'com_simplestats'))
		{
			throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}
	}
}
