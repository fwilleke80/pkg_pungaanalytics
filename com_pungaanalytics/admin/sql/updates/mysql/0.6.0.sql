CREATE TABLE IF NOT EXISTS `#__pungaanalytics_daily_event_time` (
	`visit_date` DATE NOT NULL,
	`event_type` VARCHAR(64) NOT NULL,
	`bucket_kind` VARCHAR(8) NOT NULL,
	`bucket_value` TINYINT UNSIGNED NOT NULL,
	`event_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`visit_date`, `event_type`, `bucket_kind`, `bucket_value`),
	KEY `idx_pungaanalytics_event_time_type` (`event_type`, `bucket_kind`, `visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

INSERT INTO `#__pungaanalytics_daily_event_time` (
	`visit_date`, `event_type`, `bucket_kind`, `bucket_value`, `event_count`
)
SELECT `visit_date`, 'audio.play', `bucket_kind`, `bucket_value`, `plays`
FROM `#__pungaanalytics_daily_time`
WHERE `plays` > 0
ON DUPLICATE KEY UPDATE
	`event_count` = GREATEST(`event_count`, VALUES(`event_count`));

INSERT INTO `#__pungaanalytics_daily_event_time` (
	`visit_date`, `event_type`, `bucket_kind`, `bucket_value`, `event_count`
)
SELECT `visit_date`, 'audio.download', `bucket_kind`, `bucket_value`, `downloads`
FROM `#__pungaanalytics_daily_time`
WHERE `downloads` > 0
ON DUPLICATE KEY UPDATE
	`event_count` = GREATEST(`event_count`, VALUES(`event_count`));
