-- ============================================================
-- Facturation : historique des factures récurrentes + avoirs
-- ============================================================
-- 1) asso_invoice_recurrence_runs : une ligne par génération d'une
--    facture récurrente (cron ou bouton « Générer maintenant »),
--    réussie ou en échec avec son motif. Affichée dans « Historique
--    des générations » de /mon-asso-recurrence-edit.
--    Aucun CREATE TABLE n'était versionné et rien n'y écrivait. Sans
--    la table, la génération et la suppression d'une récurrence
--    fonctionnent quand même, simplement sans historique.
--    Une table existante n'est pas modifiée, sauf ajout des colonnes
--    et de l'index manquants.
--
-- 2) Avoirs (« Créer un avoir » sur une facture émise) : colonnes et
--    journal de la migration 2026-08-12-facture-electronique-integrite.sql
--    (partie A), repris ici à l'identique au cas où elle n'aurait pas
--    été passée. Sans eux, le bouton « Créer un avoir » explique que
--    la base doit être mise à jour.
--
-- Idempotent, sans perte : on peut relancer.
--   /usr/local/bin/php migrations/run.php 2026-10-11-L05-facturation.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS asso_invoice_recurrence_runs (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recurrence_id  INT UNSIGNED NOT NULL,
  org_id         INT UNSIGNED NULL DEFAULT NULL,
  invoice_id     INT UNSIGNED NULL DEFAULT NULL,
  run_date       DATE NOT NULL,
  status         VARCHAR(20) NOT NULL DEFAULT 'success',
  error_message  TEXT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_recurrence (recurrence_id),
  KEY idx_org_date (org_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE asso_invoice_recurrence_runs ADD COLUMN IF NOT EXISTS org_id INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE asso_invoice_recurrence_runs ADD COLUMN IF NOT EXISTS invoice_id INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE asso_invoice_recurrence_runs ADD COLUMN IF NOT EXISTS run_date DATE NULL DEFAULT NULL;
ALTER TABLE asso_invoice_recurrence_runs ADD COLUMN IF NOT EXISTS status VARCHAR(20) NULL DEFAULT 'success';
ALTER TABLE asso_invoice_recurrence_runs ADD COLUMN IF NOT EXISTS error_message TEXT NULL;
ALTER TABLE asso_invoice_recurrence_runs ADD COLUMN IF NOT EXISTS created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE asso_invoice_recurrence_runs ADD KEY IF NOT EXISTS idx_recurrence (recurrence_id);

-- Avoirs : type de pièce et facture d'origine (identique à 2026-08-12, partie A)
ALTER TABLE asso_invoices
  ADD COLUMN IF NOT EXISTS invoice_type VARCHAR(20) NOT NULL DEFAULT 'invoice';

ALTER TABLE asso_invoices
  ADD COLUMN IF NOT EXISTS parent_invoice_id INT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE asso_invoices
  ADD KEY IF NOT EXISTS idx_parent_invoice (parent_invoice_id);

ALTER TABLE asso_invoices
  ADD COLUMN IF NOT EXISTS finalized_at DATETIME NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS asso_invoice_audit (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id        INT UNSIGNED NOT NULL,
  invoice_id    INT UNSIGNED NOT NULL,
  event_type    VARCHAR(40) NOT NULL,
  actor_user_id INT UNSIGNED NULL,
  actor_label   VARCHAR(190) NULL,
  from_value    VARCHAR(190) NULL,
  to_value      VARCHAR(190) NULL,
  content_hash  CHAR(64) NULL,
  ip            VARBINARY(16) NULL,
  meta_json     TEXT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_invoice (invoice_id),
  KEY idx_audit_org_date (org_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
