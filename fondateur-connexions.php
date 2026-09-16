<?php
/**
 * fondateur-connexions.php — Qui se connecte, quand, et pour y faire quoi.
 * URL : /fondateur-connexions
 * ------------------------------------------------------------------
 * /fondateur-activity affiche le journal brut : un flux d'événements
 * qu'on filtre. Utile pour remonter un incident précis, illisible pour
 * répondre à « cette association s'en sert-elle ? ».
 *
 * Cette page-ci agrège le même journal, en trois niveaux :
 *
 *   1. les associations       — combien de membres actifs, de connexions,
 *                               d'actions, et quand pour la dernière fois ;
 *   2. les membres de l'une   — les mêmes chiffres, personne par personne ;
 *   3. le détail d'un membre  — ses sessions, puis minute par minute ce
 *                               qu'il a fait.
 *
 * Pour intervenir sur un compte, la page renvoie vers l'incarnation
 * existante (/super-admin/incarner) : elle demande un motif, expire
 * d'elle-même et laisse sa propre trace. Rouvrir ici un second chemin de
 * modification aurait contourné ce garde-fou.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity-tracker.php';

// ------------------------------------------------------------------
// Accès : Fondateur uniquement (même contrôle que /fondateur-activity)
// ------------------------------------------------------------------
$est_fondateur = false;
if (!empty($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT is_founder, is_super_admin, role FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([(int) $_SESSION['user_id']]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u && ((int) ($u['is_founder'] ?? 0) === 1
                || (int) ($u['is_super_admin'] ?? 0) === 1
                || ($u['role'] ?? '') === 'super_admin'
                || ($u['role'] ?? '') === 'founder')) {
            $est_fondateur = true;
        }
    } catch (Throwable $e) {
        error_log('fondateur-connexions accès : ' . $e->getMessage());
    }
}
if (!$est_fondateur) {
    if (empty($_SESSION['user_id'])) { header('Location: /connexion'); exit; }
    http_response_code(403);
    exit('<h1>403 — Accès réservé au Fondateur</h1><p><a href="/dashboard">← Tableau de bord</a></p>');
}

activity_ensure_table($pdo);

function hc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/** Durée lisible. « 4 260 s » ne dit rien à personne. */
function duree(int $s): string
{
    if ($s < 60)   return $s . ' s';
    if ($s < 3600) return intdiv($s, 60) . ' min';
    if ($s < 86400) return intdiv($s, 3600) . ' h ' . intdiv($s % 3600, 60) . ' min';
    return intdiv($s, 86400) . ' j';
}

/**
 * Date longue en français. strftime() est déprécié depuis PHP 8.1 et
 * dépendait de la locale du serveur, jamais garantie sur un hébergement
 * mutualisé : on écrit les noms plutôt que de les demander au système.
 */
function strftime_fr(int $t): string
{
    $jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
    $mois  = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
              'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $j = $jours[(int) date('w', $t)];
    $n = (int) date('j', $t);
    return $j . ' ' . ($n === 1 ? '1er' : $n) . ' ' . $mois[(int) date('n', $t)] . ' ' . date('Y', $t);
}

/** Date relative, plus parlante qu'un horodatage pour « la dernière fois ». */
function depuis(?string $d): string
{
    if (!$d) return 'jamais';
    $t = strtotime($d);
    if (!$t) return '—';
    $e = time() - $t;
    if ($e < 60)    return "à l'instant";
    if ($e < 3600)  return 'il y a ' . intdiv($e, 60) . ' min';
    if ($e < 86400) return 'il y a ' . intdiv($e, 3600) . ' h';
    if ($e < 172800) return 'hier à ' . date('H:i', $t);
    if ($e < 604800) return 'il y a ' . intdiv($e, 86400) . ' j';
    return date('d/m/Y', $t);
}

// ------------------------------------------------------------------
// Période et niveau
// ------------------------------------------------------------------
$PERIODES = ['24h' => '24 heures', '7d' => '7 jours', '30d' => '30 jours',
             '90d' => '90 jours', 'tout' => 'Tout l’historique'];
