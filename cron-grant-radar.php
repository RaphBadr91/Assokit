<?php
/**
 * AssoKit — CRON Radar de subventions (alertes)
 * À lancer 1x par jour (matin).
 *   /usr/bin/php /home/pura7044/public_html/cron-grant-radar.php
 *
 * Pour chaque org qui utilise le radar :
 *   1. recalcule les correspondances (nouveaux dispositifs, deadlines à jour) ;
 *   2. alerte sur les NOUVELLES pistes pertinentes (score >= seuil) ;
 *   3. alerte sur les ÉCHÉANCES J-30 et J-7 des dispositifs éligibles/probables.
 *
 * Canaux : email (admins de l'org) + notification in-app.
 * Dé-doublonnage via grant_alert_sent (org, catalog, type).
 * Respecte grant_alert_prefs (seuil, on/off, canaux).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/financements-engine.php';
@require_once __DIR__ . '/notification-helpers.php';
@require_once __DIR__ . '/resend-helper.php';

// CLI true si SAPI cli OU exécution hors contexte web (binaire CGI lancé en cron :
// aucune requête HTTP -> REQUEST_METHOD absent).
$is_cli = (PHP_SAPI === 'cli') || !isset($_SERVER['REQUEST_METHOD']);
$has_key = isset($_GET['key']) && defined('CRON_SECRET') && hash_equals(CRON_SECRET, $_GET['key']);
if (!$is_cli && !$has_key) { http_response_code(403); die('Forbidden'); }

$started = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] CRON Radar subventions démarré\n";

/** Prefs par défaut si l'org n'a rien configuré. */
function radar_prefs(PDO $pdo, int $org_id): array {
    $def = ['notify_new_match'=>1, 'min_match_score'=>60, 'notify_deadlines'=>1, 'channel_email'=>1, 'channel_app'=>1];
    try {
        $st = $pdo->prepare("SELECT * FROM grant_alert_prefs WHERE org_id = ?");
        $st->execute([$org_id]);
        if ($p = $st->fetch()) {
            foreach ($def as $k => $v) if (isset($p[$k]) && $p[$k] !== null) $def[$k] = (int)$p[$k];
        }
    } catch (Throwable $e) {}
    return $def;
}

