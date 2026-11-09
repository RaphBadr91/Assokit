<?php
/**
 * fondateur-plans.php
 * --------------------------------------------------------------
 * Page admin FONDATEUR : gérer les plans tarifaires
 * Liste · Créer · Modifier · Supprimer · Voir adoption
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/plan-helpers.php';
require_once __DIR__ . '/trial-helpers.php';
require_once __DIR__ . '/sa-auth-helpers.php';
require_once __DIR__ . '/sa-plan-state-helpers.php';
require_once __DIR__ . '/plan-catalog-helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// === Vérification accès fondateur uniquement ===
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) { header('Location: /connexion'); exit; }
sa_leave_impersonation_first();   // incarnation en cours : page « Revenir à mon compte », pas un 403

$user = $pdo->prepare("SELECT id, email, role, org_id, is_founder, is_super_admin FROM users WHERE id = :id LIMIT 1");
$user->execute([':id' => $user_id]);
$user = $user->fetch(PDO::FETCH_ASSOC);

// Fondateur uniquement (role='founder' OU is_founder=1), comme le hub Pilotage Fondateur :
// les plans et leurs prix sont facturés aux associations, un super-admin ne les modifie pas.
$has_access = $user && (($user['role'] ?? '') === 'founder' || !empty($user['is_founder']));

if (!$has_access) {
    http_response_code(403);
    render_head('Accès refusé');
    render_sidebar('fondateur-plans');
    echo '<main class="main"><div class="card"><h1>🚫 Accès refusé</h1><p>Cette page est réservée au fondateur.</p></div></main>';
    render_foot();
    exit;
}

// Confirmation d'identité du cockpit (/super-admin-login), prolongée à chaque page
sa_auth_gate();

$flash = $_SESSION['flash_fondateur_plans'] ?? null;
unset($_SESSION['flash_fondateur_plans']);

// Le « label spécial » n'est proposé que si la colonne existe dans cette base
$plan_cols = ak_db_columns($pdo, 'asso_plans');
$plan_has_price_label = !$plan_cols || isset($plan_cols['price_label']);

// === Actions POST : create / update / delete ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Requete invalide (jeton CSRF manquant ou expire).'); }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create' || $action === 'update') {
            $data = [
                'slug'          => preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string)($_POST['slug'] ?? '')))),
                'name'          => trim((string)($_POST['name'] ?? '')),
                'tagline'       => trim((string)($_POST['tagline'] ?? '')),
                'price_cents'   => (int)round(((float)($_POST['price_eur'] ?? 0)) * 100),
                'price_label'   => trim((string)($_POST['price_label'] ?? '')) ?: null,
                'is_custom_quote' => !empty($_POST['is_custom_quote']) ? 1 : 0,
                'bonus_ai_first_week'      => max(0, (int)($_POST['bonus_ai_first_week'] ?? 0)),
                'is_featured' => !empty($_POST['is_featured']) ? 1 : 0,
                'is_visible'  => !empty($_POST['is_visible']) ? 1 : 0,
                'display_order' => (int)($_POST['display_order'] ?? 0),
            ];

            // Limites (vide = illimité, 0 = bloqué) et fonctions : listes de la grille (plan-helpers.php)
            foreach (ak_plan_limit_keys() as $k => $lbl) {
                $v = trim((string)($_POST[$k] ?? ''));
                $data[$k] = $v === '' ? null : max(0, (int)$v);
            }
            foreach (ak_plan_feature_keys() as $k => $lbl) {
                $data[$k] = !empty($_POST[$k]) ? 1 : 0;
            }

            if ($data['slug'] === '' || $data['name'] === '') throw new AkPlanCatalogException('Slug et nom obligatoires.');

            // Ligne actuelle (modification) : règles du catalogue (slug de la grille figé, slug unique,
            // offre fondateur / essai / accès limité toujours masqués), plan-catalog-helpers.php
            $old = null;
            if ($action === 'update') {
                $plan_id = (int)($_POST['plan_id'] ?? 0);
                if ($plan_id <= 0) throw new AkPlanCatalogException('Formule introuvable.');
                $oq = $pdo->prepare("SELECT * FROM asso_plans WHERE id = ?");
                $oq->execute([$plan_id]);
                $old = $oq->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            ak_plan_catalog_guard($pdo, $old, $data, $action);
            $price_warning = ak_plan_catalog_price_warning($old, $data);

            // N'écrit que les colonnes qui existent (schéma de prod non versionné : une seule
            // colonne absente, comme price_label, bloquait tout enregistrement de plan).
            // Même règle que l'API de l'appli (api/app-founder-plans.php).
            if ($action === 'create') {
                ak_db_insert($pdo, 'asso_plans', $data);
                $_SESSION['flash_fondateur_plans'] = ['type' => 'success', 'msg' => "Formule « {$data['name']} » créée."];
            } else {
                $pdo->beginTransaction();
                ak_db_update($pdo, 'asso_plans', (int)$old['id'], $data);
                // organizations.plan (ancien libellé) suit le slug, comme dans l'appli
                ak_plan_slug_rename($pdo, (string)$old['slug'], $data['slug']);
                $pdo->commit();
                $_SESSION['flash_fondateur_plans'] = ['type' => 'success', 'msg' => "Formule « {$data['name']} » mise à jour."];
            }
            if ($price_warning !== '') {
                $_SESSION['flash_fondateur_plans']['type'] = 'warning';
                $_SESSION['flash_fondateur_plans']['msg'] .= ' ' . $price_warning;
                $_SESSION['flash_fondateur_plans']['link'] = ['href' => '/fondateur-stripe-config', 'text' => 'Ouvrir Configuration Stripe'];
            }
        } elseif ($action === 'delete') {
            $plan_id = (int)($_POST['plan_id'] ?? 0);
            $oq = $pdo->prepare("SELECT * FROM asso_plans WHERE id = ?");
            $oq->execute([$plan_id]);
            $old = $oq->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$old) throw new AkPlanCatalogException('Formule introuvable.');
            // Lignes de la grille (Essentiel, Pro, Équipe, Sur-mesure, essai, accès limité, offre fondateur) : jamais supprimées
            $none = [];
            ak_plan_catalog_guard($pdo, $old, $none, 'delete');
            // Vérifier qu'aucun abonnement (historique compris) ni aucun ancien libellé organizations.plan
            // ne l'utilise (même règle que l'API de l'appli)
            $st = $pdo->prepare("SELECT COUNT(*) FROM asso_subscriptions WHERE plan_id = :p");
            $st->execute([':p' => $plan_id]);
            $used = (int)$st->fetchColumn();
            if ($used === 0) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM organizations WHERE plan = :s" . (isset(ak_db_columns($pdo, 'organizations')['deleted_at']) ? " AND deleted_at IS NULL" : ""));
                $st->execute([':s' => (string)$old['slug']]);
                $used = (int)$st->fetchColumn();
            }
            if ($used > 0) {
                throw new AkPlanCatalogException('Impossible : cette formule est utilisée par une ou plusieurs associations (historique compris). Masquez-la plutôt.');
            }
            $pdo->prepare("DELETE FROM asso_plans WHERE id = :id")->execute([':id' => $plan_id]);
            $_SESSION['flash_fondateur_plans'] = ['type' => 'success', 'msg' => 'Formule supprimée.'];
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Jamais le texte SQL à l'écran : il reste dans le journal du serveur
        error_log('[fondateur-plans] ' . $action . ' : ' . $e->getMessage());
        $_SESSION['flash_fondateur_plans'] = ['type' => 'error', 'msg' => 'Enregistrement impossible. Réessayez ou contactez le support technique.'];
    } catch (AkPlanCatalogException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_fondateur_plans'] = ['type' => 'error', 'msg' => $e->getMessage()];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[fondateur-plans] ' . $action . ' : ' . get_class($e) . ' ' . $e->getMessage());
        $_SESSION['flash_fondateur_plans'] = ['type' => 'error', 'msg' => 'Enregistrement impossible. Réessayez ou contactez le support technique.'];
    }
    header('Location: /fondateur-plans');
    exit;
}

// === Récupération des plans ===
// Deux blocs : les formules de la page Tarifs (Essentiel, Pro, Équipe, Sur-mesure), puis les lignes
// techniques et anciennes. Colonnes facultatives (schéma de prod non versionné) : tri sans elles.
$order = ["(p.slug IN ('" . implode("','", AK_PLAN_PUBLIC_GRID) . "')) DESC"];
if (!$plan_cols || isset($plan_cols['is_visible'])) $order[] = 'p.is_visible DESC';
if (!$plan_cols || isset($plan_cols['display_order'])) $order[] = 'p.display_order ASC';
$order[] = 'p.id ASC';
$plans = $pdo->query("
    SELECT p.*,
      (SELECT COUNT(*) FROM asso_subscriptions s WHERE s.plan_id = p.id AND s.status = 'active') AS active_subs
    FROM asso_plans p
    ORDER BY " . implode(', ', $order) . "
")->fetchAll(PDO::FETCH_ASSOC);

// Passage à la grille de novembre 2026 (migrations/2026-11-09-grille-tarifaire.sql) : ligne de la
// grille dont les valeurs ne sont pas encore posées (grille_version), colonne absente comprise
$grid_version = defined('AK_GRID_VERSION') ? (int)AK_GRID_VERSION : 202611;
$grid_col = !$plan_cols || isset($plan_cols['grille_version']);
$plan_pending = function (array $p) use ($grid_version): bool {
    return ak_plan_slug_fixed($p['slug'] ?? '') && (int)($p['grille_version'] ?? 0) < $grid_version;
};
$pending_labels = [];
foreach ($plans as $p) if ($plan_pending($p)) $pending_labels[] = ak_plan_label($p['slug'], $p['name'] ?? null);
// Formules mises en avant parmi celles proposées (une seule attendue : Pro)
$featured_visible = array_values(array_filter($plans, fn($p) => !empty($p['is_visible']) && !empty($p['is_featured'])));
$feature_keys = ak_plan_feature_keys();
$default_on = ak_plan_default_on_keys();
/** Fonction active sur la ligne ? Colonne absente : comme ak_plan_has (fonctions de la grille et e-mails permis). */
$feature_on = function (array $p, string $k) use ($default_on): bool {
    return array_key_exists($k, $p) ? !empty($p[$k]) : (in_array($k, $default_on, true) || $k === 'feature_email_diffusion');
};
$fmt_eur = fn(int $c): string => number_format($c / 100, 2, ',', "\u{00A0}") . "\u{00A0}€";
$demarrage_live = function_exists('ak_demarrage_restricted') && ak_demarrage_restricted();

