<?php
/**
 * ============================================================
 * SNIPPET — Lien "Infos de facturation" pour la sidebar asso
 * ============================================================
 *
 * À COLLER dans ton includes-layout.php, dans la fonction
 * render_sidebar(), à l'endroit où tu affiches les liens du
 * menu pour l'admin d'asso.
 *
 * Visible UNIQUEMENT pour les admins d'asso (role = 'admin').
 * ============================================================
 */
?>

<!-- ========== DÉBUT LIEN FACTURATION ASSO ========== -->
<?php if (!empty($user['role']) && $user['role'] === 'admin'): ?>
    <a href="/mon-asso/facturation"
       class="sidebar-item <?= ($current_page ?? '') === 'facturation' ? 'active' : '' ?>"
       style="display:flex; align-items:center; gap:10px; padding:10px 14px; border-radius:10px; text-decoration:none; color:inherit; font-size:14px;">
        <span style="font-size:16px;">📄</span>
        <span>Infos de facturation</span>
    </a>
<?php endif; ?>
<!-- ========== FIN LIEN FACTURATION ASSO ========== -->
