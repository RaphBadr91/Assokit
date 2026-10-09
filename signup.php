<?php
/**
 * ============================================================
 * ASSOKIT — Inscription publique : essai gratuit automatique
 * ============================================================
 * URL : /signup ou /signup?plan=association
 *
 * En 1 étape, sans carte bancaire :
 *   1. Nom de l'asso + prénom/nom + e-mail + mot de passe
 *   2. Création de l'association et de son admin, essai gratuit de
 *      AK_TRIAL_DAYS jours (15) ACTIVÉ IMMÉDIATEMENT, sans validation
 *   3. Connexion automatique, redirection vers le tableau de bord
 *   4. Le fondateur est prévenu de CHAQUE demande (e-mail + cloche),
 *      y compris si la création échoue : le prospect est alors
 *      enregistré et reçoit un accusé de réception, rien n'est perdu.
 *
 * Les écritures passent par ak_db_insert()/ak_db_update() (trial-helpers.php),
 * qui n'écrivent que les colonnes existant réellement en base : c'est une
 * colonne inexistante (organizations.plan_type) qui faisait échouer toutes
 * les inscriptions avec « Erreur technique lors de la création ».
 * ============================================================
 */
require_once __DIR__ . '/config.php';
@require_once __DIR__ . '/password-token-helper.php';
require_once __DIR__ . '/trial-helpers.php';

// Si deja connecte, rediriger
if (!empty($_SESSION['user_id'])) {
    header('Location: /dashboard');
    exit;
}

const AK_SIGNUP_PLANS = ['essentiel', 'association', 'organisation'];

