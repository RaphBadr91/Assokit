<?php
/**
 * Super Admin — Formulaire de création d'une mairie
 * URL : /super-admin-mairie-nouvelle
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-permissions.php';
require_login();
require_platform_admin();

// Génère le token CSRF s'il n'existe pas (sécurité)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

render_head('Nouvelle mairie');
render_sidebar('super-admin');
?>

<div style="max-width:900px;margin:0 auto;padding:24px;">

  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div style="background:#FEE2E2;border:1px solid #DC2626;color:#991B1B;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;">
      ❌ <?= htmlspecialchars($_SESSION['flash_error']) ?>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>

  <div style="margin-bottom:18px;">
    <a href="/super-admin-mairies" style="color:#3F3F46;text-decoration:none;font-size:13px;">← Retour aux mairies</a>
  </div>

  <h1 style="font-size:24px;margin:0 0 6px;font-weight:700;color:#0A0A0B;">🏛 Créer une mairie / collectivité</h1>
  <p style="color:#71717A;margin:0 0 24px;font-size:13.5px;">Cette mairie deviendra Super Admin sur les asso de son portefeuille.</p>

  <form method="POST" action="/action-mairie.php" style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:28px;">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

    <h3 style="font-size:15px;margin:0 0 14px;color:#0A0A0B;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">📋 Identité</h3>
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-bottom:8px;">
      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:4px;">Nom officiel *</label>
        <input name="name" required placeholder="Mairie d'Évry-Courcouronnes" style="width:100%;padding:10px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;">
      </div>
      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:4px;">Type *</label>
        <select name="type" required style="width:100%;padding:10px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;background:#fff;">
          <option value="mairie">🏛 Mairie</option>
          <option value="departement">🏢 Département</option>
          <option value="region">🌍 Région</option>
          <option value="drac">🎭 DRAC</option>
          <option value="caf">👨‍👩‍👧 CAF</option>
          <option value="federation">🤝 Fédération</option>
          <option value="autre">📁 Autre</option>
        </select>
      </div>
    </div>
    <div style="margin-bottom:24px;">
      <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:4px;">SIRET</label>
      <input name="siret" placeholder="14 chiffres" maxlength="20" style="width:100%;padding:10px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;">
    </div>

    <h3 style="font-size:15px;margin:0 0 14px;color:#0A0A0B;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">📍 Adresse</h3>
    <div style="margin-bottom:8px;">
      <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:4px;">Rue</label>
      <input name="address_street" placeholder="Place des Droits de l'Homme" style="width:100%;padding:10px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;">
    </div>
    <div style="display:grid;grid-template-columns:1fr 2fr;gap:14px;margin-bottom:8px;">
      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:4px;">Code postal</label>
        <input name="address_zip" maxlength="10" placeholder="91000" style="width:100%;padding:10px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;">
      </div>
      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:4px;">Ville</label>
        <input name="address_city" placeholder="Évry-Courcouronnes" style="width:100%;padding:10px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;">
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:24px;">
      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:4px;">Département</label>
        <input name="department" placeholder="Essonne (91)" style="width:100%;padding:10px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;">
      </div>
      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:4px;">Région</label>
        <input name="region" placeholder="Île-de-France" style="width:100%;padding:10px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;">
      </div>
    </div>

    <h3 style="font-size:15px;margin:0 0 14px;color:#0A0A0B;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">👤 Contact principal</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:8px;">
      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:4px;">Prénom</label>
        <input name="contact_first_name" style="width:100%;padding:10px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;">
      </div>
      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:4px;">Nom</label>
        <input name="contact_last_name" style="width:100%;padding:10px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;">
      </div>
    </div>
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-bottom:24px;">
      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:4px;">Email *</label>
        <input type="email" name="contact_email" required style="width:100%;padding:10px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;">
      </div>
      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:4px;">Téléphone</label>
        <input name="contact_phone" style="width:100%;padding:10px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;">
      </div>
    </div>

    <h3 style="font-size:15px;margin:0 0 14px;color:#0A0A0B;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">🎯 Quota & Statut</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:4px;">Quota d'asso (validé) *</label>
        <input type="number" name="validated_quota" value="100" min="0" max="100000" required style="width:100%;padding:10px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;">
        <small style="color:#71717A;font-size:11px;">Nombre max d'asso que cette mairie peut gérer.</small>
      </div>
      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:4px;">Statut</label>
        <select name="status" style="width:100%;padding:10px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;background:#fff;">
          <option value="active">🟢 Active (utilisable immédiatement)</option>
          <option value="pending">🟠 En attente (à valider plus tard)</option>
        </select>
      </div>
    </div>
    <div style="margin-bottom:24px;">
      <label style="display:block;font-size:12px;font-weight:600;color:#3F3F46;margin-bottom:4px;">Notes internes (optionnel)</label>
      <textarea name="notes" rows="3" placeholder="Commentaires, conditions particulières..." style="width:100%;padding:10px 12px;border:1px solid #D4D4D8;border-radius:7px;font-size:14px;resize:vertical;"></textarea>
    </div>

    <div style="border-top:1px solid #E5E7EB;padding-top:18px;display:flex;justify-content:flex-end;gap:10px;">
      <a href="/super-admin-mairies" style="padding:11px 22px;color:#3F3F46;text-decoration:none;font-size:14px;font-weight:600;">Annuler</a>
      <button type="submit" style="background:#059669;color:#fff;padding:11px 26px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;">✓ Créer la mairie</button>
    </div>
  </form>
</div>
