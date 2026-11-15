-- Punga Analytics 0.11.1 data-label cleanup; schema itself is unchanged.
UPDATE `#__pungaanalytics_events`
SET `bot_name` = 'Unclassified bot (legacy)'
WHERE `bot_name` = 'Other bot';

UPDATE `#__pungaanalytics_daily_dimensions`
SET `label` = 'Unclassified bot (legacy)',
    `label_hash` = SHA2('Unclassified bot (legacy)', 256)
WHERE `dimension_key` = 'bot' AND `label` = 'Other bot';
