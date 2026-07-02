<?php
/**
 * ============================================================
 * ASSOKIT — org-billing-form-template.php
 * Formulaire facturation (réutilisable)
 * ============================================================
 * VARIABLES REQUISES avant include :
 *   $org_id    : int
 *   $info      : array (résultat de get_org_billing_info)
 *   $csrf      : string (token CSRF)
 *   $action_url: string (URL de post)
 *   $can_edit  : bool (peut éditer ou juste lire)
 *   $flash     : ?array (message de feedback)
 * ============================================================
 */
?>

<?php if (!empty($flash)): ?>
    <div class="sa-alert <?= $flash['type'] === 'success' ? 'sa-alert-success' : 'sa-alert-error' ?>" style="margin-bottom:16px;">
        <span style="font-size:18px;"><?= $flash['type'] === 'success' ? '✅' : '⚠️' ?></span>
        <div><?= h($flash['message']) ?></div>
    </div>
<?php endif; ?>

<!-- Barre de progression complétude -->
<div class="sa-card" style="margin-bottom:16px; background:rgba(127, 119, 221, 0.04); border-color:rgba(127, 119, 221, 0.2); display:flex; align-items:center; gap:16px; padding:16px 20px;">
    <div style="flex-shrink:0; width:48px; height:48px; border-radius:50%; display:flex; align-items:center; justify-content:center; background:conic-gradient(<?= $info['completeness_percent'] >= 70 ? '#10B981' : ($info['completeness_percent'] >= 40 ? '#F59E0B' : '#EF4444') ?> <?= $info['completeness_percent'] ?>%, rgba(255,255,255,0.1) <?= $info['completeness_percent'] ?>%); position:relative;">
        <div style="position:absolute; inset:4px; background:var(--sa-bg-1); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; color:var(--sa-ink);">
            <?= $info['completeness_percent'] ?>%
        </div>
    </div>
    <div style="flex:1;">
        <div style="font-size:14px; font-weight:600; color:var(--sa-ink); margin-bottom:3px;">
            Complétude : <?= $info['completeness_filled'] ?>/<?= $info['completeness_total'] ?> champs renseignés
        </div>
        <div style="font-size:12.5px; color:var(--sa-ink-3);">
            <?php if ($info['completeness_percent'] >= 70): ?>
                ✅ Infos suffisantes pour émettre des factures conformes.
            <?php elseif ($info['completeness_percent'] >= 40): ?>
                🟡 Complétez quelques champs clés pour une facturation professionnelle.
            <?php else: ?>
                ⚠️ Des champs importants manquent. Pensez à compléter l'essentiel.
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($info['billing_updated_at'] && $info['billing_updated_by_name']): ?>
    <div class="sa-card" style="margin-bottom:16px; background:rgba(127, 119, 221, 0.04); border-color:rgba(127, 119, 221, 0.15); font-size:12.5px; color:var(--sa-ink-3); padding:10px 16px;">
        💾 Dernière modification :
        <strong style="color:var(--sa-ink-2);"><?= h(date('d/m/Y à H:i', strtotime($info['billing_updated_at']))) ?></strong>
        par <strong style="color:var(--sa-ink-2);"><?= h($info['billing_updated_by_name']) ?></strong>
    </div>
<?php endif; ?>

<?php if (!$can_edit): ?>
    <div class="sa-alert sa-alert-info" style="margin-bottom:16px;">
        <span style="font-size:18px;">👁</span>
        <div>
            <strong>Mode lecture seule.</strong>
            Vous consultez ces informations. Seul le Fondateur ou l'admin de cette association peut les modifier.
        </div>
    </div>
<?php else: ?>
    <div class="sa-alert sa-alert-info" style="margin-bottom:16px;">
        <span style="font-size:18px;">ℹ️</span>
        <div>
            <strong>Tous les champs sont optionnels.</strong>
            Les factures s'adapteront automatiquement aux informations disponibles.
        </div>
    </div>
<?php endif; ?>

