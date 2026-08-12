-- ============================================================
-- Migration : intégrité facturation — facture électronique (DGFiP)
-- Objet     : piste d'audit fiable + support avoir (note de crédit)
--             + unicité de la numérotation (EN 16931 / art. 289 CGI).
-- Cible     : MariaDB 10.x (O2switch). Idempotent (IF NOT EXISTS).
-- A exécuter une fois :
--   mysql -u pura7044_ak pura7044_assokit < migrations/2026-08-12-facture-electronique-integrite.sql
--
-- ⚠️ PRÉ-REQUIS (unicité) : les index UNIQUE échouent si des doublons
--    existent déjà. Vérifier AVANT de lancer la migration :
--
--    SELECT org_id, invoice_year, invoice_sequence, COUNT(*) c
--      FROM asso_invoices
--     GROUP BY org_id, invoice_year, invoice_sequence
--    HAVING c > 1;
--
--    SELECT org_id, invoice_number, COUNT(*) c
--      FROM asso_invoices
--     GROUP BY org_id, invoice_number
--    HAVING c > 1;
--
--    Si des lignes remontent : corriger/renuméroter les doublons (via avoir
--    ou renumérotation des brouillons) avant d'ajouter les contraintes.
--    Le code applicatif (ak_asso_invoice_next_number_parts) empêche déjà la
--    création de nouveaux doublons ; cette contrainte est la ceinture + bretelles.
-- ============================================================

-- ------------------------------------------------------------
-- 1) Unicité de la numérotation (anti-doublon définitif)
-- ------------------------------------------------------------
ALTER TABLE asso_invoices
  ADD UNIQUE KEY IF NOT EXISTS uniq_org_year_seq (org_id, invoice_year, invoice_sequence);

ALTER TABLE asso_invoices
  ADD UNIQUE KEY IF NOT EXISTS uniq_org_invoice_number (org_id, invoice_number);

-- ------------------------------------------------------------
-- 2) Support AVOIR (note de crédit) — corriger une facture émise
--    sans jamais la modifier (piste d'audit fiable).
--    invoice_type : 'invoice' (facture) | 'credit_note' (avoir)
--    parent_invoice_id : facture d'origine rattachée à l'avoir
-- ------------------------------------------------------------
ALTER TABLE asso_invoices
  ADD COLUMN IF NOT EXISTS invoice_type VARCHAR(20) NOT NULL DEFAULT 'invoice' AFTER status;

ALTER TABLE asso_invoices
  ADD COLUMN IF NOT EXISTS parent_invoice_id INT UNSIGNED NULL DEFAULT NULL AFTER invoice_type;

ALTER TABLE asso_invoices
  ADD KEY IF NOT EXISTS idx_parent_invoice (parent_invoice_id);

-- ------------------------------------------------------------
-- 3) Scellement / immutabilité — traçabilité de la finalisation
--    finalized_at   : date de passage brouillon -> émise (verrou métier)
--    content_hash   : empreinte SHA-256 du contenu figé à la finalisation
--                     (montants + lignes + snapshots), preuve d'intégrité
-- ------------------------------------------------------------
ALTER TABLE asso_invoices
  ADD COLUMN IF NOT EXISTS finalized_at DATETIME NULL DEFAULT NULL AFTER updated_at;

ALTER TABLE asso_invoices
  ADD COLUMN IF NOT EXISTS content_hash CHAR(64) NULL DEFAULT NULL AFTER finalized_at;

-- ------------------------------------------------------------
-- 4) Journal d'audit facturation (append-only) — piste d'audit fiable
--    Chaque événement significatif (création, finalisation, changement de
--    statut, émission d'avoir, envoi, paiement) est journalisé de manière
--    inaltérable, avec acteur et empreinte.
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
-- FIN. Après passage :
--  - toute nouvelle facture reçoit un numéro atomique unique (code + contrainte)
--  - une facture émise ne peut plus être modifiée (garde applicatif)
--  - les corrections passent par un avoir (invoice_type='credit_note')
--  - chaque action est traçable dans asso_invoice_audit
-- ------------------------------------------------------------
