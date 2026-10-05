<?php
/**
 * demo-formation/purge.php — Efface les données de l'org DEMO F, et rien d'autre.
 * ------------------------------------------------------------------
 * C'est le seul code de la démo qui supprime. Il est écrit pour qu'une
 * erreur ici ne puisse PAS toucher une vraie association :
 *
 *   - l'org visée est relue en base et son slug doit valoir exactement
 *     « demo-formation » ; sinon on s'arrête net ;
 *   - on ne balaie jamais « toutes les tables qui ont une colonne org_id » :
 *     certaines tables pourraient employer ce nom pour autre chose. Chaque
 *     table est nommée ici, avec la colonne qui la rattache à l'org ;
 *   - les tables filles sans org_id sont vidées à partir des ids de leurs
 *     parents, ids relevés AVANT toute suppression ;
 *   - la ligne organizations elle-même n'est jamais supprimée (son id
 *     reste stable), sauf demande explicite de désinstallation.
 *
 * Les tables absentes de la base sont simplement sautées.
 * ------------------------------------------------------------------
 */

/**
 * Ensembles d'ids à relever avant de supprimer quoi que ce soit.
 * nom => [table, requête]. :org est remplacé par l'id de l'org.
 * Une requête dont une table manque renvoie un ensemble vide.
 */
function df_purge_ensembles(): array
{
    return [
        'users'        => ['users',                   "SELECT id FROM users WHERE org_id = :org"],
        'folders'      => ['folders',                 "SELECT id FROM folders WHERE org_id = :org"],
        'projects'     => ['projects',                "SELECT p.id FROM projects p JOIN folders f ON f.id = p.folder_id WHERE f.org_id = :org"],
        'invoices'     => ['asso_invoices',           "SELECT id FROM asso_invoices WHERE org_id = :org"],
        'quotes'       => ['asso_quotes',             "SELECT id FROM asso_quotes WHERE org_id = :org"],
        'recurrences'  => ['asso_invoice_recurrences',"SELECT id FROM asso_invoice_recurrences WHERE org_id = :org"],
        'tags'         => ['asso_tags',               "SELECT id FROM asso_tags WHERE org_id = :org"],
        'grants'       => ['grants',                  "SELECT id FROM grants WHERE org_id = :org"],
        'events'       => ['events',                  "SELECT id FROM events WHERE org_id = :org"],
        'comm_campaigns' => ['communication_campaigns', "SELECT id FROM communication_campaigns WHERE org_id = :org"],
        'broadcasts'   => ['communication_broadcasts',"SELECT id FROM communication_broadcasts WHERE org_id = :org"],
        'comm_events'  => ['communication_events',    "SELECT id FROM communication_events WHERE org_id = :org"],
        'cotis_campaigns' => ['cotisation_campaigns', "SELECT id FROM cotisation_campaigns WHERE org_id = :org"],
        'channels'     => ['channels',                "SELECT id FROM channels WHERE org_id = :org"],
        'assemblies'   => ['assemblies',              "SELECT id FROM assemblies WHERE org_id = :org"],
        'resolutions'  => ['assembly_resolutions',    "SELECT id FROM assembly_resolutions WHERE assembly_id IN (SELECT id FROM assemblies WHERE org_id = :org)"],
        'attendance'   => ['attendance_sessions',     "SELECT id FROM attendance_sessions WHERE org_id = :org"],
        'tickets'      => ['support_tickets',         "SELECT id FROM support_tickets WHERE org_id = :org"],
        'ai_convs'     => ['ai_conversations',        "SELECT id FROM ai_conversations WHERE project_id IN (SELECT p.id FROM projects p JOIN folders f ON f.id = p.folder_id WHERE f.org_id = :org)"],
        'ai_diffusions'=> ['asso_ai_diffusions',      "SELECT id FROM asso_ai_diffusions WHERE org_id = :org"],
        'expense_reports' => ['expense_reports',      "SELECT id FROM expense_reports WHERE org_id = :org"],
        'prospection'  => ['asso_prospection',        "SELECT id FROM asso_prospection WHERE org_id = :org"],
        'qr_codes'     => ['asso_qr_codes',           "SELECT id FROM asso_qr_codes WHERE org_id = :org"],
        'clients'      => ['asso_clients',            "SELECT id FROM asso_clients WHERE org_id = :org"],
    ];
}

