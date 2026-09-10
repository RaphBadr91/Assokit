<?php
/**
 * prospection.php — Prospection téléphonique (association).
 * --------------------------------------------------------------
 * Une fiche par personne à contacter : nom, prénom, téléphone, e-mail.
 * Trois gestes, tous horodatés par le serveur — « Appel : oui/non »,
 * « E-mail : oui/non », « À rappeler le… » — plus un historique de tout ce
 * qui a été fait, par qui et quand.
 *
 * Deux canaux séparés plutôt qu'un état « contacté » : sur un même prospect
 * on appelle, on tombe sur un répondeur, on envoie un e-mail, on rappelle.
 * Savoir lequel a déjà servi change ce qu'on fait au coup suivant.
 *
 * Les données sont cloisonnées par organisation : chaque requête filtre sur
 * org_id, y compris les écritures, pour qu'un identifiant deviné dans un
 * formulaire ne donne pas accès au fichier d'une autre association.
 *
 * Sécurité :
 *   - connexion requise, organisation obligatoire
 *   - écritures en POST + jeton CSRF, puis redirection (pas de renvoi de
 *     formulaire au rechargement)
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';

require_login();
$user = current_user();
if (empty($user['org_id'])) { http_response_code(403); die('Aucune association rattachée à ce compte.'); }
$org_id = (int) $user['org_id'];
$uid    = (int) $user['id'];

$role = (string) ($user['role'] ?? '');
$allowed = in_array($role, ['admin', 'coordinator'], true);
if (!$allowed && function_exists('can')) $allowed = can('access_marketing');
if (!$allowed) { http_response_code(403); die('Accès refusé.'); }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

$flash = $_SESSION['flash_prospection'] ?? null;
unset($_SESSION['flash_prospection']);

/** Journalise un fait. Best-effort : un historique muet ne doit jamais
 *  empêcher l'action elle-même d'aboutir. */
