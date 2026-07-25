ALTER TABLE `#__pungaanalytics_events`
	ADD `traffic_source` VARCHAR(16) NOT NULL DEFAULT '' AFTER `referrer_host`,
	ADD `http_status` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `item_title`,
	ADD KEY `idx_pungaanalytics_source_date` (`traffic_source`, `visit_date`),
	ADD KEY `idx_pungaanalytics_status_date` (`http_status`, `visit_date`);

UPDATE `#__pungaanalytics_events`
SET `traffic_source` = CASE
	WHEN `referrer_host` = '' THEN 'unknown'
	WHEN `referrer_host` LIKE '%chatgpt.com'
		OR `referrer_host` LIKE '%openai.com'
		OR `referrer_host` LIKE '%perplexity.ai'
		OR `referrer_host` LIKE '%claude.ai'
		OR `referrer_host` LIKE '%gemini.google.com'
		OR `referrer_host` LIKE '%copilot.microsoft.com'
		OR `referrer_host` LIKE '%poe.com'
		OR `referrer_host` LIKE '%you.com'
		OR `referrer_host` LIKE '%phind.com'
		OR `referrer_host` LIKE '%mistral.ai' THEN 'ai'
	WHEN `referrer_host` LIKE '%facebook.com'
		OR `referrer_host` LIKE '%instagram.com'
		OR `referrer_host` LIKE '%threads.net'
		OR `referrer_host` LIKE '%twitter.com'
		OR `referrer_host` LIKE '%x.com'
		OR `referrer_host` LIKE '%t.co'
		OR `referrer_host` LIKE '%linkedin.com'
		OR `referrer_host` LIKE '%reddit.com'
		OR `referrer_host` LIKE '%pinterest.com'
		OR `referrer_host` LIKE '%bsky.app'
		OR `referrer_host` LIKE '%youtube.com'
		OR `referrer_host` LIKE '%tiktok.com'
		OR `referrer_host` LIKE '%t.me'
		OR `referrer_host` LIKE '%telegram.me'
		OR `referrer_host` LIKE '%whatsapp.com' THEN 'social'
	WHEN `referrer_host` LIKE '%google.%'
		OR `referrer_host` LIKE '%bing.com'
		OR `referrer_host` LIKE '%duckduckgo.com'
		OR `referrer_host` LIKE '%search.yahoo.%'
		OR `referrer_host` LIKE '%yandex.%'
		OR `referrer_host` LIKE '%ecosia.org'
		OR `referrer_host` LIKE '%startpage.com'
		OR `referrer_host` LIKE '%search.brave.com'
		OR `referrer_host` LIKE '%baidu.com'
		OR `referrer_host` LIKE '%qwant.com' THEN 'search'
	ELSE 'referral'
END
WHERE `event_type` = 'pageview';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

INSERT INTO `#__pungaanalytics_daily_dimensions` (
	`visit_date`, `dimension_key`, `label_hash`, `label`, `event_count`
)
SELECT
	`visit_date`,
	'traffic_source',
	SHA2(`source_category`, 256),
	`source_category`,
	SUM(`event_count`)
FROM (
	SELECT
		`visit_date`,
		`event_count`,
		CASE
			WHEN `label` LIKE '%chatgpt.com'
				OR `label` LIKE '%openai.com'
				OR `label` LIKE '%perplexity.ai'
				OR `label` LIKE '%claude.ai'
				OR `label` LIKE '%gemini.google.com'
				OR `label` LIKE '%copilot.microsoft.com'
				OR `label` LIKE '%poe.com'
				OR `label` LIKE '%you.com'
				OR `label` LIKE '%phind.com'
				OR `label` LIKE '%mistral.ai' THEN 'ai'
			WHEN `label` LIKE '%facebook.com'
				OR `label` LIKE '%instagram.com'
				OR `label` LIKE '%threads.net'
				OR `label` LIKE '%twitter.com'
				OR `label` LIKE '%x.com'
				OR `label` LIKE '%t.co'
				OR `label` LIKE '%linkedin.com'
				OR `label` LIKE '%reddit.com'
				OR `label` LIKE '%pinterest.com'
				OR `label` LIKE '%bsky.app'
				OR `label` LIKE '%youtube.com'
				OR `label` LIKE '%tiktok.com'
				OR `label` LIKE '%t.me'
				OR `label` LIKE '%telegram.me'
				OR `label` LIKE '%whatsapp.com' THEN 'social'
			WHEN `label` LIKE '%google.%'
				OR `label` LIKE '%bing.com'
				OR `label` LIKE '%duckduckgo.com'
				OR `label` LIKE '%search.yahoo.%'
				OR `label` LIKE '%yandex.%'
				OR `label` LIKE '%ecosia.org'
				OR `label` LIKE '%startpage.com'
				OR `label` LIKE '%search.brave.com'
				OR `label` LIKE '%baidu.com'
				OR `label` LIKE '%qwant.com' THEN 'search'
			ELSE 'referral'
		END AS `source_category`
	FROM `#__pungaanalytics_daily_dimensions`
	WHERE `dimension_key` = 'referrer'
) AS `legacy_sources`
GROUP BY `visit_date`, `source_category`
ON DUPLICATE KEY UPDATE
	`event_count` = GREATEST(`event_count`, VALUES(`event_count`));
