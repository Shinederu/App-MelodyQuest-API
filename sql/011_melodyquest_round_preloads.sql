-- MelodyQuest upcoming round preloads
-- Run after 010_melodyquest_tv_pairings.sql

CREATE TABLE IF NOT EXISTS mq_round_preloads (
  lobby_id BIGINT NOT NULL,
  round_number INT NOT NULL,
  track_id INT NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (lobby_id, round_number),
  KEY idx_mq_round_preloads_track (track_id),
  CONSTRAINT fk_mq_round_preloads_lobby
    FOREIGN KEY (lobby_id) REFERENCES mq_lobbies(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_mq_round_preloads_track
    FOREIGN KEY (track_id) REFERENCES mq_tracks(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
