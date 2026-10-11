<?php
/**
 * api/app-founder-action.php — Actions Fondateur sur une association (app mobile).
 * POST { org_id:int, action, csrf }
 *   action = 'validate' | 'reject' | 'suspend' | 'activate' | 'resiliate'
 *          | 'edit'          { name, billing_email, note, plan (slug) | plan_id }
 *          | 'extend_trial'  { days: 7 | 14 }
 * Le plan se change PARTOUT à la fois (abonnement, organizations.plan, état d'essai)
 * via ak_org_set_plan() ; l'essai se prolonge via ak_trial_extend() (trial-helpers.php).
 * Réservé Fondateur/Super Admin. NE MODIFIE PAS le site : dédié à l'application.
 */
require __DIR__ . '/_app-write-boot.php';

require_once __DIR__ . '/_app-founder.php';
require_once __DIR__ . '/_app-founder-plan.php';
$is_founder = app_is_founder($pdo, $user);
$is_sa = $is_founder || !empty($user['is_super_admin']) || (($user['role'] ?? '') === 'super_admin');
if (!$is_sa) app_fail(403, 'forbidden');

$target = (int) ($input['org_id'] ?? 0);
$action = (string) ($input['action'] ?? '');
if ($target <= 0) app_fail(400, 'org_id');
if (!in_array($action, ['validate', 'reject', 'suspend', 'activate', 'resiliate', 'edit', 'extend_trial'], true)) app_fail(400, 'action');
if (in_array($action, ['validate', 'reject'], true) && !$is_founder) app_fail(403, 'founder_only');   // valider / refuser une asso : fondateur uniquement

// Journal plateforme (même table et même signature que le cockpit web)
@include_once __DIR__ . '/../superadmin-layout.php';
$log = function (string $what, array $details = []) use ($uid, $target) {
    if (function_exists('sa_log_action')) {
        try { sa_log_action((int) $uid, 'founder_' . $what, $target, null, $details); } catch (Throwable $e) {}
    }
};

