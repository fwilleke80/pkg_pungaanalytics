<?php

declare(strict_types=1);

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Package installer for Simple Stats.
 */
return new class implements InstallerScriptInterface
{
	/**
	 * Handles initial installation.
	 *
	 * @param InstallerAdapter $adapter Installer adapter.
	 *
	 * @return bool
	 */
	public function install(InstallerAdapter $adapter): bool
	{
		return true;
	}

	/**
	 * Handles package updates.
	 *
	 * @param InstallerAdapter $adapter Installer adapter.
	 *
	 * @return bool
	 */
	public function update(InstallerAdapter $adapter): bool
	{
		return true;
	}

	/**
	 * Handles package removal.
	 *
	 * @param InstallerAdapter $adapter Installer adapter.
	 *
	 * @return bool
	 */
	public function uninstall(InstallerAdapter $adapter): bool
	{
		return true;
	}

	/**
	 * Runs before package installation, update, or removal.
	 *
	 * @param string           $type    Installation operation.
	 * @param InstallerAdapter $adapter Installer adapter.
	 *
	 * @return bool
	 */
	public function preflight(string $type, InstallerAdapter $adapter): bool
	{
		return true;
	}

	/**
	 * Enables the collector plugin after package installation or update.
	 *
	 * @param string           $type    Installation operation.
	 * @param InstallerAdapter $adapter Installer adapter.
	 *
	 * @return bool
	 */
	public function postflight(string $type, InstallerAdapter $adapter): bool
	{
		if (!\in_array($type, ['install', 'update'], true))
		{
			return true;
		}

		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->update($db->quoteName('#__extensions'))
			->set($db->quoteName('enabled') . ' = 1')
			->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
			->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
			->where($db->quoteName('element') . ' = ' . $db->quote('simplestats'));

		$db->setQuery($query)->execute();

		return true;
	}
};