<form method="POST" action="<?= h($action_url) ?>" id="form-org-billing" <?= !$can_edit ? 'onsubmit="return false;"' : '' ?>>
<input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
<input type="hidden" name="org_id" value="<?= (int)$org_id ?>">

<!-- ============================================= -->
<!-- SECTION 1 — IDENTITÉ JURIDIQUE -->
<!-- ============================================= -->
<details class="orgbill-section" open>
    <summary>🏛 Identité juridique</summary>
    <div class="orgbill-grid">
        <div class="field">
            <label>Raison sociale</label>
            <input type="text" name="legal_name" value="<?= h($info['legal_name'] ?? '') ?>"
                   placeholder="Si différente du nom de l'asso"
                   <?= !$can_edit ? 'disabled' : '' ?>>
        </div>
        <div class="field">
            <label>Forme juridique</label>
            <select name="legal_form" <?= !$can_edit ? 'disabled' : '' ?>>
                <option value="">—</option>
                <?php foreach (['Association loi 1901', 'Association reconnue d\'utilité publique', 'Fondation', 'Fonds de dotation', 'Association loi 1908 (Alsace-Moselle)', 'Coopérative', 'SCIC', 'SCOP', 'Autre'] as $f): ?>
                    <option value="<?= h($f) ?>" <?= ($info['legal_form'] ?? '') === $f ? 'selected' : '' ?>><?= h($f) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>SIREN</label>
            <input type="text" name="siren" value="<?= h($info['siren'] ?? '') ?>"
                   placeholder="123 456 789" maxlength="20"
                   <?= !$can_edit ? 'disabled' : '' ?>>
        </div>
        <div class="field">
            <label>SIRET (siège)</label>
            <input type="text" name="siret" value="<?= h($info['siret'] ?? '') ?>"
                   placeholder="123 456 789 00012" maxlength="20"
                   <?= !$can_edit ? 'disabled' : '' ?>>
        </div>
        <div class="field" style="grid-column:1/-1">
            <label>N° RNA (Répertoire National des Associations)</label>
            <input type="text" name="rna_number" value="<?= h($info['rna_number'] ?? '') ?>"
                   placeholder="W123456789" maxlength="20"
                   <?= !$can_edit ? 'disabled' : '' ?>>
            <small style="color:var(--sa-ink-3); margin-top:4px; display:block; font-size:11.5px;">Numéro attribué à chaque association loi 1901 (commence par W).</small>
        </div>
    </div>
</details>

<!-- ============================================= -->
<!-- SECTION 2 — TVA -->
<!-- ============================================= -->
<details class="orgbill-section">
    <summary>📋 TVA</summary>
    <div class="orgbill-grid">
        <div class="field" style="grid-column:1/-1">
            <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                <input type="checkbox" name="vat_subject" value="1"
                       <?= !empty($info['vat_subject']) ? 'checked' : '' ?>
                       <?= !$can_edit ? 'disabled' : '' ?>>
                <span>Association assujettie à la TVA</span>
            </label>
            <small style="color:var(--sa-ink-3); margin-top:4px; display:block; font-size:11.5px;">La plupart des associations loi 1901 ne sont pas assujetties à la TVA.</small>
        </div>
        <div class="field" style="grid-column:1/-1">
            <label>N° TVA intracommunautaire</label>
            <input type="text" name="vat_number" value="<?= h($info['vat_number'] ?? '') ?>"
                   placeholder="FR XX XXXXXXXXX"
                   <?= !$can_edit ? 'disabled' : '' ?>>
        </div>
    </div>
</details>

