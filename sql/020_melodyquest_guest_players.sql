-- MelodyQuest guest players and account-independent gameplay identities
-- Run after 019_melodyquest_realtime_outbox.sql.
--
-- Registered accounts keep their positive users.id as actor_id.
-- Guest sessions use the negative value of mq_guest_sessions.id as actor_id.
-- Existing user_id columns are preserved for account history and become nullable
-- only where a guest can legitimately produce the same gameplay data.

CREATE TABLE IF NOT EXISTS mq_guest_sessions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  nickname VARCHAR(32) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  expires_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_guest_sessions_token_hash (token_hash),
  KEY idx_mq_guest_sessions_expiry (expires_at),
  KEY idx_mq_guest_sessions_nickname_expiry (nickname, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS mq_guest_exec_if;
DELIMITER $$
CREATE PROCEDURE mq_guest_exec_if(IN should_run BOOLEAN, IN statement_text LONGTEXT)
BEGIN
  IF should_run THEN
    SET @mq_guest_statement := statement_text;
    PREPARE mq_guest_statement FROM @mq_guest_statement;
    EXECUTE mq_guest_statement;
    DEALLOCATE PREPARE mq_guest_statement;
  END IF;
END$$
DELIMITER ;

-- Live lobby participants.
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_lobby_players' AND column_name = 'actor_id'),
  'ALTER TABLE mq_lobby_players ADD COLUMN actor_id BIGINT NULL AFTER lobby_id'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_lobby_players' AND column_name = 'guest_session_id'),
  'ALTER TABLE mq_lobby_players ADD COLUMN guest_session_id BIGINT NULL AFTER user_id'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_lobby_players' AND column_name = 'display_name_snapshot'),
  'ALTER TABLE mq_lobby_players ADD COLUMN display_name_snapshot VARCHAR(120) NOT NULL DEFAULT "" AFTER guest_session_id'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_lobby_players' AND column_name = 'avatar_url_snapshot'),
  'ALTER TABLE mq_lobby_players ADD COLUMN avatar_url_snapshot VARCHAR(512) NULL AFTER display_name_snapshot'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_lobby_players' AND column_name = 'removed_by_actor_id'),
  'ALTER TABLE mq_lobby_players ADD COLUMN removed_by_actor_id BIGINT NULL AFTER removed_by'
);

UPDATE mq_lobby_players SET actor_id = user_id WHERE actor_id IS NULL AND user_id IS NOT NULL;
UPDATE mq_lobby_players lp
JOIN users u ON u.id = lp.user_id
SET lp.display_name_snapshot = u.username,
    lp.avatar_url_snapshot = u.avatar_url
WHERE lp.display_name_snapshot = '';
UPDATE mq_lobby_players SET removed_by_actor_id = removed_by WHERE removed_by_actor_id IS NULL AND removed_by IS NOT NULL;

CALL mq_guest_exec_if(
  EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'mq_lobby_players'
      AND index_name = 'PRIMARY' AND column_name = 'user_id'
  ),
  'ALTER TABLE mq_lobby_players DROP PRIMARY KEY, ADD PRIMARY KEY (lobby_id, actor_id)'
);
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_lobby_players' AND column_name = 'actor_id' AND is_nullable = 'YES'),
  'ALTER TABLE mq_lobby_players MODIFY actor_id BIGINT NOT NULL'
);
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_lobby_players' AND column_name = 'user_id' AND is_nullable = 'NO'),
  'ALTER TABLE mq_lobby_players MODIFY user_id INT NULL'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mq_lobby_players' AND index_name = 'uq_mq_lobby_players_lobby_user'),
  'ALTER TABLE mq_lobby_players ADD UNIQUE KEY uq_mq_lobby_players_lobby_user (lobby_id, user_id)'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mq_lobby_players' AND index_name = 'uq_mq_lobby_players_lobby_guest'),
  'ALTER TABLE mq_lobby_players ADD UNIQUE KEY uq_mq_lobby_players_lobby_guest (lobby_id, guest_session_id)'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mq_lobby_players' AND index_name = 'idx_mq_lobby_players_actor'),
  'ALTER TABLE mq_lobby_players ADD KEY idx_mq_lobby_players_actor (actor_id)'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'mq_lobby_players' AND constraint_name = 'fk_mq_lobby_players_guest'),
  'ALTER TABLE mq_lobby_players ADD CONSTRAINT fk_mq_lobby_players_guest FOREIGN KEY (guest_session_id) REFERENCES mq_guest_sessions(id) ON DELETE SET NULL'
);

