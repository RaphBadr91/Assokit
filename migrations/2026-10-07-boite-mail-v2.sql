-- ============================================================
-- Boîte mail v2 : priorités, newsletters, tri IA repris
-- ============================================================
-- - priority / priority_reason : « urgent », « important », « normal »,
--   « faible » + raison courte (ex. « Date limite de dépôt le 15/12 »)
-- - is_bulk : newsletter / promotion (Gmail CATEGORY_PROMOTIONS,
--   en-tête List-Unsubscribe…) — rangée à part, jamais urgente
-- - ai_done : 0 = à (re)trier par la nouvelle IA. Toutes les conversations
--   existantes repassent donc au tri, progressivement, au fil du cron.
--
-- Idempotent, sans perte.
--   /usr/local/bin/php migrations/run.php 2026-10-07-boite-mail-v2.sql
-- ============================================================

ALTER TABLE mail_threads
  ADD COLUMN IF NOT EXISTS priority VARCHAR(10) NOT NULL DEFAULT 'normal' AFTER category_source,
  ADD COLUMN IF NOT EXISTS priority_reason VARCHAR(160) NULL AFTER priority,
  ADD COLUMN IF NOT EXISTS is_bulk TINYINT(1) NOT NULL DEFAULT 0 AFTER priority_reason,
  ADD COLUMN IF NOT EXISTS ai_done TINYINT(1) NOT NULL DEFAULT 0 AFTER is_bulk;

ALTER TABLE mail_threads
  ADD INDEX IF NOT EXISTS idx_mail_threads_prio (org_id, is_archived, priority, last_message_at);

ALTER TABLE mail_messages
  ADD COLUMN IF NOT EXISTS is_bulk TINYINT(1) NOT NULL DEFAULT 0 AFTER label_ids;
