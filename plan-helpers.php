<?php
/**
 * plan-helpers.php
 * --------------------------------------------------------------
 * Système de gestion des plans tarifaires Assokit
 *
 * Helpers principaux :
 *   ak_get_current_plan($pdo, $org_id)          → array plan + subscription
 *   ak_get_usage($pdo, $org_id)                 → array usage actuel
 *   ak_can_create_invoice($pdo, $org_id)        → ['ok'=>bool, 'reason'=>string]
 *   ak_can_create_quote($pdo, $org_id)
 *   ak_can_add_adherent($pdo, $org_id)
 *   ak_can_add_contact($pdo, $org_id)
 *   ak_can_add_user($pdo, $org_id)
 *   ak_can_use_ai_text($pdo, $org_id)
 *   ak_can_use_ai_image($pdo, $org_id)
 *   ak_can_send_email($pdo, $org_id, $count)
 *   ak_can_use_feature($pdo, $org_id, $feature)
 *   ak_render_quota_banner($pdo, $org_id)       → HTML bandeau si limite proche
 *   ak_render_overdue_overlay($pdo, $org_id)    → HTML overlay régularisation
 * --------------------------------------------------------------
 */

if (!defined('AK_PLAN_HELPERS_LOADED')) {
    define('AK_PLAN_HELPERS_LOADED', true);

// Auto-load Stripe helpers (silent si absent — Stripe optionnel)
@require_once __DIR__ . '/stripe-config-helpers.php';
@require_once __DIR__ . '/stripe-helpers.php';

// ============================================================
// 1. Plan + abonnement actuel
// ============================================================

function ak_get_current_plan(PDO $pdo, int $org_id): ?array {
    static $cache = [];
    if (isset($cache[$org_id])) return $cache[$org_id];

    try {
        $st = $pdo->prepare("
            SELECT
              s.id AS subscription_id, s.status, s.started_at, s.current_period_end,
              s.grace_period_end, s.downgraded_at, s.cancelled_at, s.previous_plan_id, s.notes,
              p.*,
              p.id AS plan_id
            FROM asso_subscriptions s
            INNER JOIN asso_plans p ON p.id = s.plan_id
            WHERE s.org_id = :o
            LIMIT 1
        ");
        $st->execute([':o' => $org_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            // Pas d'abonnement → on auto-crée un Démarrage
            $defaultPlanId = (int)$pdo->query("SELECT id FROM asso_plans WHERE slug='demarrage' LIMIT 1")->fetchColumn();
            if ($defaultPlanId > 0) {
                $pdo->prepare("INSERT INTO asso_subscriptions (org_id, plan_id, status) VALUES (:o, :p, 'active')")
                    ->execute([':o' => $org_id, ':p' => $defaultPlanId]);
                return ak_get_current_plan($pdo, $org_id); // récursif sécurisé une seule fois
            }
            return null;
        }

        $cache[$org_id] = $row;
        return $row;
    } catch (Throwable $e) {
        error_log('[ak_get_current_plan] ' . $e->getMessage());
        return null;
    }
}

// ============================================================
// 2. Usage actuel d'une organisation
// ============================================================

function ak_get_usage(PDO $pdo, int $org_id): array {
    $usage = [
        'adherents'        => 0,
        'invoices_total'   => 0,
        'quotes_total'     => 0,
        'contacts'         => 0,
        'users'            => 0,
        'ai_text_month'    => 0,
        'ai_image_month'   => 0,
        'emails_month'     => 0,
    ];

    try {
        // Adhérents (users actifs liés à l'org via memberships ou table dédiée)
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM users
            WHERE org_id = :o AND deleted_at IS NULL AND is_active = 1
        ");
        $st->execute([':o' => $org_id]);
        $usage['users'] = (int)$st->fetchColumn();

        // Si table asso_adherents existe
        if (ak_table_exists($pdo, 'asso_adherents')) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM asso_adherents WHERE org_id = :o AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
            try { $st->execute([':o' => $org_id]); $usage['adherents'] = (int)$st->fetchColumn(); } catch (Throwable $e) {}
        } else {
            $usage['adherents'] = $usage['users']; // fallback
        }

        // Factures
        $st = $pdo->prepare("SELECT COUNT(*) FROM asso_invoices WHERE org_id = :o");
        $st->execute([':o' => $org_id]);
        $usage['invoices_total'] = (int)$st->fetchColumn();

        // Devis (si table existe)
        if (ak_table_exists($pdo, 'asso_quotes')) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM asso_quotes WHERE org_id = :o");
            try { $st->execute([':o' => $org_id]); $usage['quotes_total'] = (int)$st->fetchColumn(); } catch (Throwable $e) {}
        }

        // Contacts (clients)
        if (ak_table_exists($pdo, 'asso_clients')) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM asso_clients WHERE org_id = :o");
            try { $st->execute([':o' => $org_id]); $usage['contacts'] = (int)$st->fetchColumn(); } catch (Throwable $e) {}
        }

        // IA texte sur le mois en cours
        if (ak_table_exists($pdo, 'asso_ai_logs')) {
            $st = $pdo->prepare("
                SELECT COUNT(*) FROM asso_ai_logs
                WHERE org_id = :o
                  AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')
                  AND (tool_type IS NULL OR tool_type NOT LIKE 'image-%')
            ");
            try { $st->execute([':o' => $org_id]); $usage['ai_text_month'] = (int)$st->fetchColumn(); } catch (Throwable $e) {}

            $st = $pdo->prepare("
                SELECT COUNT(*) FROM asso_ai_logs
                WHERE org_id = :o
                  AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')
                  AND tool_type LIKE 'image-%'
            ");
            try { $st->execute([':o' => $org_id]); $usage['ai_image_month'] = (int)$st->fetchColumn(); } catch (Throwable $e) {}
        }

        // Emails sur le mois
        if (ak_table_exists($pdo, 'asso_email_diffusions')) {
            $st = $pdo->prepare("
                SELECT COALESCE(SUM(recipient_count), 0) FROM asso_email_diffusions
                WHERE org_id = :o
                  AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')
                  AND status = 'sent'
            ");
            try { $st->execute([':o' => $org_id]); $usage['emails_month'] = (int)$st->fetchColumn(); } catch (Throwable $e) {}
        }

    } catch (Throwable $e) {
        error_log('[ak_get_usage] ' . $e->getMessage());
    }

    return $usage;
}

// Helper : table existe-t-elle ?
function ak_table_exists(PDO $pdo, string $tableName): bool {
    static $cache = [];
    if (isset($cache[$tableName])) return $cache[$tableName];
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t");
        $st->execute([':t' => $tableName]);
        $cache[$tableName] = (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { $cache[$tableName] = false; }
    return $cache[$tableName];
}

// ============================================================
// 3. Vérifications de quotas (booléen + raison)
// ============================================================

/**
 * Vérifie si l'organisation peut créer une nouvelle facture.
 * Doux : retourne false si limite atteinte mais on peut éventuellement l'autoriser.
 *
 * @return array ['ok' => bool, 'reason' => string, 'limit' => int|null, 'current' => int]
 */
function ak_can_create_invoice(PDO $pdo, int $org_id): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return ['ok' => false, 'reason' => 'Aucun plan actif', 'limit' => 0, 'current' => 0];

    if ($plan['limit_invoices_total'] === null) return ['ok' => true, 'reason' => '', 'limit' => null, 'current' => 0];

    $usage = ak_get_usage($pdo, $org_id);
    $current = (int)$usage['invoices_total'];
    $limit = (int)$plan['limit_invoices_total'];

    if ($current >= $limit) {
        return ['ok' => false, 'reason' => "Limite atteinte : {$current}/{$limit} factures sur le plan {$plan['name']}", 'limit' => $limit, 'current' => $current];
    }
    return ['ok' => true, 'reason' => '', 'limit' => $limit, 'current' => $current];
}

function ak_can_create_quote(PDO $pdo, int $org_id): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return ['ok' => false, 'reason' => 'Aucun plan actif', 'limit' => 0, 'current' => 0];

    if ($plan['limit_quotes_total'] === null) return ['ok' => true, 'reason' => '', 'limit' => null, 'current' => 0];

    $usage = ak_get_usage($pdo, $org_id);
    $current = (int)$usage['quotes_total'];
    $limit = (int)$plan['limit_quotes_total'];

    if ($current >= $limit) {
        return ['ok' => false, 'reason' => "Limite atteinte : {$current}/{$limit} devis sur le plan {$plan['name']}", 'limit' => $limit, 'current' => $current];
    }
    return ['ok' => true, 'reason' => '', 'limit' => $limit, 'current' => $current];
}

function ak_can_add_adherent(PDO $pdo, int $org_id): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return ['ok' => false, 'reason' => 'Aucun plan actif', 'limit' => 0, 'current' => 0];

    if ($plan['limit_adherents'] === null) return ['ok' => true, 'reason' => '', 'limit' => null, 'current' => 0];

    $usage = ak_get_usage($pdo, $org_id);
    $current = (int)$usage['adherents'];
    $limit = (int)$plan['limit_adherents'];

    if ($current >= $limit) {
        return ['ok' => false, 'reason' => "Limite atteinte : {$current}/{$limit} adhérents sur le plan {$plan['name']}", 'limit' => $limit, 'current' => $current];
    }
    return ['ok' => true, 'reason' => '', 'limit' => $limit, 'current' => $current];
}

function ak_can_add_contact(PDO $pdo, int $org_id): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return ['ok' => false, 'reason' => 'Aucun plan actif', 'limit' => 0, 'current' => 0];

    if ($plan['limit_contacts'] === null) return ['ok' => true, 'reason' => '', 'limit' => null, 'current' => 0];

    $usage = ak_get_usage($pdo, $org_id);
    $current = (int)$usage['contacts'];
    $limit = (int)$plan['limit_contacts'];

    if ($limit === 0) {
        return ['ok' => false, 'reason' => "Les contacts ne sont pas inclus dans le plan {$plan['name']}", 'limit' => 0, 'current' => $current];
    }
    if ($current >= $limit) {
        return ['ok' => false, 'reason' => "Limite atteinte : {$current}/{$limit} contacts sur le plan {$plan['name']}", 'limit' => $limit, 'current' => $current];
    }
    return ['ok' => true, 'reason' => '', 'limit' => $limit, 'current' => $current];
}

