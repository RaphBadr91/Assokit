-- ============================================================
-- Nouvelle grille tarifaire : Essentiel, Pro, Équipe, Sur-mesure
-- ============================================================
-- Pose le socle de la grille de novembre 2026 (chantier « fondations ») :
--   1) asso_plans : colonnes déjà lues par le code, ajoutées si absentes
--      (sans effet en production, où elles existent) ;
--   2) asso_plans : nouvelles colonnes de la grille (limit_managers,
--      limit_mail_ai_batches_per_month, dix fonctions feature_*, grille_version) ;
--   3) asso_subscriptions : garantie de l'offre fondateur et baisse de
--      formule programmée ;
--   4) mail_threads : attribution d'une conversation à un membre ;
--   5) tables : ai_usage_ledger (compteur IA unifié), org_feature_usage,
--      treasurer_digest_optout ;
--   6) les sept lignes de la grille, dans cet ordre (Démarrage : nom et visibilité seulement,
--      ses limites ne changent qu'avec AK_DEMARRAGE_READONLY_LIVE, après le préavis) :
--      a) fondateur (masquée), recopiée de la ligne assokit d'avant ;
--      b) ak-essentiel, ak-equipe, sur-mesure, pro-essai, demarrage créées
--         si elles manquent (ligne vide, remplie à l'étape c) ;
--      c) valeurs de la grille, posées UNE fois (grille_version) ;
--      d) anciennes lignes visibles hors grille masquées, une fois ;
--      e) EN DERNIER, assokit devient « Pro » à 29,99 € HT/mois, mais
--         seulement quand plus aucun client réel n'y est rattaché.
--
-- Clients actuels à 49,99 € (ligne assokit), procédure sûre dans tous les ordres :
--   1. lancer cette migration : fondateur est créée, assokit ne change pas
--      tant qu'un client réel (hors démos « demo… », hors essais) y est
--      rattaché, par sa ligne d'abonnement courante ou par l'ancien libellé
--      organizations.plan (ak_legacy_plan_row, plan-helpers.php) ;
--   2. lancer « php cli-rattacher-fondateur.php » (liste) puis « --apply » :
--      les clients passent sur fondateur (même liste que
--      ak_fondateur_candidates(), plan-helpers.php) ;
--   3. relancer cette migration : assokit devient Pro.
-- Aucun client ne passe donc, même une minute, sous les plafonds de Pro.
--
-- Idempotent : chaque valeur de la grille n'est écrite qu'une fois (marque
-- grille_version = 202611) ; une relance ne réécrase jamais une modification
-- faite ensuite dans Pilotage › Plans tarifaires. Aucun DELETE, aucun
-- changement de slug. Cible : MariaDB 10.x.
-- Lancement : /usr/local/bin/php migrations/run.php 2026-11-09-grille-tarifaire.sql
-- ============================================================

-- ---------- 1) asso_plans : colonnes déjà lues par le code ----------
ALTER TABLE asso_plans
  ADD COLUMN IF NOT EXISTS tagline VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS price_label VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS is_custom_quote TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS is_featured TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS is_visible TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS is_trial TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS display_order INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS limit_adherents INT NULL,
  ADD COLUMN IF NOT EXISTS limit_users INT NULL,
  ADD COLUMN IF NOT EXISTS limit_invoices_total INT NULL,
  ADD COLUMN IF NOT EXISTS limit_quotes_total INT NULL,
  ADD COLUMN IF NOT EXISTS limit_contacts INT NULL,
  ADD COLUMN IF NOT EXISTS limit_ai_text_per_month INT NULL,
  ADD COLUMN IF NOT EXISTS limit_ai_image_per_month INT NULL,
  ADD COLUMN IF NOT EXISTS limit_emails_per_month INT NULL,
  ADD COLUMN IF NOT EXISTS bonus_ai_first_week INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS feature_recurring_invoices TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS feature_signature_quotes TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS feature_email_diffusion TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS feature_advanced_stats TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS feature_priority_support TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS feature_custom_domain TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS feature_dedicated_support TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS stripe_price_id_monthly VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;

