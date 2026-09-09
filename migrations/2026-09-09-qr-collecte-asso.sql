-- ============================================================
-- Migration : QR de collecte de contacts (par association)
-- Cible : MariaDB 10.x. Idempotent (IF NOT EXISTS).
--
--   asso_qr_codes : un QR par usage (accueil d'événement, stand, affiche…).
--   Le QR encode /qr/<token>. Le token est tiré au sort sur 32 caractères
--   hexadécimaux : c'est l'adresse d'une page publique, elle ne doit pas
--   être devinable à partir de l'identifiant de l'organisation.
--
-- Les contacts collectés n'ont PAS de table à eux : ils atterrissent dans
-- asso_prospects, la table de l'onglet Prospection. Une personne rencontrée
-- sur un stand est exactement un prospect à rappeler — lui donner une liste
-- séparée aurait obligé à la recopier avant de pouvoir l'appeler.
-- D'où les trois colonnes ajoutées ci-dessous : d'où vient la fiche, par
-- quel QR, et ce que la personne a écrit.
-- ============================================================

CREATE TABLE IF NOT EXISTS asso_qr_codes (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  org_id       INT NOT NULL,
  token        CHAR(32) NOT NULL,              -- adresse publique, non devinable
  label        VARCHAR(120) NOT NULL DEFAULT '',
  intro        VARCHAR(300) NOT NULL DEFAULT '',
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  show_vcard   TINYINT(1) NOT NULL DEFAULT 1,  -- proposer la fiche contact de l'asso
  ask_phone    TINYINT(1) NOT NULL DEFAULT 1,
  ask_message  TINYINT(1) NOT NULL DEFAULT 0,
  scan_count   INT UNSIGNED NOT NULL DEFAULT 0,
  submit_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_scan_at DATETIME NULL,
  created_by   INT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NULL,
  deleted_at   DATETIME NULL,
  UNIQUE KEY uq_token (token),
  KEY idx_org (org_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provenance des fiches de prospection. `IF NOT EXISTS` sur ADD COLUMN est
-- une extension MariaDB : la migration reste rejouable sans erreur.
ALTER TABLE asso_prospects
  ADD COLUMN IF NOT EXISTS source     VARCHAR(16) NOT NULL DEFAULT 'manuel',
  ADD COLUMN IF NOT EXISTS qr_id      INT NULL,
  ADD COLUMN IF NOT EXISTS consent_at DATETIME NULL;