$error  = null;   // erreur de saisie : le formulaire reste affiché
$notice = null;   // demande enregistrée mais non ouverte automatiquement : on remercie
$form = [
    'org_name'   => '',
    'first_name' => '',
    'last_name'  => '',
    'email'      => '',
    'plan'       => in_array($_GET['plan'] ?? '', AK_SIGNUP_PLANS, true) ? $_GET['plan'] : 'essentiel',
    'accept_cgu' => false,
];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/** Demande enregistrée sans ouverture automatique : alerte fondateur + accusé au prospect. */
function ak_signup_fallback(PDO $pdo, array $lead, ?int $signup_id, string $reason): string
{
    if ($signup_id) {
        try { ak_db_update($pdo, 'public_signups', $signup_id, ['status' => ak_db_pick($pdo, 'public_signups', 'status', ['blocked']) ?? 'blocked']); } catch (Throwable $e) {}
        try { ak_db_update($pdo, 'public_signups', $signup_id, ['error_message' => mb_substr($reason, 0, 500)]); } catch (Throwable $e) {}
    }
    try {
        @require_once __DIR__ . '/signup-email-helpers.php';
        if (function_exists('ak_signup_alert_founder')) ak_signup_alert_founder($pdo, $lead, 'failed', null, $reason);
        if (function_exists('ak_signup_ack_prospect')) ak_signup_ack_prospect($lead['email'], $lead['first_name'], $lead['org_name']);
    } catch (Throwable $e) {
        error_log('[signup] fallback: ' . get_class($e));
    }
    return 'Merci ' . $lead['first_name'] . ' ! Votre demande d’essai pour « ' . $lead['org_name'] . ' » est bien enregistrée. '
         . 'Un incident nous empêche de l’ouvrir automatiquement : notre équipe l’active pour vous et vous écrit à '
         . $lead['email'] . ' sous 24 h ouvrées. Inutile de recommencer.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/rate-limit-helper.php';
    $csrf_ok = function_exists('check_csrf') && check_csrf($_POST['csrf_token'] ?? '');

    // Anti-robots : champ invisible rempli, ou formulaire envoyé en moins de 2 secondes.
    // On affiche un faux succès, sans rien écrire ni alerter personne.
    $form_at  = (int)($_SESSION['signup_form_at'] ?? 0);
    $is_robot = trim((string)($_POST['website'] ?? '')) !== '' || ($form_at > 0 && time() - $form_at < 2);

    if (!$csrf_ok) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $error = 'Session expirée. Veuillez réessayer.';
    } elseif ($is_robot) {
        $notice = 'Merci ! Votre demande est bien enregistrée.';
    } elseif (!ak_rate_limit('signup', 5, 900)) {
        $error = 'Trop de tentatives de création de compte. Merci de patienter quelques minutes.';
    } else {
        $form['org_name']   = trim((string)($_POST['org_name'] ?? ''));
        $form['first_name'] = trim((string)($_POST['first_name'] ?? ''));
        $form['last_name']  = trim((string)($_POST['last_name'] ?? ''));
        $form['email']      = strtolower(trim((string)($_POST['email'] ?? '')));
        $form['plan']       = in_array($_POST['plan'] ?? '', AK_SIGNUP_PLANS, true) ? $_POST['plan'] : 'essentiel';
        $form['accept_cgu'] = !empty($_POST['accept_cgu']);
        $password           = (string)($_POST['password'] ?? '');
        $password_confirm   = (string)($_POST['password_confirm'] ?? '');
        $no_link            = '~https?://|www\.|[<>]~i';

        if ($form['org_name'] === '' || mb_strlen($form['org_name']) < 2) {
            $error = 'Le nom de l\'association est requis (2 caractères minimum).';
        } elseif (mb_strlen($form['org_name']) > 200) {
            $error = 'Le nom de l\'association est trop long.';
        } elseif ($form['first_name'] === '' || $form['last_name'] === '') {
            $error = 'Veuillez renseigner votre prénom et votre nom.';
        } elseif (mb_strlen($form['first_name']) > 100 || mb_strlen($form['last_name']) > 100) {
            $error = 'Prénom ou nom trop long.';
        } elseif (preg_match($no_link, $form['org_name'] . ' ' . $form['first_name'] . ' ' . $form['last_name'])) {
            $error = 'Les liens et les caractères < > ne sont pas acceptés dans les noms.';
        } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 190) {
            $error = 'Adresse email invalide.';
        } elseif (mb_strlen($password) < 8) {
            $error = 'Le mot de passe doit faire au moins 8 caractères.';
        } elseif (mb_strlen($password) > 200) {
            $error = 'Le mot de passe est trop long.';
        } elseif ($password !== $password_confirm) {
            $error = 'Les mots de passe ne correspondent pas.';
        } elseif (!$form['accept_cgu']) {
            $error = 'Veuillez accepter les CGU et la politique de confidentialité.';
        }

        $ip   = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $lead = ['org_name' => $form['org_name'], 'first_name' => $form['first_name'], 'last_name' => $form['last_name'],
                 'email' => $form['email'], 'plan' => $form['plan'], 'ip' => $ip, 'signup_id' => null, 'trial_end' => null];
        $has_status = isset(ak_db_columns($pdo, 'public_signups')['status']);

        // Anti-abus : 5 essais OUVERTS par heure et par adresse IP (une panne ne bloque plus le prospect)
        if (!$error) {
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM public_signups WHERE ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
                                    . ($has_status ? " AND status = 'completed'" : ""));
                $st->execute([$ip]);
                if ((int)$st->fetchColumn() >= 5) $error = 'Trop d’inscriptions depuis cette adresse. Merci de réessayer dans une heure.';
            } catch (Throwable $e) {
                error_log('[signup] limite IP: ' . get_class($e));
            }
        }

        // Compte déjà existant
        $db_trouble = null;
        if (!$error) {
            try {
                $st = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $st->execute([$form['email']]);
                if ($st->fetchColumn()) {
                    $error = 'Un compte existe déjà avec cette adresse email. Connectez-vous, ou utilisez « Mot de passe oublié ».';
                }
            } catch (Throwable $e) {
                $db_trouble = get_class($e) . ': ' . $e->getMessage();
            }
        }

        if (!$error) {
            // Trace de la demande, AVANT toute création : rien n'est jamais perdu
            $signup_id = null;
            try {
                $signup_id = ak_db_insert($pdo, 'public_signups', [
                    'org_name' => mb_substr($form['org_name'], 0, 200), 'admin_email' => $form['email'],
                    'admin_first_name' => mb_substr($form['first_name'], 0, 100), 'admin_last_name' => mb_substr($form['last_name'], 0, 100),
                    'plan_choice' => $form['plan'], 'ip_address' => $ip,
                    'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                    'referer' => mb_substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
                    'status' => 'pending',
                ], ['created_at' => 'NOW()']) ?: null;
            } catch (Throwable $e) {
                error_log('[signup] public_signups: ' . get_class($e) . ' ' . $e->getCode());
            }
            $lead['signup_id'] = $signup_id;

            // Disjoncteur anti-abus : plus de 30 essais ouverts en 1 h → les demandes sont
            // transmises au fondateur au lieu d'être ouvertes automatiquement.
            $breaker = false;
            try {
                if ($has_status) {
                    $breaker = (int)$pdo->query("SELECT COUNT(*) FROM public_signups WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn() >= 30;
                }
            } catch (Throwable $e) {}

            if ($db_trouble !== null) {
                $notice = ak_signup_fallback($pdo, $lead, $signup_id, 'Contrôle du doublon impossible : ' . $db_trouble);
            } elseif ($breaker) {
                $notice = ak_signup_fallback($pdo, $lead, $signup_id, 'Plus de 30 essais ouverts en 1 h : ouverture automatique suspendue (anti-abus). Vérifiez puis créez l’espace.');
            } else {
                $warnings = [];
                $org_id = 0; $user_id = 0;
                try {
                    $trial_end  = (string)$pdo->query("SELECT DATE_ADD(NOW(), INTERVAL " . (int)AK_TRIAL_DAYS . " DAY)")->fetchColumn();
                    $lead['trial_end'] = $trial_end;
                    $trial_plan = ak_trial_plan($pdo);
                    if (!$trial_plan) $warnings[] = 'Aucun plan d’essai trouvé (asso_plans) : l’association a le plan par défaut.';
                    $slug = ak_signup_slug($pdo, $form['org_name']);

                    $pdo->beginTransaction();

                    // 1. Association — la même forme d'INSERT que les créations fondateur, éprouvées en prod
                    try {
                        $org_id = ak_db_insert($pdo, 'organizations',
                            ['name' => mb_substr($form['org_name'], 0, 200), 'slug' => $slug, 'billing_email' => $form['email']],
                            ['created_at' => 'NOW()']);
                    } catch (PDOException $e) {
                        if ((string)$e->getCode() !== '23000') throw $e;   // slug pris entre-temps : un autre
                        $slug = substr($slug, 0, 50) . '-' . bin2hex(random_bytes(2));
                        $org_id = ak_db_insert($pdo, 'organizations',
                            ['name' => mb_substr($form['org_name'], 0, 200), 'slug' => $slug, 'billing_email' => $form['email']],
                            ['created_at' => 'NOW()']);
                    }
                    if ($org_id <= 0) throw new RuntimeException('organizations: id introuvable après INSERT');

                    // Essai actif tout de suite, sans validation manuelle
                    $upd = ['trial_ends_at' => $trial_end];
                    if ($trial_plan && ak_db_accepts($pdo, 'organizations', 'plan', $trial_plan['slug'])) $upd['plan'] = $trial_plan['slug'];
                    if ($v = ak_db_pick($pdo, 'organizations', 'status', ['trial', 'active'])) $upd['status'] = $v;
                    if ($v = ak_db_pick($pdo, 'organizations', 'validation_status', ['validated', 'approved'])) $upd['validation_status'] = $v;
                    ak_db_update($pdo, 'organizations', $org_id, $upd, ['validated_at' => 'NOW()']);

                    // 2. Admin de l'association
                    $colors = ['#3b82f6', '#8b5cf6', '#f59e0b', '#ec4899', '#14b8a6'];
                    $user_id = ak_db_insert($pdo, 'users', [
                        'org_id' => $org_id, 'role' => 'admin', 'email' => $form['email'],
                        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                        'first_name' => mb_substr($form['first_name'], 0, 100), 'last_name' => mb_substr($form['last_name'], 0, 100),
                        'avatar_color' => $colors[array_rand($colors)],
                        'must_change_password' => 0, 'is_active' => 1,
                        'can_create_projects' => 1, 'can_create_folders' => 1, 'can_manage_members' => 1, 'can_manage_finances' => 1,
                        'can_access_marketing' => 1, 'can_manage_events' => 1, 'can_moderate_messages' => 1,
                    ], ['adhesion_date' => 'CURDATE()', 'created_at' => 'NOW()']);
                    if ($user_id <= 0) throw new RuntimeException('users: id introuvable après INSERT');
                    ak_db_update($pdo, 'organizations', $org_id, ['created_by_user_id' => $user_id]);

                    $pdo->commit();
                } catch (Throwable $e) {
                    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e0) {}
                    error_log('[signup] création: ' . get_class($e) . ': ' . $e->getMessage());
                    $notice = ak_signup_fallback($pdo, $lead, $signup_id, get_class($e) . ': ' . $e->getMessage());
                    $org_id = 0;
                }

                if ($org_id > 0 && $user_id > 0) {
                    // 3. Éléments de l'essai, hors transaction : un échec ici ne coûte jamais le compte.
                    try {
                        ak_db_insert($pdo, 'folders', ['org_id' => $org_id, 'name' => 'Projets généraux', 'color_theme' => 'blue',
                            'icon' => 'folder', 'created_by' => $user_id], ['created_at' => 'NOW()']);
                    } catch (Throwable $e) {
                        $warnings[] = 'Dossier « Projets généraux » non créé (' . get_class($e) . ').';
                    }
                    // Abonnement « historique » : bandeau d'essai + rappels J-7 / J-3 / J-1 / J-0
                    try {
                        $sub_status = ak_db_pick($pdo, 'subscriptions', 'status', ['trial']);
                        if (!ak_db_columns($pdo, 'subscriptions')) {
                            $warnings[] = 'Table subscriptions absente : pas de bandeau d’essai ni de rappels.';
                        } elseif (!$sub_status) {
                            $warnings[] = 'subscriptions.status n’accepte pas « trial » : pas de bandeau d’essai ni de rappels.';
                        } else {
                            ak_db_insert($pdo, 'subscriptions', [
                                'org_id' => $org_id,
                                'plan' => ak_db_pick($pdo, 'subscriptions', 'plan', ['pro', 'association', 'essentiel']) ?? 'pro',
                                'status' => $sub_status, 'billing_cycle' => ak_db_pick($pdo, 'subscriptions', 'billing_cycle', ['monthly']) ?? 'monthly',
                                'price_ht' => 49.99, 'tva_rate' => 20.00, 'current_period_end' => $trial_end,
                            ], ['started_at' => 'NOW()', 'current_period_start' => 'NOW()', 'created_at' => 'NOW()']);
                        }
                    } catch (Throwable $e) {
                        $warnings[] = 'Abonnement d’essai (subscriptions) non créé : ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 160);
                    }
                    // Plan des fonctionnalités pendant l'essai
                    if ($trial_plan) {
                        try {
                            ak_db_insert($pdo, 'asso_subscriptions', [
                                'org_id' => $org_id, 'plan_id' => $trial_plan['id'],
                                'status' => ak_db_pick($pdo, 'asso_subscriptions', 'status', ['trial', 'active']) ?? 'trial',
                                'payment_mode' => ak_db_pick($pdo, 'asso_subscriptions', 'payment_mode', ['free_grant', 'manual']),
                                'current_period_end' => $trial_end,
                            ], ['started_at' => 'NOW()', 'created_at' => 'NOW()', 'updated_at' => 'NOW()']);
                        } catch (Throwable $e) {
                            $warnings[] = 'Plan d’essai (asso_subscriptions) non attribué : ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 160);
                        }
                    }
                    if ($signup_id) {
                        try {
                            ak_db_update($pdo, 'public_signups', $signup_id, ['org_id' => $org_id, 'user_id' => $user_id, 'status' => 'completed'], ['completed_at' => 'NOW()']);
                        } catch (Throwable $e) {
                            error_log('[signup] public_signups completed: ' . get_class($e));
                        }
                    }

                    // 4. Connexion automatique (avant les e-mails : si l'envoi est lent, le prospect est déjà connecté)
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['org_id'] = $org_id;
                    $_SESSION['user_email'] = $form['email'];
                    $_SESSION['user_name'] = $form['first_name'] . ' ' . $form['last_name'];
                    $_SESSION['user_role'] = 'admin';
                    $_SESSION['is_super_admin'] = 0;
                    $_SESSION['logged_at'] = time();
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $_SESSION['signup_welcome'] = 1;
                    unset($_SESSION['signup_form_at']);
                    session_write_close();

                    header('Location: /dashboard?welcome=1');
                    // La réponse part tout de suite ; les e-mails sont envoyés ensuite.
                    ignore_user_abort(true);
                    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
                    elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

                    // 5. E-mails : bienvenue au prospect, alerte au fondateur (jamais bloquants)
                    try {
                        @require_once __DIR__ . '/signup-email-helpers.php';
                        $verify_url = function_exists('ak_email_verify_url') ? ak_email_verify_url($user_id) : 'https://assokit.fr/connexion';
                        if (function_exists('send_demo_welcome_email')) {
                            send_demo_welcome_email($form['email'], $form['first_name'], $form['org_name'], $verify_url, ak_trial_end_label($trial_end));
                        }
                        if (function_exists('ak_signup_alert_founder')) {
                            ak_signup_alert_founder($pdo, $lead, $warnings ? 'incomplete' : 'ok', $org_id, null, $warnings);
                        }
                    } catch (Throwable $e) {
                        error_log('[signup] e-mails: ' . get_class($e));
                    }
                    exit;
                }
            }
        }
    }
}