<!-- ============================================= -->
<!-- SECTION 3 — ADRESSE DE FACTURATION -->
<!-- ============================================= -->
<details class="orgbill-section">
    <summary>📍 Adresse de facturation</summary>
    <div class="orgbill-grid">
        <div class="field" style="grid-column:1/-1">
            <label>Rue</label>
            <input type="text" name="billing_address_street" value="<?= h($info['billing_address_street'] ?? '') ?>"
                   placeholder="12 rue des Lilas" <?= !$can_edit ? 'disabled' : '' ?>>
        </div>
        <div class="field" style="grid-column:1/-1">
            <label>Complément</label>
            <input type="text" name="billing_address_complement" value="<?= h($info['billing_address_complement'] ?? '') ?>"
                   placeholder="Bâtiment B, 3e étage" <?= !$can_edit ? 'disabled' : '' ?>>
        </div>
        <div class="field">
            <label>Code postal</label>
            <input type="text" name="billing_address_zip" value="<?= h($info['billing_address_zip'] ?? '') ?>"
                   placeholder="75012" maxlength="20" <?= !$can_edit ? 'disabled' : '' ?>>
        </div>
        <div class="field">
            <label>Ville</label>
            <input type="text" name="billing_address_city" value="<?= h($info['billing_address_city'] ?? '') ?>"
                   placeholder="Paris" <?= !$can_edit ? 'disabled' : '' ?>>
        </div>
        <div class="field" style="grid-column:1/-1">
            <label>Pays</label>
            <select name="billing_address_country" <?= !$can_edit ? 'disabled' : '' ?>>
                <option value="">—</option>
                <?php foreach (['FR' => 'France', 'BE' => 'Belgique', 'CH' => 'Suisse', 'LU' => 'Luxembourg', 'CA' => 'Canada', 'MA' => 'Maroc', 'TN' => 'Tunisie', 'DZ' => 'Algérie', 'DE' => 'Allemagne', 'ES' => 'Espagne', 'IT' => 'Italie', 'PT' => 'Portugal', 'GB' => 'Royaume-Uni', 'US' => 'États-Unis'] as $code => $label): ?>
                    <option value="<?= h($code) ?>" <?= ($info['billing_address_country'] ?? '') === $code ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</details>

<!-- ============================================= -->
<!-- SECTION 4 — CONTACTS FACTURATION -->
<!-- ============================================= -->
<details class="orgbill-section" open>
    <summary>📧 Contacts facturation</summary>
    <div class="orgbill-grid">
        <div class="field">
            <label>Email de facturation</label>
            <input type="email" name="billing_email" value="<?= h($info['billing_email'] ?? '') ?>"
                   placeholder="tresorier@asso.fr" <?= !$can_edit ? 'disabled' : '' ?>>
            <?php if (!empty($info['billing_email_is_fallback']) && !empty($info['billing_email_effective'])): ?>
                <small style="color:var(--sa-violet); margin-top:4px; display:block; font-size:11.5px;">
                    💡 Actuellement les factures sont envoyées à l'admin principal :
                    <strong><?= h($info['billing_email_effective']) ?></strong>
                </small>
            <?php endif; ?>
        </div>
        <div class="field">
            <label>Téléphone</label>
            <input type="text" name="billing_phone" value="<?= h($info['billing_phone'] ?? '') ?>"
                   placeholder="+33 1 23 45 67 89" <?= !$can_edit ? 'disabled' : '' ?>>
        </div>
    </div>
</details>

<!-- ============================================= -->
<!-- SECTION 5 — REPRÉSENTANT LÉGAL -->
<!-- ============================================= -->
<details class="orgbill-section">
    <summary>👤 Représentant légal (Président)</summary>
    <div class="orgbill-grid">
        <div class="field">
            <label>Prénom</label>
            <input type="text" name="president_first_name" value="<?= h($info['president_first_name'] ?? '') ?>"
                   <?= !$can_edit ? 'disabled' : '' ?>>
        </div>
        <div class="field">
            <label>Nom</label>
            <input type="text" name="president_last_name" value="<?= h($info['president_last_name'] ?? '') ?>"
                   <?= !$can_edit ? 'disabled' : '' ?>>
        </div>
        <div class="field" style="grid-column:1/-1">
            <label>Fonction</label>
            <select name="president_role" <?= !$can_edit ? 'disabled' : '' ?>>
                <option value="">—</option>
                <?php foreach (['Président', 'Présidente', 'Co-président·e', 'Secrétaire général', 'Secrétaire générale', 'Trésorier', 'Trésorière', 'Directeur', 'Directrice', 'Autre'] as $r): ?>
                    <option value="<?= h($r) ?>" <?= ($info['president_role'] ?? '') === $r ? 'selected' : '' ?>><?= h($r) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</details>

