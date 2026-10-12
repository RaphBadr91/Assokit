<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/includes-assemblies.php';
require_login();
$user = current_user(); $org_id = (int)$user['org_id'];
if ($org_id <= 0) { header('Location: /dashboard'); exit; }  // compte sans association (agent de collectivité) : pas d'« org 0 » partagée
if ($user['role'] !== 'admin') { http_response_code(403); die('Réservé aux admins.'); }

$id = (int)($_GET['id'] ?? 0);
$ag = ag_load($pdo, $id, $org_id);
if (!$ag) { http_response_code(404); die('AG introuvable.'); }
$attendees = ag_load_attendees($pdo, $id);
$resos = ag_load_resolutions($pdo, $id);
$quorum = ag_quorum_status($ag, $attendees);
$m = ag_status_meta($ag['status']);
$frozen = ag_is_frozen($ag['status']);
$before_session = in_array($ag['status'], ['draft', 'sent'], true);
$not_deliberated = ag_not_deliberated($ag, $attendees);
$flash_error = $_SESSION['flash_assemblee'] ?? null;
$flash_warn = $_SESSION['flash_assemblee_warn'] ?? null;
$flash_ok = $_SESSION['flash_assemblee_ok'] ?? null;
unset($_SESSION['flash_assemblee'], $_SESSION['flash_assemblee_warn'], $_SESSION['flash_assemblee_ok']);

// Pouvoirs : mandataire de chaque représenté, et pouvoirs portés par chaque participant
$has_holder = ag_has_proxy_holder($pdo);
$att_by_id = ag_index_attendees($attendees);
$held = [];
foreach ($attendees as $a) {
    if ($a['status'] === 'proxy' && !empty($a['proxy_attendee_id'])) $held[(int)$a['proxy_attendee_id']][] = $a['full_name'];
}

$nb_invited = $nb_present = $nb_proxy = $nb_excused = $nb_absent = $nb_signed = $nb_convoked = $nb_coming = 0;
foreach ($attendees as $a) {
    $nb_invited++;
    if ($a['status'] === 'present') $nb_present++;
    if ($a['status'] === 'proxy') $nb_proxy++;
    if ($a['status'] === 'excused') $nb_excused++;
    if ($a['status'] === 'absent') $nb_absent++;
    if (!empty($a['signed_at'])) $nb_signed++;
    if (!empty($a['invitation_sent_at'])) $nb_convoked++;
    if (ag_rsvp($a) === 'yes') $nb_coming++;
}

