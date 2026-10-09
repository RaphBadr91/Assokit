-- ============================================================
-- Inscription publique : essai gratuit automatique (15 jours)
-- ============================================================
-- public_signups trace CHAQUE demande d'essai, avant toute création :
-- une demande ne peut plus se perdre. error_message garde la cause
-- d'un échec (la colonne manquait : l'erreur n'était notée nulle part).
--
-- Idempotent, sans perte. Le code fonctionne aussi sans cette migration
-- (écritures adaptées aux colonnes présentes).
--   /usr/local/bin/php migrations/run.php 2026-10-09-inscription-essai.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS public_signups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  org_name VARCHAR(200) NULL,
  admin_email VARCHAR(190) NULL,
  admin_first_name VARCHAR(100) NULL,
  admin_last_name VARCHAR(100) NULL,
  plan_choice VARCHAR(40) NULL,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(500) NULL,
  referer VARCHAR(500) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  org_id INT NULL,
  user_id INT NULL,
  error_message TEXT NULL,
  created_at DATETIME NULL,
  completed_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE public_signups
  ADD COLUMN IF NOT EXISTS org_id INT NULL,
  ADD COLUMN IF NOT EXISTS user_id INT NULL,
  ADD COLUMN IF NOT EXISTS error_message TEXT NULL,
  ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL;

ALTER TABLE public_signups
  ADD INDEX IF NOT EXISTS idx_public_signups_ip (ip_address, created_at);
