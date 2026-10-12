<?php
/**
 * /action-cotisation-payment - Enregistrer / mettre à jour un paiement
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity-tracker.php';  // journal fondateur : qui a fait quoi, à la minute près
activity_log_request();
require_once __DIR__ . '/includes-cotisations.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
$is_admin = ($user['role'] === 'admin'); $is_coord = ($user['role'] === 'coordinator');
if (!$is_admin && !$is_coord) { http_response_code(403); die('Accès refusé.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /cotisations'); exit; }
if (!check_csrf($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_cotisation'] = ['type' => 'error', 'message' => 'Session expirée : rechargez la page puis recommencez.'];
    header('Location: /cotisations?error=csrf'); exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'create') {
    $campaign_id = (int)($_POST['campaign_id'] ?? 0);
    $campaign = ck_load_campaign($pdo, $campaign_id, $org_id);
    if (!$campaign) {
        $_SESSION['flash_cotisation'] = ['type' => 'error', 'message' => 'Campagne introuvable.'];
        header('Location: /cotisations?error=notfound'); exit;
    }
    $tier_id = (int)($_POST['tier_id'] ?? 0) ?: null;
    $adherent_id = (int)($_POST['adherent_id'] ?? 0) ?: null;
    // Identifiants secondaires : doivent appartenir à cette association
    if ($tier_id) {   // la formule doit appartenir à cette campagne
        $ck = $pdo->prepare("SELECT id FROM cotisation_tiers WHERE id = ? AND campaign_id = ?");
        $ck->execute([$tier_id, $campaign_id]);
        if (!$ck->fetchColumn()) $tier_id = null;
    }
    if ($adherent_id) {
        $ck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND org_id = ?");
        $ck->execute([$adherent_id, $org_id]);
        if (!$ck->fetchColumn()) $adherent_id = null;
    }
    $payer_name = trim($_POST['payer_name'] ?? '');
    $payer_email = trim($_POST['payer_email'] ?? '') ?: null;
    $amount = (float)str_replace(',', '.', $_POST['amount'] ?? 0);
    $method = in_array($_POST['payment_method'] ?? '', ['stripe','bank','check','cash','other']) ? $_POST['payment_method'] : 'other';
    $status = in_array($_POST['status'] ?? '', ['paid','pending','refunded','cancelled']) ? $_POST['status'] : 'paid';
    $reference = trim($_POST['reference'] ?? '') ?: null;
    $notes = trim($_POST['notes'] ?? '') ?: null;
    // Date du paiement (paiement « payé ») : même contrôle que l'appli. Une date impossible,
    // future ou de plus de 5 ans est refusée (les KPI sont rattachés à paid_at).
    $paid_at = trim((string)($_POST['paid_at'] ?? ''));
    $date_error = null;
    if ($status === 'paid' && $paid_at !== '') {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $paid_at, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            $date_error = 'Date du paiement invalide : utilisez JJ/MM/AAAA.';
        } elseif ($paid_at > date('Y-m-d', strtotime('+1 day'))) {
            $date_error = 'La date du paiement ne peut pas être dans le futur.';
        } elseif ($paid_at < date('Y-m-d', strtotime('-5 years'))) {
            $date_error = 'Date du paiement trop ancienne (5 ans au plus).';
        }
    }
    $paid_at_full = $status === 'paid' ? ($paid_at !== '' ? $paid_at : date('Y-m-d')) . ' ' . date('H:i:s') : null;

    if ($payer_name === '' || $amount <= 0 || $date_error) {
        // Saisie conservée : le formulaire se rouvre prérempli avec le message d'erreur.
        $_SESSION['ck_pay_form'] = [
            'campaign_id' => $campaign_id,
            'adherent_id' => (int)$adherent_id, 'tier_id' => (int)$tier_id,
            'payer_name' => $payer_name, 'payer_email' => (string)$payer_email,
            'amount' => trim((string)($_POST['amount'] ?? '')), 'payment_method' => $method, 'status' => $status,
            'reference' => (string)$reference, 'paid_at' => (string)$paid_at, 'notes' => (string)$notes,
        ];
        $_SESSION['flash_cotisation'] = ['type' => 'error', 'message' => $payer_name === ''
            ? 'Indiquez le nom du payeur.'
            : ($amount <= 0 ? 'Le montant doit être supérieur à zéro.' : $date_error)];
        header('Location: /cotisation/' . $campaign_id . '?error=invalid'); exit;
    }

    // Double clic sur « Enregistrer le paiement » : le second envoi ne crée pas de doublon.
    // Contrôlé après la saisie : un envoi refusé ci-dessus peut être corrigé puis renvoyé.
    if (!ck_form_nonce_consume($_POST['form_nonce'] ?? null)) {
        $_SESSION['flash_cotisation'] = ['type' => 'error', 'message' => 'Ce paiement vient déjà d’être enregistré : il n’a pas été ajouté une seconde fois.'];
        header('Location: /cotisation/' . $campaign_id); exit;
    }

    $stmt = $pdo->prepare("INSERT INTO cotisation_payments (campaign_id, tier_id, org_id, adherent_id, payer_name, payer_email, amount, currency, payment_method, status, reference, notes, paid_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$campaign_id, $tier_id, $org_id, $adherent_id, $payer_name, $payer_email, $amount, $campaign['currency'], $method, $status, $reference, $notes, $paid_at_full, (int)$user['id']]);

    // Cotisation payée : l'adhérent est à jour (plus de relance, « À jour » dans l'appli).
    $msg = 'Paiement de ' . $payer_name . ' enregistré.';
    if ($status === 'paid' && $adherent_id) {
        $until = ck_extend_membership($pdo, $org_id, $adherent_id, $campaign, $paid_at_full);
        if ($until) {
            $msg .= ' Adhésion valable jusqu’au ' . date('d/m/Y', strtotime($until)) . '.';
        } elseif (ck_campaign_renews_membership($campaign) && ck_membership_end($campaign, $paid_at_full) === null) {
            $msg .= ' Adhésion non prolongée : la campagne était close à la date du paiement.';
        }
    }
    $_SESSION['flash_cotisation'] = ['type' => 'success', 'message' => $msg];
    header('Location: /cotisation/' . $campaign_id . '?paid=1');
    exit;
}

if ($action === 'mark_paid') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, campaign_id, adherent_id, payer_name FROM cotisation_payments WHERE id=? AND org_id=?");
    $stmt->execute([$id, $org_id]);
    $p = $stmt->fetch();
    if (!$p) {
        $_SESSION['flash_cotisation'] = ['type' => 'error', 'message' => 'Paiement introuvable.'];
        header('Location: /cotisations?error=notfound'); exit;
    }
    // Seul un paiement « en attente » s'encaisse (comme dans l'appli) : un paiement annulé
    // ou remboursé ne revient pas dans les encaissements.
    $stmt = $pdo->prepare("UPDATE cotisation_payments SET status='paid', paid_at=NOW() WHERE id=? AND org_id=? AND status='pending'");
    $stmt->execute([$id, $org_id]);
    if ($stmt->rowCount() > 0) {
        $msg = 'Paiement de ' . $p['payer_name'] . ' encaissé.';
        $campaign = ck_load_campaign($pdo, (int)$p['campaign_id'], $org_id);
        if ($campaign && $p['adherent_id']) {
            $until = ck_extend_membership($pdo, $org_id, (int)$p['adherent_id'], $campaign, date('Y-m-d'));
            if ($until) $msg .= ' Adhésion valable jusqu’au ' . date('d/m/Y', strtotime($until)) . '.';
        }
        $_SESSION['flash_cotisation'] = ['type' => 'success', 'message' => $msg];
    } else {
        $_SESSION['flash_cotisation'] = ['type' => 'error', 'message' => 'Ce paiement n’est plus en attente : il n’a pas été modifié.'];
    }
    header('Location: /cotisation/' . (int)$p['campaign_id']);
    exit;
}

// Annuler un paiement saisi par erreur (doublon, mauvais montant) : admin seul, comme dans l'appli.
// Le paiement reste dans l'historique avec le statut « Annulé » et sort des montants encaissés.
if ($action === 'cancel') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, campaign_id, adherent_id, payer_name, status FROM cotisation_payments WHERE id=? AND org_id=?");
    $stmt->execute([$id, $org_id]);
    $p = $stmt->fetch();
    if (!$p) {
        $_SESSION['flash_cotisation'] = ['type' => 'error', 'message' => 'Paiement introuvable.'];
        header('Location: /cotisations?error=notfound'); exit;
    }
    if (!$is_admin) {
        $_SESSION['flash_cotisation'] = ['type' => 'error', 'message' => 'Seul un administrateur peut annuler un paiement.'];
    } elseif ((string)$p['status'] === 'cancelled') {
        $_SESSION['flash_cotisation'] = ['type' => 'error', 'message' => 'Ce paiement est déjà annulé.'];
    } else {
        $stmt = $pdo->prepare("UPDATE cotisation_payments SET status='cancelled' WHERE id=? AND org_id=? AND status <> 'cancelled'");
        $stmt->execute([$id, $org_id]);
        $msg = 'Paiement de ' . $p['payer_name'] . ' annulé.';
        // L'annulation ne raccourcit pas l'adhésion (cf. ck_extend_membership) : on le signale,
        // pour une campagne d'adhésion seulement (une participation aux frais ne l'a pas prolongée).
        $campaign = ck_load_campaign($pdo, (int)$p['campaign_id'], $org_id);
        if ((string)$p['status'] === 'paid' && $p['adherent_id'] && $campaign && ck_campaign_renews_membership($campaign)) {
            $st = $pdo->prepare("SELECT adhesion_valid_until FROM users WHERE id = ? AND org_id = ?");
            $st->execute([(int)$p['adherent_id'], $org_id]);
            $until = (string)($st->fetchColumn() ?: '');
            if ($until !== '' && strpos($until, '0000-00-00') !== 0) {
                $msg .= ' L’adhésion reste valable jusqu’au ' . date('d/m/Y', strtotime($until)) . ' : corrigez-la sur la fiche de l’adhérent si besoin.';
            }
        }
        $_SESSION['flash_cotisation'] = ['type' => 'success', 'message' => $msg];
    }
    header('Location: /cotisation/' . (int)$p['campaign_id']);
    exit;
}

header('Location: /cotisations');
