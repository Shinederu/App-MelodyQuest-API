-- Playback bounds already exist on mq_tracks. Preserve all existing data.

SET @mq_ddl = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_tracks' AND column_name = 'familiarity') = 0,
  'ALTER TABLE mq_tracks ADD COLUMN familiarity TINYINT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE mq_stmt FROM @mq_ddl;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

SET @mq_ddl = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_lobbies' AND column_name = 'min_familiarity') = 0,
  'ALTER TABLE mq_lobbies ADD COLUMN min_familiarity TINYINT UNSIGNED NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE mq_stmt FROM @mq_ddl;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

SET @mq_ddl = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_player_suggestions' AND column_name = 'proposed_family_name') = 0,
  'ALTER TABLE mq_player_suggestions ADD COLUMN proposed_family_name VARCHAR(160) DEFAULT NULL', 'SELECT 1');
PREPARE mq_stmt FROM @mq_ddl;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

SET @mq_ddl = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_player_suggestions' AND column_name = 'proposed_start_offset_seconds') = 0,
  'ALTER TABLE mq_player_suggestions ADD COLUMN proposed_start_offset_seconds INT DEFAULT NULL', 'SELECT 1');
PREPARE mq_stmt FROM @mq_ddl;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

SET @mq_ddl = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_player_suggestions' AND column_name = 'proposed_end_offset_seconds') = 0,
  'ALTER TABLE mq_player_suggestions ADD COLUMN proposed_end_offset_seconds INT DEFAULT NULL', 'SELECT 1');
PREPARE mq_stmt FROM @mq_ddl;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

SET @mq_ddl = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_player_suggestions' AND column_name = 'admin_end_offset_seconds') = 0,
  'ALTER TABLE mq_player_suggestions ADD COLUMN admin_end_offset_seconds INT DEFAULT NULL', 'SELECT 1');
PREPARE mq_stmt FROM @mq_ddl;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

ALTER TABLE mq_lobbies ALTER COLUMN total_rounds SET DEFAULT 30, ALTER COLUMN round_duration_seconds SET DEFAULT 20;
