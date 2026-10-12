-- ============================================================
-- Suggestions du jour (bloc « Assokit, votre copilote » du tableau de bord)
-- ============================================================
-- La table today_suggestions n'était définie nulle part dans le dépôt.
-- Le code y lit et y écrit : suggestions_json, has_error, error_message,
-- profile, refresh_count, tokens_input, tokens_output, last_generated_at,
-- last_refreshed_at. Il n'écrit déjà que les colonnes présentes (écritures
-- adaptées au schéma) ; cette migration garantit qu'elles existent toutes :
--   - sans tokens_* ni last_refreshed_at, l'enregistrement d'une réponse IA
--     échouait en silence (pas de cache : un appel IA payant par visite) ;
--   - last_generated_at sert à ne pas rappeler l'IA pendant 30 min après un échec ;
--   - refresh_count à NULL rendait inopérante la limite de 3 rafraîchissements
--     par jour (NULL + 1 = NULL).
-- Index (user_id, generation_date) : lecture de la ligne du jour.
--
-- Idempotent, relançable.
--   /usr/local/bin/php migrations/run.php 2026-10-11-L08-ia.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS today_suggestions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  org_id INT UNSIGNED NOT NULL,
  profile VARCHAR(20) NULL,
  generation_date DATE NOT NULL,
  suggestions_json MEDIUMTEXT NULL,
  has_error TINYINT(1) NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  refresh_count INT NOT NULL DEFAULT 0,
  tokens_input INT NOT NULL DEFAULT 0,
  tokens_output INT NOT NULL DEFAULT 0,
  last_generated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  last_refreshed_at DATETIME NULL,
  KEY idx_today_user_day (user_id, generation_date),
  KEY idx_today_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE today_suggestions
  ADD COLUMN IF NOT EXISTS profile VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS has_error TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS error_message TEXT NULL,
  ADD COLUMN IF NOT EXISTS refresh_count INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS tokens_input INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS tokens_output INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS last_generated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS last_refreshed_at DATETIME NULL,
  ADD INDEX IF NOT EXISTS idx_today_user_day (user_id, generation_date);

-- Compteurs restés à NULL : la limite de rafraîchissements repart de 0
UPDATE today_suggestions SET refresh_count = 0 WHERE refresh_count IS NULL;
UPDATE today_suggestions SET has_error = 0 WHERE has_error IS NULL;
ALTER TABLE today_suggestions ALTER COLUMN refresh_count SET DEFAULT 0;
ALTER TABLE today_suggestions ALTER COLUMN has_error SET DEFAULT 0;

-- Anciennes cartes de secours en cache avec des liens morts (/nouveau-utilisateur,
-- /admin-tickets) ou des compteurs faux : régénérées à la prochaine visite.
DELETE FROM today_suggestions
 WHERE has_error = 1
   AND (suggestions_json LIKE '%nouveau-utilisateur%' OR suggestions_json LIKE '%admin-tickets%');
