<?php
/**
 * _relances-prospection.php — Les rappels dus, sur l'accueil.
 * ------------------------------------------------------------------
 * À inclure dans dashboard.php. Attend $pdo et $user.
 *
 * Un rappel programmé depuis la prospection ne servait à rien tant qu'on
 * n'ouvrait pas la page Prospection : rien ne le signalait ailleurs. Ce
 * bandeau le remonte là où l'on passe chaque matin.
 *
 * Il ne s'affiche que s'il y a effectivement quelque chose à faire —
 * un bandeau permanent affichant « 0 rappel » devient du décor qu'on
 * cesse de lire, et rendrait invisible le jour où il compte.
 *
 * Volontairement sans IA et sans mise en forme savante : c'est un
 * compte exact, tiré d'une requête, pas une suggestion.
 * ------------------------------------------------------------------
 */

// Même public que la page Prospection elle-même.
$rel_role = (string) ($user['role'] ?? '');
$rel_ok   = in_array($rel_role, ['admin', 'coordinator'], true);
if (!$rel_ok && function_exists('can')) $rel_ok = can('access_marketing');

if ($rel_ok && !empty($user['org_id'])):

$rel_org = (int) $user['org_id'];
$rel_retard = $rel_auj = 0;
$rel_liste = [];

try {
    // Deux compteurs distincts : « en retard » appelle une action tout de
    // suite, « aujourd'hui » se planifie dans la journée.
    $st = $pdo->prepare("SELECT
            SUM(callback_at < NOW())                                   AS retard,
            SUM(callback_at >= NOW() AND callback_at < CURDATE() + INTERVAL 1 DAY) AS auj
          FROM asso_prospection
          WHERE org_id = ? AND deleted_at IS NULL AND callback_at IS NOT NULL
            AND callback_at < CURDATE() + INTERVAL 1 DAY");
    $st->execute([$rel_org]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $rel_retard = (int) ($r['retard'] ?? 0);
    $rel_auj    = (int) ($r['auj'] ?? 0);

    if ($rel_retard + $rel_auj > 0) {
        $st = $pdo->prepare("SELECT id, prenom, nom, telephone, callback_at
                             FROM asso_prospection
                             WHERE org_id = ? AND deleted_at IS NULL
                               AND callback_at IS NOT NULL
                               AND callback_at < CURDATE() + INTERVAL 1 DAY
                             ORDER BY callback_at ASC LIMIT 3");
        $st->execute([$rel_org]);
        $rel_liste = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    // Migration de prospection pas encore passée : l'accueil ne doit pas
    // tomber pour autant.
    $rel_retard = $rel_auj = 0;
}

$rel_total = $rel_retard + $rel_auj;
if ($rel_total > 0):
?>
<style id="ak-relances-css">
.ak-rel{display:flex;align-items:center;gap:16px;flex-wrap:wrap;
  background:linear-gradient(135deg,#FFF7ED,#FEF3C7);border:1px solid #FCD34D;
  border-radius:14px;padding:15px 18px;margin:0 0 18px}
.ak-rel-ic{width:38px;height:38px;border-radius:11px;background:#F59E0B;color:#fff;
  display:flex;align-items:center;justify-content:center;flex-shrink:0}
.ak-rel-corps{flex:1;min-width:200px}
.ak-rel-t{font-size:14.5px;font-weight:700;color:#78350F;margin:0 0 3px}
.ak-rel-s{font-size:13px;color:#92400E;margin:0;line-height:1.5}
.ak-rel-s b{font-weight:700}
.ak-rel-btn{background:#92400E;color:#fff !important;text-decoration:none;
  padding:10px 17px;border-radius:10px;font-size:13.5px;font-weight:600;white-space:nowrap;flex-shrink:0}
.ak-rel-btn:hover{background:#78350F}
.ak-rel.retard{background:linear-gradient(135deg,#FEF2F2,#FEE2E2);border-color:#FCA5A5}
.ak-rel.retard .ak-rel-ic{background:#DC2626}
.ak-rel.retard .ak-rel-t{color:#7F1D1D}
.ak-rel.retard .ak-rel-s{color:#991B1B}
.ak-rel.retard .ak-rel-btn{background:#B91C1C}
.ak-rel.retard .ak-rel-btn:hover{background:#991B1B}
@media (max-width:640px){
  .ak-rel{padding:14px}
  .ak-rel-btn{width:100%;text-align:center}
}
</style>
<div class="ak-rel <?= $rel_retard > 0 ? 'retard' : '' ?>">
  <span class="ak-rel-ic">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/>
    </svg>
  </span>
  <div class="ak-rel-corps">
    <p class="ak-rel-t">
      <?php if ($rel_retard > 0): ?>
        <?= $rel_retard ?> rappel<?= $rel_retard > 1 ? 's' : '' ?> en retard<?php
          if ($rel_auj > 0): ?>, <?= $rel_auj ?> pour aujourd'hui<?php endif; ?>
      <?php else: ?>
        <?= $rel_auj ?> rappel<?= $rel_auj > 1 ? 's' : '' ?> à faire aujourd'hui
      <?php endif; ?>
    </p>
    <p class="ak-rel-s">
      <?php
        // Les trois premiers noms : de quoi décider d'y aller sans cliquer.
        $bouts = [];
        foreach ($rel_liste as $c) {
            $nom = trim(($c['prenom'] ?? '') . ' ' . ($c['nom'] ?? ''));
            if ($nom === '') $nom = (string) ($c['telephone'] ?? 'Sans nom');
            $t = strtotime((string) $c['callback_at']);
            $bouts[] = '<b>' . h($nom) . '</b> ('
                     . h($t ? date('d/m à H\hi', $t) : '—') . ')';
        }
        echo implode(' · ', $bouts);
        if ($rel_total > count($rel_liste)) {
            echo ' · et ' . ($rel_total - count($rel_liste)) . ' autre'
               . ($rel_total - count($rel_liste) > 1 ? 's' : '');
        }
      ?>
    </p>
  </div>
  <a class="ak-rel-btn" href="/prospection?f=a_rappeler">Voir les rappels</a>
</div>
<?php endif; endif; ?>
