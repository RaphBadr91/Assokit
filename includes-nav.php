<?php
/**
 * includes-nav.php — Structure et rendu du menu latéral.
 * ------------------------------------------------------------------
 * Avant, le menu alignait une vingtaine d'entrées à plat pour un
 * administrateur : il fallait faire défiler pour atteindre Paramètres.
 * Les entrées sont désormais rangées par famille, repliées par défaut,
 * et une seule famille reste ouverte à la fois — le menu tient dans
 * l'écran quoi qu'on ouvre.
 *
 * Tout est décrit ici en tableau plutôt qu'en HTML, pour trois raisons :
 *
 *   1. Une famille dont aucune entrée n'est visible pour le rôle courant
 *      ne doit pas s'afficher du tout. Avec du HTML entrelacé de `if`,
 *      ce cas se rate ; avec un tableau filtré, il est impossible.
 *   2. Les droits d'accès restent lisibles d'un coup d'œil, alignés dans
 *      une colonne, au lieu d'être noyés dans les balises.
 *   3. Ajouter une page devient une ligne.
 *
 * Les conditions de visibilité reprennent une à une celles qui existaient
 * dans le menu précédent. Aucune page n'est ouverte ni fermée à un rôle
 * qui ne l'avait pas déjà.
 * ------------------------------------------------------------------
 */

