-- MelodyQuest player suggestions
-- Run after 008_melodyquest_answer_similarity.sql

CREATE TABLE IF NOT EXISTS mq_player_suggestions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  suggestion_type ENUM('track_correction', 'new_track') NOT NULL,
  status ENUM('pending', 'reviewed', 'rejected') NOT NULL DEFAULT 'pending',
  user_id INT DEFAULT NULL,
  lobby_id BIGINT DEFAULT NULL,
  round_id BIGINT DEFAULT NULL,
  track_id INT DEFAULT NULL,
  current_title VARCHAR(220) DEFAULT NULL,
  current_artist VARCHAR(160) DEFAULT NULL,
  current_youtube_video_id VARCHAR(32) DEFAULT NULL,
  current_family_name VARCHAR(160) DEFAULT NULL,
  proposed_title VARCHAR(220) DEFAULT NULL,
  proposed_artist VARCHAR(160) DEFAULT NULL,
  proposed_youtube_url VARCHAR(255) DEFAULT NULL,
  proposed_youtube_video_id VARCHAR(32) DEFAULT NULL,
  proposed_alias VARCHAR(160) DEFAULT NULL,
  note TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  reviewed_at DATETIME(3) DEFAULT NULL,
  reviewed_by_user_id INT DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_mq_player_suggestions_status (status, created_at),
  KEY idx_mq_player_suggestions_user (user_id),
  KEY idx_mq_player_suggestions_track (track_id),
  CONSTRAINT fk_mq_player_suggestions_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_mq_player_suggestions_reviewer
    FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_mq_player_suggestions_track
    FOREIGN KEY (track_id) REFERENCES mq_tracks(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mq_round_suggestion_holds (
  id BIGINT NOT NULL AUTO_INCREMENT,
  lobby_id BIGINT NOT NULL,
  round_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_round_suggestion_holds_round_user (round_id, user_id),
  KEY idx_mq_round_suggestion_holds_lobby (lobby_id, expires_at),
  KEY idx_mq_round_suggestion_holds_expiry (expires_at),
  CONSTRAINT fk_mq_round_suggestion_holds_lobby
    FOREIGN KEY (lobby_id) REFERENCES mq_lobbies(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_mq_round_suggestion_holds_round
    FOREIGN KEY (round_id) REFERENCES mq_rounds(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_mq_round_suggestion_holds_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
