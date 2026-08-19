-- ============================================================
-- Migration : Détection d'anomalies — anomalies ignorées
-- Cible : MariaDB 10.x. Idempotent (IF NOT EXISTS).
-- Permet à une org de masquer durablement une anomalie
-- (faux positif : facture annulée légitime, gros montant réel…).
-- L'empreinte (finding_hash) = sha1(category|title) : stable tant
-- que l'anomalie existe, réapparaît si un cas identique revient.
-- ============================================================
CREATE TABLE IF NOT EXISTS anomaly_dismissed (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id        INT NOT NULL,
  finding_hash  CHAR(40) NOT NULL,
  category      VARCHAR(48) NULL,
  dismissed_by  INT NULL,
  dismissed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_org_hash (org_id, finding_hash),
  KEY idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