function ak_can_add_user(PDO $pdo, int $org_id): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return ['ok' => false, 'reason' => 'Aucun plan actif', 'limit' => 0, 'current' => 0];

    if ($plan['limit_users'] === null) return ['ok' => true, 'reason' => '', 'limit' => null, 'current' => 0];

    $usage = ak_get_usage($pdo, $org_id);
    $current = (int)$usage['users'];
    $limit = (int)$plan['limit_users'];

    if ($current >= $limit) {
        return ['ok' => false, 'reason' => "Limite atteinte : {$current}/{$limit} utilisateurs sur le plan {$plan['name']}", 'limit' => $limit, 'current' => $current];
    }
    return ['ok' => true, 'reason' => '', 'limit' => $limit, 'current' => $current];
}

/**
 * STRICT - Vérifie si l'IA texte peut être utilisée
 * Avec gestion bonus première semaine
 */
function ak_can_use_ai_text(PDO $pdo, int $org_id): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return ['ok' => false, 'reason' => 'Aucun plan actif', 'limit' => 0, 'current' => 0];

    if ($plan['limit_ai_text_per_month'] === null) return ['ok' => true, 'reason' => '', 'limit' => null, 'current' => 0];

    $usage = ak_get_usage($pdo, $org_id);
    $current = (int)$usage['ai_text_month'];
    $limit = (int)$plan['limit_ai_text_per_month'];

    // Bonus première semaine (si abonnement < 7 jours)
    $bonus = 0;
    if (!empty($plan['bonus_ai_first_week']) && !empty($plan['started_at'])) {
        $days_since = (time() - strtotime($plan['started_at'])) / 86400;
        if ($days_since <= 7) {
            $bonus = (int)$plan['bonus_ai_first_week'];
        }
    }
    $effective_limit = $limit + $bonus;

    if ($limit === 0 && $bonus === 0) {
        return ['ok' => false, 'reason' => "L'IA texte n'est pas incluse dans le plan {$plan['name']}", 'limit' => 0, 'current' => $current];
    }
    if ($current >= $effective_limit) {
        $msg = "Limite atteinte : {$current}/{$effective_limit} générations IA texte ce mois";
        if ($bonus > 0) $msg .= " (bonus 1ère semaine inclus)";
        return ['ok' => false, 'reason' => $msg, 'limit' => $effective_limit, 'current' => $current];
    }
    return ['ok' => true, 'reason' => '', 'limit' => $effective_limit, 'current' => $current, 'bonus' => $bonus];
}

