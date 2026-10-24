<?php
/**
 * super-admin-associations.php — Gestion des associations
 * ========================================================
 * Deux vues dans cette page :
 *   1. Vue liste (par defaut) : toutes les assos avec filtres
 *   2. Vue fiche (?id=X)       : detail complet d'une asso + actions
 *
 * Actions disponibles sur une asso :
 *   - Modifier (nom, plan, notes internes)
 *   - Essai : prolonger de 7 / 14 jours, passer en abonnement
 *   - Suspendre / Reactiver / Resilier
 *   - Voir ses users, ses projets, son abonnement, sa demande d'inscription
 *   - Notes internes super admin
 *
 * Plan et statut : une seule vérité, ak_org_plan_state() (trial-helpers.php),
 * la même que l'appli et l'espace de l'association. Un changement de plan passe
 * TOUJOURS par ak_org_set_plan() (abonnement, organizations.plan et essai à la fois).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
require_once __DIR__ . '/trial-helpers.php';
require_once __DIR__ . '/sa-plan-state-helpers.php';
require_once __DIR__ . '/signup-review-helpers.php';   // demandes d'espace de l'app iPhone : valider / refuser
require_login();
$user = sa_require_super_admin();
$ctx = sa_get_permissions_context();
$can_plan = !empty($ctx['can_manage_subscriptions']);

/**
 * Change le plan partout (ak_org_set_plan) dans une transaction. Renvoie null ou un message d'erreur.
 * Sortie d'essai : ak_org_set_plan efface aussi la fin d'essai restée comme « échéance » et passe
 * le mode « Offert » de l'inscription en « manuel » si le nouveau plan est payant.
 */
function sa_assoc_set_plan(PDO $pdo, int $org_id, int $plan_id): ?string {
    try {
        $pdo->beginTransaction();
        ak_org_set_plan($pdo, $org_id, $plan_id);
        $pdo->commit();
        return null;
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Plan inconnu : choisissez un plan de la liste.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[super-admin-associations] set_plan: ' . get_class($e) . ' ' . $e->getMessage());
        return 'Le plan n’a pas pu être changé (erreur technique). Rien n’a été modifié.';
    }
}

/**
 * Client Stripe passé sur un plan que Stripe ne facture pas (gratuit, essai, Sur-mesure…) : le plan
 * change ici, pas dans Stripe. Les données de paiement sont gardées (ak_org_set_plan) ; c'est au
 * Fondateur d'annuler dans Stripe. $free : la nouvelle formule est gratuite (ak_stripe_left_on_free).
 */
function sa_stripe_left_message(string $stripe_sub_id, bool $free = true): string {
    if (!$free) {
        return 'Abonnement Stripe ' . $stripe_sub_id . ' toujours actif : il facture encore l’ancienne formule, alors que la formule '
             . 'actuelle n’est pas réglée par Stripe. Annulez-le dans le tableau de bord Stripe (Abonnements), sinon la carte du client '
             . 'continuera d’être prélevée.';
    }
    return 'Abonnement Stripe ' . $stripe_sub_id . ' toujours actif : le plan a changé dans Assokit, mais Stripe continuera '
         . 'de prélever la carte du client tant que cet abonnement n’est pas annulé dans le tableau de bord Stripe (Abonnements).';
}

$error = null;
$success = null;
$view_id = (int) ($_GET['id'] ?? 0);