/**
 * Suppressions, dans l'ordre : enfants d'abord.
 * [table, colonne, ensemble] — ensemble 'org' = l'id de l'org lui-même.
 */
function df_purge_plan(): array
{
    return [
        // Projets et leurs dépendances
        ['ai_messages',                 'conversation_id', 'ai_convs'],
        ['ai_conversations',            'project_id',      'projects'],
        ['ai_generated_docs',           'project_id',      'projects'],
        ['project_grants',              'project_id',      'projects'],
        ['project_invoices',            'project_id',      'projects'],
        ['project_documents',           'project_id',      'projects'],
        ['project_files',               'project_id',      'projects'],
        ['project_followers',           'project_id',      'projects'],
        ['project_members',             'project_id',      'projects'],
        ['project_messages',            'project_id',      'projects'],
        ['project_share_tokens',        'project_id',      'projects'],
        ['project_steps',               'project_id',      'projects'],
        ['project_updates',             'project_id',      'projects'],
        ['project_activity_log',        'project_id',      'projects'],
        ['user_project_reads',          'project_id',      'projects'],
        ['user_pinned_folders',         'folder_id',       'folders'],
        ['projects',                    'id',              'projects'],
        ['folders',                     'org_id',          'org'],
        ['archive_logs',                'org_id',          'org'],

        // Facturation
        ['asso_invoice_lines',          'invoice_id',      'invoices'],
        ['asso_invoice_payments',       'invoice_id',      'invoices'],
        ['asso_invoice_notifications',  'invoice_id',      'invoices'],
        ['asso_invoice_emails_log',     'invoice_id',      'invoices'],
        ['asso_invoice_recurrence_runs','recurrence_id',   'recurrences'],
        ['asso_invoice_recurrences',    'org_id',          'org'],
        ['asso_quote_lines',            'quote_id',        'quotes'],
        ['asso_quotes',                 'org_id',          'org'],
        ['asso_invoices',               'org_id',          'org'],
        ['asso_clients',                'org_id',          'org'],
        ['asso_tag_links',              'tag_id',          'tags'],
        ['asso_tags',                   'org_id',          'org'],
        ['org_invoice_settings',        'org_id',          'org'],
        ['invoice_sequences',           'org_id',          'org'],

        // Subventions
        ['grant_steps',                 'grant_id',        'grants'],
        ['grant_documents',             'grant_id',        'grants'],
        ['grant_activity_log',          'grant_id',        'grants'],
        ['grant_reminders_sent',        'grant_id',        'grants'],
        ['grants',                      'org_id',          'org'],
        ['grant_matches',               'org_id',          'org'],
        ['org_grant_profile',           'org_id',          'org'],
        ['grant_alert_prefs',           'org_id',          'org'],
        ['grant_alert_sent',            'org_id',          'org'],
        // Dispositifs du radar propres à DEMO F (jamais le catalogue national : org_id IS NULL).
        ['grant_catalog',               'org_id',          'org'],

        // Agenda, équipe
        ['event_participants',          'event_id',        'events'],
        ['event_rsvp',                  'event_id',        'events'],
        ['events',                      'org_id',          'org'],
        ['assokit_schedules',           'org_id',          'org'],
        ['assokit_absences',            'org_id',          'org'],

        // Assemblées, émargement
        ['assembly_votes',              'resolution_id',   'resolutions'],
        ['assembly_resolutions',        'assembly_id',     'assemblies'],
        ['assembly_attendees',          'assembly_id',     'assemblies'],
        ['assemblies',                  'org_id',          'org'],
        ['attendance_records',          'session_id',      'attendance'],
        ['attendance_sessions',         'org_id',          'org'],

        // Messagerie
        ['channel_ai_reports',          'channel_id',      'channels'],
        ['channel_reads',               'channel_id',      'channels'],
        ['channel_messages',            'channel_id',      'channels'],
        ['channel_members',             'channel_id',      'channels'],
        ['channels',                    'org_id',          'org'],

        // Communication
        ['communication_broadcast_recipients', 'broadcast_id', 'broadcasts'],
        ['communication_broadcasts',    'org_id',          'org'],
        ['communication_logs',          'campaign_id',     'comm_campaigns'],
        ['communication_campaigns',     'org_id',          'org'],
        ['communication_event_rsvps',   'event_id',        'comm_events'],
        ['communication_events',        'org_id',          'org'],
        ['communication_saved_templates','org_id',         'org'],
        ['asso_ai_diffusion_recipients','diffusion_id',    'ai_diffusions'],
        ['asso_ai_diffusions',          'org_id',          'org'],
        ['asso_ai_generations',         'org_id',          'org'],
        ['asso_ai_settings',            'org_id',          'org'],
        ['asso_ai_role_quotas',         'org_id',          'org'],
        ['asso_email_diffusions',       'org_id',          'org'],

        // Cotisations
        ['cotisation_payments',         'org_id',          'org'],
        ['cotisation_tiers',            'campaign_id',     'cotis_campaigns'],
        ['cotisation_campaigns',        'org_id',          'org'],
        ['asso_membership_reminders',   'org_id',          'org'],
        ['org_payment_settings',        'org_id',          'org'],

        // Notes de frais
        ['expense_report_lines',        'report_id',       'expense_reports'],
        ['expense_reports',             'org_id',          'org'],

        // Prospection et codes QR
        ['asso_prospection_rappels',    'prospect_id',     'prospection'],
        ['asso_prospection_events',     'prospect_id',     'prospection'],
        ['asso_prospection',            'org_id',          'org'],
        ['asso_prospection_imports',    'org_id',          'org'],
        ['asso_qr_codes',               'org_id',          'org'],

        // Support
        ['support_ticket_events',       'ticket_id',       'tickets'],
        ['support_messages',            'ticket_id',       'tickets'],
        ['support_tickets',             'org_id',          'org'],

        // Pilotage, IA, préférences
        ['coach_reports',               'org_id',          'org'],
        ['anomaly_dismissed',           'org_id',          'org'],
        ['org_relance_prefs',           'org_id',          'org'],
        ['org_forecast_prefs',          'org_id',          'org'],
        ['today_suggestions',           'org_id',          'org'],
        ['copilote_audit_log',          'org_id',          'org'],
        ['org_feature_usage',           'org_id',          'org'],
        ['org_espace_tokens',           'org_id',          'org'],
        ['public_signups',              'org_id',          'org'],

        // Abonnement Assokit de l'org
        ['asso_subscription_addons',    'org_id',          'org'],
        ['asso_subscriptions',          'org_id',          'org'],
        ['subscriptions',               'org_id',          'org'],

        // Ce qui est rattaché aux personnes
        ['user_notifications',          'user_id',         'users'],
        ['user_calendar_tokens',        'user_id',         'users'],
        ['asso_push_tokens',            'user_id',         'users'],
        ['user_password_tokens',        'user_id',         'users'],
        // Les comptes de connexion ne sont pas supprimés (voir df_purger).
        ['users',                       'id',              'figurants'],
    ];
}

