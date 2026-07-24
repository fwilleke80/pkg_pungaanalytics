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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `#__simplestats_daily_time` (
	`visit_date` DATE NOT NULL,
	`bucket_kind` VARCHAR(8) NOT NULL,
	`bucket_value` TINYINT UNSIGNED NOT NULL,
	`human_visits` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`human_pageviews` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`bot_pageviews` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`plays` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`downloads` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`, `bucket_kind`, `bucket_value`),
	KEY `idx_simplestats_time_kind_date` (`bucket_kind`, `visit_date`)
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
