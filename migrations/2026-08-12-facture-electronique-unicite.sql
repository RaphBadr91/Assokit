-- ============================================================
-- Migration PARTIE B : contraintes UNIQUE de numérotation
-- ============================================================
-- ⚠️ À PASSER UNIQUEMENT APRÈS AVOIR RÉSOLU LES DOUBLONS.
--    Vérifier avec 2026-08-12-diagnostic-doublons.sql que les requêtes 1) et 2)
--    ne renvoient PLUS aucune ligne. Sinon ces ALTER échoueront (#1062).
--
-- Le code applicatif (ak_asso_invoice_next_number_parts) empêche déjà la
-- création de nouveaux doublons ; ces contraintes sont la ceinture + bretelles.
-- Cible : MariaDB 10.x. Idempotent (IF NOT EXISTS).
-- ============================================================

ALTER TABLE asso_invoices
  ADD UNIQUE KEY IF NOT EXISTS uniq_org_year_seq (org_id, invoice_year, invoice_sequence);

ALTER TABLE asso_invoices
  ADD UNIQUE KEY IF NOT EXISTS uniq_org_invoice_number (org_id, invoice_number);
