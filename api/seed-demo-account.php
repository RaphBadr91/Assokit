<?php
/**
 * api/seed-demo-account.php — Crée (ou réinitialise) le COMPTE DE DÉMO pour la
 * validation Apple App Store / Google Play.
 *
 * But : donner aux relecteurs Apple/Google un identifiant qui fonctionne, sur
 * une association fictive déjà remplie de données réalistes, pour qu'ils voient
 * l'app tourner sans avoir à créer de compte.
 *
 * SÉCURITÉ : ne s'exécute qu'en ligne de commande (CLI) OU avec la clé secrète.
 *   - En SSH sur O2switch :   php api/seed-demo-account.php
 *   - Via navigateur (secours) : /api/seed-demo-account.php?key=LA_CLE_CI_DESSOUS
 *
 * Ré-exécutable : supprime l'ancienne démo et la recrée proprement à chaque fois.
 * NE TOUCHE À AUCUN compte réel : cible uniquement l'email de démo ci-dessous.
 */

require_once __DIR__ . '/../config.php';

// ---- Identifiants du compte de démo (à copier dans App Store Connect) --------
const DEMO_EMAIL    = 'demo-review@assokit.fr';
const DEMO_PASSWORD = 'AssokitDemo2026!';
const DEMO_ORG_NAME = 'Association Démo (Apple Review)';
const DEMO_FIRST    = 'Compte';
const DEMO_LAST     = 'Démo';
const SEED_SECRET   = 'assokit-seed-8f3a91d6';   // clé pour l'accès navigateur de secours

// ---- Garde d'accès -----------------------------------------------------------
$is_cli = (PHP_SAPI === 'cli');
if (!$is_cli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (($_GET['key'] ?? '') !== SEED_SECRET) {
        http_response_code(403);
        exit("403 — clé manquante ou invalide.\n");
    }
}