function ak_can_use_ai_image(PDO $pdo, int $org_id): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return ['ok' => false, 'reason' => 'Aucun plan actif', 'limit' => 0, 'current' => 0];

    if ($plan['limit_ai_image_per_month'] === null) return ['ok' => true, 'reason' => '', 'limit' => null, 'current' => 0];

    $usage = ak_get_usage($pdo, $org_id);
    $current = (int)$usage['ai_image_month'];
    $limit = (int)$plan['limit_ai_image_per_month'];

    if ($limit === 0) {
        return ['ok' => false, 'reason' => "L'IA image n'est pas incluse dans le plan {$plan['name']}", 'limit' => 0, 'current' => $current];
    }
    if ($current >= $limit) {
        return ['ok' => false, 'reason' => "Limite atteinte : {$current}/{$limit} générations IA image ce mois", 'limit' => $limit, 'current' => $current];
    }
    return ['ok' => true, 'reason' => '', 'limit' => $limit, 'current' => $current];
}

/**
 * STRICT - Vérifie si N emails peuvent être envoyés
 */
function ak_can_send_email(PDO $pdo, int $org_id, int $count = 1): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return ['ok' => false, 'reason' => 'Aucun plan actif', 'limit' => 0, 'current' => 0];

    // Si la feature email_diffusion est désactivée → bloqué
    if (!$plan['feature_email_diffusion']) {
        return ['ok' => false, 'reason' => "La diffusion email n'est pas incluse dans le plan {$plan['name']}", 'limit' => 0, 'current' => 0, 'feature_disabled' => true];
    }

    if ($plan['limit_emails_per_month'] === null) return ['ok' => true, 'reason' => '', 'limit' => null, 'current' => 0];

    $usage = ak_get_usage($pdo, $org_id);
    $current = (int)$usage['emails_month'];
    $limit = (int)$plan['limit_emails_per_month'];

    if ($limit === 0) {
        return ['ok' => false, 'reason' => "La diffusion email n'est pas incluse dans le plan {$plan['name']}", 'limit' => 0, 'current' => $current, 'feature_disabled' => true];
    }
    if ($current + $count > $limit) {
        return ['ok' => false, 'reason' => "Quota dépassé : {$current}+{$count} dépasse la limite de {$limit} emails/mois", 'limit' => $limit, 'current' => $current];
    }
    return ['ok' => true, 'reason' => '', 'limit' => $limit, 'current' => $current];
}

