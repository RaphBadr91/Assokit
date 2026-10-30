-- ============================================================
-- Souscription pendant l'essai : le premier prélèvement attend la fin de l'essai
-- ============================================================
-- Une association en essai gratuit qui souscrit garde tous ses jours d'essai : sa carte
-- (ou son mandat SEPA, Apple Pay, Google Pay) est enregistrée tout de suite, et Stripe
-- prélève le premier mois automatiquement le jour où l'essai se termine.
-- Tant que ce premier prélèvement n'a pas eu lieu, l'association reste en essai ; ces
-- colonnes le notent (stripe-sync-helpers.php, ak_stripe_apply_trial_subscription) :
--   - stripe_trial_sub_id       : abonnement Stripe souscrit pendant l'essai (sub_…) ;
--   - stripe_trial_charge_at    : date du premier prélèvement (fin de l'essai) ;
--   - stripe_trial_charge_cents : montant du premier prélèvement (TTC, en centimes) ;
--   - stripe_trial_charge_tax_cents : dont TVA (0 : TVA non applicable, art. 293 B du CGI) ; la mention
--                                 « TTC » suit l'abonnement Stripe, pas l'interrupteur de TVA du jour ;
--   - stripe_trial_pm_type      : moyen enregistré (card, sepa_debit).
-- Elles sont effacées au premier prélèvement réussi ou refusé, et à l'annulation.
-- Pendant ce temps, la fin d'essai (cron-trial-check.php) ne suspend pas l'association
-- et les rappels « choisissez votre formule » ne partent pas.
--
-- Idempotent : sans effet sur ce qui existe déjà, ne supprime aucune donnée.
-- Cible : MariaDB 10.x. À lancer APRÈS 2026-10-28-stripe-mise-en-service.sql.
-- Déjà lancée avant l'ajout de stripe_trial_charge_tax_cents : la relancer ajoute seulement cette colonne.
-- Lancement : /usr/local/bin/php migrations/run.php 2026-10-29-stripe-souscription-pendant-essai.sql
-- ============================================================

ALTER TABLE organizations
  ADD COLUMN IF NOT EXISTS stripe_trial_sub_id VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS stripe_trial_charge_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS stripe_trial_charge_cents INT NULL,
  ADD COLUMN IF NOT EXISTS stripe_trial_charge_tax_cents INT NULL,
  ADD COLUMN IF NOT EXISTS stripe_trial_pm_type VARCHAR(20) NULL;

ALTER TABLE organizations ADD INDEX IF NOT EXISTS idx_org_stripe_trial_sub (stripe_trial_sub_id);
