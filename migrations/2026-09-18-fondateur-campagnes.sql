-- ------------------------------------------------------------------
-- 2026-09-18-fondateur-campagnes.sql
-- ------------------------------------------------------------------
-- Campagnes e-mail du Fondateur : des listes d'adresses, et des envois
-- en masse vers ces listes.
--
-- Pourquoi on ne repart pas de zéro pour les contacts
-- ---------------------------------------------------
-- asso_prospects existe déjà et porte ce qui compte : une contrainte
-- d'unicité sur l'e-mail, un statut qui sait dire « désinscrit », une
-- base légale de consentement, et le jeton qui alimente la page
-- /desinscription.
--
-- Une seconde table de contacts aurait été plus simple à écrire et
-- fausse à l'usage : quelqu'un qui se désinscrit d'une campagne doit
-- l'être partout, y compris de la séquence de prospection. Deux tables,
-- c'est deux mémoires du refus — et un jour, une relance envoyée à
-- quelqu'un qui avait dit non.
--
-- Les listes sont donc une couche AU-DESSUS des contacts existants.
--
-- Usage :
--   php migrations/run.php 2026-09-18-fondateur-campagnes.sql
-- ------------------------------------------------------------------

-- ---- Les groupes d'adresses ---------------------------------------
CREATE TABLE IF NOT EXISTS fond_listes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom         VARCHAR(160) NOT NULL,
  description VARCHAR(500) DEFAULT NULL,
  cree_par    INT UNSIGNED DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_nom (nom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Qui est dans quelle liste ------------------------------------
-- Un contact peut appartenir à plusieurs listes ; la clé primaire
-- composée empêche de l'y mettre deux fois.
CREATE TABLE IF NOT EXISTS fond_liste_membres (
  liste_id    INT UNSIGNED NOT NULL,
  prospect_id INT UNSIGNED NOT NULL,
  ajoute_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (liste_id, prospect_id),
  -- Pour compter « dans combien de listes est ce contact ? » sans scan.
  KEY idx_contact (prospect_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Les campagnes -------------------------------------------------
CREATE TABLE IF NOT EXISTS fond_campagnes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom           VARCHAR(160) NOT NULL,
  liste_id      INT UNSIGNED NOT NULL,

  sujet         VARCHAR(255) NOT NULL,
  corps_html    MEDIUMTEXT   NOT NULL,

  -- La pièce jointe est rangée hors du dossier web ; on ne garde ici
  -- que de quoi la retrouver et l'annoncer.
  pj_nom        VARCHAR(255) DEFAULT NULL,
  pj_fichier    VARCHAR(255) DEFAULT NULL,
  pj_taille     INT UNSIGNED DEFAULT NULL,
  pj_type       VARCHAR(120) DEFAULT NULL,

  -- brouillon → en_cours → terminee. « pause » arrête l'envoi sans
  -- perdre la place : c'est le bouton qu'on cherche à 2 h du matin
  -- quand on s'aperçoit d'une faute dans l'objet.
  statut        VARCHAR(16)  NOT NULL DEFAULT 'brouillon',

  total         INT UNSIGNED NOT NULL DEFAULT 0,
  envoyes       INT UNSIGNED NOT NULL DEFAULT 0,
  echecs        INT UNSIGNED NOT NULL DEFAULT 0,
  ignores       INT UNSIGNED NOT NULL DEFAULT 0,

  cree_par      INT UNSIGNED DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  demarree_at   DATETIME DEFAULT NULL,
  terminee_at   DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_statut (statut, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Un envoi par destinataire -------------------------------------
-- C'est cette table qui rend la campagne reprenable : le cron demande
-- « les N prochains en attente », envoie, marque. Un passage interrompu
-- ne perd rien et ne renvoie rien.
--
-- La clé unique (campagne, contact) est la garantie centrale : même si
-- deux passages se chevauchaient, personne ne peut recevoir deux fois
-- la même campagne.
CREATE TABLE IF NOT EXISTS fond_campagne_envois (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campagne_id INT UNSIGNED NOT NULL,
  prospect_id INT UNSIGNED NOT NULL,
  email       VARCHAR(255) NOT NULL,

  -- attente → envoye | echec | ignore (désinscrit, adresse invalide)
  statut      VARCHAR(12)  NOT NULL DEFAULT 'attente',
  erreur      VARCHAR(255) DEFAULT NULL,
  sent_at     DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_envoi (campagne_id, prospect_id),
  -- La requête du cron : « les prochains en attente de cette campagne ».
  KEY idx_file (campagne_id, statut, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
