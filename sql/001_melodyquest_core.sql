-- MelodyQuest core schema
-- Target DB: ShinedeCore
-- Idempotent migration (IF NOT EXISTS)

CREATE TABLE IF NOT EXISTS mq_categories (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(140) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_categories_slug (slug),
  CONSTRAINT fk_mq_categories_created_by
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mq_families (
  id INT NOT NULL AUTO_INCREMENT,
  category_id INT NOT NULL,
  name VARCHAR(140) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  description TEXT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_families_category_slug (category_id, slug),
  KEY idx_mq_families_category (category_id),
  CONSTRAINT fk_mq_families_category
    FOREIGN KEY (category_id) REFERENCES mq_categories(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_mq_families_created_by
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mq_tracks (
  id INT NOT NULL AUTO_INCREMENT,
  family_id INT NOT NULL,
  title VARCHAR(220) NOT NULL,
  artist VARCHAR(220) DEFAULT NULL,
  youtube_video_id VARCHAR(32) NOT NULL,
  duration_seconds INT DEFAULT NULL,
  start_offset_seconds INT NOT NULL DEFAULT 0,
  end_offset_seconds INT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_validated TINYINT(1) NOT NULL DEFAULT 0,
  validated_by INT DEFAULT NULL,
  validated_at DATETIME DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mq_tracks_family (family_id),
  KEY idx_mq_tracks_video_id (youtube_video_id),
  KEY idx_mq_tracks_validation (is_validated, is_active),
  CONSTRAINT fk_mq_tracks_family
    FOREIGN KEY (family_id) REFERENCES mq_families(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_mq_tracks_validated_by
    FOREIGN KEY (validated_by) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_mq_tracks_created_by
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mq_lobbies (
  id BIGINT NOT NULL AUTO_INCREMENT,
  lobby_code CHAR(8) NOT NULL,
  name VARCHAR(120) NOT NULL,
  owner_user_id INT NOT NULL,
  status ENUM('waiting', 'playing', 'finished', 'closed') NOT NULL DEFAULT 'waiting',
  visibility ENUM('public', 'private') NOT NULL DEFAULT 'private',
  max_players INT NOT NULL DEFAULT 8,
  round_duration_seconds INT NOT NULL DEFAULT 30,
  reveal_duration_seconds INT NOT NULL DEFAULT 8,
  guess_mode ENUM('title', 'artist', 'both') NOT NULL DEFAULT 'both',
  current_track_id INT DEFAULT NULL,
  playback_state ENUM('stopped', 'playing', 'paused') NOT NULL DEFAULT 'stopped',
  playback_started_at DATETIME(3) DEFAULT NULL,
  playback_offset_seconds DECIMAL(8,3) NOT NULL DEFAULT 0.000,
  sync_revision INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_lobbies_code (lobby_code),
  KEY idx_mq_lobbies_owner (owner_user_id),
  KEY idx_mq_lobbies_status (status),
  CONSTRAINT fk_mq_lobbies_owner
    FOREIGN KEY (owner_user_id) REFERENCES users(id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_mq_lobbies_current_track
    FOREIGN KEY (current_track_id) REFERENCES mq_tracks(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mq_lobby_players (
  lobby_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  role ENUM('owner', 'player') NOT NULL DEFAULT 'player',
  is_ready TINYINT(1) NOT NULL DEFAULT 0,
  score INT NOT NULL DEFAULT 0,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (lobby_id, user_id),
  KEY idx_mq_lobby_players_user (user_id),
  CONSTRAINT fk_mq_lobby_players_lobby
    FOREIGN KEY (lobby_id) REFERENCES mq_lobbies(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_mq_lobby_players_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mq_lobby_track_pool (
  lobby_id BIGINT NOT NULL,
  track_id INT NOT NULL,
  added_by INT DEFAULT NULL,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (lobby_id, track_id),
  KEY idx_mq_lobby_track_pool_track (track_id),
  CONSTRAINT fk_mq_lobby_track_pool_lobby
    FOREIGN KEY (lobby_id) REFERENCES mq_lobbies(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_mq_lobby_track_pool_track
    FOREIGN KEY (track_id) REFERENCES mq_tracks(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_mq_lobby_track_pool_added_by
    FOREIGN KEY (added_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mq_rounds (
  id BIGINT NOT NULL AUTO_INCREMENT,
  lobby_id BIGINT NOT NULL,
  round_number INT NOT NULL,
  track_id INT NOT NULL,
  started_at DATETIME(3) NOT NULL,
  reveal_started_at DATETIME(3) DEFAULT NULL,
  ended_at DATETIME(3) DEFAULT NULL,
  status ENUM('running', 'reveal', 'finished', 'cancelled') NOT NULL DEFAULT 'running',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_rounds_lobby_round_number (lobby_id, round_number),
  KEY idx_mq_rounds_lobby (lobby_id),
  KEY idx_mq_rounds_track (track_id),
  CONSTRAINT fk_mq_rounds_lobby
    FOREIGN KEY (lobby_id) REFERENCES mq_lobbies(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_mq_rounds_track
    FOREIGN KEY (track_id) REFERENCES mq_tracks(id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mq_round_answers (
  id BIGINT NOT NULL AUTO_INCREMENT,
  round_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  guess_title VARCHAR(220) DEFAULT NULL,
  guess_artist VARCHAR(220) DEFAULT NULL,
  is_correct_title TINYINT(1) NOT NULL DEFAULT 0,
  is_correct_artist TINYINT(1) NOT NULL DEFAULT 0,
  score_awarded INT NOT NULL DEFAULT 0,
  answered_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_round_answers_round_user (round_id, user_id),
  KEY idx_mq_round_answers_user (user_id),
  CONSTRAINT fk_mq_round_answers_round
    FOREIGN KEY (round_id) REFERENCES mq_rounds(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_mq_round_answers_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
