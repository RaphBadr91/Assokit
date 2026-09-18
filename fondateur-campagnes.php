<?php
/**
 * fondateur-campagnes.php — Composer et lancer une campagne e-mail.
 * ------------------------------------------------------------------
 * Objet, corps, pièce jointe, liste de destinataires. Réservé au
 * Fondateur.
 *
 * La page ne fait jamais partir la masse elle-même : elle prépare la
 * file (une ligne par destinataire) et c'est le cron qui l'écoule par
 * lots. Sans cela, lancer une campagne de 20 000 adresses depuis un
 * navigateur reviendrait à attendre plusieurs heures sur une page qui
 * finirait coupée par le serveur, au milieu, sans savoir où.
 *
 * Seul l'envoi de TEST part directement : un seul message, vers soi.
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_once __DIR__ . '/superadmin-layout.php';
require_once __DIR__ . '/sa-permissions.php';
require_once __DIR__ . '/fondateur-emailing-helpers.php';
@require_once __DIR__ . '/resend-helper.php';

require_login();
$user = sa_require_super_admin();
$ctx  = sa_get_permissions_context();
if (empty($ctx['is_founder'])) {
    http_response_code(403);
    sa_render_head('Accès refusé'); sa_render_sidebar('fondateur-pilotage');
    echo '<div style="max-width:560px;margin:50px auto;text-align:center;padding:34px;'
       . 'background:var(--sa-bg-2);border:1px solid var(--sa-border);border-radius:16px;">'
       . '<div style="font-size:44px;">🏗️</div><h1>Réservé au Fondateur</h1>'
       . '<a href="/super-admin" class="sa-btn sa-btn-ghost" style="margin-top:16px;">← Retour</a></div>';
    sa_render_foot(); exit;
}

ak_prospect_tables_ensure($pdo);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];

$flash = $_SESSION['flash_campagnes'] ?? null;
unset($_SESSION['flash_campagnes']);

$migration_ok = true;
try { $pdo->query("SELECT 1 FROM fond_campagnes LIMIT 1")->closeCursor(); }
catch (Throwable $e) { $migration_ok = false; }

// ── Écritures ───────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $migration_ok) {
    if (!hash_equals($CSRF, (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(419); exit('Jeton CSRF invalide.');
    }
    $action = (string) ($_POST['action'] ?? '');
    $cid    = (int) ($_POST['campagne_id'] ?? 0);
    $msg    = null;
    $vers   = '/fondateur-campagnes';

    try {
        if ($action === 'creer') {
            $nom    = trim((string) ($_POST['nom'] ?? ''));
            $sujet  = trim((string) ($_POST['sujet'] ?? ''));
            $corps  = trim((string) ($_POST['corps_html'] ?? ''));
            $liste  = (int) ($_POST['liste_id'] ?? 0);

            $st = $pdo->prepare("SELECT COUNT(*) FROM fond_listes WHERE id = ?");
            $st->execute([$liste]);

            if ($nom === '' || $sujet === '' || $corps === '') {
                $msg = "Nom, objet et corps du message sont nécessaires.";
            } elseif (!(int) $st->fetchColumn()) {
                $msg = "Choisissez une liste de destinataires.";
            } else {
                $pj = ak_camp_pj_ranger($_FILES['piece_jointe'] ?? []);
                if (!$pj['ok']) {
                    $msg = $pj['message'];
                } else {
                    $pdo->prepare("INSERT INTO fond_campagnes
                            (nom, liste_id, sujet, corps_html, pj_nom, pj_fichier, pj_taille, pj_type, cree_par)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([mb_substr($nom, 0, 160), $liste, mb_substr($sujet, 0, 255), $corps,
                                   $pj['nom'] ?? null, $pj['fichier'] ?? null,
                                   $pj['taille'] ?? null, $pj['type'] ?? null, (int) $user['id']]);
                    $cid = (int) $pdo->lastInsertId();
                    $msg = "Campagne créée en brouillon. Faites un envoi de test avant de la lancer.";
                    $vers .= '?c=' . $cid;
                }
            }

        } elseif ($action === 'test' && $cid > 0) {
            $dest = trim((string) ($_POST['email_test'] ?? ''));
            $st = $pdo->prepare("SELECT * FROM fond_campagnes WHERE id = ?");
            $st->execute([$cid]);
            $camp = $st->fetch(PDO::FETCH_ASSOC);

            if (!$camp) {
                $msg = "Campagne introuvable.";
            } elseif (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
                $msg = "Adresse de test invalide.";
            } else {
                // Contact fictif : le test ne doit pas créer de ligne en
                // base ni compter dans les statistiques. L'id 0 rend le
                // lien de désinscription inopérant, ce qui est correct
                // pour un test — mais le pied de page reste visible,
                // c'est justement ce qu'on veut relire.
                $faux = ['id' => 0, 'email' => $dest, 'name' => 'Prénom Nom',
                         'org_name' => 'Association exemple', 'city' => 'Évry', 'status' => 'new'];
                $r = ak_camp_envoyer($pdo, $camp, $faux, true);
                $msg = $r['statut'] === 'envoye'
                    ? "Test envoyé à $dest. Vérifiez l'objet, la mise en page, la pièce jointe et le pied de page."
                    : "Échec du test : " . ($r['erreur'] ?? 'raison inconnue');
            }
            $vers .= '?c=' . $cid;

        } elseif ($action === 'lancer' && $cid > 0) {
            $st = $pdo->prepare("SELECT * FROM fond_campagnes WHERE id = ?");
            $st->execute([$cid]);
            $camp = $st->fetch(PDO::FETCH_ASSOC);

            if (!$camp) {
                $msg = "Campagne introuvable.";
            } elseif ($camp['statut'] !== 'brouillon' && $camp['statut'] !== 'pause') {
                $msg = "Cette campagne est déjà " . $camp['statut'] . ".";
            } else {
                // On remplit la file, une ligne par destinataire. Les
                // désinscrits sont marqués « ignore » dès maintenant :
                // ils apparaissent ainsi dans le compte rendu au lieu de
                // disparaître silencieusement.
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("INSERT IGNORE INTO fond_campagne_envois
                                    (campagne_id, prospect_id, email, statut, erreur)
                                   SELECT ?, p.id, p.email,
                                          CASE WHEN p.status IN ('unsubscribed','bounced') THEN 'ignore' ELSE 'attente' END,
                                          CASE WHEN p.status IN ('unsubscribed','bounced') THEN 'désinscrit ou adresse en erreur' ELSE NULL END
                                     FROM fond_liste_membres m
                                     JOIN asso_prospects p ON p.id = m.prospect_id
                                    WHERE m.liste_id = ?")
                        ->execute([$cid, (int) $camp['liste_id']]);

                    $st = $pdo->prepare("SELECT
                            COUNT(*) total,
                            SUM(statut = 'ignore') ignores
                          FROM fond_campagne_envois WHERE campagne_id = ?");
                    $st->execute([$cid]);
                    $c = $st->fetch(PDO::FETCH_ASSOC);

                    $pdo->prepare("UPDATE fond_campagnes
                                   SET statut = 'en_cours', total = ?, ignores = ?,
                                       demarree_at = IFNULL(demarree_at, NOW())
                                   WHERE id = ?")
                        ->execute([(int) $c['total'], (int) $c['ignores'], $cid]);
                    $pdo->commit();

                    $aFaire = (int) $c['total'] - (int) $c['ignores'];
                    $msg = "Campagne lancée : $aFaire destinataire" . ($aFaire > 1 ? 's' : '')
                         . ($c['ignores'] ? " (" . (int) $c['ignores'] . " désinscrit(s) écarté(s))" : '')
                         . ". L'envoi se fait par lots, en arrière-plan.";
                } catch (Throwable $e) {
                    $pdo->rollBack(); throw $e;
                }
            }
            $vers .= '?c=' . $cid;

        } elseif ($action === 'pause' && $cid > 0) {
            $pdo->prepare("UPDATE fond_campagnes SET statut = 'pause' WHERE id = ? AND statut = 'en_cours'")
                ->execute([$cid]);
            $msg = "Campagne mise en pause. Les envois déjà partis ne reviennent pas ; le reste attend.";
            $vers .= '?c=' . $cid;

        } elseif ($action === 'supprimer' && $cid > 0) {
            $st = $pdo->prepare("SELECT pj_fichier, statut FROM fond_campagnes WHERE id = ?");
            $st->execute([$cid]);
            $camp = $st->fetch(PDO::FETCH_ASSOC);
            if ($camp && $camp['statut'] === 'en_cours') {
                $msg = "Mettez d'abord la campagne en pause.";
                $vers .= '?c=' . $cid;
            } elseif ($camp) {
                $pj = ak_camp_pj_chemin($camp['pj_fichier'] ?? null);
                if ($pj) @unlink($pj);
                $pdo->prepare("DELETE FROM fond_campagne_envois WHERE campagne_id = ?")->execute([$cid]);
                $pdo->prepare("DELETE FROM fond_campagnes WHERE id = ?")->execute([$cid]);
                $msg = "Campagne supprimée.";
            }
        }
    } catch (Throwable $e) {
        $msg = "Échec : " . $e->getMessage();
    }

    $_SESSION['flash_campagnes'] = $msg;
    header('Location: ' . $vers);
    exit;
}

// ── Lecture ─────────────────────────────────────────────────────────
$campagnes = $listes = [];
$detail = null;
$vue = (int) ($_GET['c'] ?? 0);
$plafondJour = defined('AK_PROSPECT_DAILY_CAP') ? (int) AK_PROSPECT_DAILY_CAP : 40;
$dejaAujourdhui = 0;
$envoiActif = defined('AK_PROSPECT_SENDING_ENABLED') && AK_PROSPECT_SENDING_ENABLED;

if ($migration_ok) {
    $listes = $pdo->query("SELECT l.id, l.nom,
                (SELECT COUNT(*) FROM fond_liste_membres m WHERE m.liste_id = l.id) AS effectif
              FROM fond_listes l ORDER BY l.nom")->fetchAll(PDO::FETCH_ASSOC);
    $campagnes = $pdo->query("SELECT c.*, l.nom AS liste_nom
              FROM fond_campagnes c LEFT JOIN fond_listes l ON l.id = c.liste_id
              ORDER BY c.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $dejaAujourdhui = ak_camp_envoyes_aujourdhui($pdo);

    if ($vue > 0) {
        $st = $pdo->prepare("SELECT c.*, l.nom AS liste_nom
                             FROM fond_campagnes c LEFT JOIN fond_listes l ON l.id = c.liste_id
                             WHERE c.id = ?");
        $st->execute([$vue]);
        $detail = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($detail) {
            $st = $pdo->prepare("SELECT statut, COUNT(*) n FROM fond_campagne_envois
                                 WHERE campagne_id = ? GROUP BY statut");
            $st->execute([$vue]);
            $detail['file'] = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $detail['file'][$r['statut']] = (int) $r['n'];
        }
    }
}

$STATUTS = ['brouillon' => 'Brouillon', 'en_cours' => 'En cours',
            'pause' => 'En pause', 'terminee' => 'Terminée'];

sa_render_head('Campagnes e-mail');
sa_render_sidebar('fondateur-campagnes');
?>
<style>
.fc-wrap{max-width:1100px;margin:0 auto}
.fc-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:6px}
.fc-sub{color:var(--sa-text-2,#94a3b8);font-size:13.5px;margin:0 0 20px;line-height:1.6;max-width:70ch}
.fc-flash{background:var(--sa-bg-2);border:1px solid var(--sa-border);border-left:3px solid #10B981;
  border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13.5px}
.fc-alerte{background:#422006;border:1px solid #a16207;border-radius:10px;padding:13px 16px;
  margin-bottom:18px;font-size:13.5px;color:#fde68a;line-height:1.6}
.fc-card{background:var(--sa-bg-2);border:1px solid var(--sa-border);border-radius:14px;padding:20px;margin-bottom:18px}
.fc-card h2{font-size:15px;margin:0 0 4px}
.fc-card .aide{font-size:12.5px;color:var(--sa-text-2,#94a3b8);margin:0 0 14px;line-height:1.6}
.fc-in{width:100%;padding:9px 12px;border-radius:9px;border:1px solid var(--sa-border);
  background:var(--sa-bg);color:inherit;font:inherit}
textarea.fc-in{min-height:220px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;line-height:1.6}
label.fc-lab{display:block;font-size:12px;font-weight:600;margin-bottom:5px}
.fc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;margin-bottom:12px}
.fc-table{width:100%;border-collapse:collapse;font-size:13.5px}
.fc-table th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;
  color:var(--sa-text-2,#94a3b8);padding:8px 10px;border-bottom:1px solid var(--sa-border)}
.fc-table td{padding:10px;border-bottom:1px solid var(--sa-border)}
.fc-num{text-align:right;font-variant-numeric:tabular-nums}
.fc-bg{font-size:11px;font-weight:700;padding:2px 9px;border-radius:999px}
.fc-bg.brouillon{background:#334155;color:#cbd5e1}
.fc-bg.en_cours{background:#064e3b;color:#6ee7b7}
.fc-bg.pause{background:#422006;color:#fde68a}
.fc-bg.terminee{background:#1e3a8a;color:#bfdbfe}
.fc-vide{color:var(--sa-text-2,#94a3b8);font-size:13.5px;padding:18px 0}
.fc-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:14px}
.fc-jauge{height:8px;border-radius:999px;background:var(--sa-bg);overflow:hidden;margin:10px 0 6px}
.fc-jauge span{display:block;height:100%;background:#10B981}
.fc-var{font-size:12.5px;color:var(--sa-text-2,#94a3b8);line-height:1.8}
.fc-var code{background:var(--sa-bg);padding:1px 6px;border-radius:5px}
.fc-scroll{overflow-x:auto}
</style>

<div class="fc-wrap">
  <div class="fc-head">
    <h1 style="margin:0">Campagnes e-mail</h1>
    <a class="sa-btn sa-btn-ghost" href="/fondateur-listes">← Listes d’adresses</a>
  </div>
  <p class="fc-sub">
    Un objet, un message, une pièce jointe, une liste. La page prépare la file ;
    l’envoi part ensuite par lots, en arrière-plan, sans que vous ayez à laisser
    l’onglet ouvert.
  </p>

  <?php if ($flash): ?><div class="fc-flash"><?= h($flash) ?></div><?php endif; ?>

  <?php if (!$migration_ok): ?>
    <div class="fc-card">
      <h2>Migration à passer</h2>
      <p class="aide">En SSH :</p>
      <pre style="background:var(--sa-bg);padding:12px;border-radius:8px;overflow:auto;font-size:12.5px"><code>cd ~/public_html
php migrations/run.php 2026-09-18-fondateur-campagnes.sql</code></pre>
    </div>
  <?php else: ?>

  <?php if (!$envoiActif): ?>
    <div class="fc-alerte">
      <strong>Envoi réel désactivé.</strong> La constante <code>AK_PROSPECT_SENDING_ENABLED</code>
      vaut <code>false</code> dans <code>config.php</code> : les campagnes se préparent et se
      lancent, mais rien ne part. C’est le garde-fou de la prospection, laissé volontairement
      fermé tant que le domaine d’envoi n’est pas chauffé. Passez-le à <code>true</code> quand
      vous êtes prêt.
    </div>
  <?php endif; ?>

  <div class="fc-alerte" style="background:var(--sa-bg-2);border-color:var(--sa-border);color:inherit">
    <strong>Plafond du jour : <?= $dejaAujourdhui ?> / <?= $plafondJour ?> envois.</strong>
    Campagnes et séquence de prospection puisent au même plafond — c’est la réputation du
    domaine qui est en jeu, et elle ne fait pas la différence. Une campagne plus grande que le
    plafond s’étale simplement sur plusieurs jours, sans rien perdre.
    Pour monter, augmentez <code>AK_PROSPECT_DAILY_CAP</code> progressivement.
  </div>

  <div class="fc-card">
    <h2>Nouvelle campagne</h2>
    <p class="aide">Elle est créée en brouillon : rien ne part tant que vous n’avez pas cliqué « Lancer ».</p>
    <?php if (!$listes): ?>
      <p class="fc-vide">Créez d’abord une <a href="/fondateur-listes">liste d’adresses</a>.</p>
    <?php else: ?>
    <form method="post" action="/fondateur-campagnes" enctype="multipart/form-data">
      <input type="hidden" name="action" value="creer">
      <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
      <div class="fc-grid">
        <div><label class="fc-lab">Nom interne</label>
          <input class="fc-in" name="nom" required maxlength="160" placeholder="Rentrée 2026 — assos sportives"></div>
        <div><label class="fc-lab">Liste de destinataires</label>
          <select class="fc-in" name="liste_id" required>
            <option value="">— choisir —</option>
            <?php foreach ($listes as $l): ?>
              <option value="<?= (int) $l['id'] ?>"><?= h($l['nom']) ?> (<?= (int) $l['effectif'] ?>)</option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <label class="fc-lab">Objet du message</label>
      <input class="fc-in" name="sujet" required maxlength="255" style="margin-bottom:12px"
             placeholder="Gérer votre association sans y passer vos dimanches">
      <label class="fc-lab">Corps du message (HTML)</label>
      <textarea class="fc-in" name="corps_html" required
                placeholder="&lt;p&gt;Bonjour {{prenom}},&lt;/p&gt;&#10;&lt;p&gt;…&lt;/p&gt;"></textarea>
      <p class="fc-var" style="margin:8px 0 12px">
        Variables remplacées à l’envoi :
        <code>{{prenom}}</code> <code>{{nom}}</code> <code>{{association}}</code>
        <code>{{ville}}</code> <code>{{email}}</code><br>
        Le pied de page légal — votre identité et le lien de désinscription — est ajouté
        automatiquement. Il n’est pas optionnel : sans lui, l’envoi est en infraction et les
        messageries classent en indésirable.
      </p>
      <label class="fc-lab">Pièce jointe <span style="font-weight:400">(facultative, <?= round(AK_CAMP_PJ_MAX / 1048576) ?> Mo max)</span></label>
      <input class="fc-in" type="file" name="piece_jointe"
             accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.doc,.docx,.xls,.xlsx,.csv,.ppt,.pptx,.odt,.ods,.txt">
      <div class="fc-actions"><button class="sa-btn" type="submit">Créer le brouillon</button></div>
    </form>
    <?php endif; ?>
  </div>

  <?php if ($detail): ?>
    <?php
      $f = $detail['file'] ?? [];
      $envoyes = (int) ($f['envoye'] ?? 0);
      $attente = (int) ($f['attente'] ?? 0);
      $echecs  = (int) ($f['echec'] ?? 0);
      $ignores = (int) ($f['ignore'] ?? 0);
      $totalF  = $envoyes + $attente + $echecs + $ignores;
      $pct     = $totalF > 0 ? round(100 * ($envoyes + $echecs + $ignores) / $totalF) : 0;
    ?>
    <div class="fc-card">
      <h2><?= h($detail['nom']) ?>
        <span class="fc-bg <?= h($detail['statut']) ?>"><?= h(strtoupper($STATUTS[$detail['statut']] ?? $detail['statut'])) ?></span>
      </h2>
      <p class="aide">
        <?php // L'objet tel qu'il partira, variables remplacées : c'est
              // là qu'un {{association}} oublié se voit, et c'est la
              // première chose que lit le destinataire. ?>
        Objet : <strong><?= h(ak_camp_sujet((string) $detail['sujet'], [
            'name' => 'Marie Durand', 'org_name' => 'Les Amis du Parc',
            'city' => 'Évry', 'email' => 'exemple@association.fr'])) ?></strong><br>
        Liste : <?= h($detail['liste_nom'] ?? '—') ?>
        <?php if (!empty($detail['pj_nom'])): ?>
          · Pièce jointe : <?= h($detail['pj_nom']) ?> (<?= round(((int) $detail['pj_taille']) / 1024) ?> Ko)
        <?php endif; ?>
      </p>

      <?php if ($totalF > 0): ?>
        <div class="fc-jauge"><span style="width:<?= $pct ?>%"></span></div>
        <p class="fc-var">
          <?= $envoyes ?> envoyé<?= $envoyes > 1 ? 's' : '' ?> ·
          <?= $attente ?> en attente ·
          <?= $echecs ?> échec<?= $echecs > 1 ? 's' : '' ?> ·
          <?= $ignores ?> écarté<?= $ignores > 1 ? 's' : '' ?> (désinscrits)
        </p>
      <?php endif; ?>

      <?php // L'envoi de test d'abord : c'est l'étape qu'on saute et
            // qu'on regrette. Il part tout de suite, vers une seule
            // adresse, et ne compte dans aucune statistique. ?>
      <form method="post" action="/fondateur-campagnes" style="margin-top:16px">
        <input type="hidden" name="action" value="test">
        <input type="hidden" name="campagne_id" value="<?= (int) $detail['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
        <label class="fc-lab">Envoi de test — relisez tout avant de lancer</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <input class="fc-in" type="email" name="email_test" required style="flex:1;min-width:220px"
                 value="<?= h($user['email'] ?? '') ?>">
          <button class="sa-btn sa-btn-ghost" type="submit">Envoyer un test</button>
        </div>
      </form>

      <div class="fc-actions">
        <?php if (in_array($detail['statut'], ['brouillon', 'pause'], true)): ?>
          <form method="post" action="/fondateur-campagnes" style="display:inline"
                onsubmit="return confirm('Lancer « <?= h($detail['nom']) ?> » ? Les envois partiront par lots et ne pourront pas être rappelés.')">
            <input type="hidden" name="action" value="lancer">
            <input type="hidden" name="campagne_id" value="<?= (int) $detail['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
            <button class="sa-btn" type="submit"><?= $detail['statut'] === 'pause' ? 'Reprendre l’envoi' : 'Lancer la campagne' ?></button>
          </form>
        <?php endif; ?>
        <?php if ($detail['statut'] === 'en_cours'): ?>
          <form method="post" action="/fondateur-campagnes" style="display:inline">
            <input type="hidden" name="action" value="pause">
            <input type="hidden" name="campagne_id" value="<?= (int) $detail['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
            <button class="sa-btn sa-btn-ghost" type="submit">Mettre en pause</button>
          </form>
        <?php endif; ?>
        <?php if ($detail['statut'] !== 'en_cours'): ?>
          <form method="post" action="/fondateur-campagnes" style="display:inline"
                onsubmit="return confirm('Supprimer cette campagne et son historique d’envoi ?')">
            <input type="hidden" name="action" value="supprimer">
            <input type="hidden" name="campagne_id" value="<?= (int) $detail['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
            <button class="sa-btn sa-btn-ghost" type="submit">Supprimer</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="fc-card">
      <h2>Aperçu du message</h2>
      <p class="aide">Rendu avec un contact d’exemple, pied de page compris.</p>
      <div style="background:#fff;color:#111;border-radius:10px;padding:20px;overflow:auto;max-height:520px">
        <?= ak_camp_corps((string) $detail['corps_html'], [
              'id' => 0, 'email' => 'exemple@association.fr', 'name' => 'Marie Durand',
              'org_name' => 'Les Amis du Parc', 'city' => 'Évry']) ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="fc-card">
    <h2><?= count($campagnes) ?> campagne<?= count($campagnes) > 1 ? 's' : '' ?></h2>
    <?php if (!$campagnes): ?>
      <p class="fc-vide">Aucune campagne pour l’instant.</p>
    <?php else: ?>
      <div class="fc-scroll">
      <table class="fc-table">
        <tr><th>Campagne</th><th>Liste</th><th>État</th>
            <th class="fc-num">Envoyés</th><th class="fc-num">Total</th><th></th></tr>
        <?php foreach ($campagnes as $c): ?>
          <tr>
            <td><a href="/fondateur-campagnes?c=<?= (int) $c['id'] ?>" style="font-weight:600"><?= h($c['nom']) ?></a>
              <div style="font-size:12.5px;color:var(--sa-text-2,#94a3b8)"><?= h($c['sujet']) ?></div></td>
            <td><?= h($c['liste_nom'] ?? '—') ?></td>
            <td><span class="fc-bg <?= h($c['statut']) ?>"><?= h($STATUTS[$c['statut']] ?? $c['statut']) ?></span></td>
            <td class="fc-num"><?= number_format((int) $c['envoyes'], 0, ',', ' ') ?></td>
            <td class="fc-num"><?= number_format((int) $c['total'], 0, ',', ' ') ?></td>
            <td style="text-align:right">
              <a class="sa-btn sa-btn-ghost" href="/fondateur-campagnes?c=<?= (int) $c['id'] ?>">Ouvrir</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
      </div>
    <?php endif; ?>
  </div>

  <?php endif; ?>
</div>
<?php sa_render_foot(); ?>
