<?php
/**
 * api/seed-demo-account.php — Crée (ou réinitialise) le COMPTE DE DÉMO pour la
 * validation Apple App Store / Google Play.
 *
 * Association : « Focus Point Asso » (Paris) — culturelle & sportive, 120 adhérents,
 * projets, événements, clients + logo généré. Objectif : donner aux relecteurs
 * Apple/Google un identifiant qui fonctionne sur une asso déjà remplie de données
 * réalistes, pour qu'ils voient l'app vivante sans créer de compte.
 *
 * SÉCURITÉ : ne s'exécute qu'en CLI OU avec la clé secrète.
 *   - SSH O2switch :        php api/seed-demo-account.php
 *   - Navigateur (secours) : /api/seed-demo-account.php?key=LA_CLE_CI_DESSOUS
 *
 * Ré-exécutable : supprime l'ancienne démo (par email) et la recrée proprement.
 * NE TOUCHE À AUCUN compte réel : cible uniquement l'email de démo ci-dessous.
 */

require_once __DIR__ . '/../config.php';

// ---- Identifiants du compte de démo (à copier dans les stores) ---------------
const DEMO_EMAIL    = 'demo-review@assokit.fr';
const DEMO_PASSWORD = 'AssokitDemo2026!';
const DEMO_ORG_NAME = 'Focus Point Asso';
const DEMO_CITY     = 'Paris';
const DEMO_FIRST    = 'Camille';
const DEMO_LAST     = 'Laurent';           // président·e / admin de la démo
const DEMO_MEMBERS  = 120;

// ---- Garde d'accès : CLI, OU fondateur / super-admin connecté ----------------
// (l'ancien secret en dur dans le code versionné a été retiré — accès web réservé
//  aux comptes privilégiés authentifiés, comme seed-grants-catalog.php.)
$is_cli = (PHP_SAPI === 'cli');
if (!$is_cli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!function_exists('require_login')) { http_response_code(403); exit("403\n"); }
    require_login();
    $u = current_user();
    $priv = !empty($u['is_super_admin']) || !empty($u['is_founder']);
    if (!$priv) {
        try {
            $st = $pdo->prepare("SELECT is_super_admin, is_founder FROM users WHERE id = ?");
            $st->execute([(int)($u['id'] ?? 0)]);
            if ($row = $st->fetch()) $priv = ((int)($row['is_super_admin'] ?? 0) === 1) || ((int)($row['is_founder'] ?? 0) === 1);
        } catch (Throwable $e) {}
    }
    if (!$priv) { http_response_code(403); exit("403 — réservé au fondateur.\n"); }
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

/** Génère un logo PNG « FP » (monogramme Focus Point) via GD. Renvoie le logo_path ou null. */
function make_demo_logo(): ?string {
    if (!function_exists('imagecreatetruecolor')) return null;
    $dir = __DIR__ . '/../uploads/logos';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_dir($dir) || !is_writable($dir)) return null;

    $size = 512;
    $img = imagecreatetruecolor($size, $size);
    imageantialias($img, true);
    // Fond dégradé vert de marque (approx : deux bandes + cercle)
    $bg1 = imagecolorallocate($img, 5, 150, 105);    // #059669
    $bg2 = imagecolorallocate($img, 4, 120, 87);     // #047857
    imagefilledrectangle($img, 0, 0, $size, $size, $bg1);
    imagefilledrectangle($img, 0, (int)($size*0.55), $size, $size, $bg2);
    $white = imagecolorallocate($img, 255, 255, 255);
    // Cercle central blanc
    imagefilledellipse($img, (int)($size/2), (int)($size/2), (int)($size*0.62), (int)($size*0.62), $white);
    // Point vert au centre (clin d'œil « Focus Point »)
    imagefilledellipse($img, (int)($size/2), (int)($size/2), (int)($size*0.20), (int)($size*0.20), $bg1);

    $file = 'focus-point-demo.png';
    $abs  = $dir . '/' . $file;
    $ok = imagepng($img, $abs);
    imagedestroy($img);
    return $ok ? ('/uploads/logos/' . $file) : null;
}

say('=== Seed compte de démo Assokit — Focus Point Asso ===');

