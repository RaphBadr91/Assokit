<?php
/**
 * /cotisation/{id} - Détail d'une campagne avec liste paiements + ajout manuel
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-cotisations.php';
require_once __DIR__ . '/stripe-connect-helpers.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
if ($org_id <= 0) { header('Location: /dashboard'); exit; }  // compte sans association (agent de collectivité) : pas d'« org 0 » partagée
$is_admin = ($user['role'] === 'admin'); $is_coord = ($user['role'] === 'coordinator');
$can_manage = $is_admin || $is_coord;
// Liste des payeurs (noms, e-mails, montants) : réservée à la gestion,
// comme l'entrée du menu.
if (!$can_manage) { http_response_code(403); die('Accès réservé aux administrateurs et coordinateurs.'); }

$campaign_id = (int)($_GET['id'] ?? 0);
$campaign = ck_load_campaign($pdo, $campaign_id, $org_id);
if (!$campaign) { http_response_code(404); die('Campagne introuvable.'); }

// Recherche d'adhérent pour la saisie d'un paiement (associations de plus de
// CK_ADH_SELECT_MAX membres, cf. plus bas) : réponse JSON, limitée à l'association.
if (isset($_GET['adh_q'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $q = trim((string)$_GET['adh_q']);
    $items = [];
    if (mb_strlen($q) >= 2) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        try {
            $st = $pdo->prepare("SELECT id, first_name, last_name, email FROM users
                                 WHERE org_id = ? AND deleted_at IS NULL
                                   AND (CONCAT_WS(' ', first_name, last_name) LIKE ? OR CONCAT_WS(' ', last_name, first_name) LIKE ? OR email LIKE ?)
                                 ORDER BY last_name, first_name LIMIT 30");
            $st->execute([$org_id, $like, $like, $like]);
            foreach ($st->fetchAll() as $a) {
                $items[] = ['id' => (int)$a['id'], 'name' => trim($a['first_name'] . ' ' . $a['last_name']), 'email' => (string)$a['email']];
            }
        } catch (Throwable $e) {
            error_log('[cotisation-detail] recherche adhérent : ' . $e->getMessage());
        }
    }
    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

// Lien personnel de paiement d'un adhérent (à copier et envoyer) : JSON, adhérent de l'association seulement
if (isset($_GET['lien_adherent'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $aid = (int)$_GET['lien_adherent'];
    $ok = false;
    try {
        $st = $pdo->prepare("SELECT id FROM users WHERE id = ? AND org_id = ? AND deleted_at IS NULL");
        $st->execute([$aid, $org_id]);
        $ok = (bool)$st->fetchColumn();
    } catch (Throwable $e) {}
    $url = $ok && ak_connect_campaign_online($pdo, $campaign)['ok'] ? ak_connect_campaign_url($pdo, $campaign, $aid) : '';
    echo json_encode(['ok' => $url !== '', 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$tiers = ck_load_tiers($pdo, $campaign_id);
$stats = ck_campaign_stats($pdo, $campaign_id);
// Paiement en ligne de la campagne (Stripe Connect) : état, lien public, frais des paiements reçus en ligne
$online = ak_connect_campaign_online($pdo, $campaign);
$online_closed = ak_connect_campaign_open($campaign);
$online_url = $online['ok'] ? ak_connect_campaign_url($pdo, $campaign) : '';
$is_don = ak_connect_campaign_is_don($campaign);
$online_fees = [];
try {
    if (!ak_connect_schema_missing($pdo)) {
        $st = $pdo->prepare("SELECT cotisation_payment_id, fee_cents, net_cents, status, payment_method_type FROM asso_online_payments WHERE campaign_id = ? AND org_id = ? AND cotisation_payment_id IS NOT NULL");
        $st->execute([$campaign_id, $org_id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $online_fees[(int)$r['cotisation_payment_id']] = $r;
    }
} catch (Throwable $e) {}
$payments = ck_load_payments($pdo, $campaign_id);

$flash = $_SESSION['flash_cotisation'] ?? null;
unset($_SESSION['flash_cotisation']);
if (!$flash && !empty($_GET['error'])) {
    $msg = ['invalid' => 'Paiement non enregistré : vérifiez le nom du payeur, le montant et la date.',
            'notfound' => 'Élément introuvable.', 'csrf' => 'Session expirée : rechargez la page puis recommencez.'][$_GET['error']] ?? null;
    if ($msg) $flash = ['type' => 'error', 'message' => $msg];
}

// Saisie d'un paiement refusée (montant nul, nom vide) : formulaire rouvert et prérempli.
$pf = $_SESSION['ck_pay_form'] ?? null;
unset($_SESSION['ck_pay_form']);
if (!is_array($pf) || (int)($pf['campaign_id'] ?? 0) !== $campaign_id) $pf = null;
$pv = fn(string $k, $def = '') => $pf[$k] ?? $def;

$cur = $campaign['currency'] ?? 'EUR';
$is_archived = !empty($campaign['archived_at']);

// Adhérents pour le select : tous jusqu'à CK_ADH_SELECT_MAX ; au-delà, champ de recherche
// (sinon les derniers de l'alphabet n'étaient jamais proposés).
const CK_ADH_SELECT_MAX = 500;
$adherents = [];
$adh_search = false;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ? AND deleted_at IS NULL");
    $stmt->execute([$org_id]);
    $adh_search = (int)$stmt->fetchColumn() > CK_ADH_SELECT_MAX;
    if (!$adh_search) {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email FROM users WHERE org_id = ? AND deleted_at IS NULL ORDER BY last_name, first_name");
        $stmt->execute([$org_id]);
        $adherents = $stmt->fetchAll();
    } elseif ((int)$pv('adherent_id', 0) > 0) {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email FROM users WHERE id = ? AND org_id = ? AND deleted_at IS NULL");
        $stmt->execute([(int)$pv('adherent_id'), $org_id]);
        $adherents = $stmt->fetchAll();
    }
} catch (Throwable $e) {}

render_head($campaign['name']);
?>
<?= render_sidebar('cotisations') ?>
<main class="main">
  <div class="ck-page" style="max-width:1100px;">
    <a href="/cotisations" class="ck-back">← Cotisations</a>

    <?php if ($flash): ?>
    <div class="alert <?= ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-top:12px;">
      <span><?= ($flash['type'] ?? '') === 'success' ? ak_icon('check-circle', 16) : ak_icon('alert-tri', 16) ?></span>
      <div><?= h($flash['message'] ?? '') ?></div>
    </div>
    <?php endif; ?>

    <div class="ck-camp-hero">
      <div>
        <h1 class="ck-pg-title"><?= h($campaign['name']) ?> <span class="ck-camp-status <?= $campaign['is_active'] ? 'active' : 'paused' ?>"><?= $campaign['is_active'] ? '● Active' : '○ Pause' ?></span><?php if ($is_archived): ?> <span class="ck-camp-status paused">Archivée le <?= date('d/m/Y', strtotime($campaign['archived_at'])) ?></span><?php endif; ?></h1>
        <?php if ($campaign['description']): ?><p class="ck-pg-sub"><?= nl2br(h($campaign['description'])) ?></p><?php endif; ?>
        <?php if ($campaign['closes_at']): ?><div class="ck-deadline">⏰ Clôture le <?= date('d/m/Y', strtotime($campaign['closes_at'])) ?></div><?php endif; ?>
        <?php if (!ck_campaign_renews_membership($campaign)): ?><div class="ck-pg-sub" style="margin-top:6px;">ℹ️ Les paiements de cette campagne ne prolongent pas l’adhésion<?= ck_has_renews_setting($pdo) ? ' (réglage dans ✏️ Modifier).' : ' : son nom ne contient ni « adhésion » ni « cotisation ».' ?></div><?php endif; ?>
      </div>
      <?php if ($can_manage): ?>
      <div class="ck-actions-row">
        <a href="/cotisation-form?id=<?= (int)$campaign['id'] ?>" class="ck-btn-ghost">✏️ Modifier</a>
        <?php if ($is_admin): ?>
        <form method="POST" action="/action-cotisation" style="display:inline"
              onsubmit="return confirm(<?= h(json_encode($is_archived ? 'Désarchiver cette campagne ?' : 'Archiver cette campagne ? Elle sortira de la liste principale (rubrique « Campagnes archivées ») ; ses paiements restent comptés.', JSON_UNESCAPED_UNICODE)) ?>)">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="<?= $is_archived ? 'unarchive' : 'archive' ?>">
          <input type="hidden" name="id" value="<?= (int)$campaign['id'] ?>">
          <button type="submit" class="ck-btn-ghost"><?= $is_archived ? '↩ Désarchiver' : '🗄 Archiver' ?></button>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="ck-kpis">
      <div class="ck-kpi"><div class="ck-kpi-lbl">Encaissé</div><div class="ck-kpi-val ck-green"><?= h(ck_money($stats['amount_paid'], $cur, 0)) ?></div><div class="ck-kpi-sub"><?= $stats['count_paid'] ?> paiements</div></div>
      <div class="ck-kpi"><div class="ck-kpi-lbl">En attente</div><div class="ck-kpi-val ck-amber"><?= h(ck_money($stats['amount_pending'], $cur, 0)) ?></div><div class="ck-kpi-sub"><?= $stats['count_pending'] ?> en attente</div></div>
      <div class="ck-kpi"><div class="ck-kpi-lbl">Tarifs proposés</div><div class="ck-kpi-val"><?= count($tiers) ?></div></div>
      <div class="ck-kpi"><div class="ck-kpi-lbl">Total enregistré</div><div class="ck-kpi-val"><?= count($payments) ?></div></div>
    </div>

    <!-- Tarifs -->
    <?php if (!empty($tiers)): ?>
    <div class="ck-tiers-row">
      <?php foreach ($tiers as $t): ?>
      <div class="ck-tier-card">
        <div class="ck-tier-name"><?= h($t['name']) ?></div>
        <div class="ck-tier-amount"><?= h(ck_money($t['amount'], $cur)) ?></div>
        <?php if ($t['description']): ?><div class="ck-tier-desc"><?= h($t['description']) ?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($can_manage): ?>
    <!-- Saisie paiement manuel -->
    <details class="ck-add-pay"<?= $pf ? ' open' : '' ?>>
      <summary>➕ Enregistrer un paiement manuel (chèque, virement, espèces…)</summary>
      <form method="POST" action="/action-cotisation-payment" class="ck-pay-form" id="ck-pay-form">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="form_nonce" value="<?= bin2hex(random_bytes(16)) ?>">

        <div class="ck-row">
          <div class="ck-fld">
            <label>Adhérent</label>
            <?php if ($adh_search): ?>
            <input type="search" id="ck-adh-search" placeholder="Rechercher un adhérent (nom, e-mail)…" autocomplete="off" style="margin-bottom:6px;">
            <?php endif; ?>
            <select name="adherent_id" id="ck-adh-select">
              <option value="">— Sans adhérent (saisie libre) —</option>
              <?php foreach ($adherents as $a): ?>
                <option value="<?= (int)$a['id'] ?>" data-name="<?= h(trim($a['first_name'].' '.$a['last_name'])) ?>" data-email="<?= h($a['email']) ?>"<?= (int)$pv('adherent_id', 0) === (int)$a['id'] ? ' selected' : '' ?>><?= h(trim($a['first_name'].' '.$a['last_name'])) ?><?= $a['email'] ? ' · ' . h($a['email']) : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ck-fld">
            <label>Tarif</label>
            <select name="tier_id" id="ck-tier-select">
              <option value="">— Aucun tarif (montant libre) —</option>
              <?php foreach ($tiers as $t): ?>
                <option value="<?= (int)$t['id'] ?>" data-amount="<?= h($t['amount']) ?>"<?= (int)$pv('tier_id', 0) === (int)$t['id'] ? ' selected' : '' ?>><?= h($t['name']) ?> — <?= h(ck_money($t['amount'], $cur)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="ck-row">
          <div class="ck-fld"><label>Nom du payeur *</label><input type="text" name="payer_name" id="ck-payer-name" required maxlength="150" value="<?= h($pv('payer_name')) ?>"></div>
          <div class="ck-fld"><label>Email payeur</label><input type="email" name="payer_email" id="ck-payer-email" maxlength="200" value="<?= h($pv('payer_email')) ?>"></div>
        </div>

        <div class="ck-row">
          <div class="ck-fld"><label>Montant (<?= h(ck_currency_symbol($cur)) ?>) *</label><input type="number" name="amount" id="ck-amount" required min="0.01" step="0.01" value="<?= h($pv('amount')) ?>"></div>
          <div class="ck-fld">
            <label>Méthode</label>
            <select name="payment_method">
              <?php foreach (['bank' => '🏦 Virement', 'check' => '✉️ Chèque', 'cash' => '💶 Espèces', 'other' => '➕ Autre'] as $mk => $ml): ?>
              <option value="<?= $mk ?>"<?= $pv('payment_method', 'bank') === $mk ? ' selected' : '' ?>><?= $ml ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ck-fld">
            <label>Statut</label>
            <select name="status"><option value="paid"<?= $pv('status', 'paid') !== 'pending' ? ' selected' : '' ?>>● Payé</option><option value="pending"<?= $pv('status', 'paid') === 'pending' ? ' selected' : '' ?>>○ En attente</option></select>
          </div>
        </div>

        <div class="ck-row">
          <div class="ck-fld"><label>Référence (n° chèque, virement…)</label><input type="text" name="reference" maxlength="100" value="<?= h($pv('reference')) ?>"></div>
          <div class="ck-fld"><label>Date paiement</label><input type="date" name="paid_at" value="<?= h($pv('paid_at') ?: date('Y-m-d')) ?>" min="<?= date('Y-m-d', strtotime('-5 years')) ?>" max="<?= date('Y-m-d') ?>"></div>
        </div>

        <div class="ck-fld"><label>Notes</label><textarea name="notes" rows="2"><?= h($pv('notes')) ?></textarea></div>

        <button type="submit" class="ck-btn-primary">Enregistrer le paiement</button>
      </form>
    </details>
    <?php endif; ?>

    <!-- Liste paiements -->
    <h2 class="ck-section">📋 Paiements (<?= count($payments) ?>)</h2>
    <?php if (empty($payments)): ?>
    <div class="ck-empty-mini">Aucun paiement pour le moment.</div>
    <?php else: ?>
    <div class="ck-pay-list">
      <table class="ck-pay-tbl">
        <thead><tr><th>Payeur</th><th>Tarif</th><th>Montant</th><th>Méthode</th><th>Statut</th><th>Date</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p):
          $b = ck_status_badge($p['status']);
          $name = $p['adherent_id'] ? trim($p['a_first'].' '.$p['a_last']) : $p['payer_name'];
        ?>
        <tr>
          <td>
            <div class="ck-pay-name"><?= h($name) ?></div>
            <?php if ($p['payer_email']): ?><div class="ck-pay-email"><?= h($p['payer_email']) ?></div><?php endif; ?>
          </td>
          <td><?= h($p['tier_name'] ?? '—') ?></td>
          <td class="ck-pay-amount"><?= h(ck_money($p['amount'], $p['currency'] ?? $cur)) ?></td>
          <td><?= ck_method_label($p['payment_method']) ?><?php if (isset($online_fees[(int)$p['id']])): $of = $online_fees[(int)$p['id']]; ?><div class="ck-pay-email"><?= ($of['payment_method_type'] ?? '') === 'sepa_debit' ? 'Prélèvement SEPA' : 'Carte' ?><?= $of['fee_cents'] !== null ? ' · frais Stripe ' . h(ak_connect_money((int)$of['fee_cents'])) : '' ?></div><?php endif; ?></td>
          <td><span class="ck-badge" style="background:<?= $b[2] ?>;color:<?= $b[1] ?>;"><?= h($b[0]) ?></span></td>
          <td class="ck-pay-date"><?= $p['paid_at'] ? date('d/m/Y', strtotime($p['paid_at'])) : date('d/m/Y', strtotime($p['created_at'])) ?></td>
          <td class="ck-pay-acts">
            <?php if ($can_manage && $p['status'] === 'pending'): ?>
            <form method="POST" action="/action-cotisation-payment" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="mark_paid">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button type="submit" class="ck-tbl-btn">✓ Marquer payé</button>
            </form>
            <?php endif; ?>
            <?php if ($is_admin && in_array($p['status'], ['paid', 'pending'], true)): ?>
            <form method="POST" action="/action-cotisation-payment" style="display:inline"
                  onsubmit="return confirm(<?= h(json_encode('Annuler le paiement de ' . $name . ' (' . ck_money($p['amount'], $p['currency'] ?? $cur) . ') ? Il restera visible avec le statut « Annulé » et ne comptera plus dans les encaissements.', JSON_UNESCAPED_UNICODE)) ?>)">
              <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="cancel">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button type="submit" class="ck-tbl-btn ck-tbl-btn-danger">✕ Annuler</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- Paiement en ligne (Stripe Connect) : lien public de la campagne, QR code, lien personnel d'un adhérent -->
    <div class="ck-stripe-card" id="paiement-en-ligne">
      <div class="ck-stripe-head">
        <span class="ck-stripe-icon">💳</span>
        <div style="flex:1;min-width:0;">
          <div class="ck-stripe-title"><?= $is_don ? 'Page de dons en ligne' : 'Paiement en ligne par carte' ?><?= $online['ok'] && $online_closed === null ? ' · actif' : '' ?></div>
          <?php if ($online['ok']): ?>
            <div class="ck-stripe-sub"><?= $is_don ? 'Partagez ce lien : vos donateurs choisissent leur montant et paient par carte, Apple Pay, Google Pay' : 'Partagez ce lien : vos adhérents choisissent leur tarif et paient par carte, Apple Pay, Google Pay' ?><?= in_array('sepa_debit', ak_connect_payment_types($online['account'], $online['settings'], (string)($campaign['currency'] ?: 'EUR')), true) ? ' ou prélèvement SEPA' : '' ?>. Le paiement s’enregistre tout seul ci-dessus<?= $is_don ? '' : ', et prolonge l’adhésion des adhérents venus par leur lien personnel' ?>.</div>
            <?php if ($online_closed !== null): ?><div class="ck-stripe-sub" style="color:#92400E;margin-top:4px;">⚠ <?= h($online_closed) ?> Le lien l’indique au payeur : rouvrez la campagne (✏️ Modifier) pour encaisser.</div><?php endif; ?>
          <?php else: ?>
            <div class="ck-stripe-sub"><?= h([
                'no_account' => 'Recevez les paiements par carte directement sur le compte bancaire de l’association : reliez son compte Stripe en quelques minutes.',
                'not_ready' => 'Stripe vérifie encore les informations de l’association : le lien de paiement apparaîtra ici dès que ce sera terminé.',
                'kind_off' => $is_don ? 'Les dons en ligne ne sont pas activés pour l’association.' : 'Les cotisations en ligne ne sont pas activées pour l’association.',
                'campaign_off' => 'Le paiement en ligne est désactivé pour cette campagne (✏️ Modifier pour le rétablir).',
                'currency' => 'Le paiement en ligne n’est proposé qu’en euros.',
                'suspended' => 'Paiement en ligne indisponible tant que l’espace est suspendu.',
              ][$online['reason']] ?? 'Bientôt disponible. En attendant, enregistrez ci-dessus les paiements reçus (chèque, virement, espèces).') ?></div>
          <?php endif; ?>
          <?php if (!(function_exists('ak_is_mobile_app') && ak_is_mobile_app())): /* guide public : page du site, pas de l'application */ ?>
          <div class="ck-stripe-sub" style="margin-top:4px;"><a href="/aide/paiements-en-ligne" target="_blank" rel="noopener" style="color:#059669;">Guide des paiements en ligne ↗</a></div>
          <?php endif; ?>
        </div>
        <?php if (!$online['ok'] && in_array($online['reason'], ['no_account', 'not_ready', 'kind_off'], true)): ?>
          <a href="/encaissement-en-ligne" class="ck-btn-ghost"><?= $online['reason'] === 'no_account' ? 'Activer le paiement en ligne' : 'Paiements en ligne' ?></a>
        <?php endif; ?>
      </div>
      <?php if ($online_url !== ''): ?>
      <div class="ck-online-body">
        <div class="ck-online-link">
          <input type="text" readonly value="<?= h($online_url) ?>" id="ck-pay-url" onclick="this.select()">
          <button type="button" class="ck-btn-ghost" data-copy="ck-pay-url">Copier le lien</button>
        </div>
        <div class="ck-online-qr">
          <canvas id="ck-qr" width="160" height="160" aria-label="QR code du lien de paiement"></canvas>
          <a href="#" id="ck-qr-dl" class="ck-btn-ghost" download="<?= h('qr-' . preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', (string)$campaign['name']) ?: 'campagne')) . '.png') ?>">Télécharger le QR code</a>
          <div class="ck-stripe-sub">À imprimer sur une affiche ou un flyer : il ouvre la page de paiement.</div>
        </div>
        <?php if (!$is_don && $adherents): ?>
        <div class="ck-online-member">
          <label for="ck-member-link">Lien personnel d’un adhérent (prérempli, prolonge son adhésion)</label>
          <div class="ck-online-link">
            <select id="ck-member-link"><option value="">— Choisir un adhérent —</option><?php foreach ($adherents as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h(trim($a['first_name'] . ' ' . $a['last_name'])) ?></option><?php endforeach; ?></select>
            <input type="text" readonly id="ck-member-url" placeholder="Le lien apparaît ici" onclick="this.select()">
            <button type="button" class="ck-btn-ghost" data-copy="ck-member-url">Copier</button>
          </div>
          <div class="ck-stripe-sub">Les rappels de cotisation (Relances) envoient déjà ce lien personnel à chaque adhérent.</div>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>
<style>
/* Socle commun aux pages Cotisations (mêmes valeurs que cotisations.php / cotisation-form.php) */
.ck-page { max-width: 1100px; margin: 0 auto; padding: 24px 22px; }
.ck-back { color: #6b7280; text-decoration: none; font-size: 13px; display: inline-block; margin-bottom: 12px; }
.ck-back:hover { color: #10B981; }
.ck-pg-title { font-size: 24px; margin: 0 0 4px; color: #111827; }
.ck-pg-sub { color: #6b7280; margin: 0; font-size: 14px; }
.ck-btn-primary { display: inline-flex; align-items: center; padding: 9px 16px; background: #10B981; color: #fff; text-decoration: none; border: 0; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; transition: background 0.15s; }
.ck-btn-primary:hover { background: #059669; }
.ck-btn-primary:disabled { opacity: .6; cursor: wait; }
.ck-btn-ghost { display: inline-flex; align-items: center; padding: 8px 14px; background: #fff; border: 1px solid #e5e7eb; color: #4b5563; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; font-family: inherit; }
.ck-btn-ghost:hover { background: #f9fafb; }
.ck-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 24px; }
.ck-kpi { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; }
.ck-kpi-lbl { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; margin-bottom: 4px; }
.ck-kpi-val { font-size: 22px; font-weight: 700; color: #111827; }
.ck-green { color: #10B981; } .ck-amber { color: #F59E0B; }
.ck-camp-status { font-size: 10.5px; padding: 3px 8px; border-radius: 999px; font-weight: 600; flex-shrink: 0; }
.ck-camp-status.active { background: #ECFDF5; color: #065F46; }
.ck-camp-status.paused { background: #F3F4F6; color: #6B7280; }
.ck-fld { margin-bottom: 14px; }
.ck-fld label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.04em; }
.ck-fld input, .ck-fld select, .ck-fld textarea { width: 100%; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px; font-family: inherit; box-sizing: border-box; }
.ck-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; }
.ck-camp-hero { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; margin-bottom: 20px; flex-wrap: wrap; }
.ck-camp-hero .ck-pg-title { display: inline-flex; align-items: center; gap: 10px; }
.ck-deadline { font-size: 12.5px; color: #92400E; background: #FEF3C7; padding: 4px 10px; border-radius: 6px; display: inline-block; margin-top: 6px; }
.ck-actions-row { display: flex; gap: 8px; }
.ck-tiers-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; margin-bottom: 22px; }
.ck-tier-card { background: linear-gradient(135deg, #fff, #F0FDF4); border: 1px solid #D1FAE5; border-radius: 10px; padding: 14px; text-align: center; }
.ck-tier-name { font-size: 13px; font-weight: 600; color: #065F46; }
.ck-tier-amount { font-size: 22px; font-weight: 700; color: #111827; margin: 4px 0; }
.ck-tier-desc { font-size: 11.5px; color: #6b7280; }
.ck-add-pay { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 18px; margin-bottom: 22px; }
.ck-add-pay summary { cursor: pointer; font-weight: 600; color: #065F46; font-size: 14px; }
.ck-add-pay[open] summary { margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid #f3f4f6; }
.ck-pay-form { padding-top: 4px; }
.ck-section { font-size: 14px; color: #065F46; margin: 16px 0 10px; padding-bottom: 6px; border-bottom: 1px solid #d1fae5; }
.ck-empty-mini { background: #fff; border: 1px dashed #e5e7eb; border-radius: 10px; padding: 24px; text-align: center; color: #6b7280; font-size: 13px; }
.ck-pay-list { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: auto; }
.ck-pay-tbl { width: 100%; border-collapse: collapse; }
.ck-pay-tbl th { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; text-align: left; padding: 10px 12px; border-bottom: 1px solid #f3f4f6; background: #fafbfc; font-weight: 700; }
.ck-pay-tbl td { padding: 12px; font-size: 13px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
.ck-pay-tbl tr:last-child td { border-bottom: 0; }
.ck-pay-name { font-weight: 600; color: #111827; }
.ck-pay-email { font-size: 11.5px; color: #6b7280; }
.ck-pay-amount { font-weight: 700; color: #10B981; white-space: nowrap; }
.ck-pay-date { color: #6b7280; white-space: nowrap; }
.ck-badge { font-size: 11px; padding: 3px 8px; border-radius: 999px; font-weight: 600; }
.ck-tbl-btn { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; padding: 4px 10px; border-radius: 6px; font-size: 11.5px; font-weight: 600; cursor: pointer; font-family: inherit; }
.ck-tbl-btn:hover { background: #D1FAE5; }
.ck-tbl-btn-danger { background: #FEF2F2; color: #B91C1C; border-color: #FECACA; }
.ck-tbl-btn-danger:hover { background: #FEE2E2; }
.ck-pay-acts { white-space: nowrap; }
.ck-pay-acts form + form { margin-left: 4px; }
.ck-stripe-card { background: linear-gradient(135deg, #fafbff, #f0fdf4); border: 1px solid #d1fae5; border-radius: 12px; padding: 16px 20px; margin-top: 22px; }
.ck-stripe-head { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.ck-stripe-icon { font-size: 28px; }
.ck-stripe-title { font-size: 14px; font-weight: 700; color: #111827; }
.ck-stripe-sub { font-size: 12.5px; color: #6b7280; margin-top: 2px; }
.ck-stripe-head .ck-btn-ghost { margin-left: auto; }
.ck-online-body { display: grid; gap: 14px; margin-top: 14px; padding-top: 14px; border-top: 1px solid #d1fae5; }
.ck-online-link { display: flex; gap: 8px; flex-wrap: wrap; }
.ck-online-link input, .ck-online-link select { flex: 1; min-width: 180px; padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; font-family: inherit; background: #fff; }
.ck-online-qr { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; }
.ck-online-qr canvas { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 6px; }
.ck-online-member label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 5px; }
.ck-kpi-sub { font-size: 11px; color: #6b7280; margin-top: 2px; }
@media (max-width: 540px) { .ck-pay-tbl thead { display: none; } .ck-pay-tbl, .ck-pay-tbl tbody, .ck-pay-tbl tr, .ck-pay-tbl td { display: block; width: 100%; box-sizing: border-box; } .ck-pay-tbl tr { padding: 10px 14px; border-bottom: 1px solid #f3f4f6; } .ck-pay-tbl td { padding: 3px 0; border: 0; } }
</style>
<script>
// Auto-fill payeur depuis adhérent / tarif
(function() {
  const adh = document.getElementById('ck-adh-select');
  const tier = document.getElementById('ck-tier-select');
  const name = document.getElementById('ck-payer-name');
  const email = document.getElementById('ck-payer-email');
  const amount = document.getElementById('ck-amount');
  if (adh) adh.addEventListener('change', function() {
    const opt = adh.options[adh.selectedIndex];
    if (opt.value && !name.value) name.value = opt.dataset.name || '';
    if (opt.value && !email.value) email.value = opt.dataset.email || '';
  });
  if (tier) tier.addEventListener('change', function() {
    const opt = tier.options[tier.selectedIndex];
    if (opt.value && opt.dataset.amount && !amount.value) amount.value = opt.dataset.amount;
  });
  // Double clic : un seul envoi (le serveur refuse aussi un second envoi du même formulaire)
  const form = document.getElementById('ck-pay-form');
  if (form) form.addEventListener('submit', function() {
    const btn = form.querySelector('button[type=submit]');
    if (btn) { btn.disabled = true; btn.textContent = 'Enregistrement…'; }
  });
  // Grande association : recherche côté serveur au lieu d'une liste complète
  const search = document.getElementById('ck-adh-search');
  if (search && adh) {
    let timer = null, seq = 0;
    search.addEventListener('input', function() {
      clearTimeout(timer);
      timer = setTimeout(function() {
        const q = search.value.trim();
        if (q.length < 2) return;
        const my = ++seq;
        fetch(location.pathname + '?adh_q=' + encodeURIComponent(q), {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
          .then(function(r) { return r.json(); })
          .then(function(d) {
            if (my !== seq || !d || !d.items) return;
            const keep = adh.value;
            while (adh.options.length > 1) adh.remove(1);
            d.items.forEach(function(it) {
              const o = new Option(it.name + (it.email ? ' · ' + it.email : ''), it.id);
              o.dataset.name = it.name; o.dataset.email = it.email || '';
              adh.add(o);
            });
            if (!d.items.length) { const none = new Option('Aucun adhérent trouvé', ''); none.disabled = true; adh.add(none); }
            const only = d.items.length === 1 ? String(d.items[0].id) : '';
            adh.value = d.items.some(function(it) { return String(it.id) === keep; }) ? keep : only;
            if (adh.value !== keep) adh.dispatchEvent(new Event('change'));
          })
          .catch(function() {});
      }, 250);
    });
  }
})();
// Paiement en ligne : copie des liens, QR code du lien de la campagne, lien personnel d'un adhérent
(function() {
  document.querySelectorAll('[data-copy]').forEach(function(b) {
    b.addEventListener('click', function() {
      const i = document.getElementById(b.getAttribute('data-copy'));
      if (!i || !i.value) return;
      i.select();
      (navigator.clipboard ? navigator.clipboard.writeText(i.value) : Promise.reject()).catch(function() { try { document.execCommand('copy'); } catch (e) {} });
      const t = b.textContent; b.textContent = 'Copié ✓'; setTimeout(function() { b.textContent = t; }, 1600);
    });
  });
  const url = document.getElementById('ck-pay-url'), cv = document.getElementById('ck-qr'), dl = document.getElementById('ck-qr-dl');
  if (url && cv) {
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js';
    s.onload = function() {
      if (!window.QRCode) return;
      QRCode.toCanvas(cv, url.value, { width: 160, margin: 1 }, function() { if (dl) dl.href = cv.toDataURL('image/png'); });
    };
    document.head.appendChild(s);
  }
  const sel = document.getElementById('ck-member-link'), mu = document.getElementById('ck-member-url');
  if (sel && mu) sel.addEventListener('change', function() {
    mu.value = '';
    if (!sel.value) return;
    fetch(location.pathname + '?lien_adherent=' + encodeURIComponent(sel.value), {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
      .then(function(r) { return r.json(); }).then(function(d) { if (d && d.ok) mu.value = d.url; }).catch(function() {});
  });
})();
</script>
<?= render_foot() ?>
