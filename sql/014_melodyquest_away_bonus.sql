-- MelodyQuest migration 014
-- Tracks the one-time away bonus awarded to absent players for a round.

CREATE TABLE IF NOT EXISTS mq_round_away_bonuses (
  id BIGINT NOT NULL AUTO_INCREMENT,
  round_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  source_user_id INT NOT NULL,
  score_awarded INT NOT NULL DEFAULT 0,
  awarded_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_round_away_bonuses_round_user (round_id, user_id),
  KEY idx_mq_round_away_bonuses_source (source_user_id),
  CONSTRAINT fk_mq_round_away_bonuses_round
    FOREIGN KEY (round_id) REFERENCES mq_rounds(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_mq_round_away_bonuses_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_mq_round_away_bonuses_source
    FOREIGN KEY (source_user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
