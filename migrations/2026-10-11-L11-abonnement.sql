-- ============================================================
-- Migration L11 — Abonnement : schéma des crons et des factures Assokit
-- ============================================================
-- Rien de tout cela n'était versionné : cron.php, le cockpit CRON
-- (admin-cron*.php) et les factures de la plateforme (table invoices)
-- dépendaient de tables créées à la main en production.
--   1) cron_runs, cron_email_log, cron_admin_logins (journal des crons,
--      e-mails envoyés, connexions au cockpit) ;
--   2) colonnes de la table invoices lues ou écrites par le cockpit
--      Factures (ak_create_invoice) ET par les crons (renouvellements,
--      relances : amount_cents, due_date, reminder_j*_sent_at) ;
--   3) vue v_cron_dashboard (chiffres du cockpit CRON) ;
--   4) une seule séquence de numérotation AK-AAAA-NNNNNN : invoice_sequences
--      est recalée sur le plus grand numéro déjà émis (l'ancien cron de
--      renouvellement numérotait à part, sans la mettre à jour) ;
--   5) index UNIQUE sur invoices.invoice_number (dernière instruction).
--
-- Idempotent (IF NOT EXISTS partout) : sans effet sur ce qui existe déjà,
-- ne modifie ni ne supprime aucune donnée. Cible : MariaDB 10.x.
-- Lancement : /usr/local/bin/php migrations/run.php 2026-10-11-L11-abonnement.sql
-- ============================================================

-- ---------- 1) Journal des crons ----------
CREATE TABLE IF NOT EXISTS cron_runs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_name VARCHAR(50) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'running',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  items_processed INT UNSIGNED NOT NULL DEFAULT 0,
  items_succeeded INT UNSIGNED NOT NULL DEFAULT 0,
  items_failed INT UNSIGNED NOT NULL DEFAULT 0,
  emails_sent INT UNSIGNED NOT NULL DEFAULT 0,
  details MEDIUMTEXT NULL,
  error_message TEXT NULL,
  triggered_by VARCHAR(20) NOT NULL DEFAULT 'cron',
  triggered_by_user_id INT UNSIGNED NULL,
  KEY idx_cron_runs_job (job_name, started_at),
  KEY idx_cron_runs_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cron_runs
  ADD COLUMN IF NOT EXISTS job_name VARCHAR(50) NULL,
  ADD COLUMN IF NOT EXISTS status VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS started_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS finished_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS items_processed INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS items_succeeded INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS items_failed INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS emails_sent INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS details MEDIUMTEXT NULL,
  ADD COLUMN IF NOT EXISTS error_message TEXT NULL,
  ADD COLUMN IF NOT EXISTS triggered_by VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS triggered_by_user_id INT UNSIGNED NULL;

ALTER TABLE cron_runs ADD INDEX IF NOT EXISTS idx_cron_runs_started (started_at);

CREATE TABLE IF NOT EXISTS cron_email_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cron_run_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  invoice_id INT UNSIGNED NULL,
  org_id INT UNSIGNED NULL,
  email_to VARCHAR(191) NOT NULL DEFAULT '',
  email_type VARCHAR(50) NOT NULL DEFAULT '',
  subject VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'sent',
  provider_id VARCHAR(100) NULL,
  error_message TEXT NULL,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cron_email_run (cron_run_id),
  KEY idx_cron_email_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cron_email_log
  ADD COLUMN IF NOT EXISTS cron_run_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS invoice_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS org_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS email_to VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS email_type VARCHAR(50) NULL,
  ADD COLUMN IF NOT EXISTS subject VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS status VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS provider_id VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS error_message TEXT NULL,
  ADD COLUMN IF NOT EXISTS sent_at DATETIME NULL;

ALTER TABLE cron_email_log ADD INDEX IF NOT EXISTS idx_cron_email_run (cron_run_id);

CREATE TABLE IF NOT EXISTS cron_admin_logins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email_attempted VARCHAR(191) NULL,
  user_id INT UNSIGNED NULL,
  status VARCHAR(30) NOT NULL DEFAULT '',
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  error_detail VARCHAR(255) NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cron_admin_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cron_admin_logins
  ADD COLUMN IF NOT EXISTS email_attempted VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS status VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL,
  ADD COLUMN IF NOT EXISTS user_agent VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS error_detail VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS attempted_at DATETIME NULL;

