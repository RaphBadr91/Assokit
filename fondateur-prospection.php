<?php
/**
 * fondateur-prospection.php — CRM de prospection / emailing rentrée (Fondateur web).
 * Table complète : contact, association, email, statut, étape, dates, relance, suivi.
 * Actions : importer, envoyer maintenant, planifier une relance, marquer (répondu/RDV/désinscrit),
 *           supprimer, mettre toute la file en séquence.
 * Envoi personnalisé rentrée via ak_prospect_build_email(). Réservé Fondateur.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
require_once __DIR__ . '/api/_app-prospect.php';
require_once __DIR__ . '/api/_app-directory.php';
@require_once __DIR__ . '/resend-helper.php';

require_login();
$user = sa_require_super_admin();
$ctx = sa_get_permissions_context();
if (empty($ctx['is_founder'])) {
    http_response_code(403);
    sa_render_head('Accès refusé'); sa_render_sidebar('fondateur-pilotage');
    echo '<div style="max-width:560px;margin:50px auto;text-align:center;padding:34px;background:var(--sa-bg-2);border:1px solid var(--sa-border);border-radius:16px;"><div style="font-size:44px;">🏗️</div><h1>Réservé au Fondateur</h1><a href="/super-admin" class="sa-btn sa-btn-ghost" style="margin-top:16px;">← Retour</a></div>';
    sa_render_foot(); exit;
}

ak_prospect_tables_ensure($pdo);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];
$seq = ak_prospect_sequence();
$LAST_STEP = max(array_keys($seq));
$flash = $_SESSION['flash_prospection'] ?? null;
unset($_SESSION['flash_prospection']);

// ============================ ACTIONS (POST) ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = !empty($_POST['csrf']) && hash_equals($CSRF, (string) $_POST['csrf']);
    $action = (string) ($_POST['action'] ?? '');
    $msg = null; $tone = 'ok';
    if (!$ok) { $msg = 'Session expirée, réessayez.'; $tone = 'err'; }
    else try {
        if ($action === 'import') {
            $type = (($_POST['type'] ?? 'asso') === 'tpe') ? 'tpe' : 'asso';
            // Source 1 : texte collé. Source 2 : fichier CSV/TXT uploadé.
            $raw = (string) ($_POST['bulk'] ?? '');
            $upErr = (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($upErr === UPLOAD_ERR_INI_SIZE || $upErr === UPLOAD_ERR_FORM_SIZE) {
                throw new RuntimeException('Fichier trop volumineux pour le serveur. Découpez-le, ou collez les lignes dans la zone de texte.');
            }
            if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                $content = (string) @file_get_contents($_FILES['file']['tmp_name']);
                // UTF-8 : retire un éventuel BOM et convertit depuis ISO-8859-1 si besoin
                $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
                if (!mb_check_encoding($content, 'UTF-8')) $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
                $raw .= "\n" . $content;
            }
            $lines = preg_split('/[\r\n]+/', $raw);
            $added = 0; $skipped = 0;
            $ins = $pdo->prepare("INSERT INTO asso_prospects (name, org_name, type, email, city, source, status, created_at)
                                  VALUES (?, ?, ?, ?, ?, 'web_import', 'new', NOW()) ON DUPLICATE KEY UPDATE id = id");
            foreach ($lines as $line) {
                $line = trim($line); if ($line === '') continue;
                // Split tolérant (; , tabulation) + retrait des guillemets CSV
                $cells = array_map(fn($c) => trim(trim($c), "\"'"), preg_split('/[;,\t]/', $line));
                // Trouve l'email où qu'il soit dans la ligne (pas forcément en 1re colonne)
                $emailIdx = -1;
                foreach ($cells as $i => $c) { if (filter_var(strtolower($c), FILTER_VALIDATE_EMAIL)) { $emailIdx = $i; break; } }
                if ($emailIdx < 0) { $skipped++; continue; }   // ligne d'en-tête ou sans email → ignorée
                $email = strtolower($cells[$emailIdx]);
                // Les autres cellules non vides (dans l'ordre) → nom, association, ville
                $rest = [];
                foreach ($cells as $i => $c) { if ($i !== $emailIdx && $c !== '') $rest[] = $c; }
                $ins->execute([($rest[0] ?? '') ?: null, ($rest[1] ?? '') ?: null, $type, $email, ($rest[2] ?? '') ?: null]);
                if ($ins->rowCount() > 0) $added++; else $skipped++;
            }
            $msg = "Import terminé : $added ajouté(s), $skipped ignoré(s) (doublons, en-têtes ou lignes sans email).";
        } elseif ($action === 'send') {
            $id = (int) ($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM asso_prospects WHERE id = ? LIMIT 1"); $st->execute([$id]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) { $msg = 'Prospect introuvable.'; $tone = 'err'; }
            elseif (in_array($p['status'], ['unsubscribed', 'replied', 'booked'], true)) { $msg = 'Ce contact ne doit plus être relancé (désinscrit / a répondu).'; $tone = 'err'; }
            elseif (!function_exists('send_transactional_email')) { $msg = 'Service d\'envoi indisponible (Resend non configuré).'; $tone = 'err'; }
            else {
                $step = (int) $p['step'];
                $mail = ak_prospect_build_email($p, $step, $seq);
                send_transactional_email((string) $p['email'], $mail['subject'], $mail['html'], [
                    'tag' => 'prospect_web_' . $step,
                    'from_email' => AK_PROSPECT_FROM, 'from_name' => AK_PROSPECT_FROM_NAME,
                    'reply_to' => AK_PROSPECT_REPLY_TO,
                ]);
                ak_prospect_event($pdo, $id, 'sent', $step, $mail['subject']);
                $nextStep = $step + 1;
                if ($nextStep > $LAST_STEP) {
                    $pdo->prepare("UPDATE asso_prospects SET status='contacted', step=?, last_sent_at=NOW(), next_send_at=NULL WHERE id=?")->execute([$step, $id]);
                } else {
                    $delay = (int) ($seq[$step]['delay'] ?? 18);
                    $pdo->prepare("UPDATE asso_prospects SET status='contacted', step=?, last_sent_at=NOW(), next_send_at=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=?")->execute([$nextStep, $delay, $id]);
                }
                $msg = 'Email envoyé à ' . htmlspecialchars((string) $p['email']) . '.';
            }
        } elseif ($action === 'relance') {
            $id = (int) ($_POST['id'] ?? 0);
            $date = trim((string) ($_POST['date'] ?? ''));
            $dt = $date !== '' ? date('Y-m-d 09:00:00', strtotime($date)) : null;
            $pdo->prepare("UPDATE asso_prospects SET next_send_at = ?, status = IF(status IN ('new'),'queued',status) WHERE id = ?")->execute([$dt, $id]);
            $msg = $dt ? 'Relance planifiée le ' . date('d/m/Y', strtotime($dt)) . '.' : 'Relance annulée.';
        } elseif ($action === 'status') {
            $id = (int) ($_POST['id'] ?? 0);
            $val = (string) ($_POST['value'] ?? '');
            if (in_array($val, ['new', 'replied', 'booked', 'unsubscribed'], true)) {
                $pdo->prepare("UPDATE asso_prospects SET status = ?, next_send_at = NULL WHERE id = ?")->execute([$val, $id]);
                ak_prospect_event($pdo, $id, $val === 'booked' ? 'booked' : ($val === 'replied' ? 'reply' : 'status'), null, 'web');
                $msg = 'Statut mis à jour.';
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM asso_prospects WHERE id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM asso_prospect_events WHERE prospect_id = ?")->execute([$id]);
            $msg = 'Prospect supprimé.';
        } elseif ($action === 'queue_all') {
            $n = $pdo->exec("UPDATE asso_prospects SET status='queued', step=0, next_send_at=NOW() WHERE status='new'");
            $msg = ($n ?: 0) . ' prospect(s) mis en séquence automatique.';
        }
    } catch (Throwable $e) { $msg = 'Erreur : ' . $e->getMessage(); $tone = 'err'; }
    $_SESSION['flash_prospection'] = ['msg' => $msg, 'tone' => $tone];
    header('Location: /fondateur-prospection'); exit;
}

// ============================ DONNÉES ============================
$STATUS_META = [
    'new'          => ['Nouveau', '#94A3B8', 'rgba(148,163,184,.15)'],
    'queued'       => ['En file', '#F59E0B', 'rgba(245,158,11,.15)'],
    'contacted'    => ['Contacté', '#3B82F6', 'rgba(59,130,246,.15)'],
    'engaged'      => ['Engagé', '#8B5CF6', 'rgba(139,92,246,.15)'],
    'replied'      => ['A répondu', '#10B981', 'rgba(16,185,129,.15)'],
    'booked'       => ['RDV pris', '#10B981', 'rgba(16,185,129,.2)'],
    'unsubscribed' => ['Désinscrit', '#64748B', 'rgba(100,116,139,.15)'],
    'bounced'      => ['Rejeté', '#EF4444', 'rgba(239,68,68,.15)'],
];
$filter = (string) ($_GET['statut'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
$where = '1=1'; $args = [];
if (isset($STATUS_META[$filter])) { $where .= ' AND status = ?'; $args[] = $filter; }
if ($search !== '') { $where .= ' AND (org_name LIKE ? OR name LIKE ? OR email LIKE ? OR city LIKE ?)'; $like = '%' . $search . '%'; array_push($args, $like, $like, $like, $like); }

$rows = [];
try {
    $st = $pdo->prepare("SELECT * FROM asso_prospects WHERE $where ORDER BY
        FIELD(status,'replied','booked','engaged','contacted','queued','new','unsubscribed','bounced'), created_at DESC LIMIT 500");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Suivi (événements) agrégés par prospect
$ev = [];
try {
    foreach ($pdo->query("SELECT prospect_id, type, COUNT(*) n FROM asso_prospect_events GROUP BY prospect_id, type")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ev[(int) $r['prospect_id']][(string) $r['type']] = (int) $r['n'];
    }
} catch (Throwable $e) {}

// Stats globales
$by = array_fill_keys(array_keys($STATUS_META), 0);
try { foreach ($pdo->query("SELECT status, COUNT(*) n FROM asso_prospects GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($by[$r['status']])) $by[$r['status']] = (int) $r['n']; } catch (Throwable $e) {}
$total = array_sum($by);
$sentToday = 0;
try { $sentToday = (int) $pdo->query("SELECT COUNT(*) FROM asso_prospect_events WHERE type='sent' AND created_at >= CURDATE()")->fetchColumn(); } catch (Throwable $e) {}
$evTot = ['sent' => 0, 'click' => 0, 'reply' => 0];
try { foreach ($pdo->query("SELECT type, COUNT(*) n FROM asso_prospect_events GROUP BY type")->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($evTot[$r['type']])) $evTot[$r['type']] = (int) $r['n']; } catch (Throwable $e) {}
$sendingOn = defined('AK_PROSPECT_SENDING_ENABLED') && AK_PROSPECT_SENDING_ENABLED;
$cap = defined('AK_PROSPECT_DAILY_CAP') ? AK_PROSPECT_DAILY_CAP : 40;

$CAT_LABELS = [];
foreach (ak_directory_categories() as $k => $v) $CAT_LABELS[$k] = $v['label'];
function pr_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function pr_date($d) { return $d ? date('d/m/Y', strtotime($d)) : '—'; }
function pr_ds($d) { return $d ? date('d/m/y', strtotime($d)) : '—'; }

sa_render_head('Prospection — Emailing rentrée');
sa_render_sidebar('fondateur-pilotage');
?>
<style>
.pr-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:22px;}
.pr-title{font-size:26px;font-weight:800;letter-spacing:-.03em;margin:0;display:flex;align-items:center;gap:11px;}
.pr-seal{font-size:11px;font-weight:800;letter-spacing:.05em;padding:5px 11px;border-radius:999px;background:linear-gradient(135deg,#FCD34D,#F59E0B);color:#3A2A08;}
.pr-sub{color:var(--sa-ink-3);font-size:13px;margin-top:7px;}
.pr-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px;}
.pr-kpi{background:var(--sa-bg-2);border:1px solid var(--sa-border);border-radius:14px;padding:15px 16px;}
.pr-kpi-v{font-size:24px;font-weight:800;letter-spacing:-.02em;}
.pr-kpi-l{font-size:11.5px;color:var(--sa-ink-3);margin-top:3px;text-transform:uppercase;letter-spacing:.04em;font-weight:600;}
.pr-panel{background:var(--sa-bg-2);border:1px solid var(--sa-border);border-radius:16px;padding:18px;margin-bottom:20px;}
.pr-panel h3{margin:0 0 12px;font-size:15px;font-weight:700;}
.pr-note{font-size:12px;border-radius:10px;padding:10px 13px;margin-bottom:16px;display:flex;gap:9px;align-items:flex-start;line-height:1.5;}
.pr-note.warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:#FBBF24;}
.pr-note.ok{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);color:#34D399;}
.pr-note.err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#F87171;}
.pr-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px;}
.pr-tabs{display:flex;gap:6px;flex-wrap:wrap;}
.pr-tab{font-size:12.5px;padding:6px 12px;border-radius:999px;border:1px solid var(--sa-border);background:var(--sa-bg-3);color:var(--sa-ink-2);text-decoration:none;font-weight:600;}
.pr-tab.active{background:var(--sa-ink);color:var(--sa-bg);border-color:var(--sa-ink);}
.pr-search{margin-left:auto;display:flex;gap:8px;}
.pr-search input{background:var(--sa-bg-3);border:1px solid var(--sa-border);border-radius:10px;padding:8px 12px;color:var(--sa-ink);font-size:13px;min-width:200px;}
.pr-tablewrap{overflow-x:auto;border:1px solid var(--sa-border);border-radius:14px;background:var(--sa-bg-2);}
table.pr-table{width:100%;border-collapse:collapse;font-size:12.5px;min-width:1080px;table-layout:fixed;}
.pr-table th{text-align:left;padding:10px 11px;font-size:10.5px;text-transform:uppercase;letter-spacing:.03em;color:var(--sa-ink-4);font-weight:700;border-bottom:1px solid var(--sa-border);white-space:nowrap;}
.pr-table td{padding:9px 11px;border-bottom:1px solid var(--sa-border);vertical-align:middle;overflow:hidden;text-overflow:ellipsis;}
.pr-table tr:last-child td{border-bottom:none;}
/* Largeurs de colonnes (table-layout:fixed) */
.pr-c-asso{width:19%;} .pr-c-mail{width:15%;} .pr-c-ville{width:9%;} .pr-c-dept{width:12%;}
.pr-c-stat{width:8%;} .pr-c-etape{width:5%;} .pr-c-dates{width:10%;} .pr-c-suivi{width:7%;} .pr-c-act{width:12%;}
.pr-org{font-weight:700;color:var(--sa-ink);font-size:12.5px;line-height:1.25;}
.pr-meta{font-size:11px;color:var(--sa-ink-3);margin-top:2px;}
.pr-badge{display:inline-block;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:999px;white-space:nowrap;}
.pr-dates{font-size:11px;line-height:1.5;color:var(--sa-ink-2);}
.pr-dates .l{display:inline-block;width:52px;color:var(--sa-ink-4);}
.pr-track{display:flex;gap:8px;font-size:11px;color:var(--sa-ink-3);}
.pr-track b{color:var(--sa-ink);}
.pr-actions{display:flex;gap:4px;flex-wrap:wrap;align-items:center;}
.pr-ic{border:1px solid var(--sa-border);background:var(--sa-bg-3);color:var(--sa-ink-2);border-radius:7px;padding:5px 7px;font-size:12px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;gap:4px;line-height:1;}
.pr-ic:hover{border-color:var(--sa-ink-3);}
.pr-ic.send{background:#059669;border-color:#059669;color:#fff;padding:6px 9px;}
.pr-ic.danger:hover{border-color:#EF4444;color:#F87171;}
details.pr-rel{display:inline-block;position:relative;}
details.pr-rel>summary{list-style:none;cursor:pointer;}
details.pr-rel>summary::-webkit-details-marker{display:none;}
details.pr-rel .pop{position:absolute;z-index:20;top:calc(100% + 5px);right:0;background:var(--sa-bg-2);border:1px solid var(--sa-border);border-radius:10px;padding:9px;display:flex;gap:5px;box-shadow:0 12px 30px -8px rgba(0,0,0,.7);}
textarea.pr-bulk{width:100%;min-height:110px;background:var(--sa-bg-3);border:1px solid var(--sa-border);border-radius:10px;padding:11px 13px;color:var(--sa-ink);font-size:13px;font-family:ui-monospace,monospace;resize:vertical;}
.pr-empty{text-align:center;padding:44px;color:var(--sa-ink-3);}
select.pr-mini,input.pr-mini{background:var(--sa-bg-3);border:1px solid var(--sa-border);border-radius:8px;padding:5px 8px;color:var(--sa-ink);font-size:12px;}
</style>

<div class="pr-head">
  <div>
    <h1 class="pr-title">🚀 Prospection <span class="pr-seal">EMAILING RENTRÉE</span></h1>
    <div class="pr-sub">Trouvez de nouveaux clients pour la rentrée — emailing personnalisé, relances et suivi complet.</div>
  </div>
  <a href="/fondateur-pilotage" class="sa-btn sa-btn-ghost sa-btn-sm">← Pilotage</a>
</div>

<?php if ($flash && !empty($flash['msg'])): ?>
  <div class="pr-note <?= $flash['tone'] === 'err' ? 'err' : 'ok' ?>"><span>●</span><div><?= $flash['msg'] ?></div></div>
<?php endif; ?>

<?php if (!$sendingOn): ?>
  <div class="pr-note warn"><span>⚠️</span><div><strong>Mode sécurité activé.</strong> L'envoi automatique en masse (séquence CRON) est <strong>désactivé</strong> tant qu'un domaine d'envoi dédié + warm-up ne sont pas en place. Le bouton « Envoyer » ci-dessous envoie quand même à l'unité (envoi manuel). Pour activer l'automatisation, définissez <code>AK_PROSPECT_SENDING_ENABLED = true</code>.</div></div>
<?php endif; ?>

<div class="pr-kpis">
  <div class="pr-kpi"><div class="pr-kpi-v"><?= number_format($total, 0, ',', ' ') ?></div><div class="pr-kpi-l">Prospects</div></div>
  <div class="pr-kpi"><div class="pr-kpi-v" style="color:#3B82F6;"><?= number_format($evTot['sent'], 0, ',', ' ') ?></div><div class="pr-kpi-l">Emails envoyés</div></div>
  <div class="pr-kpi"><div class="pr-kpi-v" style="color:#A78BFA;"><?= number_format($evTot['click'], 0, ',', ' ') ?></div><div class="pr-kpi-l">Clics</div></div>
  <div class="pr-kpi"><div class="pr-kpi-v" style="color:#34D399;"><?= number_format($by['replied'] + $by['booked'], 0, ',', ' ') ?></div><div class="pr-kpi-l">Réponses / RDV</div></div>
  <div class="pr-kpi"><div class="pr-kpi-v"><?= $sentToday ?> <span style="font-size:14px;color:var(--sa-ink-4);">/ <?= $cap ?></span></div><div class="pr-kpi-l">Envoyés aujourd'hui</div></div>
</div>

<div class="pr-panel">
  <h3>📥 Importer des contacts</h3>
  <div class="pr-note warn" style="margin-bottom:12px;"><span>🛡️</span><div>N'importez que des adresses <strong>professionnelles publiées</strong> (contact@ de la structure) que vous avez le droit de démarcher (intérêt légitime B2B). Chaque email contient un lien de désinscription. Jamais d'adresses personnelles achetées ou scrapées.</div></div>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= pr_h($CSRF) ?>">
    <input type="hidden" name="action" value="import">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:11px;padding:12px;border:1px dashed var(--sa-border);border-radius:10px;background:var(--sa-bg-3);">
      <span style="font-size:20px;">📄</span>
      <div style="flex:1;min-width:180px;">
        <div style="font-weight:700;font-size:13px;">Importer un fichier CSV</div>
        <div style="font-size:11.5px;color:var(--sa-ink-3);">Colonnes : email · nom · association · ville (Excel : « Enregistrer sous → CSV »)</div>
      </div>
      <input type="file" name="file" accept=".csv,.txt" style="font-size:12px;color:var(--sa-ink-2);">
    </div>
    <div style="text-align:center;font-size:11px;color:var(--sa-ink-4);margin-bottom:9px;">— OU colle les contacts ci-dessous —</div>
    <textarea class="pr-bulk" name="bulk" placeholder="Un contact par ligne :&#10;email;prénom nom;nom association;ville&#10;contact@club-sport.fr;Marie Dupont;Club Sportif de Palaiseau;Palaiseau&#10;contact@asso-culture.fr;;Association Culturelle;Massy"></textarea>
    <div style="display:flex;gap:10px;align-items:center;margin-top:11px;flex-wrap:wrap;">
      <label style="font-size:12.5px;color:var(--sa-ink-3);">Type :
        <select class="pr-mini" name="type"><option value="asso">Association</option><option value="tpe">TPE / PME</option></select>
      </label>
      <button type="submit" class="sa-btn sa-btn-primary sa-btn-sm">Importer (fichier + texte)</button>
      <span style="flex:1"></span>
    </div>
  </form>
  <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--sa-border);text-align:right;">
    <form method="post" style="display:inline" onsubmit="return confirm('Mettre tous les prospects « Nouveau » en séquence automatique de relance ?');">
      <input type="hidden" name="csrf" value="<?= pr_h($CSRF) ?>"><input type="hidden" name="action" value="queue_all">
      <button type="submit" class="sa-btn sa-btn-ghost sa-btn-sm">⚡ Tout mettre en séquence auto</button>
    </form>
  </div>
</div>

<?php
$toEnrich = 0;
try { $toEnrich = (int) $pdo->query("SELECT COUNT(*) FROM asso_prospects WHERE enriched_at IS NULL")->fetchColumn(); } catch (Throwable $e) {}
if ($toEnrich > 0): ?>
<div class="pr-panel">
  <h3>🌍 Localisation &amp; annuaire</h3>
  <p style="font-size:13px;color:var(--sa-ink-3);margin:0 0 12px;line-height:1.55;">
    <strong style="color:var(--sa-ink);"><?= $toEnrich ?> prospect(s)</strong> sans localisation. L'enrichissement retrouve automatiquement le <strong>nom officiel, la ville, le département et la région</strong> de chaque structure (données publiques de l'État), corrige les colonnes mélangées à l'import, et référence tout dans l'annuaire (région → département → catégorie).
  </p>
  <div class="pr-note warn" style="margin:0;"><span>💻</span><div>Lancez en SSH (le serveur a accès internet) :<br><code style="color:var(--sa-ink);font-size:13px;">php founder-prospects-enrich.php</code><br>ou dans le navigateur : <code style="font-size:12px;">/founder-prospects-enrich.php?key=assokit-enrich-5c2b90</code> (affiche la progression en direct).</div></div>