-- Lobby ownership can belong to either an account or a guest actor.
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_lobbies' AND column_name = 'owner_actor_id'),
  'ALTER TABLE mq_lobbies ADD COLUMN owner_actor_id BIGINT NULL AFTER owner_user_id'
);
UPDATE mq_lobbies SET owner_actor_id = owner_user_id WHERE owner_actor_id IS NULL AND owner_user_id IS NOT NULL;
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_lobbies' AND column_name = 'owner_actor_id' AND is_nullable = 'YES'),
  'ALTER TABLE mq_lobbies MODIFY owner_actor_id BIGINT NOT NULL'
);
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_lobbies' AND column_name = 'owner_user_id' AND is_nullable = 'NO'),
  'ALTER TABLE mq_lobbies MODIFY owner_user_id INT NULL'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mq_lobbies' AND index_name = 'idx_mq_lobbies_owner_actor'),
  'ALTER TABLE mq_lobbies ADD KEY idx_mq_lobbies_owner_actor (owner_actor_id)'
);

-- Round answers.
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_round_answers' AND column_name = 'actor_id'),
  'ALTER TABLE mq_round_answers ADD COLUMN actor_id BIGINT NULL AFTER user_id'
);
UPDATE mq_round_answers SET actor_id = user_id WHERE actor_id IS NULL AND user_id IS NOT NULL;
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_round_answers' AND column_name = 'actor_id' AND is_nullable = 'YES'),
  'ALTER TABLE mq_round_answers MODIFY actor_id BIGINT NOT NULL'
);
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_round_answers' AND column_name = 'user_id' AND is_nullable = 'NO'),
  'ALTER TABLE mq_round_answers MODIFY user_id INT NULL'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mq_round_answers' AND index_name = 'uq_mq_round_answers_round_actor'),
  'ALTER TABLE mq_round_answers ADD UNIQUE KEY uq_mq_round_answers_round_actor (round_id, actor_id)'
);

-- Complete answer-attempt history retained for catalogue insights.
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_round_answer_attempts' AND column_name = 'actor_id'),
  'ALTER TABLE mq_round_answer_attempts ADD COLUMN actor_id BIGINT NULL AFTER user_id'
);
UPDATE mq_round_answer_attempts SET actor_id = user_id WHERE actor_id IS NULL AND user_id IS NOT NULL;
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_round_answer_attempts' AND column_name = 'actor_id' AND is_nullable = 'YES'),
  'ALTER TABLE mq_round_answer_attempts MODIFY actor_id BIGINT NOT NULL'
);
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_round_answer_attempts' AND column_name = 'user_id' AND is_nullable = 'NO'),
  'ALTER TABLE mq_round_answer_attempts MODIFY user_id INT NULL'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mq_round_answer_attempts' AND index_name = 'idx_mq_round_answer_attempts_actor'),
  'ALTER TABLE mq_round_answer_attempts ADD KEY idx_mq_round_answer_attempts_actor (actor_id)'
);

