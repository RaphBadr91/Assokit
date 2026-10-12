-- ============================================================
-- Comptes supprimés mais encore actifs (« comptes fantômes »)
-- ============================================================
-- Aucune modification de structure : uniquement des données.
--
-- La page Administration listait les comptes de la corbeille parmi les
-- « Désactivés », et son bouton « Réactiver » remettait is_active = 1 sans
-- toucher à deleted_at. Le compte pouvait alors se connecter tout en restant
-- absent de /adherents et des compteurs. Le code est corrigé (admin.php,
-- action-admin-utilisateur.php) ; on désactive ici les comptes déjà touchés.
-- Une restauration depuis la corbeille de /adherents les réactive normalement
-- (deleted_at = NULL, is_active = 1) : rien n'est perdu.
--
-- Idempotent : on peut relancer. La borne de date écarte une éventuelle date
-- « zéro » héritée (qui ne signifie pas une suppression).
--   /usr/local/bin/php migrations/run.php 2026-10-11-L15-socle.sql
-- ============================================================

UPDATE users SET is_active = 0
 WHERE deleted_at IS NOT NULL AND deleted_at >= '2000-01-01' AND is_active = 1;

-- ============================================================
-- Associations supprimées encore rattachées à une collectivité
-- ============================================================
-- La suppression d'une association depuis le cockpit (deleted_at renseigné)
-- laissait son parent_org_id : la collectivité la voyait encore (liste, fiche,
-- e-mailing, canal de suivi) et elle occupait toujours une place du quota.
-- On fait ici, pour les associations déjà supprimées, ce que la suppression
-- fait désormais : canal de suivi archivé (messages conservés), agents de la
-- collectivité retirés du canal, rattachement levé. Une association restaurée
-- à la main en base se rattache de nouveau depuis le cockpit (« Lier »).
--
-- Données seulement, aucun changement de schéma. Idempotent.

-- 1. Canal de suivi mairie de l'association archivé
UPDATE channels c
INNER JOIN organizations o ON o.id = c.org_id
SET c.is_archived = 1, c.updated_at = NOW()
WHERE c.is_mairie_channel = 1 AND c.is_archived = 0
  AND o.deleted_at IS NOT NULL AND o.deleted_at >= '2000-01-01';

-- 2. Agents de la collectivité retirés de ce canal (historique des messages gardé)
DELETE cm FROM channel_members cm
INNER JOIN channels c ON c.id = cm.channel_id
INNER JOIN organizations o ON o.id = c.org_id
INNER JOIN users u ON u.id = cm.user_id
WHERE c.is_mairie_channel = 1
  AND o.deleted_at IS NOT NULL AND o.deleted_at >= '2000-01-01'
  AND u.parent_org_id IS NOT NULL AND u.parent_org_id = c.parent_org_id;

-- 3. Rattachement levé : l'association libère sa place dans le quota
UPDATE organizations SET parent_org_id = NULL, parent_org_linked_at = NULL
 WHERE deleted_at IS NOT NULL AND deleted_at >= '2000-01-01' AND parent_org_id IS NOT NULL;

-- ============================================================
-- Notifications cockpit « Nouveau message » d'un ticket de support
-- ============================================================
-- Leur lien menait à la liste du support (/super-admin/support?id=42, paramètre
-- ignoré) au lieu du ticket. Le code crée désormais /super-admin/support/42 ;
-- on corrige ici les notifications déjà créées (le .htaccess redirige aussi les
-- anciens liens des e-mails). Données seulement. Idempotent.
UPDATE platform_notifications
   SET target_url = CONCAT('/super-admin/support/', SUBSTRING_INDEX(target_url, '=', -1))
 WHERE target_url REGEXP '^/super-admin/support[?]id=[0-9]+$';