function ak_can_use_feature(PDO $pdo, int $org_id, string $feature): bool {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return false;
    $key = 'feature_' . $feature;
    return !empty($plan[$key]);
}

// ====== Features capees (ex: Bilan analytique en essai) ======
function ak_feature_usage_count(PDO $pdo, int $org_id, string $key): int {
    try {
        $st = $pdo->prepare("SELECT used_count FROM org_feature_usage WHERE org_id = :o AND feature_key = :k LIMIT 1");
        $st->execute([':o' => $org_id, ':k' => $key]);
        $v = $st->fetchColumn();
        return $v === false ? 0 : (int)$v;
    } catch (Throwable $e) { error_log('[ak_feature_usage_count] ' . $e->getMessage()); return 0; }
}
function ak_feature_usage_record(PDO $pdo, int $org_id, string $key): void {
    try {
        $pdo->prepare("INSERT INTO org_feature_usage (org_id, feature_key, used_count, first_used_at, last_used_at)
                       VALUES (:o, :k, 1, NOW(), NOW())
                       ON DUPLICATE KEY UPDATE used_count = used_count + 1, last_used_at = NOW()")
            ->execute([':o' => $org_id, ':k' => $key]);
    } catch (Throwable $e) { error_log('[ak_feature_usage_record] ' . $e->getMessage()); }
}
function ak_trial_gate(PDO $pdo, int $org_id, string $key, int $cap = 1): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan || empty($plan['is_trial'])) return ['blocked'=>false,'trial'=>false,'used'=>0,'cap'=>$cap,'plan'=>$plan];
    $used = ak_feature_usage_count($pdo, $org_id, $key);
    if ($used >= $cap) return ['blocked'=>true,'trial'=>true,'used'=>$used,'cap'=>$cap,'plan'=>$plan];
    ak_feature_usage_record($pdo, $org_id, $key);
    return ['blocked'=>false,'trial'=>true,'used'=>$used+1,'cap'=>$cap,'plan'=>$plan];
}

