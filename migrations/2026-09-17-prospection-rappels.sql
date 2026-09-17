-- ------------------------------------------------------------------
-- 2026-09-17-prospection-rappels.sql
-- ------------------------------------------------------------------
-- Plusieurs rappels par fiche, au lieu d'un seul.
--
-- Jusqu'ici une fiche portait une date de rappel, et une seule :
-- asso_prospection.callback_at. Programmer le rappel suivant effaçait
-- le précédent. On perdait donc ce qui compte le plus en prospection —
-- combien de fois on a appelé, quand, et ce qu'on a obtenu à chaque
-- fois. « Je l'ai déjà relancé trois fois, il ne répond jamais le
-- matin » ne se lisait nulle part.
--
-- Chaque rappel devient une ligne : celui qui est prévu, et tous ceux
-- qui ont déjà eu lieu, avec leur issue et leur note.
--
-- callback_at NE DISPARAÎT PAS. Elle reste sur la fiche et porte
-- désormais la date du PROCHAIN rappel en attente. Le bandeau des
-- rappels du jour, l'onglet « À rappeler », la notification du tableau
-- de bord et l'export la lisent tous : la garder à jour évite de
-- réécrire cinq endroits qui fonctionnent, et garde la liste triable
-- sans jointure.
--
-- Usage :
--   php migrations/run.php 2026-09-17-prospection-rappels.sql
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS asso_prospection_rappels (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id      INT UNSIGNED NOT NULL,
  prospect_id INT UNSIGNED NOT NULL,

  -- Le quantième : 1er rappel, 2e, 3e… Calculé à la création, pour
  -- pouvoir l'afficher sans recompter la liste à chaque ligne.
  rang        SMALLINT UNSIGNED NOT NULL DEFAULT 1,

  du_at       DATETIME     NOT NULL,   -- quand il est prévu
  fait_at     DATETIME     NULL,       -- quand il a réellement eu lieu

  -- Ce qu'on a obtenu. NULL tant que le rappel n'a pas été fait.
  --   repondu   : on a eu la personne
  --   absent    : pas décroché, messagerie
  --   reporte   : la personne a demandé à être rappelée plus tard
  --   refus     : elle ne veut pas être rappelée
  issue       VARCHAR(20)  NULL,
  note        VARCHAR(500) NULL,

  cree_par    INT UNSIGNED NULL,
  fait_par    INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  -- L'historique d'une fiche, dans l'ordre.
  KEY idx_fiche (org_id, prospect_id, du_at),
  -- Les rappels encore en attente d'une association, par échéance.
  KEY idx_attente (org_id, fait_at, du_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reprise de l'existant : chaque fiche qui portait déjà une date de
-- rappel reçoit sa première ligne. Sans cela, les rappels programmés
-- avant aujourd'hui disparaîtraient de l'historique — ils sont dans la
-- colonne, mais plus dans la liste qui l'affiche désormais.
--
-- NOT EXISTS : la migration doit pouvoir être relancée sans créer de
-- doublon.
INSERT INTO asso_prospection_rappels (org_id, prospect_id, rang, du_at, cree_par, created_at)
SELECT p.org_id, p.id, 1, p.callback_at, p.updated_by, IFNULL(p.updated_at, NOW())
  FROM asso_prospection p
 WHERE p.callback_at IS NOT NULL
   AND p.deleted_at IS NULL
   AND NOT EXISTS (SELECT 1 FROM asso_prospection_rappels r
                    WHERE r.prospect_id = p.id AND r.fait_at IS NULL);
