-- ============================================================
-- Accord de l'utilisateur avant l'envoi de contenus à l'IA, dans l'app
-- ============================================================
-- Règle 5.1.2(i) d'Apple : l'app demande une fois l'accord de l'utilisateur
-- avant d'envoyer un contenu à Anthropic (États-Unis), sous-traitant d'IA
-- d'Assokit. La décision est gardée ici, une ligne par compte
-- (ai-consent-helper.php, api/app-ai-consent.php).
--
-- La table est aussi créée automatiquement à la première utilisation
-- (ak_ai_consent_ready) : cette migration n'est qu'une précaution.
--
-- Idempotent : on peut relancer.
--   /usr/local/bin/php migrations/run.php 2026-10-18-accord-ia.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS user_ai_consent (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    consent TINYINT(1) NOT NULL,
    decided_at DATETIME NOT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'app'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