render_head($ag['title']);
?>
<?= render_sidebar('assemblees') ?>
<main class="main">
  <div class="ag-page" style="max-width:1100px;">
    <a href="/assemblees" class="ag-back">← Assemblées</a>

    <?php if ($flash_error): ?>
      <div class="alert alert-error" style="margin:12px 0;">
        <span>⚠️</span>
        <div><?= h($flash_error) ?></div>
      </div>
    <?php endif; ?>
    <?php if ($flash_warn): ?>
      <div class="alert alert-warn" style="margin:12px 0;">
        <span>⚠️</span>
        <div><?= h($flash_warn) ?></div>
      </div>
    <?php endif; ?>
    <?php if ($flash_ok): ?>
      <div class="alert alert-success" style="margin:12px 0;">
        <span>✓</span>
        <div><?= h($flash_ok) ?></div>
      </div>
    <?php endif; ?>

    <div class="ag-detail-hero">
      <div>
        <span class="ag-status" style="background:<?= $m[2] ?>;color:<?= $m[1] ?>;"><?= $m[3] ?> <?= h($m[0]) ?></span>
        <h1 class="ag-pg-title"><?= h($ag['title']) ?></h1>
        <div class="ag-detail-meta">
          <?= h(ag_type_label($ag['type'])) ?> · 📅 <?= fr_format_date('%A %d %B %Y à %H:%M', strtotime($ag['scheduled_at'])) ?>
          <?php if ($ag['location']): ?> · 📍 <?= h($ag['location']) ?><?php endif; ?>
        </div>
      </div>
      <div class="ag-detail-actions">
        <?php if (!$frozen): ?>
          <a href="/assemblee-form?id=<?= (int)$ag['id'] ?>" class="ag-btn-ghost">✏️ Modifier</a>
        <?php endif; ?>
        <?php if ($ag['status'] === 'draft' || $ag['status'] === 'sent'): ?>
          <form method="POST" action="/action-assemblee" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="send_invitations">
            <input type="hidden" name="id" value="<?= (int)$ag['id'] ?>">
            <button type="submit" class="ag-btn-primary">📨 <?= $ag['convocation_sent_at'] ? 'Renvoyer convocations' : 'Envoyer convocations' ?></button>
          </form>
        <?php endif; ?>
        <?php if ($ag['status'] === 'sent' || $ag['status'] === 'draft'): ?>
          <form method="POST" action="/action-assemblee" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="open">
            <input type="hidden" name="id" value="<?= (int)$ag['id'] ?>">
            <button type="submit" class="ag-btn-go">🟢 Ouvrir la séance</button>
          </form>
        <?php elseif ($ag['status'] === 'in_progress'): ?>
          <form method="POST" action="/action-assemblee" style="display:inline;" onsubmit="return confirm(<?= h(json_encode($quorum['ok']
              ? 'Clôturer définitivement la séance et générer le PV ?'
              : 'Quorum non atteint (' . $quorum['present'] . '/' . $quorum['required'] . ') : en clôturant maintenant, aucune résolution ne sera adoptée et le PV les déclarera non délibérées. Clôturer quand même ?', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>)">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="close">
            <input type="hidden" name="id" value="<?= (int)$ag['id'] ?>">
            <button type="submit" class="ag-btn-primary">✅ Clôturer & générer PV</button>
          </form>
        <?php elseif ($ag['status'] === 'closed'): ?>
          <a href="/assemblee-pv?id=<?= (int)$ag['id'] ?>" target="_blank" class="ag-btn-primary">📄 Télécharger PV</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="ag-kpis">
      <div class="ag-kpi"><div class="ag-kpi-lbl">Participants</div><div class="ag-kpi-val"><?= $nb_invited ?></div><div class="ag-kpi-sub"><?= $nb_convoked ?> convoqué<?= $nb_convoked > 1 ? 's' : '' ?> par e-mail</div></div>
      <div class="ag-kpi"><div class="ag-kpi-lbl">Présents</div><div class="ag-kpi-val ag-green"><?= $nb_present ?></div><?php if ($before_session): ?><div class="ag-kpi-sub"><?= $nb_coming ?> annoncé<?= $nb_coming > 1 ? 's' : '' ?> · émargement en séance</div><?php endif; ?><?php if ($nb_proxy): ?><div class="ag-kpi-sub">+ <?= $nb_proxy ?> représenté<?= $nb_proxy > 1 ? 's' : '' ?> (pouvoir<?= $nb_proxy > 1 ? 's' : '' ?>)</div><?php endif; ?></div>
      <div class="ag-kpi"><div class="ag-kpi-lbl">Excusés</div><div class="ag-kpi-val"><?= $nb_excused ?></div></div>
      <div class="ag-kpi"><div class="ag-kpi-lbl">Signés</div><div class="ag-kpi-val ag-blue"><?= $nb_signed ?></div></div>
      <?php if ($ag['quorum_required']): ?>
        <div class="ag-kpi"><div class="ag-kpi-lbl">Quorum</div><div class="ag-kpi-val <?= $quorum['ok'] ? 'ag-green' : 'ag-red' ?>"><?= $quorum['present'] ?>/<?= (int)$ag['quorum_required'] ?></div><div class="ag-kpi-sub"><?= $quorum['ok'] ? '✓ atteint' : 'manquant' ?> · présents et représentés</div></div>
      <?php endif; ?>
    </div>

    <div class="ag-cols">
      <div class="ag-col-main">
        <!-- Convocations -->
        <div class="ag-card">
          <div class="ag-card-head">
            <h2>👥 Participants (<?= $nb_invited ?>)</h2>
            <?php if (!$frozen): ?>
            <div class="ag-card-actions">
              <button type="button" onclick="document.getElementById('ag-add-att').style.display='block'" class="ag-btn-mini">+ Ajouter</button>
              <form method="POST" action="/action-assemblee" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="import_adherents">
                <input type="hidden" name="id" value="<?= (int)$ag['id'] ?>">
                <button type="submit" class="ag-btn-mini" title="Importer les adhérents actifs à jour de leur adhésion (hors financeurs)">📥 Importer adhérents</button>
              </form>
            </div>
            <?php endif; ?>
          </div>

          <?php if (!$frozen): ?>
          <form method="POST" action="/action-assemblee" id="ag-add-att" style="display:none; margin-bottom:12px; padding:12px; background:#f9fafb; border-radius:8px;">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="add_attendee">
            <input type="hidden" name="id" value="<?= (int)$ag['id'] ?>">
            <div style="display:grid; grid-template-columns:1fr 1fr auto; gap:6px;">
              <input type="text" name="full_name" required placeholder="Nom complet *" style="padding:8px;border:1px solid #e5e7eb;border-radius:7px;">
              <input type="email" name="email" placeholder="Email (recommandé)" style="padding:8px;border:1px solid #e5e7eb;border-radius:7px;">
              <button type="submit" class="ag-btn-primary">Ajouter</button>
            </div>
          </form>
          <?php endif; ?>

          <?php if (empty($attendees)): ?>
            <p class="ag-muted">Aucun participant. Importe tes adhérents ou ajoute manuellement.</p>
          <?php else: ?>
          <table class="ag-table">
            <thead><tr><th>Nom</th><th>Email</th><th>Statut</th><th>Signature</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($attendees as $a):
              $st_meta = ['invited'=>['Invité','#6b7280'],'present'=>['Présent','#10B981'],'excused'=>['Excusé','#F59E0B'],'absent'=>['Absent','#EF4444'],'proxy'=>['Pouvoir','#8B5CF6']];
              $sm = $st_meta[$a['status']] ?? ['—','#6b7280'];
              $public_url = "https://" . ($_SERVER['HTTP_HOST'] ?? 'assokit.fr') . "/ag-public/" . $a['access_token'];
              $email_ok = !empty($a['email']) && filter_var($a['email'], FILTER_VALIDATE_EMAIL);
              $a_rsvp = ag_rsvp($a);
            ?>
              <tr>
                <td><strong><?= h($a['full_name']) ?></strong></td>
                <td>
                  <?= h($a['email'] ?: '—') ?>
                  <?php if (!$email_ok): ?><div class="ag-att-sub" style="color:#B45309;">⚠️ <?= empty($a['email']) ? 'Pas d’e-mail : à convoquer autrement' : 'E-mail invalide : non convoqué' ?></div>
                  <?php elseif (!empty($a['invitation_sent_at'])): ?><div class="ag-att-sub">✉️ Convoqué le <?= date('d/m', strtotime($a['invitation_sent_at'])) ?></div>
                  <?php elseif (!$frozen): ?><div class="ag-att-sub">Pas encore convoqué</div><?php endif; ?>
                </td>
                <td>
                  <?php if ($frozen): ?>
                    <span class="ag-mini-status" style="color:<?= $sm[1] ?>;"><?= $sm[0] ?></span>
                  <?php else: ?>
                    <form method="POST" action="/action-assemblee" style="display:inline;">
                      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="action" value="set_attendee_status">
                      <input type="hidden" name="id" value="<?= (int)$ag['id'] ?>">
                      <input type="hidden" name="attendee_id" value="<?= (int)$a['id'] ?>">
                      <select name="status" class="ag-att-status" style="color:<?= $sm[1] ?>;" onchange="this.form.submit()" aria-label="Statut de <?= h($a['full_name']) ?>">
                        <?php foreach ($st_meta as $k => $meta):
                          $dis = $k === 'present' && $ag['status'] !== 'in_progress' && $a['status'] !== 'present'; ?>
                          <option value="<?= $k ?>" <?= $a['status'] === $k ? 'selected' : '' ?> <?= $dis ? 'disabled' : '' ?>><?= $meta[0] ?><?= $dis ? ' (en séance)' : '' ?></option>
                        <?php endforeach; ?>
                        <?php if (!isset($st_meta[$a['status']])): ?><option value="" selected disabled>—</option><?php endif; ?>
                      </select>
                    </form>
                  <?php endif; ?>
                  <?php if ($a_rsvp === 'yes' && $a['status'] !== 'present'): ?><div class="ag-att-sub">A répondu : viendra</div>
                  <?php elseif ($before_session && $a['status'] === 'proxy' && ($a['rsvp'] ?? null) === 'yes'): ?><div class="ag-att-sub">A répondu : viendra finalement (pouvoir conservé d’ici là)</div><?php endif; ?>
                  <?php if ($a['status'] === 'proxy' && $has_holder):
                    $holder = !empty($a['proxy_attendee_id']) ? ($att_by_id[(int)$a['proxy_attendee_id']] ?? null) : null; ?>
                    <?php if ($frozen): ?>
                      <div class="ag-att-sub"><?= $holder ? 'Représenté·e par ' . h($holder['full_name']) : 'Pouvoir sans mandataire' ?></div>
                    <?php else: ?>
                      <form method="POST" action="/action-assemblee" class="ag-att-sub">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="set_proxy_holder">
                        <input type="hidden" name="id" value="<?= (int)$ag['id'] ?>">
                        <input type="hidden" name="attendee_id" value="<?= (int)$a['id'] ?>">
                        <label>Représenté·e par
                          <select name="proxy_to" class="ag-att-status" onchange="this.form.submit()" aria-label="Mandataire de <?= h($a['full_name']) ?>">
                            <option value="0"><?= $holder ? '— retirer le mandataire —' : '— choisir le mandataire —' ?></option>
                            <?php foreach ($attendees as $o):
                              if ((int)$o['id'] === (int)$a['id'] || (!ag_can_hold_proxy($o) && (int)$o['id'] !== (int)($a['proxy_attendee_id'] ?? 0))) continue; ?>
                              <option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === (int)($a['proxy_attendee_id'] ?? 0) ? 'selected' : '' ?>><?= h($o['full_name']) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                        <?php if (!$holder): ?><div style="color:#B45309;">⚠️ Sans mandataire, ce pouvoir compte pour le quorum mais ne vote pas.</div>
                        <?php elseif (!ag_proxy_valid($a, $att_by_id, $ag['status'])): ?><div style="color:#B45309;">⚠️ Mandataire <?= $before_session ? 'indisponible' : 'pas encore présent' ?> : pouvoir non compté.</div><?php endif; ?>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if (!empty($held[(int)$a['id']]) && $a['status'] !== 'proxy'): ?>
                    <div class="ag-att-sub">🤝 Porte <?= count($held[(int)$a['id']]) > 1 ? 'les pouvoirs' : 'le pouvoir' ?> de <?= h(implode(', ', $held[(int)$a['id']])) ?></div>
                  <?php endif; ?>
                </td>
                <td><?php if ($a['signed_at']): ?><span style="color:#10B981;">✓ <?= date('d/m H:i', strtotime($a['signed_at'])) ?></span><?php if (empty($a['signature_data'])): ?><div class="ag-att-sub">pointé par l’organisateur</div><?php endif; ?><?php else: ?>—<?php endif; ?></td>
                <td>
                  <button aria-label="Copier le lien de vote" type="button" class="ag-btn-mini" onclick="navigator.clipboard.writeText('<?= h($public_url) ?>');this.textContent='✓';setTimeout(()=>this.textContent='🔗',1500);" title="Copier le lien personnel">🔗</button>
                  <?php if (!$frozen): ?>
                  <form method="POST" action="/action-assemblee" style="display:inline;" onsubmit="return confirm('Retirer ?')">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="remove_attendee">
                    <input type="hidden" name="id" value="<?= (int)$ag['id'] ?>">
                    <input type="hidden" name="attendee_id" value="<?= (int)$a['id'] ?>">
                    <button type="submit" class="ag-btn-mini-rm">×</button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>

        <!-- Résolutions -->
        <div class="ag-card">
          <h2>🗳️ Résolutions (<?= count($resos) ?>)</h2>
          <?php if ($not_deliberated): ?>
            <p class="ag-muted" style="color:#B45309;">Quorum non atteint (<?= $quorum['present'] ?>/<?= $quorum['required'] ?>) : l’assemblée n’a pas pu délibérer valablement, aucune résolution n’est adoptée.</p>
          <?php endif; ?>
          <?php if (empty($resos)): ?>
            <p class="ag-muted">Aucune résolution. Ajoute-en via "Modifier".</p>
          <?php else: ?>
            <?php foreach ($resos as $i => $r):
              $stats = ag_resolution_stats($pdo, (int)$r['id']);
              $total = $stats['total'];
              $pct_for = $total > 0 ? round(($stats['for'] / $total) * 100) : 0;
              $pct_ag = $total > 0 ? round(($stats['against'] / $total) * 100) : 0;
              $pct_ab = $total > 0 ? round(($stats['abstain'] / $total) * 100) : 0;
              $rmeta = ['adopted'=>['Adoptée','#10B981','#ECFDF5'],'rejected'=>['Rejetée','#EF4444','#FEE2E2'],'pending'=>['En attente','#6b7280','#f3f4f6']];
              $rm = $not_deliberated ? ['Non délibérée','#B45309','#FEF3C7'] : ($rmeta[$r['result']] ?? $rmeta['pending']);
            ?>
            <div class="ag-reso-card">
              <div class="ag-reso-head">
                <div>
                  <div class="ag-reso-num">Résolution n°<?= $i + 1 ?></div>
                  <div class="ag-reso-name"><?= h($r['title']) ?></div>
                  <div class="ag-reso-type"><?= h(ag_vote_type_label((string)$r['vote_type'])) ?></div>
                </div>
                <span class="ag-mini-status" style="background:<?= $rm[2] ?>;color:<?= $rm[1] ?>;padding:4px 10px;border-radius:999px;"><?= $rm[0] ?></span>
              </div>
              <?php if ($total > 0): ?>
              <div class="ag-bar">
                <span class="ag-bar-for" style="width:<?= $pct_for ?>%;" title="Pour"></span>
                <span class="ag-bar-ag" style="width:<?= $pct_ag ?>%;" title="Contre"></span>
                <span class="ag-bar-ab" style="width:<?= $pct_ab ?>%;" title="Abstention"></span>
              </div>
              <div class="ag-bar-leg">
                <span>✓ Pour : <strong><?= $stats['for'] ?></strong> (<?= $pct_for ?>%)</span>
                <span>✗ Contre : <strong><?= $stats['against'] ?></strong> (<?= $pct_ag ?>%)</span>
                <span>○ Abstention : <strong><?= $stats['abstain'] ?></strong></span>
                <span class="ag-muted">Votants : <?= $total ?><?= $stats['by_proxy'] ? ' (dont ' . $stats['by_proxy'] . ' par pouvoir)' : '' ?> · Exprimés : <?= $stats['for'] + $stats['against'] ?></span>
              </div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="ag-col-side">
        <div class="ag-card">
          <h2>🔗 Lien public</h2>
          <p class="ag-muted" style="font-size:12px;">Chaque participant reçoit un lien personnel par email. Ils peuvent signer et voter via ce lien.</p>
          <?php if ($ag['convocation_sent_at']): ?>
            <p style="font-size:12px;color:#10B981;">✓ Convocations envoyées le <?= date('d/m à H:i', strtotime($ag['convocation_sent_at'])) ?></p>
          <?php endif; ?>
        </div>
        <?php if ($ag['notes_internal']): ?>
        <div class="ag-card">
          <h2>📝 Notes internes</h2>
          <p style="font-size:13px;white-space:pre-line;"><?= h($ag['notes_internal']) ?></p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
