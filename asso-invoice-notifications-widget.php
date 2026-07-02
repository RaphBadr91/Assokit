<?php
/**
 * asso-invoice-notifications-widget.php
 * Widget à inclure dans le dashboard admin asso.
 * Affiche les relances en attente de réponse.
 *
 * Usage : require_once __DIR__ . '/asso-invoice-notifications-widget.php';
 *         ak_render_invoice_notifications($pdo, (int)$user['org_id']);
 */

if (!function_exists('ak_render_invoice_notifications')) {

function ak_render_invoice_notifications(PDO $pdo, int $org_id): void
{
    $stmt = $pdo->prepare("
        SELECT * FROM v_pending_notifications
        WHERE org_id = :org
        LIMIT 5
    ");
    $stmt->execute([':org' => $org_id]);
    $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($notifs)) return;

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrf = $_SESSION['csrf_token'];

    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
            $level_labels = [1 => 'J+15', 2 => 'J+30', 3 => 'J+45'];
            $level_colors = [1 => '#F59E0B', 2 => '#EA580C', 3 => '#DC2626'];
        ?>
        <div style="padding:14px 18px; border-top:1px solid #FDE68A; display:grid; grid-template-columns:1fr auto; gap:14px; align-items:center;">
            <div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="background:<?= $level_colors[$n['level']] ?>; color:white; padding:3px 8px; border-radius:6px; font-size:11px; font-weight:700;">
                        <?= $h($level_labels[$n['level']]) ?>
                    </span>
                    <strong style="font-size:14px;"><?= $h($n['invoice_number']) ?></strong>
                    <span style="color:#6B7280; font-size:12px;">— <?= $h($n['client_name'] ?? '?') ?></span>
                </div>
                <div style="font-size:13px; color:#4B5563; margin-top:4px;">
                    Montant : <strong><?= $h(number_format($n['amount_ttc_cents'] / 100, 2, ',', ' ')) ?> €</strong>
                    · Échéance : <?= $h(date('d/m/Y', strtotime($n['due_at']))) ?>
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
