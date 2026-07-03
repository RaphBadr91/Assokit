-- ============================================================
-- Migration : ajout de users.email_verified_at
-- Objet     : trace la confirmation d'email (tunnel demo).
-- Idempotent : IF NOT EXISTS (MariaDB 10.x / O2switch).
-- A executer une fois :
--   mysql -u pura7044_ak pura7044_assokit < migrations/2026-07-03-add-email-verified-at.sql
-- (le code fonctionne meme si cette migration n'a pas encore ete passee :
--  les ecritures sont en try/catch, l'inscription ne peut pas planter.)
-- ============================================================

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL DEFAULT NULL AFTER is_active;
