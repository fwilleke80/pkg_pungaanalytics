<?php

declare(strict_types=1);

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Package installer for Punga Analytics.
 */
return new class implements InstallerScriptInterface
{
	/**
	 * Legacy and rebranded statistics table pairs.
	 *
	 * @var array<string, string>
	 */
	private const LEGACY_TABLES = [
		'#__simplestats_events' => '#__pungaanalytics_events',
		'#__simplestats_daily' => '#__pungaanalytics_daily',
		'#__simplestats_daily_dimensions' => '#__pungaanalytics_daily_dimensions',
		'#__simplestats_daily_time' => '#__pungaanalytics_daily_time',
		'#__simplestats_daily_event_time' => '#__pungaanalytics_daily_event_time',
		'#__simplestats_daily_items' => '#__pungaanalytics_daily_items',
		'#__simplestats_daily_404' => '#__pungaanalytics_daily_404',
	];

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
	 * Migrates legacy data before the renamed component creates its schema.
	 *
	 * @param string           $type    Installation operation.
	 * @param InstallerAdapter $adapter Installer adapter.
	 *
	 * @return bool
	 */
	public function preflight(string $type, InstallerAdapter $adapter): bool
	{
		if (!\in_array($type, ['install', 'update'], true))
		{
			return true;
		}

		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$this->migrateLegacyTables($db);
		$this->migrateLegacyCache();

		return true;
	}

	/**
	 * Enables Punga Analytics and completes the SimpleStats-to-Punga migration.
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
		$this->migrateLegacyParameters($db);
		$this->setPluginState($db, 'pungaanalytics', 1);
		$this->setPluginState($db, 'simplestats', 0);
		$this->ensureDashboardModule($db);
		$this->removeLegacyExtensions($db);

		return true;
	}

	/**
	 * Copies old tables, or merges them into tables created by a partial migration.
	 *
	 * @param DatabaseInterface $db Database connection.
	 *
	 * @return void
	 */
	private function migrateLegacyTables(DatabaseInterface $db): void
	{
		$knownTables = $db->getTableList();

		foreach (self::LEGACY_TABLES as $legacyName => $newName)
		{
			$legacyTable = $db->replacePrefix($legacyName);
			$newTable = $db->replacePrefix($newName);

			if (!\in_array($legacyTable, $knownTables, true))
			{
				continue;
			}

			if (!\in_array($newTable, $knownTables, true))
			{
				$db->setQuery(
					'CREATE TABLE ' . $db->quoteName($newTable)
					. ' LIKE ' . $db->quoteName($legacyTable)
				)->execute();
				$knownTables[] = $newTable;
			}

			$this->mergeTables($db, $legacyTable, $newTable);
			$this->renameLegacyIndexes($db, $newTable);
		}
	}

	/**
	 * Copies matching columns without replacing rows already present.
	 *
	 * @param DatabaseInterface $db          Database connection.
	 * @param string            $legacyTable Expanded legacy table name.
	 * @param string            $newTable    Expanded destination table name.
	 *
	 * @return void
	 */
	private function mergeTables(DatabaseInterface $db, string $legacyTable, string $newTable): void
	{
		$legacyColumns = array_keys($db->getTableColumns($legacyTable, false));
		$newColumns = array_keys($db->getTableColumns($newTable, false));
		$columns = array_values(array_intersect($legacyColumns, $newColumns));

		if ($columns === [])
		{
			return;
		}

		$columnList = implode(', ', array_map([$db, 'quoteName'], $columns));
		$db->setQuery(
			'INSERT IGNORE INTO ' . $db->quoteName($newTable)
			. ' (' . $columnList . ')'
			. ' SELECT ' . $columnList
			. ' FROM ' . $db->quoteName($legacyTable)
		)->execute();
	}

	/**
	 * Changes legacy index names after a table rename.
	 *
	 * @param DatabaseInterface $db    Database connection.
	 * @param string            $table Expanded table name.
	 *
	 * @return void
	 */
	private function renameLegacyIndexes(DatabaseInterface $db, string $table): void
	{
		$db->setQuery('SHOW INDEX FROM ' . $db->quoteName($table));
		$rows = $db->loadObjectList();
		$existing = [];

		foreach ($rows as $row)
		{
			$existing[(string) $row->Key_name] = true;
		}

		foreach (array_keys($existing) as $legacyIndex)
		{
			if (!str_contains($legacyIndex, 'simplestats'))
			{
				continue;
			}

			$newIndex = str_replace('simplestats', 'pungaanalytics', $legacyIndex);

			if (isset($existing[$newIndex]))
			{
				continue;
			}

			$db->setQuery(
				'ALTER TABLE ' . $db->quoteName($table)
				. ' RENAME INDEX ' . $db->quoteName($legacyIndex)
				. ' TO ' . $db->quoteName($newIndex)
			)->execute();
			$existing[$newIndex] = true;
		}
	}

	/**
	 * Copies the legacy component options into the rebranded component once.
	 *
	 * @param DatabaseInterface $db Database connection.
	 *
	 * @return void
	 */
	private function migrateLegacyParameters(DatabaseInterface $db): void
	{
		$legacy = $this->getComponentExtension($db, 'com_simplestats');
		$current = $this->getComponentExtension($db, 'com_pungaanalytics');

		if (!$legacy || !$current)
		{
			return;
		}

		$currentParams = json_decode((string) $current->params, true);
		$currentParams = \is_array($currentParams) ? $currentParams : [];

		if ((bool) ($currentParams['legacy_migration_complete'] ?? false))
		{
			return;
		}

		$legacyParams = json_decode((string) $legacy->params, true);
		$legacyParams = \is_array($legacyParams) ? $legacyParams : [];

		if (isset($legacyParams['exclude_components']) && \is_string($legacyParams['exclude_components']))
		{
			$legacyParams['exclude_components'] = str_replace(
				'com_simplestats',
				'com_pungaanalytics',
				$legacyParams['exclude_components']
			);
		}

		$params = array_replace($currentParams, $legacyParams);
		$params['legacy_migration_complete'] = 1;
		$paramsJson = json_encode($params, JSON_UNESCAPED_SLASHES) ?: '{}';
		$extensionId = (int) $current->extension_id;
		$query = $db->getQuery(true)
			->update($db->quoteName('#__extensions'))
			->set($db->quoteName('params') . ' = :params')
			->where($db->quoteName('extension_id') . ' = :extensionId')
			->bind(':params', $paramsJson)
			->bind(':extensionId', $extensionId, ParameterType::INTEGER);
		$db->setQuery($query)->execute();
	}

	/**
	 * Returns one component extension record.
	 *
	 * @param DatabaseInterface $db      Database connection.
	 * @param string            $element Component element.
	 *
	 * @return object|null
	 */
	private function getComponentExtension(DatabaseInterface $db, string $element): ?object
	{
		$query = $db->getQuery(true)
			->select($db->quoteName(['extension_id', 'params']))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('component'))
			->where($db->quoteName('element') . ' = ' . $db->quote($element));
		$db->setQuery($query, 0, 1);
		$row = $db->loadObject();

		return \is_object($row) ? $row : null;
	}

	/**
	 * Enables or disables one system plugin.
	 *
	 * @param DatabaseInterface $db      Database connection.
	 * @param string            $element Plugin element.
	 * @param int               $enabled Desired state.
	 *
	 * @return void
	 */
	private function setPluginState(DatabaseInterface $db, string $element, int $enabled): void
	{
		$query = $db->getQuery(true)
			->update($db->quoteName('#__extensions'))
			->set($db->quoteName('enabled') . ' = ' . $enabled)
			->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
			->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
			->where($db->quoteName('element') . ' = ' . $db->quote($element));
		$db->setQuery($query)->execute();
	}

	/**
	 * Creates the default administrator dashboard module instance once.
	 *
	 * An unconfigured instance created by Joomla's module installer is completed.
	 * Existing configured instances are left untouched.
	 *
	 * @param DatabaseInterface $db Database connection.
	 *
	 * @return void
	 */
	private function ensureDashboardModule(DatabaseInterface $db): void
	{
		$query = $db->getQuery(true)
			->select($db->quoteName(['id', 'position', 'params']))
			->from($db->quoteName('#__modules'))
			->where($db->quoteName('module') . ' = ' . $db->quote('mod_pungaanalytics'))
			->where($db->quoteName('client_id') . ' = 1');
		$db->setQuery($query, 0, 1);
		$existing = $db->loadObject();
		$existingParams = \is_object($existing)
			? json_decode((string) ($existing->params ?? ''), true)
			: null;

		if (
			\is_object($existing)
			&& (
				trim((string) ($existing->position ?? '')) !== ''
				|| (\is_array($existingParams) && $existingParams !== [])
			)
		)
		{
			return;
		}

		$orderQuery = $db->getQuery(true)
			->select('COALESCE(MAX(' . $db->quoteName('ordering') . '), 0)')
			->from($db->quoteName('#__modules'))
			->where($db->quoteName('position') . ' = ' . $db->quote('cpanel'))
			->where($db->quoteName('client_id') . ' = 1');
		$db->setQuery($orderQuery);
		$ordering = (int) $db->loadResult() + 1;
		$module = (object) [
			'title' => 'Punga Analytics',
			'note' => '',
			'content' => '',
			'ordering' => $ordering,
			'position' => 'cpanel',
			'published' => 1,
			'module' => 'mod_pungaanalytics',
			'access' => $this->getSpecialAccessLevel($db),
			'showtitle' => 1,
			'params' => json_encode([
				'days' => 7,
				'show_custom_events' => 1,
				'show_bots' => 1,
				'show_top_pages' => 1,
				'top_pages_limit' => 5,
			], JSON_UNESCAPED_SLASHES) ?: '{}',
			'client_id' => 1,
			'language' => '*',
		];

		if (\is_object($existing) && (int) ($existing->id ?? 0) > 0)
		{
			$module->id = (int) $existing->id;
			$db->updateObject('#__modules', $module, 'id');
		}
		else
		{
			$db->insertObject('#__modules', $module, 'id');
		}

		if ((int) ($module->id ?? 0) > 0)
		{
			$assignmentQuery = $db->getQuery(true)
				->select('COUNT(*)')
				->from($db->quoteName('#__modules_menu'))
				->where($db->quoteName('moduleid') . ' = ' . (int) $module->id);
			$db->setQuery($assignmentQuery);

			if ((int) $db->loadResult() === 0)
			{
				$assignment = (object) [
					'moduleid' => (int) $module->id,
					'menuid' => 0,
				];
				$db->insertObject('#__modules_menu', $assignment);
			}
		}
	}

	/**
	 * Returns the Special view-level ID, with Joomla's conventional ID as fallback.
	 *
	 * @param DatabaseInterface $db Database connection.
	 *
	 * @return int
	 */
	private function getSpecialAccessLevel(DatabaseInterface $db): int
	{
		$query = $db->getQuery(true)
			->select($db->quoteName('id'))
			->from($db->quoteName('#__viewlevels'))
			->where($db->quoteName('title') . ' = ' . $db->quote('Special'));
		$db->setQuery($query, 0, 1);

		return (int) ($db->loadResult() ?: 3);
	}

	/**
	 * Removes installed legacy package records and files after migration.
	 *
	 * @param DatabaseInterface $db Database connection.
	 *
	 * @return void
	 */
	private function removeLegacyExtensions(DatabaseInterface $db): void
	{
		$targets = [
			['package', 'pkg_simplestats', ''],
			['component', 'com_simplestats', ''],
			['plugin', 'simplestats', 'system'],
		];

		foreach ($targets as [$type, $element, $folder])
		{
			$extensionId = $this->findExtensionId($db, $type, $element, $folder);

			if ($extensionId > 0)
			{
				$installer = new Installer();
				$installer->setDatabase($db);
				$installer->uninstall($type, $extensionId);
			}
		}
	}

	/**
	 * Finds one installed extension ID.
	 *
	 * @param DatabaseInterface $db      Database connection.
	 * @param string            $type    Extension type.
	 * @param string            $element Extension element.
	 * @param string            $folder  Optional plugin group.
	 *
	 * @return int
	 */
	private function findExtensionId(
		DatabaseInterface $db,
		string $type,
		string $element,
		string $folder
	): int
	{
		$query = $db->getQuery(true)
			->select($db->quoteName('extension_id'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote($type))
			->where($db->quoteName('element') . ' = ' . $db->quote($element));

		if ($folder !== '')
		{
			$query->where($db->quoteName('folder') . ' = ' . $db->quote($folder));
		}

		$db->setQuery($query, 0, 1);

		return (int) $db->loadResult();
	}

	/**
	 * Preserves an existing locally compiled country database.
	 *
	 * @return void
	 */
	private function migrateLegacyCache(): void
	{
		$legacyPath = JPATH_ROOT . '/cache/com_simplestats';
		$newPath = JPATH_ROOT . '/cache/com_pungaanalytics';

		if (!is_dir($legacyPath))
		{
			return;
		}

		if (!is_dir($newPath))
		{
			@mkdir($newPath, 0755, true);
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($legacyPath, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $item)
		{
			$relative = substr($item->getPathname(), strlen($legacyPath) + 1);
			$destination = $newPath . '/' . $relative;

			if ($item->isDir())
			{
				if (!is_dir($destination))
				{
					@mkdir($destination, 0755, true);
				}
			}
			elseif (!is_file($destination))
			{
				@copy($item->getPathname(), $destination);
			}
		}
	}
};
