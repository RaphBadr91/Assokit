<?php
/**
 * ============================================================
 * ASSOKIT — Import CSV des adhérents (v3 - token magic link)
 * ============================================================
 * Workflow :
 *   1. Upload du fichier CSV
 *   2. Prévisualisation (lignes à importer, doublons détectés)
 *   3. Confirmation → import réel + token + email à chacun
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/password-token-helper.php';
@require_once __DIR__ . '/plan-helpers.php';
require_once __DIR__ . '/trial-limits-helpers.php';
require_once __DIR__ . '/xlsx-helper.php';

require_login();

$user = current_user();
$org_id = (int)$user['org_id'];
if ($org_id <= 0) { header('Location: /dashboard'); exit; }  // compte sans association (agent de collectivité) : pas d'« org 0 » partagée

// Admin, coordinateur, ou droit « Gérer les adhérents » donné par l'admin (jamais un suiveur).
// Rôles qu'il ne peut pas attribuer : admin pour un coordinateur ; admin et coordinateur pour
// un titulaire du seul droit (repli sur « Membre », comme /nouveau-adherent).
$role_me = (string)($user['role'] ?? '');
if (!in_array($role_me, ['admin', 'coordinator'], true)
    && ($role_me === 'follower' || !function_exists('can') || !can('manage_members'))) {
    http_response_code(403);
    die('Accès refusé — rôle insuffisant.');
}
$roles_reserved = $role_me === 'admin' ? [] : ($role_me === 'coordinator' ? ['admin'] : ['admin', 'coordinator']);

// Lignes de données au plus par fichier (au-delà, l'aperçu et la session deviennent ingérables).
const AK_IMPORT_ADH_MAX_ROWS = 5000;
// Au-delà de ce nombre d'adhérents créés, les e-mails de bienvenue partent après la réponse
// (un envoi par adhérent : un gros import ne doit pas faire attendre ni expirer la page).
const AK_IMPORT_ADH_SYNC_EMAILS = 20;

/** En-tête ou valeur ramenée à des lettres sans accent : « E-mail » → « email », « PRÉNOM » → « prenom ». */
function ak_import_norm(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    // Table fixe plutôt qu'iconv//TRANSLIT, dont le résultat dépend de la locale du serveur.
    $s = strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','ä'=>'a','î'=>'i','ï'=>'i',
                    'ô'=>'o','ö'=>'o','û'=>'u','ù'=>'u','ü'=>'u','ç'=>'c','œ'=>'oe','æ'=>'ae','ÿ'=>'y']);
    return (string)preg_replace('/[^a-z0-9]/', '', $s);
}

/**
 * Adresses du fichier déjà présentes en base, quelle que soit l'association et la casse.
 * @return array e-mail en minuscules => 'exists' (compte actif) | 'trash' (supprimé dans cette
 *               asso) | 'foreign_trash' (supprimé dans une autre asso : l'adresse peut être reprise,
 *               comme avec « Ajouter un adhérent »)
 */
function ak_import_existing_emails(PDO $pdo, array $emails, int $org_id): array {
    $found = [];
    $rank = ['foreign_trash' => 1, 'trash' => 2, 'exists' => 3];
    $emails = array_values(array_unique(array_filter($emails)));
    foreach (array_chunk($emails, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("SELECT LOWER(email) AS e, org_id, deleted_at FROM users WHERE LOWER(email) IN ($ph)");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $kind = $r['deleted_at'] === null ? 'exists' : ((int)$r['org_id'] === $org_id ? 'trash' : 'foreign_trash');
            // Un compte actif l'emporte sur une ligne supprimée, la corbeille de cette asso sur celle d'une autre.
            if (!isset($found[$r['e']]) || $rank[$kind] > $rank[$found[$r['e']]]) $found[$r['e']] = $kind;
        }
    }
    return $found;
}

/** Places restantes sur le plan : null = illimité. */
function ak_import_plan_room(PDO $pdo, int $org_id): ?int {
    if (!function_exists('ak_can_add_adherent')) return null;
    $chk = ak_can_add_adherent($pdo, $org_id);
    if ($chk['limit'] === null) return null;
    return max(0, (int)$chk['limit'] - (int)$chk['current']);
}

