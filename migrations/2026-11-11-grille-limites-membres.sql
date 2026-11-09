-- ============================================================
-- Grille tarifaire : nouvelles limites de membres (Essentiel 50, Équipe 3 000)
-- ============================================================
-- Décision du fondateur, prise avant la mise en production de la grille :
--   - Essentiel (ak-essentiel) : jusqu'à 50 membres (bureau compris), au lieu de 100 ;
--   - Équipe (ak-equipe) : jusqu'à 3 000 membres (bureau compris), au lieu de 2 000,
--     présentée comme « Structures avec une équipe et petites fédérations » ;
--   - Sur-mesure : « Fédérations, ligues, collectivités territoriales ».
-- Pro, les prix, les slugs et les fonctions ne changent pas.
--
-- 2026-11-09-grille-tarifaire.sql pose déjà ces valeurs sur une installation neuve. Cette
-- migration ne sert que si l'ancienne version de 2026-11-09-grille-tarifaire.sql (100 et
-- 2 000 membres) a déjà tourné : sa marque grille_version = 202611 empêche alors toute
-- réécriture des valeurs par une relance.
--
-- Idempotent : chaque valeur n'est réécrite que si elle vaut encore EXACTEMENT l'ancienne
-- valeur de la grille (accroches comparées octet par octet, en utf8mb4_bin : une accroche
-- retouchée d'une majuscule ou d'un accent compte comme modifiée) ; une modification faite
-- ensuite dans Pilotage › Plans tarifaires n'est jamais écrasée. Une relance ne change rien. Aucun DELETE, aucun changement de slug,
-- de prix ni de fonction. Sans les colonnes de la grille (2026-11-09-grille-tarifaire.sql pas
-- encore lancée), chaque instruction se réduit à « DO 0 » : rien n'est modifié, aucune erreur.
-- Cible : MariaDB 10.x.
-- Lancement : /usr/local/bin/php migrations/run.php 2026-11-11-grille-limites-membres.sql
-- ============================================================

-- Colonnes nécessaires (limit_adherents, tagline, grille_version, updated_at) : 4 si elles existent toutes.
SET @ak_grille = (SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asso_plans'
                    AND COLUMN_NAME IN ('limit_adherents', 'tagline', 'grille_version', 'updated_at'));

-- Essentiel : 100 → 50 membres (bureau compris)
SET @ak_sql = IF(@ak_grille = 4,
  'UPDATE asso_plans SET limit_adherents = 50, updated_at = NOW() WHERE slug = ''ak-essentiel'' AND COALESCE(grille_version, 0) >= 202611 AND limit_adherents = 100',
  'DO 0');
PREPARE ak_stmt FROM @ak_sql;
EXECUTE ak_stmt;
DEALLOCATE PREPARE ak_stmt;

-- Équipe : 2 000 → 3 000 membres (bureau compris)
SET @ak_sql = IF(@ak_grille = 4,
  'UPDATE asso_plans SET limit_adherents = 3000, updated_at = NOW() WHERE slug = ''ak-equipe'' AND COALESCE(grille_version, 0) >= 202611 AND limit_adherents = 2000',
  'DO 0');
PREPARE ak_stmt FROM @ak_sql;
EXECUTE ak_stmt;
DEALLOCATE PREPARE ak_stmt;

-- Équipe : accroche (petites fédérations)
SET @ak_sql = IF(@ak_grille = 4,
  'UPDATE asso_plans SET tagline = ''Structures avec une équipe et petites fédérations'', updated_at = NOW() WHERE slug = ''ak-equipe'' AND COALESCE(grille_version, 0) >= 202611 AND CONVERT(tagline USING utf8mb4) COLLATE utf8mb4_bin = CONVERT(''Structures avec une équipe'' USING utf8mb4) COLLATE utf8mb4_bin',
  'DO 0');
PREPARE ak_stmt FROM @ak_sql;
EXECUTE ak_stmt;
DEALLOCATE PREPARE ak_stmt;

-- Sur-mesure : accroche (fédérations, ligues, collectivités territoriales)
SET @ak_sql = IF(@ak_grille = 4,
  'UPDATE asso_plans SET tagline = ''Fédérations, ligues, collectivités territoriales'', updated_at = NOW() WHERE slug = ''sur-mesure'' AND COALESCE(grille_version, 0) >= 202611 AND CONVERT(tagline USING utf8mb4) COLLATE utf8mb4_bin = CONVERT(''Réseaux, fédérations, collectivités'' USING utf8mb4) COLLATE utf8mb4_bin',
  'DO 0');
PREPARE ak_stmt FROM @ak_sql;
EXECUTE ak_stmt;
DEALLOCATE PREPARE ak_stmt;
