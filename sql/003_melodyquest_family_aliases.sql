-- MelodyQuest family aliases
-- Target DB: ShinedeCore
-- Idempotent migration (IF NOT EXISTS)

CREATE TABLE IF NOT EXISTS mq_family_aliases (
  id INT NOT NULL AUTO_INCREMENT,
  family_id INT NOT NULL,
  alias VARCHAR(160) NOT NULL,
  slug VARCHAR(180) NOT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mq_family_aliases_family_slug (family_id, slug),
  KEY idx_mq_family_aliases_family (family_id),
  CONSTRAINT fk_mq_family_aliases_family
    FOREIGN KEY (family_id) REFERENCES mq_families(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_mq_family_aliases_created_by
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