// Heure d'affichage du formulaire (anti-robots : un humain met plus de 2 secondes)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $error) $_SESSION['signup_form_at'] = time();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Essai gratuit <?= (int)AK_TRIAL_DAYS ?> jours — Assokit</title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect x='2' y='2' width='28' height='28' rx='7' fill='%23059669'/%3E%3Ccircle cx='22' cy='22' r='4.5' fill='%23FFFFFF'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --acc: #059669; --acc-dark: #047857; --acc-light: #D1FAE5;
  --ink: #0A0A0B; --ink-2: #3F3F46; --ink-3: #71717A;
  --bg: #FFFFFF; --bg-2: #FAFAF9;
  --border: rgba(0,0,0,0.08); --border-strong: rgba(0,0,0,0.14);
}
body {
  font-family: 'Geist', -apple-system, BlinkMacSystemFont, sans-serif;
  background: var(--bg-2); color: var(--ink); min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  padding: 24px; font-size: 15px; line-height: 1.5;
  -webkit-font-smoothing: antialiased;
}
.wrap { max-width: 460px; width: 100%; }
.logo-wrap { text-align: center; margin-bottom: 24px; }
.logo-mark {
  display: inline-block; width: 48px; height: 48px;
  background: var(--acc); border-radius: 12px; position: relative;
  box-shadow: 0 4px 14px rgba(5, 150, 105, 0.2);
}
.logo-mark::after {
  content: ''; position: absolute;
  right: 10px; bottom: 10px;
  width: 12px; height: 12px;
  background: white; border-radius: 50%;
}
.logo-name { display: block; margin-top: 10px; font-size: 14px; color: var(--ink-3); letter-spacing: 0.05em; }

