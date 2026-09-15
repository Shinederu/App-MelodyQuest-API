-- Explicit product reset: all work estimates start at 60; real votes are retained.
-- The column default is the one-time marker; reruns preserve later admin edits.
SET @mq_reset_seed = (SELECT column_default FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'mq_families'
    AND column_name = 'notoriety_seed') = '50';
SET @mq_ddl = IF(@mq_reset_seed,
  'UPDATE mq_families SET notoriety_seed = 60, updated_at = updated_at', 'SELECT 1');
PREPARE mq_stmt FROM @mq_ddl;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;
ALTER TABLE mq_families ALTER COLUMN notoriety_seed SET DEFAULT 60;
