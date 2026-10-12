-- ============================================================
-- Communication : désinscription des envois groupés + messagerie interne
-- ============================================================
-- 1. email_optouts : une ligne par (association, adresse) désinscrite des
--    envois groupés de cette association (diffusions, invitations aux
--    événements, diffusions IA). Alimentée par le lien de pied d'e-mail
--    /desinscription?l=… et par l'en-tête List-Unsubscribe (un clic).
--    Le code crée aussi la table au premier désinscrit si cette migration
--    n'a pas tourné (resend-helper.php, ak_optout_table_ensure).
-- 2. Les suiveurs (financeurs externes) n'ont pas accès à la messagerie
--    interne : on retire les notifications de messages de canaux qu'ils
--    avaient reçues (extraits de conversations internes). Les notifications
--    de projets (mentions dans un projet suivi) ne sont pas touchées.
--
-- Idempotent, relançable.
--   /usr/local/bin/php migrations/run.php 2026-10-11-L07-communication.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS email_optouts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  source VARCHAR(40) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_optout_org_email (org_id, email),
  KEY idx_optout_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE n FROM user_notifications n
JOIN users u ON u.id = n.user_id
WHERE u.role = 'follower'
  AND n.notification_type IN ('message', 'mention')
  AND n.link_url LIKE '/messages%';
