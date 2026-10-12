-- ============================================================
-- L01 — Authentification : journal de l'incarnation, codes de secours 2FA
-- ============================================================
-- Idempotent : on peut relancer.
--   /usr/local/bin/php migrations/run.php 2026-10-11-L01-auth.sql
--
-- 1) Tables de l'incarnation Fondateur (impersonation-helpers.php,
--    historique super-admin-impersonation-logs.php). Elles ont été créées
--    hors du dépôt : on garantit ici toutes les colonnes que le code écrit
--    et lit. Le code n'écrit déjà que les colonnes présentes (ak_db_insert) :
--    sans cette migration rien ne casse, mais le journal est moins détaillé.
--
-- 2) users.totp_backup_codes en TEXT : les 8 codes de secours hachés
--    (bcrypt, en JSON) font environ 500 caractères. Dans une colonne plus
--    courte, securite-2fa.php n'en enregistre que le nombre qui tient
--    (4 dans un VARCHAR(255)) au lieu de planter ; après cette migration,
--    les 8 codes sont enregistrés.
-- ============================================================

CREATE TABLE IF NOT EXISTS impersonation_sessions (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_user_id  INT NULL,                       -- Fondateur qui incarne
  target_user_id INT NULL,                       -- compte incarné
  reason         VARCHAR(500) NULL,
  started_at     DATETIME NULL,
  ended_at       DATETIME NULL,
  auto_ended     TINYINT(1) NOT NULL DEFAULT 0,  -- 1 : fin automatique au bout d'1 h
  actions_count  INT NOT NULL DEFAULT 0,
  ip_address     VARCHAR(45) NULL,
  user_agent     VARCHAR(500) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_admin (admin_user_id),
  KEY idx_target (target_user_id),
  KEY idx_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE impersonation_sessions ADD COLUMN IF NOT EXISTS admin_user_id INT NULL;
ALTER TABLE impersonation_sessions ADD COLUMN IF NOT EXISTS target_user_id INT NULL;
ALTER TABLE impersonation_sessions ADD COLUMN IF NOT EXISTS reason VARCHAR(500) NULL;
ALTER TABLE impersonation_sessions ADD COLUMN IF NOT EXISTS started_at DATETIME NULL;
ALTER TABLE impersonation_sessions ADD COLUMN IF NOT EXISTS ended_at DATETIME NULL;
ALTER TABLE impersonation_sessions ADD COLUMN IF NOT EXISTS auto_ended TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE impersonation_sessions ADD COLUMN IF NOT EXISTS actions_count INT NOT NULL DEFAULT 0;
ALTER TABLE impersonation_sessions ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL;
ALTER TABLE impersonation_sessions ADD COLUMN IF NOT EXISTS user_agent VARCHAR(500) NULL;
ALTER TABLE impersonation_sessions ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
-- Fin de session à la seconde près (l'historique affiche une durée)
ALTER TABLE impersonation_sessions MODIFY ended_at DATETIME NULL;
ALTER TABLE impersonation_sessions ADD INDEX IF NOT EXISTS idx_started (started_at);

CREATE TABLE IF NOT EXISTS impersonation_actions_log (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id     INT UNSIGNED NULL,
  admin_user_id  INT NULL,
  target_user_id INT NULL,
  action_type    VARCHAR(32) NULL,               -- page_view, form_post, destructive, other
  method         VARCHAR(10) NULL,
  url            VARCHAR(500) NULL,
  description    VARCHAR(500) NULL,
  details        TEXT NULL,                      -- champs postés, mots de passe et jetons masqués
  ip_address     VARCHAR(45) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_session (session_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE impersonation_actions_log ADD COLUMN IF NOT EXISTS session_id INT UNSIGNED NULL;
ALTER TABLE impersonation_actions_log ADD COLUMN IF NOT EXISTS admin_user_id INT NULL;
ALTER TABLE impersonation_actions_log ADD COLUMN IF NOT EXISTS target_user_id INT NULL;
ALTER TABLE impersonation_actions_log ADD COLUMN IF NOT EXISTS action_type VARCHAR(32) NULL;
ALTER TABLE impersonation_actions_log ADD COLUMN IF NOT EXISTS method VARCHAR(10) NULL;
ALTER TABLE impersonation_actions_log ADD COLUMN IF NOT EXISTS url VARCHAR(500) NULL;
ALTER TABLE impersonation_actions_log ADD COLUMN IF NOT EXISTS description VARCHAR(500) NULL;
ALTER TABLE impersonation_actions_log ADD COLUMN IF NOT EXISTS details TEXT NULL;
ALTER TABLE impersonation_actions_log ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL;
ALTER TABLE impersonation_actions_log ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE impersonation_actions_log ADD INDEX IF NOT EXISTS idx_session (session_id);

-- Codes de secours 2FA : JSON de 8 hachages bcrypt (~500 caractères)
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_backup_codes TEXT NULL;
ALTER TABLE users MODIFY totp_backup_codes TEXT NULL;
