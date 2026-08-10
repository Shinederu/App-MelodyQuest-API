-- MelodyQuest durable realtime publication queue
-- Run after 018_melodyquest_game_history.sql
-- Rows are coalesced by stream and removed only after successful publication.

CREATE TABLE IF NOT EXISTS mq_realtime_outbox (
  id BIGINT NOT NULL AUTO_INCREMENT,
  stream_key VARCHAR(191) NOT NULL,
  event_kind ENUM('lobby_snapshot', 'lobby_deleted', 'public_lobbies') NOT NULL,
  lobby_id BIGINT DEFAULT NULL,
  lobby_code CHAR(8) DEFAULT NULL,
  payload JSON DEFAULT NULL,
  generation BIGINT NOT NULL DEFAULT 1,
  attempts INT NOT NULL DEFAULT 0,
  requested_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  locked_at DATETIME(6) DEFAULT NULL,
  last_error VARCHAR(1000) DEFAULT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_realtime_outbox_stream (stream_key),
  KEY idx_mq_realtime_outbox_available (available_at, locked_at),
  KEY idx_mq_realtime_outbox_lobby (lobby_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