/**
 * Efface toutes les données de l'org de démo. Renvoie [table => lignes supprimées].
 */
function df_purger(int $org): array
{
    $pdo = DF::$pdo;
    if ($org <= 0) throw new RuntimeException('Purge : id d\'organisation invalide.');

    // Contre-vérification : on relit le slug en base.
    $s = $pdo->prepare("SELECT slug FROM organizations WHERE id = ?");
    $s->execute([$org]);
    $slug = $s->fetchColumn();
    if ($slug !== DF_SLUG) {
        throw new RuntimeException("Purge refusée : l'organisation #$org n'est pas « " . DF_SLUG . " » (slug lu : " . var_export($slug, true) . ').');
    }

    // 1. Relever les ids des parents, tant qu'ils existent encore.
    $ens = ['org' => [$org]];
    foreach (df_purge_ensembles() as $nom => [$table, $sql]) {
        $ens[$nom] = [];
        if (!df_a_table($table)) continue;
        try {
            $q = $pdo->prepare(str_replace(':org', '?', $sql));
            $q->execute(array_fill(0, substr_count($sql, ':org'), $org));
            $ens[$nom] = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            // Une colonne absente sur cette installation : ensemble vide, on continue.
            DF::$rapport['purge_notes'][] = "ensemble $nom : " . substr($e->getMessage(), 0, 160);
        }
    }

    // Les comptes de connexion gardent leur id d'une nuit à l'autre : on ne
    // supprime que les autres personnes. Un compte dont l'e-mail a été modifié
    // pendant la journée n'est plus reconnu et part avec les figurants.
    $comptes = [];
    foreach (df_comptes() as [, , , , , $local]) $comptes[] = strtolower($local . '@' . DF_DOMAINE);
    $ens['figurants'] = [];
    if ($ens['users']) {
        $q = $pdo->prepare("SELECT id, email FROM users WHERE org_id = ?");
        $q->execute([$org]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $u) {
            if (!in_array(strtolower((string)$u['email']), $comptes, true)) $ens['figurants'][] = (int)$u['id'];
        }
    }

    // Les fichiers déposés sur les projets de démo (y compris ceux ajoutés
    // pendant la journée) : uniquement sous uploads/projet_<id d'un projet démo>/.
    if ($ens['projects']) {
        $in = implode(',', array_map('intval', $ens['projects']));
        foreach ([['project_files', 'filepath'], ['project_invoices', 'file_path'], ['project_invoices', 'filepath']] as [$t, $c]) {
            if (!df_a_colonne($t, $c)) continue;
            try {
                foreach ($pdo->query("SELECT `$c` FROM `$t` WHERE project_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $chemin) {
                    df_effacer_fichier($chemin, $ens['projects']);
                }
            } catch (Throwable $e) {}
        }
    }

    // PDF de factures et devis générés pendant la journée : seulement ceux que
    // référencent les pièces de DEMO F, et dont le nom porte son préfixe.
    foreach ([['asso_invoices', 'uploads/asso-invoices/', '/^' . preg_quote(DF_SLUG, '/') . '-\d{4}-\d{6}[-_][A-Za-z0-9_-]*\.(pdf|html)$/'],
              ['asso_quotes', 'uploads/asso-quotes/', '/^DEVIS-' . preg_quote(DF_SLUG, '/') . '-\d{4}-\d{6}[-_][A-Za-z0-9_-]*\.(pdf|html)$/']] as [$t, $dossier, $motif]) {
        if (!df_a_colonne($t, 'pdf_path')) continue;
        try {
            $q = $pdo->prepare("SELECT pdf_path FROM `$t` WHERE org_id = ? AND pdf_path IS NOT NULL");
            $q->execute([$org]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $chemin) {
                $chemin = ltrim((string)$chemin, '/');
                if (strpos($chemin, $dossier) !== 0 || strpos($chemin, '..') !== false || !preg_match($motif, basename($chemin))) continue;
                $abs = dirname(__DIR__) . '/' . $chemin;
                if (is_file($abs)) @unlink($abs);
            }
        } catch (Throwable $e) {}
    }

    // 2. Supprimer, enfants d'abord, par paquets de 500 ids.
    $bilan = [];
    foreach (df_purge_plan() as [$table, $col, $nom]) {
        if (!df_a_colonne($table, $col)) continue;
        $ids = $ens[$nom] ?? [];
        if (!$ids) continue;
        $n = 0;
        foreach (array_chunk($ids, 500) as $paquet) {
            $sql = "DELETE FROM `$table` WHERE `$col` IN (" . implode(',', array_fill(0, count($paquet), '?')) . ')';
            try {
                $q = $pdo->prepare($sql);
                $q->execute($paquet);
                $n += $q->rowCount();
            } catch (Throwable $e) {
                df_erreur("purge $table : " . substr($e->getMessage(), 0, 160));
            }
        }
        if ($n) $bilan[$table] = ($bilan[$table] ?? 0) + $n;
    }
    return $bilan;
}

/**
 * Tables NON couvertes par la purge qui contiennent encore des lignes de l'org.
 * Rien n'y est supprimé : c'est un signal pour compléter df_purge_plan().
 */
function df_purge_restes(int $org): array
{
    $pdo = DF::$pdo;
    $couvertes = [];
    foreach (df_purge_plan() as [$t]) $couvertes[$t] = true;
    $couvertes['organizations'] = true;
    $restes = [];
    $q = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'org_id'"
    );
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (isset($couvertes[$t])) continue;
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE org_id = ?");
            $s->execute([$org]);
            $n = (int)$s->fetchColumn();
            if ($n > 0) $restes[$t] = $n;
        } catch (Throwable $e) {}
    }
    return $restes;
}
