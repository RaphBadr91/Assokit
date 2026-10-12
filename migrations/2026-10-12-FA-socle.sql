-- ============================================================
-- Suiveurs (financeurs externes) avec une capacité restée cochée
-- ============================================================
-- Aucune modification de structure : uniquement des données.
--
-- Le formulaire « Modifier l'utilisateur » masque les capacités quand on
-- choisit le rôle Suiveur, mais les cases déjà cochées partaient quand même :
-- un adhérent trésorier passé en Suiveur gardait « Gérer les finances » et
-- can('manage_finances') lui ouvrait /comptabilite, /export-fec, /previsions,
-- /anomalies et /facturx (idem « Gérer les membres » et « Marketing »).
-- Le code est corrigé (action-admin-utilisateur.php : aucune capacité pour
-- un suiveur, menu masqué) ; on retire ici ces capacités aux suiveurs déjà
-- touchés. Un suiveur n'en a jamais eu l'usage voulu : le préréglage du rôle
-- les met toutes à zéro et la facturation l'exclut déjà.
--
-- Idempotent : on peut relancer.
--   /usr/local/bin/php migrations/run.php 2026-10-12-FA-socle.sql
-- ============================================================

UPDATE users SET can_manage_finances = 0, can_manage_members = 0, can_access_marketing = 0
 WHERE role = 'follower'
   AND (can_manage_finances = 1 OR can_manage_members = 1 OR can_access_marketing = 1);