try {
    // Vérifie que l'org existe
    $st = $pdo->prepare("SELECT id, name FROM organizations WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$target]);
    $org = $st->fetch(PDO::FETCH_ASSOC);
    if (!$org) app_fail(404, 'not_found');

    $before = ak_org_plan_state($pdo, $target);
    $extra = [];

    if ($action === 'validate') {
        // Une association en essai gratuit reste en essai : la passer « active » rendait l'essai illimité.
        // Une association suspendue ou résiliée le reste (le cockpit web ne touche pas au statut).
        $status = fd_live_status($pdo, $before);
        $keep = "CASE WHEN status IN ('suspended', 'cancelled') THEN status ELSE ? END";
        try { $pdo->prepare("UPDATE organizations SET validation_status = 'validated', status = $keep WHERE id = ?")->execute([$status, $target]); }
        catch (Throwable $e) { $pdo->prepare("UPDATE organizations SET status = $keep WHERE id = ?")->execute([$status, $target]); }
        $log('validate', ['status' => $status]);
    } elseif ($action === 'reject') {
        try { $pdo->prepare("UPDATE organizations SET validation_status='rejected' WHERE id=?")->execute([$target]); }
        catch (Throwable $e) {}
        $log('reject');
    } elseif ($action === 'suspend') {
        $pdo->prepare("UPDATE organizations SET status='suspended' WHERE id=?")->execute([$target]);
        // Comme le cockpit web : suspendue pendant son essai, l'ancienne table passe aussi en
        // « suspended » (sinon les rappels J-7 / J-3 / J-0 partiraient encore).
        if ($before['is_trial'] && ak_db_accepts($pdo, 'subscriptions', 'status', 'suspended')) {
            try { $pdo->prepare("UPDATE subscriptions SET status = 'suspended' WHERE org_id = ? AND status = 'trial'")->execute([$target]); }
            catch (Throwable $e) { error_log('[api/app-founder-action] suspend legacy: ' . get_class($e)); }
        }
        $log('suspend');
    } elseif ($action === 'activate') {
        // Réactiver une association en essai la remet EN ESSAI (même fin) : le cron la fera
        // expirer à la date prévue. Pour lui redonner du temps : « Prolonger l'essai ».
        $status = fd_live_status($pdo, $before);
        $pdo->prepare("UPDATE organizations SET status = ?, suspended_reason = NULL WHERE id = ?")->execute([$status, $target]);
        // Essai encore en cours : l'ancienne table (bandeau d'essai, rappels) redevient « trial »,
        // comme le fait le cockpit web — sinon le bandeau afficherait « Essai terminé ».
        $end_ts = !empty($before['trial_ends_at']) ? strtotime((string) $before['trial_ends_at']) : false;
        if ($status === 'trial' && $end_ts && $end_ts > time()) ak_legacy_sub_trial($pdo, $target, date('Y-m-d H:i:s', $end_ts), false);
        $log('activate', ['status' => $status]);
    } elseif ($action === 'resiliate') {
        // Comme le cockpit web : résilier une association en essai met fin à l'essai (plan Démarrage),
        // sinon son abonnement d'essai continuerait d'afficher « ESSAI » sur une association résiliée.
        $pdo->beginTransaction();
        $ended_trial = false;
        if ($before['is_trial'] || $before['trial_expired']) {
            $free_id = (int) $pdo->query("SELECT id FROM asso_plans WHERE slug = 'demarrage' LIMIT 1")->fetchColumn();
            if ($free_id > 0) {
                ak_org_set_plan($pdo, $target, $free_id);
                if (function_exists('sa_trial_exit_cleanup')) sa_trial_exit_cleanup($pdo, $target, $before, ak_org_plan_state($pdo, $target));
                $ended_trial = true;
            }
        }
        $pdo->prepare("UPDATE organizations SET status='cancelled' WHERE id=?")->execute([$target]);
        $pdo->commit();
        $log('resiliate', $ended_trial ? ['trial_ended' => true] : []);
    } elseif ($action === 'extend_trial') {
        $days = (int) ($input['days'] ?? 0);
        if (!in_array($days, [7, 14], true)) app_fail(400, 'days', 'Durée invalide : 7 ou 14 jours.');
        if ($before['org_status'] === 'cancelled') app_fail(409, 'cancelled', 'Association résiliée : rien à prolonger.');
        if (!$before['is_trial'] && !$before['trial_expired']) {
            app_fail(409, 'not_trial', 'Cette association n’est pas en essai. Pour lui en ouvrir un, choisissez la formule « ' . ak_plan_label('pro-essai') . ' ».');
        }
        $pdo->beginTransaction();
        $end = ak_trial_extend($pdo, $target, $days);
        // Comme le cockpit web : l'essai rouvert porte le plan d'essai partout. Si la ligne courante
        // n'y est pas revenue (rétrogradation sans previous_plan_id…), ak_org_set_plan l'y remet en
        // gardant la nouvelle fin d'essai (future).
        $trial_plan = ak_trial_plan($pdo);
        if ($trial_plan && ($trial_plan['is_trial'] || $trial_plan['slug'] === 'pro-essai')) {
            $now_state = ak_org_plan_state($pdo, $target);
            if ((int) $now_state['plan_id'] !== (int) $trial_plan['id']) ak_org_set_plan($pdo, $target, (int) $trial_plan['id']);
        }
        $pdo->commit();
        $extra = ['days' => $days, 'trial_ends_at' => $end, 'trial_ends' => ak_trial_end_label($end)];
        $log('extend_trial', ['days' => $days, 'until' => $end]);
    } elseif ($action === 'edit') {
        // Édition : nom, email de facturation, plan, note interne — comme le web
        $name    = trim((string) ($input['name'] ?? ''));
        $mail    = trim((string) ($input['billing_email'] ?? ''));
        $plan    = trim((string) ($input['plan'] ?? ''));
        $plan_id = (int) ($input['plan_id'] ?? 0);
        $note    = (string) ($input['note'] ?? '');
        if ($name === '') app_fail(400, 'name', 'Nom obligatoire.');
        if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) app_fail(400, 'billing_email', 'Email invalide.');

        // Plan : un plan réel d'asso_plans (id ou slug), jamais une chaîne libre. On ne touche
        // à l'abonnement que s'il CHANGE : renvoyer le plan actuel (même un ancien libellé
        // « essentiel » sans abonnement) ne doit rien modifier.
        $new_plan = null;
        if ($plan_id > 0 || $plan !== '') {
            $pq = $plan_id > 0
                ? $pdo->prepare("SELECT id, slug FROM asso_plans WHERE id = ? LIMIT 1")
                : $pdo->prepare("SELECT id, slug FROM asso_plans WHERE slug = ? LIMIT 1");
            $pq->execute([$plan_id > 0 ? $plan_id : $plan]);
            $row = $pq->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                if (!($plan_id <= 0 && strtolower($plan) === strtolower((string) $before['plan_slug']))) app_fail(400, 'plan', 'Plan inconnu.');
            } elseif ((string) $row['slug'] !== (string) $before['plan_slug'] || ($before['plan_id'] !== null && (int) $row['id'] !== (int) $before['plan_id'])) {
                $new_plan = ['id' => (int) $row['id'], 'slug' => (string) $row['slug']];
            }
        }

        // Construit dynamiquement selon les colonnes présentes
        $ocols = ak_db_columns($pdo, 'organizations');
        $sets = ['name = ?']; $params = [$name];
        if ($mail !== '' && (!$ocols || isset($ocols['billing_email']))) { $sets[] = 'billing_email = ?'; $params[] = $mail; }
        if (!$ocols || isset($ocols['notes_superadmin'])) { $sets[] = 'notes_superadmin = ?'; $params[] = $note; }
        $params[] = $target;

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE organizations SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        if ($new_plan) {
            ak_org_set_plan($pdo, $target, $new_plan['id']);
            // Comme le cockpit web (sa_assoc_set_plan) : en sortie d'essai, la fin d'essai ne reste pas
            // l'« échéance » du nouveau plan, et un plan payant n'est plus affiché « Offert ».
            if (function_exists('sa_trial_exit_cleanup')) sa_trial_exit_cleanup($pdo, $target, $before, ak_org_plan_state($pdo, $target));
        }
        $pdo->commit();
        $log('edit', $new_plan ? ['plan' => [$before['plan_slug'], $new_plan['slug']]] : []);
        if ($new_plan) $extra = ['plan_changed' => true, 'from' => $before['plan_label'], 'to' => ak_plan_label($new_plan['slug'])];
    }

    // organizations.plan (ancien libellé) recalé sur le plan réel : la fin d'essai rétrograde
    // l'abonnement en « demarrage » sans toucher ce libellé, qui restait « pro-essai ».
    $after = ak_org_plan_state($pdo, $target);
    if ($after['plan_id'] !== null && $after['plan_slug'] !== '') {
        $ocols = ak_db_columns($pdo, 'organizations');
        if (!$ocols || ak_db_accepts($pdo, 'organizations', 'plan', $after['plan_slug'])) {
            try { $pdo->prepare("UPDATE organizations SET plan = ? WHERE id = ? AND (plan IS NULL OR plan <> ?)")->execute([$after['plan_slug'], $target, $after['plan_slug']]); }
            catch (Throwable $e) { error_log('[api/app-founder-action] plan label: ' . get_class($e)); }
        }
    }

    echo json_encode(['ok' => true, 'org_id' => $target, 'action' => $action, 'state' => fd_plan_fields($after)] + $extra, JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    app_fail(400, 'plan', 'Plan inconnu.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[api/app-founder-action] ' . $e->getMessage());
    app_fail(500, 'server');
}