</div>
<?php endif; ?>

<div class="pr-toolbar">
  <div class="pr-tabs">
    <a href="/fondateur-prospection" class="pr-tab <?= $filter === '' ? 'active' : '' ?>">Tous (<?= $total ?>)</a>
    <?php foreach (['new','queued','contacted','replied','booked','unsubscribed'] as $s): ?>
      <a href="/fondateur-prospection?statut=<?= $s ?>" class="pr-tab <?= $filter === $s ? 'active' : '' ?>"><?= $STATUS_META[$s][0] ?> (<?= $by[$s] ?>)</a>
    <?php endforeach; ?>
  </div>
  <form method="get" class="pr-search">
    <input type="text" name="q" value="<?= pr_h($search) ?>" placeholder="Rechercher (asso, email, ville)…">
    <button type="submit" class="sa-btn sa-btn-ghost sa-btn-sm">Rechercher</button>
  </form>
</div>

<div class="pr-tablewrap">
  <table class="pr-table">
    <colgroup>
      <col class="pr-c-asso"><col class="pr-c-mail"><col class="pr-c-ville"><col class="pr-c-dept">
      <col class="pr-c-stat"><col class="pr-c-etape"><col class="pr-c-dates"><col class="pr-c-suivi"><col class="pr-c-act">
    </colgroup>
    <thead><tr>
      <th>Association</th><th>Email</th><th>Ville</th><th>Département</th><th>Statut</th><th>Étape</th>
      <th>Dates</th><th>Suivi</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="9"><div class="pr-empty">Aucun prospect ici. Importez des contacts ci-dessus, ou promouvez des associations depuis l'annuaire de l'app.</div></td></tr>
    <?php else: foreach ($rows as $p):
        $id = (int) $p['id']; $sm = $STATUS_META[$p['status']] ?? $STATUS_META['new'];
        $e = $ev[$id] ?? []; $stp = (int) $p['step']; $lbl = $seq[$stp]['label'] ?? '—';
        $canSend = !in_array($p['status'], ['unsubscribed','replied','booked'], true);
    ?>
      <tr>
        <td>
          <div class="pr-org"><?= pr_h($p['org_name'] ?: ($p['name'] ?: '—')) ?></div>
          <?php if (!empty($p['category']) && isset($CAT_LABELS[$p['category']])): ?><div class="pr-meta" style="margin-top:5px;"><span class="pr-badge" style="color:#0369A1;background:rgba(3,105,161,.12);"><?= pr_h($CAT_LABELS[$p['category']]) ?></span></div><?php endif; ?>
        </td>
        <td style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= pr_h($p['email']) ?>"><?= pr_h($p['email']) ?></td>
        <td style="font-size:12px;"><?= !empty($p['city']) ? pr_h($p['city']) : '<span style="color:var(--sa-ink-4)">—</span>' ?></td>
        <td style="font-size:12px;">
          <?php if (!empty($p['dept_code'])): ?>
            <span style="font-weight:700;color:var(--sa-ink);"><?= pr_h($p['dept_code']) ?></span><?= !empty($p['dept_name']) ? ' · ' . pr_h($p['dept_name']) : '' ?>
            <?php if (!empty($p['region'])): ?><div class="pr-meta" style="margin-top:1px;"><?= pr_h($p['region']) ?></div><?php endif; ?>
          <?php elseif (empty($p['enriched_at'])): ?>
            <span style="color:var(--sa-ink-4);font-style:italic;font-size:11px;">à enrichir</span>
          <?php else: ?>
            <span style="color:var(--sa-ink-4)">—</span>
          <?php endif; ?>
        </td>
        <td><span class="pr-badge" style="color:<?= $sm[1] ?>;background:<?= $sm[2] ?>;"><?= $sm[0] ?></span></td>
        <td style="font-size:12px;color:var(--sa-ink-2);white-space:nowrap;" title="<?= pr_h($lbl) ?>"><?= $stp + 1 ?>/<?= $LAST_STEP + 1 ?></td>
        <td class="pr-dates">
          <div><span class="l">Ajout</span><?= pr_ds($p['created_at']) ?></div>
          <div><span class="l">Envoi</span><?= pr_ds($p['last_sent_at']) ?></div>
          <div><span class="l">Relance</span><?= pr_ds($p['next_send_at']) ?></div>
        </td>
        <td><div class="pr-track"><span title="Envoyés">📤 <b><?= $e['sent'] ?? 0 ?></b></span><span title="Clics">👆 <b><?= $e['click'] ?? 0 ?></b></span><span title="Réponses">💬 <b><?= $e['reply'] ?? 0 ?></b></span></div></td>
        <td>
          <div class="pr-actions">
            <?php if ($canSend): ?>
            <form method="post" onsubmit="return confirm('Envoyer l\'email de prospection (étape <?= $stp + 1 ?>) à <?= pr_h($p['email']) ?> ?');">
              <input type="hidden" name="csrf" value="<?= pr_h($CSRF) ?>"><input type="hidden" name="action" value="send"><input type="hidden" name="id" value="<?= $id ?>">
              <button class="pr-ic send" type="submit" title="Envoyer l'email">✉️</button>
            </form>
            <details class="pr-rel">
              <summary class="pr-ic" title="Planifier une relance">🗓️</summary>
              <form method="post" class="pop">
                <input type="hidden" name="csrf" value="<?= pr_h($CSRF) ?>"><input type="hidden" name="action" value="relance"><input type="hidden" name="id" value="<?= $id ?>">
                <input class="pr-mini" type="date" name="date" value="<?= $p['next_send_at'] ? date('Y-m-d', strtotime($p['next_send_at'])) : '' ?>">
                <button class="pr-ic" type="submit">OK</button>
              </form>
            </details>
            <?php endif; ?>
            <form method="post" onsubmit="return confirm('Marquer comme « A répondu » ?');">
              <input type="hidden" name="csrf" value="<?= pr_h($CSRF) ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="value" value="replied">
              <button class="pr-ic" type="submit" title="A répondu">💬</button>
            </form>
            <form method="post" onsubmit="return confirm('Marquer « RDV pris » ?');">
              <input type="hidden" name="csrf" value="<?= pr_h($CSRF) ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="value" value="booked">
              <button class="pr-ic" type="submit" title="RDV pris">📅</button>
            </form>
            <form method="post" onsubmit="return confirm('Supprimer définitivement ce prospect ?');">
              <input type="hidden" name="csrf" value="<?= pr_h($CSRF) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
              <button class="pr-ic danger" type="submit" title="Supprimer">🗑️</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<p style="font-size:12px;color:var(--sa-ink-4);margin-top:14px;">
  Suivi : 📤 emails envoyés · 👆 clics sur le lien · 💬 réponses. Séquence rentrée : <?= $LAST_STEP + 1 ?> emails personnalisés (catégorie + ville), relances automatiques, désinscription incluse. Affichage limité à 500 lignes.
</p>

<?php sa_render_foot(); ?>