-- Early-reveal votes use actor identity as their primary key.
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_round_reveal_votes' AND column_name = 'actor_id'),
  'ALTER TABLE mq_round_reveal_votes ADD COLUMN actor_id BIGINT NULL AFTER user_id'
);
UPDATE mq_round_reveal_votes SET actor_id = user_id WHERE actor_id IS NULL AND user_id IS NOT NULL;
CALL mq_guest_exec_if(
  EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'mq_round_reveal_votes'
      AND index_name = 'PRIMARY' AND column_name = 'user_id'
  ),
  'ALTER TABLE mq_round_reveal_votes DROP PRIMARY KEY, ADD PRIMARY KEY (round_id, actor_id)'
);
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_round_reveal_votes' AND column_name = 'actor_id' AND is_nullable = 'YES'),
  'ALTER TABLE mq_round_reveal_votes MODIFY actor_id BIGINT NOT NULL'
);
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_round_reveal_votes' AND column_name = 'user_id' AND is_nullable = 'NO'),
  'ALTER TABLE mq_round_reveal_votes MODIFY user_id INT NULL'
);

-- Suggestion holds.
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_round_suggestion_holds' AND column_name = 'actor_id'),
  'ALTER TABLE mq_round_suggestion_holds ADD COLUMN actor_id BIGINT NULL AFTER user_id'
);
UPDATE mq_round_suggestion_holds SET actor_id = user_id WHERE actor_id IS NULL AND user_id IS NOT NULL;
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_round_suggestion_holds' AND column_name = 'actor_id' AND is_nullable = 'YES'),
  'ALTER TABLE mq_round_suggestion_holds MODIFY actor_id BIGINT NOT NULL'
);
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_round_suggestion_holds' AND column_name = 'user_id' AND is_nullable = 'NO'),
  'ALTER TABLE mq_round_suggestion_holds MODIFY user_id INT NULL'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mq_round_suggestion_holds' AND index_name = 'uq_mq_round_suggestion_holds_round_actor'),
  'ALTER TABLE mq_round_suggestion_holds ADD UNIQUE KEY uq_mq_round_suggestion_holds_round_actor (round_id, actor_id)'
);

-- Away-player compensation.
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_round_away_bonuses' AND column_name = 'actor_id'),
  'ALTER TABLE mq_round_away_bonuses ADD COLUMN actor_id BIGINT NULL AFTER user_id'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_round_away_bonuses' AND column_name = 'source_actor_id'),
  'ALTER TABLE mq_round_away_bonuses ADD COLUMN source_actor_id BIGINT NULL AFTER source_user_id'
);
UPDATE mq_round_away_bonuses SET actor_id = user_id WHERE actor_id IS NULL AND user_id IS NOT NULL;
UPDATE mq_round_away_bonuses SET source_actor_id = source_user_id WHERE source_actor_id IS NULL AND source_user_id IS NOT NULL;
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_round_away_bonuses' AND column_name = 'actor_id' AND is_nullable = 'YES'),
  'ALTER TABLE mq_round_away_bonuses MODIFY actor_id BIGINT NOT NULL, MODIFY source_actor_id BIGINT NOT NULL'
);
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_round_away_bonuses' AND column_name = 'user_id' AND is_nullable = 'NO'),
  'ALTER TABLE mq_round_away_bonuses MODIFY user_id INT NULL, MODIFY source_user_id INT NULL'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mq_round_away_bonuses' AND index_name = 'uq_mq_round_away_bonuses_round_actor'),
  'ALTER TABLE mq_round_away_bonuses ADD UNIQUE KEY uq_mq_round_away_bonuses_round_actor (round_id, actor_id)'
);

-- Suggestions and TV pairing preserve guest attribution without requiring an account.
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_player_suggestions' AND column_name = 'actor_id'),
  'ALTER TABLE mq_player_suggestions ADD COLUMN actor_id BIGINT NULL AFTER user_id'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_player_suggestions' AND column_name = 'guest_session_id'),
  'ALTER TABLE mq_player_suggestions ADD COLUMN guest_session_id BIGINT NULL AFTER actor_id'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_player_suggestions' AND column_name = 'submitter_name_snapshot'),
  'ALTER TABLE mq_player_suggestions ADD COLUMN submitter_name_snapshot VARCHAR(120) NULL AFTER guest_session_id'
);
UPDATE mq_player_suggestions s
LEFT JOIN users u ON u.id = s.user_id
SET s.actor_id = s.user_id,
    s.submitter_name_snapshot = u.username
