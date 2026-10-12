-- ============================================================
-- L02 — Cotisations : campagnes qui renouvellent l'adhésion
-- ============================================================
-- Idempotent : on peut relancer.
--   /usr/local/bin/php migrations/run.php 2026-10-11-L02-adherents.sql
--
-- cotisation_campaigns.renews_membership : un paiement encaissé sur la
-- campagne prolonge-t-il l'adhésion de la personne (users.adhesion_valid_until,
-- lue par les relances, les assemblées et l'appli) ?
--   NULL = automatique : oui si le nom contient « adhésion » ou « cotisation »
--   1    = oui
--   0    = non (participation aux frais d'ateliers, billetterie, dons…)
-- Sans cette migration, le choix n'est pas proposé dans le formulaire de la
-- campagne et la règle automatique s'applique à toutes les campagnes.
-- ============================================================

ALTER TABLE cotisation_campaigns ADD COLUMN IF NOT EXISTS renews_membership TINYINT(1) NULL DEFAULT NULL;
