-- ============================================================
-- Migration : Prospection téléphonique (par association)
-- Cible : MariaDB 10.x. Idempotent (IF NOT EXISTS).
--
--   asso_prospects        : la fiche, état courant
--   asso_prospect_events  : ce qui lui est arrivé, et quand
--
-- Deux tables plutôt qu'une : `updated_at` répond à « quand », jamais à
-- « quoi » ni « qui ». En prospection téléphonique c'est l'historique qui
-- a de la valeur — savoir qu'on a appelé le 3, laissé un message le 5 et
-- reprogrammé au 12 vaut plus que la seule date du dernier changement.
--
-- Suppression douce (deleted_at) : un prospect effacé par erreur en pleine
-- session d'appels doit pouvoir revenir, et son historique avec lui.
-- ============================================================

CREATE TABLE IF NOT EXISTS asso_prospects (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  org_id       INT NOT NULL,
  prenom       VARCHAR(120) NOT NULL DEFAULT '',
  nom          VARCHAR(120) NOT NULL DEFAULT '',
  telephone    VARCHAR(40)  NOT NULL DEFAULT '',
  email        VARCHAR(190) NOT NULL DEFAULT '',
  called       TINYINT(1)   NOT NULL DEFAULT 0,   -- Appelé : oui / non
  called_at    DATETIME NULL,                     -- horodaté automatiquement
  callback_at  DATETIME NULL,                     -- à rappeler le…
  notes        TEXT NULL,
  created_by   INT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by   INT NULL,
  updated_at   DATETIME NULL,
  deleted_at   DATETIME NULL,
  KEY idx_org_rappel   (org_id, callback_at),
  KEY idx_org_appele   (org_id, called),
  KEY idx_org_nom      (org_id, nom, prenom),
  KEY idx_org_supprime (org_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asso_prospect_events (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  org_id      INT NOT NULL,
  prospect_id INT NOT NULL,
  user_id     INT NULL,
  -- create · edit · call_yes · call_no · callback_set · callback_clear
  -- · note · delete · restore
  type        VARCHAR(24) NOT NULL,
  detail      VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_prospect (prospect_id, id),
  KEY idx_org_date (org_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
