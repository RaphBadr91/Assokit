<?php
/**
 * communication.php — Hub du module Communication (V2/V3)
 * =========================================================
 * Modifs V2/V3 :
 *   - Onglet DIFFUSER : liste des broadcasts + bouton Nouveau
 *   - Onglet EVENEMENTS : liste des events + bouton Nouveau
 *   - Onglet AFFICHES : reporte en V5 (bloc "Bientot")
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-layout.php';
require_login();
require_capability('access_marketing');

$user_id = (int) $_SESSION['user_id'];
$org_id  = (int) $_SESSION['org_id'];
$tab     = $_GET['tab'] ?? 'rediger';
if (!in_array($tab, ['rediger', 'diffuser', 'evenements', 'affiches', 'bibliotheque'], true)) {
    $tab = 'rediger';
}

// --- Flash message ---
$flash = $_SESSION['flash_communication'] ?? null;
unset($_SESSION['flash_communication']);

// --- Stats rapides ---
$stmt = $pdo->prepare("SELECT COUNT(*) FROM communication_campaigns WHERE org_id = ? AND status = 'sent'");
$stmt->execute([$org_id]);
$nb_sent = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM communication_saved_templates WHERE org_id = ?");
$stmt->execute([$org_id]);
$nb_templates = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM communication_campaigns WHERE org_id = ? AND ai_generated = 1");
$stmt->execute([$org_id]);
$nb_ai = (int) $stmt->fetchColumn();

// Dernières générations (pour la reprise rapide)
$recent_campaigns = [];
try {
    $stmt = $pdo->prepare("SELECT id, title, status, created_at FROM communication_campaigns WHERE org_id = ? ORDER BY created_at DESC LIMIT 3");
    $stmt->execute([$org_id]);
    $recent_campaigns = $stmt->fetchAll();
} catch (Throwable $e) { $recent_campaigns = []; }

// Stats V2 diffusion
$nb_broadcasts = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM communication_broadcasts WHERE org_id = ? AND status = 'sent'");
    $stmt->execute([$org_id]);
    $nb_broadcasts = (int) $stmt->fetchColumn();
} catch (Throwable $e) {}

// Stats V3 events
$nb_events_upcoming = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM communication_events WHERE org_id = ? AND status = 'published' AND start_date >= NOW()");
    $stmt->execute([$org_id]);
    $nb_events_upcoming = (int) $stmt->fetchColumn();
} catch (Throwable $e) {}

// --- Catalogue des types de documents generables ---
$catalog = [
    'ag' => [
        'label' => 'Vie associative',
        'icon'  => ak_icon('building', 18),
        'desc'  => 'Documents officiels lies a la gouvernance',
        'items' => [
            'convocation_ag_ordinaire' => ['title' => 'Convocation AG ordinaire', 'desc' => 'Lettre formelle conforme loi 1901 avec ordre du jour'],
            'convocation_ag_extra'     => ['title' => 'Convocation AG extraordinaire', 'desc' => 'Modification statuts, dissolution, fusion...'],
            'pv_ag'                    => ['title' => 'Proces-verbal d\'AG', 'desc' => 'PV structure a partir de vos notes'],
            'rapport_moral'            => ['title' => 'Rapport moral du president', 'desc' => 'Bilan annuel de la gouvernance'],
            'convocation_ca'           => ['title' => 'Convocation CA / Bureau', 'desc' => 'Conseil d\'administration ou bureau'],
        ],
    ],
    'dons' => [
        'label' => 'Dons & donateurs',
        'icon'  => ak_icon('euro', 18),
        'desc'  => 'Collecte de dons et relation donateurs',
        'items' => [
            'appel_dons'            => ['title' => 'Appel a dons', 'desc' => 'Campagne de financement participatif'],
            'remerciement_donateur' => ['title' => 'Remerciement donateur', 'desc' => 'Lettre chaleureuse + recu fiscal'],
        ],
    ],
    'adherents' => [
        'label' => 'Adherents & Benevoles',
        'icon'  => ak_icon('users', 18),
        'desc'  => 'Communication avec votre communaute',
        'items' => [
            'appel_benevoles'        => ['title' => 'Appel a benevoles', 'desc' => 'Cible par competence ou dispo'],
            'relance_cotisation'     => ['title' => 'Relance cotisation', 'desc' => '3 tons : douce, ferme, ultime'],
            'accueil_nouveau_membre' => ['title' => 'Accueil nouveau membre', 'desc' => 'Email bienvenue + onboarding'],
            'attestation_benevolat'  => ['title' => 'Attestation de benevolat', 'desc' => 'Pour valorisation fiscale'],
            'newsletter_mensuelle'   => ['title' => 'Newsletter mensuelle', 'desc' => 'Actualite de l\'asso'],
        ],
    ],
    'reseaux_sociaux' => [
        'label' => 'Reseaux sociaux',
        'icon'  => ak_icon('megaphone', 18),
        'desc'  => 'Posts optimises pour chaque plateforme',
        'items' => [
            'post_facebook'       => ['title' => 'Post Facebook', 'desc' => '3 variantes : emotion, info, CTA'],
            'post_instagram'      => ['title' => 'Post Instagram', 'desc' => 'Caption + hashtags optimises'],
            'post_linkedin'       => ['title' => 'Post LinkedIn', 'desc' => 'Ton professionnel'],
            'serie_multi_reseaux' => ['title' => 'Serie multi-plateformes', 'desc' => '1 sujet → 3 posts adaptes'],
            'story_instagram'     => ['title' => 'Story Instagram', 'desc' => 'Scenario en plusieurs slides'],
        ],
    ],
    'rapport' => [
        'label' => 'Rapports annuels',
        'icon'  => ak_icon('chart', 18),
        'desc'  => 'Syntheses pour AG et financeurs',
        'items' => [
            'rapport_activite'  => ['title' => 'Rapport d\'activite annuel', 'desc' => 'Depuis vos projets et evenements'],
            'rapport_financier' => ['title' => 'Rapport financier simplifie', 'desc' => 'Depuis vos factures HT/TVA/TTC'],
            'bilan_projet'      => ['title' => 'Bilan de projet', 'desc' => 'Synthese fin de projet pour financeur'],
        ],
    ],
    'courriers' => [
        'label' => 'Courriers officiels',
        'icon'  => ak_icon('mail', 18),
        'desc'  => 'Correspondance institutionnelle et presse',
        'items' => [
            'courrier_mairie'        => ['title' => 'Courrier Mairie', 'desc' => 'Demande salle, soutien, RDV...'],
            'communique_presse'      => ['title' => 'Communique de presse', 'desc' => 'Structure pro avec accroche'],
            'invitation_presse'      => ['title' => 'Invitation presse', 'desc' => 'Evenement ou conf de presse'],
            'partenariat_entreprise' => ['title' => 'Demande de partenariat', 'desc' => 'Mecenat ou sponsoring'],
        ],
    ],
];

render_head('Communication');
render_sidebar('communication');
?>

<div class="main">

  <?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:18px;">
      <span><?= $flash['type'] === 'success' ? ak_icon('check-circle',15) : ak_icon('alert-tri',15) ?></span>
      <div><?= h($flash['message']) ?></div>
    </div>
  <?php endif; ?>

  <div class="main-head">
    <div>
      <h1 class="page-title">Communication</h1>
      <div class="page-sub">Rédigez, diffusez et invitez — avec l'aide de l'IA</div>
    </div>
    <div class="comm-stats">
      <div class="comm-stat"><span class="comm-stat-n"><?= $nb_ai ?></span><span class="comm-stat-l">Générés IA</span></div>
      <div class="comm-stat"><span class="comm-stat-n"><?= $nb_broadcasts ?></span><span class="comm-stat-l">Envois</span></div>
      <div class="comm-stat"><span class="comm-stat-n"><?= $nb_events_upcoming ?></span><span class="comm-stat-l">Événements</span></div>
      <div class="comm-stat"><span class="comm-stat-n"><?= $nb_templates ?></span><span class="comm-stat-l">Biblio</span></div>
    </div>
  </div>

  <div class="tabs">
    <a href="/communication?tab=rediger" class="tab <?= $tab === 'rediger' ? 'active' : '' ?>"><?= ak_icon('edit',15) ?> Rédiger</a>
    <a href="/communication?tab=diffuser" class="tab <?= $tab === 'diffuser' ? 'active' : '' ?>"><?= ak_icon('send',15) ?> Diffuser<?php if ($nb_broadcasts > 0): ?> <span class="tab-badge"><?= $nb_broadcasts ?></span><?php endif; ?></a>
    <a href="/communication?tab=evenements" class="tab <?= $tab === 'evenements' ? 'active' : '' ?>"><?= ak_icon('calendar',15) ?> Événements<?php if ($nb_events_upcoming > 0): ?> <span class="tab-badge"><?= $nb_events_upcoming ?></span><?php endif; ?></a>
    <a href="/communication?tab=affiches" class="tab <?= $tab === 'affiches' ? 'active' : '' ?>"><?= ak_icon('image',15) ?> Affiches <span class="tab-badge">V5</span></a>
    <a href="/communication?tab=bibliotheque" class="tab <?= $tab === 'bibliotheque' ? 'active' : '' ?>"><?= ak_icon('book',15) ?> Bibliothèque <?php if ($nb_templates > 0): ?><span class="tab-badge"><?= $nb_templates ?></span><?php endif; ?></a>
  </div>

  <?php if ($tab === 'rediger'): ?>

    <!-- ===== COMPOSER IA (rédaction libre) ===== -->
    <section class="comm-composer">
      <div class="comm-composer-band" aria-hidden="true"></div>
      <form method="POST" action="/communication-generer?type=libre" class="comm-composer-in">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <div class="comm-composer-head">
          <span class="comm-composer-badge"><svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M12 2l2.2 6.1L20.5 10l-6.3 1.9L12 18l-2.2-6.1L3.5 10l6.3-1.9z"/></svg></span>
          <div><b>Que voulez-vous rédiger&nbsp;?</b><small>Décrivez votre besoin — Assokit rédige, vous validez.</small></div>
        </div>
        <textarea name="demande" id="commDemande" class="comm-composer-field" rows="3" required
                  placeholder="Ex. « Convocation à l'AG ordinaire le 15 mars à 18h, salle des fêtes, avec vote du budget »"></textarea>
        <div class="comm-composer-row">
          <div class="comm-selects">
            <label class="comm-sel">Ton
              <select name="ton"><option>Formel</option><option>Chaleureux</option><option>Neutre</option><option>Dynamique</option></select>
            </label>
            <label class="comm-sel">Format
              <select name="format"><option>Email</option><option>Courrier</option><option>Réseaux sociaux</option><option>Note interne</option></select>
            </label>
            <label class="comm-sel">Longueur
              <select name="longueur"><option>Standard</option><option>Court</option><option>Détaillé</option></select>
            </label>
          </div>
          <button type="submit" class="comm-gen-btn">
            <svg viewBox="0 0 24 24" fill="currentColor" width="17" height="17"><path d="M12 2l2.2 6.1L20.5 10l-6.3 1.9L12 18l-2.2-6.1L3.5 10l6.3-1.9z"/></svg>
            Générer avec l'IA
          </button>
        </div>
        <div class="comm-quick">
          <span class="lbl">Rapide&nbsp;:</span>
          <button type="button" class="comm-qchip" data-fill="Convocation à l'assemblée générale ordinaire le [date] à [heure], [lieu], avec l'ordre du jour suivant : rapport moral, rapport financier, questions diverses."><?= ak_icon('building',14) ?> Convocation AG</button>
          <button type="button" class="comm-qchip" data-fill="Appel à dons pour financer [projet]. Objectif : [montant]. Expliquer à quoi servira l'argent et rappeler la déduction fiscale de 66%."><?= ak_icon('gift',14) ?> Appel à dons</button>
          <button type="button" class="comm-qchip" data-fill="Newsletter du mois de [mois] : actualité phare, retour sur les événements passés, événements à venir et un appel à l'action."><?= ak_icon('mail',14) ?> Newsletter</button>
          <button type="button" class="comm-qchip" data-fill="Lettre de remerciement chaleureuse pour un donateur ayant donné [montant] le [date], avec mention du reçu fiscal."><?= ak_icon('heart',14) ?> Remerciement</button>
          <button type="button" class="comm-qchip" data-fill="Message aux adhérents pour annoncer [information importante] et inviter à [action]."><?= ak_icon('megaphone',14) ?> Annonce adhérents</button>
        </div>
      </form>
    </section>

    <?php if (!empty($recent_campaigns)): ?>
    <div class="comm-sec-h2"><b>Vos dernières générations</b><a href="/communication?tab=bibliotheque">Tout voir →</a></div>
    <section class="comm-recents">
      <?php foreach ($recent_campaigns as $rc):
        $rc_sent = ($rc['status'] ?? '') === 'sent';
      ?>
        <a href="/communication?tab=bibliotheque" class="comm-recent">
          <div class="comm-recent-t"><span class="comm-recent-i"><?= ak_icon('sparkle',14) ?></span><h4><?= h($rc['title']) ?></h4></div>
          <p><span class="comm-recent-badge<?= $rc_sent ? '' : ' draft' ?>"><?= $rc_sent ? 'Envoyé' : 'Brouillon' ?></span> · <?= h(date('d/m/Y', strtotime($rc['created_at']))) ?></p>
        </a>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <div class="comm-sec-h2"><b>Ou partez d'un modèle</b></div>

    <?php foreach ($catalog as $cat_key => $cat): ?>
      <section class="comm-section">
        <div class="comm-section-head">
          <h2 class="comm-section-title"><span class="comm-section-icon"><?= $cat['icon'] ?></span><?= h($cat['label']) ?></h2>
          <div class="comm-section-desc"><?= h($cat['desc']) ?></div>
        </div>
        <div class="comm-grid">
          <?php foreach ($cat['items'] as $type_key => $item): ?>
            <a href="/communication-generer?type=<?= urlencode($type_key) ?>" class="comm-card">
              <div class="comm-card-title"><?= h($item['title']) ?></div>
              <div class="comm-card-desc"><?= h($item['desc']) ?></div>
              <div class="comm-card-cta">Générer →</div>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>

  <?php elseif ($tab === 'diffuser'): ?>

    <!-- ======= ONGLET DIFFUSER ======= -->
    <div class="main-head" style="margin-bottom: 18px;">
      <div>
        <h2 style="font-size: 18px; font-weight: 500; margin: 0;"><?= ak_icon('send',18) ?> Vos diffusions</h2>
        <div class="page-sub">Envoyez des messages à vos adhérents par email</div>
      </div>
      <div>
        <a href="/communication-diffuser-nouveau" class="btn btn-primary">+ Nouvelle diffusion</a>
      </div>
    </div>

    <?php
    $broadcasts = [];
    try {
        $stmt = $pdo->prepare("
            SELECT b.*, u.first_name, u.last_name
            FROM communication_broadcasts b
            LEFT JOIN users u ON b.created_by_user_id = u.id
            WHERE b.org_id = ?
            ORDER BY b.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$org_id]);
        $broadcasts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    ?>

    <?php if (empty($broadcasts)): ?>
      <div class="empty-state" style="padding:48px 20px">
        <div style="font-size:48px;opacity:.35;margin-bottom:10px"><?= ak_icon('send',44,'1.5') ?></div>
        <div style="font-size:16px;color:var(--ink);font-weight:500;margin-bottom:6px">Aucune diffusion pour l'instant</div>
        <div style="max-width:460px;margin:0 auto 18px;line-height:1.55; color:var(--ink-3);">
          Envoyez une newsletter, une annonce ou un appel à vos adhérents en quelques clics.
        </div>
        <a href="/communication-diffuser-nouveau" class="btn btn-primary">+ Créer la première diffusion</a>
      </div>
    <?php else: ?>
      <div class="comm-list" style="overflow:hidden;">
        <?php foreach ($broadcasts as $b): ?>
          <div style="display:grid; grid-template-columns: 1fr auto auto; gap:14px; padding:14px 18px; border-bottom:1px solid var(--border); align-items:center;">
            <div style="min-width:0;">
              <div style="font-size:14px; font-weight:500; margin-bottom:3px; letter-spacing:-0.01em;"><?= h($b['subject']) ?></div>
              <div style="font-size:12px; color:var(--ink-3);">
                <?= h($b['first_name'] . ' ' . $b['last_name']) ?>
                · <?= $b['sent_at'] ? 'envoyé ' . date('d/m/Y H:i', strtotime($b['sent_at'])) : 'brouillon' ?>
              </div>
            </div>
            <div style="font-size:12px; color:var(--ink-3); text-align:right;">
              <?php if ($b['status'] === 'sent'): ?>
                <strong style="color:var(--acc);"><?= (int)$b['nb_sent'] ?></strong> envoyé<?= $b['nb_sent'] > 1 ? 's' : '' ?>
                <?php if ($b['nb_failed'] > 0): ?>
                  <br><span style="color:#EF4444;"><?= (int)$b['nb_failed'] ?> échec<?= $b['nb_failed'] > 1 ? 's' : '' ?></span>
                <?php endif; ?>
              <?php else: ?>
                <span style="background:var(--bg-3); padding:2px 7px; border-radius:999px; font-size:11px;">
                  <?= match($b['status']) { 'draft' => 'Brouillon', 'sending' => 'En cours', 'failed' => 'Échec', default => h($b['status']) } ?>
                </span>
              <?php endif; ?>
            </div>
            <div>
              <a href="/communication-diffusion?id=<?= (int)$b['id'] ?>" class="btn btn-ghost" style="padding:5px 12px; font-size:12px;">Voir →</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($tab === 'evenements'): ?>

    <!-- ======= ONGLET EVENEMENTS ======= -->
    <div class="main-head" style="margin-bottom: 18px;">
      <div>
        <h2 style="font-size: 18px; font-weight: 500; margin: 0;"><?= ak_icon('calendar',18) ?> Vos événements</h2>
        <div class="page-sub">Les événements que vous proposez à vos adhérents — avec inscription en ligne (RSVP)</div>
      </div>
      <div>
        <a href="/communication-evenement-nouveau" class="btn btn-primary">+ Nouvel événement</a>
      </div>
    </div>

    <?php
    $events = [];
    try {
        $stmt = $pdo->prepare("
            SELECT e.*,
                   u.first_name, u.last_name,
                   (SELECT COUNT(*) FROM communication_event_rsvps WHERE event_id = e.id AND response = 'yes') AS nb_yes,
                   (SELECT COUNT(*) FROM communication_event_rsvps WHERE event_id = e.id AND response = 'maybe') AS nb_maybe,
                   (SELECT COUNT(*) FROM communication_event_rsvps WHERE event_id = e.id AND response = 'no') AS nb_no
            FROM communication_events e
            LEFT JOIN users u ON e.created_by_user_id = u.id
            WHERE e.org_id = ?
            ORDER BY
                CASE WHEN e.start_date >= NOW() THEN 0 ELSE 1 END,
                e.start_date ASC
            LIMIT 50
        ");
        $stmt->execute([$org_id]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    ?>

    <?php if (empty($events)): ?>
      <div class="empty-state" style="padding:48px 20px">
        <div style="font-size:48px;opacity:.35;margin-bottom:10px"><?= ak_icon('calendar',44,'1.5') ?></div>
        <div style="font-size:16px;color:var(--ink);font-weight:500;margin-bottom:6px">Aucun événement pour l'instant</div>
        <div style="max-width:460px;margin:0 auto 18px;line-height:1.55; color:var(--ink-3);">
          Créez un événement avec un lien public partageable. Vos invités pourront confirmer leur venue (Oui / Non / Peut-être).
        </div>
        <a href="/communication-evenement-nouveau" class="btn btn-primary">+ Créer le premier événement</a>
      </div>
    <?php else: ?>
      <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap:12px;">
        <?php foreach ($events as $e):
          $is_past = strtotime($e['start_date']) < time();
        ?>
          <a href="/communication-evenement?id=<?= (int)$e['id'] ?>" class="comm-evt-card"
             style="display:block; padding:18px; text-decoration:none; color:inherit; <?= $is_past ? 'opacity:0.65;' : '' ?>">
            <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:8px;">
              <div style="font-size:11px; font-weight:500; color:var(--acc); letter-spacing:0.03em; text-transform:uppercase;">
                <?= date('d M Y', strtotime($e['start_date'])) ?> · <?= date('H:i', strtotime($e['start_date'])) ?>
              </div>
              <?php if ($is_past): ?>
                <span style="font-size:10px; background:var(--bg-3); color:var(--ink-3); padding:2px 7px; border-radius:999px;">Passé</span>
              <?php elseif ($e['status'] === 'cancelled'): ?>
                <span style="font-size:10px; background:#FEE2E2; color:#991B1B; padding:2px 7px; border-radius:999px;">Annulé</span>
              <?php endif; ?>
            </div>
            <div style="font-size:15px; font-weight:500; letter-spacing:-0.01em; margin-bottom:6px;">
              <?= h($e['title']) ?>
            </div>
            <?php if ($e['location']): ?>
              <div style="font-size:12px; color:var(--ink-3); margin-bottom:10px;">
                <?= ak_icon('pin',13) ?> <?= h($e['location']) ?>
              </div>
            <?php endif; ?>
            <?php if ($e['rsvp_enabled']): ?>
              <div style="display:flex; gap:8px; padding-top:10px; border-top:1px solid var(--border); font-size:12px;">
                <span style="color:var(--acc);">✓ <?= (int)$e['nb_yes'] ?></span>
                <span style="color:var(--ink-3);">? <?= (int)$e['nb_maybe'] ?></span>
                <span style="color:var(--ink-4);">✕ <?= (int)$e['nb_no'] ?></span>
              </div>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($tab === 'affiches'): ?>

    <!-- ======= ONGLET AFFICHES (V5) ======= -->
    <div class="comm-coming">
      <div class="comm-coming-icon"><?= ak_icon('image',44,'1.5') ?></div>
      <h2 class="comm-coming-title">Génération d'affiches — Bientôt (V5)</h2>
      <p class="comm-coming-sub">Créez des visuels professionnels pour vos événements en quelques secondes.</p>
    </div>
    <div class="comm-grid">
      <div class="comm-preview">
        <div class="comm-preview-icon"><?= ak_icon('palette',22) ?></div>
        <h3 class="comm-preview-title">Templates pré-conçus</h3>
        <p class="comm-preview-desc">Banque de modèles par type d'événement : concert, conférence, AG, vernissage, atelier...</p>
      </div>
      <div class="comm-preview">
        <div class="comm-preview-icon"><?= ak_icon('sparkle',22) ?></div>
        <h3 class="comm-preview-title">IA générative</h3>
        <p class="comm-preview-desc">Génération d'images d'illustration par IA à partir d'un prompt descriptif de votre événement.</p>
      </div>
      <div class="comm-preview">
        <div class="comm-preview-icon">📐</div>
        <h3 class="comm-preview-title">Multi-formats</h3>
        <p class="comm-preview-desc">Export PDF A4/A3 pour affichage, PNG carré pour réseaux sociaux, story verticale Instagram.</p>
      </div>
      <div class="comm-preview">
        <div class="comm-preview-icon"><?= ak_icon('link',22) ?></div>
        <h3 class="comm-preview-title">QR Code intégré</h3>
        <p class="comm-preview-desc">QR code automatique vers la page RSVP publique de votre événement pour inscription rapide.</p>
      </div>
    </div>

  <?php elseif ($tab === 'bibliotheque'): ?>

    <?php
    $stmt = $pdo->prepare("
      SELECT t.*, u.first_name, u.last_name
      FROM communication_saved_templates t
      LEFT JOIN users u ON t.created_by = u.id
      WHERE t.org_id = ?
      ORDER BY t.is_favorite DESC, t.updated_at DESC
    ");
    $stmt->execute([$org_id]);
    $tpls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>

    <?php if (empty($tpls)): ?>
      <div class="empty-state" style="padding:60px 20px">
        <div style="margin-bottom:10px;color:#94A3B8"><?= ak_icon('book', 44, '1.5') ?></div>
        <div style="font-size:17px;color:var(--ink);font-weight:500;margin-bottom:6px">Votre bibliothèque est vide</div>
        <div style="max-width:460px;margin:0 auto 18px;line-height:1.55">
          Quand vous générez un document qui vous plaît, cliquez sur <strong>« Sauvegarder »</strong> pour le réutiliser plus tard.
        </div>
        <a href="/communication?tab=rediger" class="btn btn-primary">Générer un premier document →</a>
      </div>
    <?php else: ?>
      <div class="comm-lib">
        <?php foreach ($tpls as $t): ?>
          <div class="comm-lib-row">
            <div class="comm-lib-main">
              <div class="comm-lib-title"><?php if ($t['is_favorite']): ?><?= ak_icon('star-fill',13) ?> <?php endif; ?><?= h($t['title']) ?></div>
              <div class="comm-lib-meta">
                <span class="comm-lib-cat"><?= h($t['category']) ?></span>
                par <?= h(trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''))) ?>
                · <?= (int) $t['usage_count'] ?> utilisations
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php endif; ?>

</div>

<style>
.comm-stats { display: flex; gap: 8px; flex-wrap: wrap; }
.comm-stat { display: flex; flex-direction: column; align-items: center; background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 8px 14px; min-width: 72px; }
.comm-stat-n { font-size: 20px; font-weight: 600; color: var(--ink); line-height: 1.1; }
.comm-stat-l { font-size: 10.5px; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.05em; margin-top: 2px; }

.comm-section { margin-bottom: 36px; }
.comm-section-head { margin-bottom: 14px; }
.comm-section-title { font-size: 16px; font-weight: 600; margin: 0 0 3px; display: inline-flex; align-items: center; gap: 9px; letter-spacing: -0.01em; }
.comm-section-icon { display: inline-flex; align-items: center; }
.comm-section-desc { font-size: 12.5px; color: var(--ink-3); }

.comm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr)); gap: 10px; }

.comm-card { display: flex; flex-direction: column; padding: 16px; background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-lg); transition: border-color 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease; color: var(--ink); }
.comm-card:hover { border-color: var(--acc); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,0.05); }
.comm-card-title { font-size: 14px; font-weight: 500; color: var(--ink); margin-bottom: 5px; }
.comm-card-desc  { font-size: 12.5px; color: var(--ink-3); line-height: 1.45; flex: 1; margin-bottom: 10px; }
.comm-card-cta   { font-size: 12.5px; color: var(--acc); font-weight: 500; }

.comm-coming { text-align: center; padding: 42px 20px; background: var(--bg); border: 1px dashed var(--border-strong); border-radius: var(--radius-lg); margin-bottom: 24px; }
.comm-coming-icon { font-size: 52px; margin-bottom: 10px; }
.comm-coming-title { font-size: 20px; font-weight: 500; margin: 0 0 4px; letter-spacing: -0.02em; }
.comm-coming-sub { font-size: 13.5px; color: var(--ink-3); margin: 0; }

.comm-preview { padding: 18px; background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-lg); }
.comm-preview-icon { font-size: 26px; margin-bottom: 8px; }
.comm-preview-title { font-size: 14px; font-weight: 500; margin: 0 0 6px; color: var(--ink); }
.comm-preview-desc  { font-size: 12.5px; color: var(--ink-3); margin: 0; line-height: 1.5; }

.comm-lib { display: flex; flex-direction: column; gap: 6px; }
.comm-lib-row { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius); }
.comm-lib-title { font-size: 14px; font-weight: 500; color: var(--ink); margin-bottom: 2px; }
.comm-lib-meta  { font-size: 12px; color: var(--ink-3); display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.comm-lib-cat   { background: var(--bg-3); padding: 1px 7px; border-radius: 5px; font-size: 11px; }

/* ============================================================
   COMMUNICATION 2.0 — surcouche premium Liquid Glass (maquette)
   ============================================================ */