function prosp_log(PDO $pdo, int $org_id, int $pid, int $uid, string $type, string $detail = ''): void {
    try {
        $pdo->prepare("INSERT INTO asso_prospect_events (org_id, prospect_id, user_id, type, detail)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([$org_id, $pid, $uid, $type, mb_substr($detail, 0, 255)]);
    } catch (Throwable $e) { /* sans importance */ }
}

/** Normalise une saisie datetime-local ('' → null). */
function prosp_dt(?string $v): ?string {
    $v = trim((string) $v);
    if ($v === '') return null;
    $t = strtotime(str_replace('T', ' ', $v));
    return $t ? date('Y-m-d H:i:00', $t) : null;
}

$migration_missing = false;

// ── Écritures ───────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(419); exit('Jeton CSRF invalide.');
    }
    $action = (string) ($_POST['action'] ?? '');
    $pid    = (int) ($_POST['id'] ?? 0);
    $msg    = null;

    try {
        if ($action === 'create') {
            $prenom = trim((string) ($_POST['prenom'] ?? ''));
            $nom    = trim((string) ($_POST['nom'] ?? ''));
            $tel    = trim((string) ($_POST['telephone'] ?? ''));
            $email  = trim((string) ($_POST['email'] ?? ''));

            // Un prospect sans nom ni téléphone n'est pas rappelable : autant
            // le refuser tout de suite plutôt que de polluer la liste.
            if ($prenom === '' && $nom === '' && $tel === '') {
                $msg = "Renseignez au moins un nom ou un numéro.";
            } else {
                $st = $pdo->prepare("INSERT INTO asso_prospects
                        (org_id, prenom, nom, telephone, email, created_by, updated_by, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $st->execute([$org_id, $prenom, $nom, $tel, $email, $uid, $uid]);
                prosp_log($pdo, $org_id, (int) $pdo->lastInsertId(), $uid, 'create', trim("$prenom $nom"));
                $msg = "Prospect ajouté.";
            }

        } elseif ($action === 'call' && $pid > 0) {
            // « Appelé : oui / non ». La date est posée par le serveur, jamais
            // saisie : c'est le seul moyen qu'elle reflète l'appel réel.
            $yes = ($_POST['value'] ?? '') === '1';
            $st = $pdo->prepare("UPDATE asso_prospects
                                 SET called = ?, called_at = " . ($yes ? 'NOW()' : 'NULL') . ",
                                     updated_by = ?, updated_at = NOW()
                                 WHERE id = ? AND org_id = ? AND deleted_at IS NULL");
            $st->execute([$yes ? 1 : 0, $uid, $pid, $org_id]);
            if ($st->rowCount() > 0) {
                prosp_log($pdo, $org_id, $pid, $uid, $yes ? 'call_yes' : 'call_no');
                $msg = $yes ? "Appel enregistré." : "Appel annulé.";
            }

        } elseif ($action === 'mail' && $pid > 0) {
            // Même principe que l'appel : la date vient du serveur. Un
            // canal distinct, parce que « appelé » et « relancé par e-mail »
            // n'appellent pas la même action suivante.
            $yes = ($_POST['value'] ?? '') === '1';
            $st = $pdo->prepare("UPDATE asso_prospects
                                 SET emailed = ?, emailed_at = " . ($yes ? 'NOW()' : 'NULL') . ",
                                     updated_by = ?, updated_at = NOW()
                                 WHERE id = ? AND org_id = ? AND deleted_at IS NULL");
            $st->execute([$yes ? 1 : 0, $uid, $pid, $org_id]);
            if ($st->rowCount() > 0) {
                prosp_log($pdo, $org_id, $pid, $uid, $yes ? 'mail_yes' : 'mail_no');
                $msg = $yes ? "E-mail enregistré." : "E-mail annulé.";
            }

        } elseif ($action === 'callback' && $pid > 0) {
            $when = prosp_dt($_POST['callback_at'] ?? '');
            $st = $pdo->prepare("UPDATE asso_prospects SET callback_at = ?, updated_by = ?, updated_at = NOW()
                                 WHERE id = ? AND org_id = ? AND deleted_at IS NULL");
            $st->execute([$when, $uid, $pid, $org_id]);
            if ($st->rowCount() > 0) {
                prosp_log($pdo, $org_id, $pid, $uid, $when ? 'callback_set' : 'callback_clear',
                          $when ? date('d/m/Y à H:i', strtotime($when)) : '');
                $msg = $when ? "Rappel programmé le " . date('d/m/Y à H:i', strtotime($when)) . "." : "Rappel retiré.";
            }

        } elseif ($action === 'edit' && $pid > 0) {
            $st = $pdo->prepare("SELECT prenom, nom, telephone, email, notes FROM asso_prospects
                                 WHERE id = ? AND org_id = ? AND deleted_at IS NULL LIMIT 1");
            $st->execute([$pid, $org_id]);
            $old = $st->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                $new = [
                    'prenom'    => trim((string) ($_POST['prenom'] ?? '')),
                    'nom'       => trim((string) ($_POST['nom'] ?? '')),
                    'telephone' => trim((string) ($_POST['telephone'] ?? '')),
                    'email'     => trim((string) ($_POST['email'] ?? '')),
                    'notes'     => trim((string) ($_POST['notes'] ?? '')),
                ];
                // On nomme les champs réellement modifiés : « fiche modifiée »
                // n'apprend rien à celui qui relira l'historique dans un mois.
                $labels = ['prenom' => 'prénom', 'nom' => 'nom', 'telephone' => 'téléphone',
                           'email' => 'e-mail', 'notes' => 'notes'];
                $changed = [];
                foreach ($new as $k => $v) if ((string) $old[$k] !== $v) $changed[] = $labels[$k];

                $pdo->prepare("UPDATE asso_prospects
                               SET prenom = ?, nom = ?, telephone = ?, email = ?, notes = ?,
                                   updated_by = ?, updated_at = NOW()
                               WHERE id = ? AND org_id = ?")
                    ->execute([$new['prenom'], $new['nom'], $new['telephone'], $new['email'],
                               $new['notes'], $uid, $pid, $org_id]);
                if ($changed) prosp_log($pdo, $org_id, $pid, $uid, 'edit', implode(', ', $changed));
                $msg = $changed ? "Fiche mise à jour (" . implode(', ', $changed) . ")." : "Aucun changement.";
            }

        } elseif ($action === 'delete' && $pid > 0) {
            $st = $pdo->prepare("UPDATE asso_prospects SET deleted_at = NOW(), updated_by = ?, updated_at = NOW()
                                 WHERE id = ? AND org_id = ? AND deleted_at IS NULL");
            $st->execute([$uid, $pid, $org_id]);
            if ($st->rowCount() > 0) { prosp_log($pdo, $org_id, $pid, $uid, 'delete'); $msg = "Prospect supprimé."; }

        } elseif ($action === 'restore' && $pid > 0) {
            $st = $pdo->prepare("UPDATE asso_prospects SET deleted_at = NULL, updated_by = ?, updated_at = NOW()
                                 WHERE id = ? AND org_id = ? AND deleted_at IS NOT NULL");
            $st->execute([$uid, $pid, $org_id]);
            if ($st->rowCount() > 0) { prosp_log($pdo, $org_id, $pid, $uid, 'restore'); $msg = "Prospect restauré."; }
        }
    } catch (Throwable $e) {
        $msg = "Échec : " . $e->getMessage();
    }

    // Redirection après écriture : sinon un F5 rejoue l'action.
    $_SESSION['flash_prospection'] = $msg;
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: /prospection' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

// ── Lecture ─────────────────────────────────────────────────────────────────
$filtre = (string) ($_GET['f'] ?? 'tous');
$q      = trim((string) ($_GET['q'] ?? ''));

$where  = ['p.org_id = ?'];
$params = [$org_id];

if ($filtre === 'corbeille') {
    $where[] = 'p.deleted_at IS NOT NULL';
} else {
    $where[] = 'p.deleted_at IS NULL';
    if ($filtre === 'a_appeler')      $where[] = 'p.called = 0';
    elseif ($filtre === 'appeles')    $where[] = 'p.called = 1';
    elseif ($filtre === 'emails')     $where[] = 'p.emailed = 1';
    elseif ($filtre === 'jamais')     $where[] = 'p.called = 0 AND p.emailed = 0';
    elseif ($filtre === 'a_rappeler') $where[] = 'p.callback_at IS NOT NULL';
    elseif ($filtre === 'en_retard')  $where[] = 'p.callback_at IS NOT NULL AND p.callback_at <= NOW()';
}
if ($q !== '') {
    $where[] = '(p.nom LIKE ? OR p.prenom LIKE ? OR p.telephone LIKE ? OR p.email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

$rows = []; $stats = ['total' => 0, 'a_appeler' => 0, 'appeles' => 0, 'emails' => 0, 'a_rappeler' => 0, 'en_retard' => 0];
$events = [];
$qr_labels = [];

try {
    // Les rappels dus remontent en tête : c'est l'ordre dans lequel on
    // décroche le téléphone.
    // users porte first_name/last_name, pas name : CONCAT_WS ignore les NULL
    // et ne laisse pas d'espace orphelin si le nom de famille manque.
    $sql = "SELECT p.*, TRIM(CONCAT_WS(' ', uu.first_name, uu.last_name)) AS updated_name
            FROM asso_prospects p
            LEFT JOIN users uu ON uu.id = p.updated_by
            WHERE " . implode(' AND ', $where) . "
            ORDER BY (p.callback_at IS NOT NULL AND p.callback_at <= NOW()) DESC,
                     p.callback_at IS NULL, p.callback_at ASC,
                     p.called ASC, p.id DESC
            LIMIT 300";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT
            COUNT(*) AS total,
            SUM(called = 0) AS a_appeler,
            SUM(called = 1) AS appeles,
            SUM(emailed = 1) AS emails,
            SUM(callback_at IS NOT NULL) AS a_rappeler,
            SUM(callback_at IS NOT NULL AND callback_at <= NOW()) AS en_retard
          FROM asso_prospects WHERE org_id = ? AND deleted_at IS NULL");
    $st->execute([$org_id]);
    $stats = array_map('intval', $st->fetch(PDO::FETCH_ASSOC) ?: $stats);

    // Libellés des codes QR, en requête séparée et tolérante : si la migration
    // des QR n'a pas été passée, la prospection doit continuer de fonctionner.
    // Une jointure aurait fait tomber toute la page.
    if ($rows) {
        try {
            foreach ($pdo->query("SELECT id, label FROM asso_qr_codes WHERE org_id = " . (int) $org_id)
                         ->fetchAll(PDO::FETCH_ASSOC) as $q) {
                $qr_labels[(int) $q['id']] = (string) $q['label'];
            }
        } catch (Throwable $e) { /* migration QR pas encore passée */ }
    }

    if ($rows) {
        $ids = array_column($rows, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $st  = $pdo->prepare("SELECT e.*, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS user_name
                              FROM asso_prospect_events e
                              LEFT JOIN users u ON u.id = e.user_id
                              WHERE e.org_id = ? AND e.prospect_id IN ($in)
                              ORDER BY e.id DESC");
        $st->execute(array_merge([$org_id], $ids));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) $events[(int) $e['prospect_id']][] = $e;
    }
} catch (Throwable $e) {
    $migration_missing = true;
}

$EVENT_LABEL = [
    'create'         => 'Fiche créée',
    'edit'           => 'Fiche modifiée',
    'call_yes'       => 'Marquée appelée',
    'call_no'        => 'Appel annulé',
    'mail_yes'       => 'E-mail envoyé',
    'mail_no'        => 'E-mail annulé',
    'callback_set'   => 'Rappel programmé',
    'callback_clear' => 'Rappel retiré',
    'delete'         => 'Supprimée',
    'restore'        => 'Restaurée',
];

$FILTRES = [
    'tous'       => 'Tous',
    'a_appeler'  => 'À appeler',
    'appeles'    => 'Appelés',
    'emails'     => 'E-mail envoyé',
    'jamais'     => 'Jamais contactés',
    'a_rappeler' => 'À rappeler',
    'en_retard'  => 'Rappels dus',
    'corbeille'  => 'Corbeille',
];

/** Conserve le filtre courant à travers les redirections. */
$qs_keep = http_build_query(array_filter(['f' => $filtre !== 'tous' ? $filtre : '', 'q' => $q]));

render_head('Prospection');
render_sidebar('prospection');
?>

<main class="main">
<style>
  .pr-top{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:20px}
  .pr-kpis{display:flex;gap:9px;flex-wrap:wrap}
  .pr-kpi{background:#fff;border:1px solid var(--line,#E7EEEA);border-radius:12px;padding:9px 15px;min-width:92px}
  .pr-kpi b{display:block;font-size:20px;line-height:1.2}
  .pr-kpi span{font-size:11px;color:var(--ink-3,#5F6D66);text-transform:uppercase;letter-spacing:.04em}
  .pr-kpi.hot b{color:#B91C1C}
  .pr-flash{border:1px solid #A7F3D0;background:#ECFDF5;color:#065F46;border-radius:11px;padding:12px 15px;margin-bottom:16px;font-size:14px}
  .pr-panel{background:#fff;border:1px solid var(--line,#E7EEEA);border-radius:16px;padding:16px;margin-bottom:16px}
  .pr-new{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,165px),1fr));gap:10px;align-items:end}
  .pr-new label{display:block;font-size:12px;font-weight:600;color:var(--ink-2,#45544D);margin-bottom:5px}
  input.pr-in,textarea.pr-in{width:100%;padding:9px 12px;border:1px solid var(--line,#E7EEEA);border-radius:9px;font:inherit;font-size:13.5px;background:#fff}
  input.pr-in:focus,textarea.pr-in:focus{outline:2px solid #05966933;border-color:#059669}
  .pr-btn{padding:10px 17px;border-radius:10px;border:none;background:#059669;color:#fff;font:inherit;font-weight:600;font-size:13.5px;cursor:pointer;white-space:nowrap}
  .pr-btn.sec{background:#fff;border:1px solid var(--line,#E7EEEA);color:var(--ink-2,#45544D)}
  .pr-btn.dgr{background:#fff;border:1px solid #FECACA;color:#B91C1C}
  .pr-tabs{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px}
  .pr-tab{padding:8px 14px;border-radius:999px;border:1px solid var(--line,#E7EEEA);background:#fff;font-size:13px;font-weight:600;color:var(--ink-2,#45544D);text-decoration:none}
  .pr-tab.on{background:#059669;border-color:#059669;color:#fff}
  .pr-search{display:flex;gap:8px;margin-left:auto}
  .pr-row{background:#fff;border:1px solid var(--line,#E7EEEA);border-radius:14px;margin-bottom:10px;overflow:hidden}
  .pr-row.due{border-color:#FCA5A5;box-shadow:0 0 0 3px #FEE2E233}
  .pr-main{display:flex;align-items:center;gap:14px;padding:13px 16px;flex-wrap:wrap}
  .pr-id{width:36px;height:36px;border-radius:11px;background:#ECF7F2;color:#059669;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0}
  .pr-who b{font-size:14.5px;display:block}
  .pr-coord{font-size:12.5px;color:var(--ink-3,#5F6D66);display:flex;gap:12px;flex-wrap:wrap;margin-top:2px}
  .pr-coord a{color:#059669;text-decoration:none;font-weight:500}
  .pr-badges{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
  .pr-bg{font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;letter-spacing:.02em}
  .pr-bg.ok{background:#D1FAE5;color:#065F46}
  .pr-bg.no{background:#F1F5F9;color:#64748B}
  .pr-bg.cb{background:#FEF3C7;color:#92400E}
  .pr-bg.due{background:#FEE2E2;color:#991B1B}
  .pr-bg.qr{background:#EDE9FE;color:#5B21B6}
  .pr-bg.ml{background:#DBEAFE;color:#1E40AF}
  .pr-acts{margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .pr-detail{border-top:1px solid var(--sep,#F1F5F4);padding:14px 16px;background:#FBFDFC;display:grid;grid-template-columns:1fr 300px;gap:20px}
  .pr-detail h4{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3,#5F6D66);margin:0 0 9px}
  .pr-hist{list-style:none;margin:0;padding:0;max-height:200px;overflow:auto}
  .pr-hist li{font-size:12.5px;padding:6px 0;border-bottom:1px solid var(--sep,#F1F5F4);line-height:1.45}
  .pr-hist li:last-child{border-bottom:none}
  .pr-hist time{color:var(--ink-3,#5F6D66)}
  .pr-empty{text-align:center;padding:44px 20px;color:var(--ink-3,#5F6D66)}
  /* Le champ de date doit pouvoir rétrécir : sans min-width:0 il garde sa
     largeur intrinsèque et pousse le bouton hors de la carte, qui le rogne. */
  .pr-cb{display:flex;gap:6px;align-items:center;flex-wrap:wrap;min-width:0}
  .pr-cb input{flex:1 1 170px;min-width:0;max-width:210px}
  .pr-seg{display:inline-flex;align-items:center;gap:0;border:1px solid var(--line,#E7EEEA);border-radius:10px;overflow:hidden;background:#fff}
  .pr-seg-lab{font-size:11.5px;font-weight:700;color:var(--ink-3,#5F6D66);padding:0 10px;letter-spacing:.04em;text-transform:uppercase}
  .pr-seg button{border:none;background:#fff;font:inherit;font-weight:700;font-size:13px;padding:9px 15px;cursor:pointer;color:var(--ink-3,#5F6D66);border-left:1px solid var(--line,#E7EEEA)}
  .pr-seg button:hover{background:var(--bg-2,#EDF2EF)}
  .pr-seg button.on{background:#059669;color:#fff}
  .pr-seg button.on.off{background:var(--ink-3,#5F6D66)}
  summary{cursor:pointer;list-style:none}
  summary::-webkit-details-marker{display:none}
  summary::before{content:"▸";display:inline-block;margin-right:7px;transition:transform .12s ease;color:var(--ink-3,#5F6D66)}
  details[open] summary::before{transform:rotate(90deg)}
  @media (max-width:820px){
    .pr-detail{grid-template-columns:1fr}
    .pr-acts{margin-left:0;width:100%}
    .pr-search{margin-left:0;width:100%}
  }
</style>

<div class="pr-top">
  <div>
    <h1 style="font-size:23px;margin:0 0 4px">Prospection</h1>
    <p style="color:var(--ink-3,#5F6D66);font-size:14px;margin:0;max-width:680px">
      Marquez l'appel ou l'e-mail d'un clic — la date est posée automatiquement — et
      programmez le rappel. Chaque geste est daté et signé dans l'historique de la fiche.
    </p>
  </div>
  <div class="pr-kpis">
    <a class="pr-kpi" href="/mon-asso-qr" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:9px;min-width:0">
      <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3h-3zM18 18h3v3h-3z"/></svg>
      <span style="font-size:13px;font-weight:600;color:#059669">Codes QR</span>
    </a>
    <div class="pr-kpi"><b><?= $stats['total'] ?></b><span>fiches</span></div>
    <div class="pr-kpi"><b><?= $stats['a_appeler'] ?></b><span>à appeler</span></div>
    <div class="pr-kpi"><b><?= $stats['appeles'] ?></b><span>appelés</span></div>
    <div class="pr-kpi"><b><?= $stats['emails'] ?></b><span>e-mails</span></div>
    <div class="pr-kpi <?= $stats['en_retard'] > 0 ? 'hot' : '' ?>"><b><?= $stats['en_retard'] ?></b><span>rappels dus</span></div>
  </div>
</div>

<?php if ($flash): ?><div class="pr-flash"><?= h($flash) ?></div><?php endif; ?>

<?php if ($migration_missing): ?>
  <div class="pr-flash" style="background:#FEF2F2;border-color:#FECACA;color:#991B1B">
    Les tables de prospection n'existent pas encore. Sur le serveur :
    <code style="display:inline-block;margin-top:6px">php migrations/run.php 2026-09-09-prospection-asso.sql</code>
  </div>
<?php else: ?>

<div class="pr-panel">
  <form method="post" action="/prospection<?= $qs_keep ? '?' . h($qs_keep) : '' ?>" class="pr-new">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <div><label>Prénom</label><input class="pr-in" name="prenom" autocomplete="off"></div>
    <div><label>Nom</label><input class="pr-in" name="nom" autocomplete="off"></div>
    <div><label>Téléphone</label><input class="pr-in" name="telephone" type="tel" inputmode="tel" placeholder="06 12 34 56 78"></div>
    <div><label>E-mail</label><input class="pr-in" name="email" type="email" autocomplete="off"></div>
    <div><button class="pr-btn" type="submit">Ajouter</button></div>
  </form>
</div>

<div class="pr-tabs">
  <?php foreach ($FILTRES as $k => $lab):
        $u = '/prospection?' . http_build_query(array_filter(['f' => $k !== 'tous' ? $k : '', 'q' => $q])); ?>
    <a class="pr-tab <?= $filtre === $k ? 'on' : '' ?>" href="<?= h($u) ?>"><?= h($lab) ?><?php
      if ($k === 'en_retard' && $stats['en_retard'] > 0) echo ' · ' . $stats['en_retard']; ?></a>
  <?php endforeach; ?>
  <form method="get" action="/prospection" class="pr-search">
    <input type="hidden" name="f" value="<?= h($filtre) ?>">
    <input class="pr-in" name="q" value="<?= h($q) ?>" placeholder="Nom, téléphone, e-mail…" style="min-width:180px">
    <button class="pr-btn sec" type="submit">Rechercher</button>
  </form>
</div>

<?php if (!$rows): ?>
  <div class="pr-panel pr-empty">
    <?= $q !== '' ? 'Aucun résultat pour « ' . h($q) . ' ».' : 'Aucune fiche ici pour le moment.' ?>
  </div>
<?php endif; ?>

<?php foreach ($rows as $p):
  $pid = (int) $p['id'];
  $due = !empty($p['callback_at']) && strtotime((string) $p['callback_at']) <= time();
  $who = trim((string) $p['prenom'] . ' ' . (string) $p['nom']);
  $deleted = !empty($p['deleted_at']);
  $telClean = preg_replace('/[^0-9+]/', '', (string) $p['telephone']);
?>
<div class="pr-row <?= $due && !$deleted ? 'due' : '' ?>">
  <div class="pr-main">
    <span class="pr-id"><?= h(mb_strtoupper(mb_substr((string) $p['prenom'], 0, 1) . mb_substr((string) $p['nom'], 0, 1))) ?: '·' ?></span>
    <div class="pr-who">
      <b><?= $who !== '' ? h($who) : '<span style="color:#8A968F;font-weight:400">Sans nom</span>' ?></b>
      <div class="pr-coord">
        <?php if ($telClean !== ''): ?><a href="tel:<?= h($telClean) ?>">📞 <?= h($p['telephone']) ?></a><?php endif; ?>
        <?php if (!empty($p['email'])): ?><a href="mailto:<?= h($p['email']) ?>">✉️ <?= h($p['email']) ?></a><?php endif; ?>
      </div>
    </div>

    <div class="pr-badges">
      <?php if ($deleted): ?>
        <span class="pr-bg no">SUPPRIMÉE</span>
      <?php else: ?>
        <?php if (($p['source'] ?? 'manuel') === 'qr'): ?>
          <span class="pr-bg qr" title="Coordonnées laissées par la personne elle-même">VIA QR<?php
            $ql = $qr_labels[(int) ($p['qr_id'] ?? 0)] ?? '';
            if ($ql !== '') echo ' · ' . h($ql); ?></span>
        <?php endif; ?>
        <?php if (!empty($p['called'])): ?>
          <span class="pr-bg ok">APPELÉ<?= !empty($p['called_at']) ? ' · ' . h(date('d/m/Y H:i', strtotime((string) $p['called_at']))) : '' ?></span>
        <?php else: ?>
          <span class="pr-bg no">NON APPELÉ</span>
        <?php endif; ?>
        <?php if (!empty($p['emailed'])): ?>
          <span class="pr-bg ml">E-MAIL<?= !empty($p['emailed_at']) ? ' · ' . h(date('d/m/Y H:i', strtotime((string) $p['emailed_at']))) : '' ?></span>
        <?php endif; ?>
        <?php if (!empty($p['callback_at'])): ?>
          <span class="pr-bg <?= $due ? 'due' : 'cb' ?>">RAPPEL <?= h(date('d/m/Y H:i', strtotime((string) $p['callback_at']))) ?></span>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="pr-acts">
      <?php if ($deleted): ?>
        <form method="post" action="/prospection<?= $qs_keep ? '?' . h($qs_keep) : '' ?>">
          <input type="hidden" name="action" value="restore">
          <input type="hidden" name="id" value="<?= $pid ?>">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <button class="pr-btn sec" type="submit">Restaurer</button>
        </form>
      <?php else: ?>
        <!-- Interrupteur à deux positions plutôt qu'un bouton qui bascule :
             un bouton unique étiqueté « Appelé : NON » à côté d'un badge
             « APPELÉ » se lit comme un état, pas comme une action. Ici l'état
             courant est celui qui est allumé. -->
        <form method="post" action="/prospection<?= $qs_keep ? '?' . h($qs_keep) : '' ?>" class="pr-seg">
          <input type="hidden" name="action" value="call">
          <input type="hidden" name="id" value="<?= $pid ?>">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <span class="pr-seg-lab">Appel</span>
          <button type="submit" name="value" value="1" class="<?= !empty($p['called']) ? 'on' : '' ?>"
                  <?= !empty($p['called']) ? 'aria-pressed="true"' : '' ?>>OUI</button>
          <button type="submit" name="value" value="0" class="<?= empty($p['called']) ? 'on off' : '' ?>"
                  <?= empty($p['called']) ? 'aria-pressed="true"' : '' ?>>NON</button>
        </form>

        <form method="post" action="/prospection<?= $qs_keep ? '?' . h($qs_keep) : '' ?>" class="pr-seg">
          <input type="hidden" name="action" value="mail">
          <input type="hidden" name="id" value="<?= $pid ?>">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <span class="pr-seg-lab">E-mail</span>
          <button type="submit" name="value" value="1" class="<?= !empty($p['emailed']) ? 'on' : '' ?>"
                  <?= !empty($p['emailed']) ? 'aria-pressed="true"' : '' ?>>OUI</button>
          <button type="submit" name="value" value="0" class="<?= empty($p['emailed']) ? 'on off' : '' ?>"
                  <?= empty($p['emailed']) ? 'aria-pressed="true"' : '' ?>>NON</button>
        </form>


        <form method="post" action="/prospection<?= $qs_keep ? '?' . h($qs_keep) : '' ?>" class="pr-cb">
          <input type="hidden" name="action" value="callback">
          <input type="hidden" name="id" value="<?= $pid ?>">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input class="pr-in" type="datetime-local" name="callback_at"
                 value="<?= !empty($p['callback_at']) ? h(date('Y-m-d\TH:i', strtotime((string) $p['callback_at']))) : '' ?>"
                 aria-label="Date de rappel">
          <button class="pr-btn sec" type="submit">À rappeler</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <details>
    <summary style="padding:9px 16px;font-size:12.5px;color:var(--ink-3,#5F6D66);border-top:1px solid var(--sep,#F1F5F4)">
      Détails et historique
      <?php if (!empty($p['updated_at'])): ?>
        — dernière modification le <?= h(date('d/m/Y à H:i', strtotime((string) $p['updated_at']))) ?><?php
        if (!empty($p['updated_name'])) echo ' par ' . h($p['updated_name']); ?>
      <?php endif; ?>
    </summary>

    <div class="pr-detail">
      <form method="post" action="/prospection<?= $qs_keep ? '?' . h($qs_keep) : '' ?>">
        <!-- Pas de champ caché « action » : les deux boutons la portent. Un
             champ caché serait écrasé par le bouton cliqué, ce qui marche mais
             ne se lit pas. -->
        <input type="hidden" name="id" value="<?= $pid ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <h4>Coordonnées</h4>
        <div class="pr-new" style="margin-bottom:10px">
          <div><label>Prénom</label><input class="pr-in" name="prenom" value="<?= h($p['prenom']) ?>"></div>
          <div><label>Nom</label><input class="pr-in" name="nom" value="<?= h($p['nom']) ?>"></div>
          <div><label>Téléphone</label><input class="pr-in" name="telephone" type="tel" value="<?= h($p['telephone']) ?>"></div>
          <div><label>E-mail</label><input class="pr-in" name="email" type="email" value="<?= h($p['email']) ?>"></div>
        </div>
        <label style="display:block;font-size:12px;font-weight:600;color:var(--ink-2,#45544D);margin-bottom:5px">Notes d'appel</label>
        <textarea class="pr-in" name="notes" rows="3" placeholder="Ce qui s'est dit, l'objection, le bon moment pour rappeler…"><?= h($p['notes']) ?></textarea>
        <div style="display:flex;gap:8px;margin-top:11px;flex-wrap:wrap">
          <button class="pr-btn" type="submit" name="action" value="edit">Enregistrer</button>
          <?php if (!$deleted): ?>
            <button class="pr-btn dgr" type="submit" name="action" value="delete"
                    onclick="return confirm('Supprimer <?= h($who !== '' ? $who : 'cette fiche') ?> ? Elle restera récupérable dans la corbeille.')">
              Supprimer
            </button>
          <?php endif; ?>
        </div>
      </form>

      <div>
        <h4>Historique</h4>
        <ul class="pr-hist">
          <?php foreach (($events[$pid] ?? []) as $e): ?>
            <li>
              <strong><?= h($EVENT_LABEL[$e['type']] ?? $e['type']) ?></strong><?php
                if (!empty($e['detail'])) echo ' — ' . h($e['detail']); ?><br>
              <time><?= h(date('d/m/Y à H:i', strtotime((string) $e['created_at']))) ?></time><?php
                if (!empty($e['user_name'])) echo ' · ' . h($e['user_name']); ?>
            </li>
          <?php endforeach; ?>
          <?php if (empty($events[$pid])): ?>
            <li style="color:var(--ink-3,#5F6D66)">Rien d'enregistré pour l'instant.</li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </details>
</div>
<?php endforeach; ?>

<?php if (count($rows) >= 300): ?>
  <p style="color:var(--ink-3,#5F6D66);font-size:13px">
    Affichage limité aux 300 premières fiches. Affinez avec la recherche ou les filtres.
  </p>
<?php endif; ?>

<?php endif; // migration ?>
</main>
<?php render_foot(); ?>
