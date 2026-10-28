-- ============================================================
-- Mise en service des paiements Stripe (abonnements des associations)
-- ============================================================
-- Aucune migration ne créait jusqu'ici les tables et colonnes lues et écrites
-- par le code Stripe (stripe-config-helpers.php, stripe-helpers.php,
-- stripe-sync-helpers.php, stripe-webhook.php, stripe-create-payment-intent.php,
-- fondateur-stripe-config.php). Sans elles : réglages jamais enregistrés,
-- webhook en erreur 500 sur chaque paiement, factures en double.
--   1) asso_stripe_config : réglages Stripe (une ligne par clé)
--   2) asso_stripe_events : journal des événements reçus (idempotence,
--      reprise d'un événement resté « processing » après une coupure)
--   3) asso_invoices_stripe : factures Stripe des associations
--      (une ligne par facture : clé UNIQUE sur stripe_invoice_id)
--   4) colonnes Stripe des formules, des abonnements et des add-ons
--   5) organizations : identifiant client Stripe en texte (cus_…) et date
--      limite d'un premier paiement SEPA en cours de vérification
--   6) valeurs d'ENUM manquantes (seulement si la colonne est un ENUM) :
--      ajoutées à la fin de la liste, rien n'est retiré
--
-- Idempotent : sans effet sur ce qui existe déjà, ne supprime aucune
-- donnée. Cible : MariaDB 10.x.
-- Lancement : /usr/local/bin/php migrations/run.php 2026-10-28-stripe-mise-en-service.sql
-- ============================================================

-- ---------- 1) Réglages Stripe ----------
CREATE TABLE IF NOT EXISTS asso_stripe_config (
  config_key VARCHAR(100) NOT NULL PRIMARY KEY,
  config_value TEXT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE asso_stripe_config
  ADD COLUMN IF NOT EXISTS config_value TEXT NULL,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;

-- Une seule ligne par clé (l'enregistrement fait « INSERT … ON DUPLICATE KEY UPDATE ») :
-- index unique ajouté seulement s'il n'y en a pas déjà un sur config_key
SET @ak_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asso_stripe_config'
                    AND COLUMN_NAME = 'config_key' AND NON_UNIQUE = 0 AND SEQ_IN_INDEX = 1) = 0,
  'ALTER TABLE asso_stripe_config ADD UNIQUE KEY uq_stripe_config_key (config_key)', 'DO 0');
PREPARE ak_stmt FROM @ak_sql;
EXECUTE ak_stmt;
DEALLOCATE PREPARE ak_stmt;

-- ---------- 2) Journal des événements Stripe ----------
CREATE TABLE IF NOT EXISTS asso_stripe_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  stripe_event_id VARCHAR(191) NOT NULL,
  event_type VARCHAR(100) NULL,
  org_id INT NULL,
  livemode TINYINT(1) NULL,
  payload_json MEDIUMTEXT NULL,
  processing_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  processed TINYINT(1) NOT NULL DEFAULT 0,
  processed_at DATETIME NULL,
  claimed_at DATETIME NULL,
  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_stripe_events_status (processing_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE asso_stripe_events
  ADD COLUMN IF NOT EXISTS stripe_event_id VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS event_type VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS org_id INT NULL,
  ADD COLUMN IF NOT EXISTS livemode TINYINT(1) NULL,
  ADD COLUMN IF NOT EXISTS payload_json MEDIUMTEXT NULL,
  ADD COLUMN IF NOT EXISTS processing_status VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS error_message TEXT NULL,
  ADD COLUMN IF NOT EXISTS processed TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS processed_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS claimed_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP;

-- Un événement n'est enregistré qu'une fois (INSERT IGNORE du webhook)
SET @ak_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asso_stripe_events'
                    AND COLUMN_NAME = 'stripe_event_id' AND NON_UNIQUE = 0 AND SEQ_IN_INDEX = 1) = 0,
  'ALTER TABLE asso_stripe_events ADD UNIQUE KEY uq_stripe_event (stripe_event_id)', 'DO 0');
PREPARE ak_stmt FROM @ak_sql;
EXECUTE ak_stmt;
DEALLOCATE PREPARE ak_stmt;

