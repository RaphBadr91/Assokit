<?php
/**
 * api/app-founder-action.php — Actions Fondateur sur une association (app mobile).
 * POST { org_id:int, action, csrf }
 *   action = 'validate' | 'activate' | 'resiliate'
 *          | 'reject' | 'suspend'  { reason? }   (refuser = refusée ET suspendue, comme le web)
 *          | 'edit'          { name, billing_email, note, plan (slug) | plan_id }
 *          | 'extend_trial'  { days: 7 | 14 }
 *          | 'reset_2fa'     { user_id }   (Fondateur : double authentification d'un compte de l'association
 *                                          retirée, téléphone perdu ; réponse user_id + totp_reset: true)
 * Le plan se change PARTOUT à la fois (abonnement, organizations.plan, état d'essai)
 * via ak_org_set_plan() ; l'essai se prolonge via ak_trial_extend() ; suspendre, réactiver et
 * refuser passent par ak_org_suspend / ak_org_reactivate / ak_org_reject (trial-helpers.php),
 * les mêmes que le cockpit web. Réponse 'warning' : abonnement Stripe à annuler dans Stripe ;
 * 'notice' : association réactivée sur Démarrage parce que son essai était terminé ;
 * résilier annule aussi l'abonnement Stripe : 'stripe_cancelled' (identifiants annulés) et
 * 'stripe_warning' (null, ou message : abonnement encore actif, à annuler dans Stripe).
 * Demande de création d'espace faite dans l'app iPhone (signup-review-helpers.php) : 'validate' ouvre
 * l'essai MAINTENANT et prévient l'administrateur (réponse 'review' : { trial_ends, mailed, warnings,
 * message }), 'reject' envoie aussi un e-mail poli au demandeur ; tant qu'elle attend, 'activate',
 * 'suspend', 'extend_trial' et un changement de formule ('edit') sont refusés (409 'pending_request').
 * Mêmes effets que le cockpit web et que les liens de l'e-mail du Fondateur.
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
if (!in_array($action, ['validate', 'reject', 'suspend', 'activate', 'resiliate', 'edit', 'extend_trial', 'reset_2fa'], true)) app_fail(400, 'action');
// valider / refuser une asso, réinitialiser une double authentification : fondateur uniquement (comme le web)
if (in_array($action, ['validate', 'reject', 'reset_2fa'], true) && !$is_founder) app_fail(403, 'founder_only');

// Journal plateforme : même table, mêmes codes et mêmes détails que le cockpit web (validate_org,
// suspend_org…), pour que /super-admin/logs et ses filtres affichent les actions de l'appli comme
// les siennes ; 'source' => 'appli' en dit l'origine. (Les anciennes entrées founder_* restent lisibles.)
@include_once __DIR__ . '/../superadmin-layout.php';
$log = function (string $code, array $details = []) use ($uid, $target) {
    if (function_exists('sa_log_action')) {
        try { sa_log_action((int) $uid, $code, $target, null, $details + ['source' => 'appli']); } catch (Throwable $e) {}
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

    // Demande de création d'espace faite dans l'app iPhone, encore en attente : valider ou refuser
    // seulement (rien d'autre ne doit l'ouvrir ni la dénaturer), comme le cockpit web
    require_once __DIR__ . '/../signup-review-helpers.php';
    $full = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
    $full->execute([$target]);
    $pending_request = ak_signup_review_pending($full->fetch(PDO::FETCH_ASSOC) ?: []);
    if ($pending_request && in_array($action, ['activate', 'suspend', 'extend_trial'], true)) {
        app_fail(409, 'pending_request', ak_signup_review_block_message());
    }

    if ($action === 'validate') {
        // Demande de l'app iPhone : l'essai démarre maintenant et l'administrateur est prévenu, exactement
        // comme par le cockpit web et le lien de l'e-mail (ak_signup_review_validate)
        $rv = ak_signup_review_validate($pdo, $target, (int) $uid);
        if ($rv['state'] === 'validated') {
            $log('validate_org', ['trial_ends_at' => $rv['trial_end'], 'demande' => 'app iPhone']);
            $extra['review'] = ['trial_ends' => ak_trial_end_label($rv['trial_end']), 'mailed' => (bool) $rv['mailed'], 'warnings' => $rv['warnings'],
                'message' => 'Essai PRO de ' . (int) AK_TRIAL_DAYS . ' jours ouvert jusqu’au ' . ak_trial_end_label($rv['trial_end']) . '. '
                           . ($rv['mailed'] ? 'L’administrateur a reçu l’e-mail « Votre espace Assokit est activé ».' : 'L’e-mail d’activation n’a pas pu partir : prévenez l’administrateur.')];
        } else {
            // Une association en essai gratuit reste en essai : la passer « active » rendait l'essai illimité.
            // Une association suspendue ou résiliée le reste (le cockpit web ne touche pas au statut).
            $status = fd_live_status($pdo, $before);
            $keep = "CASE WHEN status IN ('suspended', 'cancelled') THEN status ELSE ? END";
            try { $pdo->prepare("UPDATE organizations SET validation_status = 'validated', status = $keep WHERE id = ?")->execute([$status, $target]); }
            catch (Throwable $e) { $pdo->prepare("UPDATE organizations SET status = $keep WHERE id = ?")->execute([$status, $target]); }
            $log('validate_org', ['status' => $status]);
        }
    } elseif ($action === 'reject') {
        // Comme le cockpit web (« Refuser ») : refusée ET suspendue, plus aucun rappel d'essai ; pour une
        // demande de l'app iPhone, le demandeur reçoit en plus un e-mail poli (ak_signup_review_reject)
        $reason = trim((string) ($input['reason'] ?? ''));
        $rv = ak_signup_review_reject($pdo, $target, $reason, (int) $uid);
        if ($rv['state'] !== 'rejected') ak_org_reject($pdo, $target, $reason, (int) $uid);
        $log('reject_org', ['reason' => $reason]);
    } elseif ($action === 'suspend') {
        // Comme le cockpit web (ak_org_suspend) : suspendue pendant son essai, l'ancienne table passe
        // aussi en « suspended » (plus de rappels J-7 / J-3 / J-0 ni d'e-mail de fin d'essai).
        $reason = trim((string) ($input['reason'] ?? ''));
        ak_org_suspend($pdo, $target, $reason !== '' ? $reason : 'Suspendu depuis l’application');
        $log('suspend_org', ['reason' => $reason]);
    } elseif ($action === 'activate') {
        // Même règle que le cockpit web (ak_org_reactivate) : un essai en cours repasse EN ESSAI (même fin,
        // le cron le fera expirer à la date prévue) ; un essai dont la date est passée se termine tout de
        // suite et l'association repart active sur Démarrage. Pour lui redonner du temps : « Prolonger l'essai ».
        $trial_ended = null;
        $status = ak_org_reactivate($pdo, $target, $trial_ended);
        $log('reactivate_org', $status === 'trial' ? ['trial' => true] : ($trial_ended ? ['trial_ended' => $trial_ended] : []));
        if ($trial_ended) {
            $extra['notice'] = 'Association réactivée sur le plan gratuit Démarrage (essai terminé le ' . ak_trial_end_label($trial_ended) . '). '
                             . 'Prolongez l’essai ou changez sa formule pour rouvrir les fonctionnalités PRO.';
        }
    } elseif ($action === 'resiliate') {
        // Comme le cockpit web : résilier une association en essai met fin à l'essai (plan Démarrage),
        // sinon son abonnement d'essai continuerait d'afficher « ESSAI » sur une association résiliée.
        $pdo->beginTransaction();
        $ended_trial = false;
        if ($before['is_trial'] || $before['trial_expired']) {
            $free_id = (int) $pdo->query("SELECT id FROM asso_plans WHERE slug = 'demarrage' LIMIT 1")->fetchColumn();
            if ($free_id > 0) {
                ak_org_set_plan($pdo, $target, $free_id);   // fait aussi le ménage de sortie d'essai
                $ended_trial = true;
            }
        }
        $pdo->prepare("UPDATE organizations SET status='cancelled' WHERE id=?")->execute([$target]);
        $pdo->commit();
        // Client payant par carte : l'abonnement Stripe s'arrête aussi, comme sur le cockpit web,
        // sinon l'association est résiliée mais Stripe continue de prélever la carte du client.
        $stripe = ['cancelled' => [], 'failed' => []];
        if (function_exists('sa_stripe_cancel_for_org')) {
            try { $stripe = sa_stripe_cancel_for_org($pdo, $target); }
            catch (Throwable $e) {
                error_log('[api/app-founder-action] Stripe org #' . $target . ' : ' . get_class($e) . ' ' . $e->getMessage());
                foreach (sa_stripe_live_subs($pdo, $target) as $sid) $stripe['failed'][$sid] = 'erreur technique';
            }
        }
        $details = $ended_trial ? ['trial_ended' => true] : [];
        if ($stripe['cancelled']) $details['stripe_cancelled'] = $stripe['cancelled'];
        if ($stripe['failed'])    $details['stripe_failed'] = array_keys($stripe['failed']);
        $log('cancel_org', $details);
        $extra['stripe_cancelled'] = $stripe['cancelled'];
        $extra['stripe_warning'] = $stripe['failed'] ? sa_stripe_still_billing_message($stripe['failed']) : null;
    } elseif ($action === 'extend_trial') {
        $days = (int) ($input['days'] ?? 0);
        if (!in_array($days, [7, 14], true)) app_fail(400, 'days', 'Durée invalide : 7 ou 14 jours.');
        if ($before['org_status'] === 'cancelled') app_fail(409, 'cancelled', 'Association résiliée : rien à prolonger.');
        if (!$before['is_trial'] && !$before['trial_expired']) {
            app_fail(409, 'not_trial', 'Cette association n’est pas en essai. Pour lui en ouvrir un, choisissez la formule « ' . ak_plan_label('pro-essai') . ' ».');
        }
        // ak_trial_extend remet tout en essai (fin, statut, plan d'essai sur la ligne courante même
        // rétrogradée sans previous_plan_id, ancien libellé, ancienne table), comme le cockpit web.
        $pdo->beginTransaction();
        $end = ak_trial_extend($pdo, $target, $days);
        $pdo->commit();
        $extra = ['days' => $days, 'trial_ends_at' => $end, 'trial_ends' => ak_trial_end_label($end)];
        $log('trial_extend', ['days' => $days, 'from' => $before['trial_ends_at'], 'to' => $end]);
    } elseif ($action === 'reset_2fa') {
        // Comme le cockpit web : téléphone perdu sans code de secours, le mot de passe suffit à la
        // prochaine connexion ; la double authentification se réactive dans Paramètres › Sécurité.
        $target_user = (int) ($input['user_id'] ?? 0);
        $ust = $pdo->prepare("SELECT email FROM users WHERE id = ? AND org_id = ? AND deleted_at IS NULL LIMIT 1");
        $ust->execute([$target_user, $target]);
        $target_email = $ust->fetchColumn();
        if ($target_email === false) app_fail(404, 'user_not_found', 'Utilisateur introuvable dans cette association.');
        if ($target_user === (int) $uid) app_fail(409, 'self', 'Votre propre double authentification se gère dans Paramètres › Sécurité.');
        require_once __DIR__ . '/../totp-helper.php';
        if (!ak_totp_reset($pdo, $target_user, $target)) {
            app_fail(409, 'totp_off', 'La double authentification n’est pas activée sur ce compte : rien à réinitialiser.');
        }
        if (function_exists('sa_log_action')) {
            try { sa_log_action((int) $uid, 'reset_2fa', $target, $target_user, ['email' => $target_email, 'source' => 'appli']); } catch (Throwable $e) {}
        }
        $extra = ['user_id' => $target_user, 'totp_reset' => true];
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

        // Demande de l'app iPhone en attente : un plan l'ouvrirait sans validation (le nom, l'e-mail et la
        // note restent modifiables)
        if ($new_plan && $pending_request) app_fail(409, 'pending_request', ak_signup_review_block_message());

        // Construit dynamiquement selon les colonnes présentes
        $ocols = ak_db_columns($pdo, 'organizations');
        $sets = ['name = ?']; $params = [$name];
        if ($mail !== '' && (!$ocols || isset($ocols['billing_email']))) { $sets[] = 'billing_email = ?'; $params[] = $mail; }
        if (!$ocols || isset($ocols['notes_superadmin'])) { $sets[] = 'notes_superadmin = ?'; $params[] = $note; }
        $params[] = $target;

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE organizations SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        // ak_org_set_plan fait aussi le ménage de sortie d'essai (échéance, mode « Offert »), comme sur le web
        if ($new_plan) ak_org_set_plan($pdo, $target, $new_plan['id']);
        $pdo->commit();
        $log('update_org', ['name' => $name] + ($new_plan ? ['plan' => ['from' => $before['plan_slug'], 'to' => $new_plan['slug']]] : []));
        if ($new_plan) {
            $extra = ['plan_changed' => true, 'from' => $before['plan_label'], 'to' => ak_plan_label($new_plan['slug'])];
            // Client Stripe passé sur un plan que Stripe ne facture pas (gratuit, essai, Sur-mesure…) :
            // Stripe prélève encore l'ancienne formule (même avertissement que le web)
            if ($sid = ak_stripe_left_on_free($pdo, $target, $sfree)) {
                $extra['warning'] = 'Abonnement Stripe ' . $sid . ' toujours actif' . ($sfree ? '' : ' : il facture encore l’ancienne formule')
                                  . ' : annulez-le dans le tableau de bord Stripe, sinon la carte du client continuera d’être prélevée.';
            }
        }
    }

    // organizations.plan (ancien libellé) recalé sur le plan réel, s'il accepte la valeur
    ak_org_sync_plan_label($pdo, $target);
    $after = ak_org_plan_state($pdo, $target);

    echo json_encode(['ok' => true, 'org_id' => $target, 'action' => $action, 'state' => fd_plan_fields($after)] + $extra, JSON_UNESCAPED_UNICODE);
} catch (AkTrialBlocked $e) {
    // Prolongation refusée (paiement Stripe en attente) : rien n'est modifié, le message dit quoi faire
    if ($pdo->inTransaction()) $pdo->rollBack();
    app_fail(409, 'trial_blocked', $e->getMessage());
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    app_fail(400, 'plan', 'Plan inconnu.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[api/app-founder-action] ' . $e->getMessage());
    app_fail(500, 'server');
}
