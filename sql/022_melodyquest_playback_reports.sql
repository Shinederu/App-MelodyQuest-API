-- Additive migration. No catalogue, scores or history are removed.
ALTER TABLE mq_player_suggestions
  MODIFY suggestion_type ENUM('track_correction', 'new_track', 'track_removal', 'video_unavailable') NOT NULL;

SET @mq_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='mq_player_suggestions' AND column_name='automatic_report_key')=0,
  'ALTER TABLE mq_player_suggestions ADD COLUMN automatic_report_key VARCHAR(64) NULL, ADD UNIQUE KEY uq_mq_automatic_report (automatic_report_key)', 'SELECT 1');
PREPARE mq_stmt FROM @mq_sql;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

SET @mq_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='mq_rounds' AND column_name='unavailable_skip_at')=0,
  'ALTER TABLE mq_rounds ADD COLUMN unavailable_skip_at DATETIME(3) NULL', 'SELECT 1');
PREPARE mq_stmt FROM @mq_sql;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;