-- ---------- 3) Factures Stripe des associations ----------
CREATE TABLE IF NOT EXISTS asso_invoices_stripe (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  org_id INT NULL,
  stripe_invoice_id VARCHAR(191) NOT NULL,
  stripe_customer_id VARCHAR(191) NULL,
  stripe_subscription_id VARCHAR(191) NULL,
  invoice_number VARCHAR(100) NULL,
  billing_reason VARCHAR(40) NULL,
  amount_cents INT NOT NULL DEFAULT 0,
  tax_cents INT NOT NULL DEFAULT 0,
  currency VARCHAR(3) NULL,
  status VARCHAR(20) NULL,
  hosted_invoice_url VARCHAR(500) NULL,
  invoice_pdf_url VARCHAR(500) NULL,
  paid_at DATETIME NULL,
  due_date DATETIME NULL,
  period_start DATETIME NULL,
  period_end DATETIME NULL,
  description VARCHAR(255) NULL,
  stripe_livemode TINYINT(1) NULL,
  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  KEY idx_inv_stripe_org (org_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE asso_invoices_stripe
  ADD COLUMN IF NOT EXISTS org_id INT NULL,
  ADD COLUMN IF NOT EXISTS stripe_invoice_id VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS stripe_customer_id VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS stripe_subscription_id VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS invoice_number VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS billing_reason VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS amount_cents INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS tax_cents INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS currency VARCHAR(3) NULL,
  ADD COLUMN IF NOT EXISTS status VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS hosted_invoice_url VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS invoice_pdf_url VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS due_date DATETIME NULL,
  ADD COLUMN IF NOT EXISTS period_start DATETIME NULL,
  ADD COLUMN IF NOT EXISTS period_end DATETIME NULL,
  ADD COLUMN IF NOT EXISTS description VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS stripe_livemode TINYINT(1) NULL,
  ADD COLUMN IF NOT EXISTS created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;

ALTER TABLE asso_invoices_stripe ADD INDEX IF NOT EXISTS idx_inv_stripe_org (org_id, created_at);

-- Une ligne par facture : l'enregistrement fait « INSERT … ON DUPLICATE KEY UPDATE »
-- (sans cette clé, chaque événement de facture ajoutait une ligne)
SET @ak_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asso_invoices_stripe'
                    AND COLUMN_NAME = 'stripe_invoice_id' AND NON_UNIQUE = 0 AND SEQ_IN_INDEX = 1) = 0,
  'ALTER TABLE asso_invoices_stripe ADD UNIQUE KEY uq_stripe_invoice (stripe_invoice_id)', 'DO 0');
PREPARE ak_stmt FROM @ak_sql;
EXECUTE ak_stmt;
DEALLOCATE PREPARE ak_stmt;

-- ---------- 4) Formules, abonnements, add-ons ----------
-- Prix Stripe mensuel de la formule (renseigné par « Configurer Stripe automatiquement »)
ALTER TABLE asso_plans
  ADD COLUMN IF NOT EXISTS stripe_price_id_monthly VARCHAR(191) NULL;
ALTER TABLE asso_plans ADD INDEX IF NOT EXISTS idx_asso_plans_stripe_price (stripe_price_id_monthly);

ALTER TABLE asso_subscriptions
  ADD COLUMN IF NOT EXISTS payment_mode VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS stripe_subscription_id VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS stripe_price_id VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS stripe_livemode TINYINT(1) NULL,
  ADD COLUMN IF NOT EXISTS current_period_end DATETIME NULL,
  ADD COLUMN IF NOT EXISTS cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS grace_period_end DATETIME NULL,
  ADD COLUMN IF NOT EXISTS previous_plan_id INT NULL,
  ADD COLUMN IF NOT EXISTS downgraded_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS notes TEXT NULL,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;
ALTER TABLE asso_subscriptions ADD INDEX IF NOT EXISTS idx_asso_sub_stripe (stripe_subscription_id);

CREATE TABLE IF NOT EXISTS asso_subscription_addons (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subscription_id INT NOT NULL,
  org_id INT NOT NULL,
  addon_type VARCHAR(50) NOT NULL,
  addon_name VARCHAR(100) NULL,
  price_cents_monthly INT NOT NULL DEFAULT 0,
  stripe_price_id VARCHAR(191) NULL,
  stripe_subscription_item_id VARCHAR(191) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_addons_sub (subscription_id),
  KEY idx_addons_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE asso_subscription_addons
  ADD COLUMN IF NOT EXISTS stripe_price_id VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS stripe_subscription_item_id VARCHAR(191) NULL;

-- ---------- 5) Organisations ----------
ALTER TABLE organizations
  ADD COLUMN IF NOT EXISTS stripe_customer_id VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS billing_email VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS stripe_payment_pending_until DATETIME NULL;