// =====================================================================
// Traitement POST (modifs d'une asso)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('check_csrf') && check_csrf($_POST['csrf_token'] ?? '')) {

    $action = $_POST['action'] ?? '';
    $target_id = (int) ($_POST['org_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
    $stmt->execute([$target_id]);
    $org = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$org) {
        $error = 'Association introuvable.';
    } else {

        // ---- VALIDATE (Fondateur uniquement) ----
        if ($action === 'validate_org') {
            if (!$ctx['is_founder']) {
                $error = '🏗️ Seul un Fondateur peut valider une association.';
            } else {
                // Demande de création d'espace faite dans l'app iPhone : l'essai démarre maintenant et
                // l'administrateur est prévenu, exactement comme par le lien de l'e-mail et par l'appli
                try {
                    $rv = ak_signup_review_validate($pdo, $target_id, (int) $user['id']);
                } catch (Throwable $e) {
                    error_log('[super-admin-associations] validate_org: ' . get_class($e) . ' ' . $e->getMessage());
                    $rv = ['state' => 'error'];
                }
                if ($rv['state'] === 'error') {
                    $error = 'La validation n’a pas pu être enregistrée (erreur technique). Rien n’a été modifié.';
                } elseif ($rv['state'] === 'withdrawn') {
                    // Retirée par le demandeur pendant que la page était ouverte : l'espace reste fermé
                    $error = ak_signup_review_withdrawn_message();
                } elseif ($rv['state'] === 'validated') {
                    sa_log_action((int) $user['id'], 'validate_org', $target_id, null, ['trial_ends_at' => $rv['trial_end'], 'demande' => 'app iPhone']);
                    $success = '✓ Demande validée : l’essai PRO de ' . (int) AK_TRIAL_DAYS . ' jours court jusqu’au ' . ak_trial_end_label($rv['trial_end']) . '. '
                             . ($rv['mailed'] ? 'L’administrateur a reçu l’e-mail « Votre espace Assokit est activé ».' : 'L’e-mail d’activation n’a pas pu partir : prévenez l’administrateur.')
                             . ($rv['warnings'] ? ' À vérifier : ' . implode(' ', $rv['warnings']) : '');
                } else {
                    $stmt = $pdo->prepare("UPDATE organizations SET validation_status = 'validated', validated_by_user_id = ?, validated_at = NOW() WHERE id = ?");
                    $stmt->execute([(int) $user['id'], $target_id]);
                    sa_log_action((int) $user['id'], 'validate_org', $target_id);
                    $success = '✓ Association validée. Elle est maintenant pleinement active.';
                }
                $view_id = $target_id;
            }
        }
        // ---- REJECT (Fondateur uniquement) ----
        elseif ($action === 'reject_org') {
            if (!$ctx['is_founder']) {
                $error = '🏗️ Seul un Fondateur peut refuser une association.';
            } else {
                $reason = trim($_POST['rejection_reason'] ?? '');
                // Demande de l'app iPhone : refusée ET suspendue, et e-mail poli au demandeur (comme le lien
                // de l'e-mail et l'appli). Sinon : refusée ET suspendue (même règle que l'appli) ; en essai,
                // plus aucun rappel d'essai
                try {
                    $rv = ak_signup_review_reject($pdo, $target_id, $reason, (int) $user['id']);
                } catch (Throwable $e) {
                    error_log('[super-admin-associations] reject_org: ' . get_class($e) . ' ' . $e->getMessage());
                    $rv = ['state' => 'not_request'];   // refus habituel ci-dessous
                }
                if ($rv['state'] === 'withdrawn') {
                    // Retirée par le demandeur entre-temps : ak_org_reject rouvrirait l'espace fermé (suspendu)
                    $error = ak_signup_review_withdrawn_message();
                } else {
                    if ($rv['state'] !== 'rejected') ak_org_reject($pdo, $target_id, $reason, (int) $user['id']);
                    sa_log_action((int) $user['id'], 'reject_org', $target_id, null, ['reason' => $reason]);
                    $success = $rv['state'] === 'rejected'
                        ? '✕ Demande refusée. ' . ($rv['mailed'] ? 'Le demandeur a reçu un e-mail l’en informant.' : 'L’e-mail au demandeur n’a pas pu partir.')
                        : '✕ Association refusée.';
                }
                $view_id = $target_id;
            }
        }
        // Demande de l'app iPhone encore en attente : rien ne doit l'ouvrir (ni la dénaturer) sans décision
        elseif (in_array($action, ['reactivate', 'suspend', 'trial_extend', 'set_plan'], true) && ak_signup_review_pending($org)) {
            $error = ak_signup_review_block_message();
            $view_id = $target_id;
        }
        // Demande retirée par le demandeur : espace fermé, seul compte anonymisé ; rien ne la rouvre
        elseif (in_array($action, ['reactivate', 'suspend', 'trial_extend', 'set_plan'], true) && ak_signup_review_withdrawn($org)) {
            $error = ak_signup_review_withdrawn_message();
            $view_id = $target_id;
        }
        // ---- UPDATE ----
        elseif ($action === 'update') {
            $name = trim($_POST['name'] ?? '');
            $notes = trim($_POST['notes_superadmin'] ?? '');
            $new_plan_id = (int) ($_POST['plan_id'] ?? 0);
            $view_id = $target_id;

            if ($name === '') {
                $error = 'Nom requis.';
            } else {
                // Le plan ne change QUE s'il diffère du plan courant (jamais d'écriture « par défaut »)
                $before = ak_org_plan_state($pdo, $target_id);
                $plan_change = $new_plan_id > 0 && $new_plan_id !== (int) $before['plan_id'];
                if ($plan_change && !$can_plan) {
                    $error = 'Vous n’avez pas le droit de changer le plan d’une association.';
                } elseif ($plan_change && ak_signup_review_pending($org)) {
                    // Demande de l'app iPhone en attente : un plan l'ouvrirait sans validation
                    $error = ak_signup_review_block_message();
                } elseif ($plan_change && ak_signup_review_withdrawn($org)) {
                    // Demande retirée par le demandeur : un plan rouvrirait l'espace fermé
                    $error = ak_signup_review_withdrawn_message();
                } else {
                    $stmt = $pdo->prepare("UPDATE organizations SET name = ?, notes_superadmin = ? WHERE id = ?");
                    $stmt->execute([$name, $notes, $target_id]);
                    $log = ['name' => $name];
                    $success = 'Association mise à jour.';
                    if ($plan_change) {
                        $err = sa_assoc_set_plan($pdo, $target_id, $new_plan_id);
                        if ($err) {
                            $error = $err;
                            $success = 'Nom et notes enregistrés. Le plan n’a pas changé.';
                        } else {
                            $after = ak_org_plan_state($pdo, $target_id);
                            $log['plan'] = ['from' => $before['plan_slug'], 'to' => $after['plan_slug'], 'status' => $after['badge']];
                            $success = 'Association mise à jour. Plan : ' . $before['plan_label'] . ' → ' . $after['plan_label'] . ' (' . $after['badge'] . ').';
                            if ($sid = ak_stripe_left_on_free($pdo, $target_id, $sfree)) $error = sa_stripe_left_message($sid, (bool) $sfree);
                        }
                    }
                    sa_log_action((int) $user['id'], 'update_org', $target_id, null, $log);
                }
            }
        }
        // ---- ESSAI : PROLONGER ----
        elseif ($action === 'trial_extend') {
            $days = (int) ($_POST['days'] ?? 0);
            $before = ak_org_plan_state($pdo, $target_id);
            $view_id = $target_id;
            if (!$can_plan) {
                $error = 'Vous n’avez pas le droit de modifier l’essai d’une association.';
            } elseif (!in_array($days, [7, 14], true)) {
                $error = 'Durée de prolongation invalide.';
            } elseif (!$before['is_trial'] && !$before['trial_expired']) {
                $error = 'Cette association n’est pas en essai : rien à prolonger.';
            } else {
                try {
                    // ak_trial_extend remet tout en essai : fin d'essai, statut, plan d'essai sur la ligne
                    // courante (même rétrogradée sans previous_plan_id), ancien libellé, ancienne table.
                    $pdo->beginTransaction();
                    $new_end = ak_trial_extend($pdo, $target_id, $days);
                    $pdo->commit();
                    sa_log_action((int) $user['id'], 'trial_extend', $target_id, null, [
                        'days' => $days, 'from' => $before['trial_ends_at'], 'to' => $new_end,
                    ]);
                    $success = 'Essai prolongé de ' . $days . ' jours : il se termine maintenant le ' . date('d/m/Y', strtotime($new_end)) . '.';
                } catch (AkTrialBlocked $e) {
                    // Paiement Stripe en attente : rien n'est modifié, le Fondateur sait quoi faire
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = $e->getMessage();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('[super-admin-associations] trial_extend: ' . get_class($e) . ' ' . $e->getMessage());
                    $error = 'L’essai n’a pas pu être prolongé (erreur technique). Rien n’a été modifié.';
                }
            }
        }
        // ---- ESSAI : PASSER EN ABONNEMENT (ou changer de plan) ----
        elseif ($action === 'set_plan') {
            $plan_id = (int) ($_POST['plan_id'] ?? 0);
            $before = ak_org_plan_state($pdo, $target_id);
            $view_id = $target_id;
            if (!$can_plan) {
                $error = 'Vous n’avez pas le droit de changer le plan d’une association.';
            } elseif ($plan_id <= 0) {
                $error = 'Choisissez un plan.';
            } elseif ($err = sa_assoc_set_plan($pdo, $target_id, $plan_id)) {
                $error = $err;
            } else {
                $after = ak_org_plan_state($pdo, $target_id);
                sa_log_action((int) $user['id'], 'set_plan', $target_id, null, [
                    'from' => $before['plan_slug'], 'to' => $after['plan_slug'],
                    'was_trial' => $before['is_trial'] || $before['trial_expired'], 'status' => $after['badge'],
                ]);
                $success = 'Plan ' . $after['plan_label'] . ' activé (' . sa_plan_state_price($after) . ')'
                         . (($before['is_trial'] || $before['trial_expired']) && !$after['is_trial'] ? ' : l’essai est terminé.' : '.');
                // Paiement sur facture : premier mois à facturer depuis Factures, les suivants partent seuls
                // (cron-job-renouvellements, à partir de l'échéance posée par ak_org_set_plan)
                try {
                    $scols = ak_db_columns($pdo, 'asso_subscriptions');
                    if (!empty($after['is_paid']) && (int) $after['price_cents'] > 0 && isset($scols['current_period_end'], $scols['payment_mode'])) {
                        $cr = $pdo->prepare("SELECT s.payment_mode, s.current_period_end FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                                             WHERE s.org_id = ? ORDER BY " . ak_sub_order('s') . " LIMIT 1");
                        $cr->execute([$target_id]);
                        $crow = $cr->fetch(PDO::FETCH_ASSOC) ?: [];
                        if (($crow['payment_mode'] ?? '') !== 'stripe' && ($crow['payment_mode'] ?? '') !== 'free_grant' && !empty($crow['current_period_end'])) {
                            $success .= ' Paiement sur facture : émettez la facture du premier mois depuis Factures ; les mois suivants sont facturés automatiquement à partir du '
                                      . date('d/m/Y', strtotime((string) $crow['current_period_end'] . ' +1 day')) . '.';
                        }
                    }
                } catch (Throwable $e) {}
                if ($sid = ak_stripe_left_on_free($pdo, $target_id, $sfree)) $error = sa_stripe_left_message($sid, (bool) $sfree);
            }
        }
        // ---- SUSPEND ----
        elseif ($action === 'suspend') {
            $reason = trim($_POST['reason'] ?? '');
            $st_before = ak_org_plan_state($pdo, $target_id);
            // Suspendue pendant son essai : l'ancienne table passe aussi en « suspended » (plus de rappels
            // J-7 / J-3 / J-0). « Réactiver » rouvre l'essai s'il reste des jours. Même règle que l'appli.
            ak_org_suspend($pdo, $target_id, $reason !== '' ? $reason : 'Suspendu par super admin');
            sa_log_action((int) $user['id'], 'suspend_org', $target_id, null, ['reason' => $reason]);
            $success = 'Association suspendue.';
            if ($st_before['badge_tone'] === 'trial' && $st_before['trial_ends_at']) {
                $success .= ' Son essai est mis en pause : « Réactiver » le rouvre jusqu’au ' . date('d/m/Y', strtotime((string) $st_before['trial_ends_at'])) . '.';
            }
            $view_id = $target_id;
        }
        // ---- REACTIVATE ----
        elseif ($action === 'reactivate') {
            // Même règle que l'appli (ak_org_reactivate) : un essai en cours repasse « trial », jamais
            // « active » (qui lui laissait le plan d'essai sans fin) ; un essai dont la date est passée
            // se termine tout de suite (plan Démarrage) et l'association repart active, sans que le
            // cron ne la re-suspende quelques minutes plus tard.
            $view_id = $target_id;
            try {
                $trial_ended = null;
                $new_status = ak_org_reactivate($pdo, $target_id, $trial_ended);
                sa_log_action((int) $user['id'], 'reactivate_org', $target_id, null,
                    $new_status === 'trial' ? ['trial' => true] : ($trial_ended ? ['trial_ended' => $trial_ended] : []));
                $st_after = ak_org_plan_state($pdo, $target_id);
                if ($trial_ended) {
                    $success = 'Association réactivée sur le plan gratuit Démarrage (essai terminé le ' . date('d/m/Y', strtotime($trial_ended)) . ').'
                             . ' Prolongez l’essai ou passez-la en abonnement pour rouvrir les fonctionnalités PRO.';
                } else {
                    $success = 'Association réactivée (' . $st_after['badge'] . ').';
                }
            } catch (Throwable $e) {
                error_log('[super-admin-associations] reactivate: ' . get_class($e) . ' ' . $e->getMessage());
                $error = 'L’association n’a pas pu être réactivée (erreur technique).';
            }
        }
        // ---- CANCEL ----
        elseif ($action === 'cancel') {
            // Résilier une association en essai met aussi fin à l'essai (plan Démarrage), sinon
            // son abonnement d'essai continuerait d'afficher « ESSAI » sur une association résiliée.
            $st_before = ak_org_plan_state($pdo, $target_id);
            $ended_trial = false;
            if ($st_before['is_trial'] || $st_before['trial_expired']) {
                $free_id = (int) $pdo->query("SELECT id FROM asso_plans WHERE slug = 'demarrage' LIMIT 1")->fetchColumn();
                if ($free_id > 0 && !sa_assoc_set_plan($pdo, $target_id, $free_id)) $ended_trial = true;
            }
            $stmt = $pdo->prepare("UPDATE organizations SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$target_id]);
            // Client payant par carte : l'abonnement Stripe s'arrête aussi, sinon la carte reste prélevée
            $stripe = sa_stripe_cancel_for_org($pdo, $target_id);
            $log = $ended_trial ? ['trial_ended' => true] : [];
            if ($stripe['cancelled']) $log['stripe_cancelled'] = $stripe['cancelled'];
            if ($stripe['failed'])    $log['stripe_failed'] = array_keys($stripe['failed']);
            sa_log_action((int) $user['id'], 'cancel_org', $target_id, null, $log);
            $success = 'Association résiliée.' . ($ended_trial ? ' Son essai est terminé (plan Démarrage).' : '')
                     . sa_stripe_cancelled_note($stripe['cancelled']);
            if ($stripe['failed']) $error = sa_stripe_still_billing_message($stripe['failed']);
            $view_id = $target_id;
        }
        // ---- STRIPE : abonnement resté actif sur une association résiliée ou supprimée ----
        elseif ($action === 'stripe_cancel' || $action === 'stripe_mark_cancelled') {
            $view_id = $target_id;
            if (!$can_plan) {
                $error = 'Vous n’avez pas le droit de gérer l’abonnement d’une association.';
            } elseif (($org['status'] ?? '') !== 'cancelled' && empty($org['deleted_at'])) {
                $error = 'Cette association n’est pas résiliée : son abonnement Stripe continue normalement.';
            } else {
                $only_mark = $action === 'stripe_mark_cancelled';
                $stripe = sa_stripe_cancel_for_org($pdo, $target_id, $only_mark);
                if ($stripe['cancelled']) {
                    sa_log_action((int) $user['id'], $only_mark ? 'stripe_marked_cancelled' : 'stripe_cancel', $target_id, null, ['stripe' => $stripe['cancelled']]);
                    $success = $only_mark
                        ? 'Abonnement Stripe ' . implode(', ', $stripe['cancelled']) . ' noté comme annulé dans Stripe.'
                        : ltrim(sa_stripe_cancelled_note($stripe['cancelled']));
                }
                if ($stripe['failed']) $error = sa_stripe_still_billing_message($stripe['failed']);
                elseif (!$stripe['cancelled']) $success = 'Aucun abonnement Stripe actif sur cette association.';
            }
        }
        // ---- 2FA : RÉINITIALISER (Fondateur uniquement) ----
        elseif ($action === 'reset_2fa') {
            // Téléphone perdu sans code de secours : à la prochaine connexion, le mot de passe suffit,
            // et l'utilisateur peut réactiver la double authentification dans Paramètres › Sécurité.
            $view_id = $target_id;
            $target_user_id = (int) ($_POST['user_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ? AND org_id = ? AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([$target_user_id, $target_id]);
            $target_email = $stmt->fetchColumn();
            if (!$ctx['is_founder']) {
                $error = '🏗️ Seul un Fondateur peut réinitialiser la double authentification d’un compte.';
            } elseif ($target_email === false) {
                $error = 'Utilisateur introuvable dans cette association.';
            } elseif ($target_user_id === (int) $user['id']) {
                $error = 'Votre propre double authentification se gère dans Paramètres › Sécurité.';
            } else {
                require_once __DIR__ . '/totp-helper.php';
                if (!ak_totp_reset($pdo, $target_user_id, $target_id)) {
                    $error = 'La double authentification n’est pas activée sur ce compte : rien à réinitialiser.';
                } else {
                    sa_log_action((int) $user['id'], 'reset_2fa', $target_id, $target_user_id, ['email' => $target_email]);
                    $success = 'Double authentification réinitialisée pour ' . $target_email . ' : à la prochaine connexion, '
                             . 'le mot de passe suffira, et elle pourra être réactivée dans Paramètres › Sécurité.';
                }
            }
        }
        // ---- DELETE (soft-delete) ----
        elseif ($action === 'delete_org') {
            if (empty($ctx['can_delete_anything'])) {
                $error = '🏗️ Seul un Fondateur peut supprimer une association.';
            } elseif ((int) $target_id === 1) {
                $error = '🛡️ Cette organisation est protégée et ne peut pas être supprimée.';
            } else {
                $reason = trim($_POST['delete_reason'] ?? '');
                $pdo->beginTransaction();
                try {
                    // 1. Soft-delete de l'organisation
                    $stmt = $pdo->prepare("UPDATE organizations SET deleted_at = NOW(), deletion_reason = ?, deleted_by_user_id = ?, status = 'cancelled' WHERE id = ? AND deleted_at IS NULL");
                    $stmt->execute([$reason ?: 'Supprimée via super-admin', (int) $user['id'], $target_id]);
                    // 2. Cascade users : desactivation + liberation de l'email (UNIQUE respecte)
                    $stmt = $pdo->prepare("UPDATE users SET deleted_at = NOW(), is_active = 0, deleted_by_user_id = ?, email = CONCAT('deleted_', id, '_', UNIX_TIMESTAMP(), '__', email) WHERE org_id = ? AND deleted_at IS NULL");
                    $stmt->execute([(int) $user['id'], $target_id]);
                    $freed = $stmt->rowCount();
                    // 3. Rattachement à une collectivité levé, comme « Délier » : canal de suivi archivé
                    //    (messages conservés), agents de la collectivité retirés, place libérée dans son quota
                    $old_parent_id = (int) ($org['parent_org_id'] ?? 0);
                    if ($old_parent_id > 0) {
                        $pdo->prepare("UPDATE organizations SET parent_org_id = NULL, parent_org_linked_at = NULL WHERE id = ?")->execute([$target_id]);
                        require_once __DIR__ . '/includes-mairie-channels.php';
                        try { archive_mairie_channel_for_org($pdo, $target_id, $old_parent_id); }
                        catch (Throwable $e) { error_log('[delete_org] archive_mairie_channel_for_org: ' . $e->getMessage()); }
                    }
                    $pdo->commit();
                    // Boîte mail et agenda Google : accès Google retiré et copie locale des e-mails effacée (RGPD).
                    // Ce n'est qu'une copie de Gmail : rien n'y est perdu, une reconnexion réimporte tout.
                    require_once __DIR__ . '/mail-helpers.php';
                    require_once __DIR__ . '/google-helper.php';
                    try { mail_disconnect($pdo, (int) $target_id, true); }
                    catch (Throwable $e) { error_log('[delete_org] mail_disconnect: ' . $e->getMessage()); }
                    try { disconnect_google((int) $target_id); }
                    catch (Throwable $e) { error_log('[delete_org] disconnect_google: ' . $e->getMessage()); }
                    // Client payant par carte : l'abonnement Stripe s'arrête aussi, sinon la carte reste prélevée
                    $stripe = sa_stripe_cancel_for_org($pdo, $target_id);
                    $log = ['reason' => $reason, 'users_freed' => $freed];
                    if ($old_parent_id > 0) $log['parent_org_unlinked'] = $old_parent_id;
                    if ($stripe['cancelled']) $log['stripe_cancelled'] = $stripe['cancelled'];
                    if ($stripe['failed'])    $log['stripe_failed'] = array_keys($stripe['failed']);
                    sa_log_action((int) $user['id'], 'delete_org', $target_id, null, $log);
                    $success = '🗑️ Association supprimée (masquée). ' . $freed . ' compte(s) désactivé(s), e-mail(s) libéré(s). Récupérable en base si besoin.'
                             . sa_stripe_cancelled_note($stripe['cancelled']);
                    if ($stripe['failed']) $error = sa_stripe_still_billing_message($stripe['failed']);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = 'Erreur lors de la suppression : ' . $e->getMessage();
                }
            }
        }
    }
}

// =====================================================================
// Routage : fiche detaillee OU liste
// =====================================================================
if ($view_id > 0) {
    // ================== FICHE DETAILLEE ==================
    $stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
    $stmt->execute([$view_id]);
    $org = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$org) {
        sa_render_head('Association introuvable');
        sa_render_sidebar('associations');
        ?>
        <div class="sa-empty">
            <div class="sa-empty-icon">🔍</div>
            <div class="sa-empty-title">Association introuvable</div>
            <div style="margin-top:18px"><a href="/super-admin/associations" class="sa-btn sa-btn-violet">← Retour liste</a></div>
        </div>
        <?php
        sa_render_foot();
        exit;
    }

    // Stats de l'asso
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE org_id = ? AND is_active = 1 AND deleted_at IS NULL");
    $stmt->execute([$view_id]);
    $nb_users = (int) $stmt->fetchColumn();

    $nb_projects = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects p JOIN folders f ON p.folder_id = f.id WHERE f.org_id = ?");
        $stmt->execute([$view_id]);
        $nb_projects = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {}

    // Liste des users (avec l'état de leur double authentification)
    $totp_col = isset(ak_db_columns($pdo, 'users')['totp_enabled']) ? 'totp_enabled' : '0 AS totp_enabled';
    $stmt = $pdo->prepare("
        SELECT id, email, first_name, last_name, role, is_active, last_login_at, created_at, $totp_col
        FROM users
        WHERE org_id = ? AND deleted_at IS NULL AND is_active = 1
        ORDER BY FIELD(role, 'admin','coordinator','referent','member','follower'), last_name
    ");
    $stmt->execute([$view_id]);
    $org_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Plan et statut : la même vérité que l'appli et l'espace de l'association
    $plan_state = ak_org_plan_state($pdo, $view_id);
    $in_trial = $plan_state['is_trial'] || $plan_state['trial_expired'];
    $plans = sa_plans_list($pdo);
    $paid_plans = array_values(array_filter($plans, fn($p) => !$p['is_trial']));

    // Ligne d'abonnement courante (asso_subscriptions, même ordre que ak_get_current_plan)
    $asso_sub = null;
    try {
        $stmt = $pdo->prepare("SELECT s.* FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id
                               WHERE s.org_id = ? ORDER BY " . ak_sub_order('s') . " LIMIT 1");
        $stmt->execute([$view_id]);
        $asso_sub = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
    // Résiliation Stripe programmée (ligne « active », cancel_at_period_end) : la formule reste jusqu'à
    // l'échéance, puis Démarrage. Même date que l'espace de l'association (ak_sub_row_access_end).
    $cancel_scheduled = null;
    if ($asso_sub && !$in_trial && ($asso_sub['status'] ?? '') === 'active' && !empty($asso_sub['cancel_at_period_end'])) {
        $cancel_end = function_exists('ak_sub_row_access_end') ? ak_sub_row_access_end($pdo, $view_id, $asso_sub) : ($asso_sub['current_period_end'] ?? null);
        $cancel_scheduled = $cancel_end && strtotime((string) $cancel_end) ? date('d/m/Y', strtotime((string) $cancel_end)) : '';
    }

    // Ancienne table subscriptions : conditions de facturation manuelle (jamais le prix d'un essai)
    $subscription = null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE org_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$view_id]);
        $subscription = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
    $show_manual_billing = $subscription && !$in_trial && isset($subscription['price_ht'])
        && (!$plan_state['plan_id'] || (int) $plan_state['price_cents'] > 0 || $plan_state['plan_slug'] === 'sur-mesure');

    // Demande d'inscription en ligne (lecture seule)
    $signup = null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM public_signups WHERE org_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$view_id]);
        $signup = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
    $signup_notes = trim((string) ($org['internal_notes'] ?? ''));
    $from_signup = $signup || strpos($signup_notes, 'Inscription en ligne') === 0;
    $signup_details = [];
    if ($signup && !empty($signup['details_json'])) {
        $dec = json_decode((string) $signup['details_json'], true);
        if (is_array($dec)) {
            foreach ($dec as $k => $v) {
                $v = is_scalar($v) ? trim((string) $v) : json_encode($v, JSON_UNESCAPED_UNICODE);
                if ($v !== '') $signup_details[(string) $k] = $v;
            }
        }
    }
    // Notes coupées par une colonne trop courte : la fiche complète vient alors de public_signups
    $notes_cut = false;
    $oc = ak_db_columns($pdo, 'organizations');
    if ($signup_notes !== '' && preg_match('/^varchar\((\d+)\)/', $oc['internal_notes']['type'] ?? '', $m)) {
        $notes_cut = mb_strlen($signup_notes) >= (int) $m[1];
    }
    $show_notes = $signup_notes !== '' && !($notes_cut && $signup_details);
    $signup_when = $signup ? ($signup['completed_at'] ?? null) ?: ($signup['created_at'] ?? null) : null;
    $signup_contact = $signup ? trim(($signup['admin_first_name'] ?? '') . ' ' . ($signup['admin_last_name'] ?? '')) : '';
    $signup_email = $signup ? trim((string) ($signup['admin_email'] ?? '')) : '';
    $signup_phone = $signup ? trim((string) ($signup['phone'] ?? '')) : '';

    $days_left = $plan_state['days_left'];
    $trial_end_fr = $plan_state['trial_ends_at'] ? date('d/m/Y', strtotime((string) $plan_state['trial_ends_at'])) : '';
    // Suspendue à la main PENDANT l'essai (la fin d'essai est encore à venir) : « Essai suspendu »,
    // jamais « Essai terminé le <date future> » (état commun, le même que la liste et l'appli).
    $trial_paused = !empty($plan_state['trial_paused']);
    // Client Stripe passé sur un plan que Stripe ne facture pas (gratuit, essai, Sur-mesure…) :
    // l'abonnement Stripe de l'ancienne formule tourne encore
    $stripe_left_free = null;
    $stripe_left = ak_stripe_left_on_free($pdo, $view_id, $stripe_left_free);
    // Association résiliée ou supprimée dont l'abonnement Stripe n'a pas pu être annulé : à régler
    $org_closed = ($org['status'] ?? '') === 'cancelled' || !empty($org['deleted_at']);
    $stripe_still = $org_closed ? sa_stripe_live_subs($pdo, $view_id) : [];
    $pay_modes = ['stripe' => 'Stripe (carte)', 'manual' => 'Manuel', 'wire_transfer' => 'Virement', 'free_grant' => 'Offert'];

    sa_render_head($org['name']);
    sa_render_sidebar('associations');
    ?>

    <style>
    .sa-trial { position: relative; overflow: hidden; margin-bottom: 18px; padding: 22px 24px;
        border: 1px solid rgba(167, 139, 250, .45) !important;
        background: linear-gradient(135deg, rgba(139, 92, 246, .16), rgba(127, 119, 221, .06) 60%), var(--sa-bg-2) !important; }
    .sa-trial.is-expired { border-color: rgba(245, 158, 11, .5) !important;
        background: linear-gradient(135deg, rgba(245, 158, 11, .14), rgba(245, 158, 11, .04) 60%), var(--sa-bg-2) !important; }
    .sa-trial-top { display: flex; gap: 18px; align-items: flex-start; flex-wrap: wrap; }
    .sa-trial-main { flex: 1; min-width: 240px; }
    .sa-trial-tag { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 800; letter-spacing: .08em;
        padding: 4px 12px; border-radius: 999px; background: linear-gradient(135deg, #A78BFA, #7F77DD); color: #fff; }
    .sa-trial.is-expired .sa-trial-tag { background: linear-gradient(135deg, #FCD34D, #F59E0B); color: #78350F; }
    .sa-trial-title { font-size: 19px; font-weight: 750; letter-spacing: -.02em; margin: 12px 0 6px; color: var(--sa-ink); }
    .sa-trial-text { font-size: 13px; color: var(--sa-ink-2); line-height: 1.55; max-width: 640px; }
    .sa-trial-days { text-align: center; min-width: 120px; padding: 12px 16px; border-radius: 14px;
        background: rgba(0, 0, 0, .25); border: 1px solid rgba(255, 255, 255, .08); }
    .sa-trial-days .n { font-size: 34px; font-weight: 800; letter-spacing: -.04em; line-height: 1; color: #C4B5FD; font-variant-numeric: tabular-nums; }
    .sa-trial.is-expired .sa-trial-days .n { color: #FCD34D; }
    .sa-trial-days .l { font-size: 11.5px; color: var(--sa-ink-3); margin-top: 6px; }
    .sa-trial-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 18px; padding-top: 16px;
        border-top: 1px solid rgba(255, 255, 255, .08); }
    .sa-trial-actions form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 0; }
    .sa-trial-actions select { padding: 7px 10px; background: var(--sa-bg-2); border: 1px solid var(--sa-border-strong);
        border-radius: 8px; color: var(--sa-ink); font-family: inherit; font-size: 13px; max-width: 100%; }
    .sa-trial-sep { width: 1px; align-self: stretch; background: rgba(255, 255, 255, .1); }
    .sa-dl { font-size: 12.5px; color: var(--sa-ink-3); display: grid; grid-template-columns: auto 1fr; gap: 6px 12px; }
    .sa-dl > div:nth-child(even) { color: var(--sa-ink); overflow-wrap: anywhere; }
    .sa-signup-notes { font-size: 13px; line-height: 1.6; color: var(--sa-ink-2); background: rgba(0, 0, 0, .2);
        border: 1px solid var(--sa-border); border-radius: 10px; padding: 12px 14px; overflow-wrap: anywhere; }
    @media (max-width: 640px) { .sa-trial-sep { display: none; } .sa-trial-days { width: 100%; } }
    </style>

    <div class="sa-breadcrumb">
        <a href="/super-admin">Dashboard</a>
        <span class="sep">›</span>
        <a href="/super-admin/associations">Associations</a>
        <span class="sep">›</span>
        <?= h($org['name']) ?>
    </div>

    <div class="sa-page-head">
        <div>
            <h1 class="sa-page-title"><?= h($org['name']) ?></h1>
            <div class="sa-page-sub">
                <span class="sa-badge <?= sa_plan_tone_class($plan_state['badge_tone']) ?>"<?= in_array($plan_state['badge_tone'], ['trial', 'expired'], true) ? ' style="font-weight:800; letter-spacing:.03em;"' : '' ?>>
                    <?= sa_plan_tone_icon($plan_state['badge_tone']) ?> <?= h($plan_state['badge']) ?>
                </span>
                <?php if ($cancel_scheduled !== null): ?>
                    <span class="sa-badge sa-badge-amber" style="margin-left:6px;">🛑 Résiliation programmée<?= $cancel_scheduled !== '' ? ' le ' . h($cancel_scheduled) : '' ?></span>
                <?php endif; ?>
                <span style="margin:0 8px">·</span>
                Plan <?= h($plan_state['plan_label']) ?>
                <span style="margin:0 8px">·</span>
                Créée le <?= !empty($org['created_at']) ? date('d/m/Y', strtotime($org['created_at'])) : '—' ?>
            </div>
        </div>
        <div class="sa-page-actions">
            <a href="/super-admin/associations" class="sa-btn sa-btn-ghost">← Retour liste</a>
        </div>
    </div>

    <!-- Navigation onglets (Pack #5A) -->
    <div style="display:flex; gap:4px; margin-bottom:20px; border-bottom:1px solid var(--sa-border);">
        <a href="/super-admin/associations?id=<?= (int)$view_id ?>"
           style="padding:12px 20px; color:var(--sa-violet); text-decoration:none; font-size:14px; font-weight:600; border-bottom:2px solid var(--sa-violet); background:rgba(127, 119, 221, 0.05);">
            📊 Vue d'ensemble
        </a>
        <a href="/super-admin-association-facturation.php?id=<?= (int)$view_id ?>"
           style="padding:12px 20px; color:var(--sa-ink-3); text-decoration:none; font-size:14px; font-weight:500; border-bottom:2px solid transparent;">
            📄 Facturation
        </a>
    </div>

    <?php if ($error): ?><div class="sa-alert sa-alert-error">⚠️ <?= h($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="sa-alert sa-alert-success">✅ <?= h($success) ?></div><?php endif; ?>

    <?php
    // Banniere de validation si l'asso est pending
    $val_status = $org['validation_status'] ?? 'validated';
    // Demande de création d'espace faite dans l'app iPhone (rien d'actif avant la décision) ou
    // association créée par un super-admin (fonctionnelle, à approuver)
    $is_request = ak_signup_review_pending($org);
    if ($val_status === 'pending_founder'):
    ?>
    <div class="sa-card" style="background: linear-gradient(135deg, rgba(251, 191, 36, 0.06) 0%, rgba(245, 158, 11, 0.08) 100%); border-color:rgba(251, 191, 36, 0.35); margin-bottom:16px;">
        <div style="display:flex; align-items:flex-start; gap:14px;">
            <div style="font-size:32px;"><?= $is_request ? '📱' : '🏗️' ?></div>
            <div style="flex:1;">
                <div style="font-size:15px; font-weight:600; color:#FCD34D; margin-bottom:4px;">
                    <?= $is_request ? 'Demande de création d’espace (application iPhone) à valider' : 'Association en attente de validation Fondateur' ?>
                </div>
                <div style="font-size:13px; color:var(--sa-ink-2); margin-bottom:14px;">
                    <?php if ($is_request): ?>
                        Demandée le <?= h(ak_signup_review_when($org['created_at'] ?? null)) ?> — réponse promise sous <?= (int) AK_SIGNUP_REVIEW_HOURS ?> h.
                        Rien n’est actif : l’administrateur ne peut pas se connecter. <strong>Valider</strong> démarre l’essai PRO de <?= (int) AK_TRIAL_DAYS ?> jours
                        et lui envoie l’e-mail « Votre espace Assokit est activé » ; <strong>Refuser</strong> lui envoie un e-mail poli et garde l’espace fermé.
                        La fiche saisie est plus bas (« Demande d’inscription »).
                    <?php else: ?>
                    Cette asso a été créée par un Super Admin. Elle est fonctionnelle mais nécessite l'approbation d'un Fondateur pour être officiellement active sur la plateforme.
                    <?php endif; ?>
                </div>

                <?php if ($ctx['is_founder']): ?>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <form method="POST" action="/super-admin/associations" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                            <input type="hidden" name="action" value="validate_org">
                            <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">
                            <button type="submit" class="sa-btn" style="background:linear-gradient(135deg, #FCD34D 0%, #F59E0B 100%); color:#78350F; font-weight:600;">
                                ✓ Valider cette association
                            </button>
                        </form>
                        <button type="button" class="sa-btn sa-btn-danger" onclick="document.getElementById('reject-form').style.display='block'">
                            ✕ Refuser
                        </button>
                    </div>
                    <form method="POST" action="/super-admin/associations" id="reject-form" style="display:none; margin-top:14px;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="action" value="reject_org">
                        <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">
                        <div class="sa-group">
                            <label><?= $is_request ? 'Motif du refus (optionnel, repris dans l’e-mail au demandeur)' : 'Motif du refus (optionnel)' ?></label>
                            <textarea name="rejection_reason" rows="2"<?= $is_request ? ' maxlength="250"' : '' ?> placeholder="Ex: Doublon, infos manquantes..."></textarea>
                        </div>
                        <button type="submit" class="sa-btn sa-btn-danger">Confirmer le refus</button>
                    </form>
                <?php else: ?>
                    <div style="font-size:12.5px; color:var(--sa-ink-3); padding:10px 12px; background:rgba(0,0,0,0.2); border-radius:8px;">
                        ℹ️ Seul un Fondateur peut valider. Le Fondateur a été notifié.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php elseif ($val_status === 'rejected' && ak_signup_review_withdrawn($org)): ?>
    <div class="sa-alert sa-alert-error">
        <span style="font-size:18px">↩</span>
        <div>
            <strong>Demande d’espace retirée par le demandeur</strong>
            <br><span style="font-size:12.5px;">Espace fermé, compte anonymisé et informations transmises effacées (/retirer-demande). Rien n’est à décider.</span>
        </div>
    </div>
    <?php elseif ($val_status === 'rejected'): ?>
    <div class="sa-alert sa-alert-error">
        <span style="font-size:18px">✕</span>
        <div>
            <strong>Association refusée</strong>
            <?php if (!empty($org['rejection_reason'])): ?>
                <br><span style="font-size:12.5px;">Motif : <?= h($org['rejection_reason']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($in_trial): ?>
    <!-- ========== ESSAI ========== -->
    <div class="sa-card sa-trial<?= $plan_state['trial_expired'] || $trial_paused ? ' is-expired' : '' ?>" id="essai">
        <div class="sa-trial-top">
            <div class="sa-trial-main">
                <span class="sa-trial-tag"><?= $trial_paused ? '⏸ ESSAI SUSPENDU' : ($plan_state['trial_expired'] ? '⌛ ESSAI TERMINÉ' : '⏱ ESSAI PRO') ?></span>
                <?php if ($trial_paused): ?>
                    <div class="sa-trial-title">Association suspendue pendant son essai — fin d’essai prévue le <?= h($trial_end_fr) ?></div>
                    <div class="sa-trial-text">
                        L’accès est suspendu, les données sont conservées. «&nbsp;Réactiver&nbsp;» rouvre l’essai jusqu’au <?= h($trial_end_fr) ?>
                        (plan <?= h($plan_state['plan_label']) ?>, 0 €) ; vous pouvez aussi prolonger l’essai ou passer l’association en abonnement.
                    </div>
                <?php elseif ($plan_state['trial_expired']): ?>
                    <div class="sa-trial-title"><?= h($plan_state['badge']) ?></div>
                    <div class="sa-trial-text">
                        L’essai gratuit est arrivé à son terme sans abonnement : l’association repasse sur le plan gratuit Démarrage, ses données sont conservées.
                        Prolongez l’essai ou passez l’association en abonnement pour rouvrir l’accès.
                    </div>
                <?php else: ?>
                    <div class="sa-trial-title">
                        Essai gratuit en cours<?= $trial_end_fr !== '' ? ' — fin le ' . h($trial_end_fr) : ' — date de fin non définie' ?>
                    </div>
                    <div class="sa-trial-text">
                        Plan <strong><?= h($plan_state['plan_label']) ?></strong> · 0 € pendant l’essai, sans carte bancaire.
                        Toutes les fonctionnalités PRO sont ouvertes. Sans abonnement à la fin de l’essai, l’accès est suspendu.
                    </div>
                <?php endif; ?>
            </div>
            <div class="sa-trial-days">
                <?php if ($trial_paused): ?>
                    <div class="n">J-<?= (int) $days_left ?></div>
                    <div class="l">en pause</div>
                <?php elseif ($plan_state['trial_expired']): ?>
                    <div class="n"><?= $days_left !== null ? 'J+' . max(0, -$days_left) : '—' ?></div>
                    <div class="l">depuis la fin</div>
                <?php elseif ($days_left === null): ?>
                    <div class="n">—</div>
                    <div class="l">fin non définie</div>
                <?php elseif ($days_left <= 0): ?>
                    <div class="n">J-0</div>
                    <div class="l">dernier jour</div>
                <?php else: ?>
                    <div class="n">J-<?= (int) $days_left ?></div>
                    <div class="l"><?= (int) $days_left ?> jour<?= $days_left > 1 ? 's' : '' ?> restant<?= $days_left > 1 ? 's' : '' ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($can_plan): ?>
        <div class="sa-trial-actions">
            <?php foreach ([7, 14] as $d): ?>
            <form method="POST" action="/super-admin/associations">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="trial_extend">
                <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">
                <input type="hidden" name="days" value="<?= $d ?>">
                <button type="submit" class="sa-btn sa-btn-ghost sa-btn-sm">⏩ Prolonger de <?= $d ?> jours</button>
            </form>
            <?php endforeach; ?>
            <?php if ($paid_plans): ?>
            <span class="sa-trial-sep" aria-hidden="true"></span>
            <form method="POST" action="/super-admin/associations"
                  onsubmit="return confirm('Passer cette association sur le plan choisi ? L’essai prendra fin immédiatement.');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="set_plan">
                <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">
                <label for="trial-to-plan" style="font-size:12.5px; color:var(--sa-ink-2);">Passer en abonnement :</label>
                <select name="plan_id" id="trial-to-plan" required>
                    <?php foreach ($paid_plans as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $p['slug'] === 'assokit' ? 'selected' : '' ?>><?= h($p['option']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="sa-btn sa-btn-violet sa-btn-sm">✓ Passer en abonnement</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Stats rapides -->
    <div class="sa-kpi-grid">
        <div class="sa-kpi">
            <div class="sa-kpi-label">Utilisateurs</div>
            <div class="sa-kpi-value"><?= $nb_users ?></div>
        </div>
        <div class="sa-kpi">
            <div class="sa-kpi-label">Projets</div>
            <div class="sa-kpi-value"><?= $nb_projects ?></div>
        </div>
        <div class="sa-kpi">
            <div class="sa-kpi-label">Plan</div>
            <div class="sa-kpi-value" style="font-size:22px;"><?= h($plan_state['plan_label']) ?></div>
            <div class="sa-kpi-trend"><?= h(sa_plan_state_short($plan_state)) ?></div>
        </div>
        <div class="sa-kpi">
            <div class="sa-kpi-label">Abonnement</div>
            <?php if ($in_trial): ?>
                <div class="sa-kpi-value" style="font-size:22px;">0 €</div>
                <div class="sa-kpi-trend">essai gratuit</div>
            <?php elseif (!$plan_state['plan_id']): ?>
                <div class="sa-kpi-value" style="font-size:22px;">—</div>
                <div class="sa-kpi-trend">aucun abonnement (ancien plan)</div>
            <?php elseif ((int) $plan_state['price_cents'] > 0): ?>
                <div class="sa-kpi-value" style="font-size:22px;"><?= number_format($plan_state['price_cents'] / 100, 2, ',', ' ') ?> €</div>
                <div class="sa-kpi-trend">HT/mois</div>
            <?php else: ?>
                <div class="sa-kpi-value" style="font-size:22px;"><?= h(sa_plan_price_label(0, (string) $plan_state['plan_slug'])) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div style="display:grid; grid-template-columns: 2fr 1fr; gap: 20px;">

        <!-- ========== COLONNE GAUCHE ========== -->
        <div>

            <!-- Modification -->
            <div class="sa-card" style="margin-bottom: 16px;">
                <div class="sa-card-title">✏️ Modifier l'association</div>

                <form method="POST" action="/super-admin/associations">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">

                    <div class="sa-group">
                        <label>Nom de l'association</label>
                        <input type="text" name="name" value="<?= h($org['name']) ?>" required>
                    </div>

                    <div class="sa-group">
                        <label for="org-plan">Plan tarifaire</label>
                        <?php if ($can_plan && $plans): ?>
                        <select name="plan_id" id="org-plan">
                            <?php if (!$plan_state['plan_id']): ?>
                                <option value="0" selected>— Aucun abonnement (ancien plan : <?= h($plan_state['plan_label']) ?>) —</option>
                            <?php endif; ?>
                            <?php foreach ($plans as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" <?= (int) $plan_state['plan_id'] === $p['id'] ? 'selected' : '' ?>>
                                    <?= h($p['option']) ?><?= (int) $plan_state['plan_id'] === $p['id'] ? ' · actuel' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="font-size:11.5px; color:var(--sa-ink-4); margin-top:5px;">
                            Actuellement : <?= h($plan_state['plan_label']) ?> · <?= h($plan_state['badge']) ?>.
                            Le plan n’est modifié que si vous en choisissez un autre<?= $in_trial ? ' ; choisir un plan payant ou gratuit met fin à l’essai' : '' ?>.
                        </div>
                        <?php else: ?>
                        <div style="font-size:13px; color:var(--sa-ink-2);"><?= h($plan_state['plan_label']) ?> · <?= h($plan_state['badge']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="sa-group">
                        <label>Notes internes (super admin)</label>
                        <textarea name="notes_superadmin" rows="3" placeholder="Commentaires privés sur cette asso (pas visible par l'admin de l'asso)..."><?= h($org['notes_superadmin'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="sa-btn sa-btn-violet">💾 Enregistrer les modifications</button>
                </form>
            </div>

            <?php if ($from_signup && ($show_notes || $signup_details || $signup)): ?>
            <!-- Demande d'inscription (lecture seule) -->
            <div class="sa-card" style="margin-bottom: 16px;">
                <div class="sa-card-title">📝 Demande d’inscription</div>
                <div class="sa-card-sub">
                    Formulaire rempli par le prospect lors de l’inscription en ligne (lecture seule).
                    <?php if ($signup_when): ?>Reçue le <?= h(date('d/m/Y', strtotime((string) $signup_when))) ?><?= strlen((string) $signup_when) > 10 ? ' à ' . h(date('H:i', strtotime((string) $signup_when))) : '' ?>.<?php endif; ?>
                </div>
                <?php if ($signup_contact !== '' || $signup_email !== '' || $signup_phone !== ''): ?>
                <div class="sa-dl" style="margin-bottom:12px;">
                    <?php if ($signup_contact !== ''): ?><div>Contact :</div><div><?= h($signup_contact) ?></div><?php endif; ?>
                    <?php if ($signup_email !== ''): ?><div>E-mail :</div><div><?= h($signup_email) ?></div><?php endif; ?>
                    <?php if ($signup_phone !== '' && !isset($signup_details['Téléphone'])): ?><div>Téléphone :</div><div><?= h($signup_phone) ?></div><?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($show_notes): ?>
                    <div class="sa-signup-notes"><?= nl2br(h($signup_notes)) ?></div>
                <?php elseif ($signup_details): ?>
                    <div class="sa-signup-notes sa-dl">
                        <?php foreach ($signup_details as $k => $v): ?>
                            <div><?= h($k) ?> :</div><div><?= nl2br(h($v)) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="font-size:12.5px; color:var(--sa-ink-4);">Le détail du formulaire n’a pas été conservé.</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Liste des users -->
            <div class="sa-card">
                <div class="sa-card-title">👥 Utilisateurs (<?= count($org_users) ?>)</div>

                <?php if (empty($org_users)): ?>
                    <div class="sa-empty" style="padding: 40px 20px;">
                        <div class="sa-empty-icon">👥</div>
                        <div>Aucun utilisateur dans cette asso.</div>
                    </div>
                <?php else: ?>
                    <table class="sa-table" style="margin: 0 -6px;">
                        <thead>
                            <tr><th>Nom</th><th>Rôle</th><th>Statut</th><th>Dernière connexion</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($org_users as $u): ?>
                                <tr>
                                    <td>
                                        <div class="sa-main-col"><?= h($u['first_name'] . ' ' . $u['last_name']) ?></div>
                                        <div class="sa-sub-col"><?= h($u['email']) ?></div>
                                    </td>
                                    <td><span class="sa-badge sa-badge-violet"><?= h($u['role']) ?></span></td>
                                    <td>
                                        <?php if ($u['is_active']): ?>
                                            <span class="sa-badge sa-badge-green">Actif</span>
                                        <?php else: ?>
                                            <span class="sa-badge sa-badge-gray">Inactif</span>
                                        <?php endif; ?>
                                        <?php if (!empty($u['totp_enabled'])): ?>
                                            <div class="sa-sub-col" style="margin-top:4px;">🔐 Double authentification</div>
                                            <?php if ($ctx['is_founder'] && (int) $u['id'] !== (int) $user['id']): ?>
                                            <form method="POST" action="/super-admin/associations" style="margin-top:4px;"
                                                  onsubmit="return confirm('Réinitialiser la double authentification de <?= h(addslashes($u['email'])) ?> ? À la prochaine connexion, le mot de passe suffira.');">
                                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                                                <input type="hidden" name="action" value="reset_2fa">
                                                <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">
                                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                                <button type="submit" class="sa-btn sa-btn-ghost sa-btn-sm">Réinitialiser la 2FA</button>
                                            </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:12px; color:var(--sa-ink-3)">
                                        <?= $u['last_login_at'] ? date('d/m/Y H:i', strtotime($u['last_login_at'])) : '—' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ========== COLONNE DROITE ========== -->
        <div>

            <!-- Actions sensibles -->
            <div class="sa-card" style="margin-bottom: 16px;">
                <div class="sa-card-title">⚠️ Actions sensibles</div>
                <div class="sa-card-sub">Ces actions affectent l'accès de l'asso à la plateforme.</div>

                <?php if ($org['status'] === 'active' || $org['status'] === 'trial'): ?>
                    <form method="POST" action="/super-admin/associations"
                          onsubmit="return confirm('Suspendre cette asso ? Ses utilisateurs ne verront plus que l’avis de suspension, l’abonnement et l’export de leurs données.');" style="margin-bottom:8px">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="action" value="suspend">
                        <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">
                        <div class="sa-group">
                            <label style="font-size:11.5px">Raison de suspension (optionnel)</label>
                            <input type="text" name="reason" placeholder="Ex: Impayé, demande de l'asso...">
                        </div>
                        <button type="submit" class="sa-btn sa-btn-danger" style="width:100%">⏸ Suspendre</button>
                    </form>
                <?php elseif ($org['status'] === 'suspended'): ?>
                    <form method="POST" action="/super-admin/associations" style="margin-bottom:8px">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="action" value="reactivate">
                        <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">
                        <button type="submit" class="sa-btn sa-btn-violet" style="width:100%">▶ Réactiver</button>
                    </form>
                <?php endif; ?>

                <?php if ($org['status'] !== 'cancelled'): ?>
                    <form method="POST" action="/super-admin/associations"
                          onsubmit="return confirm('RÉSILIER définitivement cette asso ? Les données restent mais accès coupé. Un abonnement Stripe est annulé aussitôt.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">
                        <button type="submit" class="sa-btn sa-btn-ghost" style="width:100%">✕ Résilier</button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Abonnement : plan réel (asso_subscriptions), 0 € pendant un essai -->
            <div class="sa-card">
                <div class="sa-card-title">💳 Abonnement</div>

                <div class="sa-dl" style="margin-top:10px;">
                    <div>Plan :</div>
                    <div><strong><?= h($plan_state['plan_label']) ?></strong></div>

                    <div>Statut :</div>
                    <div>
                        <span class="sa-badge <?= sa_plan_tone_class($plan_state['badge_tone']) ?>"><?= h($plan_state['badge']) ?></span>
                        <?php if ($plan_state['sub_status'] === 'cancelled' && $plan_state['badge_tone'] !== 'cancelled'): ?>
                            <div style="font-size:11.5px; color:#FCA5A5; margin-top:4px;">Abonnement annulé<?= !empty($asso_sub['cancelled_at']) && strtotime((string) $asso_sub['cancelled_at']) ? ' le ' . date('d/m/Y', strtotime((string) $asso_sub['cancelled_at'])) : '' ?></div>
                        <?php elseif ($cancel_scheduled !== null): ?>
                            <div style="font-size:11.5px; color:#FCD34D; margin-top:4px;">
                                Résiliation programmée<?= $cancel_scheduled !== '' ? ' le ' . h($cancel_scheduled) : '' ?> : plus de prélèvement Stripe, puis plan gratuit Démarrage.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div>Prix :</div>
                    <div><?= h(sa_plan_state_price($plan_state)) ?></div>

                    <?php if ($in_trial && $trial_end_fr !== ''): ?>
                        <div><?= $trial_paused ? 'Fin d’essai prévue :' : 'Fin d’essai :' ?></div>
                        <div><?= h($trial_end_fr) ?></div>
                    <?php elseif ($asso_sub && !empty($asso_sub['current_period_end']) && strtotime((string) $asso_sub['current_period_end'])): ?>
                        <div>Échéance :</div>
                        <div><?= date('d/m/Y', strtotime((string) $asso_sub['current_period_end'])) ?></div>
                    <?php endif; ?>

                    <?php if ($in_trial): ?>
                        <div>Paiement :</div>
                        <div>aucun (essai gratuit, sans carte bancaire)</div>
                    <?php elseif ($asso_sub && !empty($asso_sub['payment_mode'])): ?>
                        <div>Paiement :</div>
                        <div><?= h($pay_modes[$asso_sub['payment_mode']] ?? $asso_sub['payment_mode']) ?></div>
                    <?php endif; ?>

                    <?php if ($show_manual_billing): ?>
                        <div>Facturation :</div>
                        <div>
                            <?= number_format((float) $subscription['price_ht'], 2, ',', ' ') ?> € HT
                            + TVA <?= h(rtrim(rtrim(number_format((float) ($subscription['tva_rate'] ?? 0), 2, ',', ''), '0'), ',')) ?> %
                            = <strong><?= number_format((float) $subscription['price_ht'] * (1 + (float) ($subscription['tva_rate'] ?? 0) / 100), 2, ',', ' ') ?> € TTC</strong>
                            <?= ($subscription['billing_cycle'] ?? '') === 'yearly' ? '/an' : '/mois' ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($stripe_still): ?>
                    <div class="sa-alert sa-alert-error" style="margin:12px 0 0; font-size:12.5px; display:block;">
                        ⚠️ <?= h(sa_stripe_still_billing_message(array_fill_keys(array_values($stripe_still), ''))) ?>
                        <?php if ($can_plan): ?>
                        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
                            <form method="POST" action="/super-admin/associations">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                <input type="hidden" name="action" value="stripe_cancel">
                                <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">
                                <button type="submit" class="sa-btn sa-btn-danger sa-btn-sm">Annuler dans Stripe</button>
                            </form>
                            <form method="POST" action="/super-admin/associations"
                                  onsubmit="return confirm('Confirmez-vous avoir annulé cet abonnement dans le tableau de bord Stripe ?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                <input type="hidden" name="action" value="stripe_mark_cancelled">
                                <input type="hidden" name="org_id" value="<?= (int) $org['id'] ?>">
                                <button type="submit" class="sa-btn sa-btn-ghost sa-btn-sm">Déjà annulé dans Stripe</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($stripe_left): ?>
                    <div class="sa-alert sa-alert-error" style="margin:12px 0 0; font-size:12.5px;">⚠️ <?= h(sa_stripe_left_message($stripe_left, (bool) $stripe_left_free)) ?></div>
                <?php endif; ?>

                <a href="/super-admin/abonnements?org_id=<?= (int) $org['id'] ?>" class="sa-btn sa-btn-ghost sa-btn-sm" style="margin-top:12px; width:100%">
                    Voir les factures →
                </a>
            </div>

        </div>
    </div>

    <?php
    sa_render_foot();
    exit;
}

// =====================================================================
// VUE LISTE (par defaut)
// =====================================================================
$filter_status = (string) ($_GET['status'] ?? '');
if (!in_array($filter_status, ['', 'active', 'trial', 'trial_expired', 'suspended', 'cancelled'], true)) $filter_status = '';
$filter_validation = $_GET['filter'] ?? '';  // pending, validated, rejected
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];
// Le statut (essai en cours / terminé compris) se filtre après lecture de l'état du plan,
// avec la même règle que l'affichage (ak_org_plan_state).
if ($filter_validation === 'pending') {
    $where[] = "o.validation_status = 'pending_founder'";
} elseif ($filter_validation === 'validated') {
    $where[] = "o.validation_status = 'validated'";
} elseif ($filter_validation === 'rejected') {
    $where[] = "o.validation_status = 'rejected'";
}
if ($search !== '') {
    $where[] = 'o.name LIKE ?';
    $params[] = '%' . $search . '%';
}
$where[] = 'o.deleted_at IS NULL';
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT o.*,
           COALESCE(o.validation_status, 'validated') AS validation_status_safe,
           (SELECT COUNT(*) FROM users u WHERE u.org_id = o.id AND u.is_active = 1 AND u.deleted_at IS NULL) AS nb_users,
           (SELECT COUNT(*) FROM projects p JOIN folders f ON p.folder_id = f.id WHERE f.org_id = o.id) AS nb_projects,
           (SELECT MAX(last_login_at) FROM users u WHERE u.org_id = o.id AND u.is_active = 1 AND u.deleted_at IS NULL) AS last_activity
    FROM organizations o
    $where_sql
    ORDER BY
        CASE WHEN o.validation_status = 'pending_founder' THEN 0 ELSE 1 END,
        o.created_at DESC
");
$stmt->execute($params);
$orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// État du plan de toutes les associations listées, en une requête
$plan_states = sa_org_plan_states($pdo, array_column($orgs, 'id'));
foreach ($orgs as &$o) {
    $o['_ps'] = $plan_states[(int) $o['id']] ?? ak_org_plan_state($pdo, (int) $o['id']);
}
unset($o);
if ($filter_status !== '') {
    $orgs = array_values(array_filter($orgs, function ($o) use ($filter_status) {
        $ps = $o['_ps'];
        return match ($filter_status) {
            'active'        => in_array($ps['badge_tone'], ['active', 'pending'], true),
            'trial'         => $ps['badge_tone'] === 'trial',
            'trial_expired' => $ps['badge_tone'] === 'expired',
            'suspended'     => $ps['org_status'] === 'suspended',
            'cancelled'     => $ps['org_status'] === 'cancelled',
            default         => true,
        };
    }));
}

sa_render_head('Associations');
sa_render_sidebar('associations');
?>

<div class="sa-page-head">
    <div>
        <h1 class="sa-page-title">🏛️ Associations</h1>
        <div class="sa-page-sub"><?= count($orgs) ?> résultat<?= count($orgs) > 1 ? 's' : '' ?></div>
    </div>
    <div class="sa-page-actions">
        <a href="/super-admin/nouvelle-asso" class="sa-btn sa-btn-violet">+ Créer une association</a>
    </div>
</div>

<?php if ($error): ?><div class="sa-alert sa-alert-error">⚠️ <?= h($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="sa-alert sa-alert-success">✅ <?= h($success) ?></div><?php endif; ?>

<!-- Onglets validation -->
<div style="display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap;">
    <a href="/super-admin/associations" class="sa-btn <?= !$filter_validation ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm">
        Toutes
    </a>
    <a href="/super-admin/associations?filter=pending" class="sa-btn <?= $filter_validation === 'pending' ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm"
       style="<?= $filter_validation !== 'pending' ? 'border-color:rgba(251,191,36,0.3); color:#FCD34D;' : '' ?>">
        🏗️ En attente validation
    </a>
    <a href="/super-admin/associations?filter=validated" class="sa-btn <?= $filter_validation === 'validated' ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm">
        ✓ Validées
    </a>
    <a href="/super-admin/associations?filter=rejected" class="sa-btn <?= $filter_validation === 'rejected' ? 'sa-btn-violet' : 'sa-btn-ghost' ?> sa-btn-sm">
        ✕ Refusées
    </a>
</div>

<!-- Filtres -->
<form method="GET" style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap;">
    <?php if ($filter_validation): ?>
        <input type="hidden" name="filter" value="<?= h($filter_validation) ?>">
    <?php endif; ?>
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="🔍 Rechercher par nom..."
           style="flex:1; min-width:200px; padding:8px 12px; background:var(--sa-bg-2); border:1px solid var(--sa-border-strong); border-radius:8px; color:var(--sa-ink); font-family:inherit;">

    <select name="status" style="padding:8px 12px; background:var(--sa-bg-2); border:1px solid var(--sa-border-strong); border-radius:8px; color:var(--sa-ink); font-family:inherit;">
        <option value="">Tous les statuts</option>
        <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Actives</option>
        <option value="trial" <?= $filter_status === 'trial' ? 'selected' : '' ?>>En essai</option>
        <option value="trial_expired" <?= $filter_status === 'trial_expired' ? 'selected' : '' ?>>Essai terminé</option>
        <option value="suspended" <?= $filter_status === 'suspended' ? 'selected' : '' ?>>Suspendues</option>
        <option value="cancelled" <?= $filter_status === 'cancelled' ? 'selected' : '' ?>>Résiliées</option>
    </select>

    <button type="submit" class="sa-btn sa-btn-ghost">Filtrer</button>
    <?php if ($filter_status || $search): ?>
        <a href="/super-admin/associations<?= $filter_validation ? '?filter=' . h($filter_validation) : '' ?>" class="sa-btn sa-btn-ghost">Effacer</a>
    <?php endif; ?>
</form>

<?php if (empty($orgs)): ?>
    <div class="sa-card">
        <div class="sa-empty">
            <div class="sa-empty-icon">🏛️</div>
            <div class="sa-empty-title">Aucune association trouvée</div>
            <div>Essayez un autre filtre ou <a href="/super-admin/nouvelle-asso" style="color:#C4B5FD">créez-en une</a>.</div>
        </div>
    </div>
<?php else: ?>
    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead>
                <tr>
                    <th>Association</th>
                    <th>Validation</th>
                    <th>Statut</th>
                    <th>Plan</th>
                    <th>Users</th>
                    <th>Projets</th>
                    <th>Dernière activité</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orgs as $o): ?>
                    <?php $val_status = $o['validation_status_safe'] ?? 'validated'; $ps = $o['_ps']; ?>
                    <tr style="cursor:pointer; <?= $val_status === 'pending_founder' ? 'background:rgba(251, 191, 36, 0.04);' : '' ?>" onclick="window.location='/super-admin/associations?id=<?= (int) $o['id'] ?>'">
                        <td>
                            <div class="sa-main-col"><?= h($o['name']) ?></div>
                            <div class="sa-sub-col">
                                Créée <?= !empty($o['created_at']) ? date('d/m/Y', strtotime($o['created_at'])) : '—' ?>
                                <?php if ($o['suspended_reason']): ?>
                                    · <span style="color:#FCA5A5"><?= h($o['suspended_reason']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($val_status === 'pending_founder'): ?>
                                <span class="sa-badge sa-badge-gold">🏗️ En attente</span>
                            <?php elseif ($val_status === 'rejected' && ak_signup_review_withdrawn($o)): ?>
                                <span class="sa-badge sa-badge-gray">↩ Retirée</span>
                            <?php elseif ($val_status === 'rejected'): ?>
                                <span class="sa-badge sa-badge-red">✕ Refusée</span>
                            <?php else: ?>
                                <span class="sa-badge sa-badge-green" style="opacity:0.7">✓ Validée</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="sa-badge <?= sa_plan_tone_class($ps['badge_tone']) ?>" title="<?= h($ps['badge']) ?>">
                                <?= sa_plan_tone_icon($ps['badge_tone']) ?> <?= h(sa_plan_state_short($ps)) ?>
                            </span>
                            <?php if (in_array($ps['badge_tone'], ['trial', 'expired'], true) && $ps['trial_ends_at']): ?>
                                <div class="sa-sub-col"><?= $ps['badge_tone'] === 'expired' ? 'le' : 'fin' ?> <?= date('d/m/Y', strtotime((string) $ps['trial_ends_at'])) ?></div>
                            <?php elseif (($ps['cancel_scheduled'] ?? null) !== null && $ps['badge_tone'] === 'active'): ?>
                                <div class="sa-sub-col" style="color:#FCD34D">résiliation programmée<?= $ps['cancel_scheduled'] !== '' ? ' le ' . h($ps['cancel_scheduled']) : '' ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="sa-badge <?= $ps['is_trial'] && !$ps['trial_expired'] ? 'sa-badge-violet' : 'sa-badge-gray' ?>"><?= h($ps['plan_label']) ?></span></td>
                        <td><?= (int) $o['nb_users'] ?></td>
                        <td><?= (int) $o['nb_projects'] ?></td>
                        <td style="color:var(--sa-ink-3); font-size:12.5px;">
                            <?= $o['last_activity'] ? date('d/m/Y H:i', strtotime($o['last_activity'])) : '—' ?>
                        </td>
                        <td onclick="event.stopPropagation()">
                            <a href="/super-admin/associations?id=<?= (int) $o['id'] ?>" class="sa-btn sa-btn-ghost sa-btn-sm">
                                Gérer →
                            </a>
                            <?php if ((int) $o['id'] !== 1 && !empty($ctx['can_delete_anything'])): ?>
                            <form method="POST" action="/super-admin/associations" style="display:inline"
                                  onsubmit="return confirm('Supprimer &laquo; <?= htmlspecialchars(addslashes($o['name'])) ?> &raquo; ? Elle sera masquee du tableau (donnees conservees en base).');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                <input type="hidden" name="action" value="delete_org">
                                <input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">
                                <button type="submit" class="sa-btn sa-btn-ghost sa-btn-sm" style="color:#DC2626;" title="Supprimer">🗑️</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php sa_render_foot(); ?>
