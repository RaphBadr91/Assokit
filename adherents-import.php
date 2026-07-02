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

require_login();

$user = current_user();
$org_id = (int)$user['org_id'];

if (!in_array($user['role'], ['admin', 'coordinator'], true)) {
    http_response_code(403);
    die('Accès refusé — rôle insuffisant.');
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
        if ($size > 2 * 1024 * 1024) {
            $error = 'Fichier trop volumineux (max 2 Mo).';
        } else {
            $content = file_get_contents($tmp);
            $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
            $lines = preg_split('/\r\n|\r|\n/', $content);
            $lines = array_filter($lines, fn($l) => trim($l) !== '');

            if (count($lines) < 2) {
                $error = 'Le fichier est vide ou ne contient qu\'un en-tête.';
            } else {
                $first_line = $lines[0];
                $sep = (substr_count($first_line, ';') >= substr_count($first_line, ',')) ? ';' : ',';

                $headers_raw = str_getcsv($first_line, $sep);
                $headers = array_map(function($h) {
                    $h = trim(strtolower($h));
                    $h = strtr($h, ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','ï'=>'i','î'=>'i','ô'=>'o','û'=>'u','ù'=>'u']);
                    return $h;
                }, $headers_raw);

                $col_idx = [
                    'first_name' => array_search('prenom', $headers),
                    'last_name'  => array_search('nom', $headers),
                    'email'      => array_search('email', $headers),
                    'phone'      => array_search('telephone', $headers),
                    'city'       => array_search('ville', $headers),
                    'role'       => array_search('role', $headers),
                ];

                if ($col_idx['first_name'] === false || $col_idx['last_name'] === false || $col_idx['email'] === false) {
                    $error = 'Colonnes obligatoires manquantes : le fichier doit contenir "Prénom", "Nom" et "Email".';
                } else {
                    $existing_emails = [];
                    try {
                        $stmt = $pdo->query("SELECT email FROM users WHERE deleted_at IS NULL");
                        $existing_emails = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
                    } catch (Throwable $e) {}

                    for ($i = 1; $i < count($lines); $i++) {
                        $line = trim($lines[$i]);
                        if ($line === '') continue;
                        $fields = str_getcsv($line, $sep);

                        $row = [
                            'first_name' => trim($fields[$col_idx['first_name']] ?? ''),
                            'last_name'  => trim($fields[$col_idx['last_name']] ?? ''),
                            'email'      => trim($fields[$col_idx['email']] ?? ''),
                            'phone'      => $col_idx['phone'] !== false ? trim($fields[$col_idx['phone']] ?? '') : '',
                            'city'       => $col_idx['city'] !== false ? trim($fields[$col_idx['city']] ?? '') : '',
                            'role'       => $col_idx['role'] !== false ? trim($fields[$col_idx['role']] ?? '') : 'member',
                        ];

                        $role_lower = strtolower($row['role']);
                        $role_map = [
                            'administrateur' => 'admin', 'admin' => 'admin',
                            'coordinateur'   => 'coordinator', 'coordinator' => 'coordinator',
                            'referent'       => 'referent', 'référent' => 'referent',
                            'membre'         => 'member', 'member' => 'member',
                            'suiveur'        => 'follower', 'follower' => 'follower',
                        ];
                        $row['role'] = $role_map[$role_lower] ?? 'member';

                        if ($row['first_name'] === '' || $row['last_name'] === '') {
                            $row['status'] = 'error';
                            $row['reason'] = 'Prénom ou nom manquant';
                        } elseif (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                            $row['status'] = 'error';
                            $row['reason'] = 'Email invalide';
                        } elseif (isset($existing_emails[$row['email']])) {
                            $row['status'] = 'skip';
                            $row['reason'] = 'Email déjà existant';
                        } else {
                            $row['status'] = 'ok';
                            $row['reason'] = '';
                            $existing_emails[$row['email']] = true;
                        }

                        $parsed_rows[] = $row;
                    }

                    if (empty($parsed_rows)) {
                        $error = 'Aucune ligne de données trouvée dans le fichier.';
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

    if (empty($rows)) {
        $error = 'Session expirée, merci de recommencer.';
    } else {
        $created = 0;
        $skipped = 0;
        $errors = 0;
        $emails_sent = 0;
        $emails_failed = 0;

        $org = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
        $org->execute([$org_id]);
        $org_row = $org->fetch();
        $org_name = $org_row['name'] ?? 'votre association';

        foreach ($rows as $row) {
            if ($row['status'] === 'error') { $errors++; continue; }
            if ($row['status'] === 'skip') { $skipped++; continue; }

            try {
                $placeholder_hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
                $colors = ['blue','purple','amber','pink','teal'];
                $avatar_color = $colors[array_rand($colors)];

                $perms = match($row['role']) {
                    'admin'       => [1, 1, 1, 1, 1, 1],
                    'coordinator' => [1, 1, 0, 1, 1, 1],
                    'referent'    => [1, 0, 0, 0, 1, 0],
                    default       => [0, 0, 0, 0, 0, 0],
                };

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
                $created++;

                if ($send_email && function_exists('create_password_token') && function_exists('send_welcome_email')) {
                    try {
                        $token = create_password_token($pdo, $new_id, 'set_password');
                        if (send_welcome_email($row['email'], $row['first_name'], $org_name, $token)) {
                            $emails_sent++;
                        } else {
                            $emails_failed++;
                        }
                    } catch (Throwable $e) {
                        $emails_failed++;
                    }
                }
            } catch (Throwable $e) {
                $errors++;
                error_log('Import adh: ' . $e->getMessage());
            }
        }

        unset($_SESSION['import_adherents_rows'], $_SESSION['import_adherents_send_email']);

        $msg = "Import terminé : $created créé(s), $skipped ignoré(s), $errors erreur(s).";
        if ($send_email) {
            $msg .= " 📧 $emails_sent email(s) envoyé(s)" . ($emails_failed > 0 ? ", $emails_failed échec(s)" : "") . ".";
        }

        $_SESSION['flash_adherents'] = [
            'type' => 'success',
            'message' => $msg,
        ];
        header('Location: /adherents');
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
            : 'Uploadez un fichier CSV pour importer plusieurs adhérents en une fois.' ?>
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
      <h3 style="margin:0 0 8px; font-size:14px; font-weight:500;">📋 Format du fichier CSV</h3>
      <p style="margin:0 0 8px;">Le fichier doit contenir une ligne d'en-tête avec les colonnes suivantes :</p>
      <ul style="margin:0 0 10px; padding-left:20px;">
        <li><strong>Prénom</strong> (obligatoire)</li>
        <li><strong>Nom</strong> (obligatoire)</li>
        <li><strong>Email</strong> (obligatoire)</li>
        <li>Téléphone, Ville, Rôle (optionnels)</li>
      </ul>
      <p style="margin:0; font-size:12.5px; color:var(--ink-3);">
        💡 Chaque adhérent importé recevra un email avec un lien pour créer son mot de passe (valide 7 jours).
      </p>
    </div>

    <form method="POST" action="/adherents-import" enctype="multipart/form-data"
          style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:24px; max-width:720px;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="upload">

      <div style="margin-bottom:20px;">
        <label for="csvfile" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Fichier CSV *</label>
        <input type="file" id="csvfile" name="csvfile" accept=".csv,text/csv" required
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