-- Identifiant client Stripe « cus_… » : du texte. Une colonne numérique refusait l'identifiant
-- (mode strict) ou le gardait à 0. Modifiée seulement si elle n'est pas déjà du texte ; les
-- valeurs vides ou 0 sont effacées (aucun client Stripe n'a encore été créé).
SET @ak_sql = IF((SELECT DATA_TYPE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizations'
                    AND COLUMN_NAME = 'stripe_customer_id') NOT IN ('varchar', 'char', 'text', 'tinytext'),
  'ALTER TABLE organizations MODIFY stripe_customer_id VARCHAR(191) NULL DEFAULT NULL', 'DO 0');
PREPARE ak_stmt FROM @ak_sql;
EXECUTE ak_stmt;
DEALLOCATE PREPARE ak_stmt;

UPDATE organizations SET stripe_customer_id = NULL WHERE stripe_customer_id IN ('', '0');

ALTER TABLE organizations ADD INDEX IF NOT EXISTS idx_org_stripe_customer (stripe_customer_id);

-- ---------- 6) Valeurs d'ENUM écrites par le webhook ----------
-- Seulement si la colonne est un ENUM auquel il manque une valeur : la liste existante est
-- gardée telle quelle (même ordre, même valeur par défaut, même NULL / NOT NULL), les valeurs
-- manquantes sont ajoutées à la fin. Une colonne VARCHAR n'est pas touchée.
-- asso_subscriptions.status : active, pending_payment, overdue, cancelled
SET @ak_t = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asso_subscriptions' AND COLUMN_NAME = 'status');
SET @ak_x = CONCAT(IF(LOCATE('''active''', @ak_t) = 0, ',''active''', ''),
                   IF(LOCATE('''pending_payment''', @ak_t) = 0, ',''pending_payment''', ''),
                   IF(LOCATE('''overdue''', @ak_t) = 0, ',''overdue''', ''),
                   IF(LOCATE('''cancelled''', @ak_t) = 0, ',''cancelled''', ''));
SET @ak_sql = IF(@ak_t LIKE 'enum(%' AND @ak_x <> '',
  CONCAT('ALTER TABLE asso_subscriptions MODIFY status ', LEFT(@ak_t, LENGTH(@ak_t) - 1), @ak_x, ')',
         (SELECT CONCAT(IF(IS_NULLABLE = 'NO', ' NOT NULL', ' NULL'),
                        IF(COLUMN_DEFAULT IS NULL OR COLUMN_DEFAULT = 'NULL', '', CONCAT(' DEFAULT ', COLUMN_DEFAULT)))
          FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asso_subscriptions' AND COLUMN_NAME = 'status')),
  'DO 0');
PREPARE ak_stmt FROM @ak_sql;
EXECUTE ak_stmt;
DEALLOCATE PREPARE ak_stmt;

-- asso_subscriptions.payment_mode : stripe, manual
SET @ak_t = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asso_subscriptions' AND COLUMN_NAME = 'payment_mode');
SET @ak_x = CONCAT(IF(LOCATE('''stripe''', @ak_t) = 0, ',''stripe''', ''),
                   IF(LOCATE('''manual''', @ak_t) = 0, ',''manual''', ''));
SET @ak_sql = IF(@ak_t LIKE 'enum(%' AND @ak_x <> '',
  CONCAT('ALTER TABLE asso_subscriptions MODIFY payment_mode ', LEFT(@ak_t, LENGTH(@ak_t) - 1), @ak_x, ')',
         (SELECT CONCAT(IF(IS_NULLABLE = 'NO', ' NOT NULL', ' NULL'),
                        IF(COLUMN_DEFAULT IS NULL OR COLUMN_DEFAULT = 'NULL', '', CONCAT(' DEFAULT ', COLUMN_DEFAULT)))
          FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asso_subscriptions' AND COLUMN_NAME = 'payment_mode')),
  'DO 0');
PREPARE ak_stmt FROM @ak_sql;
EXECUTE ak_stmt;
DEALLOCATE PREPARE ak_stmt;

-- asso_subscription_addons.status : active, cancelled
SET @ak_t = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asso_subscription_addons' AND COLUMN_NAME = 'status');
SET @ak_x = CONCAT(IF(LOCATE('''active''', @ak_t) = 0, ',''active''', ''),
                   IF(LOCATE('''cancelled''', @ak_t) = 0, ',''cancelled''', ''));
SET @ak_sql = IF(@ak_t LIKE 'enum(%' AND @ak_x <> '',
  CONCAT('ALTER TABLE asso_subscription_addons MODIFY status ', LEFT(@ak_t, LENGTH(@ak_t) - 1), @ak_x, ')',
         (SELECT CONCAT(IF(IS_NULLABLE = 'NO', ' NOT NULL', ' NULL'),
                        IF(COLUMN_DEFAULT IS NULL OR COLUMN_DEFAULT = 'NULL', '', CONCAT(' DEFAULT ', COLUMN_DEFAULT)))
          FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asso_subscription_addons' AND COLUMN_NAME = 'status')),
  'DO 0');
PREPARE ak_stmt FROM @ak_sql;
EXECUTE ak_stmt;
DEALLOCATE PREPARE ak_stmt;