// Adoption = associations dont le plan COURANT est ce plan (ak_org_plan_state : même vérité que la
// fiche association et l'appli). Les essais comptent sur le plan d'essai, en cours ou terminés.
// Sans abonnement : d'après l'ancien libellé organizations.plan (même repli que l'API de l'appli).
$plan_usage = [];
try {
    $org_sql = "SELECT id, plan FROM organizations" . (isset(ak_db_columns($pdo, 'organizations')['deleted_at']) ? " WHERE deleted_at IS NULL" : "");
    $org_plans = $pdo->query($org_sql)->fetchAll(PDO::FETCH_KEY_PAIR);
    $slug_ids = [];
    foreach ($plans as $p) $slug_ids[(string)($p['slug'] ?? '')] = (int)$p['id'];
    foreach (sa_org_plan_states($pdo, array_keys($org_plans)) as $oid => $ps) {
        $pid = (int)($ps['plan_id'] ?? 0);
        if (!$pid) $pid = $slug_ids[(string)($org_plans[$oid] ?? '')] ?? 0;
        if (!$pid) continue;
        $u = $plan_usage[$pid] ?? ['total' => 0, 'trial' => 0, 'expired' => 0];
        $u['total']++;
        if ($ps['badge_tone'] === 'trial') $u['trial']++;
        elseif ($ps['badge_tone'] === 'expired') $u['expired']++;
        $plan_usage[$pid] = $u;
    }
} catch (Throwable $e) {
    error_log('[fondateur-plans] adoption: ' . get_class($e));
}

