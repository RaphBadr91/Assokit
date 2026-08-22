-- ============================================================
-- Migration : Widget WordPress "Espace projets" (lecture seule)
-- Cible : MariaDB 10.x. Idempotent (IF NOT EXISTS).
--   org_espace_tokens : jeton par organisation qui autorise l'affichage
--   PUBLIC EN LECTURE SEULE de la liste des projets (aucune connexion,
--   aucune donnée privée, aucune action possible). Remplace l'ancien SSO
--   par-clé (qui usurpait l'identité de l'admin — supprimé pour raison de
--   sécurité).
-- ============================================================
CREATE TABLE IF NOT EXISTS org_espace_tokens (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  org_id         INT NOT NULL,
  token          CHAR(64) NOT NULL,          -- jeton en clair (lecture seule, aucune donnée sensible)
  label          VARCHAR(120) NULL,
  revoked_at     DATETIME NULL,
  view_count     INT NOT NULL DEFAULT 0,
  last_viewed_at DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token),
  KEY idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
