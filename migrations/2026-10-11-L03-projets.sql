-- ============================================================
-- Projets : un seul avancement partout (données à remettre d'aplomb)
-- ============================================================
-- Pour un projet qui a des étapes, l'avancement est « étapes validées /
-- total » : c'est ce qu'affichent la fiche projet et l'appli, et ce que
-- recalcule action-etape.php à chaque étape cochée. Le curseur
-- « Progression » de /modifier-projet pouvait l'écraser : la liste
-- /projets et l'appli montraient alors un autre chiffre. Le curseur est
-- désormais réservé aux projets sans étape ; cette requête recale les
-- projets déjà désalignés.
--
-- Pas de changement de schéma. Idempotent : relancée, elle ne modifie
-- plus aucune ligne. Le code fonctionne avec ou sans elle (les projets
-- se recalent aussi au prochain enregistrement ou à la prochaine étape).
--   /usr/local/bin/php migrations/run.php 2026-10-11-L03-projets.sql
-- ============================================================

UPDATE projects p
JOIN (
    SELECT project_id,
           ROUND(COALESCE(SUM(is_completed), 0) * 100 / COUNT(*)) AS pct
    FROM project_steps
    GROUP BY project_id
) s ON s.project_id = p.id
SET p.progress_percent = s.pct
WHERE p.progress_percent IS NULL OR p.progress_percent <> s.pct;

-- ============================================================
-- Équipes de projet : retirer les comptes d'une autre association
-- ============================================================
-- La création de projet sur le site (/nouveau-projet) acceptait dans
-- l'équipe n'importe quel identifiant de compte, y compris celui d'une
-- autre association. Le formulaire ne garde plus que les comptes de
-- l'association ; les lignes déjà enregistrées sont retirées ici (la
-- fiche projet, la liste et l'appli les ignorent déjà).
-- Idempotent : relancée, elle ne supprime plus rien.
-- ============================================================

DELETE pm FROM project_members pm
JOIN projects p ON p.id = pm.project_id
JOIN folders f ON f.id = p.folder_id
JOIN users u ON u.id = pm.user_id
WHERE u.org_id <> f.org_id;
