-- ------------------------------------------------------------------
-- 2026-09-17-prospection-salaries.sql
-- ------------------------------------------------------------------
-- Ajoute aux fiches de prospection : « a des salariés ? » et, si oui,
-- combien.
--
-- Une association qui emploie n'a pas les mêmes besoins ni les mêmes
-- moyens qu'une association entièrement bénévole. Le savoir avant de
-- décrocher, c'est ne pas proposer la même chose aux deux.
--
-- Trois états et non deux, d'où une colonne qui accepte NULL :
--   NULL  on ne sait pas encore (l'état de toutes les fiches existantes)
--   1     oui, elle a des salariés
--   0     non, elle n'en a pas
--
-- « On ne sait pas » et « non » sont deux informations différentes. Les
-- confondre ferait passer pour vérifiées des centaines de fiches qui ne
-- l'ont jamais été, et on ne saurait plus lesquelles restent à qualifier.
--
-- nb_salaries reste NULL quand on répond oui sans connaître le nombre :
-- là encore, 0 voudrait dire « aucun salarié », ce qui contredirait le
-- oui juste à côté.
--
-- Usage :
--   php migrations/run.php 2026-09-17-prospection-salaries.sql
-- ------------------------------------------------------------------

ALTER TABLE asso_prospection
  ADD COLUMN IF NOT EXISTS salaries TINYINT(1) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS nb_salaries SMALLINT UNSIGNED DEFAULT NULL;

-- Pour le filtre « Salariés » de la page : sans index, trier les fiches
-- d'une association sur cette colonne oblige à les relire toutes.
ALTER TABLE asso_prospection
  ADD KEY IF NOT EXISTS ak_org_salaries (org_id, deleted_at, salaries);
