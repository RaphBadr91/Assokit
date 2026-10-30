<?php
/**
 * aide-paiements-en-ligne.php — /aide/paiements-en-ligne
 * --------------------------------------------------------------
 * Guide public « Paiements en ligne » pour les associations (Stripe Connect) : relier le compte Stripe, choisir
 * ce qui se paie en ligne, factures, cotisations, dons, remboursements, litiges, comptabilité.
 *
 * Une seule source : docs/paiements-en-ligne-associations.md (le .htaccess refuse les .md en HTTP ; ce fichier
 * le lit sur le disque et l'affiche avec la mise en page du site). Modifier le guide = modifier le .md.
 * Lien depuis « Paiements en ligne » (encaissement-en-ligne.php) et la fiche d'une campagne (cotisation-detail.php).
 * Page publique, sans connexion : aucune donnée de compte.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes-public.php';

/**
 * Markdown du guide → HTML (sous-ensemble utilisé par le guide : titres #, ##, ###, paragraphes, listes à puces
 * et numérotées, tableaux, **gras**, `code`, ---). Le texte est échappé avant toute mise en forme.
 */
function ak_guide_md_inline(string $t): string {
    $t = htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t);
    $t = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $t);
    // Adresses écrites en clair dans le guide (stripe.com/fr/pricing, contact@assokit.fr)
    $t = preg_replace('#\b(stripe\.com/fr/pricing)\b#', '<a href="https://$1" target="_blank" rel="noopener">$1</a>', $t);
    $t = preg_replace('#\b(contact@assokit\.fr)\b#', '<a href="mailto:$1">$1</a>', $t);
    return $t;
}

function ak_guide_md(string $md, string &$title): string {
    $out = [];
    $lines = preg_split("/\r?\n/", $md);
    $n = count($lines);
    $para = [];
    $flush = function () use (&$para, &$out) {
        if ($para) { $out[] = '<p>' . ak_guide_md_inline(implode(' ', $para)) . '</p>'; $para = []; }
    };
    for ($i = 0; $i < $n; $i++) {
        $l = rtrim($lines[$i]);
        if ($l === '') { $flush(); continue; }
        if (preg_match('/^# (.+)$/', $l, $m)) { $flush(); $title = $m[1]; continue; }
        if (preg_match('/^(#{2,3}) (.+)$/', $l, $m)) {
            $flush();
            $lvl = strlen($m[1]);
            $id = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $m[2]) ?: '')), '-');
            $out[] = '<h' . $lvl . ($id !== '' ? ' id="' . $id . '"' : '') . '>' . ak_guide_md_inline($m[2]) . '</h' . $lvl . '>';
            continue;
        }
        if ($l === '---') { $flush(); $out[] = '<hr>'; continue; }
        if (preg_match('/^\|/', $l)) {
            $flush();
            $rows = [];
            while ($i < $n && preg_match('/^\|/', rtrim($lines[$i]))) { $rows[] = rtrim($lines[$i]); $i++; }
            $i--;
            $html = '<div class="guide-tbl"><table>';
            foreach ($rows as $k => $r) {
                if (preg_match('/^\|[\s:|-]+\|$/', $r)) continue;
                $cells = array_map('trim', explode('|', trim($r, '|')));
                $tag = $k === 0 ? 'th' : 'td';
                $html .= '<tr>' . implode('', array_map(fn($c) => '<' . $tag . '>' . ak_guide_md_inline($c) . '</' . $tag . '>', $cells)) . '</tr>';
            }
            $out[] = $html . '</table></div>';
            continue;
        }
        if (preg_match('/^(\s*)(-|\d+\.) (.+)$/', $l)) {
            $flush();
            // Liste (et sous-liste à puces, indentée de 3 espaces)
            $ordered = (bool)preg_match('/^\d+\./', ltrim($l));
            $html = $ordered ? '<ol>' : '<ul>';
            $open_li = false; $sub = false;
            while ($i < $n && preg_match('/^(\s*)(-|\d+\.) (.+)$/', rtrim($lines[$i]), $m)) {
                if (strlen($m[1]) >= 2) {
                    if (!$sub) { $html .= '<ul>'; $sub = true; }
                    $html .= '<li>' . ak_guide_md_inline($m[3]) . '</li>';
                } else {
                    if ($sub) { $html .= '</ul>'; $sub = false; }
                    if ($open_li) $html .= '</li>';
                    $html .= '<li>' . ak_guide_md_inline($m[3]);
                    $open_li = true;
                }
                $i++;
            }
            $i--;
            if ($sub) $html .= '</ul>';
            if ($open_li) $html .= '</li>';
            $out[] = $html . ($ordered ? '</ol>' : '</ul>');
            continue;
        }
        $para[] = trim($l);
    }
    $flush();
    return implode("\n", $out);
}

$title = 'Paiements en ligne : guide pour les associations';
$md = (string)@file_get_contents(__DIR__ . '/docs/paiements-en-ligne-associations.md');
$body = $md !== '' ? ak_guide_md($md, $title) : '<p>Le guide est momentanément indisponible. Écrivez-nous à <a href="mailto:contact@assokit.fr">contact@assokit.fr</a>.</p>';

render_public_head([
    'title'       => 'Paiements en ligne des associations — guide Assokit',
    'description' => 'Relier le compte Stripe de votre association à Assokit et faire payer en ligne cotisations, dons et factures : étapes, frais, remboursements, comptabilité.',
    'path'        => '/aide/paiements-en-ligne',
]);
render_public_nav('');
?>
<section class="pub-hero" style="padding: 50px 0 20px;">
  <div class="pub-container">
    <div class="pub-breadcrumb"><a href="/">Accueil</a><span class="pub-breadcrumb-sep">›</span><strong style="color:var(--c-encre);">Paiements en ligne</strong></div>
    <h1 class="pub-h1" style="font-size:34px;"><?= pub_h($title) ?></h1>
  </div>
</section>
<section class="pub-section" style="padding-top:0;">
  <div class="pub-container-narrow">
    <article class="pub-article-content guide-paiements">
      <?= $body ?>
    </article>
  </div>
</section>
<style>
.guide-paiements .guide-tbl { overflow-x: auto; margin: 0 0 18px; }
.guide-paiements table { width: 100%; border-collapse: collapse; font-size: 15px; min-width: 520px; }
.guide-paiements th, .guide-paiements td { text-align: left; vertical-align: top; padding: 10px 12px; border-bottom: 1px solid #E7E5E4; }
.guide-paiements th { background: #F5F5F4; font-size: 13px; }
.guide-paiements code { background: #F5F5F4; padding: 1px 5px; border-radius: 4px; font-size: .92em; }
.guide-paiements hr { border: 0; border-top: 1px solid #E7E5E4; margin: 28px 0; }
</style>
<?php render_public_footer(); render_public_foot(); ?>