-- ---------- 2) Factures de la plateforme (cockpit Factures + crons) ----------
CREATE TABLE IF NOT EXISTS invoices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  invoice_number VARCHAR(32) NULL,
  invoice_year SMALLINT UNSIGNED NULL,
  invoice_sequence INT UNSIGNED NULL,
  issued_at DATETIME NULL,
  due_at DATETIME NULL,
  due_date DATE NULL,
  amount_cents INT NULL,
  amount_ht_cents INT NULL,
  vat_rate DECIMAL(5,2) NULL,
  amount_vat_cents INT NULL,
  amount_ttc_cents INT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  period_start DATE NULL,
  period_end DATE NULL,
  description VARCHAR(255) NULL,
  plan_code VARCHAR(50) NULL,
  emitter_snapshot MEDIUMTEXT NULL,
  client_snapshot MEDIUMTEXT NULL,
  is_test_mode TINYINT(1) NOT NULL DEFAULT 0,
  created_by_user_id INT UNSIGNED NULL,
  pdf_path VARCHAR(255) NULL,
  pdf_generated_at DATETIME NULL,
  paid_at DATETIME NULL,
  internal_notes TEXT NULL,
  reminder_j3_sent_at DATETIME NULL,
  reminder_j7_sent_at DATETIME NULL,
  reminder_j15_sent_at DATETIME NULL,
  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  KEY idx_invoices_org (org_id),
  KEY idx_invoices_status_due (status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS org_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS invoice_number VARCHAR(32) NULL,
  ADD COLUMN IF NOT EXISTS invoice_year SMALLINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS invoice_sequence INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS issued_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS due_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS due_date DATE NULL,
  ADD COLUMN IF NOT EXISTS amount_cents INT NULL,
  ADD COLUMN IF NOT EXISTS amount_ht_cents INT NULL,
  ADD COLUMN IF NOT EXISTS vat_rate DECIMAL(5,2) NULL,
  ADD COLUMN IF NOT EXISTS amount_vat_cents INT NULL,
  ADD COLUMN IF NOT EXISTS amount_ttc_cents INT NULL,
  ADD COLUMN IF NOT EXISTS currency CHAR(3) NOT NULL DEFAULT 'EUR',
  ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS period_start DATE NULL,
  ADD COLUMN IF NOT EXISTS period_end DATE NULL,
  ADD COLUMN IF NOT EXISTS description VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS plan_code VARCHAR(50) NULL,
  ADD COLUMN IF NOT EXISTS emitter_snapshot MEDIUMTEXT NULL,
  ADD COLUMN IF NOT EXISTS client_snapshot MEDIUMTEXT NULL,
  ADD COLUMN IF NOT EXISTS is_test_mode TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS created_by_user_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS pdf_path VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS pdf_generated_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS internal_notes TEXT NULL,
  ADD COLUMN IF NOT EXISTS reminder_j3_sent_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS reminder_j7_sent_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS reminder_j15_sent_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;

ALTER TABLE invoices ADD INDEX IF NOT EXISTS idx_invoices_org (org_id);

CREATE TABLE IF NOT EXISTS invoice_sequences (
  year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
  next_sequence INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 3) Chiffres du cockpit CRON (admin-cron.php) ----------
-- Créée seulement si elle n'existe pas : une vue déjà en place n'est pas remplacée.
CREATE VIEW IF NOT EXISTS v_cron_dashboard AS
SELECT
  (SELECT COUNT(*) FROM cron_runs WHERE started_at >= NOW() - INTERVAL 30 DAY) AS runs_30d,
  (SELECT COUNT(*) FROM cron_runs WHERE started_at >= NOW() - INTERVAL 30 DAY AND status = 'error') AS errors_30d,
  (SELECT COUNT(*) FROM cron_email_log WHERE sent_at >= NOW() - INTERVAL 30 DAY AND status = 'sent') AS emails_30d,
  (SELECT COUNT(*) FROM cron_email_log WHERE sent_at >= NOW() - INTERVAL 30 DAY AND status = 'failed') AS emails_failed_30d,
  (SELECT COUNT(*) FROM organizations WHERE status = 'trial' AND deleted_at IS NULL) AS trials_active,
  (SELECT COUNT(*) FROM organizations WHERE status = 'trial' AND deleted_at IS NULL
      AND trial_ends_at BETWEEN NOW() AND NOW() + INTERVAL 7 DAY) AS trials_ending_soon,
  (SELECT COUNT(*) FROM organizations WHERE status = 'active' AND deleted_at IS NULL) AS orgs_active,
  (SELECT COUNT(*) FROM invoices WHERE status = 'pending' AND COALESCE(is_test_mode, 0) = 0) AS invoices_pending,
  (SELECT COUNT(*) FROM invoices WHERE status = 'overdue' AND COALESCE(is_test_mode, 0) = 0) AS invoices_overdue,
  (SELECT COALESCE(SUM(COALESCE(NULLIF(amount_ttc_cents, 0), amount_cents, 0)), 0) FROM invoices
      WHERE status = 'pending' AND COALESCE(is_test_mode, 0) = 0) AS amount_pending_cents,
  (SELECT COALESCE(SUM(COALESCE(NULLIF(amount_ttc_cents, 0), amount_cents, 0)), 0) FROM invoices
      WHERE status = 'overdue' AND COALESCE(is_test_mode, 0) = 0) AS amount_overdue_cents;

-- ---------- 4) Séquence AK-AAAA-NNNNNN recalée sur les numéros déjà émis ----------
-- Ne fait que remonter next_sequence (jamais la baisser).
INSERT INTO invoice_sequences (year, next_sequence)
SELECT CAST(SUBSTRING(invoice_number, 4, 4) AS UNSIGNED) AS y,
       MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)) + 1 AS nxt
FROM invoices
WHERE invoice_number REGEXP '^AK-[0-9]{4}-[0-9]+$'
GROUP BY CAST(SUBSTRING(invoice_number, 4, 4) AS UNSIGNED)
ON DUPLICATE KEY UPDATE next_sequence = GREATEST(next_sequence, VALUES(next_sequence));

-- ---------- 5) Numéro de facture unique ----------
-- ⚠️ Échoue (#1062) s'il existe déjà des doublons, sans effet sur ce qui précède.
--    Les lister : SELECT invoice_number, COUNT(*) FROM invoices
--                 GROUP BY invoice_number HAVING COUNT(*) > 1
--    puis les régulariser (avoir + nouvelle facture) et relancer ce fichier.
ALTER TABLE invoices ADD UNIQUE KEY IF NOT EXISTS uq_invoices_number (invoice_number);