-- ---------- 2) asso_plans : nouvelles colonnes de la grille ----------
-- limit_managers : comptes actifs de rôle admin, coordinator ou referent (vide = illimité).
-- limit_mail_ai_batches_per_month : lots de tri IA de la boîte mail (15 conversations par lot).
-- Les dix fonctions valent 1 PAR DÉFAUT : une ligne existante hors grille (ancienne ligne,
-- ligne créée à la main) garde tout ce qu'elle a aujourd'hui. Les lignes de la grille
-- reçoivent leurs valeurs à l'étape 6.
-- grille_version : 202611 = valeurs de cette grille déjà posées (jamais réécrites ensuite).
ALTER TABLE asso_plans
  ADD COLUMN IF NOT EXISTS limit_managers INT NULL,
  ADD COLUMN IF NOT EXISTS limit_mail_ai_batches_per_month INT NULL,
  ADD COLUMN IF NOT EXISTS feature_ai_assistant TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS feature_mail_inbox TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS feature_mail_ai_sort TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS feature_ai_daily_all TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS feature_anomalies TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS feature_team_planning TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS feature_prospection TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS feature_activity_log TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS feature_shared_inbox TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS feature_treasurer_digest TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS grille_version INT NULL;

-- ---------- 3) asso_subscriptions ----------
-- price_locked_until : fin de la garantie de prix et de fonctions (offre fondateur).
-- pending_plan_id / pending_plan_at : baisse de formule programmée pour la fin de la
-- période payée (appliquée par cron-job-renouvellements.php ou le webhook Stripe).
ALTER TABLE asso_subscriptions
  ADD COLUMN IF NOT EXISTS current_period_end DATETIME NULL,
  ADD COLUMN IF NOT EXISTS price_locked_until DATETIME NULL,
  ADD COLUMN IF NOT EXISTS pending_plan_id INT NULL,
  ADD COLUMN IF NOT EXISTS pending_plan_at DATETIME NULL;