try {
    // 1) Nettoyage de toute démo précédente (par email, sans toucher aux vrais comptes)
    $st = $pdo->prepare("SELECT id, org_id FROM users WHERE email = ? LIMIT 1");
    $st->execute([DEMO_EMAIL]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $old_org = (int) $row['org_id'];
        say("• Ancienne démo trouvée (org #$old_org) → suppression…");
        foreach (['asso_subscriptions','asso_clients','projects','folders','events',
                  'asso_invoices','asso_quotes','channels','channel_messages','event_participants'] as $t) {
            if (table_exists($pdo, $t) && col_exists($pdo, $t, 'org_id')) {
                try { $pdo->prepare("DELETE FROM $t WHERE org_id = ?")->execute([$old_org]); } catch (Throwable $e) {}
            }
        }
        try { $pdo->prepare("DELETE FROM users WHERE org_id = ?")->execute([$old_org]); } catch (Throwable $e) {}
        try { $pdo->prepare("DELETE FROM organizations WHERE id = ?")->execute([$old_org]); } catch (Throwable $e) {}
    }

    // 2) Organisation de démo
    $slug = 'focus-point-' . substr(md5(DEMO_EMAIL), 0, 6);
    $pdo->prepare("INSERT INTO organizations (name, slug, billing_email, created_at) VALUES (?, ?, ?, NOW())")
        ->execute([DEMO_ORG_NAME, $slug, DEMO_EMAIL]);
    $org_id = (int) $pdo->lastInsertId();
    say("• Organisation créée : #$org_id");

    // 2b) Champs enrichis (best-effort, seulement si les colonnes existent)
    $set = []; $args = [];
    $maybe = function($col, $val) use ($pdo, &$set, &$args) {
        if (col_exists($pdo, 'organizations', $col)) { $set[] = "`$col` = ?"; $args[] = $val; }
    };
    $maybe('legal_form', 'Association loi 1901');
    $maybe('billing_address_city', DEMO_CITY);
    $maybe('billing_address_country', 'France');
    $maybe('president_first_name', DEMO_FIRST);
    $maybe('president_last_name', DEMO_LAST);
    $maybe('president_role', 'Président·e');
    $maybe('branding_primary_color', '#059669');
    $maybe('branding_secondary_color', '#0F172A');
    $maybe('internal_notes', 'Association culturelle et sportive · compte de démonstration (revue stores).');
    $logo_path = make_demo_logo();
    if ($logo_path && col_exists($pdo, 'organizations', 'logo_path')) {
        $set[] = "`logo_path` = ?"; $args[] = $logo_path;
        if (col_exists($pdo, 'organizations', 'logo_uploaded_at')) { $set[] = "`logo_uploaded_at` = NOW()"; }
    }
    if ($set) {
        $args[] = $org_id;
        try { $pdo->prepare("UPDATE organizations SET " . implode(', ', $set) . " WHERE id = ?")->execute($args); } catch (Throwable $e) {}
    }
    say('• Profil asso enrichi' . ($logo_path ? " + logo ($logo_path)" : ' (logo non généré)'));

    // 3) Utilisateur admin (le login de démo)
    $hash = password_hash(DEMO_PASSWORD, PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO users (org_id, email, password_hash, first_name, last_name, city, role, is_active, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, 'admin', 1, NOW())")
        ->execute([$org_id, DEMO_EMAIL, $hash, DEMO_FIRST, DEMO_LAST, DEMO_CITY]);
    $uid = (int) $pdo->lastInsertId();
    foreach (['can_create_projects','can_create_folders','can_manage_members','can_manage_finances','can_access_marketing','can_manage_events','can_moderate_messages'] as $perm) {
        if (col_exists($pdo, 'users', $perm)) { try { $pdo->prepare("UPDATE users SET `$perm` = 1 WHERE id = ?")->execute([$uid]); } catch (Throwable $e) {} }
    }
    if (col_exists($pdo, 'users', 'email_verified_at')) {
        try { $pdo->prepare("UPDATE users SET email_verified_at = NOW() WHERE id = ?")->execute([$uid]); } catch (Throwable $e) {}
    }
    if (col_exists($pdo, 'users', 'onboarding_completed_at')) {
        try { $pdo->prepare("UPDATE users SET onboarding_completed_at = NOW() WHERE id = ?")->execute([$uid]); } catch (Throwable $e) {}
    }
    say("• Utilisateur admin créé : #$uid");

    // 4) Abonnement actif (prend le 1er plan disponible)
    try {
        $plan = $pdo->query("SELECT id, slug FROM asso_plans WHERE is_visible = 1 OR is_trial = 1 ORDER BY is_trial ASC, price_cents ASC LIMIT 1")
                    ->fetch(PDO::FETCH_ASSOC);
        if ($plan) {
            // Compte de démo PERMANENT : échéance très lointaine → ne s'expire jamais.
            $period_end = '2099-12-31 23:59:59';
            $pdo->prepare("INSERT INTO asso_subscriptions (org_id, plan_id, payment_mode, status, current_period_end, created_at, updated_at)
                           VALUES (?, ?, 'free_grant', 'active', ?, NOW(), NOW())")
                ->execute([$org_id, (int) $plan['id'], $period_end]);
            try { $pdo->prepare("UPDATE organizations SET plan = ?, status = 'active' WHERE id = ?")->execute([$plan['slug'], $org_id]); } catch (Throwable $e) {}
            say("• Abonnement actif (plan #{$plan['id']}) jusqu'au $period_end");
        }
    } catch (Throwable $e) { say("  (abonnement ignoré : " . $e->getMessage() . ")"); }

    // 5) 120 ADHÉRENTS réalistes -------------------------------------------------
    $prenoms = ['Camille','Léa','Hugo','Chloé','Nathan','Manon','Lucas','Emma','Théo','Sarah',
                'Enzo','Inès','Louis','Jade','Gabriel','Louise','Adam','Alice','Raphaël','Lina',
                'Arthur','Zoé','Jules','Anna','Paul','Rose','Noah','Julia','Tom','Nina',
                'Yanis','Éva','Sacha','Mila','Ethan','Léna','Mohamed','Ambre','Aaron','Romane',
                'Malo','Juliette','Isaac','Yasmine','Ali','Maya','Nolan','Clara','Ibrahim','Agathe'];
    $noms = ['Martin','Bernard','Dubois','Thomas','Robert','Richard','Petit','Durand','Leroy','Moreau',
             'Simon','Laurent','Lefebvre','Michel','Garcia','David','Bertrand','Roux','Vincent','Fournier',
             'Morel','Girard','André','Lefèvre','Mercier','Dupont','Lambert','Bonnet','François','Martinez',
             'Legrand','Garnier','Faure','Rousseau','Blanc','Guerin','Muller','Henry','Roussel','Nguyen',
             'Gauthier','Perrin','Robin','Clément','Morin','Nicolas','Henry','Roy','Benali','Diallo'];
    $arr = ['Paris 3e','Paris 10e','Paris 11e','Paris 12e','Paris 18e','Paris 19e','Paris 20e','Montreuil','Pantin','Vincennes'];
    $colors = ['blue','purple','amber','pink','teal'];

    $ins = $pdo->prepare("INSERT INTO users
        (org_id, role, email, password_hash, first_name, last_name, phone, city, avatar_color,
         adhesion_date, adhesion_valid_until, must_change_password, is_active, created_at)
        VALUES (?, 'member', ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
    $nb = 0;
    for ($i = 1; $i <= DEMO_MEMBERS; $i++) {
        $fn = $prenoms[($i * 7) % count($prenoms)];
        $ln = $noms[($i * 13) % count($noms)];
        // email unique et non-réel
        $email = strtolower(preg_replace('/[^a-z]/i', '', $fn . '.' . $ln)) . '.' . $i . '@focuspoint-demo.fr';
        $phone = '06 ' . str_pad((string)(10 + ($i * 3) % 90), 2, '0', STR_PAD_LEFT) . ' '
               . str_pad((string)(($i * 17) % 100), 2, '0', STR_PAD_LEFT) . ' '
               . str_pad((string)(($i * 29) % 100), 2, '0', STR_PAD_LEFT) . ' '
               . str_pad((string)(($i * 41) % 100), 2, '0', STR_PAD_LEFT);
        $city  = $arr[$i % count($arr)];
        $color = $colors[$i % count($colors)];
        $adh   = date('Y-m-d', strtotime('-' . (($i * 11) % 34) . ' months'));    // adhésions étalées ~3 ans
        // ~85% à jour de cotisation (échéance future), ~15% expirés
        $valid = ($i % 7 === 0)
            ? date('Y-m-d', strtotime('-' . (1 + $i % 5) . ' months'))
            : date('Y-m-d', strtotime('+' . (1 + $i % 11) . ' months'));
        $active = ($i % 13 === 0) ? 0 : 1;   // quelques inactifs pour le réalisme
        $ph = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        try {
            $ins->execute([$org_id, $email, $ph, $fn, $ln, $phone, $city, $color, $adh, $valid, ($i % 4 === 0 ? 1 : 0), $active]);
            $nb++;
        } catch (Throwable $e) { /* email dupliqué improbable → on continue */ }
    }
    say("• Adhérents créés : $nb / " . DEMO_MEMBERS);

    // 6) Clients (thème culturel & sportif)
    if (table_exists($pdo, 'asso_clients')) {
        $clients = [
            ['company', 'Mairie de Paris', 'Ville de Paris', 'Service', 'Culture', 'culture@paris-demo.fr', '01 42 00 00 01'],
            ['company', 'Décathlon Bercy', 'Décathlon France', 'Yann', 'Leroy', 'pro@decathlon-demo.fr', '01 42 00 00 02'],
            ['company', 'Studio Harmonie', 'Studio Harmonie SARL', 'Nadia', 'Cherif', 'contact@studioharmonie-demo.fr', '01 42 00 00 03'],
            ['individual', 'Marc Petit', 'Marc Petit', 'Marc', 'Petit', 'marc.petit-demo@example.fr', '06 12 00 00 04'],
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

    // 7) Dossier + projets (culturel & sportif)
    $folder_id = 0;
    if (table_exists($pdo, 'folders')) {
        try {
            $pdo->prepare("INSERT INTO folders (org_id, name, color_theme, created_by, created_at) VALUES (?, 'Saison 2026', 'emerald', ?, NOW())")
                ->execute([$org_id, $uid]);
            $folder_id = (int) $pdo->lastInsertId();
        } catch (Throwable $e) {}
    }
    if (table_exists($pdo, 'projects') && $folder_id > 0) {
        $projects = [
            ['Festival culturel de quartier', 'Concerts, expos et ateliers sur 2 jours', 'Paris 11e', 70],
            ['Tournoi inter-clubs de futsal', 'Compétition amicale ouverte aux adhérents', 'Gymnase Voltaire', 40],
            ['Stage de danse contemporaine', 'Stage encadré pour tous niveaux', 'Studio Harmonie', 20],
        ];
        $n = 0;
        foreach ($projects as $p) {
            try {
                $cols = "folder_id, name, description, location, referent_id, created_at";
                $vals = "?, ?, ?, ?, ?, NOW()";
                $args2 = [$folder_id, $p[0], $p[1], $p[2], $uid];
                if (col_exists($pdo, 'projects', 'progress_percent')) { $cols .= ", progress_percent"; $vals .= ", ?"; $args2[] = $p[3]; }
                if (col_exists($pdo, 'projects', 'status'))           { $cols .= ", status"; $vals .= ", 'active'"; }
                $pdo->prepare("INSERT INTO projects ($cols) VALUES ($vals)")->execute($args2);
                $n++;
            } catch (Throwable $e) {}
        }
        say("• Projets de démo : $n");
    }

    // 8) Événements agenda
    if (table_exists($pdo, 'events')) {
        $events = [
            ['Assemblée générale annuelle', 'Bilan de la saison et élection du bureau', 'Salle Olympe de Gouges, Paris 11e', '+9 days', '+9 days +2 hours'],
            ['Concert de la chorale', 'Concert de fin de saison ouvert au public', 'Église Saint-Ambroise', '+16 days', '+16 days +2 hours'],
            ['Tournoi de futsal', 'Journée sportive inter-clubs', 'Gymnase Voltaire', '+23 days', '+23 days +6 hours'],
            ['Réunion du bureau', 'Point mensuel de l\'équipe dirigeante', 'Local associatif', '+3 days', '+3 days +1 hour'],
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
    say('  Email        : ' . DEMO_EMAIL);
    say('  Mot de passe : ' . DEMO_PASSWORD);
    say('  Organisation : ' . DEMO_ORG_NAME . ' — ' . DEMO_CITY . ' (#' . $org_id . ')');
    say('  Adhérents    : ' . $nb);
    say('===================================================');
    say('  À coller dans App Store Connect → App Review Information');
    say('  et dans Google Play Console → App access (identifiants de test).');
} catch (Throwable $e) {
    http_response_code(500);
    say('ERREUR : ' . $e->getMessage());
    exit(1);
}
