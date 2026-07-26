-- Remove path aggregates that were created from legacy status-zero page views
-- when the same path is known only as a 404 response.
DELETE `dimensions`
FROM `#__pungaanalytics_daily_dimensions` AS `dimensions`
WHERE `dimensions`.`dimension_key` = 'path'
	AND (
		EXISTS (
			SELECT 1
			FROM `#__pungaanalytics_events` AS `raw_not_found`
			WHERE `raw_not_found`.`event_type` = 'pageview'
				AND `raw_not_found`.`http_status` = 404
				AND CAST(`raw_not_found`.`path` AS BINARY) = CAST(`dimensions`.`label` AS BINARY)
		)
		OR EXISTS (
			SELECT 1
			FROM `#__pungaanalytics_daily_404` AS `archived_not_found`
			WHERE CAST(`archived_not_found`.`path` AS BINARY) = CAST(`dimensions`.`label` AS BINARY)
		)
	)
	AND NOT EXISTS (
		SELECT 1
		FROM `#__pungaanalytics_events` AS `successful_page`
		WHERE `successful_page`.`event_type` = 'pageview'
			AND `successful_page`.`http_status` >= 200
			AND `successful_page`.`http_status` < 400
			AND CAST(`successful_page`.`path` AS BINARY) = CAST(`dimensions`.`label` AS BINARY)
	);
