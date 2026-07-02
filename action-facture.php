<?php
/**
 * ============================================================
 * ASSOKIT — Action : gérer les factures d'un projet
 * ============================================================
 * Actions gérées :
 *   - add      : ajout d'une nouvelle facture (avec ou sans PDF)
 *   - validate : un admin/coord valide une facture "en attente"
 *   - reject   : un admin/coord rejette une facture
 *   - delete   : suppression (admin uniquement)
 *
 * À chaque changement de statut, le budget_used du projet est recalculé.
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /projets');
    exit;
}

if (!check_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    die('Session expirée.');
}

$user = current_user();
$org_id = (int)$user['org_id'];
$action = $_POST['action'] ?? 'add';
$project_id = (int)($_POST['project_id'] ?? 0);

if ($project_id <= 0) {
    header('Location: /projets');
    exit;
}

// Vérifier que le projet appartient à l'organisation
$stmt = $pdo->prepare("
    SELECT p.id, p.referent_id FROM projects p
    JOIN folders f ON p.folder_id = f.id
    WHERE p.id = ? AND f.org_id = ?
");
$stmt->execute([$project_id, $org_id]);
$project = $stmt->fetch();
if (!$project) {
    http_response_code(403);
    die('Accès refusé à ce projet.');
}

// Permissions
$is_admin = ($user['role'] === 'admin');
$is_coord = ($user['role'] === 'coordinator');
$is_referent = ($project['referent_id'] == $user['id']);
$can_validate = $is_admin || $is_coord;
$can_delete = $is_admin;

// Helper : logger dans project_activity_log
function log_invoice_activity($project_id, $user_id, $action_type, $action_label) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO project_activity_log (project_id, user_id, action_type, action_label, ip_address)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $project_id, $user_id, $action_type, $action_label,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Exception $e) {
        // Silent fail
    }
}

// =========================================================
// ACTION : AJOUTER UNE FACTURE
// =========================================================
if ($action === 'add') {
    $supplier = trim($_POST['supplier_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $invoice_date = $_POST['invoice_date'] ?? '';

    // Logique de calcul HT / TVA / TTC — 3 modes de saisie
    //   'ttc'     : user tape TTC + taux TVA → on calcule HT
    //   'ht'      : user tape HT + taux TVA → on calcule TTC
    //   'no_vat'  : un seul montant (asso non assujettie à la TVA)
    $mode = $_POST['amount_mode'] ?? 'ttc';
    $vat_rate = (float)str_replace(',', '.', $_POST['vat_rate'] ?? '20');

    $amount_ttc = 0.0;
    $amount_ht = 0.0;

    if ($mode === 'ht') {
        $amount_ht = (float)str_replace([',', ' '], ['.', ''], $_POST['amount_ht'] ?? '0');
        $amount_ttc = round($amount_ht * (1 + $vat_rate / 100), 2);
    } elseif ($mode === 'no_vat') {
        $amount_ttc = (float)str_replace([',', ' '], ['.', ''], $_POST['amount_ttc'] ?? '0');
        $amount_ht = $amount_ttc;
        $vat_rate = 0;
    } else {
        $amount_ttc = (float)str_replace([',', ' '], ['.', ''], $_POST['amount_ttc'] ?? '0');
        $amount_ht = round($amount_ttc / (1 + $vat_rate / 100), 2);
    }

    $amount = $amount_ttc; // compatibilité avec le reste du code

    // Validation de base
    if ($supplier === '' || $amount_ttc <= 0 || !$invoice_date) {
        header('Location: /projet/' . $project_id . '/factures?err=invalid');
        exit;
    }

    // Upload éventuel du PDF
    $file_path = null;

    // Cas 1 : le fichier vient du scan IA (stocké en session)
    if (!empty($_POST['temp_file']) && !empty($_SESSION['invoice_scan_result'])
        && $_SESSION['invoice_scan_result']['temp_file'] === $_POST['temp_file']) {
        $temp_relative = $_SESSION['invoice_scan_result']['temp_file'];
        $temp_full = __DIR__ . '/' . $temp_relative;

        if (file_exists($temp_full)) {
            // On le déplace du dossier temp au dossier définitif
            $final_dir = __DIR__ . '/uploads/projet_' . $project_id . '/factures';
            if (!is_dir($final_dir)) mkdir($final_dir, 0755, true);

            $ext = pathinfo($temp_full, PATHINFO_EXTENSION);
            $original = $_SESSION['invoice_scan_result']['original_name'] ?? 'facture.' . $ext;
            $safe_name = preg_replace('/[^a-zA-Z0-9._\-]/', '_', pathinfo($original, PATHINFO_FILENAME));
            $final_name = 'facture_' . substr($safe_name, 0, 40) . '_' . time() . '.' . $ext;
            $final_path = $final_dir . '/' . $final_name;

            if (rename($temp_full, $final_path)) {
                $file_path = 'uploads/projet_' . $project_id . '/factures/' . $final_name;
            }
        }
        // On nettoie la session
        unset($_SESSION['invoice_scan_result']);
    }
    // Cas 2 : upload classique via le formulaire
    elseif (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['invoice_file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png'];

        if (!in_array($ext, $allowed_exts, true)) {
            header('Location: /projet/' . $project_id . '/factures?err=filetype');
            exit;
        }
        if ($f['size'] > 10 * 1024 * 1024) {
            header('Location: /projet/' . $project_id . '/factures?err=filesize');
            exit;
        }

        $uploads_dir = __DIR__ . '/uploads/projet_' . $project_id . '/factures';
        if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0755, true);

        $safe_name = preg_replace('/[^a-zA-Z0-9._\-]/', '_', pathinfo($f['name'], PATHINFO_FILENAME));
        $stored_name = 'facture_' . substr($safe_name, 0, 40) . '_' . time() . '.' . $ext;
        $stored_path = $uploads_dir . '/' . $stored_name;

        if (move_uploaded_file($f['tmp_name'], $stored_path)) {
            $file_path = 'uploads/projet_' . $project_id . '/factures/' . $stored_name;
        }
    }

    // Détermine le statut selon la règle : admin/coord → auto-validée, autre → en attente
    if ($can_validate) {
        $status = 'validated';
        $validated_by = $user['id'];
        $validated_at_clause = 'NOW()';
    } else {
        $status = 'pending';
        $validated_by = null;
        $validated_at_clause = 'NULL';
    }

    // Insertion (avec HT, TVA et TTC)
    $stmt = $pdo->prepare("
        INSERT INTO project_invoices
            (project_id, uploaded_by, supplier_name, category, description,
             amount_ht, vat_rate, amount_ttc, invoice_date, file_path, status, validated_by, validated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, $validated_at_clause)
    ");
    $stmt->execute([
        $project_id, $user['id'], $supplier, $category ?: null, $description ?: null,
        $amount_ht, $vat_rate, $amount_ttc, $invoice_date, $file_path, $status, $validated_by,
    ]);

    recompute_project_budget($pdo, $project_id);

    // Log dans l'historique du projet
    $amount_str = number_format($amount_ttc, 2, ',', ' ') . ' €';
    log_invoice_activity($project_id, $user['id'], 'invoice_added',
        'a ajouté une facture de ' . $supplier . ' (' . $amount_str . ' TTC)');

    header('Location: /projet/' . $project_id . '/factures?added=1');
    exit;
}

// =========================================================
// ACTION : VALIDER UNE FACTURE
// =========================================================
if ($action === 'validate') {
    if (!$can_validate) {
        http_response_code(403);
        die('Seul un admin ou coordinateur peut valider une facture.');
    }
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);

    $stmt = $pdo->prepare("
        UPDATE project_invoices SET status = 'validated', validated_by = ?, validated_at = NOW()
        WHERE id = ? AND project_id = ?
    ");
    $stmt->execute([$user['id'], $invoice_id, $project_id]);

    recompute_project_budget($pdo, $project_id);

    // Log
    $inv_stmt = $pdo->prepare("SELECT supplier_name, amount_ttc FROM project_invoices WHERE id = ?");
    $inv_stmt->execute([$invoice_id]);
    if ($inv = $inv_stmt->fetch()) {
        log_invoice_activity($project_id, $user['id'], 'invoice_updated',
            'a validé la facture de ' . $inv['supplier_name'] . ' (' . number_format((float)$inv['amount_ttc'], 2, ',', ' ') . ' €)');
    }

    header('Location: /projet/' . $project_id . '/factures?validated=1');
    exit;
}

// =========================================================
// ACTION : REJETER UNE FACTURE
// =========================================================
if ($action === 'reject') {
    if (!$can_validate) {
        http_response_code(403);
        die('Seul un admin ou coordinateur peut rejeter une facture.');
    }
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);

    // Récupérer les infos pour le log AVANT le changement de statut
    $inv_stmt = $pdo->prepare("SELECT supplier_name, amount_ttc FROM project_invoices WHERE id = ?");
    $inv_stmt->execute([$invoice_id]);
    $inv_info = $inv_stmt->fetch();

    $stmt = $pdo->prepare("
        UPDATE project_invoices SET status = 'rejected', validated_by = ?, validated_at = NOW()
        WHERE id = ? AND project_id = ?
    ");
    $stmt->execute([$user['id'], $invoice_id, $project_id]);

    recompute_project_budget($pdo, $project_id);

    if ($inv_info) {
        log_invoice_activity($project_id, $user['id'], 'invoice_updated',
            'a rejeté la facture de ' . $inv_info['supplier_name'] . ' (' . number_format((float)$inv_info['amount_ttc'], 2, ',', ' ') . ' €)');
    }

    header('Location: /projet/' . $project_id . '/factures?rejected=1');
    exit;
}

// =========================================================
// ACTION : SUPPRIMER UNE FACTURE
// =========================================================
if ($action === 'delete') {
    if (!$can_delete) {
        http_response_code(403);
        die('Seul un admin peut supprimer une facture.');
    }
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);

    // Récupérer les infos pour le log AVANT la suppression
    $stmt = $pdo->prepare("SELECT file_path, supplier_name, amount_ttc FROM project_invoices WHERE id = ? AND project_id = ?");
    $stmt->execute([$invoice_id, $project_id]);
    $inv = $stmt->fetch();

    if ($inv && $inv['file_path']) {
        $full_path = __DIR__ . '/' . $inv['file_path'];
        if (file_exists($full_path)) @unlink($full_path);
    }

    $pdo->prepare("DELETE FROM project_invoices WHERE id = ? AND project_id = ?")
        ->execute([$invoice_id, $project_id]);

    recompute_project_budget($pdo, $project_id);

    if ($inv) {
        log_invoice_activity($project_id, $user['id'], 'invoice_deleted',
            'a supprimé la facture de ' . $inv['supplier_name'] . ' (' . number_format((float)$inv['amount_ttc'], 2, ',', ' ') . ' €)');
    }

    header('Location: /projet/' . $project_id . '/factures?deleted=1');
    exit;
}

// =========================================================
// ACTION : RECLASSER (poste comptable) UNE FACTURE
// =========================================================
if ($action === 'set_account') {
    $can_set = $can_validate || $is_referent;
    if (!$can_set) { http_response_code(403); die('Action non autorisée.'); }
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $ov = trim($_POST['account_override'] ?? '');
    require_once __DIR__ . '/pca-mapping.php';
    if ($ov !== '') {
        $comptes = ak_pca_comptes();
        if (!isset($comptes[$ov])) $ov = '';
    }
    $val = ($ov === '') ? null : $ov;
    $pdo->prepare("UPDATE project_invoices SET account_override = ? WHERE id = ? AND project_id = ?")
        ->execute([$val, $invoice_id, $project_id]);
    $inv_stmt = $pdo->prepare("SELECT supplier_name FROM project_invoices WHERE id = ? AND project_id = ?");
    $inv_stmt->execute([$invoice_id, $project_id]);
    if ($inv = $inv_stmt->fetch()) {
        $lbl = ($val === null) ? 'classement automatique' : ('compte ' . $val);
        log_invoice_activity($project_id, $user['id'], 'invoice_updated',
            'a reclassé la facture de ' . $inv['supplier_name'] . ' (' . $lbl . ')');
    }
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $safe = false;
    if ($ref !== '') {
        if (strpos($ref, 'https://assokit.fr/') === 0) $safe = true;
        elseif ($ref[0] === '/' && (strlen($ref) < 2 || $ref[1] !== '/')) $safe = true;
    }
    header('Location: ' . ($safe ? $ref : '/factures?reclassed=1'));
    exit;
}

header('Location: /projet/' . $project_id . '/factures');
exit;

// =========================================================
// HELPER : Recalculer le budget utilisé du projet
// Appelé après chaque changement qui affecte le total
// =========================================================
function recompute_project_budget($pdo, $project_id) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount_ttc), 0) AS total
        FROM project_invoices
        WHERE project_id = ? AND status = 'validated'
    ");
    $stmt->execute([$project_id]);
    $total = (float)$stmt->fetchColumn();

    $pdo->prepare("UPDATE projects SET budget_used = ? WHERE id = ?")
        ->execute([$total, $project_id]);
}
