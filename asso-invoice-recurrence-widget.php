<?php
/**
 * asso-invoice-recurrence-widget.php
 * --------------------------------------------------------------
 * Widget Dashboard — Prochaines factures récurrentes
 * À inclure : require __DIR__ . '/asso-invoice-recurrence-widget.php';
 *
 * v2 (DÉFENSIF) — Toute erreur est capturée localement.
 *                 Le widget ne peut JAMAIS faire crasher la page hôte.
 * --------------------------------------------------------------
 */

if (!isset($pdo) || !isset($org_id)) return; // garde-fou contexte

// Helpers (silencieux si manquants)
if (file_exists(__DIR__ . '/asso-recurrence-helpers.php')) {
    require_once __DIR__ . '/asso-recurrence-helpers.php';
}

// Tout est encapsulé : si un truc plante, on affiche un état dégradé
$widget_error = null;
$next_recs = [];
$counts = ['active' => 0, 'paused' => 0, 'ended' => 0, 'cancelled' => 0, 'total' => 0];
$tables_ready = false;

try {
    // 0. Vérifier que les tables existent (la migration v41 a-t-elle été passée ?)
    $check = $pdo->query("SHOW TABLES LIKE 'asso_invoice_recurrences'");
    $tables_ready = (bool)$check->fetch();

    if ($tables_ready) {
        // 1. Récupère les 5 prochaines récurrences actives
        $stRec = $pdo->prepare("
            SELECT r.id, r.title, r.next_run_date, r.frequency, r.interval_count,
                   c.display_name AS client_name, r.occurrences_count, r.max_occurrences
            FROM asso_invoice_recurrences r
            LEFT JOIN asso_clients c ON c.id = r.client_id
            WHERE r.org_id = :o AND r.status = 'active'
            ORDER BY r.next_run_date ASC
            LIMIT 5
        ");
        $stRec->execute([':o' => (int)$org_id]);
        $next_recs = $stRec->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 2. Compteurs
        if (function_exists('ak_recurrence_count_by_status')) {
            $counts = ak_recurrence_count_by_status($pdo, (int)$org_id);
        }
    }
} catch (Throwable $e) {
    // On capture tout, le widget s'auto-désactive proprement
    $widget_error = $e->getMessage();
    error_log('[asso-invoice-recurrence-widget] ' . $widget_error);
}

// Si tables absentes ET pas d'erreur : widget silencieux (migration pas encore passée)
if (!$tables_ready && !$widget_error) {
    return;
}
?>
<div style="background:white;border:1px solid #E2E8F0;border-radius:14px;padding:18px;font-family:Geist,system-ui,sans-serif;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
    <div style="display:flex;align-items:center;gap:10px;">
      <div style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#059669,#047857);display:flex;align-items:center;justify-content:center;color:white;font-size:18px;">🔄</div>
      <div>
        <div style="font-weight:700;font-size:15px;color:#0F172A;">Factures récurrentes</div>
        <div style="font-size:12px;color:#64748B;">
          <?php if ($widget_error): ?>
            Widget temporairement indisponible
          <?php else: ?>
            <?= (int)$counts['active'] ?> active(s) · <?= (int)$counts['paused'] ?> en pause
          <?php endif; ?>
        </div>
      </div>
    </div>
    <a href="/mon-asso-recurrences" style="font-size:13px;color:#059669;text-decoration:none;font-weight:600;">Tout voir →</a>
  </div>

  <?php if ($widget_error): ?>
    <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:12px;font-size:12px;color:#991B1B;">
      ⚠️ Le widget n'a pas pu charger ses données. Le reste du tableau de bord fonctionne normalement.
      <details style="margin-top:6px;"><summary style="cursor:pointer;color:#7F1D1D;">Détails techniques</summary>
        <code style="display:block;margin-top:6px;font-size:11px;background:white;padding:6px;border-radius:4px;word-break:break-all;"><?= htmlspecialchars(mb_substr($widget_error, 0, 300), ENT_QUOTES, 'UTF-8') ?></code>
      </details>
    </div>
  <?php elseif (empty($next_recs)): ?>
    <div style="text-align:center;padding:24px 12px;color:#64748B;font-size:13px;">
      <div style="font-size:32px;margin-bottom:6px;opacity:0.5;">📅</div>
      Aucune récurrence active pour le moment.<br>
      <a href="/mon-asso-recurrence-new" style="color:#059669;font-weight:600;text-decoration:none;">+ Créer ma première récurrence</a>
    </div>
  <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:8px;">
      <?php foreach ($next_recs as $r):
        $is_late  = $r['next_run_date'] < date('Y-m-d');
        $is_today = $r['next_run_date'] === date('Y-m-d');
      ?>
      <a href="/mon-asso-recurrence-edit?id=<?= (int)$r['id'] ?>" style="text-decoration:none;color:inherit;display:flex;justify-content:space-between;align-items:center;padding:10px 12px;background:<?= $is_late ? '#FEF2F2' : ($is_today ? '#ECFDF5' : '#F8FAFC') ?>;border-radius:8px;border-left:3px solid <?= $is_late ? '#DC2626' : ($is_today ? '#059669' : '#E2E8F0') ?>;">
        <div style="min-width:0;">
          <div style="font-weight:600;font-size:13px;color:#0F172A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($r['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
          <div style="font-size:11px;color:#64748B;">
            <?= htmlspecialchars($r['client_name'] ?? 'Sans client', ENT_QUOTES, 'UTF-8') ?>
            <?php if (function_exists('ak_recurrence_frequency_label')): ?>
              · <?= htmlspecialchars(ak_recurrence_frequency_label($r['frequency'], (int)$r['interval_count']), ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
          </div>
        </div>
        <div style="text-align:right;flex-shrink:0;">
          <div style="font-size:12px;font-weight:600;color:<?= $is_late ? '#DC2626' : ($is_today ? '#059669' : '#475569') ?>;">
            <?php if ($is_today): ?>Aujourd'hui<?php elseif ($is_late): ?>En retard<?php else: ?><?= htmlspecialchars(date('d/m', strtotime($r['next_run_date'])), ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
