-- Optional familiarity survey about the work, independent of track ratings.
-- Guest expiry anonymizes the response without removing its aggregate value.
CREATE TABLE IF NOT EXISTS mq_family_knowledge (
  id BIGINT NOT NULL AUTO_INCREMENT,
  family_id INT NOT NULL,
  user_id INT DEFAULT NULL,
  guest_session_id BIGINT DEFAULT NULL,
  known TINYINT(1) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_knowledge_user (family_id, user_id),
  UNIQUE KEY uq_mq_knowledge_guest (family_id, guest_session_id),
  CONSTRAINT fk_mq_knowledge_family FOREIGN KEY (family_id) REFERENCES mq_families(id) ON DELETE CASCADE,
  CONSTRAINT fk_mq_knowledge_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_mq_knowledge_guest FOREIGN KEY (guest_session_id) REFERENCES mq_guest_sessions(id) ON DELETE SET NULL,
  CONSTRAINT chk_mq_knowledge_choice CHECK (known IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