function ak_import_plan_message(int $room, int $wanted): string {
    return $room > 0
        ? "Votre plan permet encore $room adhérent" . ($room > 1 ? 's' : '') . " ; ce fichier en ajouterait $wanted. Réduisez le fichier ou passez au plan supérieur."
        : "Vous avez atteint la limite d'adhérents de votre plan. Passez au plan supérieur pour en importer d'autres.";
}

$step = 'upload';
$error = null;
$parsed_rows = [];

// Etape PREVIEW : upload du fichier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload' && check_csrf($_POST['csrf_token'] ?? '')) {
    if (!isset($_FILES['csvfile']) || $_FILES['csvfile']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Erreur lors du téléversement du fichier.';
    } else {
        $tmp = $_FILES['csvfile']['tmp_name'];
        $size = filesize($tmp);
        $lines = null;
        if ($size > 2 * 1024 * 1024) {
            $error = 'Fichier trop volumineux (max 2 Mo).';
        } else {
            // Lecteur partagé : BOM, Windows-1252 (CSV d'Excel), séparateur, champs sur plusieurs lignes, .xlsx.
            try {
                $lines = ak_tableur_lire($tmp, (string)($_FILES['csvfile']['name'] ?? ''), AK_IMPORT_ADH_MAX_ROWS + 100);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            } catch (Throwable $e) {
                error_log('Import adh: lecture ' . $e->getMessage());
                $error = 'Fichier illisible. Enregistrez-le au format CSV (séparateur point-virgule) ou Excel (.xlsx).';
            }
        }

        if ($lines !== null) {
            // Les lignes vides (avant l'en-tête, au milieu, à la fin) sont ignorées, sans décaler les suivantes.
            $lines = array_values(array_filter($lines, fn($r) => trim(implode('', $r)) !== ''));

            if (count($lines) < 2) {
                $error = 'Le fichier est vide ou ne contient qu\'un en-tête.';
            } elseif (count($lines) - 1 > AK_IMPORT_ADH_MAX_ROWS) {
                $error = 'Fichier trop long : ' . number_format(AK_IMPORT_ADH_MAX_ROWS, 0, ',', ' ') . ' adhérents au plus par import. Découpez-le en plusieurs fichiers.';
            } else {
                $headers = array_map(fn($h) => ak_import_norm((string)$h), $lines[0]);
                $synonyms = [
                    'first_name' => ['prenom', 'prenoms', 'firstname'],
                    'last_name'  => ['nom', 'nomdefamille', 'lastname', 'surname'],
                    'email'      => ['email', 'mail', 'courriel', 'adresseemail', 'adressemail', 'adressecourriel', 'emailaddress'],
                    'phone'      => ['telephone', 'tel', 'portable', 'mobile', 'telephoneportable', 'telportable', 'phone'],
                    'city'       => ['ville', 'commune', 'localite', 'city'],
                    'role'       => ['role'],
                ];
                $col_idx = [];
                foreach ($synonyms as $key => $names) {
                    $col_idx[$key] = false;
                    foreach ($names as $n) {
                        $pos = array_search($n, $headers, true);
                        if ($pos !== false) { $col_idx[$key] = $pos; break; }
                    }
                }

                if ($col_idx['first_name'] === false || $col_idx['last_name'] === false || $col_idx['email'] === false) {
                    $found = array_filter(array_map(fn($h) => trim((string)$h), $lines[0]), fn($h) => $h !== '');
                    $error = 'Colonnes obligatoires manquantes : le fichier doit contenir « Prénom », « Nom » et « Email »'
                           . ($found ? ' (colonnes trouvées : ' . mb_substr(implode(', ', $found), 0, 200) . ')' : '') . '.';
                } else {
                    $cell = function (array $fields, $idx): string {
                        if ($idx === false) return '';
                        // Un champ saisi sur plusieurs lignes (adresse…) est remis sur une seule.
                        return trim((string)preg_replace('/\s+/u', ' ', (string)($fields[$idx] ?? '')));
                    };
                    $role_map = [
                        'administrateur' => 'admin', 'administratrice' => 'admin', 'admin' => 'admin',
                        'coordinateur'   => 'coordinator', 'coordinatrice' => 'coordinator', 'coordinator' => 'coordinator',
                        'referent'       => 'referent', 'referente' => 'referent',
                        'membre'         => 'member', 'member' => 'member', 'adherent' => 'member', 'adherente' => 'member',
                        'suiveur'        => 'follower', 'suiveuse' => 'follower', 'follower' => 'follower',
                    ];

                    foreach (array_slice($lines, 1) as $fields) {
                        $row = [
                            'first_name' => $cell($fields, $col_idx['first_name']),
                            'last_name'  => $cell($fields, $col_idx['last_name']),
                            'email'      => mb_strtolower($cell($fields, $col_idx['email']), 'UTF-8'),
                            // Mêmes longueurs que le formulaire d'ajout (nouveau-adherent.php)
                            'phone'      => mb_substr($cell($fields, $col_idx['phone']), 0, 30),
                            'city'       => mb_substr($cell($fields, $col_idx['city']), 0, 100),
                            'role'       => $role_map[ak_import_norm($cell($fields, $col_idx['role']))] ?? 'member',
                        ];
                        // Seul un admin peut créer des admins (comme nouveau-adherent.php)
                        if (in_array($row['role'], $roles_reserved, true)) $row['role'] = 'member';
                        $parsed_rows[] = $row;
                    }

                    $existing = [];
                    try {
                        $existing = ak_import_existing_emails($pdo, array_column($parsed_rows, 'email'), $org_id);
                    } catch (Throwable $e) {
                        error_log('Import adh: doublons ' . $e->getMessage());
                    }
                    $seen = [];
                    foreach ($parsed_rows as &$row) {
                        if ($row['first_name'] === '' || $row['last_name'] === '') {
                            $row['status'] = 'error';
                            $row['reason'] = 'Prénom ou nom manquant';
                        } elseif (mb_strlen($row['first_name']) > 100 || mb_strlen($row['last_name']) > 100) {
                            $row['status'] = 'error';
                            $row['reason'] = 'Prénom ou nom trop long (100 caractères au plus)';
                        } elseif (mb_strlen($row['email']) > 200 || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                            $row['status'] = 'error';
                            $row['reason'] = 'Email invalide';
                        } elseif (($existing[$row['email']] ?? '') === 'trash') {
                            $row['status'] = 'skip';
                            $row['reason'] = 'Adhérent supprimé : restaurez-le depuis la corbeille ou réinscrivez-le avec « Ajouter un adhérent »';
                        } elseif (($existing[$row['email']] ?? 'foreign_trash') !== 'foreign_trash') {
                            $row['status'] = 'skip';
                            $row['reason'] = 'Email déjà existant';
                        } elseif (isset($seen[$row['email']])) {
                            $row['status'] = 'skip';
                            $row['reason'] = 'Doublon dans le fichier';
                        } else {
                            $row['status'] = 'ok';
                            $row['reason'] = '';
                            $seen[$row['email']] = true;
                        }
                    }
                    unset($row);

                    $nb_ok_rows = count(array_filter($parsed_rows, fn($r) => $r['status'] === 'ok'));
                    // Essai gratuit : 200 adhérents par import, 300 ajoutés par 24 h
                    // (chaque adhérent créé peut recevoir un e-mail de bienvenue).
                    $trial_block = $parsed_rows ? ak_trial_members_block($pdo, $org_id, $nb_ok_rows, true) : null;
                    // Limite d'adhérents du plan : même règle que l'ajout un par un.
                    $room = $nb_ok_rows > 0 ? ak_import_plan_room($pdo, $org_id) : null;

                    if (empty($parsed_rows)) {
                        $error = 'Aucune ligne de données trouvée dans le fichier.';
                    } elseif ($trial_block) {
                        $error = $trial_block;
                        $parsed_rows = [];
                    } elseif ($room !== null && $nb_ok_rows > $room) {
                        $error = ak_import_plan_message($room, $nb_ok_rows);
                        $parsed_rows = [];
                    } else {
                        $_SESSION['import_adherents_rows'] = $parsed_rows;
                        $_SESSION['import_adherents_send_email'] = isset($_POST['send_email']) ? 1 : 0;
                        $step = 'preview';
                    }
                }
            }
        }
    }
}