-- ---------- 4) mail_threads : attribution d'une conversation ----------
-- Table créée par 2026-10-06-boite-mail.sql : modifiée seulement si elle existe.
SET @ak_sql = IF((SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_threads') > 0,
  'ALTER TABLE mail_threads ADD COLUMN IF NOT EXISTS assigned_user_id INT NULL, ADD COLUMN IF NOT EXISTS assigned_at DATETIME NULL, ADD COLUMN IF NOT EXISTS assigned_by INT NULL, ADD INDEX IF NOT EXISTS idx_mail_threads_assigned (org_id, assigned_user_id)',
  'DO 0');
PREPARE ak_stmt FROM @ak_sql;
EXECUTE ak_stmt;
DEALLOCATE PREPARE ak_stmt;

-- ---------- 5) Nouvelles tables ----------
-- Compteur IA unifié : une ligne par appel réussi (ai-models.php, ak_ai_ledger_record).
-- credits : crédits décomptés du quota mensuel (0 pour les tâches de fond).
CREATE TABLE IF NOT EXISTS ai_usage_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  org_id INT NULL,
  user_id INT NULL,
  path VARCHAR(40) NOT NULL,
  purpose VARCHAR(10) NOT NULL DEFAULT 'write',
  model VARCHAR(64) NULL,
  tokens_in INT UNSIGNED NOT NULL DEFAULT 0,
  tokens_out INT UNSIGNED NOT NULL DEFAULT 0,
  credits TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ledger_org_date (org_id, created_at),
  KEY idx_ledger_path_date (path, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compteur des fonctions plafonnées (« 1 bilan analytique » hors Pro) : lu et écrit par
-- plan-helpers.php (ak_feature_usage_count / ak_feature_usage_record). Aucune migration ne la créait.
CREATE TABLE IF NOT EXISTS org_feature_usage (
  org_id INT NOT NULL,
  feature_key VARCHAR(64) NOT NULL,
  used_count INT NOT NULL DEFAULT 0,
  first_used_at DATETIME NULL,
  last_used_at DATETIME NULL,
  PRIMARY KEY (org_id, feature_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE org_feature_usage
  ADD COLUMN IF NOT EXISTS used_count INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS first_used_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS last_used_at DATETIME NULL;

-- Table créée à la main sans clé sur (org_id, feature_key) : l'enregistrement fait
-- « INSERT … ON DUPLICATE KEY UPDATE », qui ajoutait alors une ligne à chaque usage.
-- Clé unique ajoutée seulement s'il n'y en a pas déjà une et s'il n'y a aucun doublon
-- (sinon rien n'est touché : le compteur additionne les lignes, plan-helpers.php).
SET @ak_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'org_feature_usage'
                    AND COLUMN_NAME = 'feature_key' AND NON_UNIQUE = 0) = 0
             AND (SELECT COUNT(*) FROM (SELECT org_id, feature_key FROM org_feature_usage
                                        GROUP BY org_id, feature_key HAVING COUNT(*) > 1) d) = 0,
  'ALTER TABLE org_feature_usage ADD UNIQUE KEY uq_org_feature_usage (org_id, feature_key)', 'DO 0');
PREPARE ak_stmt FROM @ak_sql;
EXECUTE ak_stmt;
DEALLOCATE PREPARE ak_stmt;

-- Point du lundi du trésorier (formule Équipe) : personnes qui ne veulent plus le recevoir.
CREATE TABLE IF NOT EXISTS treasurer_digest_optout (
  user_id INT NOT NULL PRIMARY KEY,
  org_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_digest_optout_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 6a) Offre fondateur (ligne masquée) ----------
-- Recopiée de la ligne assokit AVANT sa transformation en Pro : les clients actuels gardent
-- exactement leurs limites. Jamais le prix Stripe d'assokit (la synchronisation Stripe
-- rattacherait alors ses abonnements à fondateur).
INSERT INTO asso_plans (slug, name, price_cents, created_at,
  limit_adherents, limit_users, limit_invoices_total, limit_quotes_total, limit_contacts,
  limit_ai_text_per_month, limit_ai_image_per_month, limit_emails_per_month, limit_managers,
  limit_mail_ai_batches_per_month, bonus_ai_first_week)
SELECT 'fondateur', 'Offre fondateur', 2999, NOW(),
  a.limit_adherents, a.limit_users, a.limit_invoices_total, a.limit_quotes_total, a.limit_contacts,
  a.limit_ai_text_per_month, 0, a.limit_emails_per_month, NULL,
  500, 0
FROM asso_plans a
WHERE a.slug = 'assokit' AND COALESCE(a.grille_version, 0) < 202611
  AND NOT EXISTS (SELECT 1 FROM (SELECT slug FROM asso_plans) x WHERE x.slug = 'fondateur')
ORDER BY a.id
LIMIT 1;

-- Repli (assokit absente ou déjà transformée) : volumes d'Équipe, sans plafond de membres.
INSERT INTO asso_plans (slug, name, price_cents, created_at,
  limit_adherents, limit_users, limit_invoices_total, limit_quotes_total, limit_contacts,
  limit_ai_text_per_month, limit_ai_image_per_month, limit_emails_per_month, limit_managers,
  limit_mail_ai_batches_per_month, bonus_ai_first_week)
SELECT 'fondateur', 'Offre fondateur', 2999, NOW(),
  NULL, NULL, NULL, NULL, NULL,
  500, 0, 8000, NULL,
  500, 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT slug FROM asso_plans) x WHERE x.slug = 'fondateur');

-- ---------- 6b) Lignes de la grille absentes : créées vides, remplies en 6c ----------
INSERT INTO asso_plans (slug, name, price_cents, created_at)
SELECT 'ak-essentiel', 'Essentiel', 1999, NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT slug FROM asso_plans) x WHERE x.slug = 'ak-essentiel');

INSERT INTO asso_plans (slug, name, price_cents, created_at)
SELECT 'ak-equipe', 'Équipe', 5999, NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT slug FROM asso_plans) x WHERE x.slug = 'ak-equipe');

