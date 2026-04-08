DROP PROCEDURE IF EXISTS `backfill_support_routing_run_candidates`;

DELIMITER $$

CREATE PROCEDURE `backfill_support_routing_run_candidates`()
BEGIN
  DECLARE `done` TINYINT(1) DEFAULT 0;
  DECLARE `run_id` BIGINT UNSIGNED;
  DECLARE `winner_user_id` INT;
  DECLARE `candidate_scores_value` LONGTEXT;
  DECLARE `summary_value` LONGTEXT;
  DECLARE `new_candidate_scores` LONGTEXT;
  DECLARE `new_summary` LONGTEXT;
  DECLARE `search_pos` INT;
  DECLARE `candidate_pos` INT;
  DECLARE `value_start` INT;
  DECLARE `value_end` INT;
  DECLARE `candidate_id` INT;
  DECLARE `candidate_token` VARCHAR(64);
  DECLARE `candidate_delimiter` CHAR(1);
  DECLARE `candidate_username` VARCHAR(255);
  DECLARE `winner_label` VARCHAR(255);
  DECLARE `escaped_candidate_username` VARCHAR(1024);
  DECLARE `escaped_winner_label` VARCHAR(1024);
  DECLARE `candidate_json` VARCHAR(2048);

  DECLARE `run_cursor` CURSOR FOR
    SELECT `id`, `winner_user_id`, `candidate_scores_json`, `summary_json`
    FROM `support_ticket_routing_runs`
    WHERE (
        `candidate_scores_json` IS NOT NULL
        AND TRIM(`candidate_scores_json`) <> ''
        AND `candidate_scores_json` LIKE '%"candidate_id":%'
        AND `candidate_scores_json` NOT LIKE '%"candidate":{"id":%'
      )
      OR (
        `winner_user_id` IS NOT NULL
        AND `winner_user_id` > 0
        AND (
          `summary_json` IS NULL
          OR TRIM(`summary_json`) = ''
          OR `summary_json` NOT LIKE '%"winner_label"%'
        )
      );

  DECLARE CONTINUE HANDLER FOR NOT FOUND SET `done` = 1;

  OPEN `run_cursor`;

  read_loop: LOOP
    FETCH `run_cursor` INTO `run_id`, `winner_user_id`, `candidate_scores_value`, `summary_value`;
    IF `done` = 1 THEN
      LEAVE read_loop;
    END IF;

    SET `new_candidate_scores` = `candidate_scores_value`;
    SET `new_summary` = `summary_value`;

    IF `new_candidate_scores` IS NOT NULL
      AND TRIM(`new_candidate_scores`) <> ''
      AND `new_candidate_scores` LIKE '%"candidate_id":%'
      AND `new_candidate_scores` NOT LIKE '%"candidate":{"id":%' THEN
      SET `search_pos` = 1;

      candidate_loop: LOOP
        SET `candidate_pos` = LOCATE('"candidate_id":', `new_candidate_scores`, `search_pos`);
        IF `candidate_pos` = 0 THEN
          LEAVE candidate_loop;
        END IF;

        SET `value_start` = `candidate_pos` + CHAR_LENGTH('"candidate_id":');
        SET `candidate_token` = TRIM(
          SUBSTRING_INDEX(
            SUBSTRING_INDEX(SUBSTRING(`new_candidate_scores`, `value_start`), ',', 1),
            '}',
            1
          )
        );
        SET `candidate_id` = CAST(`candidate_token` AS UNSIGNED);
        SET `value_end` = `value_start` + CHAR_LENGTH(`candidate_token`);
        SET `candidate_delimiter` = SUBSTRING(`new_candidate_scores`, `value_end`, 1);

        IF `candidate_id` > 0 THEN
          SET `candidate_username` = COALESCE(
            (
              SELECT COALESCE(`username`, `email`)
              FROM `users`
              WHERE `id` = `candidate_id`
              LIMIT 1
            ),
            CONCAT('User #', `candidate_id`)
          );

          IF `candidate_username` IS NULL OR TRIM(`candidate_username`) = '' THEN
            SET `candidate_username` = CONCAT('User #', `candidate_id`);
          END IF;

          SET `escaped_candidate_username` = REPLACE(REPLACE(REPLACE(REPLACE(`candidate_username`, '\\', '\\\\'), '"', '\\"'), CHAR(13), ' '), CHAR(10), ' ');
          SET `candidate_json` = CONCAT('"candidate":{"id":', `candidate_id`, ',"username":"', `escaped_candidate_username`, '"},"candidate_id":', `candidate_id`, `candidate_delimiter`);
          SET `new_candidate_scores` = REPLACE(`new_candidate_scores`, CONCAT('"candidate_id":', `candidate_token`, `candidate_delimiter`), `candidate_json`);
          SET `search_pos` = LOCATE(`candidate_json`, `new_candidate_scores`, `candidate_pos`) + CHAR_LENGTH(`candidate_json`);
        ELSE
          SET `search_pos` = `value_start`;
        END IF;
      END LOOP;
    END IF;

    IF `winner_user_id` IS NOT NULL
      AND `winner_user_id` > 0
      AND (
        `new_summary` IS NULL
        OR TRIM(`new_summary`) = ''
        OR `new_summary` NOT LIKE '%"winner_label"%'
      ) THEN
      SET `winner_label` = COALESCE(
        (
          SELECT COALESCE(`username`, `email`)
          FROM `users`
          WHERE `id` = `winner_user_id`
          LIMIT 1
        ),
        CONCAT('User #', `winner_user_id`)
      );

      IF `winner_label` IS NULL OR TRIM(`winner_label`) = '' THEN
        SET `winner_label` = CONCAT('User #', `winner_user_id`);
      END IF;

      SET `escaped_winner_label` = REPLACE(REPLACE(REPLACE(REPLACE(`winner_label`, '\\', '\\\\'), '"', '\\"'), CHAR(13), ' '), CHAR(10), ' ');

      IF `new_summary` IS NULL OR TRIM(`new_summary`) = '' THEN
        SET `new_summary` = CONCAT('{"winner_label":"', `escaped_winner_label`, '"}');
      ELSEIF RIGHT(TRIM(`new_summary`), 1) = '}' THEN
        SET `new_summary` = CONCAT(
          SUBSTRING(TRIM(`new_summary`), 1, CHAR_LENGTH(TRIM(`new_summary`)) - 1),
          ',"winner_label":"',
          `escaped_winner_label`,
          '"}'
        );
      END IF;
    END IF;

    UPDATE `support_ticket_routing_runs`
    SET
      `candidate_scores_json` = `new_candidate_scores`,
      `summary_json` = `new_summary`
    WHERE `id` = `run_id`;
  END LOOP;

  CLOSE `run_cursor`;
END$$

DELIMITER ;

CALL `backfill_support_routing_run_candidates`();

DROP PROCEDURE IF EXISTS `backfill_support_routing_run_candidates`;