// Etape IMPORT : confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_import' && check_csrf($_POST['csrf_token'] ?? '')) {
    $rows = $_SESSION['import_adherents_rows'] ?? [];
    $send_email = !empty($_SESSION['import_adherents_send_email']);
    // Les lignes quittent la session AVANT l'import (la session est écrite juste avant la boucle) :
    // un double clic, un rechargement ou un import interrompu ne recrée pas les mêmes comptes.
    unset($_SESSION['import_adherents_rows'], $_SESSION['import_adherents_send_email']);

    if (!empty($rows)) {
        // Recontrôle en base (import parallèle, compte créé entre-temps…), avec la règle de l'aperçu.
        try {
            $existing = ak_import_existing_emails($pdo, array_map(fn($r) => mb_strtolower((string)($r['email'] ?? ''), 'UTF-8'), $rows), $org_id);
        } catch (Throwable $e) {
            $existing = [];
            error_log('Import adh: doublons ' . $e->getMessage());
        }
        foreach ($rows as &$row) {
            $row['email'] = mb_strtolower((string)($row['email'] ?? ''), 'UTF-8');
            $known = $existing[$row['email']] ?? 'foreign_trash';
            if (($row['status'] ?? '') === 'ok' && $known !== 'foreign_trash') {
                $row['status'] = 'skip';
                $row['reason'] = $known === 'trash'
                    ? 'Adhérent supprimé : restaurez-le depuis la corbeille ou réinscrivez-le avec « Ajouter un adhérent »'
                    : 'Email déjà existant';
            }
        }
        unset($row);
    }
    $nb_ok_rows = count(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'ok'));

    // Essai gratuit : on recontrôle au moment d'importer (autre import entre-temps, autre onglet…).
    $trial_block = $rows ? ak_trial_members_block($pdo, $org_id, $nb_ok_rows, true) : null;
    $room = $nb_ok_rows > 0 ? ak_import_plan_room($pdo, $org_id) : null;

    if (empty($rows)) {
        $error = 'Session expirée, merci de recommencer.';
    } elseif ($trial_block) {
        $error = $trial_block;
    } elseif ($room !== null && $nb_ok_rows > $room) {
        $error = ak_import_plan_message($room, $nb_ok_rows);
    } else {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        @set_time_limit(300);
        ignore_user_abort(true);

        $created = 0;
        $skipped = 0;
        $errors = 0;
        $emails_sent = 0;
        $emails_failed = 0;
        $failed = [];      // lignes non créées à cause d'une erreur, montrées à l'utilisateur
        $welcome = [];     // adhérents créés à qui envoyer le lien de création de mot de passe

        $org = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
        $org->execute([$org_id]);
        $org_row = $org->fetch();
        $org_name = $org_row['name'] ?? 'votre association';

        // Un seul hachage pour tout l'import : le secret est jeté aussitôt (personne ne peut
        // s'en servir) et bcrypt coûte ~0,2 s par appel, ce qui faisait expirer les gros imports.
        $placeholder_hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);

        foreach ($rows as $row) {
            if ($row['status'] === 'error') { $errors++; continue; }
            if ($row['status'] === 'skip') { $skipped++; continue; }

            try {
                $colors = ['blue','purple','amber','pink','teal'];
                $avatar_color = $colors[array_rand($colors)];

                if (in_array($row['role'], $roles_reserved, true)) $row['role'] = 'member';
                $perms = match($row['role']) {
                    'admin'       => [1, 1, 1, 1, 1, 1],
                    'coordinator' => [1, 1, 0, 1, 1, 1],
                    'referent'    => [1, 0, 0, 0, 1, 0],
                    default       => [0, 0, 0, 0, 0, 0],
                };

                // Adresse d'un compte supprimé dans une autre association : libérée (renommée, comme
                // dans nouveau-adherent.php) pour ce nouvel adhérent, dans la même transaction.
                $free_email = ($existing[$row['email']] ?? '') === 'foreign_trash';
                if ($free_email) {
                    $pdo->beginTransaction();
                    $pdo->prepare("UPDATE users SET email = CONCAT('deleted_', id, '__', email)
                                   WHERE LOWER(email) = ? AND deleted_at IS NOT NULL AND org_id <> ?")
                        ->execute([$row['email'], $org_id]);
                }
                $stmt = $pdo->prepare("
                    INSERT INTO users
                        (org_id, role, email, password_hash, first_name, last_name,
                         phone, city, avatar_color, adhesion_date,
                         must_change_password, is_active,
                         can_create_projects, can_manage_members, can_manage_finances,
                         can_access_marketing, can_manage_events, can_moderate_messages,
                         created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 1, 1, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $org_id, $row['role'], $row['email'], $placeholder_hash,
                    $row['first_name'], $row['last_name'],
                    $row['phone'] ?: null, $row['city'] ?: null, $avatar_color,
                    ...$perms,
                ]);
                $new_id = (int) $pdo->lastInsertId();
                if ($free_email) $pdo->commit();
                $created++;
                $welcome[] = ['id' => $new_id, 'email' => $row['email'], 'first_name' => $row['first_name']];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors++;
                $failed[] = $row['email'];
                error_log('Import adh: ' . $e->getMessage());
            }
        }

        // Envoie le lien de création de mot de passe à chaque adhérent créé.
        $send_welcome = function (array $list) use ($pdo, $org_name, $org_id): array {
            $sent = 0; $ko = 0;
            if (!function_exists('create_password_token') || !function_exists('send_welcome_email')) return [0, count($list)];
            foreach ($list as $w) {
                try {
                    $token = create_password_token($pdo, (int)$w['id'], 'set_password');
                    if (send_welcome_email($w['email'], $w['first_name'], $org_name, $token)) $sent++;
                    else $ko++;
                } catch (Throwable $e) {
                    $ko++;
                }
            }
            if ($ko > 0) error_log("Import adh: org $org_id, $ko e-mail(s) de bienvenue non envoyé(s) sur " . count($list));
            return [$sent, $ko];
        };
        $send_later = $send_email && count($welcome) > AK_IMPORT_ADH_SYNC_EMAILS;
        if ($send_email && !$send_later) {
            [$emails_sent, $emails_failed] = $send_welcome($welcome);
        }

        $msg = "Import terminé : $created créé(s), $skipped ignoré(s), $errors erreur(s).";
        if ($failed) {
            $msg .= ' Non importé(s) : ' . implode(', ', array_slice($failed, 0, 10))
                  . (count($failed) > 10 ? ' et ' . (count($failed) - 10) . ' autre(s)' : '') . '.';
        }
        if ($send_later) {
            $msg .= ' 📧 ' . count($welcome) . ' e-mail(s) de bienvenue en cours d’envoi.';
        } elseif ($send_email && $welcome) {
            $msg .= " 📧 $emails_sent email(s) envoyé(s)" . ($emails_failed > 0 ? ", $emails_failed échec(s)" : "") . ".";
        }

        @session_start();
        $_SESSION['flash_adherents'] = [
            'type' => $failed ? 'error' : 'success',
            'message' => $msg,
        ];
        session_write_close();
        header('Location: /adherents');

        if ($send_later) {
            // La réponse part tout de suite ; les e-mails sont envoyés ensuite (comme à l'inscription).
            if (function_exists('litespeed_finish_request')) litespeed_finish_request();
            elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            $send_welcome($welcome);
        }
        exit;
    }
}

