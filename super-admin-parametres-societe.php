<?php
/**
 * ============================================================
 * ASSOKIT — super-admin-parametres-societe.php
 * Paramètres de la société (singleton) — Fondateur uniquement
 * ============================================================
 * URL : /fondateur-cockpit/societe
 *
 * Accès : is_founder = 1 UNIQUEMENT
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/company-helpers.php';

require_login();
$user = current_user();

// Fondateur UNIQUEMENT — vérif BDD
$is_founder = false;
if ($user) {
    try {
        $stmt = $pdo->prepare("SELECT is_founder FROM users WHERE id = :id");
        $stmt->execute([':id' => (int)$user['id']]);
        $row = $stmt->fetch();
        $is_founder = $row && (int)$row['is_founder'] === 1;
    } catch (Throwable $e) {}
}
if (!$is_founder) {
    http_response_code(403);
    exit('Accès réservé aux Fondateurs.');
}

// Charger les settings actuels
$c = ak_company() ?: [];

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Flash message depuis save
$flash = $_SESSION['flash_societe'] ?? null;
unset($_SESSION['flash_societe']);

$updated_by_name = null;
if (!empty($c['updated_by_user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = :id");
        $stmt->execute([':id' => (int)$c['updated_by_user_id']]);
        $u = $stmt->fetch();
        if ($u) $updated_by_name = trim($u['first_name'] . ' ' . $u['last_name']);
    } catch (Throwable $e) {}
}

sa_render_head('Paramètres société');
sa_render_sidebar('dashboard');
?>

<div class="sa-page-head">
    <div>
        <h1 class="sa-page-title">
            ⚙️ Paramètres société
            <span style="font-size:14px;vertical-align:middle;margin-left:8px;background:linear-gradient(135deg, #FCD34D 0%, #F59E0B 100%);color:#78350F;padding:4px 12px;border-radius:999px;font-weight:600;">
                🏗️ FONDATEUR
            </span>
        </h1>
        <div class="sa-page-sub">
            Infos utilisées automatiquement pour les factures PDF, emails, mentions légales et exports comptables.
        </div>
    </div>
</div>

<?php if ($flash): ?>
    <div class="sa-alert <?= $flash['type'] === 'success' ? 'sa-alert-success' : 'sa-alert-error' ?>" style="margin-bottom:16px;">
        <span style="font-size:18px"><?= $flash['type'] === 'success' ? '✅' : '⚠️' ?></span>
        <div><?= h($flash['message']) ?></div>
    </div>
<?php endif; ?>

<!-- Info dernière modif -->
<?php if (!empty($c['updated_at']) && $updated_by_name): ?>
    <div class="sa-card" style="margin-bottom:16px; background:rgba(127, 119, 221, 0.06); border-color:rgba(127, 119, 221, 0.2);">
        <div style="font-size:13px; color:var(--sa-ink-3);">
            💾 Dernière modification : <strong style="color:var(--sa-ink-2);"><?= h(date('d/m/Y à H:i', strtotime($c['updated_at']))) ?></strong> par <strong style="color:var(--sa-ink-2);"><?= h($updated_by_name) ?></strong>
        </div>
    </div>
<?php endif; ?>

<!-- Alert : champs non obligatoires -->
<div class="sa-alert sa-alert-info" style="margin-bottom:20px;">
    <span style="font-size:18px;">ℹ️</span>
    <div>
        <strong>Tous les champs sont optionnels.</strong> Remplissez-les au fur et à mesure.
        Les factures et emails s'adapteront automatiquement aux infos disponibles.
    </div>
</div>

<form method="POST" action="/fondateur-cockpit/societe/save" enctype="multipart/form-data" id="form-societe">
<input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

<!-- ============================================= -->
<!-- SECTION 1 — IDENTITÉ LÉGALE -->
<!-- ============================================= -->
<details class="societe-section" open>
    <summary>🏢 Identité légale</summary>
    <div class="societe-grid">
        <div class="field">
            <label>Raison sociale</label>
            <input type="text" name="legal_name" value="<?= h($c['legal_name'] ?? '') ?>" placeholder="ex: Latitude91 SAS">
        </div>
        <div class="field">
            <label>Forme juridique</label>
            <select name="legal_form">
                <option value="">—</option>
                <?php foreach (['SAS', 'SASU', 'SARL', 'EURL', 'SA', 'SCS', 'SCA', 'SNC', 'Association loi 1901', 'Auto-entrepreneur', 'EI', 'EIRL', 'SCI'] as $f): ?>
                    <option value="<?= h($f) ?>" <?= ($c['legal_form'] ?? '') === $f ? 'selected' : '' ?>><?= h($f) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Capital social (en €)</label>
            <input type="number" name="capital_euros" min="0" step="0.01" value="<?= !empty($c['capital_cents']) ? h(number_format($c['capital_cents'] / 100, 2, '.', '')) : '' ?>" placeholder="ex: 1000">
        </div>
        <div class="field">
            <label>SIREN</label>
            <input type="text" name="siren" value="<?= h($c['siren'] ?? '') ?>" placeholder="123 456 789" maxlength="20">
        </div>
        <div class="field">
            <label>SIRET (siège)</label>
            <input type="text" name="siret" value="<?= h($c['siret'] ?? '') ?>" placeholder="123 456 789 00012" maxlength="20">
        </div>
        <div class="field">
            <label>Code APE / NAF</label>
            <input type="text" name="ape_code" value="<?= h($c['ape_code'] ?? '') ?>" placeholder="ex: 6201Z" maxlength="10">
        </div>
        <div class="field">
            <label>Ville RCS</label>
            <input type="text" name="rcs_city" value="<?= h($c['rcs_city'] ?? '') ?>" placeholder="ex: Paris">
        </div>
        <div class="field">
            <label>Numéro RCS</label>
            <input type="text" name="rcs_number" value="<?= h($c['rcs_number'] ?? '') ?>" placeholder="ex: 123 456 789">
        </div>
    </div>
</details>

<!-- ============================================= -->
<!-- SECTION 2 — TVA -->
<!-- ============================================= -->
<details class="societe-section">
    <summary>📋 TVA</summary>
    <div class="societe-grid">
        <div class="field" style="grid-column:1/-1">
            <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                <input type="checkbox" name="vat_subject" value="1" <?= !empty($c['vat_subject']) ? 'checked' : '' ?>>
                <span>Société assujettie à la TVA</span>
            </label>
            <small style="color:var(--sa-ink-3); margin-top:4px; display:block;">Si non cochée, les factures afficheront « TVA non applicable — art. 293 B du CGI »</small>
        </div>
        <div class="field">
            <label>N° TVA intracommunautaire</label>
            <input type="text" name="vat_number" value="<?= h($c['vat_number'] ?? '') ?>" placeholder="FR XX XXXXXXXXX">
        </div>
        <div class="field">
            <label>Taux de TVA standard (%)</label>
            <input type="number" name="vat_rate" step="0.01" min="0" max="100" value="<?= h($c['vat_rate'] ?? '') ?>" placeholder="20.00">
        </div>
    </div>
</details>

<!-- ============================================= -->
<!-- SECTION 3 — ADRESSE -->
<!-- ============================================= -->
<details class="societe-section">
    <summary>📍 Adresse du siège social</summary>
    <div class="societe-grid">
        <div class="field" style="grid-column:1/-1">
            <label>Rue</label>
            <input type="text" name="address_street" value="<?= h($c['address_street'] ?? '') ?>" placeholder="12 rue des Exemples">
        </div>
        <div class="field" style="grid-column:1/-1">
            <label>Complément d'adresse</label>
            <input type="text" name="address_complement" value="<?= h($c['address_complement'] ?? '') ?>" placeholder="Bâtiment B, 3e étage">
        </div>
        <div class="field">
            <label>Code postal</label>
            <input type="text" name="address_zip" value="<?= h($c['address_zip'] ?? '') ?>" placeholder="75012" maxlength="20">
        </div>
        <div class="field">
            <label>Ville</label>
            <input type="text" name="address_city" value="<?= h($c['address_city'] ?? '') ?>" placeholder="Paris">
        </div>
        <div class="field">
            <label>Pays</label>
            <select name="address_country">
                <option value="">—</option>
                <?php foreach (['FR' => 'France', 'BE' => 'Belgique', 'CH' => 'Suisse', 'LU' => 'Luxembourg', 'CA' => 'Canada', 'MA' => 'Maroc', 'TN' => 'Tunisie', 'DZ' => 'Algérie', 'DE' => 'Allemagne', 'ES' => 'Espagne', 'IT' => 'Italie', 'PT' => 'Portugal', 'GB' => 'Royaume-Uni', 'US' => 'États-Unis'] as $code => $label): ?>
                    <option value="<?= h($code) ?>" <?= ($c['address_country'] ?? '') === $code ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</details>

<!-- ============================================= -->
<!-- SECTION 4 — CONTACT -->
<!-- ============================================= -->
<details class="societe-section">
    <summary>📧 Contact</summary>
    <div class="societe-grid">
        <div class="field">
            <label>Email de facturation (expéditeur)</label>
            <input type="email" name="email_billing" value="<?= h($c['email_billing'] ?? '') ?>" placeholder="facturation@exemple.fr">
        </div>
        <div class="field">
            <label>Email support client</label>
            <input type="email" name="email_support" value="<?= h($c['email_support'] ?? '') ?>" placeholder="contact@exemple.fr">
        </div>
        <div class="field">
            <label>Email contact légal / RGPD</label>
            <input type="email" name="email_legal" value="<?= h($c['email_legal'] ?? '') ?>" placeholder="legal@exemple.fr">
        </div>
        <div class="field">
            <label>Téléphone</label>
            <input type="text" name="phone" value="<?= h($c['phone'] ?? '') ?>" placeholder="+33 1 23 45 67 89">
        </div>
        <div class="field" style="grid-column:1/-1">
            <label>Site web</label>
            <input type="url" name="website" value="<?= h($c['website'] ?? '') ?>" placeholder="https://exemple.fr">
        </div>
    </div>
</details>

<!-- ============================================= -->
<!-- SECTION 5 — BANCAIRE -->
<!-- ============================================= -->
<details class="societe-section">
    <summary>🏦 Coordonnées bancaires</summary>
    <div class="societe-grid">
        <div class="field">
            <label>IBAN</label>
            <input type="text" name="iban" value="<?= h($c['iban'] ?? '') ?>" placeholder="FR76 XXXX XXXX XXXX XXXX XXXX XXX">
        </div>
        <div class="field">
            <label>BIC / SWIFT</label>
            <input type="text" name="bic" value="<?= h($c['bic'] ?? '') ?>" placeholder="BNPAFRPP" maxlength="15">
        </div>
        <div class="field" style="grid-column:1/-1">
            <label>Nom de la banque</label>
            <input type="text" name="bank_name" value="<?= h($c['bank_name'] ?? '') ?>" placeholder="ex: BNP Paribas">
        </div>
    </div>
</details>

<!-- ============================================= -->
<!-- SECTION 6 — REPRÉSENTANT LÉGAL -->
<!-- ============================================= -->
<details class="societe-section">
    <summary>👤 Représentant légal</summary>
    <div class="societe-grid">
        <div class="field">
            <label>Prénom</label>
            <input type="text" name="legal_rep_first_name" value="<?= h($c['legal_rep_first_name'] ?? '') ?>" placeholder="ex: Hakim">
        </div>
        <div class="field">
            <label>Nom</label>
            <input type="text" name="legal_rep_last_name" value="<?= h($c['legal_rep_last_name'] ?? '') ?>" placeholder="ex: N.">
        </div>
        <div class="field" style="grid-column:1/-1">
            <label>Fonction</label>
            <input type="text" name="legal_rep_role" value="<?= h($c['legal_rep_role'] ?? '') ?>" placeholder="ex: Président, Gérant, DG">
        </div>
    </div>
</details>

<!-- ============================================= -->
<!-- SECTION 7 — COMMUNICATION / BRANDING -->
<!-- ============================================= -->
<details class="societe-section">
    <summary>🎨 Communication &amp; branding</summary>
    <div class="societe-grid">
        <div class="field" style="grid-column:1/-1">
            <label>Logo actuel</label>
            <?php if (!empty($c['logo_url'])): ?>
                <div style="margin:8px 0; padding:12px; background:var(--sa-bg-2); border-radius:8px;">
                    <img src="<?= h($c['logo_url']) ?>" alt="Logo" style="max-height:80px; max-width:260px; background:white; padding:6px; border-radius:6px;">
                </div>
            <?php else: ?>
                <div style="padding:16px; background:var(--sa-bg-2); border:1px dashed var(--sa-border); border-radius:8px; color:var(--sa-ink-3); font-size:13px; text-align:center; margin-bottom:8px;">
                    Aucun logo pour l'instant
                </div>
            <?php endif; ?>
            <label style="display:block; margin-top:8px; font-size:13px;">Changer le logo (PNG, JPG, SVG — max 500 Ko)</label>
            <input type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml">
        </div>
        <div class="field">
            <label>Slogan</label>
            <input type="text" name="slogan" value="<?= h($c['slogan'] ?? '') ?>" placeholder="ex: L'art de mener vos projets" maxlength="255">
        </div>
        <div class="field">
            <label>Couleur principale</label>
            <input type="color" name="primary_color" value="<?= h($c['primary_color'] ?? '#059669') ?>">
        </div>
        <div class="field">
            <label>Couleur secondaire</label>
            <input type="color" name="secondary_color" value="<?= h($c['secondary_color'] ?? '#7F77DD') ?>">
        </div>
    </div>
</details>

<!-- ============================================= -->
<!-- SECTION 8 — CGV / RGPD -->
<!-- ============================================= -->
<details class="societe-section">
    <summary>📜 URLs légales</summary>
    <div class="societe-grid">
        <div class="field" style="grid-column:1/-1">
            <label>URL des CGV</label>
            <input type="url" name="cgv_url" value="<?= h($c['cgv_url'] ?? '') ?>" placeholder="https://exemple.fr/cgv">
        </div>
        <div class="field" style="grid-column:1/-1">
            <label>URL politique de confidentialité</label>
            <input type="url" name="privacy_url" value="<?= h($c['privacy_url'] ?? '') ?>" placeholder="https://exemple.fr/privacy">
        </div>
    </div>
</details>

<!-- ============================================= -->
<!-- ACTIONS -->
<!-- ============================================= -->
<div style="display:flex; gap:12px; justify-content:flex-end; margin-top:24px; padding:18px; background:var(--sa-bg-1); border:1px solid var(--sa-border); border-radius:12px; position:sticky; bottom:12px;">
    <a href="/fondateur-cockpit" class="sa-btn sa-btn-ghost">Annuler</a>
    <button type="submit" class="sa-btn sa-btn-violet">💾 Enregistrer les paramètres</button>
</div>

</form>

<style>
.societe-section {
    background: var(--sa-bg-1);
    border: 1px solid var(--sa-border);
    border-radius: 12px;
    margin-bottom: 12px;
    overflow: hidden;
}
.societe-section summary {
    padding: 16px 20px;
    cursor: pointer;
    font-weight: 600;
    color: var(--sa-ink);
    font-size: 15px;
    user-select: none;
    list-style: none;
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--sa-bg-2);
    border-radius: 12px;
    transition: background 0.15s;
}
.societe-section summary:hover { background: rgba(127, 119, 221, 0.08); }
.societe-section summary::before {
    content: '▶';
    font-size: 10px;
    color: var(--sa-violet);
    transition: transform 0.2s;
}
.societe-section[open] summary::before { transform: rotate(90deg); }
.societe-section[open] summary {
    border-radius: 12px 12px 0 0;
    border-bottom: 1px solid var(--sa-border);
}

.societe-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    padding: 20px;
}
@media (max-width: 640px) {
    .societe-grid { grid-template-columns: 1fr; }
}

.societe-grid .field label {
    display: block;
    font-size: 12px;
    color: var(--sa-ink-3);
    margin-bottom: 6px;
    font-weight: 500;
}
.societe-grid .field input[type="text"],
.societe-grid .field input[type="email"],
.societe-grid .field input[type="url"],
.societe-grid .field input[type="number"],
.societe-grid .field input[type="file"],
.societe-grid .field select {
    width: 100%;
    padding: 10px 12px;
    background: var(--sa-bg-2);
    border: 1px solid var(--sa-border);
    border-radius: 8px;
    color: var(--sa-ink);
    font-size: 14px;
    font-family: inherit;
}
.societe-grid .field input[type="color"] {
    width: 100%;
    height: 40px;
    border: 1px solid var(--sa-border);
    border-radius: 8px;
    cursor: pointer;
    background: var(--sa-bg-2);
    padding: 4px;
}
.societe-grid .field input:focus,
.societe-grid .field select:focus {
    outline: none;
    border-color: var(--sa-violet);
    box-shadow: 0 0 0 3px rgba(127, 119, 221, 0.15);
}
.societe-grid .field input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--sa-violet);
}
</style>

<?php sa_render_foot(); ?>
