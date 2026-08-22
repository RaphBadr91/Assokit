-- ============================================================
-- Migration : SSO WordPress (bouton "Ouvrir Assokit" connecté)
-- Cible : MariaDB 10.x. Idempotent (IF NOT EXISTS).
--   - sso_keys   : clé longue durée générée par l'utilisateur (stockée hashée)
--   - sso_tokens : jeton à usage unique, courte durée, échangé au moment du clic
-- ============================================================
CREATE TABLE IF NOT EXISTS sso_keys (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  org_id       INT NOT NULL,
  key_hash     CHAR(64) NOT NULL,          -- SHA-256 de la clé (la clé n'est jamais stockée en clair)
  label        VARCHAR(120) NULL,
  revoked      TINYINT(1) NOT NULL DEFAULT 0,
  last_used_at DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_key (key_hash),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sso_tokens (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  token_hash  CHAR(64) NOT NULL,           -- SHA-256 du jeton à usage unique
  used        TINYINT(1) NOT NULL DEFAULT 0,
  expires_at  DATETIME NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token_hash),
  KEY idx_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
