-- MelodyQuest tracks store YouTube video IDs only
-- Target DB: ShinedeCore
-- Idempotent migration for existing installs

SET @mq_tracks_add_video_id_sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'mq_tracks'
      AND column_name = 'youtube_video_id'
  ),
  'SELECT 1',
  'ALTER TABLE mq_tracks ADD COLUMN youtube_video_id VARCHAR(32) DEFAULT NULL AFTER artist'
);
PREPARE mq_tracks_add_video_id_stmt FROM @mq_tracks_add_video_id_sql;
EXECUTE mq_tracks_add_video_id_stmt;
DEALLOCATE PREPARE mq_tracks_add_video_id_stmt;

SET @mq_tracks_video_id_index_sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'mq_tracks'
      AND index_name = 'idx_mq_tracks_video_id'
  ),
  'SELECT 1',
  'ALTER TABLE mq_tracks ADD KEY idx_mq_tracks_video_id (youtube_video_id)'
);
PREPARE mq_tracks_video_id_index_stmt FROM @mq_tracks_video_id_index_sql;
EXECUTE mq_tracks_video_id_index_stmt;
DEALLOCATE PREPARE mq_tracks_video_id_index_stmt;

SET @mq_tracks_backfill_video_id_sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'mq_tracks'
      AND column_name = 'youtube_url'
  ),
  'UPDATE mq_tracks
   SET youtube_video_id = TRIM(
     CASE
       WHEN COALESCE(TRIM(youtube_video_id), '''') <> '''' THEN TRIM(youtube_video_id)
       WHEN COALESCE(TRIM(youtube_url), '''') = '''' THEN ''''
       WHEN youtube_url LIKE ''%youtu.be/%'' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(youtube_url, ''youtu.be/'', -1), ''?'', 1), ''&'', 1), ''/'', 1)
       WHEN youtube_url LIKE ''%watch?v=%'' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(youtube_url, ''watch?v='', -1), ''&'', 1), ''#'', 1), ''/'', 1)
       WHEN youtube_url LIKE ''%/embed/%'' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(youtube_url, ''/embed/'', -1), ''?'', 1), ''&'', 1), ''/'', 1)
       WHEN youtube_url LIKE ''%/shorts/%'' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(youtube_url, ''/shorts/'', -1), ''?'', 1), ''&'', 1), ''/'', 1)
       WHEN youtube_url LIKE ''%/live/%'' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(youtube_url, ''/live/'', -1), ''?'', 1), ''&'', 1), ''/'', 1)
       ELSE ''''
     END
   )
   WHERE COALESCE(TRIM(youtube_video_id), '''') = ''''
     AND COALESCE(TRIM(youtube_url), '''') <> ''''',
  'SELECT 1'
);
PREPARE mq_tracks_backfill_video_id_stmt FROM @mq_tracks_backfill_video_id_sql;
EXECUTE mq_tracks_backfill_video_id_stmt;
DEALLOCATE PREPARE mq_tracks_backfill_video_id_stmt;

SET @mq_tracks_require_video_id_sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'mq_tracks'
      AND column_name = 'youtube_video_id'
      AND is_nullable = 'YES'
  )
  AND NOT EXISTS(
    SELECT 1
    FROM mq_tracks
    WHERE COALESCE(TRIM(youtube_video_id), '') = ''
  ),
  'ALTER TABLE mq_tracks MODIFY COLUMN youtube_video_id VARCHAR(32) NOT NULL',
  'SELECT 1'
);
PREPARE mq_tracks_require_video_id_stmt FROM @mq_tracks_require_video_id_sql;
EXECUTE mq_tracks_require_video_id_stmt;
DEALLOCATE PREPARE mq_tracks_require_video_id_stmt;

SET @mq_tracks_drop_youtube_url_sql = IF(
  EXISTS(
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'mq_tracks'
      AND column_name = 'youtube_url'
  )
  AND NOT EXISTS(
    SELECT 1
    FROM mq_tracks
    WHERE COALESCE(TRIM(youtube_video_id), '') = ''
  ),
  'ALTER TABLE mq_tracks DROP COLUMN youtube_url',
  'SELECT 1'
);
PREPARE mq_tracks_drop_youtube_url_stmt FROM @mq_tracks_drop_youtube_url_sql;
EXECUTE mq_tracks_drop_youtube_url_stmt;
DEALLOCATE PREPARE mq_tracks_drop_youtube_url_stmt;
