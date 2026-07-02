<?php
/**
 * SNIPPETS À INSÉRER DANS LES PAGES EXISTANTES
 * --------------------------------------------------------------
 * Ce fichier contient des EXEMPLES de code à copier-coller
 * dans tes pages d'action existantes pour activer les blocages.
 *
 * Ne PAS uploader ce fichier — c'est juste un guide.
 * --------------------------------------------------------------
 */

// ============================================================
// 📋 SNIPPET 1 — Blocage IA texte (STRICT)
// ============================================================
//
// Fichier concerné : mon-asso-ia-execute.php (ou équivalent)
//
// Insère ce code TOUT EN HAUT du script, juste après les require_once :

require_once __DIR__ . '/plan-helpers.php';

$check_ia = ak_can_use_ai_text($pdo, (int)$_SESSION['user_org_id']);
if (!$check_ia['ok']) {
    // Retour JSON si appel API
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'json') !== false)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode([
            'error' => 'quota_exceeded',
            'reason' => $check_ia['reason'],
            'limit' => $check_ia['limit'],
            'current' => $check_ia['current'],
            'upgrade_url' => '/mon-asso-plan',
        ]);
        exit;
    }
    // Sinon redirection avec message
    $_SESSION['flash_error'] = '🚫 ' . $check_ia['reason'] . '. Passez au plan supérieur pour continuer.';
    header('Location: /mon-asso-ia');
    exit;
}

// → Le code de génération IA continue ici normalement


// ============================================================
// 📋 SNIPPET 2 — Blocage IA image (STRICT)
// ============================================================
//
// Fichier concerné : mon-asso-ia-image-execute.php (ou équivalent)
//
// Insère TOUT EN HAUT, juste après les require_once :

$check_img = ak_can_use_ai_image($pdo, (int)$_SESSION['user_org_id']);
if (!$check_img['ok']) {
    $_SESSION['flash_error'] = '🚫 ' . $check_img['reason'] . '. Passez au plan supérieur pour débloquer la génération d\'images.';
    header('Location: /mon-asso-ia');
    exit;
}


// ============================================================
// 📋 SNIPPET 3 — Blocage envoi email (STRICT)
// ============================================================
//
// Fichier concerné : diffusion-email-send.php (ou équivalent)
// Cas spécial : on bloque même AVANT d'arriver à la page si la feature est désactivée
//
// Insère TOUT EN HAUT, juste après les require_once :

$count_recipients = (int)($_POST['recipient_count'] ?? 1); // ou récupérer le nb réel
$check_email = ak_can_send_email($pdo, (int)$_SESSION['user_org_id'], $count_recipients);
if (!$check_email['ok']) {
    if (!empty($check_email['feature_disabled'])) {
        // Plan Démarrage : redirection vers diffusion-email avec effet flou
        $_SESSION['flash_error'] = '🔒 La diffusion email n\'est pas incluse dans votre plan. Passez à Assokit.';
        header('Location: /diffusion-email');
        exit;
    }
    // Quota dépassé sur plan payant
    $_SESSION['flash_error'] = '🚫 ' . $check_email['reason'];
    header('Location: /diffusion-email');
    exit;
}


// ============================================================
// 📋 SNIPPET 4 — Blocage DOUX facture (autorisé + rappel)
// ============================================================
//
// Fichier concerné : nouvelle-facture.php (ou équivalent)
// Comportement : on n'EMPÊCHE PAS la création, on prévient juste si limite atteinte
//
// Insère AVANT le formulaire :

$check_inv = ak_can_create_invoice($pdo, (int)$_SESSION['user_org_id']);
$show_warning = !$check_inv['ok']; // true si quota atteint

// Dans le HTML, juste avant le formulaire :
?>
<?php if (isset($show_warning) && $show_warning): ?>
  <div style="background:#FED7AA;border-left:4px solid #EA580C;color:#9A3412;padding:14px 18px;border-radius:10px;margin-bottom:18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
    <div style="flex:1;min-width:240px;">
      <strong>⚠️ Limite atteinte</strong> — Vous avez atteint votre quota de factures sur le plan Démarrage (<?= (int)$check_inv['current'] ?>/<?= (int)$check_inv['limit'] ?>).
      <br><span style="font-size:13px;">La facture sera créée, mais nous vous recommandons de passer au plan Assokit pour des factures illimitées.</span>
    </div>
    <a href="/mon-asso-plan" style="background:#EA580C;color:white;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap;">↗ Voir les plans</a>
  </div>
<?php endif; ?>
<?php

// → Le formulaire de création de facture continue normalement


// ============================================================
// 📋 SNIPPET 5 — Blocage DOUX devis
// ============================================================
//
// Fichier concerné : nouveau-devis.php
// Même logique que SNIPPET 4 mais avec ak_can_create_quote()

$check_qt = ak_can_create_quote($pdo, (int)$_SESSION['user_org_id']);
$show_warning_quote = !$check_qt['ok'];

// Dans le HTML :
?>
<?php if (isset($show_warning_quote) && $show_warning_quote): ?>
  <div style="background:#FED7AA;border-left:4px solid #EA580C;color:#9A3412;padding:14px 18px;border-radius:10px;margin-bottom:18px;">
    <strong>⚠️ Limite devis atteinte</strong> (<?= $check_qt['current'] ?>/<?= $check_qt['limit'] ?>).
    <a href="/mon-asso-plan">Passer au plan Assokit pour devis illimités →</a>
  </div>
<?php endif; ?>
<?php


// ============================================================
// 📋 SNIPPET 6 — Blocage DOUX adhérent
// ============================================================
//
// Fichier concerné : nouveau-adherent.php

$check_adh = ak_can_add_adherent($pdo, (int)$_SESSION['user_org_id']);
$show_warning_adh = !$check_adh['ok'];

// Si tu veux bloquer DUREMENT pour adhérent (puisque limite stricte), utilise plutôt :
if (!$check_adh['ok']) {
    $_SESSION['flash_error'] = '🚫 ' . $check_adh['reason'] . '. Passez au plan supérieur pour ajouter plus d\'adhérents.';
    header('Location: /adherents');
    exit;
}


// ============================================================
// 📋 SNIPPET 7 — Blocage contact (Démarrage = 0 contact)
// ============================================================
//
// Fichier concerné : nouveau-contact.php

$check_ct = ak_can_add_contact($pdo, (int)$_SESSION['user_org_id']);
if (!$check_ct['ok']) {
    $_SESSION['flash_error'] = '🚫 ' . $check_ct['reason'] . '. La gestion des contacts nécessite le plan Assokit.';
    header('Location: /contacts');
    exit;
}


// ============================================================
// 📋 SNIPPET 8 — Blocage utilisateur (admin)
// ============================================================
//
// Fichier concerné : nouveau-utilisateur.php (super-admin/admin)

$check_us = ak_can_add_user($pdo, (int)$_SESSION['user_org_id']);
if (!$check_us['ok']) {
    $_SESSION['flash_error'] = '🚫 ' . $check_us['reason'];
    header('Location: /utilisateurs');
    exit;
}
