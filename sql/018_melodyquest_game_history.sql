-- MelodyQuest immutable game history
-- Run after 017_melodyquest_admin_suggestion_review.sql
-- Append-only tables: no existing table, key or relation is altered.

CREATE TABLE IF NOT EXISTS mq_game_sessions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  source_lobby_id BIGINT NOT NULL,
  source_last_round_id BIGINT NOT NULL,
  lobby_code CHAR(8) NOT NULL,
  lobby_name VARCHAR(120) NOT NULL,
  owner_user_id INT NOT NULL,
  owner_username_snapshot VARCHAR(120) NOT NULL,
  game_mode ENUM('participative', 'autoplay') NOT NULL DEFAULT 'participative',
  completion_status ENUM('finished', 'cancelled') NOT NULL,
  config_snapshot JSON NOT NULL,
  started_at DATETIME(3) NOT NULL,
  finished_at DATETIME(3) NOT NULL,
  archived_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_game_sessions_source_last_round (source_last_round_id),
  KEY idx_mq_game_sessions_lobby_archived (source_lobby_id, archived_at),
  KEY idx_mq_game_sessions_owner_archived (owner_user_id, archived_at),
  KEY idx_mq_game_sessions_status_archived (completion_status, archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mq_game_session_players (
  id BIGINT NOT NULL AUTO_INCREMENT,
  game_session_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  username_snapshot VARCHAR(120) NOT NULL,
  lobby_role VARCHAR(16) NOT NULL DEFAULT 'player',
  final_score INT NOT NULL DEFAULT 0,
  presence_status VARCHAR(16) NOT NULL DEFAULT 'active',
  joined_at DATETIME(3) DEFAULT NULL,
  removed_at DATETIME(3) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_game_session_players_user (game_session_id, user_id),
  KEY idx_mq_game_session_players_user (user_id),
  CONSTRAINT fk_mq_game_session_players_session
    FOREIGN KEY (game_session_id) REFERENCES mq_game_sessions(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mq_game_session_rounds (
  id BIGINT NOT NULL AUTO_INCREMENT,
  game_session_id BIGINT NOT NULL,
  source_round_id BIGINT NOT NULL,
  round_number INT NOT NULL,
  track_id INT NOT NULL,
  category_name_snapshot VARCHAR(120) NOT NULL,
  family_name_snapshot VARCHAR(160) NOT NULL,
  track_title_snapshot VARCHAR(220) NOT NULL,
  track_artist_snapshot VARCHAR(220) DEFAULT NULL,
  youtube_video_id_snapshot VARCHAR(32) NOT NULL,
  started_at DATETIME(3) NOT NULL,
  reveal_started_at DATETIME(3) DEFAULT NULL,
  ended_at DATETIME(3) DEFAULT NULL,
  round_status VARCHAR(16) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_game_session_rounds_source (source_round_id),
  UNIQUE KEY uq_mq_game_session_rounds_number (game_session_id, round_number),
  KEY idx_mq_game_session_rounds_track (track_id),
  CONSTRAINT fk_mq_game_session_rounds_session
    FOREIGN KEY (game_session_id) REFERENCES mq_game_sessions(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mq_game_session_answers (
  id BIGINT NOT NULL AUTO_INCREMENT,
  game_session_round_id BIGINT NOT NULL,
  source_answer_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  username_snapshot VARCHAR(120) NOT NULL,
  guess_title VARCHAR(220) DEFAULT NULL,
  guess_artist VARCHAR(220) DEFAULT NULL,
  is_correct_title TINYINT(1) NOT NULL DEFAULT 0,
  is_correct_artist TINYINT(1) NOT NULL DEFAULT 0,
  score_awarded INT NOT NULL DEFAULT 0,
  answered_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_game_session_answers_source (source_answer_id),
  KEY idx_mq_game_session_answers_user (user_id),
  CONSTRAINT fk_mq_game_session_answers_round
    FOREIGN KEY (game_session_round_id) REFERENCES mq_game_session_rounds(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mq_game_session_answer_attempts (
  id BIGINT NOT NULL AUTO_INCREMENT,
  game_session_round_id BIGINT NOT NULL,
  source_attempt_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  username_snapshot VARCHAR(120) NOT NULL,
  guess_title VARCHAR(220) DEFAULT NULL,
  guess_artist VARCHAR(220) DEFAULT NULL,
  is_correct TINYINT(1) NOT NULL DEFAULT 0,
  score_awarded INT NOT NULL DEFAULT 0,
  attempted_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_game_session_answer_attempts_source (source_attempt_id),
  KEY idx_mq_game_session_answer_attempts_user (user_id),
  CONSTRAINT fk_mq_game_session_answer_attempts_round
    FOREIGN KEY (game_session_round_id) REFERENCES mq_game_session_rounds(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mq_game_session_reveal_votes (
  id BIGINT NOT NULL AUTO_INCREMENT,
  game_session_round_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  username_snapshot VARCHAR(120) NOT NULL,
  voted_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_game_session_reveal_votes_user (game_session_round_id, user_id),
  KEY idx_mq_game_session_reveal_votes_user (user_id),
  CONSTRAINT fk_mq_game_session_reveal_votes_round
    FOREIGN KEY (game_session_round_id) REFERENCES mq_game_session_rounds(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mq_game_session_away_bonuses (
  id BIGINT NOT NULL AUTO_INCREMENT,
  game_session_round_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  username_snapshot VARCHAR(120) NOT NULL,
  source_user_id INT NOT NULL,
  source_username_snapshot VARCHAR(120) NOT NULL,
  score_awarded INT NOT NULL DEFAULT 0,
  awarded_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_game_session_away_bonuses_user (game_session_round_id, user_id),
  KEY idx_mq_game_session_away_bonuses_user (user_id),
  KEY idx_mq_game_session_away_bonuses_source_user (source_user_id),
  CONSTRAINT fk_mq_game_session_away_bonuses_round
    FOREIGN KEY (game_session_round_id) REFERENCES mq_game_session_rounds(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