.card {
  background: var(--bg); border: 1px solid var(--border);
  border-radius: 16px; padding: 32px 28px;
  box-shadow: 0 10px 30px rgba(0,0,0,0.04);
}
h1 { font-size: 22px; font-weight: 500; margin-bottom: 6px; letter-spacing: -0.02em; }
.sub { font-size: 13.5px; color: var(--ink-2); margin-bottom: 22px; line-height: 1.55; }

.plan-pill {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 4px 12px; background: var(--acc-light); color: var(--acc-dark);
  border-radius: 999px; font-size: 12px; font-weight: 500; margin-bottom: 18px;
}
.plan-pill-dot { width: 6px; height: 6px; background: var(--acc); border-radius: 50%; }

.alert-error {
  background: #FEF2F2; border: 1px solid #FCA5A5;
  color: #991B1B; padding: 12px 14px;
  border-radius: 10px; font-size: 13px;
  margin-bottom: 16px; line-height: 1.5;
}

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
@media (max-width: 430px) { .form-row { grid-template-columns: 1fr; } }
.form-group { margin-bottom: 12px; }
label { display: block; font-size: 12.5px; font-weight: 500; color: var(--ink-2); margin-bottom: 6px; }
input[type="text"], input[type="email"], input[type="password"] {
  width: 100%; padding: 11px 13px;
  background: var(--bg-2); border: 1px solid var(--border-strong);
  border-radius: 9px; font-family: inherit; font-size: 14px; color: var(--ink);
  transition: border-color 0.15s, background 0.15s;
}
input:focus { outline: none; border-color: var(--acc); background: var(--bg); box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1); }

