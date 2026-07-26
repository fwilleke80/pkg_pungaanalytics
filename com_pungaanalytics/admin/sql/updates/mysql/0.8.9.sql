CREATE TABLE IF NOT EXISTS `#__pungaanalytics_daily_pages` (
	`visit_date` DATE NOT NULL,
	`path_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	`path` VARCHAR(1024) NOT NULL,
	`pageview_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`, `path_hash`),
	KEY `idx_pungaanalytics_daily_pages_date` (`visit_date`),
	KEY `idx_pungaanalytics_daily_pages_path` (`path`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__pungaanalytics_page_status` (
	`path_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	`path` VARCHAR(1024) NOT NULL,
	`last_status` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	`last_seen_at` DATETIME NOT NULL,
	PRIMARY KEY (`path_hash`),
	KEY `idx_pungaanalytics_page_status_status` (`last_status`),
	KEY `idx_pungaanalytics_page_status_path` (`path`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

-- The old path aggregate did not preserve HTTP status and cannot be repaired
-- reliably. It is no longer used by the page ranking.
DELETE FROM `#__pungaanalytics_daily_dimensions`
WHERE `dimension_key` = 'path';
