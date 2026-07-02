<?php
/**
 * ============================================================
 * ASSOKIT — Page Paramètres utilisateur
 * URL : /parametres
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_login();
$current = current_user();
$user_id = (int)$current['id'];
$org_id = (int)($current['org_id'] ?? 0);

// Onglet actif
$tab = $_GET['tab'] ?? 'compte';
$valid_tabs = ['compte','organisation','apparence','securite'];
if (!in_array($tab, $valid_tabs)) $tab = 'compte';

// Charger user complet
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$user_id]);
$user_full = $stmt->fetch();

// Charger organisation
$org_full = null;
if ($org_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ? LIMIT 1");
    $stmt->execute([$org_id]);
    $org_full = $stmt->fetch();
}

// Message flash
$flash = $_GET['flash'] ?? '';
$flash_type = $_GET['ft'] ?? 'success';
$active = 'parametres';
render_head('Paramètres · Assokit');
render_sidebar('parametres');
?>
<div class="pa-wrap">
  <div class="pa-header">
    <h1 class="pa-title">⚙️ Paramètres</h1>
    <p class="pa-sub">Gérez votre compte, votre organisation, l'apparence et la sécurité.</p>
  </div>

  <?php if ($flash): ?>
    <div class="pa-flash pa-flash-<?= h($flash_type) ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <div class="pa-grid">
    <!-- Onglets verticaux -->
    <nav class="pa-tabs">
      <a href="?tab=compte" class="pa-tab <?= $tab === 'compte' ? 'is-active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <span>Compte</span>
      </a>
      <a href="?tab=organisation" class="pa-tab <?= $tab === 'organisation' ? 'is-active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V10l7-5 7 5v11M9 21V12h6v9"/></svg>
        <span>Organisation</span>
      </a>
      <a href="?tab=apparence" class="pa-tab <?= $tab === 'apparence' ? 'is-active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
        <span>Apparence</span>
      </a>
      <a href="?tab=securite" class="pa-tab <?= $tab === 'securite' ? 'is-active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <span>Sécurité</span>
      </a>
    </nav>

    <!-- Contenu de l'onglet -->
    <main class="pa-content">

      <?php if ($tab === 'compte'): ?>
      <!-- ============ ONGLET COMPTE ============ -->
      <form method="POST" action="/action-parametres" class="pa-form-card">
        <input type="hidden" name="action" value="update_account">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">

        <h2 class="pa-form-h2">Informations personnelles</h2>
        <p class="pa-form-sub">Ces informations apparaissent dans vos messages, signatures et profil.</p>

        <div class="pa-grid-2">
          <div class="pa-fld">
            <label>Prénom *</label>
            <input type="text" name="first_name" required maxlength="80" value="<?= h($user_full['first_name'] ?? '') ?>">
          </div>
          <div class="pa-fld">
            <label>Nom *</label>
            <input type="text" name="last_name" required maxlength="80" value="<?= h($user_full['last_name'] ?? '') ?>">
          </div>
        </div>

        <div class="pa-fld">
          <label>Email *</label>
          <input type="email" name="email" required maxlength="190" value="<?= h($user_full['email'] ?? '') ?>">
          <span class="pa-fld-hint">Utilisé pour la connexion et les notifications.</span>
        </div>

        <div class="pa-grid-2">
          <div class="pa-fld">
            <label>Téléphone</label>
            <input type="tel" name="phone" maxlength="30" value="<?= h($user_full['phone'] ?? '') ?>" placeholder="+33 6 12 34 56 78">
          </div>
          <div class="pa-fld">
            <label>Ville</label>
            <input type="text" name="city" maxlength="100" value="<?= h($user_full['city'] ?? '') ?>" placeholder="Paris">
          </div>
        </div>

        <div class="pa-form-actions">
          <button type="submit" class="pa-btn-primary">💾 Enregistrer</button>
        </div>
      </form>

      <!-- ============ SECTION RGPD : Mes donnees ============ -->
      <?php if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); ?>
      <div class="pa-form-card" style="margin-top:24px;">
        <h2 class="pa-card-title">🔒 Mes données personnelles (RGPD)</h2>
        <p style="color:#64748B;font-size:14px;margin:0 0 18px;">Conformément au RGPD, vous pouvez exporter ou supprimer vos données personnelles à tout moment.</p>
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;padding:14px 0;border-top:1px solid #E2E8F0;">
          <div><strong>Exporter mes données</strong><br><span style="color:#64748B;font-size:13px;">Télécharger une copie de vos données (format JSON).</span></div>
          <a href="/rgpd-export" style="display:inline-block;background:#F0FDF4;color:#047857;border:1px solid #A7F3D0;border-radius:8px;padding:10px 16px;text-decoration:none;font-weight:600;white-space:nowrap;">Exporter</a>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;padding:14px 0;border-top:1px solid #E2E8F0;">
          <div><strong style="color:#DC2626;">Supprimer mon compte</strong><br><span style="color:#64748B;font-size:13px;">Action définitive : vos données personnelles seront anonymisées.</span></div>
          <form method="post" action="/rgpd-supprimer-compte" onsubmit="return confirm('Êtes-vous sûr ? Cette action est définitive et anonymisera vos données personnelles.');" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="confirm" value="SUPPRIMER">
            <button type="submit" style="background:#FEF2F2;color:#DC2626;border:1px solid #FECACA;border-radius:8px;padding:10px 16px;cursor:pointer;font-weight:600;white-space:nowrap;">Supprimer mon compte</button>
          </form>
        </div>
      </div>
      <!-- ============ /RGPD ============ -->

      <?php elseif ($tab === 'organisation'): ?>
      <!-- ============ ONGLET ORGANISATION ============ -->
      <?php if ($current['role'] !== 'admin'): ?>
        <div class="pa-form-card">
          <h2 class="pa-form-h2">🔒 Accès restreint</h2>
          <p>Seul un administrateur de l'organisation peut modifier ces paramètres.</p>
        </div>
      <?php else: ?>
        <form method="POST" action="/action-parametres" enctype="multipart/form-data" class="pa-form-card">
          <input type="hidden" name="action" value="update_organization">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">

          <h2 class="pa-form-h2">Identité de l'organisation</h2>
          <p class="pa-form-sub">Ces informations apparaissent sur vos documents, factures et communications.</p>

          <div class="pa-fld">
            <label>Nom de l'organisation *</label>
            <input type="text" name="org_name" required maxlength="190" value="<?= h($org_full['name'] ?? '') ?>">
          </div>

          <div class="pa-grid-2">
            <div class="pa-fld">
              <label>SIREN</label>
              <input type="text" name="siren" maxlength="14" value="<?= h($org_full['siren'] ?? '') ?>" placeholder="123 456 789">
            </div>
            <div class="pa-fld">
              <label>N° RNA (associations)</label>
              <input type="text" name="rna_number" maxlength="20" value="<?= h($org_full['rna_number'] ?? '') ?>" placeholder="W123456789">
            </div>
          </div>

          <h2 class="pa-form-h2" style="margin-top:28px;">🎨 Logo &amp; identité visuelle</h2>

          <div class="pa-logo-row">
            <div class="pa-logo-current">
              <?php if (!empty($org_full['logo_path']) && file_exists(__DIR__ . $org_full['logo_path'])): ?>
                <img src="<?= h($org_full['logo_path']) ?>?v=<?= time() ?>" alt="Logo" class="pa-logo-img">
              <?php else: ?>
                <div class="pa-logo-placeholder">Aucun logo</div>
              <?php endif; ?>
            </div>
            <div class="pa-logo-actions">
              <label class="pa-btn-upload">
                <input type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml" style="display:none;">
                📁 Choisir un fichier
              </label>
              <span class="pa-fld-hint">PNG, JPG ou SVG · Max 2 Mo · 512×512 px recommandé</span>
            </div>
          </div>

          <h2 class="pa-form-h2" style="margin-top:28px;">🎨 Couleurs de marque</h2>
          <div class="pa-grid-2">
            <div class="pa-fld">
              <label>Couleur primaire</label>
              <div class="pa-color-row">
                <input type="color" name="branding_primary_color" value="<?= h($org_full['branding_primary_color'] ?? '#10B981') ?>" class="pa-color-picker">
                <input type="text" name="branding_primary_color_hex" value="<?= h($org_full['branding_primary_color'] ?? '#10B981') ?>" maxlength="7" class="pa-color-hex" pattern="^#[0-9A-Fa-f]{6}$">
              </div>
            </div>
            <div class="pa-fld">
              <label>Couleur secondaire</label>
              <div class="pa-color-row">
                <input type="color" name="branding_secondary_color" value="<?= h($org_full['branding_secondary_color'] ?? '#6366F1') ?>" class="pa-color-picker">
                <input type="text" name="branding_secondary_color_hex" value="<?= h($org_full['branding_secondary_color'] ?? '#6366F1') ?>" maxlength="7" class="pa-color-hex" pattern="^#[0-9A-Fa-f]{6}$">
              </div>
            </div>
          </div>

          <h2 class="pa-form-h2" style="margin-top:28px;">🧾 Informations légales</h2>
          <div class="pa-grid-2">
            <div class="pa-fld">
              <label>Nom légal (si différent du nom usuel)</label>
              <input type="text" name="legal_name" maxlength="200" value="<?= h($org_full['legal_name'] ?? '') ?>" placeholder="Association LATITUDE 91">
            </div>
            <div class="pa-fld">
              <label>Forme juridique</label>
              <input type="text" name="legal_form" maxlength="50" value="<?= h($org_full['legal_form'] ?? '') ?>" placeholder="Association loi 1901">
            </div>
          </div>
          <div class="pa-fld">
            <label>SIRET (14 chiffres)</label>
            <input type="text" name="siret" maxlength="20" value="<?= h($org_full['siret'] ?? '') ?>" placeholder="12345678900012">
          </div>

          <h2 class="pa-form-h2" style="margin-top:28px;">📍 Adresse de facturation</h2>
          <div class="pa-fld">
            <label>Adresse (rue et numéro)</label>
            <input type="text" name="billing_address_street" maxlength="255" value="<?= h($org_full['billing_address_street'] ?? '') ?>" placeholder="12 rue des Lilas">
          </div>
          <div class="pa-fld">
            <label>Complément d'adresse <span style="color:#A1A1AA;font-weight:400;">(facultatif)</span></label>
            <input type="text" name="billing_address_complement" maxlength="255" value="<?= h($org_full['billing_address_complement'] ?? '') ?>" placeholder="Bâtiment B, Étage 3">
          </div>
          <div class="pa-grid-2">
            <div class="pa-fld">
              <label>Code postal</label>
              <input type="text" name="billing_address_zip" maxlength="20" value="<?= h($org_full['billing_address_zip'] ?? '') ?>" placeholder="75011">
            </div>
            <div class="pa-fld">
              <label>Ville</label>
              <input type="text" name="billing_address_city" maxlength="100" value="<?= h($org_full['billing_address_city'] ?? '') ?>" placeholder="Paris">
            </div>
          </div>
          <div class="pa-fld">
            <label>Pays</label>
            <select name="billing_address_country" style="width:100%;padding:10px 12px;border:1px solid #E4E4E7;border-radius:8px;font-size:14px;background:#fff;font-family:inherit;">
              <option value="">— Sélectionner —</option>
              <?php
              $_cur = $org_full['billing_address_country'] ?? 'FR';
              $_countries = ['FR'=>'France','BE'=>'Belgique','CH'=>'Suisse','LU'=>'Luxembourg','MC'=>'Monaco','CA'=>'Canada','MA'=>'Maroc','DZ'=>'Algérie','TN'=>'Tunisie','SN'=>'Sénégal','DE'=>'Allemagne','ES'=>'Espagne','IT'=>'Italie','PT'=>'Portugal','GB'=>'Royaume-Uni','US'=>'États-Unis'];
              foreach ($_countries as $_code => $_name): ?>
                <option value="<?= $_code ?>" <?= $_cur === $_code ? 'selected' : '' ?>><?= h($_name) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <h2 class="pa-form-h2" style="margin-top:28px;">💶 TVA</h2>
          <div class="pa-fld" style="display:flex;align-items:center;gap:10px;background:#FAFAF9;padding:12px 14px;border-radius:8px;border:1px solid #E4E4E7;">
            <input type="checkbox" name="vat_subject" id="vat_subject_chk" value="1" <?= !empty($org_full['vat_subject']) ? 'checked' : '' ?> style="width:18px;height:18px;cursor:pointer;margin:0;">
            <label for="vat_subject_chk" style="margin:0;cursor:pointer;font-weight:500;">Mon organisation est assujettie à la TVA</label>
          </div>
          <div class="pa-fld">
            <label>N° TVA intracommunautaire <span style="color:#A1A1AA;font-weight:400;">(si applicable)</span></label>
            <input type="text" name="vat_number" maxlength="30" value="<?= h($org_full['vat_number'] ?? '') ?>" placeholder="FR12345678901">
          </div>

          <h2 class="pa-form-h2" style="margin-top:28px;">📧 Contact facturation</h2>
          <div class="pa-grid-2">
            <div class="pa-fld">
              <label>Email facturation</label>
              <input type="email" name="billing_email" maxlength="191" value="<?= h($org_full['billing_email'] ?? '') ?>" placeholder="compta@asso.fr">
            </div>
            <div class="pa-fld">
              <label>Téléphone facturation</label>
              <input type="tel" name="billing_phone" maxlength="30" value="<?= h($org_full['billing_phone'] ?? '') ?>" placeholder="+33 1 23 45 67 89">
            </div>
          </div>

          <div class="pa-form-actions">
            <button type="submit" class="pa-btn-primary">💾 Enregistrer</button>
          </div>
        </form>
      <?php endif; ?>

      <?php elseif ($tab === 'apparence'): ?>
      <!-- ============ ONGLET APPARENCE (DARK MODE DISABLED 2026-05) ============ -->
      <div class="pa-form-card">
        <h2 class="pa-form-h2">Thème de l'interface</h2>

        <div style="background:#FEF3C7;border:1px solid #FBBF24;border-radius:8px;padding:14px 18px;margin:14px 0;color:#92400E;font-size:14px;line-height:1.5;">
          <strong>ℹ️ Mode sombre temporairement désactivé</strong><br>
          Le thème sombre est en cours de refonte pour améliorer la lisibilité. Assokit utilise actuellement le thème <strong>Clair</strong> uniquement. Cette option reviendra dans une prochaine mise à jour.
        </div>

        <div class="pa-theme-grid">
          <div class="pa-theme-card is-selected" style="cursor:default;">
            <div class="pa-theme-preview pa-theme-light">
              <div class="pa-theme-sidebar"></div>
              <div class="pa-theme-main"><div class="pa-theme-card-mini"></div><div class="pa-theme-card-mini"></div></div>
            </div>
            <div class="pa-theme-name">☀️ Clair (actif)</div>
            <div class="pa-theme-desc">Interface lumineuse classique</div>
          </div>
        </div>
      </div>

      <?php elseif ($tab === 'securite'): ?>
      <!-- ============ ONGLET SÉCURITÉ ============ -->
      <form method="POST" action="/action-parametres" class="pa-form-card">
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">

        <h2 class="pa-form-h2">🔐 Changer mon mot de passe</h2>
        <p class="pa-form-sub">Pour des raisons de sécurité, vous devez confirmer votre mot de passe actuel.</p>

        <div class="pa-fld">
          <label>Mot de passe actuel *</label>
          <input type="password" name="current_password" required autocomplete="current-password">
        </div>

        <div class="pa-grid-2">
          <div class="pa-fld">
            <label>Nouveau mot de passe *</label>
            <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
            <span class="pa-fld-hint">Minimum 8 caractères</span>
          </div>
          <div class="pa-fld">
            <label>Confirmer le nouveau mot de passe *</label>
            <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
          </div>
        </div>

        <div class="pa-form-actions">
          <button type="submit" class="pa-btn-primary">🔒 Mettre à jour le mot de passe</button>
        </div>
      </form>

      <div class="pa-form-card" style="margin-top:18px;">
        <h2 class="pa-form-h2">🔐 Double authentification (2FA)</h2>
        <p class="pa-form-sub">Ajoutez une étape de vérification à la connexion via une application d'authentification (Google Authenticator, Authy...).</p>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
          <div style="font-size:14px;">
            <?php if (!empty($user_full['totp_enabled'])): ?><span style="color:#047857;font-weight:600;">Activée</span><?php else: ?><span style="color:#64748B;">Non activée</span><?php endif; ?>
          </div>
          <a href="/securite-2fa" class="pa-btn-primary" style="text-decoration:none;"><?= !empty($user_full['totp_enabled']) ? 'Gérer' : 'Activer la 2FA' ?></a>
        </div>
      </div>

      <div class="pa-form-card" style="margin-top:18px;">
        <h2 class="pa-form-h2">📊 Sessions &amp; activité</h2>
        <div class="pa-info-row">
          <div class="pa-info-label">Dernière connexion</div>
          <div class="pa-info-value"><?= $user_full['last_login_at'] ? h(date('d/m/Y H:i', strtotime($user_full['last_login_at']))) : 'Jamais' ?></div>
        </div>
        <div class="pa-info-row">
          <div class="pa-info-label">Compte créé le</div>
          <div class="pa-info-value"><?= $user_full['created_at'] ? h(date('d/m/Y', strtotime($user_full['created_at']))) : '—' ?></div>
        </div>
      </div>
      <?php endif; ?>

    </main>
  </div>
</div>

<link rel="stylesheet" href="/css/pa-namespace.css">

<script>
// Sync color picker ↔ hex input
document.querySelectorAll('.pa-color-picker').forEach(picker => {
  const hex = picker.nextElementSibling;
  picker.addEventListener('input', e => hex.value = e.target.value);
  hex.addEventListener('input', e => { if (/^#[0-9A-Fa-f]{6}$/.test(e.target.value)) picker.value = e.target.value; });
});

// Theme switcher DISABLED 2026-05 — dark mode removed
</script>

<?php render_foot(); ?>
