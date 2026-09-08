<?php
/**
 * fondateur-cartes.php
 * --------------------------------------------------------------
 * Cartes de visite numériques (QR codes) — FONDATEUR.
 *
 * Chaque emplacement correspond à un QR code déjà généré. Le QR encode
 * l'URL (/carte/<slug>), jamais les coordonnées : modifier une fiche ici
 * met à jour tous les QR déjà imprimés et distribués. C'est la raison
 * d'être de cette page — sans elle il faudrait réimprimer à chaque
 * changement de numéro.
 *
 * Les douze emplacements sont créés par la migration
 * 2026-09-08-cartes-qr.sql et leurs QR sont pré-générés dans
 * assets/brand/qr-cartes/. Aucune génération à l'exécution.
 *
 * Sécurité :
 *   - Réservé aux fondateurs (is_founder=1 OU is_super_admin=1)
 *   - Écritures en POST + jeton CSRF
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();
$user = current_user();

$has_access = !empty($user['is_founder']) || !empty($user['is_super_admin']);
if (!$has_access) {
    http_response_code(403);
    die('Accès réservé aux fondateurs.');
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/** Champs modifiables. L'ordre sert aussi à construire l'UPDATE. */
const CARD_FIELDS = [
    'prenom', 'nom', 'fonction', 'societe',
    'tel', 'tel_fixe', 'email', 'site',
    'adr_rue', 'adr_cp', 'adr_ville', 'adr_pays',
    'linkedin', 'note',
];

$flash = null; $flash_ok = true; $migration_missing = false;

// ── Écritures ───────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!check_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Requête invalide (jeton CSRF manquant ou expiré).');
    }
    $slug = trim((string) ($_POST['slug'] ?? ''));
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save' && $slug !== '') {
            $vals = [];
            foreach (CARD_FIELDS as $f) $vals[] = trim((string) ($_POST[$f] ?? ''));

            // Une fiche sans nom n'a rien à publier : on la désactive plutôt
            // que de servir un QR menant à une carte vide.
            $named = ($vals[0] !== '' || $vals[1] !== '');
            $vals[] = $named ? 1 : 0;
            $vals[] = (int) $user['id'];
            $vals[] = $slug;

            $set = implode(', ', array_map(fn($f) => "$f = ?", CARD_FIELDS));
            $st = $pdo->prepare("UPDATE qr_cards SET $set, is_active = ?, updated_by = ?, updated_at = NOW() WHERE slug = ?");
            $st->execute($vals);

            $flash = $named
                ? "Carte $slug enregistrée. Le QR déjà imprimé pointe sur cette version."
                : "Carte $slug enregistrée et mise hors ligne : sans nom ni prénom, il n'y a rien à afficher.";

        } elseif ($action === 'toggle' && $slug !== '') {
            $st = $pdo->prepare("SELECT prenom, nom, is_active FROM qr_cards WHERE slug = ? LIMIT 1");
            $st->execute([$slug]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException("Carte introuvable.");
            $wanted = empty($row['is_active']) ? 1 : 0;
            if ($wanted === 1 && trim((string) $row['prenom'] . $row['nom']) === '') {
                $flash = "Carte $slug : renseignez au moins un nom avant de la mettre en ligne.";
                $flash_ok = false;
            } else {
                $pdo->prepare("UPDATE qr_cards SET is_active = ?, updated_by = ?, updated_at = NOW() WHERE slug = ?")
                    ->execute([$wanted, (int) $user['id'], $slug]);
                $flash = $wanted ? "Carte $slug remise en ligne." : "Carte $slug hors ligne : le QR affiche désormais une page « carte introuvable ».";
            }

        } elseif ($action === 'reset_scans' && $slug !== '') {
            $pdo->prepare("UPDATE qr_cards SET scan_count = 0, last_scan_at = NULL WHERE slug = ?")->execute([$slug]);
            $flash = "Compteur de la carte $slug remis à zéro.";
        }
    } catch (Throwable $e) {
        $flash = "Échec de l'enregistrement : " . $e->getMessage();
        $flash_ok = false;
    }
}

// ── Lecture ─────────────────────────────────────────────────────────────────
$cards = [];
try {
    // Tri naturel : sans CAST, '10' se glisserait entre '1' et '2'.
    $cards = $pdo->query("SELECT * FROM qr_cards ORDER BY CAST(slug AS UNSIGNED), slug")
                 ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $migration_missing = true;
}

$total_scans = array_sum(array_column($cards, 'scan_count'));
$actives = count(array_filter($cards, fn($c) => !empty($c['is_active'])));

render_head('Cartes de visite QR');
render_sidebar('fondateur-cartes');
?>