render_head('Importer des adhérents');
render_sidebar('adherents');
?>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/adherents">Adhérents</a>
    <span class="sep">›</span>
    <span class="current">Importer CSV</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title">📥 Importer des adhérents</h1>
      <div class="page-sub">
        <?= $step === 'preview'
            ? 'Prévisualisation — confirmez pour valider l\'import'
            : 'Uploadez un fichier CSV ou Excel pour importer plusieurs adhérents en une fois.' ?>
      </div>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <span>⚠️</span>
      <div><?= h($error) ?></div>
    </div>
  <?php endif; ?>

  <?php if ($step === 'upload'): ?>

    <div style="background:var(--bg-2); border:1px solid var(--border); border-radius:12px; padding:18px; margin-bottom:16px; font-size:13.5px; line-height:1.6;">
      <h3 style="margin:0 0 8px; font-size:14px; font-weight:500;">📋 Format du fichier (CSV ou Excel .xlsx)</h3>
      <p style="margin:0 0 8px;">Le fichier doit contenir une ligne d'en-tête avec les colonnes suivantes :</p>
      <ul style="margin:0 0 10px; padding-left:20px;">
        <li><strong>Prénom</strong> (obligatoire)</li>
        <li><strong>Nom</strong> (obligatoire)</li>
        <li><strong>Email</strong> (obligatoire)</li>
        <li>Téléphone, Ville, Rôle (optionnels)</li>
      </ul>
      <p style="margin:0 0 8px; font-size:12.5px; color:var(--ink-3);">
        Les CSV enregistrés depuis Excel (séparateur point-virgule) sont acceptés tels quels, accents compris. Les lignes vides sont ignorées.
      </p>
      <p style="margin:0; font-size:12.5px; color:var(--ink-3);">
        💡 Chaque adhérent importé recevra un email avec un lien pour créer son mot de passe (valide 7 jours).
      </p>
      <?php if (ak_trial_limited($pdo, $org_id)): ?>
      <p style="margin:8px 0 0; font-size:12.5px; color:var(--ink-3);">
        ⏳ <?php // ak_trial_msg() : dans l'app, « essai » sans « gratuit » (Apple 3.1.1 / 3.1.3 f) ?><?= h(ak_trial_msg('Pendant l’essai gratuit : ' . (int)AK_TRIAL_MAX_IMPORT_ROWS . ' adhérents au plus par import, ' . (int)AK_TRIAL_MAX_MEMBERS_PER_DAY . ' par 24 h.')) ?>
      </p>
      <?php endif; ?>
    </div>

    <form method="POST" action="/adherents-import" enctype="multipart/form-data"
          style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:24px; max-width:720px;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="upload">

      <div style="margin-bottom:20px;">
        <label for="csvfile" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Fichier CSV ou Excel *</label>
        <input type="file" id="csvfile" name="csvfile" accept=".csv,.txt,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required
               style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
      </div>

      <div style="margin-bottom:24px; padding:14px; background:rgba(5, 150, 105, 0.06); border:1px solid rgba(5, 150, 105, 0.2); border-radius:10px;">
        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
          <input type="checkbox" name="send_email" value="1" checked style="margin-top:2px;">
          <div>
            <div style="font-size:13px; font-weight:500;">📧 Envoyer un email de bienvenue à chaque adhérent</div>
            <div style="font-size:11.5px; color:var(--ink-3); margin-top:3px;">
              🔐 Chaque adhérent recevra un lien unique (valide 7 jours) pour créer son mot de passe.
            </div>
          </div>
        </label>
      </div>

      <div style="display:flex; gap:10px; justify-content:flex-end;">
        <a href="/adherents" class="btn btn-ghost">Annuler</a>
        <button type="submit" class="btn btn-primary">Analyser le fichier →</button>
      </div>
    </form>

  <?php elseif ($step === 'preview'): ?>

    <?php
    $nb_ok = 0; $nb_skip = 0; $nb_err = 0;
    foreach ($parsed_rows as $r) {
        if ($r['status'] === 'ok') $nb_ok++;
        elseif ($r['status'] === 'skip') $nb_skip++;
        else $nb_err++;
    }
    ?>

    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:10px; margin-bottom:16px;">
      <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:14px;">
        <div style="font-size:11px; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.05em;">À créer</div>
        <div style="font-size:24px; font-weight:600; color:var(--acc); margin-top:4px;"><?= $nb_ok ?></div>
      </div>
      <?php if ($nb_skip > 0): ?>
      <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:14px;">
        <div style="font-size:11px; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.05em;">Ignorés</div>
        <div style="font-size:24px; font-weight:600; color:#F59E0B; margin-top:4px;"><?= $nb_skip ?></div>
      </div>
      <?php endif; ?>
      <?php if ($nb_err > 0): ?>
      <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:14px;">
        <div style="font-size:11px; color:var(--ink-3); text-transform:uppercase; letter-spacing:0.05em;">Erreurs</div>
        <div style="font-size:24px; font-weight:600; color:#EF4444; margin-top:4px;"><?= $nb_err ?></div>
      </div>
      <?php endif; ?>
    </div>

    <div style="background:var(--bg); border:1px solid var(--border); border-radius:12px; overflow:hidden; margin-bottom:16px;">
      <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
          <thead>
            <tr style="background:var(--bg-2); border-bottom:1px solid var(--border);">
              <th style="padding:10px 14px; text-align:left; font-weight:500; font-size:11.5px; color:var(--ink-3); text-transform:uppercase;">Statut</th>
              <th style="padding:10px 14px; text-align:left; font-weight:500; font-size:11.5px; color:var(--ink-3); text-transform:uppercase;">Prénom</th>
              <th style="padding:10px 14px; text-align:left; font-weight:500; font-size:11.5px; color:var(--ink-3); text-transform:uppercase;">Nom</th>
              <th style="padding:10px 14px; text-align:left; font-weight:500; font-size:11.5px; color:var(--ink-3); text-transform:uppercase;">Email</th>
              <th style="padding:10px 14px; text-align:left; font-weight:500; font-size:11.5px; color:var(--ink-3); text-transform:uppercase;">Rôle</th>
              <th style="padding:10px 14px; text-align:left; font-weight:500; font-size:11.5px; color:var(--ink-3); text-transform:uppercase;">Motif</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($parsed_rows as $r): ?>
              <tr style="border-bottom:1px solid var(--border); <?= $r['status'] === 'error' ? 'background:rgba(239, 68, 68, 0.04);' : ($r['status'] === 'skip' ? 'background:rgba(245, 158, 11, 0.04);' : '') ?>">
                <td style="padding:10px 14px;">
                  <?php if ($r['status'] === 'ok'): ?>
                    <span style="background:var(--acc-light); color:var(--acc-dark); font-size:10.5px; padding:2px 7px; border-radius:999px; font-weight:500;">✓ À créer</span>
                  <?php elseif ($r['status'] === 'skip'): ?>
                    <span style="background:#FEF3C7; color:#854F0B; font-size:10.5px; padding:2px 7px; border-radius:999px; font-weight:500;">⊘ Ignoré</span>
                  <?php else: ?>
                    <span style="background:rgba(239, 68, 68, 0.1); color:#991B1B; font-size:10.5px; padding:2px 7px; border-radius:999px; font-weight:500;">✕ Erreur</span>
                  <?php endif; ?>
                </td>
                <td style="padding:10px 14px;"><?= h($r['first_name']) ?></td>
                <td style="padding:10px 14px;"><?= h($r['last_name']) ?></td>
                <td style="padding:10px 14px; color:var(--ink-2);"><?= h($r['email']) ?></td>
                <td style="padding:10px 14px; color:var(--ink-3); font-size:12px;"><?= h($r['role']) ?></td>
                <td style="padding:10px 14px; color:var(--ink-3); font-size:12px;"><?= h($r['reason']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <form method="POST" action="/adherents-import" style="display:flex; gap:10px; justify-content:flex-end;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="confirm_import">
      <a href="/adherents-import" class="btn btn-ghost">← Annuler</a>
      <?php if ($nb_ok > 0): ?>
        <button type="submit" class="btn btn-primary">✓ Confirmer l'import de <?= $nb_ok ?> adhérent<?= $nb_ok > 1 ? 's' : '' ?></button>
      <?php else: ?>
        <a href="/adherents-import" class="btn btn-primary">Recommencer</a>
      <?php endif; ?>
    </form>

  <?php endif; ?>

</div>

<?php render_foot(); ?>