// Gate compta analytique : illimite pour les plans avec feature_advanced_stats
// (Pro, Sur-mesure) ; 1 seule generation pour les autres (Essentiel, Demarrage,
// Demo/pro-essai) via le compteur org_feature_usage 'bilan_analytique'.
function ak_bilan_analytique_gate(PDO $pdo, int $org_id): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!empty($plan['feature_advanced_stats'])) {
        return ['blocked'=>false,'unlimited'=>true,'used'=>0,'cap'=>null,'plan'=>$plan];
    }
    $used = ak_feature_usage_count($pdo, $org_id, 'bilan_analytique');
    if ($used >= 1) {
        return ['blocked'=>true,'unlimited'=>false,'used'=>$used,'cap'=>1,'plan'=>$plan];
    }
    ak_feature_usage_record($pdo, $org_id, 'bilan_analytique');
    return ['blocked'=>false,'unlimited'=>false,'used'=>$used+1,'cap'=>1,'plan'=>$plan];
}

// ============================================================
// 4. Statut abonnement (overdue, grâce, etc.)
// ============================================================

/**
 * Statut commercial de l'abonnement
 *
 * @return array ['status', 'is_overdue', 'in_grace_period', 'days_remaining', 'show_overlay']
 */
function ak_get_subscription_status(PDO $pdo, int $org_id): array {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) {
        return ['status' => 'none', 'is_overdue' => false, 'in_grace_period' => false, 'days_remaining' => 0, 'show_overlay' => false];
    }

    $status = (string)$plan['status'];
    $now = new DateTime();
    $in_grace = false;
    $days_remaining = 0;
    $show_overlay = false;
    $is_pending = ($status === 'pending_payment');

    // === [PACK 6.2 fix] pending_payment → afficher le bandeau orange ===
    // Si le paiement est en attente, on affiche le bandeau de rappel.
    // - Si current_period_end existe et est dépassée → calcul des jours restants
    // - Sinon → on affiche un message générique "paiement en attente"
    if ($status === 'pending_payment') {
        $in_grace = true;
        if (!empty($plan['current_period_end'])) {
            $period_end = new DateTime($plan['current_period_end']);
            if ($period_end > $now) {
                $days_remaining = max(1, (int)$period_end->diff($now)->format('%a'));
            } else {
                // Période expirée mais pas encore basculé en overdue par le CRON
                // → on affiche quand même 15 jours par défaut (en attendant le CRON)
                $days_remaining = 15;
            }
        } else {
            $days_remaining = 15; // valeur par défaut
        }
    }

    // === Cas overdue (grâce 15 jours en cours OU expirée) ===
    if ($status === 'overdue' && !empty($plan['grace_period_end'])) {
        $grace_end = new DateTime($plan['grace_period_end']);
        if ($grace_end > $now) {
            $in_grace = true;
            $days_remaining = max(1, (int)$grace_end->diff($now)->format('%a'));
        } else {
            // Grâce expirée → on AFFICHE l'overlay flou bloquant
            $show_overlay = true;
            $in_grace = false;
        }
    }

    return [
        'status' => $status,
        'is_overdue' => $status === 'overdue',
        'is_pending' => $is_pending,
        'in_grace_period' => $in_grace,
        'days_remaining' => $days_remaining,
        'show_overlay' => $show_overlay,
        'plan_name' => $plan['name'],
        'plan_slug' => $plan['slug'],
    ];
}

// ============================================================
// 5. UI : Bandeau de quota (à afficher dans le dashboard)
// ============================================================