.pw-hint { font-size: 11.5px; color: var(--ink-3); margin-top: 5px; }

.cgu-row {
  display: flex; align-items: flex-start; gap: 8px;
  font-size: 12.5px; color: var(--ink-2); margin: 14px 0 16px;
  line-height: 1.5;
}
.cgu-row input[type="checkbox"] { margin-top: 2px; accent-color: var(--acc); flex-shrink: 0; }
.cgu-row a { color: var(--acc); text-decoration: none; }
.cgu-row a:hover { text-decoration: underline; }

.btn-submit {
  width: 100%; background: var(--ink); color: var(--bg);
  border: none; padding: 13px 20px; border-radius: 10px;
  font-family: inherit; font-size: 14.5px; font-weight: 500;
  cursor: pointer; transition: opacity 0.15s, transform 0.15s;
}
.btn-submit:hover { opacity: 0.88; transform: translateY(-1px); }

.login-link { display: block; text-align: center; margin-top: 18px; font-size: 13px; color: var(--ink-3); }
.login-link a { color: var(--acc); text-decoration: none; font-weight: 500; }
.login-link a:hover { text-decoration: underline; }

.trust-line {
  text-align: center; font-size: 11.5px; color: var(--ink-3);
  margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border);
  display: flex; justify-content: center; gap: 14px; flex-wrap: wrap;
}
</style>
</head>
<body>

