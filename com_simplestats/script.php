<?php

declare(strict_types=1);

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Installer script for the Simple Stats administrator component.
 */
return new class implements InstallerScriptInterface
{
	/**
	 * Creates the database schema during initial installation.
	 */
	public function install(InstallerAdapter $adapter): bool
	{
		return $this->ensureSchema();
	}

	/**
	 * Repairs or creates the database schema during updates.
	 */
	public function update(InstallerAdapter $adapter): bool
	{
		return $this->ensureSchema();
	}

	/**
	 * Removes all Simple Stats data during uninstallation.
	 */
	public function uninstall(InstallerAdapter $adapter): bool
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName('#__simplestats_events'))->execute();
		$this->removeDirectory(JPATH_ROOT . '/cache/com_simplestats');

		return true;
	}

	public function preflight(string $type, InstallerAdapter $adapter): bool
	{
		return true;
	}

	/**
	 * Ensures the schema and migrated settings exist after install/update.
	 */
	public function postflight(string $type, InstallerAdapter $adapter): bool
	{
		if (!\in_array($type, ['install', 'update'], true))
		{
			return true;
		}

		$this->ensureSchema();
		$this->migrateParameters();

		return true;
	}

	/**
	 * Creates or repairs the event table.
	 */
	private function ensureSchema(): bool
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$query = <<<'SQL'
CREATE TABLE IF NOT EXISTS `#__simplestats_events` (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`visited_at` DATETIME NOT NULL,
	`visit_date` DATE NOT NULL,
	`visitor_hash` CHAR(32) NOT NULL,
	`path` VARCHAR(1024) NOT NULL,
	`component` VARCHAR(100) NOT NULL DEFAULT '',
	`view_name` VARCHAR(100) NOT NULL DEFAULT '',
	`referrer_host` VARCHAR(255) NOT NULL DEFAULT '',
	`country_code` CHAR(2) NOT NULL DEFAULT 'ZZ',
	`language_code` VARCHAR(16) NOT NULL DEFAULT '',
	`device_type` VARCHAR(16) NOT NULL DEFAULT 'unknown',
	`browser_family` VARCHAR(32) NOT NULL DEFAULT 'Other',
	`is_authenticated` TINYINT(1) NOT NULL DEFAULT 0,
	`is_bot` TINYINT(1) NOT NULL DEFAULT 0,
	`bot_name` VARCHAR(64) NOT NULL DEFAULT '',
	`event_type` VARCHAR(64) NOT NULL DEFAULT 'pageview',
	`item_type` VARCHAR(64) NOT NULL DEFAULT '',
	`item_id` VARCHAR(128) NOT NULL DEFAULT '',
	`item_title` VARCHAR(255) NOT NULL DEFAULT '',
	PRIMARY KEY (`id`),
	KEY `idx_simplestats_visited_at` (`visited_at`),
	KEY `idx_simplestats_visit_date` (`visit_date`),
	KEY `idx_simplestats_visitor_hash` (`visitor_hash`),
	KEY `idx_simplestats_bot_date` (`is_bot`, `visit_date`),
	KEY `idx_simplestats_auth_date` (`is_authenticated`, `visit_date`),
	KEY `idx_simplestats_country_date` (`country_code`, `visit_date`),
	KEY `idx_simplestats_event_date` (`event_type`, `visit_date`),
	KEY `idx_simplestats_item` (`item_type`, `item_id`(64)),
	KEY `idx_simplestats_path` (`path`(191)),
	KEY `idx_simplestats_referrer` (`referrer_host`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci
SQL;

		$db->setQuery($db->replacePrefix($query))->execute();
		$table = $db->replacePrefix('#__simplestats_events');
		$columns = $db->getTableColumns($table, false);

		$this->ensureColumn($db, $table, $columns, 'is_authenticated', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `browser_family`");
		$this->ensureColumn($db, $table, $columns, 'item_type', "VARCHAR(64) NOT NULL DEFAULT '' AFTER `event_type`");
		$this->ensureColumn($db, $table, $columns, 'item_id', "VARCHAR(128) NOT NULL DEFAULT '' AFTER `item_type`");
		$this->ensureColumn($db, $table, $columns, 'item_title', "VARCHAR(255) NOT NULL DEFAULT '' AFTER `item_id`");

		$db->setQuery(
			'ALTER TABLE ' . $db->quoteName($table)
			. ' MODIFY COLUMN ' . $db->quoteName('event_type')
			. " VARCHAR(64) NOT NULL DEFAULT 'pageview'"
		)->execute();

		$this->ensureIndex($db, $table, 'idx_simplestats_auth_date', '(`is_authenticated`, `visit_date`)');
		$this->ensureIndex($db, $table, 'idx_simplestats_event_date', '(`event_type`, `visit_date`)');
		$this->ensureIndex($db, $table, 'idx_simplestats_item', '(`item_type`, `item_id`(64))');

		return true;
	}

	/**
	 * Adds one missing column.
	 *
	 * @param DatabaseInterface     $db         Database connection.
	 * @param string                $table      Expanded table name.
	 * @param array<string, mixed>  $columns    Existing columns.
	 * @param string                $columnName Column name.
	 * @param string                $definition SQL definition.
	 */
	private function ensureColumn(
		DatabaseInterface $db,
		string $table,
		array &$columns,
		string $columnName,
		string $definition
	): void
	{
		if (isset($columns[$columnName]))
		{
			return;
		}

		$db->setQuery(
			'ALTER TABLE ' . $db->quoteName($table)
			. ' ADD COLUMN ' . $db->quoteName($columnName) . ' ' . $definition
		)->execute();
		$columns[$columnName] = true;
	}

	/**
	 * Adds one missing index.
	 */
	private function ensureIndex(DatabaseInterface $db, string $table, string $indexName, string $definition): void
	{
		$db->setQuery(
			'SHOW INDEX FROM ' . $db->quoteName($table)
			. ' WHERE ' . $db->quoteName('Key_name') . ' = ' . $db->quote($indexName)
		);

		if ($db->loadResult() !== null)
		{
			return;
		}

		$db->setQuery(
			'ALTER TABLE ' . $db->quoteName($table)
			. ' ADD KEY ' . $db->quoteName($indexName) . ' ' . $definition
		)->execute();
	}

	/**
	 * Migrates obsolete 0.1.x options to the 0.2 configuration.
	 */
	private function migrateParameters(): void
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select($db->quoteName(['extension_id', 'params']))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('component'))
			->where($db->quoteName('element') . ' = ' . $db->quote('com_simplestats'));
		$db->setQuery($query, 0, 1);
		$row = $db->loadObject();

		if (!$row)
		{
			return;
		}

		$params = json_decode((string) $row->params, true);
		$params = is_array($params) ? $params : [];
		$changed = false;

		if (!array_key_exists('track_authenticated', $params))
		{
			$params['track_authenticated'] = 1;
			$changed = true;
		}

		if (array_key_exists('exclude_logged_in', $params))
		{
			unset($params['exclude_logged_in']);
			$changed = true;
		}

		if (($params['country_detection'] ?? '') === 'local_de')
		{
			$params['country_detection'] = 'local_dbip';
			$changed = true;
		}

		if (!$changed)
		{
			return;
		}

		$paramsJson = json_encode($params, JSON_UNESCAPED_SLASHES) ?: '{}';
		$extensionId = (int) $row->extension_id;
		$query = $db->getQuery(true)
			->update($db->quoteName('#__extensions'))
			->set($db->quoteName('params') . ' = :params')
			->where($db->quoteName('extension_id') . ' = :extensionId')
			->bind(':params', $paramsJson)
			->bind(':extensionId', $extensionId, ParameterType::INTEGER);
		$db->setQuery($query)->execute();
	}

	/**
	 * Recursively removes a known extension-owned directory.
	 */
	private function removeDirectory(string $path): void
	{
		if (!is_dir($path))
		{
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item)
		{
			if ($item->isDir())
			{
				@rmdir($item->getPathname());
			}
			else
			{
				@unlink($item->getPathname());
			}
		}

		@rmdir($path);
	}
};
