-- MelodyQuest migration 013
-- New lobbies should default to a human-friendly 80% answer similarity.

SET @mq_has_answer_similarity_threshold := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'mq_lobbies'
    AND column_name = 'answer_similarity_threshold'
);

SET @mq_update_answer_similarity_default := IF(
  @mq_has_answer_similarity_threshold = 1,
  'ALTER TABLE mq_lobbies MODIFY COLUMN answer_similarity_threshold TINYINT UNSIGNED NOT NULL DEFAULT 80',
  'SELECT 1'
);

PREPARE mq_stmt FROM @mq_update_answer_similarity_default;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;