<main class="main">
<style>
  .qc-head{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-bottom:22px}
  .qc-kpis{display:flex;gap:10px;flex-wrap:wrap}
  .qc-kpi{background:#fff;border:1px solid #E7EEEA;border-radius:12px;padding:10px 16px;min-width:104px}
  .qc-kpi b{display:block;font-size:21px;line-height:1.2}
  .qc-kpi span{font-size:11.5px;color:#5F6D66;text-transform:uppercase;letter-spacing:.04em}
  .qc-flash{border-radius:12px;padding:13px 16px;margin-bottom:20px;font-size:14px;border:1px solid}
  .qc-ok{background:#ECFDF5;border-color:#A7F3D0;color:#065F46}
  .qc-ko{background:#FEF2F2;border-color:#FECACA;color:#991B1B}
  .qc-card{background:#fff;border:1px solid #E7EEEA;border-radius:16px;margin-bottom:14px;overflow:hidden}
  .qc-bar{display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid #F1F5F4;flex-wrap:wrap}
  .qc-slug{width:34px;height:34px;border-radius:10px;background:#ECF7F2;color:#059669;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0}
  .qc-who{font-weight:700;font-size:15px}
  .qc-url{font-size:12.5px;color:#5F6D66;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
  .qc-tag{font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;letter-spacing:.03em}
  .qc-on{background:#D1FAE5;color:#065F46}
  .qc-off{background:#F1F5F9;color:#64748B}
  .qc-scan{margin-left:auto;font-size:12.5px;color:#5F6D66;text-align:right}
  .qc-body{display:grid;grid-template-columns:200px 1fr;gap:22px;padding:18px;align-items:start}
  .qc-qr{text-align:center}
  .qc-qr img{width:170px;height:170px;display:block;margin:0 auto 8px;border:1px solid #E7EEEA;border-radius:10px}
  .qc-dl{font-size:12px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap}
  .qc-dl a{color:#059669;text-decoration:none;font-weight:600}
  .qc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,190px),1fr));gap:12px}
  .qc-grid label{display:block;font-size:12px;font-weight:600;color:#45544D;margin-bottom:5px}
  .qc-grid input{width:100%;padding:9px 12px;border:1px solid #E7EEEA;border-radius:9px;font:inherit;font-size:13.5px}
  .qc-grid input:focus{outline:2px solid #05966933;border-color:#059669}
  .qc-wide{grid-column:1/-1}
  .qc-acts{display:flex;gap:9px;flex-wrap:wrap;margin-top:14px;align-items:center}
  .qc-btn{padding:10px 18px;border-radius:10px;border:none;background:#059669;color:#fff;font:inherit;font-weight:600;font-size:14px;cursor:pointer}
  .qc-btn.sec{background:#fff;border:1px solid #E7EEEA;color:#45544D}
  .qc-note{font-size:12.5px;color:#5F6D66;margin-left:auto}
  @media (max-width:760px){ .qc-body{grid-template-columns:1fr} .qc-qr img{width:150px;height:150px} }
</style>

<div class="qc-head">
  <div>
    <h1 style="font-size:23px;margin:0 0 5px">Cartes de visite QR</h1>
    <p style="color:#5F6D66;font-size:14px;margin:0;max-width:640px">
      Le QR code contient l'adresse de la fiche, pas les coordonnées. Vous pouvez donc
      corriger un numéro ici : tous les QR déjà imprimés et distribués suivent, sans réimpression.
    </p>
  </div>
  <div class="qc-kpis">
    <div class="qc-kpi"><b><?= (int) $actives ?></b><span>en ligne</span></div>
    <div class="qc-kpi"><b><?= count($cards) ?></b><span>emplacements</span></div>
    <div class="qc-kpi"><b><?= (int) $total_scans ?></b><span>ouvertures</span></div>
  </div>
</div>

<?php if ($flash): ?>
  <div class="qc-flash <?= $flash_ok ? 'qc-ok' : 'qc-ko' ?>"><?= h($flash) ?></div>
<?php endif; ?>

<?php if ($migration_missing): ?>
  <div class="qc-flash qc-ko">
    La table <code>qr_cards</code> n'existe pas encore. Lancez la migration sur le serveur :<br>
    <code style="display:inline-block;margin-top:8px">php migrations/run.php 2026-09-08-cartes-qr.sql</code>
  </div>
<?php endif; ?>

<?php foreach ($cards as $c):
    $slug = (string) $c['slug'];
    $on = !empty($c['is_active']);
    $who = trim((string) $c['prenom'] . ' ' . (string) $c['nom']);
    $qrPng = "/assets/brand/qr-cartes/qr-carte-$slug-marque.png";
    $hasQr = is_file(__DIR__ . $qrPng);
?>
<div class="qc-card">
  <div class="qc-bar">
    <span class="qc-slug"><?= h($slug) ?></span>
    <span class="qc-who"><?= $who !== '' ? h($who) : '<span style="color:#8A968F;font-weight:400">Emplacement libre</span>' ?></span>
    <span class="qc-tag <?= $on ? 'qc-on' : 'qc-off' ?>"><?= $on ? 'EN LIGNE' : 'HORS LIGNE' ?></span>
    <a class="qc-url" href="/carte/<?= h($slug) ?>" target="_blank" rel="noopener">assokit.fr/carte/<?= h($slug) ?></a>
    <span class="qc-scan">
      <?= (int) $c['scan_count'] ?> ouverture<?= (int) $c['scan_count'] > 1 ? 's' : '' ?>
      <?php if (!empty($c['last_scan_at'])): ?><br><span style="font-size:11.5px">dernière : <?= h(date('d/m/Y H:i', strtotime((string) $c['last_scan_at']))) ?></span><?php endif; ?>
    </span>
  </div>

  <div class="qc-body">
    <div class="qc-qr">
      <?php if ($hasQr): ?>
        <img src="<?= h($qrPng) ?>" alt="QR code de la carte <?= h($slug) ?>">
        <div class="qc-dl">
          <a href="/assets/brand/qr-cartes/qr-carte-<?= h($slug) ?>.svg" download>SVG</a>
          <a href="/assets/brand/qr-cartes/qr-carte-<?= h($slug) ?>.png" download>PNG</a>
          <a href="<?= h($qrPng) ?>" download>Vert</a>
        </div>
      <?php else: ?>
        <div style="font-size:12.5px;color:#5F6D66;padding:20px 0">QR non généré pour cet emplacement.</div>
      <?php endif; ?>
    </div>

    <!-- Un seul formulaire par carte : l'action vient du bouton cliqué, ce qui
         évite d'imbriquer des <form> (interdit) ou de casser la ligne de boutons. -->
    <form method="post" action="">
      <input type="hidden" name="slug" value="<?= h($slug) ?>">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

      <div class="qc-grid">
        <div><label>Prénom</label><input name="prenom" value="<?= h($c['prenom']) ?>" autocomplete="off"></div>
        <div><label>Nom</label><input name="nom" value="<?= h($c['nom']) ?>" autocomplete="off"></div>
        <div><label>Fonction</label><input name="fonction" value="<?= h($c['fonction']) ?>" placeholder="Fondateur"></div>
        <div><label>Société</label><input name="societe" value="<?= h($c['societe']) ?>"></div>
        <div><label>Mobile</label><input name="tel" value="<?= h($c['tel']) ?>" placeholder="+33 6 12 34 56 78"></div>
        <div><label>Fixe</label><input name="tel_fixe" value="<?= h($c['tel_fixe']) ?>"></div>
        <div><label>E-mail</label><input name="email" type="email" value="<?= h($c['email']) ?>"></div>
        <div><label>Site</label><input name="site" value="<?= h($c['site']) ?>"></div>
        <div class="qc-wide"><label>Rue</label><input name="adr_rue" value="<?= h($c['adr_rue']) ?>"></div>
        <div><label>Code postal</label><input name="adr_cp" value="<?= h($c['adr_cp']) ?>"></div>
        <div><label>Ville</label><input name="adr_ville" value="<?= h($c['adr_ville']) ?>"></div>
        <div><label>Pays</label><input name="adr_pays" value="<?= h($c['adr_pays']) ?>"></div>
        <div class="qc-wide"><label>LinkedIn</label><input name="linkedin" value="<?= h($c['linkedin']) ?>" placeholder="https://www.linkedin.com/in/…"></div>
        <div class="qc-wide"><label>Note <span style="font-weight:400;color:#5F6D66">— apparaît dans la fiche enregistrée</span></label><input name="note" value="<?= h($c['note']) ?>"></div>
      </div>

      <div class="qc-acts">
        <button class="qc-btn" type="submit" name="action" value="save">Enregistrer</button>

        <!-- Ces deux actions ignorent les champs saisis : elles relisent l'état
             en base. Une modification non enregistrée serait donc perdue. -->
        <button class="qc-btn sec" type="submit" name="action" value="toggle"
                onclick="return confirm('<?= $on ? 'Mettre la carte ' . h($slug) . ' hors ligne ? Le QR affichera « carte introuvable ».' : 'Mettre la carte ' . h($slug) . ' en ligne ?' ?>\n\nLes modifications non enregistrées seront perdues.')">
          <?= $on ? 'Mettre hors ligne' : 'Mettre en ligne' ?>
        </button>

        <?php if ((int) $c['scan_count'] > 0): ?>
        <button class="qc-btn sec" type="submit" name="action" value="reset_scans"
                onclick="return confirm('Remettre le compteur de la carte <?= h($slug) ?> à zéro ?')">
          Remettre le compteur à zéro
        </button>
        <?php endif; ?>

        <?php if (!empty($c['updated_at'])): ?>
          <span class="qc-note">Modifiée le <?= h(date('d/m/Y à H:i', strtotime((string) $c['updated_at']))) ?></span>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>

<p style="color:#5F6D66;font-size:13px;margin:22px 0 40px;max-width:700px">
  À l'impression : 2 cm de côté au minimum, marge blanche conservée, jamais inversé
  (clair sur foncé). Sur un fond coloré, posez le QR dans un rectangle blanc.
</p>

</main>
<?php render_foot(); ?>