function ak_render_quota_banner(PDO $pdo, int $org_id): string {
    $plan = ak_get_current_plan($pdo, $org_id);
    if (!$plan) return '';

    $usage = ak_get_usage($pdo, $org_id);
    $alerts = [];

    // Vérifier chaque quota et alerter à 80%+
    $checks = [
        ['adherents', 'limit_adherents', 'adhérents', '👥'],
        ['invoices_total', 'limit_invoices_total', 'factures', '📋'],
        ['quotes_total', 'limit_quotes_total', 'devis', '📝'],
        ['ai_text_month', 'limit_ai_text_per_month', 'générations IA ce mois', '🤖'],
        ['emails_month', 'limit_emails_per_month', 'emails ce mois', '📨'],
    ];

    foreach ($checks as [$usage_key, $limit_key, $label, $emoji]) {
        $limit = $plan[$limit_key];
        if ($limit === null || $limit == 0) continue;
        $current = (int)$usage[$usage_key];
        $pct = ($current / $limit) * 100;

        if ($pct >= 100) {
            $alerts[] = ['level' => 'red', 'msg' => "{$emoji} <strong>Limite atteinte</strong> : {$current}/{$limit} {$label}", 'pct' => $pct];
        } elseif ($pct >= 90) {
            $alerts[] = ['level' => 'orange', 'msg' => "{$emoji} <strong>Quasi-limite</strong> : {$current}/{$limit} {$label} (" . round($pct) . "%)", 'pct' => $pct];
        } elseif ($pct >= 80) {
            $alerts[] = ['level' => 'yellow', 'msg' => "{$emoji} {$current}/{$limit} {$label} (" . round($pct) . "%)", 'pct' => $pct];
        }
    }

    if (empty($alerts)) return '';

    // Trier par sévérité (red > orange > yellow)
    usort($alerts, fn($a, $b) => $b['pct'] <=> $a['pct']);
    $top = $alerts[0];

    $colors = [
        'red'    => ['bg' => '#FEE2E2', 'border' => '#DC2626', 'color' => '#991B1B'],
        'orange' => ['bg' => '#FED7AA', 'border' => '#EA580C', 'color' => '#9A3412'],
        'yellow' => ['bg' => '#FEF3C7', 'border' => '#F59E0B', 'color' => '#92400E'],
    ];
    $c = $colors[$top['level']];

    $html = '<div style="background:' . $c['bg'] . ';border-left:4px solid ' . $c['border'] . ';color:' . $c['color'] . ';padding:14px 18px;border-radius:10px;margin-bottom:18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">';
    $html .= '<div style="flex:1;min-width:200px;">';
    $html .= '<div style="font-size:14px;line-height:1.5;">' . $top['msg'] . '</div>';
    if (count($alerts) > 1) {
        $html .= '<div style="font-size:12px;opacity:0.85;margin-top:4px;">+ ' . (count($alerts) - 1) . ' autre' . (count($alerts) > 2 ? 's' : '') . ' quota' . (count($alerts) > 2 ? 's' : '') . ' à surveiller</div>';
    }
    $html .= '</div>';

    if ($plan['slug'] === 'demarrage') {
        $html .= '<a href="/mon-asso-plan" style="background:' . $c['border'] . ';color:white;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap;">↗ Passer à Assokit</a>';
    } else {
        $html .= '<a href="/mon-asso-plan" style="background:' . $c['border'] . ';color:white;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap;">Voir mon plan</a>';
    }

    $html .= '</div>';
    return $html;
}

// ============================================================
// 6. UI : Overlay régularisation (15j passés sans paiement)
// ============================================================

