-- ============================================================
-- Prospection Fondateur (asso_prospects) : réparation des séquences
-- ============================================================
-- Aucune modification de structure : uniquement des données.
--
-- 1. En mode simulation (AK_PROSPECT_SENDING_ENABLED à false), le cron
--    cron-prospect-send.php faisait quand même avancer la séquence : des
--    prospects sont marqués « Contacté » alors qu'aucun e-mail n'est parti
--    (aucun événement « sent »). On les remet en file à la 1re étape, comme
--    après « Tout mettre en séquence auto » : ils partiront quand l'envoi
--    sera activé, dans la limite du plafond quotidien.
-- 2. Une séquence terminée gardait step = 4 (dernier e-mail) : elle est
--    maintenant notée step = 5 (fin), comme le fait le code corrigé.
-- 3. « Relance » sans date sur un prospect « Nouveau » le mettait en file
--    sans échéance : le cron ne le prend plus (il exige une échéance) et
--    « Tout mettre en séquence auto » ne voit que les « Nouveau ». Ces
--    lignes, encore à la 1re étape, reviennent à « Nouveau ».
--
-- Idempotent : on peut relancer. Si la table asso_prospects n'existe pas
-- (prospection jamais ouverte), les instructions échouent sans effet :
-- il n'y a alors rien à réparer.
--   /usr/local/bin/php migrations/run.php 2026-10-11-L12-cockpit.sql
-- ============================================================

UPDATE asso_prospects p
   SET p.status = 'queued', p.step = 0, p.next_send_at = NOW(), p.last_sent_at = NULL
 WHERE p.status IN ('contacted', 'engaged')
   AND NOT EXISTS (SELECT 1 FROM asso_prospect_events e WHERE e.prospect_id = p.id AND e.type = 'sent');

UPDATE asso_prospects p
   SET p.step = 5
 WHERE p.step = 4
   AND p.next_send_at IS NULL
   AND EXISTS (SELECT 1 FROM asso_prospect_events e WHERE e.prospect_id = p.id AND e.type = 'sent' AND e.step = 4);

UPDATE asso_prospects
   SET status = 'new'
 WHERE status = 'queued'
   AND step = 0
   AND next_send_at IS NULL;
