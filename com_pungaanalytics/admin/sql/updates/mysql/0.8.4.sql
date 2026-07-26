UPDATE `#__pungaanalytics_daily_dimensions` AS `dimensions`
INNER JOIN (
	SELECT
		`visit_date`,
		UNHEX(SHA2(`path`, 256)) AS `label_hash_binary`,
		SUM(`request_count`) AS `not_found_count`
	FROM `#__pungaanalytics_daily_404`
	WHERE `is_bot` = 0
	GROUP BY `visit_date`, UNHEX(SHA2(`path`, 256))
) AS `not_found`
	ON `not_found`.`visit_date` = `dimensions`.`visit_date`
	AND `not_found`.`label_hash_binary` = UNHEX(`dimensions`.`label_hash`)
SET `dimensions`.`event_count` = CASE
	WHEN `dimensions`.`event_count` > `not_found`.`not_found_count`
		THEN `dimensions`.`event_count` - `not_found`.`not_found_count`
	ELSE 0
END
WHERE `dimensions`.`dimension_key` = 'path';

DELETE FROM `#__pungaanalytics_daily_dimensions`
WHERE `dimension_key` = 'path'
	AND `event_count` = 0;