function ak_render_overdue_overlay(PDO $pdo, int $org_id): string {
    $status = ak_get_subscription_status($pdo, $org_id);
    if (!$status['show_overlay']) return '';

    $html = '<div id="ak-overdue-overlay" style="position:fixed;inset:0;background:rgba(15,23,42,0.85);backdrop-filter:blur(8px);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;overflow-y:auto;">';
    $html .= '<div style="background:white;max-width:560px;width:100%;border-radius:20px;padding:40px 32px;box-shadow:0 25px 60px rgba(0,0,0,0.4);text-align:center;">';
    $html .= '<div style="font-size:64px;margin-bottom:16px;">⚠️</div>';
    $html .= '<h2 style="margin:0 0 12px;font-size:24px;color:#0F172A;">Régularisez votre facture</h2>';
    $html .= '<p style="color:#475569;line-height:1.6;margin:0 0 8px;font-size:16px;">';
    $html .= 'Votre période de tolérance de 15 jours est écoulée. Pour continuer à utiliser le plan <strong>' . htmlspecialchars($status['plan_name'], ENT_QUOTES, 'UTF-8') . '</strong>, merci de régulariser votre paiement.';
    $html .= '</p>';
    $html .= '<p style="color:#94A3B8;font-size:13px;margin:0 0 24px;">🔒 Vos données sont en sécurité et restent accessibles.</p>';

    $html .= '<div style="display:flex;flex-direction:column;gap:10px;">';
    // URL régulariser dynamique : Stripe direct si configuré
    $regularize_url = function_exists('ak_get_regularize_url') ? ak_get_regularize_url($pdo, $org_id) : '/mon-asso-plan?action=pay';
    // 1. CTA principal — Régulariser
    $html .= '<a href="' . htmlspecialchars($regularize_url, ENT_QUOTES, 'UTF-8') . '" style="background:linear-gradient(180deg,#059669 0%,#047857 100%);color:white;padding:14px 24px;border-radius:12px;text-decoration:none;font-weight:600;font-size:15px;box-shadow:0 4px 14px rgba(5,150,105,0.30);">💳 Régulariser le paiement</a>';
    // 2. CTA secondaire — Contact (NOUVEAU)
    $html .= '<a href="/contact?subject=billing" style="background:#F0F9FF;color:#0C4A6E;padding:13px 24px;border-radius:12px;text-decoration:none;font-weight:600;font-size:14px;border:1px solid #BAE6FD;display:inline-flex;align-items:center;justify-content:center;gap:8px;">📞 Nous contacter — On va trouver une solution</a>';
    // 3. CTA tertiaire — Downgrade (en mode discret)
    $html .= '<a href="/mon-asso-plan?action=downgrade" style="background:transparent;color:#94A3B8;padding:10px 24px;border-radius:12px;text-decoration:none;font-size:13px;border:1px solid #E2E8F0;">↓ Passer au plan Démarrage (gratuit)</a>';
    $html .= '</div>';

    $html .= '<p style="color:#DC2626;font-size:12px;margin:18px 0 0;line-height:1.5;">';
    $html .= '⚠️ <strong>Attention</strong> : en passant au plan Démarrage, vous perdrez la diffusion email, les factures récurrentes, les statistiques avancées et certaines fonctionnalités. Vos données existantes restent intactes.';
    $html .= '</p>';
    $html .= '</div></div>';

    return $html;
}

/**
 * Bandeau "régularisez avant J-X" pendant la période de grâce
 */
function ak_render_grace_banner(PDO $pdo, int $org_id): string {
    $status = ak_get_subscription_status($pdo, $org_id);
    if (!$status['in_grace_period']) return '';

    $days = max(1, (int)$status['days_remaining']);
    $is_pending = !empty($status['is_pending']);

    // Message adapté selon le statut
    if ($is_pending) {
        $main_text = '⏳ <strong>Paiement en attente</strong> — Votre prochain règlement pour le plan <strong>' . htmlspecialchars($status['plan_name'], ENT_QUOTES, 'UTF-8') . '</strong> sera bientôt traité.';
        $sub_text = 'Vous bénéficiez de <strong>' . $days . ' jour' . ($days > 1 ? 's' : '') . '</strong> de tolérance pour régulariser. Vos accès restent complets pendant cette période.';
    } else {
        $main_text = '⏳ <strong>À régulariser sous ' . $days . ' jour' . ($days > 1 ? 's' : '') . '</strong> — Votre paiement est en attente. Pour conserver le plan <strong>' . htmlspecialchars($status['plan_name'], ENT_QUOTES, 'UTF-8') . '</strong>, merci de régulariser.';
        $sub_text = '';
    }

    $html = '<div style="background:linear-gradient(135deg,#FED7AA 0%,#FEF3C7 100%);border:1px solid #FB923C;border-left:4px solid #EA580C;color:#9A3412;padding:16px 20px;border-radius:12px;margin-bottom:18px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;box-shadow:0 2px 8px rgba(234,88,12,0.10);">';
    $html .= '<div style="flex:1;min-width:240px;font-size:14px;line-height:1.55;">';
    $html .= '<div>' . $main_text . '</div>';
    if ($sub_text) {
        $html .= '<div style="font-size:13px;margin-top:4px;color:#7C2D12;">' . $sub_text . '</div>';
    }
    $html .= '</div>';
    $html .= '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
    // URL régulariser dynamique : Stripe direct si configuré, sinon /contact
    $regularize_url = function_exists('ak_get_regularize_url') ? ak_get_regularize_url($pdo, $org_id) : '/mon-asso-plan?action=pay';
    $html .= '<a href="' . htmlspecialchars($regularize_url, ENT_QUOTES, 'UTF-8') . '" style="background:#EA580C;color:white;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap;display:inline-flex;align-items:center;gap:6px;">💳 Régulariser</a>';
    $html .= '<a href="/contact?subject=billing" style="background:white;color:#9A3412;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap;border:1px solid #FDBA74;">📞 Nous contacter</a>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

} // end define guard
