<?php
/**
 * mon-asso-sso.php — Intégration WordPress.
 * Deux briques SÛRES :
 *   1) Widget "Espace projets" LECTURE SEULE : un jeton par organisation
 *      qui autorise l'affichage public de la liste des projets (aucune
 *      connexion, aucune donnée privée, aucune action). À coller dans le
 *      shortcode WordPress.
 *   2) Connexion individuelle : chaque collaborateur se connecte à SON
 *      propre compte Assokit (bouton [assokit_espace] → /connexion). Pas
 *      d'usurpation d'identité, chacun garde ses droits.
 * Réservé aux administrateurs.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_login();
$user = current_user();
$org_id = (int)($user['org_id'] ?? 0);
$role = strtolower((string)($user['role'] ?? ''));
if ($org_id <= 0 || !in_array($role, ['admin','founder'], true)) {
    $_SESSION['flash_error'] = 'Réservé aux administrateurs.'; header('Location: /dashboard'); exit;
}
$csrf = h($_SESSION['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) { http_response_code(403); exit('CSRF'); }
    $act = $_POST['action'] ?? '';
    if ($act === 'generate') {
        // Un seul jeton actif par organisation : on révoque les précédents.
        $pdo->prepare("UPDATE org_espace_tokens SET revoked_at = NOW() WHERE org_id = ? AND revoked_at IS NULL")->execute([$org_id]);
        $tok = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO org_espace_tokens (org_id, token, label) VALUES (?,?,?)")->execute([$org_id, $tok, 'WordPress']);
        $_SESSION['flash_success'] = 'Widget activé.';
    } elseif ($act === 'revoke') {
        $pdo->prepare("UPDATE org_espace_tokens SET revoked_at = NOW() WHERE org_id = ? AND revoked_at IS NULL")->execute([$org_id]);
        $_SESSION['flash_success'] = 'Widget désactivé.';
    }
    header('Location: /mon-asso-sso'); exit;
}

// Jeton actif courant.
$st = $pdo->prepare("SELECT token, view_count FROM org_espace_tokens WHERE org_id = ? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1");
$st->execute([$org_id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
$token = $row['token'] ?? '';
$views = (int)($row['view_count'] ?? 0);

render_head('Intégration WordPress');
echo render_sidebar('mon-asso-sso');
?>
<main class="main">
  <div style="max-width:760px;margin:0 auto;padding:24px 22px;">
    <h1 style="display:flex;align-items:center;gap:11px;font-size:23px;color:#0F172A;"><?= ak_icon_badge('link','#059669',36) ?><span>Intégration WordPress</span></h1>
    <p style="color:#64748B;font-size:14px;">Affichez vos projets sur votre site WordPress, en toute sécurité.</p>

    <?php if (!empty($_SESSION['flash_success'])): ?>
      <div style="background:#D1FAE5;color:#065F46;padding:10px 14px;border-radius:10px;font-weight:600;font-size:13.5px;margin:12px 0;"><?= h($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
    <?php endif; ?>

    <!-- Brique 1 : Widget lecture seule -->
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:18px 20px;margin-top:14px;">
      <div style="font-weight:750;color:#0F172A;font-size:15.5px;margin-bottom:4px;">1 · Widget « Projets » (lecture seule)</div>
      <p style="font-size:13px;color:#64748B;margin:0 0 12px;">Affiche la liste de vos projets actifs sur une page WordPress. Aucune connexion, aucune donnée privée, personne ne devient administrateur.</p>

      <?php if ($token): ?>
        <p style="font-size:13.5px;color:#334155;margin:0 0 6px;">Statut : <strong style="color:#059669;">Activé</strong> · <?= $views ?> vue<?= $views>1?'s':'' ?></p>
        <div style="font-size:12.5px;color:#475569;margin:10px 0 4px;font-weight:600;">Collez ce shortcode dans une page WordPress :</div>
        <code style="display:block;word-break:break-all;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:10px;font-size:13px;">[assokit_projets token=&quot;<?= h($token) ?>&quot;]</code>
        <div style="font-size:12px;color:#94A3B8;margin:8px 0 12px;">Aperçu direct : <a href="/espace-public?t=<?= h($token) ?>" target="_blank" rel="noopener" style="color:#059669;font-weight:600;">ouvrir la vue publique →</a></div>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Désactiver le widget ? Le shortcode ne fonctionnera plus.')">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="revoke">
          <button style="padding:9px 16px;background:#FEE2E2;color:#991B1B;border:0;border-radius:10px;font-weight:700;cursor:pointer;">Désactiver</button>
        </form>
        <form method="POST" style="display:inline;margin-left:8px;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="generate">
          <button style="padding:9px 16px;background:#EEF2FF;color:#3730A3;border:0;border-radius:10px;font-weight:700;cursor:pointer;">Régénérer le lien</button>
        </form>
      <?php else: ?>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="generate">
          <button style="padding:10px 18px;background:linear-gradient(135deg,#10B981,#059669);color:#fff;border:0;border-radius:10px;font-weight:700;cursor:pointer;">Activer le widget</button>
        </form>
      <?php endif; ?>
    </div>

    <!-- Brique 2 : Connexion individuelle -->
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:18px 20px;margin-top:14px;">
      <div style="font-weight:750;color:#0F172A;font-size:15.5px;margin-bottom:4px;">2 · Connexion des collaborateurs</div>
      <p style="font-size:13px;color:#64748B;margin:0 0 10px;">Chaque collaborateur se connecte à <strong>son propre compte Assokit</strong>, avec <strong>ses propres droits</strong>. Aucune usurpation d'identité.</p>
      <div style="font-size:12.5px;color:#475569;margin:0 0 4px;font-weight:600;">Ajoutez ce bouton sur WordPress :</div>
      <code style="display:block;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:10px;font-size:13px;">[assokit_espace texte=&quot;Espace Assokit&quot;]</code>
    </div>

    <div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:12px;padding:14px 16px;margin-top:14px;font-size:12.5px;color:#92400E;line-height:1.5;">
      <strong>Sécurité :</strong> le widget est en lecture seule et ne montre que vos projets actifs (nom, avancement, dates). Il n'expose ni factures, ni adhérents, ni messages. Pour toute action, chacun doit se connecter à son compte.
    </div>
  </div>
</main>
<?php render_foot(); ?>