INSERT INTO asso_plans (slug, name, price_cents, created_at)
SELECT 'sur-mesure', 'Sur-mesure', 0, NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT slug FROM asso_plans) x WHERE x.slug = 'sur-mesure');

INSERT INTO asso_plans (slug, name, price_cents, created_at)
SELECT 'pro-essai', 'Essai Pro', 0, NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT slug FROM asso_plans) x WHERE x.slug = 'pro-essai');

INSERT INTO asso_plans (slug, name, price_cents, created_at)
SELECT 'demarrage', 'Démarrage', 0, NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT slug FROM asso_plans) x WHERE x.slug = 'demarrage');

-- ---------- 6c) Valeurs de la grille (une seule fois par ligne) ----------
-- Offre fondateur : 29,99 € HT/mois, masquée (jamais vendue par carte), limites recopiées
-- en 6a ; toutes les fonctions actuelles plus celles d'Équipe, sauf le support prioritaire,
-- le domaine personnel et le support dédié (ni aujourd'hui, ni promis).
UPDATE asso_plans SET
  name = 'Offre fondateur', tagline = 'Clients de la première heure', price_cents = 2999, price_label = NULL,
  display_order = 90, is_visible = 0, is_trial = 0, is_custom_quote = 0, is_featured = 0,
  limit_ai_image_per_month = 0, bonus_ai_first_week = 0, limit_mail_ai_batches_per_month = 500,
  feature_email_diffusion = 1, feature_advanced_stats = 1, feature_recurring_invoices = 1,
  feature_signature_quotes = 1, feature_ai_assistant = 1, feature_mail_inbox = 1,
  feature_mail_ai_sort = 1, feature_ai_daily_all = 1, feature_anomalies = 1,
  feature_team_planning = 1, feature_prospection = 1, feature_activity_log = 1,
  feature_shared_inbox = 1, feature_treasurer_digest = 1,
  feature_priority_support = 0, feature_custom_domain = 0, feature_dedicated_support = 0,
  grille_version = 202611, updated_at = NOW()
WHERE slug = 'fondateur' AND COALESCE(grille_version, 0) < 202611;

-- Essentiel : 19,99 € HT/mois, 50 membres (bureau compris), 3 gestionnaires,
-- 30 crédits IA, 500 e-mails par mois.
UPDATE asso_plans SET
  name = 'Essentiel', tagline = 'Petites associations et indépendants', price_cents = 1999, price_label = NULL,
  display_order = 1, is_visible = 1, is_trial = 0, is_custom_quote = 0, is_featured = 0,
  limit_adherents = 50, limit_managers = 3, limit_users = NULL, limit_invoices_total = NULL,
  limit_quotes_total = NULL, limit_contacts = NULL, limit_ai_text_per_month = 30,
  limit_ai_image_per_month = 0, limit_emails_per_month = 500, limit_mail_ai_batches_per_month = 0,
  bonus_ai_first_week = 0,
  feature_email_diffusion = 1, feature_advanced_stats = 0, feature_recurring_invoices = 0,
  feature_signature_quotes = 0, feature_ai_assistant = 0, feature_mail_inbox = 0,
  feature_mail_ai_sort = 0, feature_ai_daily_all = 0, feature_anomalies = 0,
  feature_team_planning = 0, feature_prospection = 0, feature_activity_log = 0,
  feature_shared_inbox = 0, feature_treasurer_digest = 0,
  feature_priority_support = 0, feature_custom_domain = 0, feature_dedicated_support = 0,
  grille_version = 202611, updated_at = NOW()
WHERE slug = 'ak-essentiel' AND COALESCE(grille_version, 0) < 202611;

-- Équipe : 59,99 € HT/mois, 3 000 membres (bureau compris), 25 gestionnaires,
-- 500 crédits IA, 8 000 e-mails, 500 lots de tri IA de la boîte mail par mois.
UPDATE asso_plans SET
  name = 'Équipe', tagline = 'Structures avec une équipe et petites fédérations', price_cents = 5999, price_label = NULL,
  display_order = 3, is_visible = 1, is_trial = 0, is_custom_quote = 0, is_featured = 0,
  limit_adherents = 3000, limit_managers = 25, limit_users = NULL, limit_invoices_total = NULL,
  limit_quotes_total = NULL, limit_contacts = NULL, limit_ai_text_per_month = 500,
  limit_ai_image_per_month = 0, limit_emails_per_month = 8000, limit_mail_ai_batches_per_month = 500,
  bonus_ai_first_week = 0,
  feature_email_diffusion = 1, feature_advanced_stats = 1, feature_recurring_invoices = 1,
  feature_signature_quotes = 1, feature_ai_assistant = 1, feature_mail_inbox = 1,
  feature_mail_ai_sort = 1, feature_ai_daily_all = 1, feature_anomalies = 1,
  feature_team_planning = 1, feature_prospection = 1, feature_activity_log = 1,
  feature_shared_inbox = 1, feature_treasurer_digest = 1,
  feature_priority_support = 1, feature_custom_domain = 0, feature_dedicated_support = 0,
  grille_version = 202611, updated_at = NOW()
WHERE slug = 'ak-equipe' AND COALESCE(grille_version, 0) < 202611;

-- Sur-mesure : sur devis (prix masqué), sans prix Stripe (jamais vendue par carte),
-- sans plafond de comptes ; volumes IA et e-mails par défaut, ajustés au devis.
UPDATE asso_plans SET
  name = 'Sur-mesure', tagline = 'Fédérations, ligues, collectivités territoriales', price_cents = 0, price_label = 'Sur devis',
  display_order = 4, is_visible = 1, is_trial = 0, is_custom_quote = 1, is_featured = 0,
  stripe_price_id_monthly = NULL,
  limit_adherents = NULL, limit_managers = NULL, limit_users = NULL, limit_invoices_total = NULL,
  limit_quotes_total = NULL, limit_contacts = NULL, limit_ai_text_per_month = 1000,
  limit_ai_image_per_month = 0, limit_emails_per_month = 20000, limit_mail_ai_batches_per_month = 1000,
  bonus_ai_first_week = 0,
  feature_email_diffusion = 1, feature_advanced_stats = 1, feature_recurring_invoices = 1,
  feature_signature_quotes = 1, feature_ai_assistant = 1, feature_mail_inbox = 1,
  feature_mail_ai_sort = 1, feature_ai_daily_all = 1, feature_anomalies = 1,
  feature_team_planning = 1, feature_prospection = 1, feature_activity_log = 1,
  feature_shared_inbox = 1, feature_treasurer_digest = 1,
  feature_priority_support = 1, feature_custom_domain = 0, feature_dedicated_support = 1,
  grille_version = 202611, updated_at = NOW()
WHERE slug = 'sur-mesure' AND COALESCE(grille_version, 0) < 202611;

-- Essai Pro : 14 jours, valeurs exactes de Pro sauf 100 crédits IA ; pas de fonctions d'Équipe.
UPDATE asso_plans SET
  name = 'Essai Pro', tagline = '14 jours gratuits, sans carte', price_cents = 0, price_label = NULL,
  display_order = 0, is_visible = 0, is_trial = 1, is_custom_quote = 0, is_featured = 0,
  limit_adherents = 500, limit_managers = 10, limit_users = NULL, limit_invoices_total = NULL,
  limit_quotes_total = NULL, limit_contacts = NULL, limit_ai_text_per_month = 100,
  limit_ai_image_per_month = 0, limit_emails_per_month = 3000, limit_mail_ai_batches_per_month = 0,
  bonus_ai_first_week = 0,
  feature_email_diffusion = 1, feature_advanced_stats = 1, feature_recurring_invoices = 1,
  feature_signature_quotes = 1, feature_ai_assistant = 1, feature_mail_inbox = 1,
  feature_mail_ai_sort = 0, feature_ai_daily_all = 0, feature_anomalies = 0,
  feature_team_planning = 0, feature_prospection = 0, feature_activity_log = 0,
  feature_shared_inbox = 0, feature_treasurer_digest = 0,
  feature_priority_support = 0, feature_custom_domain = 0, feature_dedicated_support = 0,
  grille_version = 202611, updated_at = NOW()
WHERE slug = 'pro-essai' AND COALESCE(grille_version, 0) < 202611;

-- Démarrage : filet technique (fin d'essai non convertie, résiliation, impayé), jamais
-- proposé. Seuls le nom, l'ordre et la visibilité sont posés ici : les LIMITES et les FONCTIONS
-- de la ligne ne sont pas touchées, car les comptes Démarrage existants (anciens comptes gratuits,
-- essais expirés) doivent recevoir un préavis de 30 jours avant tout durcissement (cgu.php § 12).
-- L'accès limité (lecture seule, fonctions et plafonds à 0) n'entre en service que lorsque le
-- Fondateur ajoute define('AK_DEMARRAGE_READONLY_LIVE', true); dans config.php, 30 jours après
-- l'e-mail de préavis (ak_plan_demarrage_overlay, plan-helpers.php ; akc_readonly_live,
-- plan-controls.php ; app_plan_readonly_live, api/_app-plan.php).
UPDATE asso_plans SET
  name = 'Démarrage', tagline = 'Accès limité (consultation et export)', price_cents = 0, price_label = NULL,
  display_order = 99, is_visible = 0, is_trial = 0, is_custom_quote = 0, is_featured = 0,
  grille_version = 202611, updated_at = NOW()
WHERE slug = 'demarrage' AND COALESCE(grille_version, 0) < 202611;

-- ---------- 6d) Anciennes lignes visibles hors grille : masquées, une seule fois ----------
-- (rien n'est supprimé : les associations qui y sont rattachées gardent leur formule)
UPDATE asso_plans SET is_visible = 0, grille_version = 202611, updated_at = NOW()
WHERE is_visible = 1
  AND COALESCE(slug, '') NOT IN ('ak-essentiel', 'assokit', 'ak-equipe', 'sur-mesure')
  AND COALESCE(grille_version, 0) < 202611;

-- ---------- 6d bis) Associations de démonstration : formule la plus complète ----------
-- Les démos (slug « demo… » : compte démo, compte d'examen Apple / Google) doivent tout
-- montrer (demo-formation/01-abonnement.php). Rattachées à assokit, elles passeraient en Pro
-- à l'étape 6e et perdraient les fonctions d'Équipe : elles sont rattachées à Sur-mesure
-- (toutes les fonctions, sans plafond de comptes). Idempotent : seules les lignes encore
-- sur assokit sont modifiées, et une ligne n'est ajoutée qu'à une démo qui n'en a aucune.
UPDATE asso_subscriptions s
  JOIN organizations o ON o.id = s.org_id
  JOIN asso_plans pa ON pa.id = s.plan_id AND pa.slug = 'assokit'
  JOIN asso_plans ps ON ps.slug = 'sur-mesure'
SET s.plan_id = ps.id
WHERE COALESCE(o.slug, '') LIKE 'demo%';

INSERT INTO asso_subscriptions (org_id, plan_id, status, started_at, created_at)
SELECT o.id, ps.id, 'active', NOW(), NOW()
FROM organizations o
JOIN asso_plans ps ON ps.slug = 'sur-mesure'
WHERE COALESCE(o.slug, '') LIKE 'demo%'
  AND o.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM asso_subscriptions s WHERE s.org_id = o.id);

