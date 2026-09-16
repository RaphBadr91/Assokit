-- ------------------------------------------------------------------
-- 2026-09-16-index-montee-en-charge.sql
-- ------------------------------------------------------------------
-- À coller dans l'onglet SQL de phpMyAdmin, pour ceux qui n'ont pas
-- la ligne de commande. Le script PHP du même nom fait le travail tout
-- seul ; celui-ci se contente de préparer les ordres à exécuter.
--
-- CE FICHIER NE MODIFIE RIEN. Il ne fait que LIRE le catalogue de la
-- base et ÉCRIRE, dans son résultat, les ALTER TABLE qui manquent.
-- Vous les relisez, vous les copiez, vous les exécutez. En deux temps.
--
-- Pourquoi en deux temps plutôt qu'un script qui pose les index
-- lui-même : la première version faisait exactement ça, avec une
-- procédure stockée et du SQL dynamique. Elle a fait tomber le serveur
-- MariaDB en test. Un outil qui touche à une base de production ne doit
-- pas pouvoir faire ça — d'où cette version qui ne peut rien casser,
-- puisqu'elle n'exécute rien.
--
-- Ce qu'elle vérifie avant de proposer un index :
--   · la table existe bien dans CETTE base ;
--   · toutes les colonnes demandées existent ;
--   · aucun index ne couvre déjà ces colonnes, même sous un autre nom
--     (un index sur (a,b,c) sert aussi pour (a) et pour (a,b)).
--
-- MODE D'EMPLOI
--   1. phpMyAdmin → sélectionner la base à gauche → onglet « SQL ».
--   2. Coller la REQUÊTE 1, exécuter.
--   3. Copier la colonne de résultat (les lignes « ALTER TABLE … »),
--      la recoller dans l'onglet SQL, exécuter.
--   4. Relancer la REQUÊTE 1 : elle doit ne plus rien renvoyer.
--   5. La REQUÊTE 2 est facultative : elle liste les index devenus
--      inutiles, qui coûtent de l'écriture pour rien.
--
-- Sur les grosses tables (journal d'activité), un index se construit en
-- quelques minutes. ALGORITHM=INPLACE, LOCK=NONE : le site reste
-- utilisable pendant ce temps.
-- ------------------------------------------------------------------


-- ══════════════════════════════════════════════════════════════════
-- REQUÊTE 1 — les index à poser
-- ══════════════════════════════════════════════════════════════════
-- L'ordre des colonnes n'est pas décoratif : d'abord ce sur quoi on
-- filtre par égalité (org_id, project_id), ensuite ce sur quoi on trie
-- ou on filtre par plage (created_at). C'est ce qui permet à MySQL de
-- lire directement les N lignes voulues au lieu de tout trier.

SELECT CONCAT(
         'ALTER TABLE `', p.t, '` ADD KEY `', p.n, '` (`',
         REPLACE(p.c, ',', '`,`'),
         '`), ALGORITHM=INPLACE, LOCK=NONE;'
       ) AS `-- copier ces lignes puis les executer`
FROM (
    -- Le noyau multi-associations : chaque page de l'app commence par
    -- « les X de mon association ».
              SELECT 'users' AS t, 'ak_org_actif' AS n, 'org_id,is_active,deleted_at' AS c
    UNION ALL SELECT 'users',         'ak_org_role',    'org_id,role'
    UNION ALL SELECT 'users',         'ak_derniere_cx', 'last_login_at'
    UNION ALL SELECT 'organizations', 'ak_supprime',    'deleted_at'
    UNION ALL SELECT 'organizations', 'ak_plan',        'plan,status'
    UNION ALL SELECT 'folders',       'ak_org_archive', 'org_id,archived_at'
    UNION ALL SELECT 'projects',      'ak_dossier',     'folder_id,status,archived_at'
    UNION ALL SELECT 'projects',      'ak_referent',    'referent_id,status,archived_at'
    UNION ALL SELECT 'projects',      'ak_maj',         'updated_at'

    -- Les tables filles des projets : pas d'org_id, on y arrive par project_id.
    UNION ALL SELECT 'project_activity_log', 'ak_projet_date',   'project_id,created_at'
    UNION ALL SELECT 'project_activity_log', 'ak_membre_date',   'user_id,created_at'
    UNION ALL SELECT 'project_messages',     'ak_projet_date',   'project_id,created_at'
    UNION ALL SELECT 'project_messages',     'ak_auteur',        'author_id'
    UNION ALL SELECT 'project_members',      'ak_projet_membre', 'project_id,user_id'
    UNION ALL SELECT 'project_members',      'ak_membre',        'user_id'
    UNION ALL SELECT 'project_steps',        'ak_projet',        'project_id'
    UNION ALL SELECT 'project_files',        'ak_projet',        'project_id'
    UNION ALL SELECT 'project_updates',      'ak_projet',        'project_id'
    UNION ALL SELECT 'project_invoices',     'ak_projet',        'project_id'

    -- Facturation
    UNION ALL SELECT 'asso_invoices',      'ak_org_statut', 'org_id,status,issued_at'
    UNION ALL SELECT 'asso_invoices',      'ak_org_echue',  'org_id,due_at'
    UNION ALL SELECT 'asso_invoices',      'ak_client',     'client_id'
    UNION ALL SELECT 'asso_invoice_lines', 'ak_facture',    'invoice_id'
    UNION ALL SELECT 'asso_clients',       'ak_org',        'org_id'
    UNION ALL SELECT 'asso_quotes',        'ak_org_statut', 'org_id,status'
    UNION ALL SELECT 'asso_quote_lines',   'ak_devis',      'quote_id'

    -- Vie associative
    UNION ALL SELECT 'events',              'ak_org_debut',    'org_id,starts_at'
    UNION ALL SELECT 'event_participants',  'ak_evenement',    'event_id'
    UNION ALL SELECT 'grants',              'ak_org_echeance', 'org_id,deadline_apply'
    UNION ALL SELECT 'cotisation_payments', 'ak_org_statut',   'org_id,status'
    UNION ALL SELECT 'cotisation_payments', 'ak_campagne',     'campaign_id'
    UNION ALL SELECT 'channels',            'ak_org',          'org_id'
    UNION ALL SELECT 'channel_messages',    'ak_canal_date',   'channel_id,created_at'
    UNION ALL SELECT 'channel_members',     'ak_canal_membre', 'channel_id,user_id'
    UNION ALL SELECT 'channel_members',     'ak_membre',       'user_id'
    UNION ALL SELECT 'communication_broadcast_recipients', 'ak_envoi', 'broadcast_id'

    -- Journal d'activité : la table qui grossit le plus vite, une ligne
    -- par action de chaque membre. Les index à une colonne posés à sa
    -- création ne servent pas quand on filtre ET qu'on trie ; il faut
    -- les paires. Le dernier est un index couvrant : les chiffres
    -- d'en-tête de /fondateur-connexions se lisent entièrement dedans,
    -- sans jamais ouvrir la table — mesuré à 5,36 millions de lignes,
    -- 25,5 s sans, 0,3 s avec.
    UNION ALL SELECT 'assokit_activity_log', 'ak_org_date',    'organization_id,created_at'
    UNION ALL SELECT 'assokit_activity_log', 'ak_membre_date', 'user_id,created_at'
    UNION ALL SELECT 'assokit_activity_log', 'ak_type_date',   'event_type,created_at'
    UNION ALL SELECT 'assokit_activity_log', 'ak_email_date',  'user_email,created_at'
    UNION ALL SELECT 'assokit_activity_log', 'ak_entete',
                     'created_at,event_type,user_id,organization_id'
    UNION ALL SELECT 'assokit_active_sessions', 'ak_org_activite',
                     'organization_id,last_activity_at'

    -- Prospection
    UNION ALL SELECT 'asso_prospection', 'ak_org_rappel', 'org_id,deleted_at,callback_at'
    UNION ALL SELECT 'asso_prospection', 'ak_org_import', 'org_id,import_id'
) AS p
WHERE
    -- la table existe chez vous
    EXISTS (SELECT 1 FROM information_schema.tables it
             WHERE it.table_schema = DATABASE() AND it.table_name = p.t)
    -- et toutes les colonnes demandées aussi
    AND (SELECT COUNT(*) FROM information_schema.columns ic
          WHERE ic.table_schema = DATABASE() AND ic.table_name = p.t
            AND FIND_IN_SET(ic.column_name, p.c))
        = LENGTH(p.c) - LENGTH(REPLACE(p.c, ',', '')) + 1
    -- et aucun index ne les couvre déjà
    AND NOT EXISTS (
        SELECT 1 FROM (
            SELECT s.table_name AS tn,
                   GROUP_CONCAT(s.column_name ORDER BY s.seq_in_index) AS sig
              FROM information_schema.statistics s
             WHERE s.table_schema = DATABASE()
             GROUP BY s.table_name, s.index_name
        ) AS x
        WHERE x.tn = p.t AND (x.sig = p.c OR x.sig LIKE CONCAT(p.c, ',%'))
    );


-- ══════════════════════════════════════════════════════════════════
-- REQUÊTE 2 (facultative) — les index devenus inutiles
-- ══════════════════════════════════════════════════════════════════
-- Un index sur (a) ne sert plus à rien dès qu'il en existe un sur
-- (a, b) : MySQL prend le second partout où il aurait pris le premier.
-- Le garder coûte de l'écriture à chaque INSERT, sur les tables qui en
-- font le plus. À lancer APRÈS avoir posé les index de la requête 1.
--
-- Les clés primaires et les index UNIQUE sont exclus : ils portent une
-- contrainte, jamais redondante.
--
-- Comme la requête 1, celle-ci n'exécute rien : elle écrit les ordres,
-- vous décidez. Effacer un index est une décision, pas un effet de bord.

-- Un même index peut être couvert par plusieurs autres ; on ne le
-- propose qu'une fois, sinon le second DROP échouerait sur un index
-- déjà supprimé.
SELECT CONCAT('ALTER TABLE `', a.tn, '` DROP KEY `', a.idx, '`;') AS `-- a relire avant d executer`,
       a.sig      AS `colonnes de l index inutile`,
       MIN(b.idx) AS `deja couvert par`,
       MIN(b.sig) AS `qui porte sur`
FROM (
    SELECT s.table_name AS tn, s.index_name AS idx,
           GROUP_CONCAT(s.column_name ORDER BY s.seq_in_index) AS sig
      FROM information_schema.statistics s
     WHERE s.table_schema = DATABASE() AND s.non_unique = 1
     GROUP BY s.table_name, s.index_name
) AS a
JOIN (
    SELECT s.table_name AS tn, s.index_name AS idx,
           GROUP_CONCAT(s.column_name ORDER BY s.seq_in_index) AS sig
      FROM information_schema.statistics s
     WHERE s.table_schema = DATABASE()
     GROUP BY s.table_name, s.index_name
) AS b
  ON b.tn = a.tn AND b.idx <> a.idx
 AND b.sig LIKE CONCAT(a.sig, ',%')
GROUP BY a.tn, a.idx, a.sig
ORDER BY a.tn, a.idx;
