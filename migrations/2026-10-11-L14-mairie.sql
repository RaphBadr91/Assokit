-- ============================================================
-- Agents de collectivité retirés : comptes restés actifs
-- ============================================================
-- Avant le correctif, « Retirer » un agent depuis les paramètres mairie
-- posait deleted_at sans désactiver le compte, et « Retirer » depuis le
-- cockpit ne faisait que le détacher (compte actif, toujours membre des
-- canaux de suivi) : l'ancien agent pouvait encore se connecter.
-- Ce script désactive ces comptes et les sort des canaux de suivi mairie.
-- Ils pourront être ré-invités (le compte est alors réactivé).
--
-- Données seulement, aucun changement de schéma. Idempotent.
--   /usr/local/bin/php migrations/run.php 2026-10-11-L14-mairie.sql
-- ============================================================

-- 1. Comptes supprimés (deleted_at renseigné) restés actifs
UPDATE users SET is_active = 0 WHERE deleted_at IS NOT NULL AND is_active = 1;

-- 2. Anciens agents détachés depuis le cockpit : ni association ni collectivité,
--    aucune casquette plateforme, mais encore membres d'un canal de suivi mairie
UPDATE users u
SET u.is_active = 0, u.deleted_at = COALESCE(u.deleted_at, NOW())
WHERE (u.org_id IS NULL OR u.org_id = 0)
  AND u.parent_org_id IS NULL
  AND COALESCE(u.is_platform_admin, 0) = 0
  AND COALESCE(u.is_super_admin, 0) = 0
  AND COALESCE(u.is_founder, 0) = 0
  AND u.role <> 'super_admin'
  AND EXISTS (
      SELECT 1 FROM channel_members cm
      INNER JOIN channels c ON c.id = cm.channel_id
      WHERE cm.user_id = u.id AND c.is_mairie_channel = 1
  );

-- 3. Comptes désactivés sans association ni collectivité : sortis des canaux de suivi
DELETE cm FROM channel_members cm
INNER JOIN channels c ON c.id = cm.channel_id
INNER JOIN users u ON u.id = cm.user_id
WHERE c.is_mairie_channel = 1
  AND u.is_active = 0
  AND (u.org_id IS NULL OR u.org_id = 0)
  AND u.parent_org_id IS NULL;