$periode  = isset($_GET['p'], $PERIODES[$_GET['p']]) ? (string) $_GET['p'] : '7d';
$intervalles = ['24h' => '24 HOUR', '7d' => '7 DAY', '30d' => '30 DAY', '90d' => '90 DAY'];
// « Tout » : une borne très ancienne plutôt qu'une requête différente, pour
// garder une seule forme de SQL et un seul jeu de paramètres.
$borne = isset($intervalles[$periode])
    ? "DATE_SUB(NOW(), INTERVAL {$intervalles[$periode]})"
    : "'2000-01-01'";

$vueOrg  = (int) ($_GET['org'] ?? 0);
$vueUser = (int) ($_GET['user'] ?? 0);

// Le niveau 1 se pagine : à 10 000 associations, tout afficher d'un coup
// n'est ni lisible ni tenable — c'est aussi ce qui obligeait à compter
// l'activité de toutes les associations pour en afficher une page.
const ASSOS_PAR_PAGE = 50;
$page       = max(1, (int) ($_GET['page'] ?? 1));
$assosTotal = 0;
$assosPages = 1;

// Capture par référence : sur /fondateur-connexions?user=12 sans `org`,
// l'association est déduite plus bas, après la définition de cette
// fermeture. Capturée par valeur, elle valait encore 0 et le fil d'Ariane
// renvoyait à la racine au lieu de remonter à l'association.
$lien = function (array $chg = []) use ($periode, &$vueOrg, &$vueUser): string {
    $q = array_filter(array_merge(
        ['p' => $periode, 'org' => $vueOrg ?: '', 'user' => $vueUser ?: ''], $chg
    ), fn($v) => $v !== '' && $v !== 0);
    return '/fondateur-connexions' . ($q ? '?' . http_build_query($q) : '');
};