.main{max-width:1200px}
.main .page-title{font-size:32px;font-weight:800;letter-spacing:-.03em;line-height:1;color:var(--ink)}
.main .page-sub{font-size:13.5px;color:var(--ink-2);margin-top:10px}
.comm-stats{gap:10px}
.comm-stat{background:var(--glass);backdrop-filter:blur(12px) saturate(1.4);-webkit-backdrop-filter:blur(12px) saturate(1.4);border:1px solid var(--glass-border);border-radius:14px;box-shadow:var(--shadow-card);padding:12px 18px;min-width:84px}
.comm-stat-n{font-size:24px;font-weight:800;letter-spacing:-.03em;font-variant-numeric:tabular-nums}
.comm-stat:first-child .comm-stat-n{color:var(--ai)}
.comm-stat-l{font-size:9.5px;font-weight:700}
.comm-section-title{font-size:17px;font-weight:750;letter-spacing:-.02em}
.comm-grid{grid-template-columns:repeat(4,1fr);gap:16px}
.comm-card{position:relative;overflow:hidden;padding:18px 20px 16px;background:var(--glass);backdrop-filter:blur(20px) saturate(1.5);-webkit-backdrop-filter:blur(20px) saturate(1.5);border:1px solid var(--glass-border);border-radius:var(--radius-lg,18px);box-shadow:var(--shadow-card);transition:transform .16s ease,box-shadow .16s ease}
.comm-card::before{content:"";position:absolute;inset:0 0 auto 0;height:3px;background:linear-gradient(90deg,#34D399,#059669)}
.comm-section:nth-of-type(2) .comm-card::before{background:linear-gradient(90deg,#FBBF24,#E0850C)}
.comm-section:nth-of-type(3) .comm-card::before{background:linear-gradient(90deg,#60A5FA,#2F73E8)}
.comm-section:nth-of-type(4) .comm-card::before{background:linear-gradient(90deg,#8B5CF6,#6366F1)}
.comm-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-pop)}
.comm-card-title{font-size:15px;font-weight:700}
.comm-card-desc{min-height:34px}
.comm-card-cta{font-weight:650;color:var(--brand-ink,#047857)}
@media (max-width:960px){.comm-grid{grid-template-columns:1fr 1fr}}
@media (max-width:560px){.comm-grid{grid-template-columns:1fr}}

/* ===== Composer IA + Récents ===== */
.comm-composer{position:relative;overflow:hidden;margin-bottom:22px;background:var(--glass);backdrop-filter:blur(22px) saturate(1.5);-webkit-backdrop-filter:blur(22px) saturate(1.5);border:1px solid var(--glass-border);border-radius:var(--radius-lg,18px);box-shadow:var(--shadow-card)}
.comm-composer-band{position:absolute;inset:0;pointer-events:none;background:radial-gradient(130% 100% at 0% 0%,rgba(99,102,241,.15),transparent 55%),radial-gradient(120% 120% at 100% 0%,rgba(16,185,129,.13),transparent 55%)}
.comm-composer-in{position:relative;padding:22px 24px 20px}
.comm-composer-head{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.comm-composer-badge{width:40px;height:40px;border-radius:12px;flex:none;background:linear-gradient(140deg,#8B5CF6,#6366F1);display:grid;place-items:center;color:#fff;box-shadow:0 10px 22px -8px rgba(99,102,241,.6),inset 0 1px 0 rgba(255,255,255,.4);position:relative;overflow:hidden}
.comm-composer-badge svg{position:relative;z-index:1}
.comm-composer-badge::after{content:"";position:absolute;inset:0;background:linear-gradient(115deg,transparent 30%,rgba(255,255,255,.55) 50%,transparent 70%);transform:translateX(-120%);animation:commSheen 4.5s ease-in-out infinite}
@keyframes commSheen{0%,70%{transform:translateX(-120%)}85%,100%{transform:translateX(120%)}}
.comm-composer-head b{font-size:16px;letter-spacing:-.01em;color:var(--ink)}
.comm-composer-head small{display:block;color:var(--ink-3);font-size:12.5px;margin-top:1px}
.comm-composer-field{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:16px;padding:15px 17px;font:inherit;font-size:15px;color:var(--ink);resize:vertical;min-height:70px;box-shadow:var(--shadow-sm);outline:none}
.comm-composer-field:focus{border-color:var(--ai,#6366F1);box-shadow:0 0 0 4px var(--ai-light,rgba(99,102,241,.1))}
.comm-composer-row{display:flex;align-items:flex-end;gap:12px;margin-top:14px;flex-wrap:wrap}
.comm-selects{display:flex;gap:8px;flex-wrap:wrap}
.comm-sel{display:inline-flex;flex-direction:column;font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-3);gap:4px}
.comm-sel select{font:inherit;font-size:13px;font-weight:600;color:var(--ink);background:var(--bg-2);border:1px solid var(--border);border-radius:10px;padding:8px 11px;cursor:pointer;text-transform:none;letter-spacing:0}
.comm-gen-btn{margin-left:auto;display:inline-flex;align-items:center;gap:9px;padding:13px 22px;border-radius:13px;border:0;cursor:pointer;font-size:14.5px;font-weight:700;color:#fff;background:linear-gradient(140deg,#8B5CF6,#6366F1);box-shadow:0 12px 26px -8px rgba(99,102,241,.6),inset 0 1px 0 rgba(255,255,255,.35);transition:transform .12s ease}
.comm-gen-btn:hover{transform:translateY(-1px)}
.comm-quick{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;align-items:center}
.comm-quick .lbl{font-size:12px;color:var(--ink-3);font-weight:600}
.comm-qchip{font:inherit;display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:999px;font-size:12.5px;font-weight:600;background:var(--bg-2);border:1px solid var(--border);color:var(--ink-2);cursor:pointer;transition:.15s}
.comm-qchip:hover{border-color:rgba(99,102,241,.35);color:var(--ink)}
.comm-sec-h2{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin:6px 2px 12px}
.comm-sec-h2 b{font-size:16px;font-weight:750;letter-spacing:-.015em;color:var(--ink)}
.comm-sec-h2 a{font-size:13px;color:var(--brand-ink,#047857);font-weight:600;text-decoration:none}
.comm-recents{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:26px}
.comm-recent{padding:15px 17px;background:var(--glass);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid var(--glass-border);border-radius:var(--radius-lg,18px);box-shadow:var(--shadow-card);text-decoration:none;color:inherit;transition:transform .16s ease,box-shadow .16s ease}
.comm-recent:hover{transform:translateY(-2px);box-shadow:var(--shadow-pop)}
.comm-recent-t{display:flex;align-items:center;gap:9px}
.comm-recent-i{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;font-size:15px;flex:none;background:var(--ai-light,rgba(99,102,241,.1))}
.comm-recent h4{font-size:13.5px;font-weight:650;letter-spacing:-.01em;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin:0}
.comm-recent p{font-size:11.5px;color:var(--ink-3);margin-top:9px}
.comm-recent-badge{font-size:10px;font-weight:700;padding:2px 7px;border-radius:6px;background:var(--brand-soft);color:var(--brand-ink)}
.comm-recent-badge.draft{background:var(--amber-soft,rgba(224,133,12,.14));color:var(--amber,#E0850C)}
@media (max-width:900px){.comm-recents{grid-template-columns:1fr 1fr}.comm-gen-btn{margin-left:0;width:100%;justify-content:center}}
@media (max-width:560px){.comm-recents{grid-template-columns:1fr}}

/* ===== Onglets Diffuser / Événements / Affiches / Bibliothèque ===== */
.main .main-head h2{font-weight:750!important;letter-spacing:-.01em;color:var(--ink)}
.empty-state{background:var(--glass)!important;backdrop-filter:blur(22px) saturate(1.5);-webkit-backdrop-filter:blur(22px) saturate(1.5);border:1px solid var(--glass-border)!important;border-radius:var(--radius-lg,18px)!important;box-shadow:var(--shadow-card)}
/* Diffuser / Événements : listes en verre */
.comm-list{background:var(--glass)!important;backdrop-filter:blur(20px) saturate(1.5);-webkit-backdrop-filter:blur(20px) saturate(1.5);border:1px solid var(--glass-border)!important;border-radius:var(--radius-lg,18px)!important;box-shadow:var(--shadow-card);overflow:hidden}
.comm-evt-card{background:var(--glass)!important;backdrop-filter:blur(18px) saturate(1.5);-webkit-backdrop-filter:blur(18px) saturate(1.5);border:1px solid var(--glass-border)!important;border-radius:var(--radius-lg,18px)!important;box-shadow:var(--shadow-card)!important;transition:transform .16s ease,box-shadow .16s ease}
.comm-evt-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-pop)!important}
/* Affiches — teaser premium */
.comm-coming{background:var(--glass);backdrop-filter:blur(22px) saturate(1.5);-webkit-backdrop-filter:blur(22px) saturate(1.5);border:1px solid var(--glass-border);border-radius:var(--radius-lg,18px);box-shadow:var(--shadow-card);position:relative;overflow:hidden}
.comm-coming::before{content:"V5 · Bientôt";position:absolute;top:14px;right:16px;font-size:10.5px;font-weight:700;letter-spacing:.04em;color:var(--ai);background:var(--ai-light,rgba(99,102,241,.1));border:1px solid rgba(99,102,241,.2);padding:4px 10px;border-radius:999px}
.comm-coming-title{font-weight:750;letter-spacing:-.02em}
.comm-preview{background:var(--glass);backdrop-filter:blur(18px) saturate(1.5);-webkit-backdrop-filter:blur(18px) saturate(1.5);border:1px solid var(--glass-border);border-radius:var(--radius-lg,18px);box-shadow:var(--shadow-card);transition:transform .16s ease,box-shadow .16s ease}
.comm-preview:hover{transform:translateY(-3px);box-shadow:var(--shadow-pop)}
.comm-preview-icon{font-size:26px}
.comm-preview-title{font-weight:700}
/* Bibliothèque — cartes de verre */
.comm-lib{gap:12px}
.comm-lib-row{background:var(--glass)!important;backdrop-filter:blur(18px) saturate(1.5);-webkit-backdrop-filter:blur(18px) saturate(1.5);border:1px solid var(--glass-border)!important;border-radius:var(--radius-lg,18px)!important;box-shadow:var(--shadow-card);padding:15px 18px!important;transition:transform .16s ease,box-shadow .16s ease}
.comm-lib-row:hover{transform:translateY(-2px);box-shadow:var(--shadow-pop)}
.comm-lib-title{font-weight:700}
.comm-lib-cat{background:var(--ai-light,rgba(99,102,241,.1))!important;color:var(--ai)!important;border-radius:999px!important;font-weight:700}
/* Alignement des icônes maison (onglets, chips, en-têtes, listes) */
.tab{display:inline-flex;align-items:center;gap:6px}
.comm-qchip{display:inline-flex;align-items:center;gap:6px}
.comm-recent-i svg{vertical-align:-2px}
.comm-sec-h2 svg,.comm-lib-title svg{vertical-align:-2px;margin-right:2px}
h2 svg{vertical-align:-3px;margin-right:4px}
.comm-coming-icon,.comm-preview-icon{color:#94A3B8}
.comm-preview-icon{display:inline-flex}
</style>

<script>
(function(){
  document.querySelectorAll('.comm-qchip').forEach(function(b){
    b.addEventListener('click', function(){
      var ta = document.getElementById('commDemande');
      if (!ta) return;
      ta.value = this.getAttribute('data-fill') || '';
      ta.focus();
      ta.dispatchEvent(new Event('input'));
    });
  });
})();
</script>

<?php render_foot(); ?>
