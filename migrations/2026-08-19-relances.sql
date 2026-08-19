-- ============================================================
-- Migration : Relances intelligentes
-- Cible : MariaDB 10.x. Idempotent (IF NOT EXISTS).
--   - asso_membership_reminders : journal des relances de cotisation
--   - org_relance_prefs          : préférences (auto-relance, stade max, cadence)
-- Les relances de FACTURES réutilisent l'infra existante
-- (asso_invoice_emails_log, email_type dunning1/2/3).
-- ============================================================

CREATE TABLE IF NOT EXISTS asso_membership_reminders (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id          INT NOT NULL,
  user_id         INT NOT NULL,
  stage           TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- 1 rappel, 2 relance, 3 dernier rappel
  channel         VARCHAR(16) NOT NULL DEFAULT 'email',
  sent_by_user_id INT NULL,
  sent_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_org_user (org_id, user_id),
  KEY idx_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS org_relance_prefs (
  org_id             INT PRIMARY KEY,
  auto_invoices      TINYINT(1) NOT NULL DEFAULT 0,   -- envoi automatique des relances factures
  auto_memberships   TINYINT(1) NOT NULL DEFAULT 0,   -- envoi automatique des relances cotisations
  max_stage          TINYINT UNSIGNED NOT NULL DEFAULT 2, -- ne pas dépasser ce stade en auto (1..3)
  min_gap_days       TINYINT UNSIGNED NOT NULL DEFAULT 7,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
