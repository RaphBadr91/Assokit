-- ============================================================
-- Migration : journal d'audit du Copilote IA
-- Cible : MariaDB 10.x. Idempotent (IF NOT EXISTS).
-- On journalise la question + l'intention choisie (pas les données
-- personnelles renvoyées → minimisation RGPD).
-- ============================================================
CREATE TABLE IF NOT EXISTS copilote_audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id      INT NOT NULL,
  user_id     INT NOT NULL,
  question    VARCHAR(500) NOT NULL,
  intent      VARCHAR(64) NULL,
  row_count   INT NOT NULL DEFAULT 0,
  latency_ms  INT NULL,
  status      VARCHAR(32) NOT NULL DEFAULT 'ok',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_org_created (org_id, created_at),
  KEY idx_intent (intent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
