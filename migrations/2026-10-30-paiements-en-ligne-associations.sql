-- ============================================================
-- Paiements en ligne reçus par les associations (Stripe Connect)
-- ============================================================
-- Chaque association peut relier SON compte Stripe (compte « Standard » créé depuis
-- Assokit, page /encaissement-en-ligne) et faire payer en ligne, par carte, Apple Pay,
-- Google Pay ou prélèvement SEPA : ses factures, ses cotisations et ses dons.
-- L'argent va directement sur le compte Stripe de l'association (paiement « direct »),
-- les frais Stripe sont à sa charge, Assokit ne prend aucune commission et ne détient
-- jamais les fonds. Code : stripe-connect-helpers.php, stripe-connect-webhook.php.
--   1) org_stripe_accounts : compte Stripe relié, par association et par mode (test / réel)
--   2) org_online_payment_settings : ce que l'association fait payer en ligne
--      (factures, cotisations, dons) et si le prélèvement SEPA est proposé
--   3) asso_online_payments : chaque paiement en ligne (session Stripe Checkout,
--      paiement, frais Stripe, remboursement), relié à la facture ou à la cotisation
--      enregistrée exactement comme un paiement saisi à la main
--   4) colonnes : événement d'un compte connecté (asso_stripe_events.connect_account),
--      campagne de dons et montant libre (cotisation_campaigns), règlement en ligne
--      d'une facture (asso_invoice_payments.online_payment_id), valeur « stripe »
--      de asso_invoice_payments.source (seulement si c'est un ENUM)
--
-- Les réglages de la plateforme (interrupteur, secret du webhook Connect, secret des
-- liens personnels de cotisation) sont des lignes de asso_stripe_config : rien à créer.
--
-- Idempotent : sans effet sur ce qui existe déjà, ne supprime aucune donnée (les anciennes
-- clés Stripe saisies par des associations dans org_payment_settings ne sont pas effacées :
-- voir docs/stripe-mise-en-service.md).
-- Cible : MariaDB 10.x. À lancer APRÈS 2026-10-28-stripe-mise-en-service.sql.
-- Lancement : /usr/local/bin/php migrations/run.php 2026-10-30-paiements-en-ligne-associations.sql
-- ============================================================

-- ---------- 1) Compte Stripe relié de chaque association ----------
CREATE TABLE IF NOT EXISTS org_stripe_accounts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  org_id INT NOT NULL,
  mode VARCHAR(4) NOT NULL,
  stripe_account_id VARCHAR(64) NOT NULL,
  charges_enabled TINYINT(1) NOT NULL DEFAULT 0,
  payouts_enabled TINYINT(1) NOT NULL DEFAULT 0,
  details_submitted TINYINT(1) NOT NULL DEFAULT 0,
  card_capability VARCHAR(20) NULL,
  sepa_capability VARCHAR(20) NULL,
  requirements_json TEXT NULL,
  disabled_reason VARCHAR(100) NULL,
  display_name VARCHAR(255) NULL,
  connected_by_user_id INT NULL,
  connected_at DATETIME NULL,
  updated_at DATETIME NULL,
  disconnected_at DATETIME NULL,
  UNIQUE KEY uq_org_stripe_account_mode (org_id, mode),
  UNIQUE KEY uq_org_stripe_account_id (stripe_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE org_stripe_accounts
  ADD COLUMN IF NOT EXISTS charges_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS payouts_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS details_submitted TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS card_capability VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS sepa_capability VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS requirements_json TEXT NULL,
  ADD COLUMN IF NOT EXISTS disabled_reason VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS display_name VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS connected_by_user_id INT NULL,
  ADD COLUMN IF NOT EXISTS connected_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS disconnected_at DATETIME NULL;

-- ---------- 2) Ce que l'association fait payer en ligne ----------
CREATE TABLE IF NOT EXISTS org_online_payment_settings (
  org_id INT NOT NULL PRIMARY KEY,
  invoices_enabled TINYINT(1) NOT NULL DEFAULT 1,
  cotisations_enabled TINYINT(1) NOT NULL DEFAULT 1,
  dons_enabled TINYINT(1) NOT NULL DEFAULT 0,
  sepa_enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NULL,
  updated_by_user_id INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE org_online_payment_settings
  ADD COLUMN IF NOT EXISTS invoices_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS cotisations_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS dons_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS sepa_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS updated_by_user_id INT NULL;

-- ---------- 3) Paiements en ligne ----------
-- status : open (page Stripe ouverte), processing (prélèvement SEPA en attente de la banque),
-- paid, failed, expired, refunded, partially_refunded, disputed. Montants en centimes.
CREATE TABLE IF NOT EXISTS asso_online_payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  org_id INT NOT NULL,
  mode VARCHAR(4) NOT NULL,
  stripe_account_id VARCHAR(64) NOT NULL,
  kind VARCHAR(20) NOT NULL,
  invoice_id INT NULL,
  campaign_id INT NULL,
  tier_id INT NULL,
  adherent_id INT NULL,
  payer_name VARCHAR(255) NULL,
  payer_email VARCHAR(255) NULL,
  amount_cents INT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  application_fee_cents INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  checkout_session_id VARCHAR(191) NULL,
  checkout_expires_at DATETIME NULL,
  payment_intent_id VARCHAR(191) NULL,
  charge_id VARCHAR(191) NULL,
  payment_method_type VARCHAR(30) NULL,
  fee_cents INT NULL,
  net_cents INT NULL,
  refunded_cents INT NOT NULL DEFAULT 0,
  paid_at DATETIME NULL,
  failure_message VARCHAR(500) NULL,
  overpaid TINYINT(1) NOT NULL DEFAULT 0,
  cotisation_payment_id INT NULL,
  invoice_payment_id INT NULL,
  created_ip VARCHAR(45) NULL,
  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_online_payment_session (checkout_session_id),
  KEY idx_online_payment_pi (payment_intent_id),
  KEY idx_online_payment_org (org_id, created_at),
  KEY idx_online_payment_invoice (invoice_id, status),
  KEY idx_online_payment_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE asso_online_payments
  ADD COLUMN IF NOT EXISTS checkout_expires_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS charge_id VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS payment_method_type VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS fee_cents INT NULL,
  ADD COLUMN IF NOT EXISTS net_cents INT NULL,
  ADD COLUMN IF NOT EXISTS refunded_cents INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS failure_message VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS overpaid TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS cotisation_payment_id INT NULL,
  ADD COLUMN IF NOT EXISTS invoice_payment_id INT NULL,
  ADD COLUMN IF NOT EXISTS created_ip VARCHAR(45) NULL,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;

