<?php
/**
 * communication-diffuser-nouveau.php — Nouvelle diffusion email
 * ===============================================================
 * Formulaire en 2 etapes :
 *   1. Composer : sujet + corps + destinataires
 *   2. Envoi immediat via Resend
 *
 * Destinataires possibles :
 *   - Tous les adherents de l'org
 *   - Par role (admin, coordinator, referent, member)
 *   - Selection custom (liste avec checkboxes)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
@require_once __DIR__ . '/resend-helper.php';
require_login();
require_capability('access_marketing');

$user = current_user();
$org_id = (int) $user['org_id'];

// Charger nom de l'org pour les emails
$stmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
$stmt->execute([$org_id]);
$org = $stmt->fetch();
$org_name = $org['name'] ?? 'votre association';

// Charger users pour la selection custom
$stmt = $pdo->prepare("
    SELECT id, first_name, last_name, email, role
    FROM users
    WHERE org_id = ? AND is_active = 1 AND email IS NOT NULL
    ORDER BY first_name ASC, last_name ASC
");
$stmt->execute([$org_id]);
$all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$error = null;
$form = [
    'subject'        => '',
    'body'           => '',
    'recipient_type' => 'all',
    'recipient_roles' => [],
    'recipient_user_ids' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf($_POST['csrf_token'] ?? '')) {
    $form['subject']         = trim($_POST['subject'] ?? '');
    $form['body']            = trim($_POST['body'] ?? '');
    $form['recipient_type']  = $_POST['recipient_type'] ?? 'all';
    $form['recipient_roles'] = $_POST['recipient_roles'] ?? [];
    $form['recipient_user_ids'] = $_POST['recipient_user_ids'] ?? [];

    // Validation
    if ($form['subject'] === '' || strlen($form['subject']) < 5) {
        $error = 'Le sujet doit faire au moins 5 caractères.';
    } elseif (strlen($form['subject']) > 200) {
        $error = 'Le sujet est trop long (max 200 caractères).';
    } elseif ($form['body'] === '' || strlen($form['body']) < 10) {
        $error = 'Le message doit faire au moins 10 caractères.';
    } else {

        // Determiner les destinataires
        $recipient_ids = [];
        if ($form['recipient_type'] === 'all') {
            $recipient_ids = array_column($all_users, 'id');
        } elseif ($form['recipient_type'] === 'by_role') {
            if (empty($form['recipient_roles'])) {
                $error = 'Sélectionne au moins un rôle.';
            } else {
                foreach ($all_users as $u) {
                    if (in_array($u['role'], $form['recipient_roles'], true)) {
                        $recipient_ids[] = $u['id'];
                    }
                }
            }
        } elseif ($form['recipient_type'] === 'custom') {
            if (empty($form['recipient_user_ids'])) {
                $error = 'Sélectionne au moins un destinataire.';
            } else {
                $selected = array_map('intval', $form['recipient_user_ids']);
                foreach ($all_users as $u) {
                    if (in_array((int)$u['id'], $selected, true)) {
                        $recipient_ids[] = $u['id'];
                    }
                }
            }
        }

        if (!$error && empty($recipient_ids)) {
            $error = 'Aucun destinataire trouvé pour ces critères.';
        }

        if (!$error) {
            // Créer le broadcast
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO communication_broadcasts
                        (org_id, created_by_user_id, subject, body, recipient_type,
                         recipient_roles, recipient_user_ids, status, nb_total, sent_at, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'sending', ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $org_id, (int) $user['id'], $form['subject'], $form['body'],
                    $form['recipient_type'],
                    json_encode($form['recipient_roles']),
                    json_encode($form['recipient_user_ids']),
                    count($recipient_ids),
                ]);
                $broadcast_id = (int) $pdo->lastInsertId();

                // Envoi mails
                $nb_sent = 0;
                $nb_failed = 0;

                foreach ($recipient_ids as $uid) {
                    $u = null;
                    foreach ($all_users as $row) {
                        if ((int)$row['id'] === (int)$uid) { $u = $row; break; }
                    }
                    if (!$u) continue;

                    // Construction du mail HTML
                    $body_html = nl2br(htmlspecialchars($form['body']));
                    $content = '<h1 style="font-size:20px;margin:0 0 10px;font-weight:500;letter-spacing:-0.01em;color:#1C1917">' . h($form['subject']) . '</h1>'
                             . '<p>Bonjour ' . h($u['first_name']) . ',</p>'
                             . '<div style="font-size:14px; line-height:1.6; color:#3F3F46; margin:14px 0;">' . $body_html . '</div>'
                             . '<p style="font-size:12px; color:#78716C; margin-top:18px;">— L\'équipe de ' . h($org_name) . '</p>';

                    if (function_exists('email_wrap')) {
                        $html = email_wrap($form['subject'], $content, null, null);
                    } else {
                        $html = $content;
                    }

                    $status = 'failed';
                    $error_msg = null;
                    if (function_exists('send_transactional_email')) {
                        try {
                            $result = send_transactional_email($u['email'], $form['subject'], $html, ['tag' => 'broadcast']);
                            if (!empty($result['success'])) {
                                $status = 'sent';
                                $nb_sent++;
                            } else {
                                $nb_failed++;
                                $error_msg = $result['error'] ?? 'Erreur inconnue';
                            }
                        } catch (Throwable $e) {
                            $nb_failed++;
                            $error_msg = $e->getMessage();
                        }
                    } else {
                        $error_msg = 'Resend non configuré';
                        $nb_failed++;
                    }

                    // Log individuel
                    try {
                        $stmt2 = $pdo->prepare("
                            INSERT INTO communication_broadcast_recipients
                                (broadcast_id, user_id, email, status, error_message, sent_at)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt2->execute([
                            $broadcast_id, (int)$u['id'], $u['email'],
                            $status, $error_msg ? mb_substr($error_msg, 0, 500) : null,
                            $status === 'sent' ? date('Y-m-d H:i:s') : null,
                        ]);
                    } catch (Throwable $e) {}
                }

                // Maj broadcast
                $stmt = $pdo->prepare("
                    UPDATE communication_broadcasts
                    SET nb_sent = ?, nb_failed = ?, status = 'sent'
                    WHERE id = ?
                ");
                $stmt->execute([$nb_sent, $nb_failed, $broadcast_id]);

                $pdo->commit();

                $_SESSION['flash_communication'] = [
                    'type' => 'success',
                    'message' => "Diffusion envoyée : $nb_sent email(s) envoyé(s)" . ($nb_failed > 0 ? ", $nb_failed échec(s)" : "") . ".",
                ];
                header('Location: /communication?tab=diffuser');
                exit;

            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'Erreur technique : ' . $e->getMessage();
            }
        }
    }
}

render_head('Nouvelle diffusion');
render_sidebar('communication');
?>

<div class="main">

  <nav class="crumbs">
    <a href="/dashboard">Dashboard</a>
    <span class="sep">›</span>
    <a href="/communication?tab=diffuser">Communication</a>
    <span class="sep">›</span>
    <span class="current">Nouvelle diffusion</span>
  </nav>

  <div class="main-head">
    <div>
      <h1 class="page-title">📧 Nouvelle diffusion</h1>
      <div class="page-sub">Envoyez un message par email à vos adhérents.</div>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <span>⚠️</span>
      <div><?= h($error) ?></div>
    </div>
  <?php endif; ?>

  <form method="POST" action="/communication-diffuser-nouveau"
        style="background:var(--bg); border:1px solid var(--border); border-radius:12px; padding:24px; max-width:900px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

    <div style="margin-bottom:16px;">
      <label for="subject" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Sujet *</label>
      <input type="text" id="subject" name="subject" required minlength="5" maxlength="200"
             placeholder="Ex : Newsletter mars 2026, Invitation AG, Rappel cotisation..."
             value="<?= h($form['subject']) ?>"
             style="width:100%; padding:10px 12px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink);">
    </div>

    <div style="margin-bottom:20px;">
      <label for="body" style="display:block; font-size:13px; font-weight:500; margin-bottom:6px;">Message *</label>
      <textarea id="body" name="body" required minlength="10" rows="10"
                placeholder="Bonjour à tous,&#10;&#10;Nous vous informons que...&#10;&#10;Cordialement,"
                style="width:100%; padding:12px 14px; background:var(--bg); border:1px solid var(--border-strong); border-radius:8px; font-family:inherit; font-size:14px; color:var(--ink); resize:vertical; line-height:1.6;"><?= h($form['body']) ?></textarea>
      <div style="font-size:11.5px; color:var(--ink-4); margin-top:4px;">
        💡 Le message sera envoyé avec « Bonjour [prénom] » en en-tête et la signature de votre asso.
      </div>
    </div>

    <div style="margin-bottom:20px;">
      <label style="display:block; font-size:13px; font-weight:500; margin-bottom:10px;">Destinataires *</label>

      <div style="display:grid; gap:8px;">
        <!-- Option 1 : Tous -->
        <label style="display:flex; align-items:flex-start; gap:10px; padding:12px; background:var(--bg-2); border:2px solid <?= $form['recipient_type'] === 'all' ? 'var(--acc)' : 'var(--border)' ?>; border-radius:10px; cursor:pointer;">
          <input type="radio" name="recipient_type" value="all" <?= $form['recipient_type'] === 'all' ? 'checked' : '' ?> onchange="toggleRecipientOptions()" style="margin-top:2px;">
          <div>
            <div style="font-size:13.5px; font-weight:500;">📢 Tous les adhérents</div>
            <div style="font-size:12px; color:var(--ink-3); margin-top:2px;">
              <?= count($all_users) ?> adhérent<?= count($all_users) > 1 ? 's' : '' ?> recevront l'email
            </div>
          </div>
        </label>

        <!-- Option 2 : Par rôle -->
        <label style="display:flex; align-items:flex-start; gap:10px; padding:12px; background:var(--bg-2); border:2px solid <?= $form['recipient_type'] === 'by_role' ? 'var(--acc)' : 'var(--border)' ?>; border-radius:10px; cursor:pointer;">
          <input type="radio" name="recipient_type" value="by_role" <?= $form['recipient_type'] === 'by_role' ? 'checked' : '' ?> onchange="toggleRecipientOptions()" style="margin-top:2px;">
          <div style="flex:1;">
            <div style="font-size:13.5px; font-weight:500;">🎯 Par rôle</div>
            <div style="font-size:12px; color:var(--ink-3); margin-top:2px; margin-bottom:10px;">Cible certains rôles seulement</div>
            <div id="roles-selector" style="display:<?= $form['recipient_type'] === 'by_role' ? 'flex' : 'none' ?>; gap:8px; flex-wrap:wrap;">
              <?php foreach (['admin' => 'Admins', 'coordinator' => 'Coordinateurs', 'referent' => 'Référents', 'member' => 'Membres', 'follower' => 'Suiveurs'] as $key => $lbl): ?>
                <label style="display:flex; align-items:center; gap:6px; padding:5px 10px; background:var(--bg); border:1px solid var(--border); border-radius:999px; font-size:12.5px; cursor:pointer;">
                  <input type="checkbox" name="recipient_roles[]" value="<?= $key ?>"
                         <?= in_array($key, $form['recipient_roles'], true) ? 'checked' : '' ?>>
                  <?= $lbl ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </label>

        <!-- Option 3 : Custom -->
        <label style="display:flex; align-items:flex-start; gap:10px; padding:12px; background:var(--bg-2); border:2px solid <?= $form['recipient_type'] === 'custom' ? 'var(--acc)' : 'var(--border)' ?>; border-radius:10px; cursor:pointer;">
          <input type="radio" name="recipient_type" value="custom" <?= $form['recipient_type'] === 'custom' ? 'checked' : '' ?> onchange="toggleRecipientOptions()" style="margin-top:2px;">
          <div style="flex:1;">
            <div style="font-size:13.5px; font-weight:500;">✅ Sélection personnalisée</div>
            <div style="font-size:12px; color:var(--ink-3); margin-top:2px; margin-bottom:10px;">Choisis manuellement les destinataires</div>
            <div id="users-selector" style="display:<?= $form['recipient_type'] === 'custom' ? 'block' : 'none' ?>; max-height:220px; overflow-y:auto; background:var(--bg); border:1px solid var(--border); border-radius:8px; padding:8px;">
              <?php foreach ($all_users as $u): ?>
                <label style="display:flex; align-items:center; gap:8px; padding:5px 8px; border-radius:6px; cursor:pointer; font-size:12.5px;" onmouseover="this.style.background='var(--bg-2)'" onmouseout="this.style.background=''">
                  <input type="checkbox" name="recipient_user_ids[]" value="<?= (int)$u['id'] ?>"
                         <?= in_array($u['id'], $form['recipient_user_ids']) ? 'checked' : '' ?>>
                  <span><?= h($u['first_name'] . ' ' . $u['last_name']) ?></span>
                  <span style="color:var(--ink-4); font-size:11px;"><?= h($u['email']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </label>
      </div>
    </div>

    <div style="padding:14px; background:rgba(5, 150, 105, 0.06); border:1px solid rgba(5, 150, 105, 0.2); border-radius:10px; margin-bottom:20px; font-size:12.5px; color:var(--acc-dark); line-height:1.5;">
      ⚠️ <strong>Attention :</strong> l'email sera envoyé immédiatement à la validation. Vérifie bien le message et les destinataires avant de confirmer.
    </div>

    <div style="display:flex; gap:10px; justify-content:flex-end;">
      <a href="/communication?tab=diffuser" class="btn btn-ghost">Annuler</a>
      <button type="submit" class="btn btn-primary" onclick="return confirm('Confirmer l\'envoi de cette diffusion ?')">📧 Envoyer maintenant</button>
    </div>
  </form>

</div>

<script>
function toggleRecipientOptions() {
  var type = document.querySelector('input[name="recipient_type"]:checked').value;
  document.getElementById('roles-selector').style.display = (type === 'by_role') ? 'flex' : 'none';
  document.getElementById('users-selector').style.display = (type === 'custom') ? 'block' : 'none';

  // Maj bordure active
  document.querySelectorAll('input[name="recipient_type"]').forEach(function(radio) {
    var label = radio.closest('label');
    if (radio.checked) {
      label.style.borderColor = 'var(--acc)';
    } else {
      label.style.borderColor = 'var(--border)';
    }
  });
}
</script>

<?php render_foot(); ?>
