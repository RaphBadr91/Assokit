-- ============================================================
-- Appli Fondateur : journal plateforme + état des demandes de contact
-- ============================================================
-- 1) Journal plateforme (données uniquement)
--
-- L'appli journalisait ses actions sous des codes à part (founder_validate,
-- founder_suspend…) que l'historique du cockpit web (/super-admin/logs)
-- affichait en code brut et que ses filtres (« Suspensions »…) ne
-- retrouvaient pas. Elle écrit maintenant les mêmes codes que le web ;
-- les anciennes entrées sont renommées ici de la même façon.
--
-- Idempotent : on peut relancer (plus rien à renommer au 2e passage).
-- Si la table platform_activity_log n'existe pas, les instructions
-- échouent sans effet : il n'y a alors rien à renommer.
--   /usr/local/bin/php migrations/run.php 2026-10-11-L13-api-fondateur.sql
-- ============================================================

UPDATE platform_activity_log SET action = 'validate_org'   WHERE action = 'founder_validate';
UPDATE platform_activity_log SET action = 'reject_org'     WHERE action = 'founder_reject';
UPDATE platform_activity_log SET action = 'suspend_org'    WHERE action = 'founder_suspend';
UPDATE platform_activity_log SET action = 'reactivate_org' WHERE action = 'founder_activate';
UPDATE platform_activity_log SET action = 'cancel_org'     WHERE action = 'founder_resiliate';
UPDATE platform_activity_log SET action = 'update_org'     WHERE action = 'founder_edit';
UPDATE platform_activity_log SET action = 'trial_extend'   WHERE action = 'founder_extend_trial';

-- ============================================================
-- 2) Demandes de contact : colonne d'état (new / read / replied)
-- ============================================================
-- L'appli Fondateur lit et écrit asso_contact_messages.status (non lue,
-- lue à l'ouverture, « Répondu »), mais aucune migration ne la crée et
-- les formulaires du site ne la renseignent pas : sans elle, le cockpit
-- et l'écran Contacts ne comptaient pas les mêmes demandes non lues.
-- Le code fonctionne sans la colonne (état déduit du fil) ; avec elle,
-- une demande ouverte compte comme lue.
--
-- Idempotent : IF NOT EXISTS, et la reprise ne touche que les demandes
-- encore « new » déjà répondues et sans réponse du prospect à lire :
-- « replied » si le dernier message du fil est la réponse envoyée, « read »
-- sinon — le même état que celui que l'appli affichait sans la colonne,
-- donc aucun compteur ne bouge au passage de la migration.
-- La table du fil est celle que l'appli crée à la première réponse
-- (même définition qu'ak_contact_thread_ensure).

ALTER TABLE asso_contact_messages ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'new';
ALTER TABLE asso_contact_messages ADD INDEX IF NOT EXISTS idx_status (status);

CREATE TABLE IF NOT EXISTS asso_contact_thread (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contact_id INT NOT NULL,
    direction VARCHAR(8) NOT NULL,
    body MEDIUMTEXT,
    from_email VARCHAR(255) DEFAULT NULL,
    read_by_founder TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    INDEX idx_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE asso_contact_messages m
   SET m.status = IF((SELECT t.direction FROM asso_contact_thread t
                       WHERE t.contact_id = m.id
                       ORDER BY t.created_at DESC, t.id DESC LIMIT 1) = 'out', 'replied', 'read')
 WHERE m.status = 'new'
   AND EXISTS (SELECT 1 FROM asso_contact_thread o WHERE o.contact_id = m.id AND o.direction = 'out')
   AND NOT EXISTS (SELECT 1 FROM asso_contact_thread i WHERE i.contact_id = m.id AND i.direction = 'in' AND i.read_by_founder = 0);
