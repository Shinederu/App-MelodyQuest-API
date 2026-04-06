-- MelodyQuest track validation
-- Target DB: ShinedeCore
-- Idempotent migration for existing installs

SET @mq_tracks_add_is_validated_sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'mq_tracks'
      AND column_name = 'is_validated'
  ),
  'SELECT 1',
  'ALTER TABLE mq_tracks ADD COLUMN is_validated TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active'
);
PREPARE mq_tracks_add_is_validated_stmt FROM @mq_tracks_add_is_validated_sql;
EXECUTE mq_tracks_add_is_validated_stmt;
DEALLOCATE PREPARE mq_tracks_add_is_validated_stmt;

SET @mq_tracks_add_validated_by_sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'mq_tracks'
      AND column_name = 'validated_by'
  ),
  'SELECT 1',
  'ALTER TABLE mq_tracks ADD COLUMN validated_by INT DEFAULT NULL AFTER is_validated'
);
PREPARE mq_tracks_add_validated_by_stmt FROM @mq_tracks_add_validated_by_sql;
EXECUTE mq_tracks_add_validated_by_stmt;
DEALLOCATE PREPARE mq_tracks_add_validated_by_stmt;

SET @mq_tracks_add_validated_at_sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'mq_tracks'
      AND column_name = 'validated_at'
  ),
  'SELECT 1',
  'ALTER TABLE mq_tracks ADD COLUMN validated_at DATETIME DEFAULT NULL AFTER validated_by'
);
PREPARE mq_tracks_add_validated_at_stmt FROM @mq_tracks_add_validated_at_sql;
EXECUTE mq_tracks_add_validated_at_stmt;
DEALLOCATE PREPARE mq_tracks_add_validated_at_stmt;

SET @mq_tracks_validation_index_sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'mq_tracks'
      AND index_name = 'idx_mq_tracks_validation'
  ),
  'SELECT 1',
  'ALTER TABLE mq_tracks ADD KEY idx_mq_tracks_validation (is_validated, is_active)'
);
PREPARE mq_tracks_validation_index_stmt FROM @mq_tracks_validation_index_sql;
EXECUTE mq_tracks_validation_index_stmt;
DEALLOCATE PREPARE mq_tracks_validation_index_stmt;

SET @mq_tracks_validated_by_fk_sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'mq_tracks'
      AND constraint_name = 'fk_mq_tracks_validated_by'
      AND constraint_type = 'FOREIGN KEY'
  ),
  'SELECT 1',
  'ALTER TABLE mq_tracks ADD CONSTRAINT fk_mq_tracks_validated_by FOREIGN KEY (validated_by) REFERENCES users(id) ON DELETE SET NULL'
);
PREPARE mq_tracks_validated_by_fk_stmt FROM @mq_tracks_validated_by_fk_sql;
EXECUTE mq_tracks_validated_by_fk_stmt;
DEALLOCATE PREPARE mq_tracks_validated_by_fk_stmt;

UPDATE mq_tracks
SET is_validated = 1,
    validated_at = COALESCE(validated_at, updated_at, created_at, NOW())
WHERE is_validated = 0;
