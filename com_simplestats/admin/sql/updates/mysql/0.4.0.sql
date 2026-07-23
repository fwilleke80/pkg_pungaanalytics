CREATE TABLE IF NOT EXISTS `#__simplestats_daily` (
	`visit_date` DATE NOT NULL,
	`human_visits` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`human_pageviews` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`authenticated_pageviews` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`german_visits` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`bot_pageviews` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`plays` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`downloads` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`custom_events` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__simplestats_daily_dimensions` (
	`visit_date` DATE NOT NULL,
	`dimension_key` VARCHAR(32) NOT NULL,
	`label_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	`label` VARCHAR(1024) NOT NULL,
	`event_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`, `dimension_key`, `label_hash`),
	KEY `idx_simplestats_dimension_date` (`dimension_key`, `visit_date`),
	KEY `idx_simplestats_dimension_label` (`dimension_key`, `label`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
