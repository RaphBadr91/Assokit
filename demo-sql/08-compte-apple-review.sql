-- ============================================================
-- 08-compte-apple-review.sql — Compte remis à Apple pour l'examen
-- ============================================================
--
-- Pourquoi un fichier séparé, et pourquoi le numéro 08 :
--
-- cron-demo-reset.php charge tous les .sql de ce dossier par ordre
-- alphabétique. Le snapshot (00) commence par
--     DELETE FROM users WHERE org_id IN (23,24,25,26)
-- ce qui effacerait chaque nuit un compte ajouté à une asso démo.
-- En s'exécutant APRÈS, ce fichier le recrée à l'identique : le compte
-- d'examen survit au reset, et repart chaque nuit sur des données propres.
--
-- Il est aussi idempotent : on peut le rejouer à volonté.
--
-- Identifiants (à recopier dans App Store Connect > Notes pour l'examen) :
--     apple.review@assokit.fr / AppleReview2026!
--
-- Le compte est administrateur de l'org 23 « Solidarité Évry » : 50
-- adhérents, 40 factures, 10 clients, 12 événements, des projets, des
-- canaux de discussion — et, ajoutés ci-dessous, des cotisations et des
-- subventions, que le snapshot ne contient pas.
--
-- Aucune surface de paiement ne lui est visible : app-context.php masque
-- les pages d'abonnement pour cette adresse comme pour toute requête
-- venant de l'app (Apple 3.1.1).
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. Le compte lui-même
-- ------------------------------------------------------------
-- On clone une ligne existante de l'org 23 plutôt que d'énumérer les
-- colonnes : le schéma users en compte une quarantaine et bouge encore.
-- Cloner garantit qu'aucune colonne NOT NULL n'est oubliée.

DELETE FROM `users` WHERE `email` = 'apple.review@assokit.fr' OR `id` = 90001;

DROP TEMPORARY TABLE IF EXISTS `ak_tmp_review`;

CREATE TEMPORARY TABLE `ak_tmp_review` AS
    SELECT * FROM `users`
    WHERE `org_id` = 23 AND `is_active` = 1 AND `deleted_at` IS NULL
    ORDER BY `id` LIMIT 1;

UPDATE `ak_tmp_review` SET
    `id`                     = 90001,
    `org_id`                 = 23,
    `email`                  = 'apple.review@assokit.fr',
    `password_hash`          = '$2y$12$XcK65naz7WjxRlyh2QOTweRjyA2JH6SzjO7OQ/WEFGa56Ohd64aCi',
    `first_name`             = 'App Store',
    `last_name`              = 'Review',
    `phone`                  = '+33 1 23 45 67 89',
    `city`                   = 'Évry-Courcouronnes',
    `role`                   = 'admin',
    `is_active`              = 1,
    `deleted_at`             = NULL,
    `deleted_by_user_id`     = NULL,
    `must_change_password`   = 0,
    `is_super_admin`         = 0,
    `is_founder`             = 0,
    `parent_org_id`          = NULL,
    `parent_org_role`        = NULL,
    `ics_token`              = 'applereview0000000000000000000ak',
    `can_create_projects`    = 1,
    `can_create_folders`     = 1,
    `can_manage_members`     = 1,
    `can_manage_finances`    = 1,
    `can_access_marketing`   = 1,
    `can_manage_events`      = 1,
    `can_moderate_messages`  = 1,
    `onboarding_completed_at`= NOW(),
    `last_login_at`          = NULL,
    `totp_secret`            = NULL,
    `totp_enabled`           = 0,
    `totp_backup_codes`      = NULL,
    `created_at`             = NOW();

INSERT INTO `users` SELECT * FROM `ak_tmp_review`;

DROP TEMPORARY TABLE IF EXISTS `ak_tmp_review`;

-- ------------------------------------------------------------
-- 2. Campagne de cotisation 2026 (absente du snapshot)
-- ------------------------------------------------------------

DELETE FROM `cotisation_payments` WHERE `campaign_id` = 9001;
DELETE FROM `cotisation_tiers`    WHERE `campaign_id` = 9001;
DELETE FROM `cotisation_campaigns` WHERE `id` = 9001;

INSERT INTO `cotisation_campaigns`
    (`id`, `org_id`, `name`, `year`, `description`, `currency`, `opens_at`, `closes_at`, `is_active`, `public_token`, `created_by`)
VALUES
    (9001, 23, 'Adhésions 2026', 2026,
     'Campagne annuelle d''adhésion. Trois formules : réduite, normale, de soutien.',
     'EUR', '2026-01-01', '2026-12-31', 1, 'demo-cotisation-2026-evry', 90001);

INSERT INTO `cotisation_tiers` (`id`, `campaign_id`, `name`, `amount`, `description`, `position`) VALUES
    (9001, 9001, 'Tarif réduit',   12.00, 'Étudiants, demandeurs d''emploi, moins de 25 ans.', 1),
    (9002, 9001, 'Tarif normal',   25.00, 'Adhésion individuelle pour l''année civile.',       2),
    (9003, 9001, 'Membre bienfaiteur', 60.00, 'Soutien renforcé, reçu fiscal envoyé en janvier.', 3);

-- Cotisations réglées : les 30 premiers adhérents de l'asso.
INSERT INTO `cotisation_payments`
    (`campaign_id`, `tier_id`, `org_id`, `adherent_id`, `payer_name`, `payer_email`,
     `amount`, `currency`, `payment_method`, `status`, `reference`, `notes`, `paid_at`, `created_by`)
SELECT 9001,
       CASE WHEN u.id % 7 = 0 THEN 9003 WHEN u.id % 3 = 0 THEN 9001 ELSE 9002 END,
       23, u.id,
       CONCAT(u.first_name, ' ', u.last_name), u.email,
       CASE WHEN u.id % 7 = 0 THEN 60.00 WHEN u.id % 3 = 0 THEN 12.00 ELSE 25.00 END,
       'EUR',
       CASE WHEN u.id % 4 = 0 THEN 'check' WHEN u.id % 4 = 1 THEN 'cash' ELSE 'bank' END,
       'paid',
       CONCAT('COT-2026-', LPAD(u.id, 5, '0')),
       NULL,
       NOW() - INTERVAL (10 + (u.id % 90)) DAY,
       90001
FROM `users` u
WHERE u.org_id = 23 AND u.is_active = 1 AND u.deleted_at IS NULL
ORDER BY u.id LIMIT 30;

-- Cotisations en attente : les 8 suivants. Sans elles, l'écran « à relancer »
-- serait vide et ne montrerait rien de ce que fait le produit.
INSERT INTO `cotisation_payments`
    (`campaign_id`, `tier_id`, `org_id`, `adherent_id`, `payer_name`, `payer_email`,
     `amount`, `currency`, `payment_method`, `status`, `reference`, `notes`, `paid_at`, `created_by`)
SELECT 9001, 9002, 23, u.id,
       CONCAT(u.first_name, ' ', u.last_name), u.email,
       25.00, 'EUR', 'bank', 'pending',
       CONCAT('COT-2026-', LPAD(u.id, 5, '0')),
       'Relance envoyée par e-mail.',
       NULL,
       90001
FROM `users` u
WHERE u.org_id = 23 AND u.is_active = 1 AND u.deleted_at IS NULL
ORDER BY u.id LIMIT 8 OFFSET 30;

-- ------------------------------------------------------------
-- 3. Subventions (absentes du snapshot)
-- ------------------------------------------------------------
-- Dates relatives à aujourd'hui : la démo ne périme pas.

DELETE FROM `grants` WHERE `id` BETWEEN 9001 AND 9010;

INSERT INTO `grants`
    (`id`, `org_id`, `project_id`, `name`, `funder`, `funder_type`, `description`,
     `amount_requested`, `amount_granted`, `currency`, `status`,
     `deadline_apply`, `submitted_at`, `decision_at`, `deadline_report`, `reported_at`,
     `cerfa_number`, `reference`, `platform`, `platform_url`,
     `contact_name`, `contact_email`, `contact_phone`, `notes`, `created_by`)
VALUES
    (9001, 23, NULL, 'FDVA 2 — Fonctionnement et innovation', 'DRAJES Île-de-France', 'etat',
     'Soutien au fonctionnement de l''association et au programme d''accompagnement scolaire.',
     8000.00, 6500.00, 'EUR', 'granted',
     CURDATE() - INTERVAL 120 DAY, CURDATE() - INTERVAL 125 DAY, CURDATE() - INTERVAL 60 DAY,
     CURDATE() + INTERVAL 95 DAY, NULL,
     '12156*05', 'FDVA2-2026-0431', 'Le Compte Asso', 'https://lecompteasso.associations.gouv.fr',
     'Service vie associative', 'vie.associative@exemple.gouv.fr', '01 40 00 00 00',
     'Bilan à déposer avant la fin du trimestre.', 90001),

    (9002, 23, NULL, 'Appel à projets Quartiers solidaires', 'Ville d''Évry-Courcouronnes', 'commune',
     'Ateliers hebdomadaires d''aide aux devoirs pour 40 enfants du quartier.',
     4500.00, NULL, 'EUR', 'in_review',
     CURDATE() - INTERVAL 30 DAY, CURDATE() - INTERVAL 32 DAY, NULL,
     NULL, NULL,
     NULL, 'QS-2026-118', NULL, NULL,
     'Direction de la vie associative', 'associations@exemple-ville.fr', '01 60 00 00 00',
     'Commission d''attribution annoncée pour le mois prochain.', 90001),

    (9003, 23, NULL, 'Fonds départemental d''aide aux associations', 'Conseil départemental de l''Essonne', 'departement',
     'Achat de matériel informatique pour l''espace numérique.',
     3000.00, NULL, 'EUR', 'submitted',
     CURDATE() + INTERVAL 12 DAY, CURDATE() - INTERVAL 5 DAY, NULL,
     NULL, NULL,
     NULL, 'CD91-2026-0087', 'Portail CD91', 'https://www.essonne.fr',
     'Pôle associations', 'associations@exemple-cd91.fr', NULL,
     NULL, 90001),

    (9004, 23, NULL, 'Fondation de France — Vivre ensemble', 'Fondation de France', 'fondation',
     'Programme intergénérationnel entre le foyer-logement et les collégiens.',
     15000.00, NULL, 'EUR', 'draft',
     CURDATE() + INTERVAL 40 DAY, NULL, NULL,
     NULL, NULL,
     NULL, NULL, NULL, NULL,
     NULL, NULL, NULL,
     'Dossier en cours de rédaction. Lettre d''intention à joindre.', 90001),

    (9005, 23, NULL, 'CAF — Appel à projets parentalité', 'CAF de l''Essonne', 'caf',
     'Cycle de six ateliers parents-enfants sur l''année scolaire.',
     5200.00, 5200.00, 'EUR', 'reported',
     CURDATE() - INTERVAL 300 DAY, CURDATE() - INTERVAL 305 DAY, CURDATE() - INTERVAL 240 DAY,
     CURDATE() - INTERVAL 30 DAY, CURDATE() - INTERVAL 34 DAY,
     '12156*05', 'CAF91-2025-2214', NULL, NULL,
     'Référente parentalité', 'parentalite@exemple-caf.fr', '01 69 00 00 00',
     'Bilan accepté. Reconduction possible l''an prochain.', 90001),

    (9006, 23, NULL, 'Mécénat local — Entreprises du plateau', 'Groupe Maréchal & Fils', 'entreprise',
     'Financement des maillots et du transport pour le tournoi de printemps.',
     2000.00, NULL, 'EUR', 'rejected',
     CURDATE() - INTERVAL 90 DAY, CURDATE() - INTERVAL 92 DAY, CURDATE() - INTERVAL 45 DAY,
     NULL, NULL,
     NULL, NULL, NULL, NULL,
     'Service communication', 'mecenat@exemple-entreprise.fr', NULL,
     'Refus : enveloppe mécénat déjà engagée. Recontacter en janvier.', 90001);

SET FOREIGN_KEY_CHECKS = 1;
