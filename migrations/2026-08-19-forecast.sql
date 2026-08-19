-- ============================================================
-- Migration : Dashboard prédictif — solde de trésorerie de départ
-- Cible : MariaDB 10.x. Idempotent (IF NOT EXISTS).
-- Le solde bancaire réel n'est pas connu de l'app : l'admin peut le
-- saisir pour ancrer la projection cumulée de trésorerie.
-- ============================================================
CREATE TABLE IF NOT EXISTS org_forecast_prefs (
  org_id             INT PRIMARY KEY,
  start_balance_cents BIGINT NOT NULL DEFAULT 0,   -- solde de trésorerie saisi (centimes)
  balance_set_at     DATETIME NULL,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
