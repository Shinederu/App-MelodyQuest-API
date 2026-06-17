-- MelodyQuest autoplay mode
-- Target DB: ShinedeCore
-- Idempotent migration

SET @mq_has_game_mode := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'mq_lobbies'
    AND column_name = 'game_mode'
);

SET @mq_add_game_mode := IF(
  @mq_has_game_mode = 0,
  'ALTER TABLE mq_lobbies ADD COLUMN game_mode ENUM(''participative'', ''autoplay'') NOT NULL DEFAULT ''participative'' AFTER visibility',
  'SELECT 1'
);

PREPARE mq_stmt FROM @mq_add_game_mode;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

UPDATE mq_lobbies
SET game_mode = 'participative'
WHERE game_mode IS NULL;
