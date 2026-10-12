-- ============================================================
-- Finances : rappels de subventions + justificatifs des notes de frais
-- ============================================================
-- 1) grant_reminders_sent : journal des rappels déjà envoyés par
--    cron-grant-reminders.php (un rappel n'est envoyé qu'une fois).
--    Aucun CREATE TABLE n'était versionné : sans la table, le cron ne
--    pouvait rien noter et la suppression d'un dossier de subvention
--    échouait. Créée seulement si elle manque ; une table existante
--    n'est pas modifiée, sauf ajout de sent_at et d'un index sur grant_id.
--
-- 2) expense_report_lines : pièce justificative d'une ligne de note de
--    frais (photo du ticket, PDF). Le fichier est rangé hors du dossier
--    web et servi par /notes-de-frais après contrôle d'accès.
--    Sans cette migration, la page fonctionne mais ne propose pas de
--    joindre de justificatif.
--
-- 3) grants : le formulaire acceptait le statut « archived » sans poser
--    de date d'archivage. Ces dossiers, déjà traités comme archivés
--    (non modifiables), restaient dans la liste et les compteurs. On leur
--    donne une date d'archivage ; seuls ceux-là sont touchés.
--
-- Idempotent, sans perte : on peut relancer.
--   /usr/local/bin/php migrations/run.php 2026-10-11-L06-finances.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS grant_reminders_sent (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  grant_id      INT NOT NULL,
  reminder_type VARCHAR(32) NOT NULL,
  sent_at       DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_grant (grant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE grant_reminders_sent
  ADD COLUMN IF NOT EXISTS sent_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP;

-- Index sur grant_id seul : le type de reminder_type d'une table déjà en
-- place n'est pas connu (un index sur une colonne TEXT exigerait un préfixe).
ALTER TABLE grant_reminders_sent
  ADD INDEX IF NOT EXISTS idx_grant (grant_id);

ALTER TABLE expense_report_lines
  ADD COLUMN IF NOT EXISTS receipt_file VARCHAR(80) NULL,
  ADD COLUMN IF NOT EXISTS receipt_name VARCHAR(190) NULL;

UPDATE grants SET archived_at = NOW()
  WHERE status = 'archived' AND archived_at IS NULL;