<style>
.alert-warn { background:#FEF3C7; color:#92400E; border:1px solid #FCD34D; }
.ag-detail-hero { display:flex; justify-content:space-between; align-items:flex-start; gap:18px; margin-bottom:18px; flex-wrap:wrap; }
.ag-detail-hero .ag-status { display:inline-block; margin-bottom:8px; }
.ag-detail-hero h1 { margin:0 0 6px; }
.ag-detail-meta { color:#6b7280; font-size:13.5px; }
.ag-detail-actions { display:flex; gap:8px; flex-wrap:wrap; }
.ag-kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:10px; margin-bottom:18px; }
.ag-kpi { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:12px 14px; }
.ag-kpi-lbl { font-size:10.5px; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em; font-weight:700; margin-bottom:4px; }
.ag-kpi-val { font-size:22px; font-weight:700; color:#111827; }
.ag-kpi-sub { font-size:10.5px; color:#6b7280; margin-top:2px; }
.ag-green { color:#10B981 !important; } .ag-blue { color:#3B82F6 !important; } .ag-red { color:#EF4444 !important; }
.ag-pg-title { font-size:24px; margin:0 0 4px; color:#111827; }
.ag-status { font-size:11px; padding:4px 10px; border-radius:999px; font-weight:600; white-space:nowrap; }
.ag-back { color:#6b7280; text-decoration:none; font-size:13px; display:inline-block; margin-bottom:12px; }
.ag-back:hover { color:#10B981; }
.ag-btn-primary { padding:9px 16px; background:#10B981; color:#fff; border:0; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; }
.ag-btn-primary:hover { background:#059669; }
.ag-btn-ghost { display:inline-flex; align-items:center; padding:8px 14px; background:#fff; border:1px solid #e5e7eb; color:#4b5563; text-decoration:none; border-radius:8px; font-size:13px; cursor:pointer; }
.ag-btn-ghost:hover { background:#f9fafb; }
.ag-page { max-width:1100px; margin:0 auto; padding:24px 22px; }
.ag-btn-go { padding:9px 16px; background:#10B981; color:#fff; border:0; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; }
.ag-btn-go:hover { background:#059669; }
.ag-cols { display:grid; grid-template-columns:1fr 280px; gap:16px; }
.ag-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 20px; margin-bottom:14px; }
.ag-card h2 { font-size:13px; margin:0 0 12px; color:#065F46; padding-bottom:6px; border-bottom:1px solid #f3f4f6; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; }
.ag-card-head { display:flex; justify-content:space-between; align-items:center; }
.ag-card-actions { display:flex; gap:6px; }
.ag-btn-mini { padding:5px 10px; background:#fff; border:1px solid #e5e7eb; border-radius:7px; font-size:11.5px; cursor:pointer; color:#4b5563; }
.ag-btn-mini:hover { background:#f9fafb; }
.ag-btn-mini-rm { background:transparent; border:0; color:#DC2626; cursor:pointer; font-size:16px; padding:2px 6px; }
.ag-muted { color:#6b7280; font-size:13px; }
.ag-table { width:100%; border-collapse:collapse; }
.ag-table th { text-align:left; padding:8px 10px; font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em; border-bottom:1px solid #e5e7eb; }
.ag-table td { padding:9px 10px; font-size:13px; border-bottom:1px solid #f3f4f6; }
.ag-mini-status { font-weight:600; font-size:12px; }
.ag-att-status { font-weight:600; font-size:12px; padding:3px 6px; border:1px solid #e5e7eb; border-radius:6px; background:#fff; font-family:inherit; cursor:pointer; }
.ag-att-sub { font-size:11px; color:#6b7280; margin-top:2px; }
.ag-reso-card { background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; margin-bottom:8px; }
.ag-reso-head { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; margin-bottom:10px; }
.ag-reso-num { font-size:10.5px; color:#6b7280; text-transform:uppercase; font-weight:700; letter-spacing:0.04em; }
.ag-reso-name { font-size:14px; font-weight:700; color:#111827; margin:2px 0; }
.ag-reso-type { font-size:11.5px; color:#6b7280; }
.ag-bar { display:flex; height:10px; border-radius:5px; overflow:hidden; background:#e5e7eb; margin-top:8px; }
.ag-bar-for { background:#10B981; }
.ag-bar-ag { background:#EF4444; }
.ag-bar-ab { background:#9ca3af; }
.ag-bar-leg { display:flex; gap:14px; flex-wrap:wrap; font-size:11.5px; color:#4b5563; margin-top:6px; }
.ag-kpi-sub { font-size:10.5px; color:#6b7280; margin-top:2px; }
.ag-red { color:#EF4444 !important; }
@media (max-width: 720px) { .ag-cols { grid-template-columns:1fr; } }
</style>
<?= render_foot() ?>
