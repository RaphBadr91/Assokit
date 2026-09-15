-- ============================================================
-- Migration : garder trace de chaque fichier importé
-- Cible : MariaDB 10.x. Idempotent.
--
-- Jusqu'ici une fiche importée portait `source = 'import'` et rien de
-- plus : impossible de dire de quel fichier elle venait, ni de revenir
-- sur un import qui s'avère hors sujet. Un « Annuaire des services
-- municipaux » versé par erreur dans la liste d'appel ne pouvait être
-- retiré qu'en cochant les fiches une par une.
--
-- Chaque import devient donc un lot : son fichier, son auteur, son
-- heure, et ce qu'il a produit. Les fiches y sont rattachées, ce qui
-- permet aussi bien de filtrer la liste sur un import que de le
-- supprimer en bloc.
--
-- `deleted_at` sur le lot, comme sur les fiches : un import supprimé
-- par erreur au milieu d'une session d'appels doit pouvoir revenir.
-- ============================================================

CREATE TABLE IF NOT EXISTS asso_prospection_imports (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  org_id      INT NOT NULL,
  fichier     VARCHAR(255) NOT NULL DEFAULT '',
  lignes      INT NOT NULL DEFAULT 0,   -- lignes lues dans le fichier
  ajoutes     INT NOT NULL DEFAULT 0,
  doublons    INT NOT NULL DEFAULT 0,
  ignorees    INT NOT NULL DEFAULT 0,
  created_by  INT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at  DATETIME NULL,
  KEY idx_org_date     (org_id, created_at),
  KEY idx_org_supprime (org_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- `IF NOT EXISTS` sur ADD COLUMN et ADD KEY est une extension MariaDB :
-- la migration reste rejouable sans erreur.
ALTER TABLE asso_prospection
  ADD COLUMN IF NOT EXISTS import_id INT NULL;

ALTER TABLE asso_prospection
  ADD KEY IF NOT EXISTS idx_org_import (org_id, import_id);

-- ------------------------------------------------------------
-- Qualifier le prospect : nature et territoire
-- ------------------------------------------------------------
-- On n'appelle pas une association comme une entreprise, et une tournée
-- d'appels se prépare département par département. Deux colonnes, donc,
-- pour pouvoir trier la liste sur ces deux axes.
--
-- `departement` est dérivé du code postal à l'écriture plutôt que calculé
-- à la lecture : une requête qui filtrerait sur LEFT(code_postal, 2)
-- n'utiliserait aucun index, et la Corse comme l'outre-mer ne se
-- découpent pas sur deux caractères.

ALTER TABLE asso_prospection
  ADD COLUMN IF NOT EXISTS type        VARCHAR(16) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS code_postal VARCHAR(10) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS ville       VARCHAR(120) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS departement VARCHAR(3) NOT NULL DEFAULT '';

ALTER TABLE asso_prospection
  ADD KEY IF NOT EXISTS idx_org_type (org_id, type),
  ADD KEY IF NOT EXISTS idx_org_dept (org_id, departement);

-- Le type appliqué par défaut aux fiches d'un import, retenu sur le lot :
-- « ce fichier, ce sont des entreprises ».
ALTER TABLE asso_prospection_imports
  ADD COLUMN IF NOT EXISTS type_defaut VARCHAR(16) NOT NULL DEFAULT '';
