-- MelodyQuest lobby settings
-- Run after 001_melodyquest_core.sql

ALTER TABLE mq_lobbies
  ADD COLUMN total_rounds INT NOT NULL DEFAULT 5 AFTER max_players,
  ADD COLUMN selected_category_ids TEXT DEFAULT NULL AFTER guess_mode;
