-- MelodyQuest duplicate category cleanup
-- Target DB: ShinedeCore
-- Idempotent data migration for:
--   animes -> anime
--   musiques -> musique
--   jeux-video -> jeux

START TRANSACTION;

-- Merge duplicate game families into their canonical family when the slug already exists.
UPDATE mq_tracks AS track
JOIN mq_families AS source_family ON source_family.id = track.family_id
JOIN (
  SELECT source.id AS source_id, target.id AS target_id, pairs.source_slug, pairs.target_slug
  FROM (
    SELECT 'animes' AS source_slug, 'anime' AS target_slug
    UNION ALL SELECT 'musiques', 'musique'
    UNION ALL SELECT 'jeux-video', 'jeux'
  ) AS pairs
  JOIN mq_categories AS source ON source.slug = pairs.source_slug
  JOIN mq_categories AS target ON target.slug = pairs.target_slug
) AS category_map
  ON category_map.source_id = source_family.category_id
  AND category_map.source_slug = 'jeux-video'
JOIN mq_families AS target_family
  ON target_family.category_id = category_map.target_id
  AND target_family.slug = source_family.slug
SET track.family_id = target_family.id;

UPDATE mq_family_aliases AS alias
JOIN mq_families AS source_family ON source_family.id = alias.family_id
JOIN (
  SELECT source.id AS source_id, target.id AS target_id, pairs.source_slug, pairs.target_slug
  FROM (
    SELECT 'animes' AS source_slug, 'anime' AS target_slug
    UNION ALL SELECT 'musiques', 'musique'
    UNION ALL SELECT 'jeux-video', 'jeux'
  ) AS pairs
  JOIN mq_categories AS source ON source.slug = pairs.source_slug
  JOIN mq_categories AS target ON target.slug = pairs.target_slug
) AS category_map
  ON category_map.source_id = source_family.category_id
  AND category_map.source_slug = 'jeux-video'
JOIN mq_families AS target_family
  ON target_family.category_id = category_map.target_id
  AND target_family.slug = source_family.slug
LEFT JOIN mq_family_aliases AS existing_alias
  ON existing_alias.family_id = target_family.id
  AND existing_alias.slug = alias.slug
SET alias.family_id = target_family.id
WHERE existing_alias.id IS NULL;

DELETE source_family
FROM mq_families AS source_family
JOIN (
  SELECT source.id AS source_id, target.id AS target_id, pairs.source_slug, pairs.target_slug
  FROM (
    SELECT 'animes' AS source_slug, 'anime' AS target_slug
    UNION ALL SELECT 'musiques', 'musique'
    UNION ALL SELECT 'jeux-video', 'jeux'
  ) AS pairs
  JOIN mq_categories AS source ON source.slug = pairs.source_slug
  JOIN mq_categories AS target ON target.slug = pairs.target_slug
) AS category_map
  ON category_map.source_id = source_family.category_id
  AND category_map.source_slug = 'jeux-video'
JOIN mq_families AS target_family
  ON target_family.category_id = category_map.target_id
  AND target_family.slug = source_family.slug;

-- Move any remaining non-conflicting game families into the canonical category.
UPDATE mq_families AS source_family
JOIN (
  SELECT source.id AS source_id, target.id AS target_id, pairs.source_slug, pairs.target_slug
  FROM (
    SELECT 'animes' AS source_slug, 'anime' AS target_slug
    UNION ALL SELECT 'musiques', 'musique'
    UNION ALL SELECT 'jeux-video', 'jeux'
  ) AS pairs
  JOIN mq_categories AS source ON source.slug = pairs.source_slug
  JOIN mq_categories AS target ON target.slug = pairs.target_slug
) AS category_map
  ON category_map.source_id = source_family.category_id
  AND category_map.source_slug = 'jeux-video'
LEFT JOIN mq_families AS target_family
  ON target_family.category_id = category_map.target_id
  AND target_family.slug = source_family.slug
SET source_family.category_id = category_map.target_id
WHERE target_family.id IS NULL;

-- Normalize lobby category selections while preserving first-seen order.
UPDATE mq_lobbies AS lobby
JOIN (
  SELECT lobby_id, CONCAT('[', GROUP_CONCAT(mapped_category_id ORDER BY first_ordinal SEPARATOR ','), ']') AS normalized_category_ids
  FROM (
    SELECT lobby_id, mapped_category_id, MIN(ordinal) AS first_ordinal
    FROM (
      SELECT valid_lobbies.id AS lobby_id,
             json_categories.ordinal,
             COALESCE(category_map.target_id, json_categories.category_id) AS mapped_category_id
      FROM (
        SELECT id, selected_category_ids
        FROM mq_lobbies
        WHERE selected_category_ids IS NOT NULL
          AND selected_category_ids <> ''
          AND JSON_VALID(selected_category_ids)
      ) AS valid_lobbies
      JOIN JSON_TABLE(
        CAST(valid_lobbies.selected_category_ids AS JSON),
        '$[*]' COLUMNS (
          ordinal FOR ORDINALITY,
          category_id INT PATH '$'
        )
      ) AS json_categories
      LEFT JOIN (
        SELECT source.id AS source_id, target.id AS target_id, pairs.source_slug, pairs.target_slug
        FROM (
          SELECT 'animes' AS source_slug, 'anime' AS target_slug
          UNION ALL SELECT 'musiques', 'musique'
          UNION ALL SELECT 'jeux-video', 'jeux'
        ) AS pairs
        JOIN mq_categories AS source ON source.slug = pairs.source_slug
        JOIN mq_categories AS target ON target.slug = pairs.target_slug
      ) AS category_map
        ON category_map.source_id = json_categories.category_id
    ) AS mapped_lobby_categories
    GROUP BY lobby_id, mapped_category_id
  ) AS deduped_lobby_categories
  GROUP BY lobby_id
) AS normalized_lobbies ON normalized_lobbies.lobby_id = lobby.id
SET lobby.selected_category_ids = normalized_lobbies.normalized_category_ids
WHERE lobby.selected_category_ids <> normalized_lobbies.normalized_category_ids;

DELETE duplicate_category
FROM mq_categories AS duplicate_category
JOIN (
  SELECT source.id AS source_id, target.id AS target_id, pairs.source_slug, pairs.target_slug
  FROM (
    SELECT 'animes' AS source_slug, 'anime' AS target_slug
    UNION ALL SELECT 'musiques', 'musique'
    UNION ALL SELECT 'jeux-video', 'jeux'
  ) AS pairs
  JOIN mq_categories AS source ON source.slug = pairs.source_slug
  JOIN mq_categories AS target ON target.slug = pairs.target_slug
) AS category_map ON category_map.source_id = duplicate_category.id
LEFT JOIN mq_families AS family ON family.category_id = duplicate_category.id
WHERE family.id IS NULL;

COMMIT;