if (!function_exists('ak_nav_familles')) {

/**
 * Décrit le menu.
 *
 * @param array $ctx  Droits et compteurs déjà calculés par render_sidebar :
 *                    role, is_follower, peut_finances, peut_marketing,
 *                    peut_admin, active, proj_count, user_count,
 *                    support_unread.
 * @return array      Familles, chacune avec ses entrées.
 */
function ak_nav_familles(array $ctx): array
{
    $role        = (string)($ctx['role'] ?? '');
    $estAdmin    = ($role === 'admin');
    $estCoord    = in_array($role, ['admin', 'coordinator'], true);
    $membre      = empty($ctx['is_follower']);        // « pas un simple suiveur »
    $finances    = !empty($ctx['peut_finances']);
    $marketing   = !empty($ctx['peut_marketing']);
    $prospection = $marketing || $estCoord;
    $admin       = !empty($ctx['peut_admin']) || $estAdmin;
    $active      = (string)($ctx['active'] ?? '');

    // Archives et Abonnement ne se montraient que si l'on était déjà dans
    // cette zone. Comportement conservé tel quel.
    $zoneAdmin   = in_array($active, ['admin', 'archives', 'abonnement'], true);
    $facturation = !function_exists('ak_billing_hidden') || !ak_billing_hidden();

    $i = 'ak_nav_icone';

    return [
        [
            'cle' => 'vie',
            'label' => 'Vie associative',
            'icone' => $i('adherents', 16),
            'entrees' => [
                ['adherents',       '/adherents',        'Adhérents',       $membre,             ['adherents'],       $ctx['user_count'] ?? null],
                ['cotisations',     '/cotisations',      'Cotisations',     $membre && $estCoord, ['cotisations']],
                ['agenda',          '/agenda',           'Agenda',          true,                ['agenda']],
                ['emploi-du-temps', '/emploi-du-temps',  'Emploi du temps', $membre,             ['emploi-du-temps']],
                ['assemblees',      '/assemblees',       'Assemblées',      $estAdmin,           ['assemblees']],
                ['emargement',      '/emargement',       'Émargement',      $estAdmin,           ['emargement']],
            ],
        ],
        [
            'cle' => 'projets',
            'label' => 'Projets',
            'icone' => $i('projets', 16),
            'entrees' => [
                ['projets',       '/projets',       $membre ? 'Projets' : 'Projets suivis', true, ['projets'], $membre ? ($ctx['proj_count'] ?? null) : null],
                ['tags',          '/mon-asso-tags', 'Tags',                                 $finances, ['tags']],
                ['notes-de-frais','/notes-de-frais','Notes de frais',                       $membre,   ['notes-de-frais']],
            ],
        ],
        [
            'cle' => 'finances',
            'label' => 'Finances',
            'icone' => $i('finances', 16),
            'entrees' => [
                ['facturation',  '/mon-asso-factures-client', 'Facturation',          $finances, ['devis','factures','clients','stats']],
                ['relances',     '/relances',                 'Relances',             $finances, ['relances']],
                ['anomalies',    '/anomalies',                'Anomalies',            $finances, ['anomalies']],
                ['previsions',   '/previsions',               'Prévisions',           $finances, ['previsions']],
                ['comptabilite', '/comptabilite',             'Comptabilité',         $finances, ['comptabilite']],
                ['export-fec',   '/export-fec',               'Export FEC',           $finances, ['export-fec']],
                ['facturx',      '/facturx',                  'E-facture (Factur-X)', $finances, ['facturx']],
                ['financements', '/financements',             'Radar subventions',    $finances && $estAdmin, ['financements']],
                ['subventions',  '/subventions',              'Mes candidatures',     $finances && $estAdmin, ['subventions']],
            ],
        ],
        [
            'cle' => 'communication',
            'label' => 'Communication',
            'icone' => $i('communication', 16),
            'entrees' => [
                ['messages',      '/messages',      'Messages',      $membre,       ['messages']],
                ['communication', '/communication', 'Diffusion',     $marketing,    ['communication']],
                ['prospection',   '/prospection',   'Prospection',   $prospection,  ['prospection']],
                ['mon-asso-qr',   '/mon-asso-qr',   'Codes QR',      $prospection,  ['mon-asso-qr']],
            ],
        ],
        [
            'cle' => 'aide',
            'label' => 'Aide',
            'icone' => $i('aide', 16),
            'entrees' => [
                ['coach-ia', '/coach-ia', 'Coach Assokit', $estAdmin, ['coach-ia']],
                ['support',  '/support',  'Support',       true,      ['support'], $ctx['support_unread'] ?? null, true],
            ],
        ],
        [
            'cle' => 'reglages',
            'label' => 'Réglages',
            'icone' => $i('reglages', 16),
            'entrees' => [
                ['parametres',   '/parametres',   'Paramètres',            true,   ['parametres']],
                ['admin',        '/admin',        'Administration',        $admin, ['admin']],
                ['mon-asso-sso', '/mon-asso-sso', 'Intégration WordPress', $admin, ['mon-asso-sso']],
                ['archives',     '/archives',     'Archives',              $admin && $zoneAdmin, ['archives']],
                ['abonnement',   '/abonnement',   'Abonnement',            $admin && $zoneAdmin && $facturation, ['abonnement']],
            ],
        ],
    ];
}

/**
 * Icônes du menu. Le trait de 1,8 et la grille de 24 sont ceux du reste
 * de l'interface : changer l'un sans l'autre se voit.
 */
function ak_nav_icone(string $nom, int $taille = 14): string
{
    $corps = [
        'adherents'     => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 6a3 3 0 0 1 0 5"/>',
        'cotisations'   => '<rect x="2" y="6" width="20" height="12" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><circle cx="6" cy="14.5" r="1.2"/>',
        'agenda'        => '<rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9h18M8 2.5v4M16 2.5v4"/>',
        'emploi'        => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'assemblees'    => '<path d="M3 21h18M5 21V10l7-5 7 5v11M9 21V12h6v9"/>',
        'emargement'    => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'projets'       => '<path d="M4 7a2 2 0 0 1 2-2h4l2 2h6a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/>',
        'tags'          => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
        'notes'         => '<rect x="3" y="8" width="18" height="12" rx="2"/><path d="M8 8V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18"/>',
        'finances'      => '<path d="M4 3h12l4 4v14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M7 8h7M7 12h9M7 16h5"/>',
        'facturation'   => '<path d="M4 4h16v16H4z"/><path d="M4 10h16M10 4v16"/>',
        'relances'      => '<path d="M3 8l9 6 9-6"/><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M16 3l2 2-2 2"/>',
        'anomalies'     => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'previsions'    => '<path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/>',
        'comptabilite'  => '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19a2 2 0 0 0 2 2h13M9 8h6"/>',
        'fec'           => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M9 15h6M9 12h2"/>',
        'facturx'       => '<path d="M12 2l8 3v6c0 5-3.4 8.5-8 11-4.6-2.5-8-6-8-11V5z"/><path d="M9 12l2 2 4-4"/>',
        'financements'  => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-3.5-3.5M11 7v4l2.5 2.5"/>',
        'subventions'   => '<path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'messages'      => '<path d="M4 5h16v11H8l-4 3z"/>',
        'communication' => '<path d="M3 11l18-7-7 18-3-8z"/>',
        'prospection'   => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'qr'            => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3h-3zM20 14h1M14 20h1M18 18h3v3h-3z"/>',
        'coach'         => '<circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><circle cx="9" cy="10" r=".8" fill="currentColor"/><circle cx="15" cy="10" r=".8" fill="currentColor"/><path d="M12 3v2M5 6l1.4 1.4M19 6l-1.4 1.4"/>',
        'support'       => '<path d="M4 5h16v10H4z"/><path d="M8 20h8M12 15v5"/>',
        'aide'          => '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.3a2.5 2.5 0 1 1 3.4 2.3c-.7.3-1 .9-1 1.6v.3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'parametres'    => '<circle cx="12" cy="12" r="3.2"/><path d="M19 12a7 7 0 0 0-.1-1.4l2-1.5-2-3.5-2.3 1a7 7 0 0 0-2.4-1.4L15.8 2h-4l-.4 2.7A7 7 0 0 0 9 6.1L6.7 5 4.7 8.5l2 1.5A7 7 0 0 0 6.6 12a7 7 0 0 0 .1 1.4l-2 1.5 2 3.5 2.3-1a7 7 0 0 0 2.4 1.4l.4 2.7h4l.4-2.7a7 7 0 0 0 2.4-1.4l2.3 1 2-3.5-2-1.5A7 7 0 0 0 19 12z"/>',
        'reglages'      => '<circle cx="12" cy="12" r="3.2"/><path d="M19 12a7 7 0 0 0-.1-1.4l2-1.5-2-3.5-2.3 1a7 7 0 0 0-2.4-1.4L15.8 2h-4l-.4 2.7A7 7 0 0 0 9 6.1L6.7 5 4.7 8.5l2 1.5A7 7 0 0 0 6.6 12a7 7 0 0 0 .1 1.4l-2 1.5 2 3.5 2.3-1a7 7 0 0 0 2.4 1.4l.4 2.7h4l.4-2.7a7 7 0 0 0 2.4-1.4l2.3 1 2-3.5-2-1.5A7 7 0 0 0 19 12z"/>',
        'admin'         => '<path d="M12 1l9 4v6c0 5.55-3.84 10.74-9 12-5.16-1.26-9-6.45-9-12V5l9-4z"/>',
        'wordpress'     => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        'archives'      => '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
        'abonnement'    => '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
        'chevron'       => '<polyline points="6 9 12 15 18 9"/>',
    ][$nom] ?? '';

    return '<svg width="' . $taille . '" height="' . $taille . '" viewBox="0 0 24 24" fill="none"'
         . ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
         . ' aria-hidden="true">' . $corps . '</svg>';
}

/** Icône de chaque entrée, par sa clé. */
function ak_nav_icone_entree(string $cle): string
{
    $corresp = [
        'adherents' => 'adherents', 'cotisations' => 'cotisations', 'agenda' => 'agenda',
        'emploi-du-temps' => 'emploi', 'assemblees' => 'assemblees', 'emargement' => 'emargement',
        'projets' => 'projets', 'tags' => 'tags', 'notes-de-frais' => 'notes',
        'facturation' => 'facturation', 'relances' => 'relances', 'anomalies' => 'anomalies',
        'previsions' => 'previsions', 'comptabilite' => 'comptabilite', 'export-fec' => 'fec',
        'facturx' => 'facturx', 'financements' => 'financements', 'subventions' => 'subventions',
        'messages' => 'messages', 'communication' => 'communication', 'prospection' => 'prospection',
        'mon-asso-qr' => 'qr', 'coach-ia' => 'coach', 'support' => 'support',
        'parametres' => 'parametres', 'admin' => 'admin', 'mon-asso-sso' => 'wordpress',
        'archives' => 'archives', 'abonnement' => 'abonnement',
    ];
    return ak_nav_icone($corresp[$cle] ?? 'projets', 14);
}

/**
 * Affiche les familles. Une famille sans entrée visible n'est pas rendue.
 */
function ak_nav_rendre(array $ctx): void
{
    $active = (string)($ctx['active'] ?? '');

    foreach (ak_nav_familles($ctx) as $famille) {
        // [0]=clé [1]=url [2]=libellé [3]=visible [4]=clés actives [5]=badge [6]=badge rouge
        $entrees = array_values(array_filter($famille['entrees'], fn($e) => !empty($e[3])));
        if (!$entrees) continue;

        $contientActive = false;
        // Les badges rouges signalent quelque chose à traiter (tickets de
        // support non lus). Repliée, la famille les cacherait : on les
        // remonte sur l'en-tête, sinon l'alerte ne se voit plus jamais.
        $urgencesCachees = 0;
        foreach ($entrees as $e) {
            if (in_array($active, $e[4], true)) $contientActive = true;
            if (!empty($e[6])) $urgencesCachees += max(0, (int)($e[5] ?? 0));
        }
        // La famille de la page courante s'ouvre d'elle-même. Les autres
        // restent fermées ici ; le script peut en rouvrir une au chargement
        // si l'utilisateur en avait laissé une ouverte.
        $classes = 'ak-nav-fam'
                 . ($contientActive ? ' contient-active est-ouverte' : '');
        ?>
        <div class="<?= $classes ?>" data-fam="<?= h($famille['cle']) ?>">
          <button type="button" class="ak-nav-fam-tete" aria-expanded="<?= $contientActive ? 'true' : 'false' ?>">
            <?= $famille['icone'] ?>
            <span class="ak-nav-fam-nom"><?= h($famille['label']) ?></span>
            <?php if ($urgencesCachees > 0): ?>
              <span class="sb-badge ak-nav-fam-urgence" style="background:#EF4444;color:#fff;"><?= $urgencesCachees ?></span>
            <?php endif; ?>
            <span class="ak-nav-fam-pastille" aria-hidden="true"></span>
            <span class="ak-nav-fam-chevron"><?= ak_nav_icone('chevron', 14) ?></span>
          </button>
          <div class="ak-nav-fam-corps">
            <?php foreach ($entrees as $e):
                $estActive = in_array($active, $e[4], true);
                $badge     = $e[5] ?? null;
                $urgent    = !empty($e[6]);
            ?>
            <a href="<?= h($e[1]) ?>" class="sb-link<?= $estActive ? ' active' : '' ?>"<?= $estActive ? ' aria-current="page"' : '' ?>>
              <?= ak_nav_icone_entree($e[0]) ?>
              <?= h($e[2]) ?>
              <?php if ($badge !== null && (int)$badge > 0): ?>
                <span class="sb-badge"<?= $urgent ? ' style="background:#EF4444;color:#fff;"' : '' ?>><?= (int)$badge ?></span>
              <?php endif; ?>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php
    }

    ak_nav_script();
}

/**
 * Le comportement de l'accordéon. Émis une seule fois par page.
 */
function ak_nav_script(): void
{
    static $dejaFait = false;
    if ($dejaFait) return;
    $dejaFait = true;
    ?>
<script>
(function () {
  var nav = document.currentScript && document.currentScript.parentNode;
  var familles = Array.prototype.slice.call(document.querySelectorAll('.ak-nav-fam'));
  if (!familles.length) return;

  var CLE = 'ak-nav-famille-ouverte';

  function lire() {
    try { return localStorage.getItem(CLE); } catch (e) { return null; }
  }
  function ecrire(v) {
    // Navigation privée, stockage bloqué : le menu doit continuer de marcher.
    try { v ? localStorage.setItem(CLE, v) : localStorage.removeItem(CLE); } catch (e) {}
  }

  function ouvrir(fam, ouverte) {
    fam.classList.toggle('est-ouverte', ouverte);
    var tete = fam.querySelector('.ak-nav-fam-tete');
    if (tete) tete.setAttribute('aria-expanded', ouverte ? 'true' : 'false');
  }

  // Une seule famille ouverte à la fois : c'est tout l'intérêt, le menu
  // ne peut jamais redevenir une longue liste.
  function nOuvrirQue(cible) {
    familles.forEach(function (f) { ouvrir(f, f === cible); });
    ecrire(cible ? cible.getAttribute('data-fam') : null);
  }

  // Au chargement : la famille de la page courante prime — elle est déjà
  // ouverte côté serveur. Sinon on rouvre celle que l'utilisateur avait
  // laissée ouverte.
  var active = familles.filter(function (f) { return f.classList.contains('contient-active'); })[0];
  if (active) {
    ecrire(active.getAttribute('data-fam'));
  } else {
    var memo = lire();
    if (memo) {
      var f = familles.filter(function (x) { return x.getAttribute('data-fam') === memo; })[0];
      if (f) ouvrir(f, true);
    }
  }

  familles.forEach(function (fam) {
    var tete = fam.querySelector('.ak-nav-fam-tete');
    if (!tete) return;
    tete.addEventListener('click', function () {
      var etaitOuverte = fam.classList.contains('est-ouverte');
      nOuvrirQue(etaitOuverte ? null : fam);
    });
  });
})();
</script>
    <?php
}

}
