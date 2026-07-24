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

		foreach ([
			'#__simplestats_daily_items',
			'#__simplestats_daily_event_time',
			'#__simplestats_daily_time',
			'#__simplestats_daily_dimensions',
			'#__simplestats_daily',
			'#__simplestats_events',
		] as $table)
		{
			$db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName($table))->execute();
		}

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
	`visit_hour` TINYINT UNSIGNED NOT NULL DEFAULT 255,
	`visit_weekday` TINYINT UNSIGNED NOT NULL DEFAULT 0,
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
	KEY `idx_simplestats_hour_date` (`visit_hour`, `visit_date`),
	KEY `idx_simplestats_weekday_date` (`visit_weekday`, `visit_date`),
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

		$this->ensureColumn($db, $table, $columns, 'visit_hour', "TINYINT UNSIGNED NOT NULL DEFAULT 255 AFTER `visit_date`");
		$this->ensureColumn($db, $table, $columns, 'visit_weekday', "TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `visit_hour`");
		$this->ensureColumn($db, $table, $columns, 'is_authenticated', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `browser_family`");
		$this->ensureColumn($db, $table, $columns, 'item_type', "VARCHAR(64) NOT NULL DEFAULT '' AFTER `event_type`");
		$this->ensureColumn($db, $table, $columns, 'item_id', "VARCHAR(128) NOT NULL DEFAULT '' AFTER `item_type`");
		$this->ensureColumn($db, $table, $columns, 'item_title', "VARCHAR(255) NOT NULL DEFAULT '' AFTER `item_id`");

		$db->setQuery(
			'ALTER TABLE ' . $db->quoteName($table)
			. ' MODIFY ' . $db->quoteName('event_type')
			. " VARCHAR(64) NOT NULL DEFAULT 'pageview'"
		)->execute();

		$this->ensureIndex($db, $table, 'idx_simplestats_auth_date', '(`is_authenticated`, `visit_date`)');
		$this->ensureIndex($db, $table, 'idx_simplestats_hour_date', '(`visit_hour`, `visit_date`)');
		$this->ensureIndex($db, $table, 'idx_simplestats_weekday_date', '(`visit_weekday`, `visit_date`)');
		$this->ensureIndex($db, $table, 'idx_simplestats_event_date', '(`event_type`, `visit_date`)');
		$this->ensureIndex($db, $table, 'idx_simplestats_item', '(`item_type`, `item_id`(64))');
		$db->setQuery(
			'UPDATE ' . $db->quoteName($table)
			. ' SET ' . $db->quoteName('visit_weekday') . ' = WEEKDAY(' . $db->quoteName('visit_date') . ') + 1'
			. ' WHERE ' . $db->quoteName('visit_weekday') . ' = 0'
		)->execute();
		$this->ensureArchiveSchema($db);

		return true;
	}

	/**
	 * Creates the permanent daily aggregate tables.
	 *
	 * @param DatabaseInterface $db Database connection.
	 *
	 * @return void
	 */
	private function ensureArchiveSchema(DatabaseInterface $db): void
	{
		$queries = [
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `#__simplestats_daily` (
	`visit_date` DATE NOT NULL,
	`human_visits` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`human_pageviews` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`authenticated_pageviews` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`german_visits` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`bot_pageviews` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`custom_events` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci
SQL,
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `#__simplestats_daily_dimensions` (
	`visit_date` DATE NOT NULL,
	`dimension_key` VARCHAR(32) NOT NULL,
	`label_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	`label` VARCHAR(1024) NOT NULL,
	`event_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`, `dimension_key`, `label_hash`),
	KEY `idx_simplestats_dimension_date` (`dimension_key`, `visit_date`),
	KEY `idx_simplestats_dimension_label` (`dimension_key`, `label`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci
SQL,
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `#__simplestats_daily_time` (
	`visit_date` DATE NOT NULL,
	`bucket_kind` VARCHAR(8) NOT NULL,
	`bucket_value` TINYINT UNSIGNED NOT NULL,
	`human_visits` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`human_pageviews` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`bot_pageviews` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`, `bucket_kind`, `bucket_value`),
	KEY `idx_simplestats_time_kind_date` (`bucket_kind`, `visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci
SQL,
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `#__simplestats_daily_event_time` (
	`visit_date` DATE NOT NULL,
	`event_type` VARCHAR(64) NOT NULL,
	`bucket_kind` VARCHAR(8) NOT NULL,
	`bucket_value` TINYINT UNSIGNED NOT NULL,
	`event_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`, `event_type`, `bucket_kind`, `bucket_value`),
	KEY `idx_simplestats_event_time_type` (`event_type`, `bucket_kind`, `visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci
SQL,
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `#__simplestats_daily_items` (
	`visit_date` DATE NOT NULL,
	`row_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	`event_type` VARCHAR(64) NOT NULL,
	`item_type` VARCHAR(64) NOT NULL DEFAULT '',
	`item_id` VARCHAR(128) NOT NULL DEFAULT '',
	`item_title` VARCHAR(255) NOT NULL DEFAULT '',
	`path` VARCHAR(1024) NOT NULL DEFAULT '',
	`event_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`, `row_hash`),
	KEY `idx_simplestats_daily_item_event` (`event_type`, `visit_date`),
	KEY `idx_simplestats_daily_item` (`item_type`, `item_id`(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci
SQL,
		];

		foreach ($queries as $query)
		{
			$db->setQuery($db->replacePrefix($query))->execute();
		}

		$db->setQuery(
			$db->replacePrefix(
				<<<'SQL'
INSERT INTO `#__simplestats_daily_time` (
	`visit_date`, `bucket_kind`, `bucket_value`, `human_visits`,
	`human_pageviews`, `bot_pageviews`
)
SELECT
	`visit_date`, 'weekday', WEEKDAY(`visit_date`) + 1, `human_visits`,
	`human_pageviews`, `bot_pageviews`
FROM `#__simplestats_daily`
ON DUPLICATE KEY UPDATE
	`human_visits` = VALUES(`human_visits`),
	`human_pageviews` = VALUES(`human_pageviews`),
	`bot_pageviews` = VALUES(`bot_pageviews`)
SQL
			)
		)->execute();
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

		if (!array_key_exists('custom_event_policy', $params))
		{
			$params['custom_event_policy'] = 'all';
			$changed = true;
		}

		if (!array_key_exists('custom_event_definitions', $params))
		{
			$params['custom_event_definitions'] = $this->discoverCustomEventDefinitions($db);
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
	 * Creates initial presentation definitions for already-recorded event types.
	 *
	 * @param DatabaseInterface $db Database connection.
	 *
	 * @return array<int, array<string, int|string>>
	 */
	private function discoverCustomEventDefinitions(DatabaseInterface $db): array
	{
		$sql = <<<'SQL'
SELECT DISTINCT `event_type`
FROM (
	SELECT `event_type`
	FROM `#__simplestats_events`
	WHERE `event_type` <> 'pageview'
	UNION
	SELECT `label` AS `event_type`
	FROM `#__simplestats_daily_dimensions`
	WHERE `dimension_key` = 'event_type'
	UNION
	SELECT `event_type`
	FROM `#__simplestats_daily_items`
) AS `recorded_events`
WHERE `event_type` REGEXP '^[a-z][a-z0-9._-]{0,63}$'
ORDER BY `event_type`
LIMIT 100
SQL;
		$db->setQuery($db->replacePrefix($sql));
		$eventTypes = array_map('strval', $db->loadColumn());
		$definitions = [];
		$colors = ['#198754', '#d99000', '#6f42c1', '#0f8b8d', '#c94b54', '#2a69b8'];

		foreach ($eventTypes as $index => $eventType)
		{
			$title = ucwords((string) preg_replace('/[._-]+/', ' ', $eventType));
			$definitions[] = [
				'event_type' => $eventType,
				'title' => $title,
				'source_component' => '',
				'record' => 1,
				'show_summary' => 0,
				'show_trend' => 0,
				'show_time' => 0,
				'show_ranking' => 1,
				'ranking_title' => $title . ' by item',
				'report_title' => $title,
				'color' => $colors[$index % \count($colors)],
				'icon' => 'icon-chart',
			];
		}

		return $definitions;
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
