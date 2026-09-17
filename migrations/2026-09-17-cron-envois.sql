-- ------------------------------------------------------------------
-- 2026-09-17-cron-envois.sql
-- ------------------------------------------------------------------
-- Le carnet des messages déjà partis, pour les crons qui n'en tenaient
-- pas.
--
-- Nos crons passent d'un lancement par jour à un lancement toutes les
-- dix minutes — c'est ce qui leur permet de traiter 10 000 associations
-- par petits lots au lieu de caler au bout de quelques centaines.
--
-- Mais un cron qui envoyait un rappel « votre essai se termine dans 3
-- jours » en se fiant au fait de ne tourner qu'une fois par jour
-- l'enverrait désormais 96 fois. La plupart savaient déjà se relire
-- (grant_alert_sent, asso_invoice_emails_log) ; celui des fins d'essai,
-- non. Cette table lui sert de mémoire.
--
-- La clé unique est le cœur du dispositif : on insère AVANT d'envoyer,
-- et c'est l'insertion qui tranche. Deux passages simultanés ne peuvent
-- pas gagner tous les deux, là où un « SELECT puis INSERT » leur
-- laisserait le temps de se croiser.
--
-- `cle` porte la date (« j3:2026-09-20 ») : sans elle, un rappel serait
-- bloqué pour toujours après le premier envoi, alors qu'il doit pouvoir
-- repartir pour une autre échéance.
--
-- Usage :
--   php migrations/run.php 2026-09-17-cron-envois.sql
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS cron_envois (
  id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job     VARCHAR(40)     NOT NULL,
  org_id  INT UNSIGNED    NOT NULL,
  cle     VARCHAR(60)     NOT NULL,
  sent_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_envoi (job, org_id, cle),
  -- Pour la purge : la table ne doit pas grossir indéfiniment.
  KEY idx_date (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------
-- Le tour de rôle des associations
-- ------------------------------------------------------------------
-- Certains crons (relances de factures, radar de subventions) marquent
-- le travail fait sur la facture ou sur la subvention, pas sur
-- l'association. Impossible donc de demander à la base « les 200
-- associations qu'il reste à voir » : elle les renverrait toutes.
--
-- Sans tour de rôle, chaque passage reprendrait la liste au début et
-- épuiserait son lot sur les deux cents premières. Les suivantes ne
-- seraient jamais servies — exactement le défaut qu'on corrige.
--
-- On note donc quand chaque élément a été examiné pour la dernière
-- fois, et on sert d'abord ceux qui attendent depuis le plus longtemps.
-- Le tour se rééquilibre tout seul, il n'y a aucun compteur à remettre
-- à zéro, et un élément créé demain passe en tête puisqu'il n'a jamais
-- été vu.
--
-- `cible` et non `org_id` : le radar de subventions fait le tour des
-- associations, mais les rappels d'échéance font celui des dossiers de
-- subvention. Une colonne nommée org_id aurait menti à l'un des deux.
CREATE TABLE IF NOT EXISTS cron_tours (
  job   VARCHAR(40)  NOT NULL,
  cible INT UNSIGNED NOT NULL,
  vu_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (job, cible),
  KEY idx_job_vu (job, vu_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
