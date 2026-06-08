-- MelodyQuest answer similarity threshold
-- Run after 007_melodyquest_game_options.sql

SET @mq_has_answer_similarity_threshold := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'mq_lobbies'
    AND column_name = 'answer_similarity_threshold'
);

SET @mq_add_answer_similarity_threshold := IF(
  @mq_has_answer_similarity_threshold = 0,
  'ALTER TABLE mq_lobbies ADD COLUMN answer_similarity_threshold TINYINT UNSIGNED NOT NULL DEFAULT 100 AFTER allow_early_reveal_vote',
  'SELECT 1'
);

PREPARE mq_stmt FROM @mq_add_answer_similarity_threshold;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;
