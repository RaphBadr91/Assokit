<?php
/**
 * mon-asso-facture-edit.php
 * Édition d'une facture existante
 * v2 — Sous-pack 2 : ajout bouton "Envoyer par email" + lien public + statut envoi
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-invoice-helpers.php';

require_login();
$user = current_user();

if (empty($user['org_id'])) { http_response_code(403); die('Aucune asso.'); }
$org_id = (int)$user['org_id'];

// [PACK 6.5 - SECURITY] Accès finances obligatoire
require_once __DIR__ . '/finance-permissions.php';
require_billing_access('factures', 'l\'édition de factures');

$invoice_id = (int)($_GET['id'] ?? 0);
if ($invoice_id <= 0) { header('Location: /mon-asso-factures-client'); exit; }

// Charger facture. Le client est lu à part : avec « i.*, c.* », ses colonnes homonymes
// (internal_notes, created_at, id…) écrasaient celles de la facture, et l'enregistrement
// remplaçait les notes internes de la facture par celles du client.
$stmt = $pdo->prepare("SELECT * FROM asso_invoices WHERE id = :id AND org_id = :org LIMIT 1");
$stmt->execute([':id' => $invoice_id, ':org' => $org_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invoice) { http_response_code(404); die('Facture introuvable.'); }

$client = [];
if (!empty($invoice['client_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM asso_clients WHERE id = :id AND org_id = :org LIMIT 1");
    $stmt->execute([':id' => (int)$invoice['client_id'], ':org' => $org_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
if (!$client) {
    // Fiche client absente : coordonnées figées à l'émission
    $snap = json_decode((string)($invoice['client_snapshot'] ?? ''), true);
    $client = is_array($snap) ? $snap : [];
}
$client_email = (string)($client['email'] ?? '');

// Encaissement : facture émise non réglée (paiement éventuellement déclaré par le client)
$can_mark_paid = in_array((string)$invoice['status'], ['pending', 'overdue'], true);
$declared = $can_mark_paid && !empty($invoice['client_marked_paid_at']);
$pay_methods = ak_asso_payment_methods();
// Date de règlement indiquée par le client (historique des règlements), sinon date de sa déclaration
$declared_date = $declared ? date('Y-m-d', strtotime($invoice['client_marked_paid_at'])) : '';
if ($declared) {
    try {
        $stmt = $pdo->prepare("SELECT paid_at FROM asso_invoice_payments WHERE invoice_id = ? AND source = 'client' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$invoice_id]);
        $d = (string)$stmt->fetchColumn();
        if ($d !== '' && strtotime($d)) $declared_date = date('Y-m-d', strtotime($d));
    } catch (Throwable $e) {}
}

// Lignes
$stmt = $pdo->prepare("SELECT * FROM asso_invoice_lines WHERE invoice_id = :id ORDER BY line_order");
$stmt->execute([':id' => $invoice_id]);
$lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Logs emails (Sous-pack 2)
$emails_log = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM asso_invoice_emails_log WHERE invoice_id = :id ORDER BY id DESC LIMIT 10");
    $stmt->execute([':id' => $invoice_id]);
    $emails_log = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$flash = $_SESSION['flash_asso_factures'] ?? null;
unset($_SESSION['flash_asso_factures']);

$public_link = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr') . '/facture/' . $invoice['public_uuid'];

// Facture finalisée : inaltérable (art. 289 CGI), affichée en lecture seule ; une erreur se
// corrige par un avoir. Brouillon : modifiable, envoi possible une fois finalisé.
$status      = (string)$invoice['status'];
$is_draft    = $status === 'draft';
$is_credit   = ak_asso_is_credit_note($invoice);
$parent      = null;
if ($is_credit && !empty($invoice['parent_invoice_id'])) {
    $stmt = $pdo->prepare("SELECT id, invoice_number, issued_at FROM asso_invoices WHERE id = :id AND org_id = :org LIMIT 1");
    $stmt->execute([':id' => (int)$invoice['parent_invoice_id'], ':org' => $org_id]);
    $parent = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$credit_note = !$is_credit && !$is_draft ? ak_asso_invoice_credit_note_of($pdo, $org_id, $invoice_id) : null;
$can_credit  = !$is_credit && !$credit_note && in_array($status, ['pending', 'overdue', 'paid'], true);
$doc_label   = $is_credit ? 'l’avoir' : 'la facture';

render_head(($is_draft ? 'Modifier ' : ($is_credit ? 'Avoir ' : 'Facture ')) . $invoice['invoice_number']);
render_sidebar('factures');
?>

<div class="main">
    <nav class="crumbs">
        <a href="/mon-asso-factures-client">Mes factures</a>
        <span class="sep">›</span>
        <span class="current"><?= $is_draft ? 'Modifier ' : ($is_credit ? 'Avoir ' : 'Facture ') ?><?= h($invoice['invoice_number']) ?></span>
    </nav>

    <div class="main-head">
        <div>
            <h1 class="page-title" style="display:flex;align-items:center;gap:11px;"><?= ak_icon_badge('edit','#059669',36) ?><span><?= h($invoice['invoice_number']) ?></span></h1>
            <div class="page-sub">
                <?php if ($is_credit): ?>
                    <strong>Avoir</strong><?php if ($parent): ?> · annule la facture <a href="/mon-asso-facture-edit?id=<?= (int)$parent['id'] ?>" style="color:#059669; font-weight:600;"><?= h($parent['invoice_number']) ?></a><?php endif; ?>
                <?php else: ?>
                    Statut : <strong><?= h(ak_asso_invoice_status_label($invoice['status'])) ?></strong>
                    <?php if ($credit_note): ?>
                        · annulée par l’avoir <a href="/mon-asso-facture-edit?id=<?= (int)$credit_note['id'] ?>" style="color:#059669; font-weight:600;"><?= h($credit_note['invoice_number']) ?></a>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($invoice['sent_at'])): ?>
                    · 📧 Envoyée le <?= h(date('d/m/Y H:i', strtotime($invoice['sent_at']))) ?>
                <?php endif; ?>
                <?php if (!empty($invoice['view_count']) && (int)$invoice['view_count'] > 0): ?>
                    · 👁 Vue <?= (int)$invoice['view_count'] ?> fois
                <?php endif; ?>
                <?php if (!empty($invoice['client_marked_paid_at'])): ?>
                    · <span style="color:#10B981; font-weight:600;">💚 Client a déclaré avoir payé le <?= h(date('d/m/Y', strtotime($invoice['client_marked_paid_at']))) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <?php if (!empty($invoice['pdf_path'])): ?>
                <a href="<?= h($invoice['pdf_path']) ?>" target="_blank" class="btn btn-ghost"><?= ak_icon('inbox',14) ?>PDF</a>
            <?php endif; ?>
            <?php if (!$is_credit): ?>
            <a href="/mon-asso-recurrence-new?from_invoice=<?= (int)$invoice_id ?>"
               class="btn btn-ghost"
               title="Créer une récurrence à partir de cette facture (auto-génération mensuelle, trimestrielle…)"
               style="border-color:#059669; color:#059669;">
                <?= ak_icon('refresh',14) ?>Rendre récurrente
            </a>
            <form method="POST" action="/mon-asso-facture-duplicate" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="invoice_id" value="<?= (int)$invoice_id ?>">
                <button type="submit" class="btn btn-ghost" title="Créer un brouillon avec le même client et les mêmes lignes (nouveau numéro)"><?= ak_icon('clipboard',14) ?>Dupliquer</button>
            </form>
            <?php endif; ?>
            <a href="/mon-asso-factures-client" class="btn btn-ghost">← Retour</a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:16px;">
        <?= h($flash['message']) ?>
        <?php if (!empty($flash['pdf_link'])): ?>
            — <a href="<?= h($flash['pdf_link']) ?>" target="_blank" style="font-weight:600;">📥 Télécharger</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ────── BLOC ENVOI EMAIL ────── -->
    <div class="card" style="padding:22px; margin-bottom:16px; background:linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%); border-left:4px solid #10B981;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
            <div>
                <h3 style="margin:0 0 4px 0; font-size:15px; color:#065F46;">📧 Envoi de <?= $doc_label ?></h3>
                <div style="font-size:13px; color:#047857;">
                    <?php if ($is_draft): ?>
                        Brouillon : finalisez la facture (en bas de page) pour pouvoir l’envoyer à <strong><?= h($client_email !== '' ? $client_email : '?') ?></strong>.
                    <?php elseif (empty($invoice['sent_at'])): ?>
                        Pas encore envoyé<?= $is_credit ? '' : 'e' ?> à <strong><?= h($client_email !== '' ? $client_email : '?') ?></strong>
                    <?php else: ?>
                        Envoyé<?= $is_credit ? '' : 'e' ?> le <strong><?= h(date('d/m/Y H:i', strtotime($invoice['sent_at']))) ?></strong> à <strong><?= h($invoice['sent_to_email'] ?? $client_email) ?></strong>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="<?= h($public_link) ?>" target="_blank" class="btn btn-ghost" style="background:white;"><?= ak_icon('link',14) ?><?= $is_draft ? 'Aperçu de la page publique' : 'Voir page publique' ?></a>
                <?php
                // Envoi : jamais un brouillon ; une facture annulée n'est plus envoyée ; une facture
                // payée peut être renvoyée en copie (pas de rappel de paiement).
                $send_type = null;
                if ($is_credit) {
                    $send_type = 'credit_note';
                    $send_label = empty($invoice['sent_at']) ? '📤 Envoyer l’avoir par email' : '🔁 Renvoyer l’avoir';
                } elseif (in_array($status, ['pending', 'overdue'], true)) {
                    $send_type = empty($invoice['sent_at']) ? 'initial' : 'manual';
                    $send_label = empty($invoice['sent_at']) ? '📤 Envoyer la facture par email' : '🔁 Renvoyer (rappel)';
                } elseif ($status === 'paid' && !$credit_note) {
                    $send_type = 'initial';
                    $send_label = empty($invoice['sent_at']) ? '📤 Envoyer la facture par email' : '📤 Renvoyer une copie';
                }
                ?>
                <?php if ($send_type): ?>
                <form method="POST" action="/mon-asso-facture-send" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="invoice_id" value="<?= (int)$invoice_id ?>">
                    <input type="hidden" name="email_type" value="<?= h($send_type) ?>">
                    <button type="submit" class="btn btn-primary" style="padding:9px 18px;"><?= $send_label ?></button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Lien public partageable -->
        <div style="margin-top:14px; padding:10px 12px; background:white; border-radius:8px; display:flex; align-items:center; gap:10px; font-size:12px;">
            <span style="color:#6B7280;">🔗 Lien à partager :</span>
            <code id="public-link-text" style="flex:1; font-family:monospace; color:#059669; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= h($public_link) ?></code>
            <button type="button" onclick="copyLink()" class="btn btn-ghost" style="padding:4px 10px; font-size:11px;"><?= ak_icon('clipboard',13) ?>Copier</button>
        </div>
    </div>

    <!-- ────── ENCAISSEMENT ────── -->
    <?php if ($can_mark_paid): ?>
    <div class="card" id="encaissement" style="padding:22px; margin-bottom:16px; border-left:4px solid <?= $declared ? '#10B981' : '#F59E0B' ?>;">
        <h3 style="margin:0 0 4px 0; font-size:15px;">💶 Encaissement</h3>
        <?php if ($declared): ?>
            <div style="font-size:13px; color:#065F46; margin-bottom:12px;">
                Le client déclare avoir payé le <strong><?= h(date('d/m/Y', strtotime($declared_date))) ?></strong><?php
                if (!empty($invoice['client_paid_method'])): ?> (<?= h($pay_methods[$invoice['client_paid_method']] ?? $invoice['client_paid_method']) ?><?php
                    if (!empty($invoice['client_paid_reference'])): ?>, réf. <?= h($invoice['client_paid_reference']) ?><?php endif; ?>)<?php endif; ?>.
                Vérifiez la réception du règlement, puis confirmez-le.
                <?php if (!empty($invoice['client_paid_note'])): ?>
                    <div style="margin-top:4px; color:#374151;">Note du client : « <?= h($invoice['client_paid_note']) ?> »</div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div style="font-size:13px; color:#6B7280; margin-bottom:12px;">Règlement reçu ? Enregistrez-le : la facture passe en « Payée » et les relances s’arrêtent.</div>
        <?php endif; ?>
        <form method="POST" action="/mon-asso-facture-save" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;"
              onsubmit="return confirm('Marquer la facture <?= h($invoice['invoice_number']) ?> comme payée ?');">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="mark_paid">
            <input type="hidden" name="invoice_id" value="<?= (int)$invoice_id ?>">
            <div>
                <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Date du règlement</label>
                <input type="date" name="paid_at" required max="<?= date('Y-m-d') ?>"
                       value="<?= h($declared ? min($declared_date, date('Y-m-d')) : date('Y-m-d')) ?>"
                       style="padding:9px 10px; border:1px solid #E5E7EB; border-radius:8px;">
            </div>
            <div>
                <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Moyen de paiement</label>
                <select name="payment_method" required style="padding:9px 10px; border:1px solid #E5E7EB; border-radius:8px;">
                    <option value="">— Choisir —</option>
                    <?php foreach ($pay_methods as $k => $lbl): ?>
                        <option value="<?= h($k) ?>" <?= ($declared && ($invoice['client_paid_method'] ?? '') === $k) ? 'selected' : '' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1; min-width:160px;">
                <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Référence (optionnel)</label>
                <input type="text" name="payment_reference" maxlength="255" placeholder="N° de virement, de chèque…"
                       value="<?= h($declared ? ($invoice['client_paid_reference'] ?? '') : '') ?>"
                       style="width:100%; padding:9px 10px; border:1px solid #E5E7EB; border-radius:8px;">
            </div>
            <button type="submit" class="btn btn-primary" style="padding:9px 18px;">✅ Marquer comme payée</button>
        </form>
    </div>
    <?php elseif ((string)$invoice['status'] === 'paid' && !empty($invoice['paid_at'])): ?>
    <div class="card" style="padding:14px 22px; margin-bottom:16px; border-left:4px solid #10B981; font-size:13px; color:#065F46;">
        💶 Payée le <strong><?= h(date('d/m/Y', strtotime($invoice['paid_at']))) ?></strong><?php
        if (!empty($invoice['payment_method']) && isset($pay_methods[$invoice['payment_method']])): ?> · <?= h($pay_methods[$invoice['payment_method']]) ?><?php endif;
        if (!empty($invoice['payment_reference'])): ?> · réf. <?= h($invoice['payment_reference']) ?><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ────── HISTORIQUE EMAILS ────── -->
    <?php if (!empty($emails_log)): ?>
    <details class="card" style="padding:0; margin-bottom:16px; overflow:hidden;">
        <summary style="padding:14px 22px; cursor:pointer; font-size:14px; font-weight:600; background:#F9FAFB; display:flex; align-items:center; gap:8px;">
            <?= ak_icon('send',16) ?>Historique des envois (<?= count($emails_log) ?>)
        </summary>
        <table style="width:100%; border-collapse:collapse;">
            <thead style="background:#F9FAFB;">
                <tr>
                    <th style="text-align:left; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Date</th>
                    <th style="text-align:left; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Type</th>
                    <th style="text-align:left; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Destinataire</th>
                    <th style="text-align:center; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($emails_log as $log):
                    $statusColors = [
                        'sent' => ['#D1FAE5', '#065F46', '✅ Envoyé'],
                        'queued' => ['#FEF3C7', '#92400E', '⏳ En attente'],
                        'failed' => ['#FEE2E2', '#991B1B', '❌ Échec'],
                        'bounced' => ['#FEE2E2', '#991B1B', '↩ Rejeté'],
                    ];
                    $col = $statusColors[$log['status']] ?? ['#E5E7EB', '#374151', $log['status']];
                    $type_labels = [
                        'initial' => '📤 Envoi initial',
                        'dunning1' => '🔔 Relance 1',
                        'dunning2' => '🔔 Relance 2',
                        'dunning3' => '⚠ Mise en demeure',
                        'manual' => '✋ Manuel',
                        'credit_note' => '↩ Avoir',
                    ];
                ?>
                <tr style="border-top:1px solid #E5E7EB;">
                    <td style="padding:10px 14px; font-size:13px;"><?= h(date('d/m/Y H:i', strtotime($log['sent_at'] ?? $log['created_at']))) ?></td>
                    <td style="padding:10px 14px; font-size:13px;"><?= h($type_labels[$log['email_type']] ?? $log['email_type']) ?></td>
                    <td style="padding:10px 14px; font-size:13px; color:#6B7280;"><?= h($log['recipient_email']) ?></td>
                    <td style="padding:10px 14px; text-align:center;">
                        <span style="display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; background:<?= $col[0] ?>; color:<?= $col[1] ?>;">
                            <?= h($col[2]) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </details>
    <?php endif; ?>

    <!-- ────── AVOIR (facture émise : annulation) ────── -->
    <?php if ($can_credit): ?>
    <details class="card" style="padding:0; margin-bottom:16px; overflow:hidden; border-left:4px solid #DC2626;">
        <summary style="padding:14px 22px; cursor:pointer; font-size:14px; font-weight:600; display:flex; align-items:center; gap:8px;">
            ↩ Annuler cette facture (créer un avoir)
        </summary>
        <div style="padding:0 22px 18px;">
            <p style="font-size:13px; color:#4B5563; margin:0 0 12px; line-height:1.55;">
                Une facture émise ne se modifie pas. Pour corriger une erreur (montant, client, doublon), émettez un avoir : il reprend
                les lignes de la facture en négatif, porte son propre numéro et annule la facture<?= $status === 'paid' ? '' : ', qui n’est alors plus relancée' ?>.
                Créez ensuite, si besoin, une nouvelle facture correcte.
                <?php if ($status === 'paid'): ?>
                    <br><strong>Cette facture est payée :</strong> l’avoir enregistre le remboursement de <?= h(ak_asso_fmt_cents((int)$invoice['amount_ttc_cents'])) ?> au client à la date du jour. Effectuez ce remboursement.
                <?php endif; ?>
            </p>
            <form method="POST" action="/mon-asso-facture-save" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;"
                  onsubmit="return confirm(<?= h(json_encode('Créer un avoir de ' . ak_asso_fmt_cents((int)$invoice['amount_ttc_cents']) . ' annulant la facture ' . $invoice['invoice_number'] . ' ? Cette opération est définitive.', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>);">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="credit_note">
                <input type="hidden" name="invoice_id" value="<?= (int)$invoice_id ?>">
                <div style="flex:1; min-width:220px;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Motif (imprimé sur l’avoir, optionnel)</label>
                    <input type="text" name="reason" maxlength="300" placeholder="Ex. : erreur de montant, facture en double…" style="width:100%; padding:9px 10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <button type="submit" class="btn" style="padding:9px 18px; background:#DC2626; color:#fff; border:none;">Créer l’avoir</button>
            </form>
        </div>
    </details>
    <?php endif; ?>

    <?php if (!$is_draft):
        // ────── FACTURE FINALISÉE / AVOIR : LECTURE SEULE ──────
        // Coordonnées figées à l'émission (celles du PDF), sinon fiche actuelle
        $snap = json_decode((string)($invoice['client_snapshot'] ?? ''), true);
        $cl = is_array($snap) && !empty($snap['display_name']) ? $snap : $client;
        $fmt_qty = fn($q) => rtrim(rtrim(number_format((float)$q, 2, ',', ' '), '0'), ',');
    ?>
    <div class="card" style="padding:14px 22px; margin-bottom:16px; background:#F8FAFC; font-size:13px; color:#475569;">
        🔒 <?= $is_credit ? 'Cet avoir est émis' : 'Cette facture est finalisée' ?> : <?= $is_credit ? 'il' : 'elle' ?> ne peut plus être modifié<?= $is_credit ? '' : 'e' ?>.
        <?php if (!$is_credit && !$credit_note && $can_credit): ?>Pour la corriger, créez un avoir ci-dessus.<?php endif; ?>
    </div>

    <div class="card" style="padding:22px; margin-bottom:16px;">
        <h3 style="margin:0 0 12px 0; font-size:15px; display:inline-flex; align-items:center; gap:8px;"><?= ak_icon('user',18) ?>Client</h3>
        <div style="font-size:14px; line-height:1.6;">
            <strong><?= h($cl['display_name'] ?? '') ?></strong>
            <?php if (!empty($cl['legal_name']) && $cl['legal_name'] !== ($cl['display_name'] ?? '')): ?> · <?= h($cl['legal_name']) ?><?php endif; ?><br>
            <?php if (!empty($cl['email'])): ?><?= h($cl['email']) ?><br><?php endif; ?>
            <?php if (!empty($cl['address_street'])): ?><?= h($cl['address_street']) ?><?php if (!empty($cl['address_complement'])): ?>, <?= h($cl['address_complement']) ?><?php endif; ?><br><?php endif; ?>
            <?php if (!empty($cl['address_zip']) || !empty($cl['address_city'])): ?><?= h(trim(($cl['address_zip'] ?? '') . ' ' . ($cl['address_city'] ?? ''))) ?><br><?php endif; ?>
            <?php if (!empty($cl['siren'])): ?><span style="color:#6B7280;">SIREN <?= h($cl['siren']) ?></span><?php endif; ?>
        </div>
        <?php if (!empty($invoice['client_id'])): ?>
            <div style="margin-top:10px; font-size:12px;"><a href="/mon-asso-client-detail?id=<?= (int)$invoice['client_id'] ?>" style="color:#059669;">Voir ou modifier la fiche client →</a></div>
        <?php endif; ?>
    </div>

    <div class="card" style="padding:0; margin-bottom:16px; overflow:hidden;">
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
            <thead style="background:#F9FAFB;">
                <tr>
                    <th style="text-align:left; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Désignation</th>
                    <th style="text-align:right; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Qté</th>
                    <th style="text-align:right; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">PU HT</th>
                    <th style="text-align:right; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">TVA</th>
                    <th style="text-align:right; padding:10px 14px; font-size:11px; color:#6B7280; text-transform:uppercase;">Total HT</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $l): ?>
                <tr style="border-top:1px solid #F3F4F6;">
                    <td style="padding:10px 14px;"><?= h($l['designation']) ?></td>
                    <td style="padding:10px 14px; text-align:right;"><?= h($fmt_qty($l['quantity'])) ?></td>
                    <td style="padding:10px 14px; text-align:right; font-variant-numeric:tabular-nums;"><?= h(ak_asso_fmt_cents((int)$l['unit_price_ht_cents'])) ?></td>
                    <td style="padding:10px 14px; text-align:right;"><?= ($l['vat_rate'] !== null && (float)$l['vat_rate'] > 0) ? h(number_format((float)$l['vat_rate'], 1, ',', ' ')) . ' %' : '—' ?></td>
                    <td style="padding:10px 14px; text-align:right; font-variant-numeric:tabular-nums;"><?= h(ak_asso_fmt_cents((int)$l['total_ht_cents'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:14px; padding:16px 22px; background:#F9FAFB; border-top:1px solid #E5E7EB;">
            <div><div style="font-size:11px; color:#6B7280;">Total HT</div><div style="font-size:18px; font-weight:700; font-variant-numeric:tabular-nums;"><?= h(ak_asso_fmt_cents((int)$invoice['amount_ht_cents'])) ?></div></div>
            <div><div style="font-size:11px; color:#6B7280;">TVA</div><div style="font-size:18px; font-weight:700; font-variant-numeric:tabular-nums;"><?= h(ak_asso_fmt_cents((int)$invoice['amount_vat_cents'])) ?></div></div>
            <div><div style="font-size:11px; color:#6B7280;">Total TTC</div><div style="font-size:18px; font-weight:700; color:#059669; font-variant-numeric:tabular-nums;"><?= h(ak_asso_fmt_cents((int)$invoice['amount_ttc_cents'])) ?></div></div>
        </div>
    </div>

    <div class="card" style="padding:22px; margin-bottom:16px; font-size:13px; line-height:1.7;">
        <div><span style="color:#6B7280;">Émis<?= $is_credit ? '' : 'e' ?> le</span> <strong><?= h(date('d/m/Y', strtotime($invoice['issued_at']))) ?></strong>
            <?php if (!$is_credit): ?> · <span style="color:#6B7280;">échéance</span> <strong><?= h(date('d/m/Y', strtotime($invoice['due_at']))) ?></strong><?php endif; ?></div>
        <?php if (!empty($invoice['description'])): ?><div><span style="color:#6B7280;">Note :</span> <?= h($invoice['description']) ?></div><?php endif; ?>
        <?php if (!empty($invoice['internal_notes'])): ?><div><span style="color:#6B7280;">Notes internes :</span> <?= h($invoice['internal_notes']) ?></div><?php endif; ?>
    </div>
    <?php else: ?>

    <!-- ────── FORMULAIRE ÉDITION (brouillon) ────── -->
    <form method="POST" action="/mon-asso-facture-save" id="invoice-edit-form">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="invoice_id" value="<?= (int)$invoice_id ?>">
        <input type="hidden" name="client_id" value="<?= (int)$invoice['client_id'] ?>">

        <!-- Client -->
        <div class="card" style="padding:22px; margin-bottom:16px;">
            <h3 style="margin:0 0 16px 0; font-size:15px; display:inline-flex; align-items:center; gap:8px;"><?= ak_icon('user',18) ?>Client</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Type</label>
                    <select name="client_type" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                        <option value="company" <?= ($client['client_type'] ?? 'company') === 'company' ? 'selected' : '' ?>>Entreprise / Asso</option>
                        <option value="individual" <?= ($client['client_type'] ?? '') === 'individual' ? 'selected' : '' ?>>Particulier</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Nom affiché *</label>
                    <input type="text" name="display_name" value="<?= h($client['display_name'] ?? '') ?>" required style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Email *</label>
                    <input type="email" name="email" value="<?= h($client['email'] ?? '') ?>" required style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                    <div style="font-size:11px; color:#9CA3AF; margin-top:4px;">Un autre e-mail rattache la facture à un autre client. Pour corriger l’e-mail de ce client, modifiez sa fiche.</div>
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Téléphone</label>
                    <input type="text" name="phone" value="<?= h($client['phone'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div style="grid-column:1/-1;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Raison sociale</label>
                    <input type="text" name="legal_name" value="<?= h($client['legal_name'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Prénom contact</label>
                    <input type="text" name="contact_first_name" value="<?= h($client['contact_first_name'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Nom contact</label>
                    <input type="text" name="contact_last_name" value="<?= h($client['contact_last_name'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>

                <!-- Adresse avec autocomplétion -->
                <div style="grid-column:1/-1; position:relative;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">
                        Adresse <span style="color:#10B981;">— autocomplétion FR active</span>
                    </label>
                    <input type="text" name="address_street" id="address_street" value="<?= h($client['address_street'] ?? '') ?>" placeholder="N° + rue" autocomplete="off" oninput="searchAddress(this.value)" onblur="setTimeout(hideAddressList, 200)" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; margin-bottom:6px;">
                    <div id="address-suggestions" style="display:none; position:absolute; top:80px; left:0; right:0; background:white; border:1px solid #E5E7EB; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1); z-index:1000; max-height:300px; overflow-y:auto;"></div>
                    <input type="text" name="address_complement" value="<?= h($client['address_complement'] ?? '') ?>" placeholder="Complément" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">CP</label>
                    <input type="text" name="address_zip" id="address_zip" value="<?= h($client['address_zip'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Ville</label>
                    <input type="text" name="address_city" id="address_city" value="<?= h($client['address_city'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div style="grid-column:1/-1;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">SIREN</label>
                    <input type="text" name="siren" value="<?= h($client['siren'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
            </div>
        </div>

        <!-- Lignes -->
        <div class="card" style="padding:22px; margin-bottom:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                <h3 style="margin:0; font-size:15px;">📦 Désignations</h3>
                <button type="button" onclick="addLine()" class="btn btn-ghost" style="padding:6px 12px; font-size:13px;">+ Ajouter une ligne</button>
            </div>
            <div id="lines-container"></div>
        </div>

        <!-- Récap -->
        <div class="card" style="padding:18px; margin-bottom:16px; background:#F9FAFB;">
            <h3 style="margin:0 0 12px 0; font-size:14px; color:#6B7280; text-transform:uppercase; display:inline-flex; align-items:center; gap:8px;"><?= ak_icon('chart',16) ?>Total</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px;">
                <div>
                    <div style="font-size:11px; color:#6B7280;">Total HT</div>
                    <div id="total-ht" style="font-size:20px; font-weight:700; font-variant-numeric:tabular-nums;">—</div>
                </div>
                <div>
                    <div style="font-size:11px; color:#6B7280;">TVA</div>
                    <div id="total-vat" style="font-size:20px; font-weight:700; font-variant-numeric:tabular-nums;">—</div>
                </div>
                <div style="background:#10B981; padding:10px 14px; border-radius:8px; margin:-10px -14px;">
                    <div style="font-size:11px; color:#D1FAE5;">⭐ Total TTC</div>
                    <div id="total-ttc" style="font-size:20px; font-weight:700; color:#fff; font-variant-numeric:tabular-nums;">—</div>
                </div>
            </div>
        </div>

        <!-- Dates -->
        <div class="card" style="padding:22px; margin-bottom:16px;">
            <h3 style="margin:0 0 16px 0; font-size:15px; display:inline-flex; align-items:center; gap:8px;"><?= ak_icon('calendar',18) ?>Dates et statut</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px;">
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Date d'émission</label>
                    <input type="date" name="issued_at" value="<?= h(date('Y-m-d', strtotime($invoice['issued_at']))) ?>" required style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Échéance (jours)</label>
                    <input type="number" name="due_days" value="<?= max(1, (int) floor((strtotime($invoice['due_at']) - strtotime($invoice['issued_at'])) / 86400)) ?>" min="0" max="365" required style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Statut actuel</label>
                    <input type="text" value="<?= h(ak_asso_invoice_status_label($invoice['status'])) ?>" disabled style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; background:#F9FAFB;">
                </div>
                <div style="grid-column:1/-1;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Description</label>
                    <textarea name="description" rows="2" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; resize:vertical; font-family:inherit;"><?= h($invoice['description'] ?? '') ?></textarea>
                </div>
                <div style="grid-column:1/-1;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Notes internes</label>
                    <textarea name="internal_notes" rows="2" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; resize:vertical; font-family:inherit;"><?= h($invoice['internal_notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Actions : un brouillon reste brouillon tant qu'il n'est pas finalisé -->
        <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap; padding:18px; background:white; border:1px solid #E5E7EB; border-radius:12px; position:sticky; bottom:12px;">
            <a href="/mon-asso-factures-client" class="btn btn-ghost">Annuler</a>
            <button type="submit" name="save_draft" value="1" class="btn btn-ghost" style="padding:10px 20px;">💾 Enregistrer le brouillon</button>
            <button type="submit" name="finalize" value="1" class="btn btn-primary" style="padding:10px 20px;"
                    onclick="return confirm('Finaliser la facture <?= h($invoice['invoice_number']) ?> ? Une fois finalisée, elle ne pourra plus être modifiée (correction par avoir uniquement).');">📤 Finaliser la facture</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
<?= ak_asso_invoice_form_js() ?>

const VAT_OPTIONS = [
    {value: '', label: 'Pas de TVA'},
    {value: '5.5', label: 'TVA 5,5%'},
    {value: '10', label: 'TVA 10%'},
    {value: '20', label: 'TVA 20%'},
];
const EXISTING_LINES = <?= json_encode(array_map(function($l){
    return [
        'designation' => $l['designation'],
        'quantity'    => (float)$l['quantity'],
        'unit_price_ht' => round($l['unit_price_ht_cents'] / 100, 2),
        // DECIMAL renvoyé en chaîne (« 20.00 ») : converti en nombre pour retrouver l'option « 20 »
        'vat_rate'    => $l['vat_rate'] === null ? null : (float)$l['vat_rate'],
    ];
}, $lines)) ?>;
let lineIdx = 0;

function addLine(prefill = {}) {
    const idx = lineIdx++;
    const vatVal = prefill.vat_rate !== null && prefill.vat_rate !== undefined ? prefill.vat_rate : '';
    const vatOpts = VAT_OPTIONS.map(o => `<option value="${o.value}" ${akVatSelected(o.value, vatVal) ? 'selected' : ''}>${o.label}</option>`).join('');
    const html = `
        <div class="invoice-line" data-idx="${idx}" style="display:grid; grid-template-columns: 1fr 70px 100px 120px 100px 30px; gap:8px; padding:10px 0; border-bottom:1px solid #F3F4F6;">
            <input type="text" name="lines[${idx}][designation]" placeholder="Désignation" required value="${(prefill.designation || '').replace(/"/g,'&quot;')}" style="padding:8px 10px; border:1px solid #E5E7EB; border-radius:6px; font-size:13px;">
            <input type="number" name="lines[${idx}][quantity]" step="0.01" min="0" value="${prefill.quantity || '1'}" oninput="recalc()" style="padding:8px 10px; border:1px solid #E5E7EB; border-radius:6px; font-size:13px; text-align:right;">
            <input type="number" name="lines[${idx}][unit_price_ht]" step="0.01" min="0" placeholder="PU HT" value="${prefill.unit_price_ht || ''}" oninput="recalc()" required style="padding:8px 10px; border:1px solid #E5E7EB; border-radius:6px; font-size:13px; text-align:right;">
            <select name="lines[${idx}][vat_rate]" onchange="recalc()" style="padding:8px 10px; border:1px solid #E5E7EB; border-radius:6px; font-size:13px;">${vatOpts}</select>
            <div class="line-total" style="padding:8px 10px; background:#F9FAFB; border-radius:6px; font-size:13px; text-align:right; font-weight:600; font-variant-numeric:tabular-nums;">0,00 €</div>
            <button type="button" onclick="this.closest('.invoice-line').remove(); recalc();" style="background:transparent; border:none; color:#DC2626; cursor:pointer; font-size:18px;">×</button>
        </div>
    `;
    document.getElementById('lines-container').insertAdjacentHTML('beforeend', html);
    recalc();
}

// Totaux en centimes, arrondis ligne par ligne comme la facture enregistrée
function recalc() {
    let totHT = 0, totVAT = 0;
    document.querySelectorAll('.invoice-line').forEach(line => {
        const q = parseFloat(line.querySelector('[name*="[quantity]"]').value) || 0;
        const p = parseFloat(line.querySelector('[name*="[unit_price_ht]"]').value) || 0;
        const r = parseFloat(line.querySelector('[name*="[vat_rate]"]').value) || 0;
        const c = akLineCents(q, p, r);
        line.querySelector('.line-total').textContent = (c.ht / 100).toFixed(2).replace('.', ',') + ' €';
        totHT += c.ht;
        totVAT += c.vat;
    });
    const fmt = cents => (cents / 100).toFixed(2).replace('.', ',') + ' €';
    document.getElementById('total-ht').textContent = fmt(totHT);
    document.getElementById('total-vat').textContent = fmt(totVAT);
    document.getElementById('total-ttc').textContent = fmt(totHT + totVAT);
}

// AUTOCOMPLÉTION ADRESSE FRANÇAISE
let addressTimer = null;
function searchAddress(query) {
    clearTimeout(addressTimer);
    if (!query || query.length < 3) { hideAddressList(); return; }
    addressTimer = setTimeout(() => {
        fetch('https://api-adresse.data.gouv.fr/search/?q=' + encodeURIComponent(query) + '&limit=5&autocomplete=1')
            .then(r => r.json())
            .then(data => {
                const list = document.getElementById('address-suggestions');
                if (!data.features || data.features.length === 0) { hideAddressList(); return; }
                list.innerHTML = data.features.map(f => {
                    const p = f.properties;
                    return `
                        <div onclick="selectAddress('${(p.name || '').replace(/'/g, "\\'")}', '${p.postcode || ''}', '${(p.city || '').replace(/'/g, "\\'")}')"
                             style="padding:10px 14px; cursor:pointer; border-bottom:1px solid #F3F4F6; font-size:13px;"
                             onmouseover="this.style.background='#F0FDF4'"
                             onmouseout="this.style.background='white'">
                            <div style="color:#059669; font-size:12px;">📍</div>
                            <div style="font-weight:600; color:#111827;">${p.name || ''}</div>
                            <div style="color:#6B7280; font-size:12px;">${p.postcode || ''} ${p.city || ''}</div>
                        </div>
                    `;
                }).join('');
                list.style.display = 'block';
            })
            .catch(() => hideAddressList());
    }, 250);
}
function selectAddress(street, zip, city) {
    document.getElementById('address_street').value = street;
    document.getElementById('address_zip').value = zip;
    document.getElementById('address_city').value = city;
    hideAddressList();
}
function hideAddressList() {
    document.getElementById('address-suggestions').style.display = 'none';
}

// COPY LIEN PUBLIC
function copyLink() {
    const text = document.getElementById('public-link-text').textContent;
    navigator.clipboard.writeText(text).then(() => {
        alert('✅ Lien copié dans le presse-papier !');
    }, () => {
        alert('Impossible de copier. Sélectionne le texte manuellement.');
    });
}

// Init (brouillon uniquement : une facture finalisée est affichée en lecture seule)
if (document.getElementById('invoice-edit-form')) {
    EXISTING_LINES.forEach(l => addLine(l));
    if (EXISTING_LINES.length === 0) addLine();
    akGuardSubmit(document.getElementById('invoice-edit-form'), 'Enregistrement…');
}
</script>

<?php render_foot(); ?>