/** Admins destinataires (email + user_id pour la notif in-app). */
function radar_admins(PDO $pdo, int $org_id): array {
    try {
        $st = $pdo->prepare("SELECT id, email, first_name FROM users WHERE org_id = ? AND role = 'admin' AND is_active = 1");
        $st->execute([$org_id]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Déjà alerté ? */
function radar_already_sent(PDO $pdo, int $org_id, int $catalog_id, string $type): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM grant_alert_sent WHERE org_id=? AND catalog_id=? AND alert_type=?");
        $st->execute([$org_id, $catalog_id, $type]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return true; /* fail-safe : ne pas spammer */ }
}
function radar_mark_sent(PDO $pdo, int $org_id, int $catalog_id, string $type): void {
    try {
        $pdo->prepare("INSERT IGNORE INTO grant_alert_sent (org_id, catalog_id, alert_type) VALUES (?,?,?)")
            ->execute([$org_id, $catalog_id, $type]);
    } catch (Throwable $e) {}
}

function radar_send_email(array $admins, string $subject, string $html, string $text): bool {
    $ok = false;
    foreach ($admins as $a) {
        if (!filter_var($a['email'] ?? '', FILTER_VALIDATE_EMAIL)) continue;
        try {
            if (function_exists('send_email_resend')) { send_email_resend($a['email'], $subject, $html, $text); $ok = true; }
            else {
                $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: AssoKit <noreply@assokit.fr>\r\n";
                if (@mail($a['email'], $subject, $html, $headers)) $ok = true;
            }
        } catch (Throwable $e) {}
    }
    return $ok;
}

function radar_email_shell(string $title, string $intro, string $itemsHtml): array {
    $url = "https://assokit.fr/financements";
    $html = '<div style="font-family:-apple-system,sans-serif;max-width:560px;margin:0 auto;padding:24px;background:#f9fafb;">'
        . '<div style="background:#fff;border-radius:14px;padding:28px;border:1px solid #e5e7eb;">'
        . '<div style="display:inline-block;background:#10B981;color:#fff;font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:14px;">🎯 Radar de subventions</div>'
        . '<h1 style="font-size:20px;margin:0 0 8px;color:#111827;">'.htmlspecialchars($title).'</h1>'
        . '<p style="color:#4b5563;font-size:14px;line-height:1.55;margin:0 0 18px;">'.htmlspecialchars($intro).'</p>'
        . $itemsHtml
        . '<a href="'.$url.'" style="display:inline-block;background:#10B981;color:#fff;padding:11px 22px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;margin-top:8px;">Ouvrir le radar →</a>'
        . '</div><p style="text-align:center;color:#6b7280;font-size:11px;margin:18px 0 0;">AssoKit · vous recevez cette alerte car le radar de subventions est actif. Réglages : /financements</p></div>';
    return [$html];
}

// ── Orgs qui utilisent le radar (profil OU correspondances déjà calculées) ──
$orgIds = [];
try {
    foreach ($pdo->query("SELECT DISTINCT org_id FROM org_grant_profile")->fetchAll(PDO::FETCH_COLUMN) as $o) $orgIds[(int)$o] = true;
    foreach ($pdo->query("SELECT DISTINCT org_id FROM grant_matches")->fetchAll(PDO::FETCH_COLUMN) as $o) $orgIds[(int)$o] = true;
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); }
$orgIds = array_keys($orgIds);
echo "→ " . count($orgIds) . " org(s) à scanner\n";

$today = date('Y-m-d');
$sent_new = 0; $sent_dl = 0;

foreach ($orgIds as $org_id) {
    // 1) rafraîchir les correspondances
    try { fin_compute_matches($pdo, $org_id); } catch (Throwable $e) { continue; }

    $prefs = radar_prefs($pdo, $org_id);
    $admins = radar_admins($pdo, $org_id);
    if (empty($admins)) continue;

    // 2) NOUVELLES pistes pertinentes
    if (!empty($prefs['notify_new_match'])) {
        $st = $pdo->prepare(
            "SELECT m.catalog_id, m.score, m.eligibility, c.title, c.funder_name, c.amount_max
             FROM grant_matches m JOIN grant_catalog c ON c.id = m.catalog_id
             WHERE m.org_id = ? AND m.dismissed = 0
               AND m.eligibility IN ('eligible','probable') AND m.score >= ?
             ORDER BY m.score DESC");
        $st->execute([$org_id, (int)$prefs['min_match_score']]);
        $fresh = [];
        foreach ($st->fetchAll() as $row) {
            if (radar_already_sent($pdo, $org_id, (int)$row['catalog_id'], 'new_match')) continue;
            $fresh[] = $row;
        }
        if ($fresh) {
            $items = '';
            foreach ($fresh as $r) {
                $amt = $r['amount_max'] ? ' · jusqu\'à '.number_format((float)$r['amount_max'],0,',',' ').' €' : '';
                $items .= '<div style="border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px;margin-bottom:10px;">'
                    . '<div style="font-weight:700;color:#111827;font-size:14px;">'.htmlspecialchars($r['title']).'</div>'
                    . '<div style="color:#6b7280;font-size:12.5px;margin-top:2px;">'.htmlspecialchars($r['funder_name']).' · '.(int)$r['score'].'%'.$amt.'</div></div>';
            }
            $n = count($fresh);
            $subject = "[AssoKit] $n nouvelle".($n>1?'s':'')." piste".($n>1?'s':'')." de subvention";
            $intro = "Le radar a détecté $n dispositif".($n>1?'s':'')." de financement adapté".($n>1?'s':'')." à votre structure. À examiner pendant qu'".($n>1?'ils sont':'il est')." ouvert".($n>1?'s':'').".";
            list($html) = radar_email_shell("Nouvelles pistes détectées", $intro, $items);
            $text = $intro . "\n\n" . implode("\n", array_map(fn($r)=>"• {$r['title']} ({$r['funder_name']}, {$r['score']}%)", $fresh)) . "\n\nhttps://assokit.fr/financements";

            $delivered = false;
            if (!empty($prefs['channel_email'])) $delivered = radar_send_email($admins, $subject, $html, $text) || $delivered;
            if (!empty($prefs['channel_app']) && function_exists('ak_notif_create')) {
                foreach ($admins as $a) {
                    ak_notif_create($pdo, (int)$a['id'], 'grant_radar', "🎯 $n nouvelle".($n>1?'s':'')." piste".($n>1?'s':'')." de subvention", "Le radar a trouvé des financements adaptés à votre structure.", "/financements");
                }
                $delivered = true;
            }
            if ($delivered) {
                foreach ($fresh as $r) radar_mark_sent($pdo, $org_id, (int)$r['catalog_id'], 'new_match');
                $sent_new += $n;
                echo "  ✓ org #$org_id : $n nouvelle(s) piste(s)\n";
            }
        }
    }

    // 3) ÉCHÉANCES J-30 / J-7
    if (!empty($prefs['notify_deadlines'])) {
        $st = $pdo->prepare(
            "SELECT m.catalog_id, m.score, c.title, c.funder_name, c.deadline_apply, c.apply_url
             FROM grant_matches m JOIN grant_catalog c ON c.id = m.catalog_id
             WHERE m.org_id = ? AND m.dismissed = 0
               AND m.eligibility IN ('eligible','probable')
               AND c.deadline_apply IS NOT NULL AND c.deadline_apply >= ?");
        $st->execute([$org_id, $today]);
        foreach ($st->fetchAll() as $r) {
            $diff = (int)((strtotime($r['deadline_apply']) - strtotime($today)) / 86400);
            $type = $diff === 30 ? 'deadline_30' : ($diff === 7 ? 'deadline_7' : null);
            if ($type === null) continue;
            if (radar_already_sent($pdo, $org_id, (int)$r['catalog_id'], $type)) continue;

            $label = $diff === 7 ? 'J-7' : 'J-30';
            $color = $diff === 7 ? '#F59E0B' : '#3B82F6';
            $dateFr = date('d/m/Y', strtotime($r['deadline_apply']));
            $subject = "[AssoKit] Subvention $label · {$r['title']}";
            $items = '<div style="border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px;margin-bottom:14px;">'
                . '<div style="display:inline-block;background:'.$color.';color:#fff;font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;margin-bottom:8px;">'.$label.' · clôture le '.$dateFr.'</div>'
                . '<div style="font-weight:700;color:#111827;font-size:14px;">'.htmlspecialchars($r['title']).'</div>'
                . '<div style="color:#6b7280;font-size:12.5px;margin-top:2px;">'.htmlspecialchars($r['funder_name']).' · '.(int)$r['score'].'%</div></div>';
            $intro = "Une piste éligible pour votre structure arrive à échéance ($label). Préparez votre dossier sans tarder.";
            list($html) = radar_email_shell("Échéance $label : {$r['title']}", $intro, $items);
            $text = $intro . "\n\n{$r['title']} ({$r['funder_name']}) — clôture le $dateFr\n\nhttps://assokit.fr/financements";

            $delivered = false;
            if (!empty($prefs['channel_email'])) $delivered = radar_send_email($admins, $subject, $html, $text) || $delivered;
            if (!empty($prefs['channel_app']) && function_exists('ak_notif_create')) {
                foreach ($admins as $a) {
                    ak_notif_create($pdo, (int)$a['id'], 'grant_radar', "⏰ Subvention $label : {$r['title']}", "Clôture le $dateFr. Préparez votre dossier.", "/financements");
                }
                $delivered = true;
            }
            if ($delivered) {
                radar_mark_sent($pdo, $org_id, (int)$r['catalog_id'], $type);
                $sent_dl++;
                echo "  ✓ org #$org_id : échéance $label « {$r['title']} »\n";
            }
        }
    }
}

$elapsed = round(microtime(true) - $started, 2);
echo "\n[" . date('Y-m-d H:i:s') . "] Terminé en {$elapsed}s · $sent_new piste(s) neuve(s), $sent_dl échéance(s)\n";
exit(0);
