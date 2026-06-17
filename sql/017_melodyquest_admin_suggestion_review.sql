-- MelodyQuest admin review helpers
-- Run after 016_melodyquest_category_visible_default.sql

SET @mq_has_admin_category_id := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'mq_player_suggestions'
    AND column_name = 'admin_category_id'
);

SET @mq_add_admin_category_id := IF(
  @mq_has_admin_category_id = 0,
  'ALTER TABLE mq_player_suggestions ADD COLUMN admin_category_id INT DEFAULT NULL AFTER proposed_alias',
  'SELECT 1'
);

PREPARE mq_stmt FROM @mq_add_admin_category_id;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

SET @mq_has_admin_family_name := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'mq_player_suggestions'
    AND column_name = 'admin_family_name'
);

SET @mq_add_admin_family_name := IF(
  @mq_has_admin_family_name = 0,
  'ALTER TABLE mq_player_suggestions ADD COLUMN admin_family_name VARCHAR(160) DEFAULT NULL AFTER admin_category_id',
  'SELECT 1'
);

PREPARE mq_stmt FROM @mq_add_admin_family_name;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

SET @mq_has_admin_start_offset_seconds := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'mq_player_suggestions'
    AND column_name = 'admin_start_offset_seconds'
);

SET @mq_add_admin_start_offset_seconds := IF(
  @mq_has_admin_start_offset_seconds = 0,
  'ALTER TABLE mq_player_suggestions ADD COLUMN admin_start_offset_seconds INT DEFAULT NULL AFTER admin_family_name',
  'SELECT 1'
);

PREPARE mq_stmt FROM @mq_add_admin_start_offset_seconds;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

SET @mq_has_applied_track_id := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'mq_player_suggestions'
    AND column_name = 'applied_track_id'
);

SET @mq_add_applied_track_id := IF(
  @mq_has_applied_track_id = 0,
  'ALTER TABLE mq_player_suggestions ADD COLUMN applied_track_id INT DEFAULT NULL AFTER reviewed_by_user_id',
  'SELECT 1'
);

PREPARE mq_stmt FROM @mq_add_applied_track_id;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

SET @mq_has_applied_at := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'mq_player_suggestions'
    AND column_name = 'applied_at'
);

SET @mq_add_applied_at := IF(
  @mq_has_applied_at = 0,
  'ALTER TABLE mq_player_suggestions ADD COLUMN applied_at DATETIME(3) DEFAULT NULL AFTER applied_track_id',
  'SELECT 1'
);

PREPARE mq_stmt FROM @mq_add_applied_at;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;

SET @mq_has_suggestions_applied_index := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'mq_player_suggestions'
    AND index_name = 'idx_mq_player_suggestions_applied_track'
);

SET @mq_add_suggestions_applied_index := IF(
  @mq_has_suggestions_applied_index = 0,
  'ALTER TABLE mq_player_suggestions ADD KEY idx_mq_player_suggestions_applied_track (applied_track_id)',
  'SELECT 1'
);

PREPARE mq_stmt FROM @mq_add_suggestions_applied_index;
EXECUTE mq_stmt;
DEALLOCATE PREPARE mq_stmt;