-- ---------- 6e) EN DERNIER : assokit devient « Pro », 29,99 € HT/mois ----------
-- Seulement quand plus aucun client réel n'y est rattaché (sinon rien ne change, et la
-- relance après cli-rattacher-fondateur.php le fera) :
--   - aucune association (hors démos « demo… », hors supprimées, hors essais, hors
--     associations résiliées ou fermées) dont la ligne d'abonnement COURANTE (ordre
--     d'ak_sub_order : active, puis essai, puis la plus récente) est assokit ET en cours
--     (active, overdue, past_due, pending_payment, ou résiliée avec une période payée qui
--     court encore). Un ancien client résilié ne bloque donc pas la transformation ;
--   - aucune association active sans ligne d'abonnement que l'ancien libellé
--     organizations.plan rattache à assokit (ak_legacy_plan_row, plan-helpers.php) :
--     « assokit », « association », « pro », ou un ancien montant mensuel payant avec un
--     libellé qui n'est ni gratuit, ni d'essai, ni une autre formule existante.
-- Le slug est gardé : ak_offer_price (includes-pricing.php), le repli d'essai
-- (trial-helpers.php) et les liens ?plan=assokit suivent sans changement.
UPDATE asso_plans p SET
  p.name = 'Pro', p.tagline = 'Associations actives et TPE', p.price_cents = 2999, p.price_label = NULL,
  p.display_order = 2, p.is_visible = 1, p.is_trial = 0, p.is_custom_quote = 0, p.is_featured = 1,
  p.limit_adherents = 500, p.limit_managers = 10, p.limit_users = NULL, p.limit_invoices_total = NULL,
  p.limit_quotes_total = NULL, p.limit_contacts = NULL, p.limit_ai_text_per_month = 300,
  p.limit_ai_image_per_month = 0, p.limit_emails_per_month = 3000, p.limit_mail_ai_batches_per_month = 0,
  p.bonus_ai_first_week = 0,
  p.feature_email_diffusion = 1, p.feature_advanced_stats = 1, p.feature_recurring_invoices = 1,
  p.feature_signature_quotes = 1, p.feature_ai_assistant = 1, p.feature_mail_inbox = 1,
  p.feature_mail_ai_sort = 0, p.feature_ai_daily_all = 0, p.feature_anomalies = 0,
  p.feature_team_planning = 0, p.feature_prospection = 0, p.feature_activity_log = 0,
  p.feature_shared_inbox = 0, p.feature_treasurer_digest = 0,
  p.feature_priority_support = 0, p.feature_custom_domain = 0, p.feature_dedicated_support = 0,
  p.grille_version = 202611, p.updated_at = NOW()
