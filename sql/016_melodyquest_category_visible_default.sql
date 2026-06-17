-- MelodyQuest category visibility default
-- Changes only the column default for new lobbies. Existing lobby values are preserved.

ALTER TABLE mq_lobbies
  MODIFY COLUMN show_track_category TINYINT(1) NOT NULL DEFAULT 1;
