-- ============================================================
-- Migration : journal d'envoi des alertes du Radar de subventions
-- Cible : MariaDB 10.x. Idempotent (IF NOT EXISTS).
-- Sert à ne jamais renvoyer 2x la même alerte (nouvelle piste,
-- échéance J-30, J-7) pour un couple (org, dispositif).
-- ============================================================
CREATE TABLE IF NOT EXISTS grant_alert_sent (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id      INT NOT NULL,
  catalog_id  INT NOT NULL,
  alert_type  VARCHAR(32) NOT NULL,   -- new_match | deadline_30 | deadline_7
  sent_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alert (org_id, catalog_id, alert_type),
  KEY idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
