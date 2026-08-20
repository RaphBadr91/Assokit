-- ============================================================
-- Migration : Notes de frais (remboursements bénévoles/salariés)
-- Cible : MariaDB 10.x. Idempotent (IF NOT EXISTS).
--   - expense_reports       : une note de frais (en-tête + statut)
--   - expense_report_lines  : lignes (justificatif OU indemnité kilométrique)
-- ============================================================
CREATE TABLE IF NOT EXISTS expense_reports (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  org_id      INT NOT NULL,
  user_id     INT NOT NULL,                     -- auteur / bénéficiaire du remboursement
  title       VARCHAR(160) NOT NULL,
  status      ENUM('draft','submitted','approved','rejected','reimbursed') NOT NULL DEFAULT 'draft',
  total_cents BIGINT NOT NULL DEFAULT 0,
  note        VARCHAR(500) NULL,
  decided_by  INT NULL,
  decided_at  DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_org (org_id), KEY idx_user (org_id, user_id), KEY idx_status (org_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_report_lines (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  report_id    INT NOT NULL,
  org_id       INT NOT NULL,
  spent_at     DATE NULL,
  mode         ENUM('receipt','mileage') NOT NULL DEFAULT 'receipt',
  category     VARCHAR(48) NULL,                 -- transport, repas, hebergement, fourniture, autre
  description  VARCHAR(255) NULL,
  amount_ttc_cents BIGINT NOT NULL DEFAULT 0,
  vat_cents    BIGINT NOT NULL DEFAULT 0,
  km           INT NULL,                         -- si mode = mileage
  vehicle_cv   TINYINT NULL,                     -- puissance fiscale (CV) si mileage
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_report (report_id), KEY idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
