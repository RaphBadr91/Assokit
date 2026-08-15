-- ============================================================
-- Migration : intégrité facturation — facture électronique (DGFiP)
-- Objet     : piste d'audit fiable + support avoir (note de crédit)
--             + unicité de la numérotation (EN 16931 / art. 289 CGI).
-- Cible     : MariaDB 10.x (O2switch). Idempotent (IF NOT EXISTS).
--
-- ⚠️ CETTE MIGRATION EST EN DEUX PARTIES.
--    PARTIE A (ci-dessous) : SANS unicité -> peut être passée immédiatement, aucun risque.
--    PARTIE B (fichier séparé 2026-08-12-facture-electronique-unicite.sql) :
--            ajoute les contraintes UNIQUE -> à passer APRÈS avoir dédoublonné
--            (voir 2026-08-12-diagnostic-doublons.sql).
-- ============================================================

-- ------------------------------------------------------------
-- A1) Support AVOIR (note de crédit) — corriger une facture émise
--     sans jamais la modifier (piste d'audit fiable).
--     invoice_type : 'invoice' (facture) | 'credit_note' (avoir)
--     parent_invoice_id : facture d'origine rattachée à l'avoir
-- ------------------------------------------------------------
ALTER TABLE asso_invoices
  ADD COLUMN IF NOT EXISTS invoice_type VARCHAR(20) NOT NULL DEFAULT 'invoice' AFTER status;

ALTER TABLE asso_invoices
  ADD COLUMN IF NOT EXISTS parent_invoice_id INT UNSIGNED NULL DEFAULT NULL AFTER invoice_type;

ALTER TABLE asso_invoices
  ADD KEY IF NOT EXISTS idx_parent_invoice (parent_invoice_id);

-- ------------------------------------------------------------
-- A2) Scellement / immutabilité — traçabilité de la finalisation
--     finalized_at   : date de passage brouillon -> émise (verrou métier)
--     content_hash   : empreinte SHA-256 du contenu figé à la finalisation
-- ------------------------------------------------------------
ALTER TABLE asso_invoices
  ADD COLUMN IF NOT EXISTS finalized_at DATETIME NULL DEFAULT NULL AFTER updated_at;

ALTER TABLE asso_invoices
  ADD COLUMN IF NOT EXISTS content_hash CHAR(64) NULL DEFAULT NULL AFTER finalized_at;

-- ------------------------------------------------------------
-- A3) Journal d'audit facturation (append-only) — piste d'audit fiable
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS asso_invoice_audit (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id        INT UNSIGNED NOT NULL,
  invoice_id    INT UNSIGNED NOT NULL,
  event_type    VARCHAR(40) NOT NULL,          -- created | finalized | status_change | credit_note | sent | paid | pdf_generated
  actor_user_id INT UNSIGNED NULL,
  actor_label   VARCHAR(190) NULL,             -- email/rôle au moment de l'action (ou 'cron', 'system')
  from_value    VARCHAR(190) NULL,
  to_value      VARCHAR(190) NULL,
  content_hash  CHAR(64) NULL,                 -- empreinte de l'état après l'événement
  ip            VARBINARY(16) NULL,
  meta_json     TEXT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_invoice (invoice_id),
  KEY idx_audit_org_date (org_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- FIN PARTIE A. Les contraintes UNIQUE sont dans le fichier
-- 2026-08-12-facture-electronique-unicite.sql (à passer après dédoublonnage).
-- ------------------------------------------------------------