WHERE s.actor_id IS NULL AND s.user_id IS NOT NULL;
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mq_player_suggestions' AND index_name = 'idx_mq_player_suggestions_actor'),
  'ALTER TABLE mq_player_suggestions ADD KEY idx_mq_player_suggestions_actor (actor_id)'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'mq_player_suggestions' AND constraint_name = 'fk_mq_player_suggestions_guest'),
  'ALTER TABLE mq_player_suggestions ADD CONSTRAINT fk_mq_player_suggestions_guest FOREIGN KEY (guest_session_id) REFERENCES mq_guest_sessions(id) ON DELETE SET NULL'
);

CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_tv_pairings' AND column_name = 'linked_by_actor_id'),
  'ALTER TABLE mq_tv_pairings ADD COLUMN linked_by_actor_id BIGINT NULL AFTER linked_by_user_id'
);
UPDATE mq_tv_pairings SET linked_by_actor_id = linked_by_user_id WHERE linked_by_actor_id IS NULL AND linked_by_user_id IS NOT NULL;

-- Immutable game history keeps anonymous guest rows but no durable guest profile.
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_sessions' AND column_name = 'owner_actor_id'),
  'ALTER TABLE mq_game_sessions ADD COLUMN owner_actor_id BIGINT NULL AFTER owner_user_id'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_sessions' AND column_name = 'owner_is_guest'),
  'ALTER TABLE mq_game_sessions ADD COLUMN owner_is_guest TINYINT(1) NOT NULL DEFAULT 0 AFTER owner_actor_id'
);
UPDATE mq_game_sessions SET owner_actor_id = owner_user_id WHERE owner_actor_id IS NULL AND owner_user_id IS NOT NULL;
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_sessions' AND column_name = 'owner_actor_id' AND is_nullable = 'YES'),
  'ALTER TABLE mq_game_sessions MODIFY owner_actor_id BIGINT NOT NULL'
);
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_sessions' AND column_name = 'owner_user_id' AND is_nullable = 'NO'),
  'ALTER TABLE mq_game_sessions MODIFY owner_user_id INT NULL'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mq_game_sessions' AND index_name = 'idx_mq_game_sessions_owner_actor'),
  'ALTER TABLE mq_game_sessions ADD KEY idx_mq_game_sessions_owner_actor (owner_actor_id, archived_at)'
);

CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_players' AND column_name = 'actor_id'),
  'ALTER TABLE mq_game_session_players ADD COLUMN actor_id BIGINT NULL AFTER user_id'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_players' AND column_name = 'is_guest'),
  'ALTER TABLE mq_game_session_players ADD COLUMN is_guest TINYINT(1) NOT NULL DEFAULT 0 AFTER actor_id'
);
UPDATE mq_game_session_players SET actor_id = user_id WHERE actor_id IS NULL AND user_id IS NOT NULL;
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_players' AND column_name = 'actor_id' AND is_nullable = 'YES'),
  'ALTER TABLE mq_game_session_players MODIFY actor_id BIGINT NOT NULL'
);
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_players' AND column_name = 'user_id' AND is_nullable = 'NO'),
  'ALTER TABLE mq_game_session_players MODIFY user_id INT NULL'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_players' AND index_name = 'uq_mq_game_session_players_actor'),
  'ALTER TABLE mq_game_session_players ADD UNIQUE KEY uq_mq_game_session_players_actor (game_session_id, actor_id)'
);

CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_answers' AND column_name = 'actor_id'),
  'ALTER TABLE mq_game_session_answers ADD COLUMN actor_id BIGINT NULL AFTER user_id'
);
UPDATE mq_game_session_answers SET actor_id = user_id WHERE actor_id IS NULL AND user_id IS NOT NULL;
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_answers' AND column_name = 'actor_id' AND is_nullable = 'YES'),
  'ALTER TABLE mq_game_session_answers MODIFY actor_id BIGINT NOT NULL'
);
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_answers' AND column_name = 'user_id' AND is_nullable = 'NO'),
  'ALTER TABLE mq_game_session_answers MODIFY user_id INT NULL'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_answers' AND index_name = 'idx_mq_game_session_answers_actor'),
  'ALTER TABLE mq_game_session_answers ADD KEY idx_mq_game_session_answers_actor (actor_id)'
);

CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_answer_attempts' AND column_name = 'actor_id'),
  'ALTER TABLE mq_game_session_answer_attempts ADD COLUMN actor_id BIGINT NULL AFTER user_id'
);
UPDATE mq_game_session_answer_attempts SET actor_id = user_id WHERE actor_id IS NULL AND user_id IS NOT NULL;
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_answer_attempts' AND column_name = 'actor_id' AND is_nullable = 'YES'),
  'ALTER TABLE mq_game_session_answer_attempts MODIFY actor_id BIGINT NOT NULL'
);
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_answer_attempts' AND column_name = 'user_id' AND is_nullable = 'NO'),
  'ALTER TABLE mq_game_session_answer_attempts MODIFY user_id INT NULL'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_answer_attempts' AND index_name = 'idx_mq_game_session_answer_attempts_actor'),
  'ALTER TABLE mq_game_session_answer_attempts ADD KEY idx_mq_game_session_answer_attempts_actor (actor_id)'
);

CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_reveal_votes' AND column_name = 'actor_id'),
  'ALTER TABLE mq_game_session_reveal_votes ADD COLUMN actor_id BIGINT NULL AFTER user_id'
);
UPDATE mq_game_session_reveal_votes SET actor_id = user_id WHERE actor_id IS NULL AND user_id IS NOT NULL;
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_reveal_votes' AND column_name = 'actor_id' AND is_nullable = 'YES'),
  'ALTER TABLE mq_game_session_reveal_votes MODIFY actor_id BIGINT NOT NULL'
);
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_reveal_votes' AND column_name = 'user_id' AND is_nullable = 'NO'),
  'ALTER TABLE mq_game_session_reveal_votes MODIFY user_id INT NULL'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_reveal_votes' AND index_name = 'uq_mq_game_session_reveal_votes_actor'),
  'ALTER TABLE mq_game_session_reveal_votes ADD UNIQUE KEY uq_mq_game_session_reveal_votes_actor (game_session_round_id, actor_id)'
);

CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_away_bonuses' AND column_name = 'actor_id'),
  'ALTER TABLE mq_game_session_away_bonuses ADD COLUMN actor_id BIGINT NULL AFTER user_id'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_away_bonuses' AND column_name = 'source_actor_id'),
  'ALTER TABLE mq_game_session_away_bonuses ADD COLUMN source_actor_id BIGINT NULL AFTER source_user_id'
);
UPDATE mq_game_session_away_bonuses SET actor_id = user_id WHERE actor_id IS NULL AND user_id IS NOT NULL;
UPDATE mq_game_session_away_bonuses SET source_actor_id = source_user_id WHERE source_actor_id IS NULL AND source_user_id IS NOT NULL;
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_away_bonuses' AND column_name = 'actor_id' AND is_nullable = 'YES'),
  'ALTER TABLE mq_game_session_away_bonuses MODIFY actor_id BIGINT NOT NULL, MODIFY source_actor_id BIGINT NOT NULL'
);
CALL mq_guest_exec_if(
  EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_away_bonuses' AND column_name = 'user_id' AND is_nullable = 'NO'),
  'ALTER TABLE mq_game_session_away_bonuses MODIFY user_id INT NULL, MODIFY source_user_id INT NULL'
);
CALL mq_guest_exec_if(
  NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'mq_game_session_away_bonuses' AND index_name = 'uq_mq_game_session_away_bonuses_actor'),
  'ALTER TABLE mq_game_session_away_bonuses ADD UNIQUE KEY uq_mq_game_session_away_bonuses_actor (game_session_round_id, actor_id)'
);

DROP PROCEDURE IF EXISTS mq_guest_exec_if;