<!-- ============================================= -->
<!-- SECTION 6 — RÉFÉRENCES & NOTES (Fondateur only) -->
<!-- ============================================= -->
<?php if (isset($is_founder_view) && $is_founder_view): ?>
<details class="orgbill-section">
    <summary>📎 Références &amp; notes internes <span style="font-size:11px; background:#FCD34D; color:#78350F; padding:2px 7px; border-radius:4px; margin-left:8px;">🏗️ FONDATEUR</span></summary>
    <div class="orgbill-grid">
        <div class="field" style="grid-column:1/-1">
            <label>Référence externe (comptable)</label>
            <input type="text" name="external_ref" value="<?= h($info['external_ref'] ?? '') ?>"
                   placeholder="ex: CLIENT-42" <?= !$can_edit ? 'disabled' : '' ?>>
        </div>
        <div class="field" style="grid-column:1/-1">
            <label>Notes internes (invisibles à l'asso)</label>
            <textarea name="internal_notes" rows="4"
                      <?= !$can_edit ? 'disabled' : '' ?>
                      placeholder="Infos sensibles ou particularités à retenir..."
                      style="width:100%; padding:10px 12px; background:var(--sa-bg-2); border:1px solid var(--sa-border); border-radius:8px; color:var(--sa-ink); font-size:14px; font-family:inherit; resize:vertical;"><?= h($info['internal_notes'] ?? '') ?></textarea>
        </div>
    </div>
</details>
<?php endif; ?>

<?php if ($can_edit): ?>
<!-- ACTIONS -->
<div style="display:flex; gap:12px; justify-content:flex-end; margin-top:24px; padding:18px; background:var(--sa-bg-1); border:1px solid var(--sa-border); border-radius:12px; position:sticky; bottom:12px; z-index:10;">
    <a href="<?= h($cancel_url ?? '/super-admin/associations') ?>" class="sa-btn sa-btn-ghost">Annuler</a>
    <button type="submit" class="sa-btn sa-btn-violet">💾 Enregistrer les infos</button>
</div>
<?php endif; ?>

</form>

<style>
.orgbill-section {
    background: var(--sa-bg-1);
    border: 1px solid var(--sa-border);
    border-radius: 12px;
    margin-bottom: 12px;
    overflow: hidden;
}
.orgbill-section summary {
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
.orgbill-section summary:hover { background: rgba(127, 119, 221, 0.08); }
.orgbill-section summary::before {
    content: '▶';
    font-size: 10px;
    color: var(--sa-violet);
    transition: transform 0.2s;
}
.orgbill-section[open] summary::before { transform: rotate(90deg); }
.orgbill-section[open] summary {
    border-radius: 12px 12px 0 0;
    border-bottom: 1px solid var(--sa-border);
}

.orgbill-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    padding: 20px;
}
@media (max-width: 640px) { .orgbill-grid { grid-template-columns: 1fr; } }

.orgbill-grid .field label {
    display: block;
    font-size: 12px;
    color: var(--sa-ink-3);
    margin-bottom: 6px;
    font-weight: 500;
}
.orgbill-grid .field input[type="text"],
.orgbill-grid .field input[type="email"],
.orgbill-grid .field select {
    width: 100%;
    padding: 10px 12px;
    background: var(--sa-bg-2);
    border: 1px solid var(--sa-border);
    border-radius: 8px;
    color: var(--sa-ink);
    font-size: 14px;
    font-family: inherit;
}
.orgbill-grid .field input:focus,
.orgbill-grid .field select:focus {
    outline: none;
    border-color: var(--sa-violet);
    box-shadow: 0 0 0 3px rgba(127, 119, 221, 0.15);
}
.orgbill-grid .field input:disabled,
.orgbill-grid .field select:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}
.orgbill-grid .field input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--sa-violet);
}
</style>
