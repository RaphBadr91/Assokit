-- ============================================================
-- Boîte mail Gmail dans Assokit
-- ============================================================
-- Une boîte Gmail par association, reliée par le bouton officiel
-- « Se connecter avec Google ». Les e-mails sont copiés localement pour
-- être triés par catégorie (règles sur l'objet + IA), rattachés aux
-- fiches (adhérent, client, facture) et traités par l'équipe ; les
-- réponses partent de la vraie adresse, dans le même fil Gmail.
--
-- Idempotent, sans perte : uniquement des CREATE TABLE IF NOT EXISTS.
--   /usr/local/bin/php migrations/run.php 2026-10-06-boite-mail.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS mail_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  org_id INT NOT NULL,
  provider VARCHAR(16) NOT NULL DEFAULT 'gmail',
  email VARCHAR(190) NOT NULL,
  display_name VARCHAR(190) NULL,
  access_token_enc TEXT NULL,
  refresh_token_enc TEXT NULL,
  token_expires_at DATETIME NULL,
  history_id VARCHAR(40) NULL,
  sync_cursor VARCHAR(255) NULL,
  initial_done TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  last_error VARCHAR(500) NULL,
  last_sync_at DATETIME NULL,
  sync_days SMALLINT NOT NULL DEFAULT 30,
  retention_months SMALLINT NOT NULL DEFAULT 24,
  ai_sort TINYINT(1) NOT NULL DEFAULT 1,
  signature TEXT NULL,
  connected_by_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mail_accounts_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  org_id INT NOT NULL,
  slug VARCHAR(40) NOT NULL,
  label VARCHAR(80) NOT NULL,
  color VARCHAR(9) NOT NULL DEFAULT '#64748B',
  icon VARCHAR(30) NOT NULL DEFAULT 'tag',
  keywords TEXT NULL,
  roles VARCHAR(120) NULL,
  position SMALLINT NOT NULL DEFAULT 0,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mail_categories_slug (org_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_threads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  org_id INT NOT NULL,
  account_id INT NOT NULL,
  gmail_thread_id VARCHAR(40) NOT NULL,
  subject VARCHAR(500) NULL,
  snippet VARCHAR(500) NULL,
  counterpart_email VARCHAR(190) NULL,
  counterpart_name VARCHAR(190) NULL,
  last_message_at DATETIME NULL,
  last_direction VARCHAR(3) NOT NULL DEFAULT 'in',
  message_count INT NOT NULL DEFAULT 0,
  unread TINYINT(1) NOT NULL DEFAULT 0,
  category_id INT NULL,
  category_source VARCHAR(10) NOT NULL DEFAULT 'none',
  linked_user_id INT NULL,
  linked_client_id INT NULL,
  linked_invoice_id INT NULL,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mail_threads_gmail (account_id, gmail_thread_id),
  KEY idx_mail_threads_list (org_id, is_archived, category_id, last_message_at),
  KEY idx_mail_threads_unread (org_id, unread)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  org_id INT NOT NULL,
  thread_id INT NOT NULL,
  gmail_message_id VARCHAR(40) NOT NULL,
  rfc_message_id VARCHAR(500) NULL,
  references_hdr TEXT NULL,
  direction VARCHAR(3) NOT NULL DEFAULT 'in',
  from_email VARCHAR(190) NULL,
  from_name VARCHAR(190) NULL,
  reply_to VARCHAR(190) NULL,
  to_list TEXT NULL,
  cc_list TEXT NULL,
  subject VARCHAR(500) NULL,
  body_text MEDIUMTEXT NULL,
  body_html MEDIUMTEXT NULL,
  sent_at DATETIME NULL,
  label_ids VARCHAR(500) NULL,
  attachments_json TEXT NULL,
  sent_by_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mail_messages_gmail (org_id, gmail_message_id),
  KEY idx_mail_messages_thread (thread_id, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
