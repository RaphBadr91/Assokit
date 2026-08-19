-- ============================================================
-- Migration : base de subventions intelligente (catalogue + matching)
-- Cible : MariaDB 10.x. Idempotent (IF NOT EXISTS).
-- S'ajoute AU-DESSUS du socle existant (table `grants` = candidatures) sans le modifier.
--   - grant_catalog      : dispositifs de financement (partagés, org_id NULL = global)
--   - org_grant_profile  : profil d'éligibilité de l'asso (1 ligne / org)
--   - grant_matches      : correspondances profil <-> dispositif (score)
--   - grant_alert_prefs  : préférences d'alerte par org
-- ============================================================

CREATE TABLE IF NOT EXISTS grant_catalog (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  title          VARCHAR(255) NOT NULL,
  funder_name    VARCHAR(255) NOT NULL,
  funder_type    ENUM('etat','region','departement','commune','epci','caf','fondation','entreprise','europe','autre') NOT NULL DEFAULT 'autre',
  program_code   VARCHAR(64) NULL,
  summary        TEXT NULL,
  geo_scope      ENUM('national','region','departement','commune','europe') NOT NULL DEFAULT 'national',
  region_code    VARCHAR(10) NULL,
  dept_code      VARCHAR(3) NULL,
  sectors        VARCHAR(255) NULL,          -- CSV : sport,culture,social,environnement,sante,education,jeunesse,numerique,patrimoine
  beneficiary    VARCHAR(120) NOT NULL DEFAULT 'association',  -- CSV : association,tpe,collectivite
  amount_min     DECIMAL(12,2) NULL,
  amount_max     DECIMAL(12,2) NULL,
  recurrence     ENUM('ponctuel','annuel','permanent','pluriannuel') NOT NULL DEFAULT 'annuel',
  opens_at       DATE NULL,
  deadline_apply DATE NULL,
  next_expected  DATE NULL,
  apply_url      VARCHAR(512) NULL,
  source         VARCHAR(64) NOT NULL DEFAULT 'curation_assokit',
  source_ref     VARCHAR(160) NULL,
  source_url     VARCHAR(512) NULL,          -- URL officielle (toujours renvoyer ici)
  verified_at    DATETIME NULL,
  is_verified    TINYINT(1) NOT NULL DEFAULT 0,
  status         ENUM('active','closed','draft','archived') NOT NULL DEFAULT 'active',
  org_id         INT NULL,                   -- NULL = dispositif global
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_source (source, source_ref),
  KEY idx_deadline (deadline_apply),
  KEY idx_geo (geo_scope, region_code, dept_code),
  KEY idx_status (status),
  KEY idx_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS org_grant_profile (
  org_id         INT PRIMARY KEY,
  region_code    VARCHAR(10) NULL,
  dept_code      VARCHAR(3) NULL,
  sectors        VARCHAR(255) NULL,          -- CSV
  is_qpv         TINYINT(1) NULL,
  is_zrr         TINYINT(1) NULL,
  members_count  INT NULL,
  annual_budget  DECIMAL(12,2) NULL,
  is_interet_general TINYINT(1) NULL,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grant_matches (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  org_id       INT NOT NULL,
  catalog_id   INT NOT NULL,
  score        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  eligibility  ENUM('eligible','probable','a_verifier','ineligible') NOT NULL DEFAULT 'a_verifier',
  reasons      TEXT NULL,
  dismissed    TINYINT(1) NOT NULL DEFAULT 0,
  saved        TINYINT(1) NOT NULL DEFAULT 0,
  computed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_org_catalog (org_id, catalog_id),
  KEY idx_org_score (org_id, score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grant_alert_prefs (
  org_id           INT PRIMARY KEY,
  notify_new_match TINYINT(1) NOT NULL DEFAULT 1,
  min_match_score  TINYINT UNSIGNED NOT NULL DEFAULT 60,
  notify_deadlines TINYINT(1) NOT NULL DEFAULT 1,
  channel_email    TINYINT(1) NOT NULL DEFAULT 1,
  channel_app      TINYINT(1) NOT NULL DEFAULT 1,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
