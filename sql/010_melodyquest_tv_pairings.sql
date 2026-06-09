-- MelodyQuest TV pairing
-- Run after 009_melodyquest_player_suggestions.sql

CREATE TABLE IF NOT EXISTS mq_tv_pairings (
  id BIGINT NOT NULL AUTO_INCREMENT,
  pairing_code CHAR(6) NOT NULL,
  device_token CHAR(64) NOT NULL,
  status ENUM('pending', 'linked', 'expired') NOT NULL DEFAULT 'pending',
  lobby_id BIGINT DEFAULT NULL,
  linked_by_user_id INT DEFAULT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  expires_at DATETIME(3) NOT NULL,
  linked_at DATETIME(3) DEFAULT NULL,
  last_seen_at DATETIME(3) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_tv_pairings_device_token (device_token),
  KEY idx_mq_tv_pairings_code_status (pairing_code, status, expires_at),
  KEY idx_mq_tv_pairings_lobby (lobby_id, status),
  KEY idx_mq_tv_pairings_expiry (expires_at),
  CONSTRAINT fk_mq_tv_pairings_lobby
    FOREIGN KEY (lobby_id) REFERENCES mq_lobbies(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_mq_tv_pairings_user
    FOREIGN KEY (linked_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
