-- MelodyQuest game/lobby options
-- Run after 006_melodyquest_merge_duplicate_categories.sql

SET @mq_has_show_track_category := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'mq_lobbies'
    AND column_name = 'show_track_category'
);

SET @mq_add_show_track_category := IF(
  @mq_has_show_track_category = 0,
  'ALTER TABLE mq_lobbies ADD COLUMN show_track_category TINYINT(1) NOT NULL DEFAULT 0 AFTER selected_category_ids',
  'SELECT 1'
);

PREPARE mq_stmt FROM @mq_add_show_track_category;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

SET @mq_has_early_reveal_vote := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'mq_lobbies'
    AND column_name = 'allow_early_reveal_vote'
);

SET @mq_add_early_reveal_vote := IF(
  @mq_has_early_reveal_vote = 0,
  'ALTER TABLE mq_lobbies ADD COLUMN allow_early_reveal_vote TINYINT(1) NOT NULL DEFAULT 1 AFTER show_track_category',
  'SELECT 1'
);

PREPARE mq_stmt FROM @mq_add_early_reveal_vote;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

CREATE TABLE IF NOT EXISTS mq_round_reveal_votes (
  round_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  voted_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (round_id, user_id),
  KEY idx_mq_round_reveal_votes_user (user_id),
  CONSTRAINT fk_mq_round_reveal_votes_round
    FOREIGN KEY (round_id) REFERENCES mq_rounds(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_mq_round_reveal_votes_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
