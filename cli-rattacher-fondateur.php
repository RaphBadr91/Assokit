<?php
/**
 * cli-rattacher-fondateur.php — rattache les clients actuels (formule Assokit à 49,99 € HT) à l'offre
 * fondateur : 29,99 € HT/mois, fonctions actuelles gardées, prix et fonctions garantis 12 mois.
 * Ligne de commande uniquement.
 *
 *   /usr/local/bin/php cli-rattacher-fondateur.php                 liste les associations concernées (rien n'est écrit)
 *   /usr/local/bin/php cli-rattacher-fondateur.php --apply         rattache
 *   options : --email        envoie aussi à chaque association l'e-mail « Bonne nouvelle » (une fois) ;
 *             --stripe-prix  abonnement par carte trouvé : son prix passe à celui de l'offre fondateur
 *                            (sans prorata, à partir de la prochaine échéance). Sans cette option, il est
 *                            seulement signalé (Stripe n'est pas actif en production aujourd'hui) ;
 *             --avant=AAAA-MM-JJ  seulement après la transformation de la ligne assokit en Pro : ne rattache
 *                            que les abonnements commencés avant cette date (les clients de la première heure,
 *                            jamais un nouvel abonné Pro).
 *
 * Mise en service (migrations/2026-11-09-grille-tarifaire.sql) :
 *   1) lancer la migration : la ligne fondateur est créée ; assokit n'est pas transformée tant qu'un client réel y est rattaché ;
 *   2) lancer ce script sans option (vérifier la liste), puis avec --apply ;
 *   3) relancer la migration : assokit devient Pro (29,99 € HT).
 *
 * Pour chaque association (liste d'ak_fondateur_candidates, plan-helpers.php : formule courante assokit
 * en cours, ou ancien libellé rattaché à assokit ; hors démos, hors essais, hors anciens clients résiliés) :
 *   - ligne d'abonnement existante : elle est seulement RATTACHÉE à fondateur (plan_id), sans rien
 *     changer d'autre : ni son statut (un impayé reste un impayé, une résiliation reste une
 *     résiliation), ni ses échéances ;
 *   - sans ligne d'abonnement (compte historique) : ak_org_set_plan(fondateur) ;
 *   - garantie price_locked_until = maintenant + 12 mois ;
 *   - ancien montant mensuel des comptes historiques (organizations.monthly_price_cents) : 4999 → 2999
 *     (sinon le renouvellement le signale « montant à confirmer ») ;
 *   - note datée dans asso_subscriptions.notes.
 * Relancé, il ne refait rien : les associations rattachées ne sont plus dans la liste.
 *
 * Lancez-le toujours avec --email : l'e-mail d'annonce (« 49,99 € HT → 29,99 € HT, prix et
 * fonctions garantis jusqu'au … ») est la seule information écrite de l'offre fondateur
 * (cgu.php renvoie aux « conditions communiquées par e-mail »). Il n'est envoyé qu'aux
 * associations qui payaient bien 49,99 € HT, abonnement en cours, hors « Offert » ; les
 * autres sont signalées, à prévenir à la main.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/plan-helpers.php';
require_once __DIR__ . '/trial-helpers.php';
require_once __DIR__ . '/stripe-plan-change-helpers.php';
if (is_file(__DIR__ . '/includes-pricing.php')) require_once __DIR__ . '/includes-pricing.php';

$opts = getopt('', ['apply', 'email', 'stripe-prix', 'avant:', 'help']);
if (isset($opts['help'])) { echo "Usage : php cli-rattacher-fondateur.php [--apply] [--email] [--stripe-prix] [--avant=AAAA-MM-JJ]\n"; exit(0); }
$apply = isset($opts['apply']);
$avant = isset($opts['avant']) ? (string)$opts['avant'] : '';
if ($avant !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $avant) || !strtotime($avant))) { fwrite(STDERR, "--avant attend une date AAAA-MM-JJ.\n"); exit(1); }

if (!function_exists('ak_fondateur_candidates') || !function_exists('ak_plan_by_slug')) {
    fwrite(STDERR, "plan-helpers.php n'a pas les fonctions de la grille 2026 : déployez-le d'abord.\n"); exit(1);
}
$fond = ak_plan_by_slug($pdo, 'fondateur');
$assokit = ak_plan_by_slug($pdo, 'assokit');
if (!$fond || !$assokit) {
    fwrite(STDERR, "Ligne « fondateur » ou « assokit » absente : lancez d'abord php migrations/run.php 2026-11-09-grille-tarifaire.sql\n"); exit(1);
}
$scols = ak_db_columns($pdo, 'asso_subscriptions');
if (!isset($scols['price_locked_until'])) { fwrite(STDERR, "Colonne asso_subscriptions.price_locked_until absente : lancez d'abord la migration 2026-11-09-grille-tarifaire.sql\n"); exit(1); }
$transformed = !empty($assokit['grille_version']);
if ($transformed && $avant === '') {
    fwrite(STDERR, "La ligne assokit est déjà devenue Pro (grille_version renseignée) : ses abonnés peuvent être de nouveaux clients Pro.\n"
        . "Relancez avec --avant=AAAA-MM-JJ (date de mise en service de la grille) pour ne rattacher que les abonnements plus anciens.\n");
    exit(1);
}

$cands = ak_fondateur_candidates($pdo);
// Après la transformation : seulement les abonnements commencés avant --avant
if ($avant !== '') {
    $start = $pdo->prepare("SELECT COALESCE(started_at, created_at) FROM asso_subscriptions WHERE id = ?");
    $ocreated = $pdo->prepare("SELECT created_at FROM organizations WHERE id = ?");
    $cands = array_values(array_filter($cands, function ($c) use ($start, $ocreated, $avant) {
        if (!empty($c['subscription_id'])) { $start->execute([(int)$c['subscription_id']]); $d = (string)$start->fetchColumn(); }
        else { $ocreated->execute([(int)$c['org_id']]); $d = (string)$ocreated->fetchColumn(); }
        return $d !== '' && substr($d, 0, 10) < $avant;
    }));
}

$fond_label = ak_plan_label('fondateur', $fond['name'] ?? null);
$fond_price = ak_pc_price_label((int)$fond['price_cents'], 0.0);
echo "Offre fondateur : « {$fond_label} », " . html_entity_decode($fond_price) . " (formule n° " . (int)$fond['id'] . ").\n";
echo $transformed ? "Ligne assokit déjà transformée en Pro : filtre --avant={$avant}.\n" : "Ligne assokit pas encore transformée (" . ak_format_price_cents((int)$assokit['price_cents']) . " HT).\n";
if (!$cands) { echo "Aucune association à rattacher.\n"; exit(0); }

$stripe_q = $pdo->prepare("SELECT id, stripe_subscription_id, status FROM asso_subscriptions
                           WHERE org_id = ? AND COALESCE(stripe_subscription_id, '') <> '' AND status IN ('active', 'overdue')"
                           . (isset($scols['payment_mode']) ? " AND COALESCE(payment_mode, 'stripe') = 'stripe'" : "") . " ORDER BY id DESC LIMIT 1");
echo count($cands) . " association(s) " . ($apply ? "à rattacher :" : "concernée(s) (rien n'est écrit sans --apply) :") . "\n";
$ok = 0; $fail = 0; $stripe_seen = 0;
foreach ($cands as $c) {
    $oid = (int)$c['org_id'];
    $stripe_q->execute([$oid]);
    $live = $stripe_q->fetch(PDO::FETCH_ASSOC) ?: null;
    $line = sprintf("  - n° %d « %s » (%s)%s%s", $oid, $c['name'], $c['source'],
        $c['monthly_price_cents'] !== null ? ', ancien montant ' . ak_format_price_cents((int)$c['monthly_price_cents']) : '',
        $live ? ', abonnement par carte ' . $live['stripe_subscription_id'] : '');
    echo html_entity_decode($line) . "\n";
    if ($live) $stripe_seen++;
    // E-mail « 49,99 € HT → 29,99 € HT » : seulement si c'est vrai (ancien montant de 49,99 €,
    // abonnement en cours, pas « Offert »). Lu AVANT le rattachement.
    $sub_mode = ''; $sub_status = (string)($c['subscription_status'] ?? '');
    if (!empty($c['subscription_id']) && isset($scols['payment_mode'])) {
        $pm = $pdo->prepare("SELECT COALESCE(payment_mode, '') FROM asso_subscriptions WHERE id = ?");
        $pm->execute([(int)$c['subscription_id']]);
        $sub_mode = (string)$pm->fetchColumn();
    }
    $old_cents = ($c['monthly_price_cents'] !== null && (int)$c['monthly_price_cents'] > 0)
        ? (int)$c['monthly_price_cents'] : (!empty($c['subscription_id']) ? (int)$assokit['price_cents'] : 0);
    $mail_ok = $old_cents === 4999 && $sub_mode !== 'free_grant'
        && ($sub_status === '' || in_array($sub_status, ['active', 'overdue', 'past_due', 'pending_payment'], true));
    if (!$apply) continue;
    try {
        if (!empty($c['subscription_id'])) {
            // Rattachement seul : la ligne garde son statut, ses échéances et sa date de résiliation.
            if (function_exists('ak_org_review_guard')) ak_org_review_guard($pdo, $oid);
            $sets = (isset($scols['previous_plan_id']) ? 'previous_plan_id = plan_id, ' : '') . 'plan_id = ?'
                  . (isset($scols['updated_at']) ? ', updated_at = NOW()' : '');
            $u = $pdo->prepare("UPDATE asso_subscriptions SET $sets WHERE id = ? AND plan_id = ?");
            $u->execute([(int)$fond['id'], (int)$c['subscription_id'], (int)$assokit['id']]);
            if ($u->rowCount() !== 1) throw new RuntimeException('la ligne d’abonnement a changé entre-temps');
            if (ak_db_accepts($pdo, 'organizations', 'plan', 'fondateur')) {
                $pdo->prepare("UPDATE organizations SET plan = 'fondateur' WHERE id = ?")->execute([$oid]);
            }
        } else {
            ak_org_set_plan($pdo, $oid, (int)$fond['id']);
        }
        $row = $pdo->prepare("SELECT s.id FROM asso_subscriptions s JOIN asso_plans p ON p.id = s.plan_id WHERE s.org_id = ? ORDER BY " . ak_sub_order('s') . " LIMIT 1");
        $row->execute([$oid]);
        $sid = (int)$row->fetchColumn();
        if ($sid <= 0) throw new RuntimeException('ligne d’abonnement introuvable après le rattachement');
        $until = date('Y-m-d 23:59:59', strtotime('+12 months'));
        $pdo->prepare("UPDATE asso_subscriptions SET price_locked_until = ? WHERE id = ? AND plan_id = ?")->execute([$until, $sid, (int)$fond['id']]);
        if (isset($scols['notes'])) {
            $note = '|fondateur:' . date('Y-m-d') . ' rattaché à l’offre fondateur (' . number_format((int)$fond['price_cents'] / 100, 2, ',', ' ') . ' € HT), garanti jusqu’au ' . date('d/m/Y', strtotime($until)) . '|';
            $pdo->prepare("UPDATE asso_subscriptions SET notes = CONCAT(COALESCE(notes, ''), ?) WHERE id = ?")->execute([$note, $sid]);
        }
        $pdo->prepare("UPDATE organizations SET monthly_price_cents = ? WHERE id = ? AND COALESCE(monthly_price_cents, 0) > 0")->execute([(int)$fond['price_cents'], $oid]);
        echo "      ✓ rattachée, garantie jusqu’au " . date('d/m/Y', strtotime($until)) . "\n";
        if ($live) {
            if (isset($opts['stripe-prix'])) {
                if (!ak_stripe_is_configured($pdo)) {
                    echo "      ⚠ Stripe n’est pas configuré : prix de l’abonnement par carte non modifié.\n";
                } else {
                    $r = ak_pc_stripe_set_price($pdo, (string)$live['stripe_subscription_id'], $fond, 'none');
                    echo $r['ok'] ? "      ✓ abonnement par carte : prix de l’offre fondateur à partir de la prochaine échéance\n"
                                  : "      ⚠ abonnement par carte NON modifié : " . $r['error'] . "\n";
                }
            } else {
                echo "      ⚠ abonnement par carte " . $live['stripe_subscription_id'] . " : non modifié (relancez avec --stripe-prix une fois Stripe configuré, ou changez le prix dans Stripe)\n";
            }
        }
        if (isset($opts['email']) && !$mail_ok) {
            echo "      ⚠ e-mail d’annonce NON envoyé : " . ($sub_mode === 'free_grant' ? 'abonnement « Offert »'
                 : ($old_cents !== 4999 ? 'ancien montant ' . html_entity_decode(ak_format_price_cents($old_cents)) . ' (pas 49,99 €)' : 'abonnement « ' . $sub_status . ' »'))
                 . " : prévenez cette association à la main.\n";
        } elseif (isset($opts['email'])) {
            $until_fr = date('d/m/Y', strtotime($until));
            $vat = function_exists('ak_vat_note') ? ak_vat_note($pdo) : 'TVA non applicable (art. 293 B du CGI) : le prix HT est le prix payé.';
            $sent = null;
            ak_stripe_once($pdo, $oid, 'fondateur-annonce', function () use ($pdo, $oid, $until_fr, $vat, &$sent) {
                return $sent = ak_stripe_notify_org($pdo, $oid, 'Bonne nouvelle : votre abonnement Assokit baisse',
                    '<p>Bonjour,</p><p>Bonne nouvelle : votre abonnement passe de 49,99 € HT à 29,99 € HT par mois dès votre prochaine échéance. '
                    . 'Prix et fonctions garantis jusqu’au ' . ak_stripe_h($until_fr) . '. Avant cette date, nous vous écrirons au moins 30 jours à l’avance, '
                    . 'et vous pourrez résilier sans frais. Vous n’avez rien à faire.</p>'
                    . '<p>' . ak_stripe_h($vat) . '</p>',
                    'Voir mon abonnement', 'https://assokit.fr/mon-asso-plan');
            });
            echo $sent === null ? "      – e-mail d’annonce déjà envoyé\n" : ($sent ? "      ✓ e-mail d’annonce envoyé\n" : "      ⚠ e-mail d’annonce NON envoyé (configuration des e-mails) : il sera retenté au prochain lancement avec --email\n");
        }
        $ok++;
    } catch (Throwable $e) {
        $fail++;
        echo "      ✕ non rattachée : " . $e->getMessage() . "\n";
    }
}
if (!$apply) {
    echo "\nRelancez avec --apply pour rattacher ces associations" . ($stripe_seen ? " ({$stripe_seen} abonnement(s) par carte : signalés, non modifiés sans --stripe-prix)" : '') . ".\n";
    exit(0);
}
echo "\n{$ok} rattachée(s), {$fail} échec(s)." . (!$transformed && $fail === 0 ? " Relancez maintenant la migration : assokit deviendra Pro." : '') . "\n";
exit($fail ? 1 : 0);
