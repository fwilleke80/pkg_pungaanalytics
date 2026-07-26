<?php

declare(strict_types=1);

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Installer script for the Punga Analytics administrator component.
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
	 * Removes all Punga Analytics data during uninstallation.
	 */
	public function uninstall(InstallerAdapter $adapter): bool
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);

		foreach ([
			'#__pungaanalytics_page_status',
			'#__pungaanalytics_daily_pages',
			'#__pungaanalytics_daily_404',
			'#__pungaanalytics_daily_items',
			'#__pungaanalytics_daily_event_time',
			'#__pungaanalytics_daily_time',
			'#__pungaanalytics_daily_dimensions',
			'#__pungaanalytics_daily',
			'#__pungaanalytics_events',
		] as $table)
		{
			$db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName($table))->execute();
		}

		$this->removeDirectory(JPATH_ROOT . '/cache/com_pungaanalytics');

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
		$this->rebuildPageStatusIndex();
		$this->discardLegacyPathAggregates();
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
CREATE TABLE IF NOT EXISTS `#__pungaanalytics_events` (
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
	`traffic_source` VARCHAR(16) NOT NULL DEFAULT '',
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
	`http_status` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`id`),
	KEY `idx_pungaanalytics_visited_at` (`visited_at`),
	KEY `idx_pungaanalytics_visit_date` (`visit_date`),
	KEY `idx_pungaanalytics_hour_date` (`visit_hour`, `visit_date`),
	KEY `idx_pungaanalytics_weekday_date` (`visit_weekday`, `visit_date`),
	KEY `idx_pungaanalytics_visitor_hash` (`visitor_hash`),
	KEY `idx_pungaanalytics_bot_date` (`is_bot`, `visit_date`),
	KEY `idx_pungaanalytics_auth_date` (`is_authenticated`, `visit_date`),
	KEY `idx_pungaanalytics_country_date` (`country_code`, `visit_date`),
	KEY `idx_pungaanalytics_event_date` (`event_type`, `visit_date`),
	KEY `idx_pungaanalytics_item` (`item_type`, `item_id`(64)),
	KEY `idx_pungaanalytics_path` (`path`(191)),
	KEY `idx_pungaanalytics_referrer` (`referrer_host`),
	KEY `idx_pungaanalytics_source_date` (`traffic_source`, `visit_date`),
	KEY `idx_pungaanalytics_status_date` (`http_status`, `visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci
SQL;

		$db->setQuery($db->replacePrefix($query))->execute();
		$table = $db->replacePrefix('#__pungaanalytics_events');
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

		$this->ensureIndex($db, $table, 'idx_pungaanalytics_auth_date', '(`is_authenticated`, `visit_date`)');
		$this->ensureIndex($db, $table, 'idx_pungaanalytics_hour_date', '(`visit_hour`, `visit_date`)');
		$this->ensureIndex($db, $table, 'idx_pungaanalytics_weekday_date', '(`visit_weekday`, `visit_date`)');
		$this->ensureIndex($db, $table, 'idx_pungaanalytics_event_date', '(`event_type`, `visit_date`)');
		$this->ensureIndex($db, $table, 'idx_pungaanalytics_item', '(`item_type`, `item_id`(64))');
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
CREATE TABLE IF NOT EXISTS `#__pungaanalytics_daily` (
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
CREATE TABLE IF NOT EXISTS `#__pungaanalytics_daily_dimensions` (
	`visit_date` DATE NOT NULL,
	`dimension_key` VARCHAR(32) NOT NULL,
	`label_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	`label` VARCHAR(1024) NOT NULL,
	`event_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`, `dimension_key`, `label_hash`),
	KEY `idx_pungaanalytics_dimension_date` (`dimension_key`, `visit_date`),
	KEY `idx_pungaanalytics_dimension_label` (`dimension_key`, `label`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci
SQL,
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `#__pungaanalytics_daily_pages` (
	`visit_date` DATE NOT NULL,
	`path_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	`path` VARCHAR(1024) NOT NULL,
	`pageview_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`, `path_hash`),
	KEY `idx_pungaanalytics_daily_pages_date` (`visit_date`),
	KEY `idx_pungaanalytics_daily_pages_path` (`path`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci
SQL,
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `#__pungaanalytics_page_status` (
	`path_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	`path` VARCHAR(1024) NOT NULL,
	`last_status` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	`last_seen_at` DATETIME NOT NULL,
	PRIMARY KEY (`path_hash`),
	KEY `idx_pungaanalytics_page_status_status` (`last_status`),
	KEY `idx_pungaanalytics_page_status_path` (`path`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci
SQL,
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `#__pungaanalytics_daily_time` (
	`visit_date` DATE NOT NULL,
	`bucket_kind` VARCHAR(8) NOT NULL,
	`bucket_value` TINYINT UNSIGNED NOT NULL,
	`human_visits` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`human_pageviews` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`bot_pageviews` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`, `bucket_kind`, `bucket_value`),
	KEY `idx_pungaanalytics_time_kind_date` (`bucket_kind`, `visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci
SQL,
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `#__pungaanalytics_daily_event_time` (
	`visit_date` DATE NOT NULL,
	`event_type` VARCHAR(64) NOT NULL,
	`bucket_kind` VARCHAR(8) NOT NULL,
	`bucket_value` TINYINT UNSIGNED NOT NULL,
	`event_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`, `event_type`, `bucket_kind`, `bucket_value`),
	KEY `idx_pungaanalytics_event_time_type` (`event_type`, `bucket_kind`, `visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci
SQL,
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `#__pungaanalytics_daily_items` (
	`visit_date` DATE NOT NULL,
	`row_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	`event_type` VARCHAR(64) NOT NULL,
	`item_type` VARCHAR(64) NOT NULL DEFAULT '',
	`item_id` VARCHAR(128) NOT NULL DEFAULT '',
	`item_title` VARCHAR(255) NOT NULL DEFAULT '',
	`path` VARCHAR(1024) NOT NULL DEFAULT '',
	`event_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`, `row_hash`),
	KEY `idx_pungaanalytics_daily_item_event` (`event_type`, `visit_date`),
	KEY `idx_pungaanalytics_daily_item` (`item_type`, `item_id`(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci
SQL,
			<<<'SQL'
CREATE TABLE IF NOT EXISTS `#__pungaanalytics_daily_404` (
	`visit_date` DATE NOT NULL,
	`row_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	`path` VARCHAR(1024) NOT NULL,
	`referrer_host` VARCHAR(255) NOT NULL DEFAULT '',
	`is_bot` TINYINT(1) NOT NULL DEFAULT 0,
	`request_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`first_seen` DATETIME NOT NULL,
	`last_seen` DATETIME NOT NULL,
	PRIMARY KEY (`visit_date`, `row_hash`),
	KEY `idx_pungaanalytics_404_date` (`visit_date`),
	KEY `idx_pungaanalytics_404_path` (`path`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci
SQL,
		];

		foreach ($queries as $query)
		{
			$db->setQuery($db->replacePrefix($query))->execute();
		}

		$this->migrateLegacyEventTime($db);
		$db->setQuery(
			$db->replacePrefix(
				<<<'SQL'
INSERT INTO `#__pungaanalytics_daily_time` (
	`visit_date`, `bucket_kind`, `bucket_value`, `human_visits`,
	`human_pageviews`, `bot_pageviews`
)
SELECT
	`visit_date`, 'weekday', WEEKDAY(`visit_date`) + 1, `human_visits`,
	`human_pageviews`, `bot_pageviews`
FROM `#__pungaanalytics_daily`
ON DUPLICATE KEY UPDATE
	`human_visits` = VALUES(`human_visits`),
	`human_pageviews` = VALUES(`human_pageviews`),
	`bot_pageviews` = VALUES(`bot_pageviews`)
SQL
			)
		)->execute();
	}

	/**
	 * Preserves audio time aggregates created before generic event definitions.
	 *
	 * @param DatabaseInterface $db Database connection.
	 *
	 * @return void
	 */
	private function migrateLegacyEventTime(DatabaseInterface $db): void
	{
		$timeTable = $db->replacePrefix('#__pungaanalytics_daily_time');
		$columns = $db->getTableColumns($timeTable, false);
		$legacyColumns = [
			'plays' => 'audio.play',
			'downloads' => 'audio.download',
		];

		foreach ($legacyColumns as $column => $eventType)
		{
			if (!isset($columns[$column]))
			{
				continue;
			}

			$sql = <<<'SQL'
INSERT INTO `#__pungaanalytics_daily_event_time` (
	`visit_date`, `event_type`, `bucket_kind`, `bucket_value`, `event_count`
)
SELECT `visit_date`, %s, `bucket_kind`, `bucket_value`, %s
FROM `#__pungaanalytics_daily_time`
WHERE %s > 0
ON DUPLICATE KEY UPDATE
	`event_count` = GREATEST(`event_count`, VALUES(`event_count`))
SQL;
			$sql = sprintf(
				$sql,
				$db->quote($eventType),
				$db->quoteName($column),
				$db->quoteName($column)
			);
			$query = $db->replacePrefix($sql);
			$db->setQuery($query)->execute();
		}
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
	 * Backfills the latest known status for every retained page path.
	 *
	 * The index is permanent, so a path remains classifiable after its raw event
	 * rows have been archived and removed.
	 *
	 * @return void
	 */
	private function rebuildPageStatusIndex(): void
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$rawSql = <<<'SQL'
INSERT INTO `#__pungaanalytics_page_status` (
	`path_hash`, `path`, `last_status`, `last_seen_at`
)
SELECT
	SHA2(`event`.`path`, 256),
	`event`.`path`,
	`event`.`http_status`,
	`event`.`visited_at`
FROM `#__pungaanalytics_events` AS `event`
INNER JOIN (
	SELECT `path`, MAX(`id`) AS `latest_id`
	FROM `#__pungaanalytics_events`
	WHERE `event_type` = 'pageview'
		AND `http_status` >= 100
		AND `http_status` <= 599
	GROUP BY `path`
) AS `latest`
	ON `latest`.`latest_id` = `event`.`id`
ON DUPLICATE KEY UPDATE
	`path` = IF(
		VALUES(`last_seen_at`) >= `#__pungaanalytics_page_status`.`last_seen_at`,
		VALUES(`path`),
		`#__pungaanalytics_page_status`.`path`
	),
	`last_status` = IF(
		VALUES(`last_seen_at`) >= `#__pungaanalytics_page_status`.`last_seen_at`,
		VALUES(`last_status`),
		`#__pungaanalytics_page_status`.`last_status`
	),
	`last_seen_at` = GREATEST(
		`#__pungaanalytics_page_status`.`last_seen_at`,
		VALUES(`last_seen_at`)
	)
SQL;
		$db->setQuery($db->replacePrefix($rawSql))->execute();

		$rawErrorSql = <<<'SQL'
INSERT INTO `#__pungaanalytics_page_status` (
	`path_hash`, `path`, `last_status`, `last_seen_at`
)
SELECT
	SHA2(`event`.`path`, 256),
	`event`.`path`,
	`event`.`http_status`,
	`event`.`visited_at`
FROM `#__pungaanalytics_events` AS `event`
INNER JOIN (
	SELECT `path`, MAX(`id`) AS `latest_id`
	FROM `#__pungaanalytics_events`
	WHERE `event_type` = 'pageview'
		AND `http_status` >= 400
		AND `http_status` <= 599
	GROUP BY `path`
) AS `latest_error`
	ON `latest_error`.`latest_id` = `event`.`id`
ON DUPLICATE KEY UPDATE
	`path` = IF(
		VALUES(`last_seen_at`) >= `#__pungaanalytics_page_status`.`last_seen_at`,
		VALUES(`path`),
		`#__pungaanalytics_page_status`.`path`
	),
	`last_status` = IF(
		VALUES(`last_seen_at`) >= `#__pungaanalytics_page_status`.`last_seen_at`,
		VALUES(`last_status`),
		`#__pungaanalytics_page_status`.`last_status`
	),
	`last_seen_at` = GREATEST(
		`#__pungaanalytics_page_status`.`last_seen_at`,
		VALUES(`last_seen_at`)
	)
SQL;
		$db->setQuery($db->replacePrefix($rawErrorSql))->execute();

		$archived404Sql = <<<'SQL'
INSERT INTO `#__pungaanalytics_page_status` (
	`path_hash`, `path`, `last_status`, `last_seen_at`
)
SELECT
	SHA2(`path`, 256),
	`path`,
	404,
	MAX(`last_seen`)
FROM `#__pungaanalytics_daily_404`
GROUP BY `path`
ON DUPLICATE KEY UPDATE
	`path` = IF(
		VALUES(`last_seen_at`) >= `#__pungaanalytics_page_status`.`last_seen_at`,
		VALUES(`path`),
		`#__pungaanalytics_page_status`.`path`
	),
	`last_status` = IF(
		VALUES(`last_seen_at`) >= `#__pungaanalytics_page_status`.`last_seen_at`,
		404,
		`#__pungaanalytics_page_status`.`last_status`
	),
	`last_seen_at` = GREATEST(
		`#__pungaanalytics_page_status`.`last_seen_at`,
		VALUES(`last_seen_at`)
	)
SQL;
		$db->setQuery($db->replacePrefix($archived404Sql))->execute();
	}

	/**
	 * Discards the obsolete mixed-status page-path aggregate.
	 *
	 * That table did not preserve HTTP status, so contaminated historical counts
	 * cannot be repaired reliably. Other dimensions and all summary totals remain.
	 *
	 * @return void
	 */
	private function discardLegacyPathAggregates(): void
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->delete($db->quoteName('#__pungaanalytics_daily_dimensions'))
			->where($db->quoteName('dimension_key') . ' = ' . $db->quote('path'));

		$db->setQuery($query)->execute();
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
			->where($db->quoteName('element') . ' = ' . $db->quote('com_pungaanalytics'));
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
	FROM `#__pungaanalytics_events`
	WHERE `event_type` <> 'pageview'
	UNION
	SELECT `label` AS `event_type`
	FROM `#__pungaanalytics_daily_dimensions`
	WHERE `dimension_key` = 'event_type'
	UNION
	SELECT `event_type`
	FROM `#__pungaanalytics_daily_items`
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