-- ---------- 4) Colonnes des tables existantes ----------
-- Événement d'un compte connecté (journal commun : même idempotence que les abonnements)
ALTER TABLE asso_stripe_events
  ADD COLUMN IF NOT EXISTS connect_account VARCHAR(64) NULL;

-- Campagne : cotisation (NULL) ou don ('don') ; paiement en ligne proposé ; montant libre et minimum
ALTER TABLE cotisation_campaigns
  ADD COLUMN IF NOT EXISTS kind VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS online_payment TINYINT(1) NULL,
  ADD COLUMN IF NOT EXISTS free_amount TINYINT(1) NULL,
  ADD COLUMN IF NOT EXISTS min_amount DECIMAL(10,2) NULL;

-- Lien de paiement d'une campagne : jeton unique (une ligne par jeton)
ALTER TABLE cotisation_campaigns ADD INDEX IF NOT EXISTS idx_cotisation_campaign_token (public_token);

-- Paiement en ligne d'une cotisation ou d'une facture retrouvé depuis la ligne de paiement
ALTER TABLE cotisation_payments ADD COLUMN IF NOT EXISTS online_payment_id INT UNSIGNED NULL;
ALTER TABLE asso_invoice_payments ADD COLUMN IF NOT EXISTS online_payment_id INT UNSIGNED NULL;

-- asso_invoice_payments.source : 'stripe' ajouté seulement si la colonne est un ENUM auquel il manque
-- (liste existante gardée telle quelle, même NULL / NOT NULL et même valeur par défaut)
SET @ak_t = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asso_invoice_payments' AND COLUMN_NAME = 'source');
SET @ak_x = IF(LOCATE('''stripe''', @ak_t) = 0, ',''stripe''', '');
SET @ak_sql = IF(@ak_t LIKE 'enum(%' AND @ak_x <> '',
  CONCAT('ALTER TABLE asso_invoice_payments MODIFY source ', LEFT(@ak_t, LENGTH(@ak_t) - 1), @ak_x, ')',
         (SELECT CONCAT(IF(IS_NULLABLE = 'NO', ' NOT NULL', ' NULL'),
                        IF(COLUMN_DEFAULT IS NULL OR COLUMN_DEFAULT = 'NULL', '', CONCAT(' DEFAULT ', COLUMN_DEFAULT)))
          FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asso_invoice_payments' AND COLUMN_NAME = 'source')),
  'DO 0');
PREPARE ak_stmt FROM @ak_sql;
EXECUTE ak_stmt;
DEALLOCATE PREPARE ak_stmt;