function say($m) { echo $m . "\n"; }
function col_exists(PDO $pdo, $table, $col) {
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $q->execute([$table, $col]);
        return (int) $q->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
function table_exists(PDO $pdo, $table) {
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $q->execute([$table]);
        return (int) $q->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

say('=== Seed compte de démo Assokit (Apple / Google review) ===');

try {
    // 1) Nettoyage de toute démo précédente (par email, sans toucher aux vrais comptes)
    $st = $pdo->prepare("SELECT id, org_id FROM users WHERE email = ? LIMIT 1");
    $st->execute([DEMO_EMAIL]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $old_org = (int) $row['org_id'];
        say("• Ancienne démo trouvée (org #$old_org) → suppression…");
        // On supprime l'utilisateur + l'organisation ; les données liées suivent
        // (soit via ON DELETE CASCADE, soit best-effort ci-dessous).
        foreach (['asso_subscriptions','asso_clients','projects','folders','events',
                  'asso_invoices','asso_quotes'] as $t) {
            if (table_exists($pdo, $t) && col_exists($pdo, $t, 'org_id')) {
                try { $pdo->prepare("DELETE FROM $t WHERE org_id = ?")->execute([$old_org]); } catch (Throwable $e) {}
            }
        }
        try { $pdo->prepare("DELETE FROM users WHERE org_id = ?")->execute([$old_org]); } catch (Throwable $e) {}
        try { $pdo->prepare("DELETE FROM organizations WHERE id = ?")->execute([$old_org]); } catch (Throwable $e) {}
    }

    // 2) Organisation de démo
    $slug = 'demo-review-' . substr(md5(DEMO_EMAIL), 0, 6);
    $pdo->prepare("INSERT INTO organizations (name, slug, billing_email, created_at) VALUES (?, ?, ?, NOW())")
        ->execute([DEMO_ORG_NAME, $slug, DEMO_EMAIL]);
    $org_id = (int) $pdo->lastInsertId();
    say("• Organisation créée : #$org_id");

    // 3) Utilisateur admin (le login de démo)
    $hash = password_hash(DEMO_PASSWORD, PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO users (org_id, email, password_hash, first_name, last_name, role, is_active, created_at)
                   VALUES (?, ?, ?, ?, ?, 'admin', 1, NOW())")
        ->execute([$org_id, DEMO_EMAIL, $hash, DEMO_FIRST, DEMO_LAST]);
    $uid = (int) $pdo->lastInsertId();
    // email_verified_at si la colonne existe (évite un mur de vérification)
    if (col_exists($pdo, 'users', 'email_verified_at')) {
        try { $pdo->prepare("UPDATE users SET email_verified_at = NOW() WHERE id = ?")->execute([$uid]); } catch (Throwable $e) {}
    }
    say("• Utilisateur admin créé : #$uid");

    // 4) Abonnement actif (prend le 1er plan disponible)
    try {
        $plan = $pdo->query("SELECT id, slug FROM asso_plans WHERE is_visible = 1 OR is_trial = 1 ORDER BY is_trial ASC, price_cents ASC LIMIT 1")
                    ->fetch(PDO::FETCH_ASSOC);
        if ($plan) {
            $period_end = date('Y-m-d H:i:s', strtotime('+1 year'));
            $pdo->prepare("INSERT INTO asso_subscriptions (org_id, plan_id, payment_mode, status, current_period_end, created_at, updated_at)
                           VALUES (?, ?, 'free_grant', 'active', ?, NOW(), NOW())")
                ->execute([$org_id, (int) $plan['id'], $period_end]);
            try { $pdo->prepare("UPDATE organizations SET plan = ?, status = 'active' WHERE id = ?")->execute([$plan['slug'], $org_id]); } catch (Throwable $e) {}
            say("• Abonnement actif (plan #{$plan['id']}) jusqu'au $period_end");
        }
    } catch (Throwable $e) { say("  (abonnement ignoré : " . $e->getMessage() . ")"); }

    // 5) Données de démo (best-effort — chaque bloc isolé)
    // 5a) Clients
    if (table_exists($pdo, 'asso_clients')) {
        $clients = [
            ['company', 'Mairie de Palaiseau', 'Commune de Palaiseau', 'Sophie', 'Martin', 'contact@palaiseau-demo.fr', '01 60 00 00 01'],
            ['company', 'Boulangerie Le Fournil', 'SARL Le Fournil', 'Karim', 'Benali', 'contact@lefournil-demo.fr', '01 60 00 00 02'],
            ['individual', 'Jeanne Dubois', 'Jeanne Dubois', 'Jeanne', 'Dubois', 'jeanne.dubois-demo@example.fr', '06 12 00 00 03'],
        ];
        $n = 0;
        foreach ($clients as $c) {
            try {
                $pdo->prepare("INSERT INTO asso_clients (org_id, client_type, display_name, legal_name, contact_first_name, contact_last_name, email, phone, address_country, created_by_user_id)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'France', ?)")
                    ->execute([$org_id, $c[0], $c[1], $c[2], $c[3], $c[4], $c[5], $c[6], $uid]);
                $n++;
            } catch (Throwable $e) {}
        }
        say("• Clients de démo : $n");
    }

    // 5b) Dossier + projets
    $folder_id = 0;
    if (table_exists($pdo, 'folders')) {
        try {
            $pdo->prepare("INSERT INTO folders (org_id, name, color_theme, created_by, created_at) VALUES (?, 'Projets 2026', 'blue', ?, NOW())")
                ->execute([$org_id, $uid]);
            $folder_id = (int) $pdo->lastInsertId();
        } catch (Throwable $e) {}
    }
    if (table_exists($pdo, 'projects') && $folder_id > 0) {
        $projects = [
            ['Forum des associations', 'Stand + animations sur la journée', 'Palaiseau', 60],
            ['Rénovation du local', 'Travaux de peinture et mobilier', 'Local associatif', 25],
        ];
        $n = 0;
        foreach ($projects as $p) {
            try {
                $cols = "folder_id, name, description, location, referent_id, created_at";
                $vals = "?, ?, ?, ?, ?, NOW()";
                $args = [$folder_id, $p[0], $p[1], $p[2], $uid];
                if (col_exists($pdo, 'projects', 'progress_percent')) { $cols .= ", progress_percent"; $vals .= ", ?"; $args[] = $p[3]; }
                if (col_exists($pdo, 'projects', 'status'))           { $cols .= ", status"; $vals .= ", 'active'"; }
                $pdo->prepare("INSERT INTO projects ($cols) VALUES ($vals)")->execute($args);
                $n++;
            } catch (Throwable $e) {}
        }
        say("• Projets de démo : $n");
    }

    // 5c) Événements agenda
    if (table_exists($pdo, 'events')) {
        $events = [
            ['Assemblée générale', 'AG annuelle de l\'association', 'Salle des fêtes', '+7 days', '+7 days +2 hours'],
            ['Réunion du bureau', 'Point mensuel', 'Local associatif', '+14 days', '+14 days +1 hour'],
        ];
        $n = 0;
        foreach ($events as $ev) {
            try {
                $starts = date('Y-m-d H:i:s', strtotime($ev[3]));
                $ends   = date('Y-m-d H:i:s', strtotime($ev[4]));
                $pdo->prepare("INSERT INTO events (org_id, created_by, title, description, location, event_type, color_theme, starts_at, ends_at, is_all_day, visibility)
                               VALUES (?, ?, ?, ?, ?, 'other', 'emerald', ?, ?, 0, 'organization')")
                    ->execute([$org_id, $uid, $ev[0], $ev[1], $ev[2], $starts, $ends]);
                $n++;
            } catch (Throwable $e) {}
        }
        say("• Événements de démo : $n");
    }

    say('');
    say('===================================================');
    say('  COMPTE DE DÉMO PRÊT ✅');
    say('  Email    : ' . DEMO_EMAIL);
    say('  Mot de passe : ' . DEMO_PASSWORD);
    say('  Organisation : ' . DEMO_ORG_NAME . ' (#' . $org_id . ')');
    say('===================================================');
    say('  À coller dans App Store Connect → App Review Information');
    say('  et dans Google Play Console → App access.');
} catch (Throwable $e) {
    http_response_code(500);
    say('ERREUR : ' . $e->getMessage());
    exit(1);
}
