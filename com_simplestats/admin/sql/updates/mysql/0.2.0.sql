ALTER TABLE `#__simplestats_events`
	MODIFY COLUMN `event_type` VARCHAR(64) NOT NULL DEFAULT 'pageview';
