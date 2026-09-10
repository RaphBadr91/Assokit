-- ============================================================
-- Migration : suivi de l'e-mail dans la prospection
-- Cible : MariaDB 10.x. Idempotent (ADD COLUMN IF NOT EXISTS).
--
-- Deux canaux valent mieux qu'un état unique « contacté » : sur un même
-- prospect on appelle, on tombe sur un répondeur, on envoie un e-mail, on
-- rappelle. Savoir lequel des deux a déjà servi — et quand — change ce
-- qu'on fait au coup suivant.
--
-- Comme called_at, emailed_at est posé par le serveur et jamais saisi.
-- ============================================================

ALTER TABLE asso_prospects
  ADD COLUMN IF NOT EXISTS emailed    TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS emailed_at DATETIME NULL;