<div class="wrap">

  <div class="logo-wrap">
    <a href="/" style="display:inline-block;">
      <span class="logo-mark"></span>
    </a>
    <span class="logo-name">ASSOKIT</span>
  </div>

  <div class="card">

    <?php if ($form['plan'] === 'association'): ?>
      <span class="plan-pill"><span class="plan-pill-dot"></span>Formule Association · essai gratuit <?= (int)AK_TRIAL_DAYS ?> jours</span>
    <?php elseif ($form['plan'] === 'organisation'): ?>
      <span class="plan-pill"><span class="plan-pill-dot"></span>Formule Organisation · essai gratuit <?= (int)AK_TRIAL_DAYS ?> jours</span>
    <?php else: ?>
      <span class="plan-pill"><span class="plan-pill-dot"></span>Essai gratuit <?= (int)AK_TRIAL_DAYS ?> jours · sans carte bancaire</span>
    <?php endif; ?>

    <h1>Démarrez votre essai gratuit</h1>
    <p class="sub"><?= (int)AK_TRIAL_DAYS ?> jours d’accès complet, activé immédiatement. Sans carte bancaire, sans engagement.</p>

    <?php if ($notice): ?>
      <div class="alert-ok" style="background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;padding:14px 16px;border-radius:12px;font-size:14px;line-height:1.55;margin-bottom:6px;">✅ <?= htmlspecialchars($notice) ?></div>
    <?php else: ?>
    <?php if ($error): ?>
      <div class="alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off" id="signupForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="plan" value="<?= htmlspecialchars($form['plan']) ?>">
      <!-- Champ piège anti-robots : invisible pour les humains -->
      <div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
        <label for="website">Site web</label>
        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off" value="">
      </div>

      <div class="form-group">
        <label for="org_name">Nom de votre association *</label>
        <input type="text" id="org_name" name="org_name" required maxlength="200" autofocus
               placeholder="Ex : Latitude 91" value="<?= htmlspecialchars($form['org_name']) ?>">
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="first_name">Votre prénom *</label>
          <input type="text" id="first_name" name="first_name" required maxlength="100"
                 value="<?= htmlspecialchars($form['first_name']) ?>">
        </div>
        <div class="form-group">
          <label for="last_name">Votre nom *</label>
          <input type="text" id="last_name" name="last_name" required maxlength="100"
                 value="<?= htmlspecialchars($form['last_name']) ?>">
        </div>
      </div>

      <div class="form-group">
        <label for="email">Votre email *</label>
        <input type="email" id="email" name="email" required maxlength="200" autocomplete="email"
               placeholder="vous@association.fr" value="<?= htmlspecialchars($form['email']) ?>">
      </div>

      <div class="form-group">
        <label for="password">Mot de passe *</label>
        <div style="position:relative;">
          <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password" style="padding-right:44px;">
          <button type="button" id="togglePwd" aria-label="Afficher le mot de passe" aria-pressed="false"
            style="position:absolute;top:50%;right:8px;transform:translateY(-50%);background:none;border:0;cursor:pointer;padding:6px;color:#64748B;font-size:14px;">👁</button>
        </div>
        <div class="pw-hint">8 caractères minimum — choisissez un mot de passe robuste.</div>
      </div>

      <div class="form-group">
        <label for="password_confirm">Confirmez le mot de passe *</label>
        <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
        <div id="pwMatchMsg" class="pw-hint" style="display:none;color:#DC2626;">Les mots de passe ne correspondent pas.</div>
      </div>
      <script>
        (function(){
          var b=document.getElementById('togglePwd'), p=document.getElementById('password'),
              c=document.getElementById('password_confirm'), m=document.getElementById('pwMatchMsg');
          if(b&&p){ b.addEventListener('click', function(){
            var show=p.type==='password'; p.type=show?'text':'password';
            b.setAttribute('aria-pressed', show?'true':'false');
            b.setAttribute('aria-label', show?'Masquer le mot de passe':'Afficher le mot de passe');
          }); }
          function check(){ if(!c||!m) return;
            var mismatch = c.value.length>0 && p.value!==c.value;
            m.style.display = mismatch ? 'block' : 'none';
            c.setCustomValidity(mismatch ? 'Les mots de passe ne correspondent pas.' : '');
          }
          if(p&&c){ p.addEventListener('input',check); c.addEventListener('input',check); }
        })();
      </script>

      <label class="cgu-row">
        <input type="checkbox" name="accept_cgu" value="1" required<?= !empty($form['accept_cgu']) ? ' checked' : '' ?>>
        <span>J'accepte les <a href="/cgu" target="_blank">conditions générales d'utilisation</a> et la <a href="/confidentialite" target="_blank">politique de confidentialité</a>.</span>
      </label>

      <button type="submit" class="btn-submit" id="signupBtn">Démarrer mon essai gratuit →</button>
    </form>
    <script>
      // Un seul envoi : un double clic créait deux demandes
      (function(){ var f=document.getElementById('signupForm'), b=document.getElementById('signupBtn');
        if(f&&b) f.addEventListener('submit', function(){ setTimeout(function(){ b.disabled=true; b.textContent='Création de votre espace…'; }, 0); }); })();
    </script>
    <?php endif; ?>

    <div class="trust-line">
      <span>🇫🇷 Hébergé en France</span>
      <span>🔒 Conforme RGPD</span>
      <span>✨ IA incluse</span>
    </div>

  </div>

  <div class="login-link">
    Vous avez déjà un compte ? <a href="/connexion">Se connecter</a><br>
    Vous préférez être accompagné ? <a href="/contact">Parler à un conseiller</a>
  </div>

</div>

</body>
</html>
