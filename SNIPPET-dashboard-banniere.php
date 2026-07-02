<?php
/**
 * SNIPPET DASHBOARD — Bandeaux quota / grâce / overlay
 * --------------------------------------------------------------
 * À insérer DANS ta page dashboard.php existante,
 * juste APRÈS render_sidebar() et AVANT le contenu principal.
 * --------------------------------------------------------------
 */

// (Tu as déjà ces lignes dans dashboard.php, ne les duplique pas)
// require_once __DIR__ . '/config.php';
// require_once __DIR__ . '/includes-layout.php';

// AJOUTE cette ligne au début de ton dashboard.php (après les require_once existants) :
require_once __DIR__ . '/plan-helpers.php';

// (Reste du code de dashboard.php, récupération user, etc.)

// Récupère l'org_id de l'utilisateur courant (utilise la variable que tu as déjà)
$org_id = (int)($_SESSION['user_org_id'] ?? $current_user['org_id'] ?? 0);

// Code du dashboard.php existant...
render_head('Tableau de bord');
render_sidebar('dashboard');
?>

<main class="main">

  <?php
  // ============================================================
  // BLOC À INSÉRER ICI - JUSTE APRÈS <main class="main">
  // ============================================================

  // 1. Overlay régularisation (15j passés sans paiement) - bloque tout
  echo ak_render_overdue_overlay($pdo, $org_id);

  // 2. Bandeau de grâce (en cours de tolérance 15j)
  echo ak_render_grace_banner($pdo, $org_id);

  // 3. Bandeau quotas si limite ≥ 80%
  echo ak_render_quota_banner($pdo, $org_id);

  // ============================================================
  // FIN DU BLOC À INSÉRER
  // ============================================================
  ?>

  <!-- Le reste du dashboard (titre, stats, etc.) continue ici -->
  <div class="page-head">
    <h1>Tableau de bord</h1>
    <!-- ... -->
  </div>

  <!-- ... reste du dashboard ... -->

</main>

<?php render_foot(); ?>
