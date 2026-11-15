
CREATE TABLE IF NOT EXISTS `#__pungaanalytics_daily_sessions` (
	`visit_date` DATE NOT NULL,
	`session_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`duration_seconds` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`pageview_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`custom_event_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`page_session_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`single_page_sessions` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__pungaanalytics_daily_session_events` (
	`visit_date` DATE NOT NULL,
	`event_type` VARCHAR(64) NOT NULL,
	`event_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`session_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`multi_event_sessions` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`, `event_type`),
	KEY `idx_pungaanalytics_session_event_type` (`event_type`, `visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