WHERE p.slug = 'assokit' AND COALESCE(p.grille_version, 0) < 202611
  AND NOT EXISTS (
    SELECT 1 FROM organizations o
    JOIN asso_subscriptions s ON s.id = (
        SELECT s2.id FROM asso_subscriptions s2 JOIN (SELECT id FROM asso_plans) p2 ON p2.id = s2.plan_id
        WHERE s2.org_id = o.id
        ORDER BY (s2.status = 'active') DESC, (s2.status = 'trial') DESC, s2.id DESC LIMIT 1)
    WHERE s.plan_id = p.id
      AND (COALESCE(s.status, '') IN ('active', 'overdue', 'past_due', 'pending_payment')
           OR (s.status = 'cancelled' AND s.current_period_end IS NOT NULL AND s.current_period_end > NOW()))
      AND COALESCE(o.status, '') NOT IN ('trial', 'cancelled', 'closed')
      AND o.deleted_at IS NULL
      AND COALESCE(o.slug, '') NOT LIKE 'demo%')
  AND NOT EXISTS (
    SELECT 1 FROM organizations o
    WHERE o.deleted_at IS NULL
      AND COALESCE(o.slug, '') NOT LIKE 'demo%'
      AND COALESCE(o.status, '') IN ('active', '')
      AND NOT EXISTS (SELECT 1 FROM asso_subscriptions s3 JOIN (SELECT id FROM asso_plans) p3 ON p3.id = s3.plan_id
                      WHERE s3.org_id = o.id)
      AND (LOWER(TRIM(COALESCE(o.plan, ''))) IN ('assokit', 'association', 'pro')
           OR (COALESCE(o.monthly_price_cents, 0) > 0
               AND LOWER(TRIM(COALESCE(o.plan, ''))) NOT IN ('organisation', 'sur-mesure', 'demarrage', 'gratuit', 'free', 'trial', 'essai', 'pro-essai')
               AND LOWER(TRIM(COALESCE(o.plan, ''))) NOT IN (SELECT x.slug FROM (SELECT slug FROM asso_plans WHERE slug IS NOT NULL AND slug <> 'assokit') x))));
