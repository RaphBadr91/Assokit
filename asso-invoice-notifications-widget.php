<?php
/**
 * asso-invoice-notifications-widget.php
 * Widget à inclure dans le dashboard admin asso.
 * Affiche les paiements déclarés par les clients (à confirmer) et les relances
 * en attente de réponse.
 *
 * Usage : require_once __DIR__ . '/asso-invoice-notifications-widget.php';
 *         ak_render_invoice_notifications($pdo, (int)$user['org_id']);
 */

if (!function_exists('ak_render_invoice_notifications')) {

function ak_render_invoice_notifications(PDO $pdo, int $org_id): void
{
    // Une ligne par facture (sa demande de plus haut niveau, celle que traite l'envoi) :
    // le cron du matin n'en garde qu'une, mais d'anciennes demandes (J+15, J+30 et J+45
    // créées le même jour) peuvent encore coexister.
    $stmt = $pdo->prepare("
        SELECT * FROM v_pending_notifications
        WHERE org_id = :org
        ORDER BY due_at ASC, level DESC, id DESC
        LIMIT 50
    ");
    $stmt->execute([':org' => $org_id]);
    $notifs = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $iid = (int)$row['invoice_id'];
        if (!isset($notifs[$iid])) $notifs[$iid] = $row;
    }
    $notifs = array_slice(array_values($notifs), 0, 5);

    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Paiements déclarés par les clients (« J'ai payé » sur la page publique) : à confirmer
    $declared = [];
    try {
        $st = $pdo->prepare("
            SELECT i.id, i.invoice_number, i.amount_ttc_cents, i.client_marked_paid_at,
                   COALESCE(NULLIF(c.display_name, ''), JSON_UNQUOTE(JSON_EXTRACT(i.client_snapshot, '$.display_name'))) AS client_name
            FROM asso_invoices i
            LEFT JOIN asso_clients c ON c.id = i.client_id
            WHERE i.org_id = :org AND i.status IN ('pending','overdue') AND i.paid_at IS NULL
              AND i.client_marked_paid_at IS NOT NULL
            ORDER BY i.client_marked_paid_at DESC
            LIMIT 5
        ");
        $st->execute([':org' => $org_id]);
        $declared = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[invoice-notifications-widget] ' . $e->getMessage());
    }

    if ($declared): ?>
    <div class="card" style="padding:0; margin-bottom:18px; overflow:hidden; border:2px solid #6EE7B7;">
        <div style="background:linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%); padding:14px 18px; display:flex; align-items:center; gap:10px;">
            <div style="font-size:22px;">💚</div>
            <div>
                <div style="font-weight:700; color:#065F46;">Paiements déclarés par vos clients</div>
                <div style="font-size:12px; color:#047857;">Vérifiez la réception du règlement, puis confirmez-le sur la facture.</div>
            </div>
        </div>
        <?php foreach ($declared as $d): ?>
        <div style="padding:12px 18px; border-top:1px solid #A7F3D0; display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap;">
            <div>
                <strong style="font-size:14px;"><?= $h($d['invoice_number']) ?></strong>
                <span style="color:#6B7280; font-size:12px;">— <?= $h($d['client_name'] ?? '?') ?></span>
                <div style="font-size:13px; color:#4B5563; margin-top:2px;">
                    <?= $h(number_format($d['amount_ttc_cents'] / 100, 2, ',', ' ')) ?> € · déclaré le <?= $h(date('d/m/Y', strtotime($d['client_marked_paid_at']))) ?>
                </div>
            </div>
            <a href="/mon-asso-facture-edit?id=<?= (int)$d['id'] ?>#encaissement" style="padding:7px 14px; font-size:12px; background:#D1FAE5; color:#065F46; border:1px solid #6EE7B7; border-radius:6px; font-weight:600; text-decoration:none;">Vérifier et confirmer →</a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif;

    if (empty($notifs)) return;

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrf = $_SESSION['csrf_token'];
    ?>

    <div class="card" style="padding:0; margin-bottom:18px; overflow:hidden; border:2px solid #FCD34D;">
        <div style="background:linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%); padding:14px 18px; display:flex; align-items:center; gap:10px;">
            <div style="font-size:22px;">🔔</div>
            <div>
                <div style="font-weight:700; color:#92400E;">Relances en attente de votre validation</div>
                <div style="font-size:12px; color:#78350F;">Pour chaque facture, indique si elle a été payée ou pas.</div>
            </div>
        </div>

        <?php foreach ($notifs as $n):
            // Stades de relances-engine.php (même cadence que /relances, comptée depuis l'échéance)
            $level_labels = [1 => 'Rappel courtois', 2 => 'Relance ferme', 3 => 'Mise en demeure'];
            $level_colors = [1 => '#F59E0B', 2 => '#EA580C', 3 => '#DC2626'];
            $lvl = max(1, min(3, (int)$n['level']));
            $late = !empty($n['due_at']) ? (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($n['due_at'])))) / 86400) : 0;
        ?>
        <div style="padding:14px 18px; border-top:1px solid #FDE68A; display:grid; grid-template-columns:1fr auto; gap:14px; align-items:center;">
            <div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="background:<?= $level_colors[$lvl] ?>; color:white; padding:3px 8px; border-radius:6px; font-size:11px; font-weight:700;">
                        <?= $h($level_labels[$lvl]) ?>
                    </span>
                    <strong style="font-size:14px;"><?= $h($n['invoice_number']) ?></strong>
                    <span style="color:#6B7280; font-size:12px;">— <?= $h($n['client_name'] ?? '?') ?></span>
                </div>
                <div style="font-size:13px; color:#4B5563; margin-top:4px;">
                    Montant : <strong><?= $h(number_format($n['amount_ttc_cents'] / 100, 2, ',', ' ')) ?> €</strong>
                    · Échéance : <?= $h(date('d/m/Y', strtotime($n['due_at']))) ?>
                    <?php if ($late > 0): ?>(<?= $late ?> j de retard)<?php endif; ?>
                </div>
            </div>
            <form method="POST" action="/mon-asso-notification-respond" style="display:flex; gap:6px;">
                <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                <input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>">
                <button type="submit" name="response" value="paid" class="btn" style="padding:7px 14px; font-size:12px; background:#D1FAE5; color:#065F46; border:1px solid #6EE7B7; border-radius:6px; cursor:pointer; font-weight:600;" title="Cette facture a été payée">
                    ✅ OUI
                </button>
                <button type="submit" name="response" value="not_paid" class="btn" style="padding:7px 14px; font-size:12px; background:#FEE2E2; color:#991B1B; border:1px solid #FCA5A5; border-radius:6px; cursor:pointer; font-weight:600;" title="Toujours pas payée — envoyer relance">
                    ❌ NON
                </button>
                <button type="submit" name="response" value="no_more" class="btn" style="padding:7px 14px; font-size:12px; background:#E5E7EB; color:#374151; border:1px solid #D1D5DB; border-radius:6px; cursor:pointer; font-weight:600;" title="Plus de relance pour cette facture">
                    🚫 Stop
                </button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>

    <?php
}

}