// ------------------------------------------------------------------
// Chiffres d'en-tête
// ------------------------------------------------------------------
$tot = ['connexions' => 0, 'membres' => 0, 'actions' => 0, 'assos' => 0, 'enligne' => 0];
try {
    $r = $pdo->query("SELECT
            SUM(event_type = 'login')  AS connexions,
            SUM(event_type = 'action') AS actions,
            COUNT(DISTINCT user_id)          AS membres,
            COUNT(DISTINCT organization_id)  AS assos
        FROM assokit_activity_log WHERE created_at >= $borne")->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['connexions', 'actions', 'membres', 'assos'] as $k) $tot[$k] = (int) ($r[$k] ?? 0);
    $tot['enligne'] = (int) $pdo->query("SELECT COUNT(*) FROM assokit_active_sessions
        WHERE last_activity_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)")->fetchColumn();
} catch (Throwable $e) {}

// ------------------------------------------------------------------
// Données du niveau affiché
// ------------------------------------------------------------------
$assos = $membres = $sessions = $timeline = [];
$fiche = null;
$orgNom = '';

try {
    if ($vueUser > 0) {
        // ---- Niveau 3 : un membre ----
        $st = $pdo->prepare("SELECT u.*, o.name AS org_nom FROM users u
                             LEFT JOIN organizations o ON o.id = u.org_id
                             WHERE u.id = ? LIMIT 1");
        $st->execute([$vueUser]);
        $fiche = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($fiche) { $vueOrg = (int) $fiche['org_id']; $orgNom = (string) $fiche['org_nom']; }

        // Les sessions : une ligne par session_id, du début à la dernière trace.
        $st = $pdo->prepare("SELECT session_id,
                    MIN(created_at) AS debut, MAX(created_at) AS fin,
                    TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(created_at)) AS duree_s,
                    SUM(event_type = 'pageview') AS pages,
                    SUM(event_type = 'action')   AS actions,
                    MAX(ip) AS ip, MAX(user_agent) AS agent
                 FROM assokit_activity_log
                 WHERE user_id = ? AND created_at >= $borne AND session_id IS NOT NULL
                 GROUP BY session_id ORDER BY debut DESC LIMIT 60");
        $st->execute([$vueUser]);
        $sessions = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $pdo->prepare("SELECT * FROM assokit_activity_log
                             WHERE user_id = ? AND created_at >= $borne
                             ORDER BY created_at DESC LIMIT 600");
        $st->execute([$vueUser]);
        $timeline = $st->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($vueOrg > 0) {
        // ---- Niveau 2 : les membres d'une association ----
        $st = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
        $st->execute([$vueOrg]);
        $orgNom = (string) ($st->fetchColumn() ?: ('Organisation ' . $vueOrg));

        $st = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.last_login_at,
                    SUM(l.event_type = 'login')    AS connexions,
                    SUM(l.event_type = 'action')   AS actions,
                    SUM(l.event_type = 'pageview') AS pages,
                    COUNT(DISTINCT l.session_id)   AS sessions,
                    MAX(l.created_at)              AS derniere
                 FROM users u
                 LEFT JOIN assokit_activity_log l
                        ON l.user_id = u.id AND l.created_at >= $borne
                 WHERE u.org_id = ? AND u.deleted_at IS NULL
                 GROUP BY u.id, u.first_name, u.last_name, u.email, u.role, u.last_login_at
                 -- L'expression est répétée plutôt que reprise par son alias :
                 -- MariaDB refuse « derniere IS NULL », un alias d'agrégat ne
                 -- pouvant pas servir d'opérande dans ORDER BY.
                 ORDER BY MAX(l.created_at) IS NULL, MAX(l.created_at) DESC, u.last_name ASC");
        $st->execute([$vueOrg]);
        $membres = $st->fetchAll(PDO::FETCH_ASSOC);

    } else {
        // ---- Niveau 1 : les associations ----
        //
        // La version d'origine agrégeait le journal entier, joint à toutes
        // les associations, en une seule requête. Mesuré sur une base de
        // test à 10 000 associations et 5,36 millions de lignes de
        // journal : 11 secondes. Aucun index n'y change rien — le tri
        // final porte sur un agrégat, donc MySQL doit de toute façon
        // calculer les compteurs des 10 000 associations avant de savoir
        // laquelle afficher en premier.
        //
        // On procède donc en deux temps : on ne demande d'abord que la
        // date de dernière activité (0,4 s, l'index (organization_id,
        // created_at) suffit), on trie et on découpe en PHP, puis on ne
        // compte réellement que les associations de la page affichée.
        // Même résultat, 0,5 s au lieu de 11.
        //
        // LEFT JOIN et non filtre : une association sans aucune activité
        // doit apparaître, c'est précisément l'information qu'on cherche.

        // 1. Dernière activité connue, association par association.
        $derniere = [];
        foreach ($pdo->query("SELECT organization_id, MAX(created_at) AS d
                                FROM assokit_activity_log
                               WHERE created_at >= $borne AND organization_id IS NOT NULL
                               GROUP BY organization_id")->fetchAll(PDO::FETCH_NUM) as [$oid, $d]) {
            $derniere[(int) $oid] = $d;
        }

        // 2. Toutes les associations, triées : les plus récemment
        //    actives d'abord, les muettes ensuite par ordre alphabétique.
        $toutes = $pdo->query("SELECT id, name FROM organizations
                                WHERE deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($toutes as &$o) { $o['derniere'] = $derniere[(int) $o['id']] ?? null; }
        unset($o);
        usort($toutes, function ($a, $b) {
            if (($a['derniere'] === null) !== ($b['derniere'] === null)) {
                return $a['derniere'] === null ? 1 : -1;
            }
            if ($a['derniere'] !== null && $a['derniere'] !== $b['derniere']) {
                return strcmp($b['derniere'], $a['derniere']);
            }
            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        $assosTotal = count($toutes);
        $assosPages = max(1, (int) ceil($assosTotal / ASSOS_PAR_PAGE));
        $page       = min($page, $assosPages);
        $assos      = array_slice($toutes, ($page - 1) * ASSOS_PAR_PAGE, ASSOS_PAR_PAGE);

        // 3. Les compteurs, pour ces associations-là seulement.
        if ($assos) {
            $ids = array_map(fn($o) => (int) $o['id'], $assos);
            $ph  = implode(',', array_fill(0, count($ids), '?'));

            $st = $pdo->prepare("SELECT organization_id,
                        COUNT(DISTINCT user_id)      AS actifs,
                        SUM(event_type = 'login')    AS connexions,
                        SUM(event_type = 'action')   AS actions,
                        SUM(event_type = 'pageview') AS pages
                     FROM assokit_activity_log
                     WHERE organization_id IN ($ph) AND created_at >= $borne
                     GROUP BY organization_id");
            $st->execute($ids);
            $compte = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $compte[(int) $r['organization_id']] = $r;

            // L'effectif était un sous-select corrélé exécuté une fois par
            // association, soit 10 000 fois. Une seule requête suffit.
            $st = $pdo->prepare("SELECT org_id, COUNT(*) AS n FROM users
                                  WHERE org_id IN ($ph) AND deleted_at IS NULL GROUP BY org_id");
            $st->execute($ids);
            $effectif = [];
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$oid, $n]) $effectif[(int) $oid] = (int) $n;

            foreach ($assos as &$a) {
                $c = $compte[(int) $a['id']] ?? [];
                $a['actifs']     = (int) ($c['actifs'] ?? 0);
                $a['connexions'] = (int) ($c['connexions'] ?? 0);
                $a['actions']    = (int) ($c['actions'] ?? 0);
                $a['pages']      = (int) ($c['pages'] ?? 0);
                $a['effectif']   = $effectif[(int) $a['id']] ?? 0;
            }
            unset($a);
        }
    }
} catch (Throwable $e) {
    error_log('fondateur-connexions données : ' . $e->getMessage());
    $erreur = $e->getMessage();
}

/** Couleur d'un type d'événement, reprise de /fondateur-activity. */
function teinte(string $t): string
{
    return match ($t) {
        'login' => '#10b981', 'login_failed' => '#ef4444', 'logout' => '#94a3b8',
        'action' => '#818cf8', 'pageview' => '#475569', default => '#64748b',
    };
}
function libelle_type(string $t): string
{
    return ['login' => 'Connexion', 'login_failed' => 'Échec de connexion',
            'logout' => 'Déconnexion', 'action' => 'Action', 'pageview' => 'Page'][$t] ?? $t;
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Connexions et usage · Fondateur</title>
<style>
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
       background: #0f172a; color: #f1f5f9; margin: 0; padding: 24px; }
.wrap { max-width: 1400px; margin: 0 auto; }
a { color: #a5b4fc; }
h1 { margin: 0 0 6px; font-size: 25px; }
.sub { color: #64748b; font-size: 14px; margin: 0; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
.btn { padding: 8px 15px; background: #1e293b; border: 1px solid #334155; color: #e2e8f0;
       border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-block; }
.btn:hover { background: #334155; }
.btn.acc { background: #4f46e5; border-color: #4f46e5; color: #fff; font-weight: 600; }
.btn.acc:hover { background: #4338ca; }

.fil { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 18px; font-size: 13px; color: #64748b; }
.fil a { text-decoration: none; }

.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 13px; margin-bottom: 20px; }
.card { background: linear-gradient(135deg, #1e293b, #1a2238); border: 1px solid #334155; border-radius: 12px; padding: 16px; }
.card .lab { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: .05em; font-weight: 600; margin-bottom: 7px; }
.card .val { font-size: 26px; font-weight: 700; }
.card.vert { border-color: #10b981; }

.periodes { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 18px; }
.periodes a { padding: 7px 13px; border-radius: 999px; background: #1e293b; border: 1px solid #334155;
              color: #cbd5e1; text-decoration: none; font-size: 13px; }
.periodes a.on { background: #4f46e5; border-color: #4f46e5; color: #fff; font-weight: 600; }

.bloc { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 18px; margin-bottom: 18px; }
.bloc h2 { margin: 0 0 14px; font-size: 15px; }
/* Un tableau large glisse au doigt dans son cadre, au lieu de pousser
   toute la page et de créer un défilement horizontal du document. */
.tableau { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.tableau table { min-width: 540px; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
     padding: 0 10px 9px; font-weight: 600; white-space: nowrap; }
td { padding: 10px; border-top: 1px solid #293548; vertical-align: middle; }
tr:hover td { background: #22304a; }
td.num { text-align: right; font-variant-numeric: tabular-nums; }
.nom { font-weight: 600; color: #f1f5f9; text-decoration: none; }
.nom:hover { color: #a5b4fc; }
.mail { color: #64748b; font-size: 12px; }
.muet { color: #475569; }
.pagination { display: flex; align-items: center; justify-content: space-between;
  gap: 14px; margin-top: 14px; font-size: 13px; flex-wrap: wrap; }
.pagination a { color: #93c5fd; text-decoration: none; padding: 7px 13px;
  border: 1px solid #334155; border-radius: 8px; }
.pagination a:hover { background: #1e293b; }
.pagination span.muet { padding: 7px 0; }
.puce { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
.enligne { background: rgba(16,185,129,.16); color: #34d399; }

/* Déroulé minute par minute */
.jour { margin-top: 16px; font-size: 12px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em; }
.ev { display: flex; gap: 12px; align-items: baseline; padding: 6px 0; border-top: 1px solid #22304a; }
.ev:first-of-type { border-top: 0; }
.ev .h { font-variant-numeric: tabular-nums; color: #64748b; font-size: 12px; white-space: nowrap; min-width: 62px; }
.ev .t { font-size: 11px; font-weight: 700; white-space: nowrap; min-width: 118px; }
.ev .q { min-width: 0; word-break: break-word; font-size: 13px; }
.ev .meta { color: #475569; font-size: 11.5px; }
.vide { color: #64748b; padding: 22px 0; text-align: center; }
@media (max-width: 700px) {
  body { padding: 14px; }
  .ev { flex-wrap: wrap; gap: 6px; }
  /* Les colonnes ne sont plus masquées : le tableau défile. Cacher
     « Connexions » ou « Actions » sur téléphone aurait retiré
     précisément les chiffres qu'on vient consulter. */
  th, td { padding-left: 8px; padding-right: 8px; }
}
</style>
</head>
<body>
<div class="wrap">

  <div class="head">
    <div>
      <h1>Connexions et usage</h1>
      <p class="sub">Qui se connecte, quand, et ce qu’il fait — association par association.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn" href="/fondateur-activity">Journal brut</a>
      <a class="btn" href="/fondateur-pilotage">← Pilotage</a>
    </div>
  </div>

  <?php if (!empty($erreur)): ?>
    <div class="bloc" style="border-color:#7f1d1d;background:#2a1414">
      Impossible de lire le journal : <?= hc($erreur) ?>
    </div>
  <?php endif; ?>

  <div class="periodes">
    <?php foreach ($PERIODES as $k => $lab): ?>
      <a href="<?= hc($lien(['p' => $k])) ?>" class="<?= $periode === $k ? 'on' : '' ?>"><?= hc($lab) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="cards">
    <div class="card vert"><div class="lab">En ligne maintenant</div><div class="val"><?= $tot['enligne'] ?></div></div>
    <div class="card"><div class="lab">Connexions</div><div class="val"><?= number_format($tot['connexions'], 0, ',', ' ') ?></div></div>
    <div class="card"><div class="lab">Membres actifs</div><div class="val"><?= $tot['membres'] ?></div></div>
    <div class="card"><div class="lab">Associations actives</div><div class="val"><?= $tot['assos'] ?></div></div>
    <div class="card"><div class="lab">Actions</div><div class="val"><?= number_format($tot['actions'], 0, ',', ' ') ?></div></div>
  </div>

  <?php // Fil d'Ariane : sans lui on ne sait plus à quel niveau on se trouve. ?>
  <?php if ($vueOrg > 0 || $vueUser > 0): ?>
    <div class="fil">
      <a href="<?= hc($lien(['org' => '', 'user' => ''])) ?>">Toutes les associations</a>
      <?php if ($orgNom !== ''): ?>
        <span>›</span>
        <?php if ($vueUser > 0): ?>
          <a href="<?= hc($lien(['user' => ''])) ?>"><?= hc($orgNom) ?></a>
          <span>›</span><span style="color:#cbd5e1"><?= hc(trim(($fiche['first_name'] ?? '') . ' ' . ($fiche['last_name'] ?? ''))) ?></span>
        <?php else: ?>
          <span style="color:#cbd5e1"><?= hc($orgNom) ?></span>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

<?php if ($vueUser > 0 && $fiche): ?>
  <?php // ============ NIVEAU 3 : un membre ============ ?>
  <div class="bloc">
    <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-start">
      <div>
        <h2 style="margin:0 0 4px;font-size:18px"><?= hc(trim($fiche['first_name'] . ' ' . $fiche['last_name'])) ?></h2>
        <div class="mail"><?= hc($fiche['email']) ?> · <?= hc($fiche['role']) ?> · <?= hc($orgNom) ?></div>
        <div class="mail" style="margin-top:4px">Dernière connexion enregistrée : <?= hc(depuis($fiche['last_login_at'] ?? null)) ?></div>
      </div>
      <?php // Pour corriger quelque chose sur ce compte, on passe par
            // l'incarnation : elle exige un motif, expire seule et laisse sa
            // propre trace. Un raccourci d'édition ici contournerait tout cela. ?>
      <a class="btn acc" href="/super-admin/incarner?q=<?= urlencode((string) $fiche['email']) ?>">
        Intervenir sur ce compte
      </a>
    </div>
  </div>

  <div class="bloc">
    <h2>Sessions (<?= count($sessions) ?>)</h2>
    <?php if (!$sessions): ?>
      <div class="vide">Aucune session sur la période.</div>
    <?php else: ?>
      <div class="tableau">
      <table>
        <tr><th>Début</th><th>Fin</th><th class="num">Durée</th><th class="num">Pages</th><th class="num">Actions</th><th>Adresse IP</th></tr>
        <?php foreach ($sessions as $s):
              $d = strtotime((string) $s['debut']); $f = strtotime((string) $s['fin']); ?>
        <tr>
          <td><?= date('d/m/Y', $d) ?> à <strong><?= date('H:i', $d) ?></strong></td>
          <td><?= date('H:i', $f) ?></td>
          <td class="num"><?= hc(duree((int) $s['duree_s'])) ?></td>
          <td class="num"><?= (int) $s['pages'] ?></td>
          <td class="num"><?= (int) $s['actions'] ?></td>
          <td class="mail"><?= hc($s['ip']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="bloc">
    <h2>Déroulé minute par minute</h2>
    <?php if (!$timeline): ?>
      <div class="vide">Rien sur la période choisie.</div>
    <?php else: $jour = ''; foreach ($timeline as $e):
        $t = strtotime((string) $e['created_at']);
        $j = date('Y-m-d', $t);
        if ($j !== $jour) { $jour = $j;
            echo '<div class="jour">' . hc(strftime_fr($t)) . '</div>'; } ?>
      <div class="ev">
        <span class="h"><?= date('H:i:s', $t) ?></span>
        <span class="t" style="color:<?= teinte((string) $e['event_type']) ?>"><?= hc(libelle_type((string) $e['event_type'])) ?></span>
        <span class="q">
          <?= hc($e['event_action'] ?? $e['path'] ?? '—') ?>
          <?php if (!empty($e['event_target'])): ?>
            <span class="meta">· <?= hc($e['event_target']) ?></span>
          <?php endif; ?>
          <?php
            // Le méta est du JSON : on n'en montre que ce qui aide à
            // comprendre le geste, pas le dépotoir complet.
            $m = json_decode((string) ($e['event_meta'] ?? ''), true);
            if (is_array($m)) {
                $bouts = [];
                if (!empty($m['echec']))      $bouts[] = 'refusé (' . (int) ($m['http'] ?? 0) . ')';
                if (isset($m['ms']))          $bouts[] = $m['ms'] . ' ms';
                foreach ($m as $k => $v) {
                    if (in_array($k, ['echec', 'http', 'ms'], true) || is_array($v)) continue;
                    if (count($bouts) >= 5) break;
                    $bouts[] = $k . ' : ' . mb_substr((string) $v, 0, 40);
                }
                if ($bouts) echo '<span class="meta"> · ' . hc(implode(' · ', $bouts)) . '</span>';
            }
          ?>
        </span>
      </div>
    <?php endforeach; endif; ?>
  </div>

<?php elseif ($vueOrg > 0): ?>
  <?php // ============ NIVEAU 2 : les membres ============ ?>
  <div class="bloc">
    <h2><?= hc($orgNom) ?> — <?= count($membres) ?> membre<?= count($membres) > 1 ? 's' : '' ?></h2>
    <?php if (!$membres): ?>
      <div class="vide">Aucun membre dans cette association.</div>
    <?php else: ?>
      <div class="tableau">
      <table>
        <tr><th>Membre</th><th>Rôle</th><th>Dernière activité</th><th class="num">Connexions</th>
            <th class="num">Sessions</th><th class="num">Pages</th><th class="num">Actions</th></tr>
        <?php foreach ($membres as $m):
              $jamais = empty($m['derniere']); ?>
        <tr>
          <td>
            <a class="nom" href="<?= hc($lien(['user' => (int) $m['id']])) ?>"><?= hc(trim($m['first_name'] . ' ' . $m['last_name'])) ?></a>
            <div class="mail"><?= hc($m['email']) ?></div>
          </td>
          <td class="mail"><?= hc($m['role']) ?></td>
          <td class="<?= $jamais ? 'muet' : '' ?>"><?= hc(depuis($m['derniere'] ?? null)) ?></td>
          <td class="num"><?= (int) $m['connexions'] ?></td>
          <td class="num"><?= (int) $m['sessions'] ?></td>
          <td class="num"><?= (int) $m['pages'] ?></td>
          <td class="num"><?= (int) $m['actions'] ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      </div>
    <?php endif; ?>
  </div>

<?php else: ?>
  <?php // ============ NIVEAU 1 : les associations ============ ?>
  <div class="bloc">
    <h2><?= number_format($assosTotal, 0, ',', ' ') ?> association<?= $assosTotal > 1 ? 's' : '' ?><?php
      if ($assosPages > 1): ?> <span class="mail">— page <?= $page ?> sur <?= $assosPages ?></span><?php
      endif; ?></h2>
    <?php if (!$assos): ?>
      <div class="vide">Aucune association.</div>
    <?php else: ?>
      <div class="tableau">
      <table>
        <tr><th>Association</th><th>Dernière activité</th><th class="num">Membres actifs</th>
            <th class="num">Connexions</th><th class="num">Pages</th><th class="num">Actions</th></tr>
        <?php foreach ($assos as $a):
              $jamais = empty($a['derniere']); ?>
        <tr>
          <td><a class="nom" href="<?= hc($lien(['org' => (int) $a['id'], 'user' => ''])) ?>"><?= hc($a['name']) ?></a></td>
          <td class="<?= $jamais ? 'muet' : '' ?>"><?= hc(depuis($a['derniere'] ?? null)) ?></td>
          <td class="num"><?= (int) $a['actifs'] ?> <span class="mail">/ <?= (int) $a['effectif'] ?></span></td>
          <td class="num"><?= (int) $a['connexions'] ?></td>
          <td class="num"><?= (int) $a['pages'] ?></td>
          <td class="num"><?= (int) $a['actions'] ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      </div>
      <?php if ($assosPages > 1):
        // Lien de pagination : mêmes paramètres, page différente.
        $lienPage = fn(int $n) => '/fondateur-connexions?' . http_build_query(['p' => $periode, 'page' => $n]);
      ?>
      <div class="pagination">
        <?php if ($page > 1): ?>
          <a href="<?= hc($lienPage($page - 1)) ?>">← Précédentes</a>
        <?php else: ?><span class="muet">← Précédentes</span><?php endif; ?>
        <span class="muet">
          <?= number_format(($page - 1) * ASSOS_PAR_PAGE + 1, 0, ',', ' ') ?>
          – <?= number_format(($page - 1) * ASSOS_PAR_PAGE + count($assos), 0, ',', ' ') ?>
          sur <?= number_format($assosTotal, 0, ',', ' ') ?>
        </span>
        <?php if ($page < $assosPages): ?>
          <a href="<?= hc($lienPage($page + 1)) ?>">Suivantes →</a>
        <?php else: ?><span class="muet">Suivantes →</span><?php endif; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

</div>
</body>
</html>
