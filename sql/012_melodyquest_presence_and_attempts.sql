-- MelodyQuest player presence and answer attempts
-- Run after 011_melodyquest_round_preloads.sql

SET @mq_has_presence_status := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'mq_lobby_players'
    AND column_name = 'presence_status'
);

SET @mq_add_presence_status := IF(
  @mq_has_presence_status = 0,
  'ALTER TABLE mq_lobby_players ADD COLUMN presence_status VARCHAR(16) NOT NULL DEFAULT ''active'' AFTER score',
  'SELECT 1'
);

PREPARE mq_stmt FROM @mq_add_presence_status;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

SET @mq_has_removed_at := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'mq_lobby_players'
    AND column_name = 'removed_at'
);

SET @mq_add_removed_at := IF(
  @mq_has_removed_at = 0,
  'ALTER TABLE mq_lobby_players ADD COLUMN removed_at DATETIME(3) DEFAULT NULL AFTER last_seen_at',
  'SELECT 1'
);

PREPARE mq_stmt FROM @mq_add_removed_at;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

SET @mq_has_removed_by := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'mq_lobby_players'
    AND column_name = 'removed_by'
);

SET @mq_add_removed_by := IF(
  @mq_has_removed_by = 0,
  'ALTER TABLE mq_lobby_players ADD COLUMN removed_by INT DEFAULT NULL AFTER removed_at',
  'SELECT 1'
);

PREPARE mq_stmt FROM @mq_add_removed_by;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

SET @mq_has_presence_index := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'mq_lobby_players'
    AND index_name = 'idx_mq_lobby_players_presence'
);

SET @mq_add_presence_index := IF(
  @mq_has_presence_index = 0,
  'ALTER TABLE mq_lobby_players ADD KEY idx_mq_lobby_players_presence (lobby_id, presence_status, last_seen_at)',
  'SELECT 1'
);

PREPARE mq_stmt FROM @mq_add_presence_index;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

CREATE TABLE IF NOT EXISTS mq_round_answer_attempts (
  id BIGINT NOT NULL AUTO_INCREMENT,
  round_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  guess_title VARCHAR(220) DEFAULT NULL,
  guess_artist VARCHAR(220) DEFAULT NULL,
  is_correct TINYINT(1) NOT NULL DEFAULT 0,
  score_awarded INT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_mq_round_answer_attempts_round_created (round_id, created_at),
  KEY idx_mq_round_answer_attempts_user (user_id),
  CONSTRAINT fk_mq_round_answer_attempts_round
    FOREIGN KEY (round_id) REFERENCES mq_rounds(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_mq_round_answer_attempts_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
