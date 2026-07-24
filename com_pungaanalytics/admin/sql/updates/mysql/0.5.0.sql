CREATE TABLE IF NOT EXISTS `#__pungaanalytics_daily_time` (
	`visit_date` DATE NOT NULL,
	`bucket_kind` VARCHAR(8) NOT NULL,
	`bucket_value` TINYINT UNSIGNED NOT NULL,
	`human_visits` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`human_pageviews` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`bot_pageviews` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`plays` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	`downloads` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`, `bucket_kind`, `bucket_value`),
	KEY `idx_pungaanalytics_time_kind_date` (`bucket_kind`, `visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

INSERT INTO `#__pungaanalytics_daily_time` (
	`visit_date`, `bucket_kind`, `bucket_value`, `human_visits`,
	`human_pageviews`, `bot_pageviews`, `plays`, `downloads`
)
SELECT
	`visit_date`, 'weekday', WEEKDAY(`visit_date`) + 1, `human_visits`,
	`human_pageviews`, `bot_pageviews`, `plays`, `downloads`
FROM `#__pungaanalytics_daily`
ON DUPLICATE KEY UPDATE
	`human_visits` = VALUES(`human_visits`),
	`human_pageviews` = VALUES(`human_pageviews`),
	`bot_pageviews` = VALUES(`bot_pageviews`),
	`plays` = VALUES(`plays`),
	`downloads` = VALUES(`downloads`);
