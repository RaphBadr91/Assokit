-- ============================================================
-- Étapes de projet : compte-rendu de ce qui a été fait
-- ============================================================
-- La description d'une étape dit ce qu'il FAUT faire. Le compte-rendu
-- dit ce qui A ÉTÉ fait, au moment de la validation, pour que la personne
-- qui reprend le projet sache où on en est sans avoir à demander.
--
-- Idempotent, sans perte : une colonne ajoutée, rien de modifié.
--   /usr/local/bin/php migrations/run.php 2026-10-05-etapes-compte-rendu.sql
-- Le code fonctionne avant comme après : tant que la colonne manque,
-- le champ de saisie n'apparaît simplement pas.
-- ============================================================

ALTER TABLE project_steps
  ADD COLUMN IF NOT EXISTS completion_note TEXT NULL DEFAULT NULL AFTER completed_by;
