<?php
/**
 * mon-asso-factures-parametres.php
 * Paramètres de facturation par asso :
 * - Templates emails personnalisables (initial + 3 relances)
 * - IBAN/BIC/Banque
 * - Délai par défaut
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/asso-invoice-helpers.php';

require_login();
$user = current_user();
if (empty($user['org_id'])) { http_response_code(403); die('Aucune asso.'); }
$org_id = (int)$user['org_id'];

$is_admin = false;
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
    $stmt->execute([':id' => (int)$user['id']]);
    $is_admin = in_array($stmt->fetchColumn(), ['admin', 'coordinator'], true);
} catch (Throwable $e) {}
if (!$is_admin) { http_response_code(403); die('Accès refusé.'); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Traitement POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(419); exit('CSRF');
    }

    try {
        // Garantit qu'il y a une ligne
        ak_asso_invoice_settings($pdo, $org_id);

        $pdo->prepare("
            UPDATE org_invoice_settings SET
                default_due_days = :due,
                bank_iban = :iban, bank_bic = :bic, bank_name = :bank,
                email_invoice_subject = :s0, email_invoice_body = :b0,
                email_relance1_subject = :s1, email_relance1_body = :b1,
                email_relance2_subject = :s2, email_relance2_body = :b2,
                email_relance3_subject = :s3, email_relance3_body = :b3
            WHERE org_id = :org
        ")->execute([
            ':due'  => max(0, (int)($_POST['default_due_days'] ?? 30)),
            ':iban' => trim($_POST['bank_iban'] ?? '') ?: null,
            ':bic'  => trim($_POST['bank_bic'] ?? '') ?: null,
            ':bank' => trim($_POST['bank_name'] ?? '') ?: null,
            ':s0'   => trim($_POST['email_invoice_subject'] ?? '') ?: null,
            ':b0'   => trim($_POST['email_invoice_body'] ?? '') ?: null,
            ':s1'   => trim($_POST['email_relance1_subject'] ?? '') ?: null,
            ':b1'   => trim($_POST['email_relance1_body'] ?? '') ?: null,
            ':s2'   => trim($_POST['email_relance2_subject'] ?? '') ?: null,
            ':b2'   => trim($_POST['email_relance2_body'] ?? '') ?: null,
            ':s3'   => trim($_POST['email_relance3_subject'] ?? '') ?: null,
            ':b3'   => trim($_POST['email_relance3_body'] ?? '') ?: null,
            ':org'  => $org_id,
        ]);

        $_SESSION['flash_asso_params'] = ['type' => 'success', 'message' => '✅ Paramètres enregistrés.'];
    } catch (Throwable $e) {
        $_SESSION['flash_asso_params'] = ['type' => 'error', 'message' => 'Erreur : ' . $e->getMessage()];
    }
    header('Location: /mon-asso-factures-parametres'); exit;
}

$settings = ak_asso_invoice_settings($pdo, $org_id);
$flash = $_SESSION['flash_asso_params'] ?? null;
unset($_SESSION['flash_asso_params']);

// Templates par défaut (pour placeholder)
$defaults = [
    'email_invoice_subject' => '{NOM_ASSO} — Votre facture {NUMERO}',
    'email_invoice_body' => "Bonjour {NOM_CLIENT},\n\nNous vous adressons ci-joint la facture {NUMERO} d'un montant de {MONTANT_TTC}.\n\nÉchéance : {DATE_ECHEANCE}\n\nLien direct : {LIEN_PUBLIC}\n\nCordialement,\n{NOM_ASSO}",
    'email_relance1_subject' => 'Relance — Facture {NUMERO}',
    'email_relance1_body' => "Bonjour {NOM_CLIENT},\n\nVotre facture {NUMERO} d'un montant de {MONTANT_TTC} reste impayée.\n\nLien direct : {LIEN_PUBLIC}\n\nCordialement,\n{NOM_ASSO}",
    'email_relance2_subject' => '2e RELANCE — Facture {NUMERO}',
    'email_relance2_body' => "Bonjour {NOM_CLIENT},\n\nMalgré notre précédente relance, votre facture {NUMERO} ({MONTANT_TTC}) reste impayée.\n\nMerci de procéder au règlement rapidement.\n\n{LIEN_PUBLIC}\n\nCordialement,\n{NOM_ASSO}",
    'email_relance3_subject' => 'MISE EN DEMEURE — Facture {NUMERO}',
    'email_relance3_body' => "Bonjour {NOM_CLIENT},\n\nVotre facture {NUMERO} ({MONTANT_TTC}) est impayée depuis plus de 45 jours.\n\nSans règlement sous 8 jours, nous engagerons les procédures de recouvrement.\n\n{LIEN_PUBLIC}\n\nCordialement,\n{NOM_ASSO}",
];

render_head('Paramètres factures');
render_sidebar('factures');
?>

<div class="main">
    <nav class="crumbs">
        <a href="/mon-asso-factures">Mes factures</a>
        <span class="sep">›</span>
        <span class="current">Paramètres</span>
    </nav>

    <div class="main-head">
        <div>
            <h1 class="page-title">⚙ Paramètres facturation</h1>
            <div class="page-sub">IBAN, délais et templates d'emails</div>
        </div>
        <div>
            <a href="/mon-asso-factures" class="btn btn-ghost">← Retour</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:16px;">
            <?= h($flash['message']) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="/mon-asso-factures-parametres">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

        <!-- Délais et bancaire -->
        <div class="card" style="padding:22px; margin-bottom:16px;">
            <h3 style="margin:0 0 16px 0; font-size:15px;">💳 Coordonnées bancaires & délais</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Délai d'échéance par défaut (jours)</label>
                    <input type="number" name="default_due_days" value="<?= h($settings['default_due_days'] ?? 30) ?>" min="0" max="365" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Banque</label>
                    <input type="text" name="bank_name" value="<?= h($settings['bank_name'] ?? '') ?>" placeholder="ex: Crédit Mutuel" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px;">
                </div>
                <div style="grid-column:1/-1;">
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">IBAN</label>
                    <input type="text" name="bank_iban" value="<?= h($settings['bank_iban'] ?? '') ?>" placeholder="FR76 1027 8060 4000 0203 6354 022" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; font-family:monospace;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">BIC / SWIFT</label>
                    <input type="text" name="bank_bic" value="<?= h($settings['bank_bic'] ?? '') ?>" placeholder="CMCIFR2A" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; font-family:monospace;">
                </div>
            </div>
        </div>

        <!-- Variables disponibles -->
        <div class="card" style="padding:18px; margin-bottom:16px; background:#EFF6FF; border-left:4px solid #3B82F6;">
            <div style="font-weight:600; color:#1E3A8A; margin-bottom:8px;">📌 Variables utilisables dans les emails :</div>
            <div style="display:flex; flex-wrap:wrap; gap:6px; font-size:12px;">
                <?php foreach (['{NOM_CLIENT}','{NUMERO}','{MONTANT_TTC}','{DATE_EMISSION}','{DATE_ECHEANCE}','{NOM_ASSO}','{LIEN_PUBLIC}','{IBAN}'] as $var): ?>
                    <code style="background:white; padding:3px 8px; border-radius:4px; color:#1E40AF; border:1px solid #DBEAFE;"><?= h($var) ?></code>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 4 templates -->
        <?php
        $tpl_blocks = [
            ['initial', '📧 Email initial (envoi de la facture)', '#10B981'],
            ['relance1', '🔔 Relance 1 (J+15)', '#F59E0B'],
            ['relance2', '🔔 Relance 2 (J+30)', '#EA580C'],
            ['relance3', '⚠ Mise en demeure (J+45)', '#DC2626'],
        ];
        foreach ($tpl_blocks as [$key, $label, $color]):
            $col_subj = $key === 'initial' ? 'email_invoice_subject' : 'email_' . $key . '_subject';
            $col_body = $key === 'initial' ? 'email_invoice_body' : 'email_' . $key . '_body';
        ?>
        <div class="card" style="padding:22px; margin-bottom:16px;">
            <h3 style="margin:0 0 16px 0; font-size:15px; color:<?= $color ?>;"><?= h($label) ?></h3>
            <div>
                <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Sujet</label>
                <input type="text" name="<?= h($col_subj) ?>" value="<?= h($settings[$col_subj] ?? '') ?>" placeholder="<?= h($defaults[$col_subj] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; margin-bottom:10px;">
            </div>
            <div>
                <label style="display:block; font-size:12px; color:#6B7280; margin-bottom:6px;">Corps</label>
                <textarea name="<?= h($col_body) ?>" rows="8" placeholder="<?= h($defaults[$col_body] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #E5E7EB; border-radius:8px; font-family:inherit; resize:vertical;"><?= h($settings[$col_body] ?? '') ?></textarea>
            </div>
            <div style="font-size:11px; color:#6B7280; margin-top:6px;">
                Laisse vide pour utiliser le template par défaut (visible en placeholder).
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Actions -->
        <div style="display:flex; gap:10px; justify-content:flex-end; padding:18px; background:white; border:1px solid #E5E7EB; border-radius:12px; position:sticky; bottom:12px;">
            <a href="/mon-asso-factures" class="btn btn-ghost">Annuler</a>
            <button type="submit" class="btn btn-primary" style="padding:10px 20px;">💾 Enregistrer</button>
        </div>
    </form>
</div>

<?php render_foot(); ?>
