-- Additive conversion. Do not reset validations, votes or historical ratings.
SET @mq_new_seed = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_families' AND column_name = 'notoriety_seed') = 0;
SET @mq_ddl = IF(@mq_new_seed,
  'ALTER TABLE mq_families ADD COLUMN notoriety_seed TINYINT UNSIGNED NOT NULL DEFAULT 50', 'SELECT 1');
PREPARE mq_stmt FROM @mq_ddl;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

-- Seed existing works from the mean historical rating, snapped to the closest preset.
SET @mq_ddl = IF(@mq_new_seed,
  'UPDATE mq_families f JOIN (SELECT family_id, AVG(familiarity) * 10 AS rating FROM mq_tracks WHERE familiarity IS NOT NULL GROUP BY family_id) r ON r.family_id = f.id SET f.notoriety_seed = CASE WHEN r.rating >= 87.5 THEN 100 WHEN r.rating >= 62.5 THEN 75 ELSE 50 END', 'SELECT 1');
PREPARE mq_stmt FROM @mq_ddl;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

SET @mq_new_min = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_lobbies' AND column_name = 'min_notoriety') = 0;
SET @mq_ddl = IF(@mq_new_min,
  'ALTER TABLE mq_lobbies ADD COLUMN min_notoriety TINYINT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE mq_stmt FROM @mq_ddl;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

SET @mq_ddl = IF(@mq_new_min,
  'UPDATE mq_lobbies SET min_notoriety = CASE WHEN min_familiarity <= 1 THEN 0 WHEN min_familiarity >= 9 THEN 90 ELSE 60 END', 'SELECT 1');
PREPARE mq_stmt FROM @mq_ddl;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;