// Plan en édition ?
$edit_id = (int)($_GET['edit'] ?? 0);
$editing = null;
if ($edit_id > 0) {
    foreach ($plans as $p) if ((int)$p['id'] === $edit_id) { $editing = $p; break; }
}

render_head('Formules — Admin');
render_sidebar('fondateur-plans');
?>

<main class="main">
  <div class="page-head" style="margin-bottom:24px;">
    <div>
      <h1 style="margin:0 0 4px;">💼 Formules</h1>
      <p style="color:#64748b;margin:0;">Gérer le catalogue des offres Assokit</p>
    </div>
    <button onclick="document.getElementById('plan-form').scrollIntoView({behavior:'smooth'});document.querySelector('#plan-form input[name=name]').focus()" class="btn btn-primary">
      ➕ Nouvelle formule
    </button>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>" style="margin-bottom:18px;<?= $flash['type'] === 'warning' ? 'background:#FFF7ED;border:1px solid #FDBA74;color:#9A3412;' : '' ?>">
      <?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?>
      <?php if (!empty($flash['link']['href'])): ?>
        <a href="<?= h($flash['link']['href']) ?>" style="font-weight:700;margin-left:6px;"><?= h($flash['link']['text'] ?? $flash['link']['href']) ?> →</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($pending_labels): ?>
    <div class="alert" style="margin-bottom:18px;background:#FFF7ED;border:1px solid #FDBA74;color:#9A3412;">
      <strong>Pas encore passée<?= count($pending_labels) > 1 ? 's' : '' ?> à la nouvelle grille :</strong> <?= h(implode(', ', $pending_labels)) ?>.
      <?php if (!$grid_col): ?>
        Lancez <code>php migrations/run.php 2026-11-09-grille-tarifaire.sql</code>, puis <code>php cli-rattacher-fondateur.php --apply</code>, puis relancez <code>php migrations/run.php 2026-11-09-grille-tarifaire.sql</code>.
      <?php else: ?>
        Lancez <code>php cli-rattacher-fondateur.php --apply</code>, puis relancez <code>php migrations/run.php 2026-11-09-grille-tarifaire.sql</code>.
      <?php endif; ?>
      D’ici là, ces formules ne sont ni affichées avec un prix ni vendues en ligne.
    </div>
  <?php endif; ?>

  <?php if (count($featured_visible) > 1): ?>
    <div class="alert" style="margin-bottom:18px;background:#FFF7ED;border:1px solid #FDBA74;color:#9A3412;">
      Plusieurs formules proposées sont cochées « Mis en avant » (<?= h(implode(', ', array_map(fn($p) => ak_plan_label($p['slug'], $p['name'] ?? null), $featured_visible))) ?>). Une seule est attendue (Pro, comme sur la page Tarifs).
    </div>
  <?php endif; ?>

  <!-- LISTE DES PLANS -->
  <div class="card" style="padding:0;overflow:hidden;margin-bottom:24px;">
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:14px;min-width:900px;">
        <thead style="background:#F8FAFC;border-bottom:1px solid #E2E8F0;">
          <tr style="text-align:left;">
            <th style="padding:14px 18px;font-weight:600;">Plan</th>
            <th style="padding:14px 12px;font-weight:600;">Prix</th>
            <th style="padding:14px 12px;font-weight:600;text-align:center;">Membres / gestionnaires</th>
            <th style="padding:14px 12px;font-weight:600;text-align:center;">Fonctions</th>
            <th style="padding:14px 12px;font-weight:600;text-align:center;">Crédits IA / mois</th>
            <th style="padding:14px 12px;font-weight:600;text-align:center;">E-mails / mois</th>
            <th style="padding:14px 12px;font-weight:600;text-align:center;">Adoption</th>
            <th style="padding:14px 12px;font-weight:600;text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php $in_grid_block = null; ?>
          <?php foreach ($plans as $p):
              $p_slug = (string)($p['slug'] ?? '');
              $p_public = in_array($p_slug, AK_PLAN_PUBLIC_GRID, true);
              $p_is_trial = !empty($p['is_trial']) || $p_slug === 'pro-essai';
              $p_label = ak_plan_label($p_slug, $p['name'] ?? null);
              $p_pending = $plan_pending($p);
              $p_lim = fn(string $k) => (!array_key_exists($k, $p) || $p[$k] === null || $p[$k] === '') ? '∞' : ak_plan_fmt_int($p[$k]);
              $p_nf = 0;
              foreach ($feature_keys as $fk => $fl) if ($feature_on($p, $fk)) $p_nf++;
          ?>
            <?php if ($in_grid_block !== $p_public): $in_grid_block = $p_public; ?>
              <tr style="background:#F8FAFC;border-bottom:1px solid #E2E8F0;">
                <td colspan="8" style="padding:8px 18px;font-size:12px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.04em;">
                  <?= $p_public ? 'Formules de la page Tarifs' : 'Lignes techniques et anciennes formules (jamais sur la page Tarifs)' ?>
                </td>
              </tr>
            <?php endif; ?>
            <tr style="border-bottom:1px solid #F1F5F9;<?= !empty($p['is_featured']) ? 'background:#F0FDF4;' : '' ?>">
              <td style="padding:14px 18px;">
                <div style="font-weight:600;color:#0F172A;">
                  <?= htmlspecialchars((string)$p['name'], ENT_QUOTES, 'UTF-8') ?>
                  <?= !empty($p['is_featured']) ? ' <span style="color:#059669;">⭐</span>' : '' ?>
                  <?= empty($p['is_visible']) ? ' <span style="color:#64748B;font-size:12px;">(masquée)</span>' : '' ?>
                  <?php if ($p_is_trial): ?>
                    <span style="background:#EDE9FE;color:#5B21B6;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.03em;margin-left:4px;">ESSAI</span>
                  <?php endif; ?>
                </div>
                <div style="color:#64748B;font-size:12px;">slug : <code><?= htmlspecialchars($p_slug, ENT_QUOTES, 'UTF-8') ?></code><?php if ($p_slug === 'assokit' && $p_pending): ?> · affiché « Pro (en attente, ancienne offre <?= h($fmt_eur((int)($p['price_cents'] ?? 0))) ?>) »<?php elseif ($p_label !== trim((string)$p['name'])): ?> · affiché « <?= h($p_label) ?> »<?php endif; ?></div>
                <div style="margin-top:4px;">
                  <span style="display:inline-block;background:<?= $p_public ? '#E0F2FE' : '#F1F5F9' ?>;color:<?= $p_public ? '#0C4A6E' : '#475569' ?>;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;"><?= h(ak_plan_catalog_role($p)) ?></span>
                  <?php if ($p_pending): ?>
                    <span style="display:inline-block;background:#FFEDD5;color:#9A3412;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;">Pas encore passée à la nouvelle grille</span>
                  <?php endif; ?>
                </div>
              </td>
              <td style="padding:14px 12px;">
                <?php if ($p_is_trial): ?>
                  <span style="color:#5B21B6;font-weight:600;">0&nbsp;€ · essai gratuit</span>
                  <div style="color:#64748B;font-size:12px;"><?= (int)AK_TRIAL_DAYS ?>&nbsp;jours, sans carte bancaire</div>
                <?php elseif ($p_slug === 'demarrage'): ?>
                  <span style="color:#475569;font-weight:600;">Accès limité</span>
                  <div style="color:#64748B;font-size:12px;"><?= $demarrage_live ? 'lecture seule active' : 'lecture seule pas encore active' ?></div>
                <?php elseif (!empty($p['is_custom_quote']) && (int)($p['price_cents'] ?? 0) <= 0): ?>
                  <span style="color:#475569;">Sur devis</span>
                <?php elseif ((int)($p['price_cents'] ?? 0) <= 0): ?>
                  <span style="color:#059669;font-weight:600;">Gratuit</span>
                <?php else: ?>
                  <strong><?= h($fmt_eur((int)$p['price_cents'])) ?></strong>
                  <span style="color:#64748B;font-size:12px;">HT/mois</span>
                  <?php if (!empty($p['is_custom_quote'])): ?><div style="color:#64748B;font-size:12px;">devis d’un client</div><?php endif; ?>
                <?php endif; ?>
              </td>
              <td style="padding:14px 12px;text-align:center;">
                <?= $p_lim('limit_adherents') ?>
                <?php if (array_key_exists('limit_managers', $p)): ?>
                  <div style="color:#64748B;font-size:11px;"><?= $p_lim('limit_managers') ?> gest.</div>
                <?php endif; ?>
              </td>
              <td style="padding:14px 12px;text-align:center;" title="Fonctions incluses sur <?= count($feature_keys) ?>"><?= $p_nf ?>/<?= count($feature_keys) ?></td>
              <td style="padding:14px 12px;text-align:center;">
                <?= $p_lim('limit_ai_text_per_month') ?>
                <?php if ((int)($p['bonus_ai_first_week'] ?? 0) > 0): ?>
                  <div style="color:#7E22CE;font-size:11px;">+<?= (int)$p['bonus_ai_first_week'] ?> la 1re semaine</div>
                <?php endif; ?>
              </td>
              <td style="padding:14px 12px;text-align:center;">
                <?php if (!$feature_on($p, 'feature_email_diffusion')): ?><span style="color:#DC2626;">🔒</span><?php else: ?><?= $p_lim('limit_emails_per_month') ?><?php endif; ?>
              </td>
              <td style="padding:14px 12px;text-align:center;">
                <?php $usage = $plan_usage[(int)$p['id']] ?? ['total' => 0, 'trial' => 0, 'expired' => 0]; ?>
                <span style="background:<?= $p_is_trial ? '#EDE9FE' : '#E0F2FE' ?>;color:<?= $p_is_trial ? '#5B21B6' : '#0C4A6E' ?>;padding:3px 10px;border-radius:999px;font-weight:600;font-size:12px;white-space:nowrap;">
                  <?= (int)$usage['total'] ?> org.
                </span>
                <?php if ($usage['trial'] || $usage['expired']): ?>
                  <div style="color:#64748B;font-size:11px;margin-top:4px;white-space:nowrap;">
                    <?= $usage['trial'] ? (int)$usage['trial'] . ' en essai' : '' ?><?= $usage['trial'] && $usage['expired'] ? ' · ' : '' ?><?= $usage['expired'] ? (int)$usage['expired'] . ' essai' . ($usage['expired'] > 1 ? 's' : '') . ' terminé' . ($usage['expired'] > 1 ? 's' : '') : '' ?>
                  </div>
                <?php endif; ?>
              </td>
              <td style="padding:14px 12px;text-align:right;">
                <a href="?edit=<?= $p['id'] ?>" class="btn btn-sm" style="margin-right:4px;">✏️ Éditer</a>
                <?php if (!ak_plan_slug_fixed($p_slug) && (int)$p['active_subs'] === 0 && (int)$usage['total'] === 0): ?>
                  <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer définitivement cette formule ?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="color:#DC2626;">🗑</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- FORMULAIRE CRÉATION / ÉDITION -->
  <div class="card" id="plan-form">
    <h2 style="margin-top:0;font-size:18px;">
      <?= $editing ? '✏️ Modifier la formule : ' . htmlspecialchars((string)$editing['name']) : '➕ Créer une formule' ?>
    </h2>

    <?php
      $e_slug = (string)($editing['slug'] ?? '');
      $e_fixed = $editing && ak_plan_slug_fixed($e_slug);
      $e_never = $editing && ak_plan_never_visible($e_slug);
    ?>
    <form method="post" style="display:grid;gap:16px;">
      <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
      <?php if ($editing): ?>
        <input type="hidden" name="plan_id" value="<?= $editing['id'] ?>">
      <?php endif; ?>

      <!-- Identité -->
      <fieldset style="border:1px solid #E2E8F0;border-radius:10px;padding:18px;">
        <legend style="font-weight:600;padding:0 8px;">Identité</legend>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Nom *</label>
            <input type="text" name="name" required value="<?= htmlspecialchars($editing['name'] ?? '') ?>" style="width:100%;padding:10px;border:1px solid #E2E8F0;border-radius:8px;">
          </div>
          <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Slug * (a-z, 0-9, -)</label>
            <input type="text" name="slug" required pattern="[a-z0-9\-]+" value="<?= htmlspecialchars($editing['slug'] ?? '') ?>"<?= $e_fixed ? ' readonly aria-readonly="true"' : '' ?> style="width:100%;padding:10px;border:1px solid #E2E8F0;border-radius:8px;<?= $e_fixed ? 'background:#F1F5F9;color:#475569;' : '' ?>">
            <?php if ($e_fixed): ?>
              <div style="margin-top:6px;padding:8px 10px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;font-size:12px;color:#475569;">
                🔒 Formule de la grille : la page Tarifs, l’essai et la migration dépendent de ce slug. Il ne peut pas changer.
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div style="margin-top:12px;">
          <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Accroche</label>
          <input type="text" name="tagline" value="<?= htmlspecialchars($editing['tagline'] ?? '') ?>" style="width:100%;padding:10px;border:1px solid #E2E8F0;border-radius:8px;">
          <div style="color:#64748B;font-size:12px;margin-top:4px;">Utilisée dans Mon abonnement et l’appli. La page Tarifs garde son texte.</div>
        </div>
      </fieldset>

      <!-- Prix -->
      <fieldset style="border:1px solid #E2E8F0;border-radius:10px;padding:18px;">
        <legend style="font-weight:600;padding:0 8px;">Prix</legend>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
          <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Prix mensuel HT (€)</label>
            <?php $e_price_locked = $e_slug === 'fondateur'; ?>
            <input type="number" step="0.01" min="0" name="price_eur" value="<?= $editing ? number_format($editing['price_cents'] / 100, 2, '.', '') : '0' ?>"<?= $e_price_locked ? ' readonly aria-readonly="true"' : '' ?> style="width:100%;padding:10px;border:1px solid #E2E8F0;border-radius:8px;<?= $e_price_locked ? 'background:#F1F5F9;color:#475569;' : '' ?>">
            <?php if ($e_price_locked): ?>
              <div style="color:#64748B;font-size:12px;margin-top:4px;">🔒 Prix garanti 12 mois aux clients rattachés, prélevé au prix Stripe de Pro : il ne se modifie pas ici.</div>
            <?php endif; ?>
          </div>
          <?php if ($plan_has_price_label): ?>
          <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Label spécial</label>
            <input type="text" name="price_label" placeholder="Gratuit, Sur devis…" value="<?= htmlspecialchars($editing['price_label'] ?? '') ?>" style="width:100%;padding:10px;border:1px solid #E2E8F0;border-radius:8px;">
          </div>
          <?php endif; ?>
          <div style="display:flex;align-items:end;padding-bottom:8px;">
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
              <input type="checkbox" name="is_custom_quote" <?= !empty($editing['is_custom_quote']) ? 'checked' : '' ?>>
              <span>Sur devis (pas de paiement direct)</span>
            </label>
          </div>
        </div>
      </fieldset>

      <!-- Limites quotas -->
      <fieldset style="border:1px solid #E2E8F0;border-radius:10px;padding:18px;">
        <legend style="font-weight:600;padding:0 8px;">Limites & quotas <span style="color:#64748B;font-weight:400;font-size:12px;">(vide = illimité, 0 = bloqué ; membres = bureau compris ; gestionnaires = administrateurs, coordinateurs, référents)</span></legend>
        <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:12px;">
          <?php
          $fields = ak_plan_limit_keys();
          foreach ($fields as $field => $label):
              $val = $editing[$field] ?? '';
          ?>
            <div>
              <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></label>
              <input type="number" min="0" name="<?= $field ?>" value="<?= $val === null ? '' : htmlspecialchars((string)$val) ?>" placeholder="∞" style="width:100%;padding:8px;border:1px solid #E2E8F0;border-radius:8px;font-size:13px;">
            </div>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:12px;">
          <label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Bonus de crédits IA la 1re semaine (en plus du quota mensuel)</label>
          <input type="number" min="0" name="bonus_ai_first_week" value="<?= htmlspecialchars((string)($editing['bonus_ai_first_week'] ?? 0)) ?>" style="width:200px;padding:8px;border:1px solid #E2E8F0;border-radius:8px;">
        </div>
      </fieldset>

      <!-- Features -->
      <fieldset style="border:1px solid #E2E8F0;border-radius:10px;padding:18px;">
        <legend style="font-weight:600;padding:0 8px;">Fonctions incluses</legend>
        <div style="color:#64748B;font-size:12px;margin:0 0 10px;">Utilisées dans Mon abonnement et l’appli. La page Tarifs garde son texte.</div>
        <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:8px;">
          <?php
          $features = ak_plan_feature_keys();
          foreach ($features as $key => $label):
              // Création : mêmes valeurs par défaut que les colonnes de la grille (et que l'appli)
              $checked = $editing ? !empty($editing[$key]) : (in_array($key, $default_on, true) || $key === 'feature_email_diffusion');
          ?>
            <label style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:#F8FAFC;border-radius:8px;font-size:13px;cursor:pointer;">
              <input type="checkbox" name="<?= $key ?>" <?= $checked ? 'checked' : '' ?>>
              <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <!-- Affichage -->
      <fieldset style="border:1px solid #E2E8F0;border-radius:10px;padding:18px;">
        <legend style="font-weight:600;padding:0 8px;">Affichage</legend>
        <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:12px;">
          <div>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
              <input type="checkbox" name="is_featured" <?= !empty($editing['is_featured']) ? 'checked' : '' ?>>
              <span>⭐ Mis en avant</span>
            </label>
            <div style="color:#64748B;font-size:12px;margin-top:4px;">Utilisé dans Mon abonnement. La page Tarifs garde son texte.</div>
          </div>
          <div>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;<?= $e_never ? 'color:#94A3B8;' : '' ?>">
              <input type="checkbox" name="is_visible" <?= !$e_never && $editing && !empty($editing['is_visible']) ? 'checked' : '' ?><?= $e_never ? ' disabled' : '' ?>>
              <span>👁 Proposée à la souscription (Mon abonnement, paiement en ligne)</span>
            </label>
            <div style="color:#64748B;font-size:12px;margin-top:4px;">
              <?= $e_never ? 'Toujours masquée : l’offre fondateur, l’essai et l’accès limité ne se souscrivent jamais.' : 'La page Tarifs affiche toujours Essentiel, Pro, Équipe et Sur-mesure.' ?>
            </div>
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Ordre d'affichage</label>
            <input type="number" name="display_order" value="<?= htmlspecialchars((string)($editing['display_order'] ?? 0)) ?>" style="width:100%;padding:8px;border:1px solid #E2E8F0;border-radius:8px;">
          </div>
        </div>
      </fieldset>

      <div style="display:flex;gap:10px;">
        <button type="submit" class="btn btn-primary"><?= $editing ? '💾 Mettre à jour' : '➕ Créer la formule' ?></button>
        <?php if ($editing): ?>
          <a href="/fondateur-plans" class="btn">Annuler</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</main>

<?php render_foot(); ?>
