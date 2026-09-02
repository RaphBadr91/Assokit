<?php
require_once __DIR__ . '/ak-icons.php';   // jeu d'icônes maison (ak_icon, ak_icon_badge, ak_dot)

if (!function_exists('ak_render_rich_text')) {
    /**
     * Affiche proprement un texte pouvant contenir du HTML brut issu d'une
     * synchro (Google Calendar / Outlook) : convertit les balises de bloc en
     * sauts de ligne, retire tout le HTML (anti-XSS), puis rend cliquables les
     * URLs, emails et numéros de téléphone. Sûr : aucune balise d'origine ne
     * survit (strip_tags avant échappement).
     */
    function ak_render_rich_text($raw): string {
        $s = (string)$raw;
        if ($s === '') return '';
        // Balises de bloc / <br> -> sauts de ligne ; puces de liste
        $s = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $s);
        $s = preg_replace('#</\s*(p|div|li|tr|h[1-6]|ul|ol)\s*>#i', "\n", $s);
        $s = preg_replace('#<\s*li[^>]*>#i', "• ", $s);
        // Retire toute balise restante puis décode les entités
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Nettoie les espaces/sauts multiples
        $s = preg_replace("/[ \t]+\n/", "\n", $s);
        $s = preg_replace("/\n{3,}/", "\n\n", $s);
        $s = trim($s);
        if ($s === '') return '';
        // Échappe AVANT d'insérer nos propres liens (aucun HTML d'origine ne subsiste)
        $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $lnk = 'color:#059669;text-decoration:underline;';
        // URLs
        $s = preg_replace_callback('#\bhttps?://[^\s<]+#i', function ($m) use ($lnk) {
            $u = rtrim($m[0], '.,);');
            return '<a href="' . $u . '" target="_blank" rel="noopener" style="' . $lnk . '">' . $u . '</a>';
        }, $s);
        // Emails
        $s = preg_replace('#(?<![\w.])([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})#', '<a href="mailto:$1" style="' . $lnk . '">$1</a>', $s);
        // Téléphones (FR/international) — évite de matcher à l'intérieur d'un lien déjà posé
        $s = preg_replace_callback('#(?<![\w/:=."+>])(\+?\d[\d ().\-]{7,}\d)#', function ($m) use ($lnk) {
            $disp = $m[0];
            $tel = preg_replace('/[^\d+]/', '', $disp);
            return '<a href="tel:' . $tel . '" style="' . $lnk . '">' . $disp . '</a>';
        }, $s);
        return nl2br($s);
    }
}

if (!function_exists('fr_format_date')) {
    function fr_format_date($fmt, $ts) {
        $jours = ['Sunday'=>'dimanche','Monday'=>'lundi','Tuesday'=>'mardi','Wednesday'=>'mercredi','Thursday'=>'jeudi','Friday'=>'vendredi','Saturday'=>'samedi'];
        $mois = ['January'=>'janvier','February'=>'février','March'=>'mars','April'=>'avril','May'=>'mai','June'=>'juin','July'=>'juillet','August'=>'août','September'=>'septembre','October'=>'octobre','November'=>'novembre','December'=>'décembre'];
        $mois_court = ['Jan'=>'janv.','Feb'=>'févr.','Mar'=>'mars','Apr'=>'avr.','May'=>'mai','Jun'=>'juin','Jul'=>'juil.','Aug'=>'août','Sep'=>'sept.','Oct'=>'oct.','Nov'=>'nov.','Dec'=>'déc.'];
        $r = $fmt;
        $r = str_replace('%A', $jours[date('l', $ts)] ?? '', $r);
        $r = str_replace('%B', $mois[date('F', $ts)] ?? '', $r);
        $r = str_replace('%b', $mois_court[date('M', $ts)] ?? '', $r);
        $r = str_replace('%d', date('d', $ts), $r);
        $r = str_replace('%Y', date('Y', $ts), $r);
        $r = str_replace('%H', date('H', $ts), $r);
        $r = str_replace('%M', date('i', $ts), $r);
        $r = str_replace('%m', date('m', $ts), $r);
        return $r;
    }
}

/**
 * ============================================================
 * ASSOKIT — Éléments partagés entre les pages connectées
 * ============================================================
 * Ce fichier contient :
 *   - render_head($title)    : HTML <head> + <style> commun
 *   - render_sidebar($active): la barre latérale de navigation
 *   - render_mobile_bar()    : la barre du haut sur mobile
 * ============================================================
 * Inclus dans dashboard.php, projets.php, projet.php, etc.
 * ============================================================
 */

if (!defined('SITE_NAME')) {
    require_once __DIR__ . '/config.php';
}

// === Tracking activité utilisateur (Fondateur audit) ===
if (file_exists(__DIR__ . '/activity-tracker.php')) {
    require_once __DIR__ . '/activity-tracker.php';
}

// === Bandeau MODE DÉMO ===
if (file_exists(__DIR__ . '/demo-banner.php')) {
    require_once __DIR__ . '/demo-banner.php';
}

/**
 * Renvoie les balises <link> pour le favicon Assokit unifie.
 * A appeler dans le <head> de TOUTES les pages du site.
 *
 * Design : carré vert émeraude (#059669) arrondi (style iOS),
 *          avec un cercle blanc en bas à droite (marque Assokit).
 *
 * Aucun fichier externe nécessaire : tout est en data-URI SVG.
 */
function assokit_favicon() {
    // SVG principal (affiche par les navigateurs modernes)
    $svg_main = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect x='2' y='2' width='28' height='28' rx='7' fill='%23059669'/%3E%3Ccircle cx='22' cy='22' r='4.5' fill='%23FFFFFF'/%3E%3C/svg%3E";

    // Apple Touch Icon (180x180, plus grand, pour iOS add-to-homescreen)
    $svg_apple = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 180 180'%3E%3Crect width='180' height='180' rx='40' fill='%23059669'/%3E%3Ccircle cx='125' cy='125' r='25' fill='%23FFFFFF'/%3E%3C/svg%3E";

    return '<link rel="icon" type="image/svg+xml" href="' . $svg_main . '">'
         . '<link rel="apple-touch-icon" href="' . $svg_apple . '">';
}

/**
 * Affiche le <head> + tous les styles CSS communs.
 */
function render_head($page_title) {
    // Track la pageview (silencieux si tracking pas dispo)
    if (function_exists('activity_track_pageview') && !empty($_SESSION['user_id'])) {
        activity_track_pageview();
    }
    
    // Le bandeau démo s'affichera après <body> (voir hook plus bas)
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light">
<meta name="theme-color" content="#059669" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#065F46" media="(prefers-color-scheme: dark)">
<meta name="robots" content="noindex, nofollow">
<title><?= h($page_title) ?> — Assokit</title>
<?= assokit_favicon() ?>

<!-- 📱 PWA -->
<link rel="manifest" href="/manifest.json">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Assokit">
<meta name="mobile-web-app-capable" content="yes">
<link rel="apple-touch-icon" sizes="192x192" href="/icons/icon-192.png">
<link rel="apple-touch-icon" sizes="512x512" href="/icons/icon-512.png">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600&display=swap" rel="stylesheet">
<!-- DARK MODE DISABLED — ak-theme-early-init removed -->
<!-- DARK MODE DISABLED -- <link rel="stylesheet" href="/css/dark-fixes.css?v=1778772802"> -->
<style>
*, *::before, *::after { box-sizing: border-box; }
html { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
body { margin: 0; padding: 0; }
button { font: inherit; cursor: pointer; border: none; background: none; color: inherit; }
a { color: inherit; text-decoration: none; }

:root {
  --acc: #059669; --acc-hover: #047857; --acc-light: #D1FAE5; --acc-dark: #047857;
  --ai: #6366F1; --ai-light: #ECEBFE; --ai-dark: #4338CA;
  --ink: #0B1A13; --ink-2: #45544D; --ink-3: #78857F; --ink-4: #A6B0AA;
  --bg: #FFFFFF; --bg-2: #EDF2EF; --bg-3: #E6EDE9;
  --border: rgba(12, 40, 28, 0.07); --border-strong: rgba(12, 40, 28, 0.13);
  --radius: 12px; --radius-lg: 18px;
  --glass: rgba(255,255,255,0.72); --glass-border: rgba(255,255,255,0.65);
  --shadow-card: 0 1px 2px rgba(9,30,22,0.04), 0 14px 34px -16px rgba(9,30,22,0.16);
  --shadow-pop: 0 2px 8px rgba(9,30,22,0.06), 0 26px 56px -20px rgba(9,30,22,0.24);
  --font-sans: 'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  --sidebar-w: 240px;
}
/* DARK MODE DISABLED 2026-05-14 - @media prefers-color-scheme dark block neutralized
@media (prefers-color-scheme: dark) {
  :root {
    --ink: #FAFAFA; --ink-2: #D4D4D8; --ink-3: #A1A1AA; --ink-4: #71717A;
    --bg: #0A0A0B; --bg-2: #131315; --bg-3: #1C1C1F;
    --border: rgba(255, 255, 255, 0.06); --border-strong: rgba(255, 255, 255, 0.12);
    --acc-light: rgba(5, 150, 105, 0.14); --ai-light: rgba(127, 119, 221, 0.14);
  }
}
*/
body { font-family: var(--font-sans); color: var(--ink); font-size: 14px; line-height: 1.55; letter-spacing: -0.005em;
  background:
    radial-gradient(58% 52% at 8% 0%, rgba(16,185,129,0.11), transparent 60%),
    radial-gradient(50% 48% at 96% 1%, rgba(99,102,241,0.10), transparent 62%),
    radial-gradient(55% 55% at 88% 100%, rgba(16,185,129,0.07), transparent 60%),
    var(--bg-2);
  background-attachment: fixed; }
.app { display: grid; grid-template-columns: var(--sidebar-w) 1fr; min-height: 100vh; }

/* SIDEBAR */
.sidebar { background: var(--glass); backdrop-filter: blur(24px) saturate(1.5); -webkit-backdrop-filter: blur(24px) saturate(1.5); border-right: 1px solid var(--glass-border); padding: 28px 12px 18px; display: flex; flex-direction: column; gap: 20px; position: sticky; top: 0; height: 100vh; overflow-y: auto; }
.sb-backto-sa:first-child { margin-top: 10px; }
.sb-logo { display: inline-flex; align-items: center; gap: 11px; font-weight: 700; font-size: 18px; letter-spacing: -0.02em; padding: 4px 10px; color: var(--ink); }
.sb-logo i { font-style: normal; color: var(--acc); }
.sb-search {
    display: flex; align-items: center; gap: 8px;
    width: 100%;
    margin: 8px 0 12px;
    padding: 8px 10px;
    background: var(--bg-2, #f5f6f7);
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 8px;
    color: var(--ink-3, #6b7280);
    font-size: 12.5px;
    font-family: inherit;
    cursor: pointer;
    transition: border-color 0.15s ease, background 0.15s ease;
}
.sb-search:hover { border-color: var(--ink-3, #9ca3af); background: #fff; }
.sb-search-lbl { flex: 1; text-align: left; }
.sb-search-kbd {
    font-family: inherit; font-size: 10px; font-weight: 600;
    padding: 1px 6px; background: #fff; color: var(--ink-3, #6b7280);
    border-radius: 4px; border: 1px solid var(--border, #e5e7eb);
}
.sb-logo-mark { width: 30px; height: 30px; background: linear-gradient(140deg, #12CE93, #059669 58%, #025C43); border-radius: 10px; position: relative; box-shadow: 0 6px 14px -3px rgba(5,150,105,0.55), inset 0 1px 0 rgba(255,255,255,0.5); flex-shrink: 0; }
.sb-logo-mark::after { content: ""; position: absolute; right: 6px; bottom: 6px; width: 8px; height: 8px; background: #fff; border-radius: 50%; opacity: 0.95; }

/* ===== Bandeau retour cockpit (Super Admin / Fondateur) ===== */
.sb-backto-sa { display: block; margin: 0 14px; padding: 10px 11px; border-radius: 10px; text-decoration: none; transition: transform 0.15s, box-shadow 0.15s; position: relative; overflow: visible; line-height: 1.35; }
.sb-backto-sa:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.sb-backto-sa--founder { background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%); border: 1px solid #FCD34D; }
.sb-backto-sa--sa { background: linear-gradient(135deg, #EEEDFE 0%, #E0DDFC 100%); border: 1px solid #C4B5FD; }
.sb-backto-sa-label { font-size: 9.5px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; line-height: 1.6; display: flex; align-items: center; gap: 5px; white-space: nowrap; overflow: visible; }
.sb-backto-sa--founder .sb-backto-sa-label { color: #92400E; }
.sb-backto-sa--sa .sb-backto-sa-label { color: #5B52A6; }
.sb-backto-sa-title { font-size: 11.5px; font-weight: 500; margin-top: 2px; display: flex; align-items: center; justify-content: space-between; gap: 6px; white-space: nowrap; overflow: hidden; }
.sb-backto-sa--founder .sb-backto-sa-title { color: #78350F; }
.sb-backto-sa--sa .sb-backto-sa-title { color: #3C3489; }
.sb-backto-sa-title > span:first-child { overflow: hidden; text-overflow: ellipsis; }
.sb-backto-sa-arrow { font-size: 13px; opacity: 0.7; flex-shrink: 0; }

/* ===== Groupe déroulant (Facturation) ===== */
.sb-group { display: flex; flex-direction: column; }
.sb-group-toggle { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 8px; color: var(--ink-2); font-size: 13px; font-weight: 500; cursor: pointer; user-select: none; transition: background 0.12s; width: 100%; text-align: left; }
.sb-group-toggle:hover { background: var(--bg-3); color: var(--ink); }
.sb-group-toggle.has-active { color: var(--acc-dark); }
.sb-group-chevron { margin-left: auto; transition: transform 0.2s; opacity: 0.5; }
.sb-group.open .sb-group-chevron { transform: rotate(180deg); }
.sb-group-items { display: none; flex-direction: column; padding-left: 10px; margin-top: 2px; gap: 1px; border-left: 1px solid var(--border); margin-left: 17px; }
.sb-group.open .sb-group-items { display: flex; }
.sb-group-items .sb-link { padding: 6px 10px; font-size: 12.5px; }
.ak-collapse { display: flex; flex-direction: column; }
.ak-collapse-row { display: flex; align-items: center; gap: 2px; }
.ak-collapse-toggle { background: transparent; border: 0; padding: 6px 8px; cursor: pointer; color: var(--ink-4); border-radius: 6px; display: flex; align-items: center; justify-content: center; transition: transform 0.2s, background 0.15s, opacity 0.15s; flex-shrink: 0; opacity: 0; }
.ak-collapse-row:hover .ak-collapse-toggle, .ak-collapse.is-open .ak-collapse-toggle { opacity: 1; }
.ak-collapse-toggle:hover { background: var(--bg-2); color: var(--ink); }
.ak-collapse.is-open .ak-collapse-toggle { transform: rotate(180deg); color: var(--acc); }
.ak-collapse-body { display: none; flex-direction: column; margin-left: 24px; border-left: 1px solid var(--border); padding-left: 8px; gap: 1px; margin-top: 2px; }
.ak-collapse.is-open .ak-collapse-body { display: flex; }


/* ===== MOBILE BURGER ===== */
.sb-mobile-header { display: none; position: sticky; top: 0; z-index: 50; background: var(--bg); border-bottom: 1px solid var(--border); padding: 10px 14px; align-items: center; gap: 12px; }
.sb-burger { width: 38px; height: 38px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; background: var(--bg-3); color: var(--ink); cursor: pointer; transition: background 0.15s; flex-shrink: 0; border: none; }
.sb-burger:hover { background: var(--border); }
.sb-mobile-title { display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 15px; }
.sb-mobile-title i { font-style: normal; color: var(--acc); }
.sb-mobile-title .sb-logo-mark { width: 18px; height: 18px; }
.sb-mobile-org { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--ink-3); padding: 5px 10px; background: var(--bg-3); border-radius: 8px; }
.sb-mobile-org-main { margin-left: 0; flex: 1; min-width: 0; font-size: 14px; font-weight: 500; color: var(--ink); padding: 6px 12px; }
.sb-mobile-org-name { max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sb-mobile-org-avatar { width: 22px; height: 22px; border-radius: 6px; background: linear-gradient(135deg, #FCD34D, #F59E0B); color: #78350F; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 10px; flex-shrink: 0; }
.sb-mobile-logout { width: 36px; height: 36px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; background: transparent; color: var(--ink-3); cursor: pointer; transition: all 0.15s; flex-shrink: 0; border: none; margin-left: auto; }
.sb-mobile-logout:hover { background: var(--bg-3); color: var(--ink); }
.sb-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.4); z-index: 99; }
.sb-overlay.active { display: block; }

@media (max-width: 900px) {
  .app { grid-template-columns: 1fr; }
  .sidebar { position: fixed; top: 0; left: -100%; width: 280px; height: 100vh; z-index: 100; transition: left 0.25s ease; box-shadow: 2px 0 24px rgba(0,0,0,0.08); }
  .sidebar.open { left: 0; }
  .sb-mobile-header { display: flex; }
  .main { padding: 16px 14px 60px !important; max-width: 100% !important; min-width: 0; overflow-x: hidden; width: 100%; box-sizing: border-box; }

  /* Force grilles à 1 colonne */
  .ak-kpis { grid-template-columns: 1fr 1fr !important; }
  .metrics, .folder-grid, .my-proj-grid { grid-template-columns: 1fr !important; }
  .proj-layout { grid-template-columns: 1fr !important; gap: 14px !important; }

  /* Force tout le contenu à respecter la largeur */
  .card, .ak-kpi, .ak-filters, .ak-table-wrap, .panel, .proj-desc, .step-desc {
    max-width: 100%; min-width: 0;
    word-wrap: break-word; overflow-wrap: break-word;
    word-break: break-word;
  }

  /* Texte long : casse les mots si nécessaire */
  .proj-desc, .step-desc, .panel p, .panel div, .chat-content {
    overflow-wrap: anywhere;
    word-break: break-word;
  }

  /* Tables : scroll horizontal interne */
  .ak-table-wrap, table { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .ak-table { min-width: 500px; }
  .ak-table th, .ak-table td { padding: 10px 12px !important; font-size: 12px !important; }

  /* Images respectent largeur */
  .main img { max-width: 100%; height: auto; }

  /* Empêche débordement global */
  body, html { overflow-x: hidden; max-width: 100vw; }
  .app { overflow-x: hidden; max-width: 100vw; }
  .main * { max-width: 100%; box-sizing: border-box; }
  
  /* Neutralise les styles inline avec width fixe */
  .main [style*="max-width:"] { max-width: 100% !important; }
  .main [style*="min-width: 280px"], .main [style*="min-width: 300px"],
  .main [style*="min-width: 220px"], .main [style*="min-width: 200px"] {
    min-width: 0 !important;
  }
  
  /* Panels : padding réduit pour gagner de la place */
  .panel { padding: 16px !important; }
  
  /* Big-progress-bar et autres barres de progression : full width container */
  .big-progress-bar-bg, .budget-bar-bg, .bar-bg, .folder-bar-bg, .mp-bar-bg { 
    width: 100% !important; 
    max-width: 100% !important; 
  }
  
  /* Main-head : aligner verticalement sur mobile */
  .main-head { flex-direction: column !important; align-items: stretch !important; }
  .head-actions { flex-wrap: wrap; }

}
@media (max-width: 540px) {
  .main { padding: 14px 10px 60px !important; }
  .ak-kpis { grid-template-columns: 1fr !important; }
  h1, .page-title, .ak-page-title { font-size: 22px !important; word-break: break-word; line-height: 1.2; }
  .page-sub { font-size: 12px !important; }
  .ak-page-head { flex-direction: column; align-items: stretch; gap: 12px; }
  .ak-page-actions { flex-wrap: wrap; }
  .card { padding: 14px !important; }
  
  /* Crumbs : autorise wrap */
  .crumbs { flex-wrap: wrap; font-size: 12px !important; }
  
  /* Tabs : padding réduit + scroll horizontal */
  .tabs { gap: 0 !important; padding-bottom: 2px; }
  .tab { padding: 8px 12px !important; font-size: 12.5px !important; }
  
  /* Steps : padding et fonts réduits */
  .step-list .step { padding: 10px 12px !important; }
  .step-title { font-size: 13px !important; }
  
  /* Forms : pleine largeur */
  .form-input-lg, .form-select-lg, .form-textarea-lg { 
    width: 100% !important; 
    box-sizing: border-box; 
  }
  
  /* Boutons : prennent plus de place */
  .btn { padding: 8px 14px !important; font-size: 13px !important; }
  
  /* Forcer flex-wrap sur tout ce qui pourrait déborder */
  .main-head, .ak-page-head, .form-actions, .head-actions,
  .panel-title, .step-builder-item, .chat-form {
    flex-wrap: wrap !important;
  }

  /* Grilles de formulaire à 2 colonnes en inline (Prénom/Nom, Tél/Ville…) :
     empilées sur petit écran pour ne pas tasser les champs. */
  .main [style*="grid-template-columns: 1fr 1fr"],
  .main [style*="grid-template-columns:1fr 1fr"] { grid-template-columns: 1fr !important; }

  /* Autres gabarits inline à colonnes multiples (2fr 1fr, repeat(3|4,1fr), 2fr auto auto) : empilés */
  .main [style*="grid-template-columns: 2fr"], .main [style*="grid-template-columns:2fr"],
  .main [style*="repeat(3, 1fr)"], .main [style*="repeat(3,1fr)"],
  .main [style*="repeat(4, 1fr)"], .main [style*="repeat(4,1fr)"] { grid-template-columns: 1fr !important; }

  /* Blocs « Total TTC » à marges négatives (facture/devis) : une fois empilés, ils débordaient du cadre */
  .main [style*="margin:-10px -14px"], .main [style*="margin: -10px -14px"] { margin: 0 !important; border-radius: 10px !important; }
}

/* ── Anti-débordement transverse (≤900px) — tables, lignes de facture, popovers, en-têtes d'actions ── */
@media (max-width: 900px) {
  /* Une table enfant d'une carte overflow:hidden ne pouvait pas défiler : on rend la carte scrollable */
  .main .card:has(> table), .main .card:has(> .ak-table), .main details:has(> table),
  .main [style*="overflow:hidden"]:has(> table), .main [style*="overflow: hidden"]:has(> table),
  .main .section:has(> table), .ak-table-wrap { overflow-x: auto !important; overflow-y: visible; -webkit-overflow-scrolling: touch; }

  /* Ligne de facture/devis (grille JS 1fr 70px 100px 120px 100px 30px = 420px de pistes fixes) */
  .main .invoice-line, .main [style*="1fr 70px 100px"] { grid-template-columns: 1fr 1fr !important; }
  .main .invoice-line > :first-child, .main [style*="1fr 70px 100px"] > :first-child { grid-column: 1 / -1; }
  .main .invoice-line > :last-child, .main [style*="1fr 70px 100px"] > :last-child { justify-self: end; }

  /* Rangées d'actions inline (plusieurs boutons) : passent à la ligne au lieu de déborder */
  .main [style*="display:flex"]:has(> .btn), .main [style*="display: flex"]:has(> .btn),
  .main [style*="display:flex"]:has(> a.btn), .main [style*="display:flex"]:has(> form > .btn) { flex-wrap: wrap; }

  /* Popovers ancrés à droite d'un bouton (partage / bilan / exports projet, menus) : pleine largeur du conteneur */
  .ck-actions-bar { position: relative; }
  .ck-bilan, .ck-share, .ck-exports { position: static !important; }
  .ck-bilan-pop, .ck-share-pop, .ck-exports-menu { left: 0; right: 0; width: auto !important; max-width: 100%; transform: none; top: calc(100% + 6px); }
  #mentionDropdown { max-width: calc(100vw - 24px); }

  /* Messagerie : liste des canaux basculable (le bouton ☰ est ajouté dans messages.php) */
  .msg-toggle { display: inline-flex !important; }
  .msg-channels.mobile-open { display: flex !important; position: absolute; inset: 0; z-index: 6; background: var(--bg); }
  .msg-layout, .msg-wrap { position: relative; }
}
.msg-toggle { display: none; }

/* ── Impression : factures, devis, bilans sans chrome d'interface ── */
@media print {
  .sidebar, .sb-mobile-header, .sidebar-overlay, .sb-overlay, #akCopFab, #akCopPanel, #notifToastContainer,
  .ak-trial-banner, #demo-banner, .btn, .head-actions, .tabs, [style*="position:sticky"], [style*="position: sticky"] { display: none !important; }
  .app { display: block !important; }
  .main { padding: 0 !important; max-width: none !important; }
  body { background: #fff !important; }
}
.sb-org { display: flex; align-items: center; gap: 10px; padding: 10px; background: var(--bg-2); border: 1px solid var(--border); border-radius: var(--radius); cursor: pointer; }
.sb-org:hover { background: var(--bg-3); }
.sb-org-avatar { width: 28px; height: 28px; border-radius: 7px; background: #FAC775; color: #633806; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 500; flex-shrink: 0; }
.sb-org-body { flex: 1; min-width: 0; text-align: left; }
.sb-org-name { font-size: 13.5px; font-weight: 700; letter-spacing: -0.01em; line-height: 1.2; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sb-org-role { font-size: 11px; color: var(--ink-3); line-height: 1.2; }
.sb-org-chevron { color: var(--ink-4); flex-shrink: 0; }
.sb-nav { display: flex; flex-direction: column; gap: 3px; }
.sb-link { display: flex; align-items: center; gap: 12px; padding: 9px 11px; border-radius: 11px; font-size: 13.5px; font-weight: 500; color: var(--ink-2); transition: background 0.12s ease, color 0.12s ease; }
.sb-link:hover { background: var(--bg-2); color: var(--ink); }
.sb-link.active { background: var(--acc-light); color: var(--acc-dark); font-weight: 600; }
.sb-link svg { color: var(--ink-3); flex-shrink: 0; }
.sb-link.active svg { color: var(--acc); }
.sb-badge { margin-left: auto; font-size: 11px; background: var(--acc); color: #fff; padding: 1px 8px; border-radius: 999px; font-weight: 700; }
.sb-link.active .sb-badge { background: var(--acc); color: #fff; }
.sb-foot { margin-top: auto; padding: 10px; display: flex; align-items: center; gap: 10px; border-top: 1px solid var(--border); }
.sb-user-avatar { width: 32px; height: 32px; border-radius: 50%; background: #B5D4F4; color: #0C447C; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 500; flex-shrink: 0; }
.sb-user-body { flex: 1; min-width: 0; }
.sb-user-name { font-size: 13px; font-weight: 700; letter-spacing: -0.01em; line-height: 1.2; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sb-user-email { font-size: 11px; color: var(--ink-3); line-height: 1.2; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sb-user-menu { color: var(--ink-4); padding: 4px; border-radius: 4px; }
.sb-user-menu:hover { background: var(--bg-2); color: var(--ink); }

/* MOBILE BAR */
.mobile-bar { display: none; align-items: center; justify-content: space-between; padding: 14px 18px; background: var(--bg); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 40; }
.mb-btn { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 8px; color: var(--ink-2); }
.mb-btn:hover { background: var(--bg-2); color: var(--ink); }
.sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.4); z-index: 45; }
.sidebar-overlay.open { display: block; }

/* CONTENT */
.main { padding: 32px 36px 56px; min-width: 0; max-width: 1280px; margin: 0 auto; width: 100%; }
.main-head { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 28px; gap: 16px; flex-wrap: wrap; }
.greet h1 { font-size: 28px; font-weight: 500; letter-spacing: -0.03em; margin: 0 0 4px; line-height: 1.1; }
.greet-ai { display: inline-flex; align-items: center; gap: 8px; font-size: 13.5px; color: var(--ink-3); margin-top: 8px; }
.greet-ai-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--ai); animation: pulse 2.5s ease-in-out infinite; }
.greet-ai em { font-style: normal; color: var(--ink-2); font-weight: 500; }
.head-actions { display: flex; gap: 10px; }
.btn { display: inline-flex; align-items: center; gap: 7px; font-size: 13.5px; font-weight: 500; padding: 9px 16px; border-radius: 9px; transition: transform 0.15s ease, opacity 0.15s ease, background 0.15s ease; letter-spacing: -0.01em; white-space: nowrap; }
.btn-primary { background: var(--ink); color: var(--bg); }
.btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }
.btn-ghost { background: var(--bg); color: var(--ink); border: 1px solid var(--border-strong); }
.btn-ghost:hover { background: var(--bg-2); }

/* AUJOURD'HUI */
.today { background: var(--bg); border: 1px solid var(--border); border-radius: 16px; padding: 24px 26px; margin-bottom: 24px; }
.today-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
.today-label { font-size: 11px; font-weight: 500; color: var(--acc); letter-spacing: 0.1em; text-transform: uppercase; display: inline-flex; align-items: center; gap: 7px; }
.today-label-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--acc); }
.today-date { font-size: 12px; color: var(--ink-3); }
.today-list { display: flex; flex-direction: column; }
.today-item { display: flex; align-items: center; gap: 14px; padding: 14px 0; border-top: 1px solid var(--border); cursor: pointer; transition: padding 0.15s ease; }
.today-item:first-child { padding-top: 4px; border-top: none; }
.today-item:hover { padding-left: 4px; }
.today-icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.today-icon.ic-ai { background: var(--ai-light); color: var(--ai); }
.today-icon.ic-urgent { background: #FEF3C7; color: #B45309; }
.today-icon.ic-info { background: var(--bg-3); color: var(--ink-2); }
/* DARK MODE DISABLED (orphelin neutralise) :
@media (prefers-color-scheme: dark) { .today-icon.ic-urgent { background: rgba(239, 159, 39, 0.15); color: #FAC775; } }
*/
.today-body { flex: 1; min-width: 0; }
.today-title { font-size: 14.5px; font-weight: 500; margin-bottom: 2px; line-height: 1.3; }
.today-meta { font-size: 12.5px; color: var(--ink-3); line-height: 1.4; }
.today-arrow { color: var(--ink-4); flex-shrink: 0; transition: transform 0.15s ease, color 0.15s ease; }
.today-item:hover .today-arrow { transform: translateX(2px); color: var(--ink-2); }

/* MES PROJETS */
.my-projects { margin-bottom: 32px; }
.my-proj-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.mp-card { background: var(--bg); border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; display: flex; flex-direction: column; gap: 10px; transition: border-color 0.15s ease, transform 0.15s ease; }
.mp-card:hover { border-color: var(--border-strong); transform: translateY(-1px); }
.mp-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
.mp-title-wrap { min-width: 0; }
.mp-folder-tag { font-size: 10.5px; color: var(--ink-3); letter-spacing: 0.04em; text-transform: uppercase; margin-bottom: 3px; font-weight: 500; }
.mp-title { font-size: 14.5px; font-weight: 500; letter-spacing: -0.01em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mp-role { font-size: 10.5px; background: var(--ai-light); color: var(--ai-dark); padding: 2px 8px; border-radius: 999px; font-weight: 500; white-space: nowrap; flex-shrink: 0; }
.mp-progress { display: flex; align-items: center; gap: 10px; }
.mp-bar-bg { flex: 1; height: 5px; background: var(--bg-3); border-radius: 3px; overflow: hidden; }
.mp-bar { height: 100%; background: var(--acc); border-radius: 3px; }
.mp-pct { font-size: 12px; font-weight: 500; min-width: 34px; text-align: right; font-variant-numeric: tabular-nums; }
.mp-foot { display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 1px solid var(--border); }
.mp-meta { font-size: 11.5px; color: var(--ink-3); }
.mp-update-btn { font-size: 12px; font-weight: 500; color: var(--acc-dark); background: var(--acc-light); padding: 5px 11px; border-radius: 7px; transition: background 0.15s ease; display: inline-flex; align-items: center; gap: 5px; }
.mp-update-btn:hover { background: var(--acc); color: #fff; }

/* SECTION TITLE */
.section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 12px; }
.section-head h2 { font-size: 18px; font-weight: 500; letter-spacing: -0.02em; margin: 0; }
.section-head-meta { font-size: 13px; color: var(--ink-3); }
.section-head-meta a { color: var(--acc); font-weight: 500; }
.section-head-meta a:hover { text-decoration: underline; }

/* DOSSIERS */
.folder-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 14px; margin-bottom: 32px; }
.folder-card { background: var(--bg); border: 1px solid var(--border); border-radius: 14px; padding: 20px 22px; display: flex; flex-direction: column; gap: 14px; transition: border-color 0.15s ease, transform 0.15s ease; cursor: pointer; position: relative; }
.folder-card:hover { border-color: var(--border-strong); transform: translateY(-2px); }
.folder-head { display: flex; align-items: center; gap: 12px; }
.folder-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.folder-icon svg { width: 20px; height: 20px; }
/* 16 couleurs de dossiers (palette étendue) */
.fi-blue    { background: #B5D4F4; color: #0C447C; }
.fi-indigo  { background: #C7D2FE; color: #312E81; }
.fi-purple  { background: #CECBF6; color: #3C3489; }
.fi-magenta { background: #F5D0FE; color: #701A75; }
.fi-pink    { background: #F4C0D1; color: #72243E; }
.fi-red     { background: #FECACA; color: #7F1D1D; }
.fi-orange  { background: #FED7AA; color: #7C2D12; }
.fi-amber   { background: #FAC775; color: #633806; }
.fi-yellow  { background: #FEF08A; color: #713F12; }
.fi-lime    { background: #D9F99D; color: #365314; }
.fi-green   { background: #A7F3D0; color: #064E3B; }
.fi-emerald { background: #6EE7B7; color: #064E3B; }
.fi-teal    { background: #9FE1CB; color: #0F6E56; }
.fi-cyan    { background: #A5F3FC; color: #164E63; }
.fi-slate   { background: #CBD5E1; color: #1E293B; }
.fi-brown   { background: #D4A574; color: #5D2F0E; }
.folder-title-wrap { min-width: 0; flex: 1; }
.folder-title { font-size: 16px; font-weight: 500; letter-spacing: -0.015em; line-height: 1.2; margin-bottom: 2px; }
.folder-count { font-size: 12px; color: var(--ink-3); }
.folder-progress { display: flex; align-items: center; gap: 10px; }
.folder-bar-bg { flex: 1; height: 5px; background: var(--bg-3); border-radius: 3px; overflow: hidden; }
.folder-bar { height: 100%; background: var(--acc); border-radius: 3px; }
.folder-pct { font-size: 12px; color: var(--ink-2); min-width: 34px; text-align: right; font-variant-numeric: tabular-nums; }
.folder-foot { display: flex; justify-content: space-between; align-items: center; padding-top: 12px; border-top: 1px solid var(--border); font-size: 12px; color: var(--ink-3); }
.folder-foot .dot { color: var(--ink-4); margin: 0 5px; }

/* MÉTRIQUES */
.metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
.metric { background: var(--bg); border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; }
.metric-lbl { font-size: 11.5px; color: var(--ink-3); margin-bottom: 8px; }
.metric-val { font-size: 28px; font-weight: 500; letter-spacing: -0.025em; line-height: 1.05; font-variant-numeric: tabular-nums; }
.metric-sub { font-size: 11.5px; color: var(--ink-4); margin-top: 6px; }
.metric-sub.up { color: var(--acc); }

/* PROJETS PAGE — accordéon */
.crumbs { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--ink-3); margin-bottom: 18px; }
.crumbs a { color: var(--ink-3); }
.crumbs a:hover { color: var(--ink); }
.crumbs .sep { color: var(--ink-4); }
.crumbs .current { color: var(--ink); font-weight: 500; }
.page-title { font-size: 28px; font-weight: 500; letter-spacing: -0.03em; line-height: 1.1; margin: 0 0 6px; }
.page-sub { font-size: 13.5px; color: var(--ink-3); }

.folder { background: var(--bg); border: 1px solid var(--border); border-radius: 14px; margin-bottom: 14px; overflow: hidden; }
.folder-btn { width: 100%; display: flex; align-items: center; gap: 14px; padding: 18px 22px; text-align: left; transition: background 0.15s ease; }
.folder-btn:hover { background: var(--bg-2); }
.folder-info { flex: 1; min-width: 0; }
.folder-name { font-size: 16px; font-weight: 500; letter-spacing: -0.015em; margin-bottom: 2px; }
.folder-meta { font-size: 12.5px; color: var(--ink-3); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.folder-meta .dot { color: var(--ink-4); }
.folder-stats { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }
.folder-pct-wrap { text-align: right; }
.folder-pct-lbl { font-size: 10.5px; color: var(--ink-4); letter-spacing: 0.04em; text-transform: uppercase; margin-bottom: 3px; }
.folder-pct-big { font-size: 16px; font-weight: 500; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; }
.folder-toggle { color: var(--ink-3); transition: transform 0.22s ease, color 0.15s ease; flex-shrink: 0; }
.folder.open .folder-toggle { transform: rotate(180deg); color: var(--ink); }
.folder-body { display: none; border-top: 1px solid var(--border); background: var(--bg-2); }
.folder.open .folder-body { display: block; }
.folder-body-head { display: flex; justify-content: space-between; align-items: center; padding: 14px 22px; font-size: 12.5px; color: var(--ink-3); }
.folder-body-head-l { display: flex; align-items: center; gap: 6px; }
.add-btn { font-size: 12.5px; font-weight: 500; color: var(--acc-dark); background: var(--acc-light); padding: 6px 12px; border-radius: 7px; transition: background 0.15s ease; display: inline-flex; align-items: center; gap: 5px; }
.add-btn:hover { background: var(--acc); color: #fff; }
.no-perm { font-size: 12px; color: var(--ink-4); display: inline-flex; align-items: center; gap: 5px; }
.project-list { padding: 0 22px 20px; display: flex; flex-direction: column; gap: 10px; }
.project-row { background: var(--bg); border: 1px solid var(--border); border-radius: 11px; padding: 14px 18px; display: grid; grid-template-columns: 1fr auto auto; gap: 16px; align-items: center; cursor: pointer; transition: border-color 0.15s ease, transform 0.15s ease; }
.project-row:hover { border-color: var(--border-strong); transform: translateY(-1px); }
.project-main { min-width: 0; }
.project-name { font-size: 14.5px; font-weight: 500; margin-bottom: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; letter-spacing: -0.01em; }
.project-meta { font-size: 12px; color: var(--ink-3); display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.project-meta .dot { color: var(--ink-4); }
.project-progress { display: flex; align-items: center; gap: 10px; min-width: 150px; }
.p-bar-bg { flex: 1; height: 5px; background: var(--bg-3); border-radius: 3px; overflow: hidden; }
.p-bar { height: 100%; background: var(--acc); border-radius: 3px; }
.p-bar.warn { background: #EF9F27; }
.p-pct { font-size: 12px; font-weight: 500; min-width: 36px; text-align: right; font-variant-numeric: tabular-nums; }
.project-badge { font-size: 10.5px; padding: 3px 9px; border-radius: 999px; font-weight: 500; white-space: nowrap; }
.badge-ok { background: var(--acc-light); color: var(--acc-dark); }
.badge-warn { background: #FEF3C7; color: #854F0B; }
.badge-done { background: var(--bg-3); color: var(--ink-3); }
/* DARK MODE DISABLED (orphelin neutralise) :
@media (prefers-color-scheme: dark) { .badge-warn { background: rgba(239, 159, 39, 0.15); color: #FAC775; } }
*/
.empty-state { padding: 40px 22px; text-align: center; color: var(--ink-3); font-size: 13px; }

/* ===== ADHÉRENTS / FACTURES ===== */
.toolbar { display: flex; gap: 10px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
.search-wrap { position: relative; flex: 1; min-width: 220px; max-width: 400px; }
.search-wrap svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--ink-4); }
.search-input { width: 100%; padding: 10px 14px 10px 38px; border: 1px solid var(--border-strong); border-radius: 9px; font-size: 14px; font-family: inherit; background: var(--bg); color: var(--ink); transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.search-input:focus { outline: none; border-color: var(--acc); box-shadow: 0 0 0 3px var(--acc-light); }
.filter-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.chip { font-size: 12.5px; font-weight: 500; padding: 7px 12px; border-radius: 8px; color: var(--ink-3); background: var(--bg); border: 1px solid var(--border); transition: all 0.15s ease; cursor: pointer; }
.chip:hover { color: var(--ink); border-color: var(--border-strong); }
.chip.active { background: var(--ink); color: var(--bg); border-color: var(--ink); }

/* Table-like list */
.list { background: var(--bg); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
.list-row { display: grid; gap: 16px; align-items: center; padding: 14px 20px; border-top: 1px solid var(--border); transition: background 0.12s ease; cursor: pointer; }
.list-row:first-child { border-top: none; }
.list-row:hover { background: var(--bg-2); }
/* Anti-débordement (tous écrans) : une valeur longue non sécable (email, URL,
   référence) ne doit jamais élargir la page. La piste `1fr` d'une grille vaut
   minmax(auto,1fr) — sans césure, son minimum = la largeur max-content de la
   valeur → débordement horizontal et clipping du bord droit sur mobile. On
   autorise la césure sur tous les enfants de .list-row et on les rend
   rétractables. Sans effet visible sur desktop (le contenu tient déjà). */
.list-row { min-width: 0; }
.list-row > * { min-width: 0; overflow-wrap: anywhere; word-break: break-word; }
.main a[href^="mailto:"]:not([class*="btn"]), .main a[href^="tel:"]:not([class*="btn"]) { overflow-wrap: anywhere; word-break: break-word; }
.list-row-header { background: var(--bg-2); cursor: default; font-size: 11px; font-weight: 500; color: var(--ink-3); letter-spacing: 0.04em; text-transform: uppercase; padding: 10px 20px; }
.list-row-header:hover { background: var(--bg-2); }

/* Adhérent row */
.adh-row { grid-template-columns: auto 1fr auto auto auto; }
.adh-row-header { grid-template-columns: auto 1fr auto auto auto; }
.adh-avatar { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 500; flex-shrink: 0; }
.av-blue { background: #B5D4F4; color: #0C447C; }
.av-purple { background: #CECBF6; color: #3C3489; }
.av-amber { background: #FAC775; color: #633806; }
.av-pink { background: #F4C0D1; color: #72243E; }
.av-teal { background: #9FE1CB; color: #0F6E56; }
.adh-main { min-width: 0; }
.adh-name { font-size: 14px; font-weight: 500; margin-bottom: 2px; line-height: 1.3; }
.adh-email { font-size: 12px; color: var(--ink-3); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.adh-city { font-size: 12.5px; color: var(--ink-2); }
.adh-role-badge { font-size: 10.5px; padding: 3px 9px; border-radius: 999px; font-weight: 500; white-space: nowrap; }
.role-admin { background: #FEF3C7; color: #854F0B; }
.role-coordinator { background: var(--ai-light); color: var(--ai-dark); }
.role-referent { background: var(--acc-light); color: var(--acc-dark); }
.role-member { background: var(--bg-3); color: var(--ink-2); }
/* DARK MODE DISABLED (orphelin neutralise) :
@media (prefers-color-scheme: dark) { .role-admin { background: rgba(239, 159, 39, 0.15); color: #FAC775; } }
*/
.adh-adh-date { font-size: 12px; color: var(--ink-3); font-variant-numeric: tabular-nums; }

/* Facture row */
.inv-row { grid-template-columns: 2fr 1.5fr 1fr auto auto; }
.inv-row-header { grid-template-columns: 2fr 1.5fr 1fr auto auto; }
.inv-main { min-width: 0; }
.inv-supplier { font-size: 14px; font-weight: 500; margin-bottom: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.inv-project { font-size: 12px; color: var(--ink-3); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.inv-cat { font-size: 12.5px; color: var(--ink-2); }
.inv-date { font-size: 12.5px; color: var(--ink-3); font-variant-numeric: tabular-nums; }
.inv-amount { font-size: 14px; font-weight: 500; font-variant-numeric: tabular-nums; min-width: 90px; text-align: right; }
.inv-status { font-size: 10.5px; padding: 3px 9px; border-radius: 999px; font-weight: 500; white-space: nowrap; }
.status-validated { background: var(--acc-light); color: var(--acc-dark); }
.status-pending { background: #FEF3C7; color: #854F0B; }
.status-rejected { background: rgba(185, 28, 28, 0.12); color: #B91C1C; }
/* DARK MODE DISABLED (orphelin neutralise) :
@media (prefers-color-scheme: dark) {
  .status-pending { background: rgba(239, 159, 39, 0.15); color: #FAC775; }
  .status-rejected { background: rgba(185, 28, 28, 0.2); color: #FCA5A5; }
}
*/

/* Stats bar */
.stats-bar { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 24px; }

/* Upload drop zone */
.drop-zone { border: 2px dashed var(--border-strong); border-radius: 14px; padding: 32px 20px; text-align: center; margin-bottom: 24px; background: var(--bg); transition: border-color 0.15s ease, background 0.15s ease; cursor: pointer; }
.drop-zone:hover { border-color: var(--acc); background: var(--acc-light); }
.drop-zone svg { color: var(--ink-3); margin-bottom: 10px; }
.drop-zone-title { font-size: 14.5px; font-weight: 500; margin-bottom: 4px; }
.drop-zone-sub { font-size: 12.5px; color: var(--ink-3); }
.drop-zone-ai { display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; color: var(--ai); margin-top: 10px; font-weight: 500; }

/* Mobile list adjustments */
@media (max-width: 720px) {
  .adh-row { grid-template-columns: auto 1fr auto; padding: 12px 16px; }
  .adh-row .adh-city, .adh-row-header .adh-city { display: none; }
  .adh-row .adh-adh-date, .adh-row-header .adh-adh-date { display: none; }
  .adh-row-header { grid-template-columns: auto 1fr auto; }
  .inv-row { grid-template-columns: 1fr auto; padding: 12px 16px; }
  .inv-row .inv-cat, .inv-row-header .inv-cat { display: none; }
  .inv-row .inv-date, .inv-row-header .inv-date { display: none; }
  .inv-row .inv-amount { font-size: 13px; min-width: 0; }
  .inv-row-header { grid-template-columns: 1fr auto; }
  .toolbar { flex-direction: column; align-items: stretch; }
  .search-wrap { max-width: 100%; }
}

.empty-state { padding: 40px 22px; text-align: center; color: var(--ink-3); font-size: 13px; }

/* ===== FICHE PROJET (projet.php) ===== */
.proj-header { display: flex; gap: 20px; align-items: flex-start; margin-bottom: 28px; flex-wrap: wrap; }
.proj-header-icon { width: 56px; height: 56px; border-radius: 14px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.proj-header-icon svg { width: 26px; height: 26px; }
.proj-header-info { flex: 1; min-width: 0; }
.proj-header-tag { font-size: 11px; font-weight: 500; color: var(--ink-3); letter-spacing: 0.06em; text-transform: uppercase; margin-bottom: 6px; }
.proj-header-title { font-size: 26px; font-weight: 500; letter-spacing: -0.025em; line-height: 1.15; margin: 0 0 8px; }
.proj-header-meta { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 13px; color: var(--ink-3); }
.proj-header-meta .dot { color: var(--ink-4); }
.proj-header-meta .referent-tag { display: inline-flex; align-items: center; gap: 6px; color: var(--ink-2); }
.proj-header-meta .ref-avatar { width: 20px; height: 20px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 9px; font-weight: 500; }

.proj-layout { display: grid; grid-template-columns: 1fr 300px; gap: 24px; }
@media (max-width: 960px) { .proj-layout { grid-template-columns: 1fr; } }

.panel { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 22px 24px; margin-bottom: 16px; box-shadow: var(--shadow-card); position: relative; }
/* Élévation premium partagée : les cartes principales du site gagnent la profondeur "Liquid Glass" */
.today, .mp-card, .folder-card, .metric, .folder, .cal-grid, .stat-card, .kpi-card, .ak-kpi-card { box-shadow: var(--shadow-card); }
.mp-card:hover, .folder-card:hover { box-shadow: var(--shadow-pop); }
.panel-title { font-size: 15px; font-weight: 500; letter-spacing: -0.01em; margin: 0 0 14px; display: flex; justify-content: space-between; align-items: center; }
.panel-title-actions { font-size: 12px; color: var(--acc); font-weight: 500; }
.panel-title-actions:hover { text-decoration: underline; }

.proj-desc { font-size: 14px; line-height: 1.65; color: var(--ink-2); white-space: pre-wrap; }
.proj-desc-empty { color: var(--ink-4); font-style: italic; }

.proj-objective { font-size: 14px; line-height: 1.65; color: var(--ink-2); padding: 14px 16px; background: var(--acc-light); border-radius: 10px; border-left: 3px solid var(--acc); }

.big-progress { margin-bottom: 4px; }
.big-progress-bar-bg { height: 10px; background: var(--bg-3); border-radius: 5px; overflow: hidden; margin-bottom: 8px; }
.big-progress-bar { height: 100%; background: linear-gradient(90deg, var(--acc) 0%, #10B981 100%); border-radius: 5px; transition: width 0.6s ease; }
.big-progress-bar.warn { background: linear-gradient(90deg, #EF9F27 0%, #F59E0B 100%); }
.big-progress-info { display: flex; justify-content: space-between; font-size: 12px; color: var(--ink-3); }
.big-progress-info b { color: var(--ink); font-weight: 500; font-variant-numeric: tabular-nums; }

/* Étapes */
.step-list { display: flex; flex-direction: column; }
.step-item { display: flex; gap: 12px; align-items: flex-start; padding: 14px 10px; border-top: 1px solid var(--border); transition: background 0.15s ease; margin: 0 -10px; border-radius: 8px; }
.step-item:first-child { border-top: none; }
.step-item:hover { background: var(--bg-2); }
.step-check { width: 22px; height: 22px; border-radius: 50%; border: 2px solid var(--border-strong); background: var(--bg); flex-shrink: 0; margin-top: 1px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.15s ease; padding: 0; }
.step-check:hover { border-color: var(--acc); }
.step-check.done { background: var(--acc); border-color: var(--acc); }
.step-check svg { opacity: 0; color: #fff; transition: opacity 0.15s ease; }
.step-check.done svg { opacity: 1; }
.step-check.readonly { cursor: not-allowed; opacity: 0.7; }
.step-check.readonly:hover { border-color: var(--border-strong); }
.step-body { flex: 1; min-width: 0; }
.step-title { font-size: 14px; font-weight: 500; margin-bottom: 3px; line-height: 1.4; }
.step-item.done .step-title { color: var(--ink-3); text-decoration: line-through; }
.step-desc { font-size: 12.5px; color: var(--ink-3); line-height: 1.5; margin-bottom: 4px; }
.step-meta { font-size: 11.5px; color: var(--ink-4); display: flex; align-items: center; gap: 6px; }

/* Sidebar droite fiche projet */
.side-panel { background: var(--bg); border: 1px solid var(--border); border-radius: 14px; padding: 18px 20px; margin-bottom: 14px; }
.side-panel-label { font-size: 11px; font-weight: 500; color: var(--ink-3); letter-spacing: 0.06em; text-transform: uppercase; margin-bottom: 10px; }
.side-panel-value { font-size: 22px; font-weight: 500; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; line-height: 1.1; }
.side-panel-sub { font-size: 12px; color: var(--ink-3); margin-top: 4px; }
.side-kv { display: flex; justify-content: space-between; padding: 8px 0; border-top: 1px solid var(--border); font-size: 13px; }
.side-kv:first-of-type { border-top: none; }
.side-kv-label { color: var(--ink-3); }
.side-kv-value { color: var(--ink); font-weight: 500; text-align: right; }
.side-participants-bars { display: flex; gap: 4px; height: 8px; border-radius: 4px; overflow: hidden; background: var(--bg-3); margin-top: 6px; }
.side-participants-bars .bar-f { background: #F4C0D1; }
.side-participants-bars .bar-m { background: #B5D4F4; }
.side-participants-legend { display: flex; justify-content: space-between; font-size: 11px; color: var(--ink-3); margin-top: 6px; }
.budget-bar-bg { height: 8px; background: var(--bg-3); border-radius: 4px; overflow: hidden; margin-top: 6px; }
.budget-bar { height: 100%; background: var(--acc); border-radius: 4px; }
.budget-bar.over { background: #EF9F27; }

/* ===== FORMULAIRES (nouveau projet / modifier) ===== */
.form-section { background: var(--bg); border: 1px solid var(--border); border-radius: 14px; padding: 26px 28px; margin-bottom: 16px; }
.form-section-title { font-size: 16px; font-weight: 500; letter-spacing: -0.015em; margin: 0 0 6px; }
.form-section-desc { font-size: 13px; color: var(--ink-3); margin: 0 0 20px; line-height: 1.5; }
.form-row { margin-bottom: 16px; }
.form-row:last-child { margin-bottom: 0; }
.form-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 540px) { .form-cols { grid-template-columns: 1fr; } }
.form-label { display: block; font-size: 13px; font-weight: 500; color: var(--ink-2); margin-bottom: 6px; }
.form-label .required { color: #B91C1C; }
.form-input-lg, .form-textarea-lg, .form-select-lg { width: 100%; padding: 10px 14px; border: 1px solid var(--border-strong); border-radius: 9px; font-size: 14px; font-family: inherit; background: var(--bg); color: var(--ink); transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.form-input-lg:focus, .form-textarea-lg:focus, .form-select-lg:focus { outline: none; border-color: var(--acc); box-shadow: 0 0 0 3px var(--acc-light); }
.form-textarea-lg { min-height: 100px; resize: vertical; line-height: 1.55; }
.form-hint { font-size: 12px; color: var(--ink-4); margin-top: 4px; }
.num-suffix-wrap { position: relative; }
.num-suffix-wrap .suffix { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); font-size: 13px; color: var(--ink-3); pointer-events: none; }
.num-suffix-wrap .form-input-lg { padding-right: 44px; }

/* Étapes dans le formulaire */
.steps-builder { display: flex; flex-direction: column; gap: 10px; }
.step-builder-item { display: flex; gap: 10px; align-items: center; padding: 10px 14px; background: var(--bg-2); border: 1px solid var(--border); border-radius: 10px; transition: border-color 0.15s ease; }
.step-builder-item:hover { border-color: var(--border-strong); }
.step-builder-num { width: 26px; height: 26px; border-radius: 50%; background: var(--bg-3); color: var(--ink-3); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 500; flex-shrink: 0; }
.step-builder-input { flex: 1; background: transparent; border: none; font-size: 14px; color: var(--ink); padding: 4px 0; outline: none; font-family: inherit; }
.step-builder-remove { background: transparent; color: var(--ink-4); padding: 4px; border-radius: 4px; cursor: pointer; transition: color 0.15s ease, background 0.15s ease; display: inline-flex; border: none; }
.step-builder-remove:hover { background: rgba(185, 28, 28, 0.1); color: #B91C1C; }
.add-step-btn { margin-top: 4px; padding: 10px 14px; background: var(--bg); border: 1px dashed var(--border-strong); border-radius: 10px; font-size: 13px; font-weight: 500; color: var(--ink-2); cursor: pointer; transition: all 0.15s ease; display: inline-flex; align-items: center; gap: 7px; width: 100%; justify-content: center; }
.add-step-btn:hover { border-color: var(--acc); color: var(--acc); background: var(--acc-light); }

.form-actions { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 18px 0; margin-top: 12px; border-top: 1px solid var(--border); flex-wrap: wrap; }
.form-actions-left { font-size: 12.5px; color: var(--ink-3); }
.form-actions-right { display: flex; gap: 10px; }

.alert { padding: 12px 16px; border-radius: 10px; font-size: 13.5px; margin-bottom: 18px; display: flex; gap: 10px; align-items: flex-start; line-height: 1.5; }
.alert svg { flex-shrink: 0; margin-top: 2px; }
.alert-error { background: rgba(185, 28, 28, 0.1); color: #B91C1C; border: 1px solid rgba(185, 28, 28, 0.2); }
.alert-success { background: var(--acc-light); color: var(--acc-dark); border: 1px solid rgba(5, 150, 105, 0.2); }
/* DARK MODE DISABLED (orphelin neutralise) :
@media (prefers-color-scheme: dark) { .alert-error { background: rgba(185, 28, 28, 0.15); color: #FCA5A5; } }
*/

/* ===== ONGLETS PROJET ===== */
.tabs { display: flex; gap: 2px; margin-bottom: 22px; border-bottom: 1px solid var(--border); overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
.tabs::-webkit-scrollbar { display: none; }
.tab { padding: 10px 16px; font-size: 13.5px; font-weight: 500; color: var(--ink-3); border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap; transition: color 0.15s ease, border-color 0.15s ease; display: inline-flex; align-items: center; gap: 7px; }
.tab:hover { color: var(--ink); }
.tab.active { color: var(--ink); border-bottom-color: var(--acc); }
.tab-badge { font-size: 10.5px; padding: 1px 7px; background: var(--bg-3); color: var(--ink-2); border-radius: 999px; font-weight: 500; }
.tab.active .tab-badge { background: var(--acc-light); color: var(--acc-dark); }
.tab-ai { color: var(--ai); }
.tab-ai.active { border-bottom-color: var(--ai); color: var(--ai-dark); }
/* DARK MODE DISABLED (orphelin neutralise) :
@media (prefers-color-scheme: dark) { .tab-ai.active { color: var(--ai); } }
*/

/* ===== CHAT MESSAGES ===== */
.chat-wrap { background: var(--bg); border: 1px solid var(--border); border-radius: 14px; display: flex; flex-direction: column; min-height: 500px; }
.chat-list { flex: 1; padding: 22px 24px; display: flex; flex-direction: column; gap: 16px; max-height: 600px; overflow-y: auto; }
.chat-msg { display: flex; gap: 10px; align-items: flex-start; }
.chat-avatar { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 500; flex-shrink: 0; margin-top: 2px; }
.chat-bubble { flex: 1; min-width: 0; }
.chat-head-line { display: flex; gap: 8px; align-items: baseline; margin-bottom: 4px; flex-wrap: wrap; }
.chat-author { font-size: 13px; font-weight: 500; }
.chat-time { font-size: 11.5px; color: var(--ink-4); }
.chat-content { font-size: 14px; line-height: 1.5; color: var(--ink-2); white-space: pre-wrap; word-break: break-word; }
.chat-content a { color: var(--acc); text-decoration: underline; }

.chat-form { border-top: 1px solid var(--border); padding: 14px 18px; display: flex; gap: 10px; align-items: flex-end; background: var(--bg-2); border-radius: 0 0 14px 14px; }
.chat-input { flex: 1; padding: 10px 14px; border: 1px solid var(--border-strong); border-radius: 10px; font-size: 14px; font-family: inherit; background: var(--bg); color: var(--ink); resize: none; min-height: 40px; max-height: 160px; line-height: 1.5; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.chat-input:focus { outline: none; border-color: var(--acc); box-shadow: 0 0 0 3px var(--acc-light); }
.chat-send { padding: 10px 16px; background: var(--acc); color: #fff; border-radius: 10px; font-size: 13.5px; font-weight: 500; white-space: nowrap; transition: background 0.15s ease; display: inline-flex; align-items: center; gap: 6px; border: none; cursor: pointer; }
.chat-send:hover { background: var(--acc-hover); }
.chat-send:disabled { opacity: 0.5; cursor: not-allowed; }

/* ===== FICHIERS ===== */
.files-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
.file-card { background: var(--bg); border: 1px solid var(--border); border-radius: 12px; padding: 16px; display: flex; gap: 12px; align-items: flex-start; transition: border-color 0.15s ease, transform 0.15s ease; cursor: pointer; text-decoration: none; color: inherit; }
.file-card:hover { border-color: var(--border-strong); transform: translateY(-1px); }
.file-icon { width: 40px; height: 40px; border-radius: 10px; background: var(--bg-3); color: var(--ink-3); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.file-icon.pdf { background: rgba(185, 28, 28, 0.1); color: #B91C1C; }
.file-icon.img { background: var(--ai-light); color: var(--ai-dark); }
.file-icon.doc { background: #B5D4F4; color: #0C447C; }
.file-info { flex: 1; min-width: 0; }
.file-name { font-size: 13.5px; font-weight: 500; margin-bottom: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.file-meta { font-size: 11.5px; color: var(--ink-3); }

/* ===== ASSISTANT IA ===== */
.ai-hero { background: linear-gradient(135deg, var(--ai-light) 0%, var(--bg) 100%); border: 1px solid var(--border); border-radius: 14px; padding: 24px; margin-bottom: 16px; display: flex; gap: 16px; align-items: flex-start; }
.ai-hero-icon { width: 44px; height: 44px; border-radius: 12px; background: var(--ai); color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ai-hero-body { flex: 1; }
.ai-hero-title { font-size: 17px; font-weight: 500; letter-spacing: -0.015em; margin: 0 0 4px; display: flex; align-items: center; gap: 10px; }
.ai-hero-badge { font-size: 10px; padding: 2px 8px; background: var(--ai); color: #fff; border-radius: 999px; font-weight: 500; letter-spacing: 0.04em; text-transform: uppercase; }
.ai-hero-desc { font-size: 13.5px; color: var(--ink-2); line-height: 1.55; margin: 0; }

.ai-actions-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px; margin-bottom: 22px; }
.ai-action-card { background: var(--bg); border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; text-align: left; display: flex; flex-direction: column; gap: 6px; transition: border-color 0.15s ease, transform 0.15s ease; cursor: pointer; width: 100%; font-family: inherit; }
.ai-action-card:hover { border-color: var(--ai); transform: translateY(-1px); }
.ai-action-card:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
.ai-action-emoji { font-size: 20px; margin-bottom: 4px; }
.ai-action-title { font-size: 14px; font-weight: 500; color: var(--ink); letter-spacing: -0.01em; }
.ai-action-desc { font-size: 12px; color: var(--ink-3); line-height: 1.5; }

/* Chat IA */
.ai-chat-wrap { background: var(--bg); border: 1px solid var(--border); border-radius: 14px; display: flex; flex-direction: column; min-height: 400px; }
.ai-chat-head { padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; justify-content: space-between; }
.ai-chat-head-left { display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 500; }
.ai-chat-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--ai); animation: pulse 2.5s ease-in-out infinite; }
.ai-chat-model { font-size: 11px; color: var(--ink-4); }
.ai-chat-list { flex: 1; padding: 22px 24px; display: flex; flex-direction: column; gap: 18px; max-height: 500px; overflow-y: auto; }
.ai-msg { display: flex; gap: 12px; align-items: flex-start; }
.ai-msg-avatar { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 11px; font-weight: 500; }
.ai-msg.user .ai-msg-avatar { background: #B5D4F4; color: #0C447C; }
.ai-msg.assistant .ai-msg-avatar { background: var(--ai); color: #fff; }
.ai-msg-content { flex: 1; min-width: 0; font-size: 14px; line-height: 1.65; color: var(--ink); white-space: pre-wrap; }
.ai-msg-content h1, .ai-msg-content h2, .ai-msg-content h3 { font-size: 15px; font-weight: 500; margin: 12px 0 6px; letter-spacing: -0.015em; }
.ai-msg-content p { margin: 0 0 10px; }
.ai-msg-content ul, .ai-msg-content ol { margin: 0 0 10px; padding-left: 22px; }
.ai-msg-content li { margin-bottom: 4px; }
.ai-msg-content code { background: var(--bg-3); padding: 1px 6px; border-radius: 4px; font-size: 13px; font-family: 'SF Mono', Monaco, Consolas, monospace; }
.ai-msg.assistant .ai-msg-content { color: var(--ink); }
.ai-typing { display: inline-flex; gap: 4px; align-items: center; padding-top: 4px; }
.ai-typing span { width: 7px; height: 7px; border-radius: 50%; background: var(--ink-4); animation: ai-typing-dot 1.4s ease-in-out infinite; }
.ai-typing span:nth-child(2) { animation-delay: 0.2s; }
.ai-typing span:nth-child(3) { animation-delay: 0.4s; }
@keyframes ai-typing-dot { 0%, 60%, 100% { opacity: 0.3; transform: translateY(0); } 30% { opacity: 1; transform: translateY(-3px); } }

.ai-suggestions { padding: 12px 20px; display: flex; gap: 8px; flex-wrap: wrap; border-top: 1px solid var(--border); background: var(--bg-2); }
.ai-suggestion { font-size: 12px; padding: 6px 11px; background: var(--bg); border: 1px solid var(--border); border-radius: 999px; color: var(--ink-2); cursor: pointer; transition: border-color 0.15s ease, color 0.15s ease; border: 1px solid var(--border); }
.ai-suggestion:hover { border-color: var(--ai); color: var(--ai); }

.ai-input-wrap { border-top: 1px solid var(--border); padding: 14px 18px; display: flex; gap: 10px; align-items: flex-end; background: var(--bg-2); border-radius: 0 0 14px 14px; }
.ai-input { flex: 1; padding: 10px 14px; border: 1px solid var(--border-strong); border-radius: 10px; font-size: 14px; font-family: inherit; background: var(--bg); color: var(--ink); resize: none; min-height: 40px; max-height: 160px; line-height: 1.5; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.ai-input:focus { outline: none; border-color: var(--ai); box-shadow: 0 0 0 3px var(--ai-light); }
.ai-send { padding: 10px 14px; background: var(--ai); color: #fff; border-radius: 10px; font-size: 13.5px; font-weight: 500; white-space: nowrap; transition: opacity 0.15s ease; display: inline-flex; align-items: center; gap: 6px; border: none; cursor: pointer; }
.ai-send:hover { opacity: 0.9; }
.ai-send:disabled { opacity: 0.5; cursor: not-allowed; }

.ai-disclaimer { font-size: 11px; color: var(--ink-4); text-align: center; margin-top: 10px; padding: 0 20px; line-height: 1.4; }

/* Documents IA générés */
.gen-doc { background: var(--bg); border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; margin-bottom: 12px; }
.gen-doc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
.gen-doc-title { font-size: 14px; font-weight: 500; letter-spacing: -0.01em; margin: 0; }
.gen-doc-meta { font-size: 11.5px; color: var(--ink-4); margin-top: 2px; }
.gen-doc-tag { font-size: 10.5px; padding: 3px 9px; background: var(--ai-light); color: var(--ai-dark); border-radius: 999px; font-weight: 500; }
.gen-doc-preview { font-size: 13px; color: var(--ink-2); line-height: 1.55; max-height: 80px; overflow: hidden; position: relative; }
.gen-doc-preview::after { content: ""; position: absolute; bottom: 0; left: 0; right: 0; height: 30px; background: linear-gradient(to bottom, transparent, var(--bg)); }
.gen-doc-actions { display: flex; gap: 10px; margin-top: 12px; font-size: 12px; }
.gen-doc-actions a { color: var(--acc); font-weight: 500; }
.gen-doc-actions a:hover { text-decoration: underline; }

/* ===== FACTURES DANS LA FICHE PROJET ===== */
.inv-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 20px; }
.inv-summary-card { background: var(--bg); border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; }
.inv-summary-card.primary { background: var(--acc-light); border-color: rgba(5, 150, 105, 0.2); }
.inv-summary-card.warn { background: #FEF3C7; border-color: rgba(234, 179, 8, 0.3); }
/* DARK MODE DISABLED (orphelin neutralise) :
@media (prefers-color-scheme: dark) {
  .inv-summary-card.primary { background: rgba(5, 150, 105, 0.12); }
  .inv-summary-card.warn { background: rgba(239, 159, 39, 0.12); }
}
*/
.inv-summary-lbl { font-size: 11px; color: var(--ink-3); letter-spacing: 0.04em; text-transform: uppercase; margin-bottom: 6px; font-weight: 500; }
.inv-summary-val { font-size: 22px; font-weight: 500; font-variant-numeric: tabular-nums; letter-spacing: -0.02em; line-height: 1.1; }
.inv-summary-sub { font-size: 11.5px; color: var(--ink-3); margin-top: 4px; }

.inv-add-form { background: var(--bg); border: 1px solid var(--border); border-radius: 14px; padding: 20px 22px; margin-bottom: 18px; }
.inv-add-title { font-size: 14.5px; font-weight: 500; margin: 0 0 14px; }

.inv-list-project { background: var(--bg); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
.inv-row-project { display: grid; grid-template-columns: 1fr auto auto auto; gap: 14px; align-items: center; padding: 14px 18px; border-top: 1px solid var(--border); transition: background 0.12s ease; }
.inv-row-project:first-child { border-top: none; }
.inv-row-project:hover { background: var(--bg-2); }
.inv-row-header-proj { background: var(--bg-2); font-size: 11px; font-weight: 500; color: var(--ink-3); letter-spacing: 0.04em; text-transform: uppercase; padding: 10px 18px; }
.inv-row-main { min-width: 0; }
.inv-row-supplier { font-size: 14px; font-weight: 500; margin-bottom: 3px; line-height: 1.3; }
.inv-row-details { font-size: 12px; color: var(--ink-3); display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.inv-row-details .dot { color: var(--ink-4); }
.inv-file-link { color: var(--acc); display: inline-flex; align-items: center; gap: 4px; }
.inv-file-link:hover { text-decoration: underline; }

.inv-actions-inline { display: flex; gap: 6px; }
.inv-btn-sm { font-size: 11.5px; font-weight: 500; padding: 5px 10px; border-radius: 7px; display: inline-flex; align-items: center; gap: 4px; transition: all 0.15s ease; cursor: pointer; border: none; font-family: inherit; }
.inv-btn-validate { background: var(--acc-light); color: var(--acc-dark); }
.inv-btn-validate:hover { background: var(--acc); color: #fff; }
.inv-btn-reject { background: rgba(185, 28, 28, 0.1); color: #B91C1C; }
.inv-btn-reject:hover { background: #B91C1C; color: #fff; }
.inv-btn-delete { background: var(--bg-3); color: var(--ink-3); }
.inv-btn-delete:hover { background: rgba(185, 28, 28, 0.15); color: #B91C1C; }

@media (max-width: 720px) {
  .inv-row-project { grid-template-columns: 1fr auto; gap: 10px; padding: 12px 14px; }
  .inv-row-project .inv-row-amount { order: 3; grid-column: 1 / 2; text-align: left; }
  .inv-row-project .inv-row-status { order: 2; }
  .inv-row-project .inv-actions-inline { order: 4; grid-column: 1 / 3; justify-content: flex-start; }
}

/* ===== AGENDA / CALENDRIER ===== */
.cal-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
.cal-nav { display: flex; align-items: center; gap: 8px; }
.cal-nav-btn { width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border-strong); background: var(--bg); color: var(--ink-2); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.15s ease; }
.cal-nav-btn:hover { background: var(--bg-2); color: var(--ink); border-color: var(--ink-3); }
.cal-month-title { font-size: 17px; font-weight: 500; letter-spacing: -0.02em; min-width: 180px; text-align: center; text-transform: capitalize; }
.cal-today-btn { font-size: 12.5px; font-weight: 500; padding: 6px 12px; background: var(--bg); border: 1px solid var(--border-strong); border-radius: 8px; cursor: pointer; color: var(--ink-2); }
.cal-today-btn:hover { background: var(--bg-2); color: var(--ink); }

.cal-view-switch { display: flex; gap: 2px; background: var(--bg-3); border-radius: 8px; padding: 2px; }
.cal-view-btn { font-size: 12.5px; font-weight: 500; padding: 6px 12px; border-radius: 6px; color: var(--ink-2); }
.cal-view-btn.active { background: var(--bg); color: var(--ink); box-shadow: 0 1px 2px rgba(0,0,0,0.04); }

/* Grille calendrier mois */
.cal-grid { background: var(--bg); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
.cal-weekdays { display: grid; grid-template-columns: repeat(7, 1fr); border-bottom: 1px solid var(--border); }
.cal-weekday { padding: 10px 8px; font-size: 11px; font-weight: 500; color: var(--ink-3); letter-spacing: 0.06em; text-transform: uppercase; text-align: center; box-sizing: border-box; min-width: 0; }
.cal-days { display: grid; grid-template-columns: repeat(7, 1fr); }
.cal-day { min-height: 100px; padding: 8px; border-right: 1px solid var(--border); border-top: 1px solid var(--border); cursor: pointer; transition: background 0.12s ease; position: relative; box-sizing: border-box; min-width: 0; overflow: hidden; }
.cal-day:nth-child(7n) { border-right: none; }
.cal-day:hover { background: var(--bg-2); }
.cal-day.other-month { background: var(--bg-2); color: var(--ink-4); }
.cal-day.other-month .cal-day-num { color: var(--ink-4); }
.cal-day.today .cal-day-num { background: var(--acc); color: #fff; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 500; margin: -3px 0 3px -4px; }
.cal-day-num { font-size: 13px; font-weight: 500; color: var(--ink-2); margin-bottom: 4px; display: block; }
.cal-day-events { display: flex; flex-direction: column; gap: 2px; }
.cal-event-pill { font-size: 11px; padding: 2px 6px; border-radius: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; cursor: pointer; transition: opacity 0.15s ease; }
.cal-event-pill:hover { opacity: 0.8; }
.cal-event-pill.ev-blue { background: #B5D4F4; color: #0C447C; }
.cal-event-pill.ev-purple { background: #CECBF6; color: #3C3489; }
.cal-event-pill.ev-amber { background: #FAC775; color: #633806; }
.cal-event-pill.ev-pink { background: #F4C0D1; color: #72243E; }
.cal-event-pill.ev-teal { background: #9FE1CB; color: #0F6E56; }
.cal-event-pill.ev-green { background: var(--acc-light); color: var(--acc-dark); }
.cal-event-pill.ev-red { background: rgba(185, 28, 28, 0.15); color: #991B1B; }
.cal-event-pill.ev-time { font-weight: 500; }
.cal-day-more { font-size: 10.5px; color: var(--ink-3); margin-top: 2px; font-weight: 500; }
/* DARK MODE DISABLED (orphelin neutralise) :
@media (prefers-color-scheme: dark) {
  .cal-event-pill.ev-red { background: rgba(185, 28, 28, 0.2); color: #FCA5A5; }
}
*/

/* Vue liste */
.cal-list { display: flex; flex-direction: column; gap: 20px; }
.cal-list-day { }
.cal-list-day-head { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid var(--border); }
.cal-list-day-date { font-size: 22px; font-weight: 500; letter-spacing: -0.025em; line-height: 1; font-variant-numeric: tabular-nums; min-width: 40px; text-align: center; }
.cal-list-day-info { }
.cal-list-day-name { font-size: 13px; font-weight: 500; color: var(--ink); text-transform: capitalize; line-height: 1.2; }
.cal-list-day-month { font-size: 11px; color: var(--ink-3); text-transform: capitalize; letter-spacing: 0.03em; margin-top: 1px; }
.cal-list-day-badge { margin-left: auto; font-size: 11px; color: var(--ink-3); }
.cal-list-day.today .cal-list-day-date { color: var(--acc); }
.cal-list-day.past .cal-list-day-name, .cal-list-day.past .cal-list-day-date { color: var(--ink-4); }
.cal-list-events { display: flex; flex-direction: column; gap: 8px; }
.cal-list-event { display: grid; grid-template-columns: 80px 4px 1fr auto; gap: 14px; align-items: center; padding: 14px 18px; background: var(--bg); border: 1px solid var(--border); border-radius: 12px; cursor: pointer; transition: border-color 0.15s ease, transform 0.15s ease; }
.cal-list-event:hover { border-color: var(--border-strong); transform: translateY(-1px); }
.cal-list-event-time { font-size: 12.5px; color: var(--ink-2); font-variant-numeric: tabular-nums; font-weight: 500; line-height: 1.3; }
.cal-list-event-time small { display: block; color: var(--ink-4); font-size: 10.5px; font-weight: 400; margin-top: 1px; }
.cal-list-event-bar { width: 4px; height: 40px; border-radius: 2px; background: var(--ink-4); }
.cal-list-event-bar.ev-blue { background: #4F80BD; }
.cal-list-event-bar.ev-purple { background: var(--ai); }
.cal-list-event-bar.ev-amber { background: #EF9F27; }
.cal-list-event-bar.ev-pink { background: #D77CA0; }
.cal-list-event-bar.ev-teal { background: #2AAE89; }
.cal-list-event-bar.ev-green { background: var(--acc); }
.cal-list-event-bar.ev-red { background: #B91C1C; }
.cal-list-event-body { min-width: 0; }
.cal-list-event-title { font-size: 14.5px; font-weight: 500; margin-bottom: 2px; letter-spacing: -0.01em; }
.cal-list-event-meta { font-size: 12px; color: var(--ink-3); display: flex; gap: 8px; flex-wrap: wrap; }
.cal-list-event-meta .dot { color: var(--ink-4); }
.cal-list-event-arrow { color: var(--ink-4); flex-shrink: 0; }

/* Légende types d'événements */
.cal-legend { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; padding: 12px 16px; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; }
.cal-legend-item { display: inline-flex; align-items: center; gap: 6px; font-size: 11.5px; color: var(--ink-2); }
.cal-legend-dot { width: 10px; height: 10px; border-radius: 3px; }

/* Mobile agenda */
@media (max-width: 720px) {
  .cal-toolbar { gap: 10px; }
  .cal-view-switch { flex: 1; justify-content: center; }
  .cal-day { min-height: 64px; padding: 4px; }
  .cal-day-num { font-size: 12px; }
  .cal-event-pill { font-size: 9.5px; padding: 1px 4px; }
  .cal-list-event { grid-template-columns: 60px 4px 1fr; padding: 12px 14px; }
  .cal-list-event-arrow { display: none; }
  .cal-list-event-time { font-size: 11.5px; }
  .cal-list-event-title { font-size: 14px; }
  .cal-month-title { font-size: 15px; min-width: 0; flex: 1; }
}

/* ===== MESSAGES / CANAUX ===== */
.msg-layout { display: grid; grid-template-columns: 260px 1fr; gap: 0; height: calc(100vh - 120px); min-height: 600px; background: var(--bg); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }

/* Sidebar des canaux */
.msg-channels { border-right: 1px solid var(--border); background: var(--bg-2); display: flex; flex-direction: column; overflow: hidden; }
.msg-channels-head { padding: 16px 18px 10px; display: flex; align-items: center; justify-content: space-between; gap: 8px; border-bottom: 1px solid var(--border); }
.msg-channels-title { font-size: 12px; font-weight: 600; color: var(--ink-3); letter-spacing: 0.08em; text-transform: uppercase; }
.msg-channels-list { flex: 1; overflow-y: auto; padding: 8px; }
.msg-channel-group { margin-bottom: 6px; }
.msg-channel-group-lbl { font-size: 10.5px; color: var(--ink-4); font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; padding: 10px 10px 4px; }
.msg-channel-link { display: flex; align-items: center; gap: 8px; padding: 7px 10px; border-radius: 7px; color: var(--ink-2); text-decoration: none; font-size: 13.5px; transition: background 0.12s ease; cursor: pointer; margin-bottom: 1px; }
.msg-channel-link:hover { background: var(--bg); color: var(--ink); }
.msg-channel-link.active { background: var(--bg); color: var(--ink); font-weight: 500; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
.msg-channel-icon { flex-shrink: 0; width: 20px; text-align: center; font-size: 14px; }
.msg-channel-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.msg-channel-type-icon { color: var(--ink-4); flex-shrink: 0; }
.msg-channel-badge { flex-shrink: 0; min-width: 18px; height: 18px; padding: 0 5px; background: var(--acc); color: #fff; font-size: 10.5px; font-weight: 600; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; }
.msg-btn-new-channel { display: flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 6px; background: transparent; border: 1px solid var(--border-strong); color: var(--ink-2); cursor: pointer; }
.msg-btn-new-channel:hover { background: var(--bg); color: var(--ink); }

/* Main chat area */
.msg-main { display: flex; flex-direction: column; overflow: hidden; background: var(--bg); min-width: 0; }
.msg-head { padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.msg-head-icon { font-size: 20px; }
.msg-head-info { flex: 1; min-width: 0; }
.msg-head-name { font-size: 15px; font-weight: 500; display: flex; align-items: center; gap: 8px; }
.msg-head-desc { font-size: 12px; color: var(--ink-3); margin-top: 1px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.msg-head-actions { display: flex; gap: 6px; flex-shrink: 0; }
.msg-head-btn { display: inline-flex; align-items: center; gap: 5px; padding: 6px 10px; border-radius: 7px; background: var(--bg-2); border: 1px solid var(--border); color: var(--ink-2); font-size: 12px; font-weight: 500; cursor: pointer; text-decoration: none; transition: all 0.15s ease; }
.msg-head-btn:hover { background: var(--bg); border-color: var(--border-strong); color: var(--ink); }
.msg-head-btn.ai { background: var(--ai-light); border-color: var(--ai); color: var(--ai-dark); }
.msg-head-btn.ai:hover { background: var(--ai); color: #fff; }

/* Liste des messages */
.msg-list { flex: 1; overflow-y: auto; padding: 20px 24px; display: flex; flex-direction: column; gap: 16px; }
.msg-day-sep { display: flex; align-items: center; gap: 12px; margin: 10px 0 4px; }
.msg-day-sep::before, .msg-day-sep::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.msg-day-sep-label { font-size: 11px; color: var(--ink-3); font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; padding: 3px 10px; background: var(--bg); border: 1px solid var(--border); border-radius: 20px; }

.msg-item { display: grid; grid-template-columns: 36px 1fr; gap: 12px; align-items: flex-start; }
.msg-avatar { width: 36px; height: 36px; border-radius: 50%; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; flex-shrink: 0; }
.msg-body { min-width: 0; }
.msg-header { display: flex; align-items: baseline; gap: 8px; margin-bottom: 2px; flex-wrap: wrap; }
.msg-author { font-size: 13.5px; font-weight: 500; color: var(--ink); }
.msg-author-role { font-size: 10.5px; color: var(--ink-3); padding: 1px 6px; background: var(--bg-2); border-radius: 4px; font-weight: 500; }
.msg-time { font-size: 11px; color: var(--ink-4); }
.msg-content { font-size: 13.5px; color: var(--ink); line-height: 1.55; word-wrap: break-word; white-space: pre-wrap; }
.msg-content strong { font-weight: 600; }
.msg-edited { font-size: 10px; color: var(--ink-4); margin-left: 4px; }

/* Message actions inline (apparaissent au hover) */
.msg-item { position: relative; }
.msg-actions-inline { position: absolute; top: -12px; right: 12px; display: none; gap: 2px; background: var(--bg); border: 1px solid var(--border-strong); border-radius: 8px; padding: 2px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
.msg-item:hover .msg-actions-inline { display: flex; }
.msg-action-btn { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 6px; background: transparent; border: none; color: var(--ink-3); cursor: pointer; }
.msg-action-btn:hover { background: var(--bg-2); color: var(--ink); }
.msg-action-btn.danger:hover { color: #B91C1C; }

/* Zone d'envoi */
.msg-compose { padding: 14px 20px; border-top: 1px solid var(--border); flex-shrink: 0; background: var(--bg); }
.msg-compose-announce-notice { padding: 10px 14px; background: var(--bg-2); border: 1px solid var(--border); border-radius: 10px; font-size: 12.5px; color: var(--ink-3); text-align: center; }
.msg-compose-form { display: flex; gap: 10px; align-items: flex-end; }
.msg-compose-input { flex: 1; resize: none; min-height: 40px; max-height: 150px; padding: 10px 14px; border: 1px solid var(--border); border-radius: 10px; font-size: 13.5px; font-family: inherit; background: var(--bg); color: var(--ink); outline: none; line-height: 1.5; transition: border-color 0.15s ease; }
.msg-compose-input:focus { border-color: var(--acc); box-shadow: 0 0 0 3px rgba(5,150,105,0.08); }
.msg-compose-send { flex-shrink: 0; padding: 10px 14px; background: var(--acc); color: #fff; border: none; border-radius: 10px; font-size: 13px; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: background 0.15s ease; }
.msg-compose-send:hover { background: var(--acc-dark); }
.msg-compose-send:disabled { opacity: 0.5; cursor: not-allowed; }
.msg-compose-hint { font-size: 10.5px; color: var(--ink-4); margin-top: 6px; text-align: right; }

/* État vide */
.msg-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 20px; text-align: center; color: var(--ink-3); }
.msg-empty-icon { font-size: 40px; margin-bottom: 12px; opacity: 0.5; }
.msg-empty-title { font-size: 15px; color: var(--ink-2); margin-bottom: 4px; font-weight: 500; }
.msg-empty-desc { font-size: 13px; max-width: 380px; line-height: 1.5; }

/* Modal IA compte-rendu */
.msg-ai-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 100; display: none; align-items: center; justify-content: center; padding: 20px; }
.msg-ai-modal-backdrop.open { display: flex; }
.msg-ai-modal { background: var(--bg); border-radius: 14px; max-width: 640px; width: 100%; max-height: 85vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
.msg-ai-modal-head { padding: 18px 22px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.msg-ai-modal-title { font-size: 15px; font-weight: 500; display: flex; align-items: center; gap: 8px; }
.msg-ai-modal-close { background: transparent; border: none; cursor: pointer; color: var(--ink-3); padding: 4px; }
.msg-ai-modal-close:hover { color: var(--ink); }
.msg-ai-modal-body { padding: 22px; overflow-y: auto; flex: 1; }
.msg-ai-modal-foot { padding: 14px 22px; border-top: 1px solid var(--border); display: flex; gap: 10px; justify-content: flex-end; }

/* Mobile */
@media (max-width: 720px) {
  .msg-layout { grid-template-columns: 1fr; height: auto; min-height: 500px; }
  .msg-channels { display: none; }
  .msg-channels.mobile-open { display: flex; position: fixed; inset: 0; z-index: 100; border-radius: 0; }
}

/* RESPONSIVE */
@media (max-width: 900px) {
  .app { grid-template-columns: 1fr; }
  .sidebar { position: fixed; left: 0; top: 0; z-index: 50; width: 280px; height: 100vh; transform: translateX(-100%); transition: transform 0.22s ease; }
  .sidebar.open { transform: translateX(0); }
  .mobile-bar { display: flex; }
  .main { padding: 22px 20px 48px; }
  .main-head { margin-bottom: 22px; align-items: flex-start; }
  .greet h1 { font-size: 24px; }
  .head-actions { width: 100%; }
  .btn { flex: 1; justify-content: center; }
  .today { padding: 20px; }
  .my-proj-grid, .folder-grid { grid-template-columns: 1fr; }
  .folder-btn { padding: 16px 18px; gap: 12px; }
  .folder-pct-wrap { display: none; }
  .project-list { padding: 0 16px 18px; }
  .project-row { grid-template-columns: 1fr; gap: 10px; padding: 14px 16px; }
  .project-progress { min-width: 0; }
}
@media (max-width: 480px) {
  .main { padding: 18px 16px 40px; }
  .greet h1 { font-size: 22px; }
  .today-title { font-size: 14px; }
  .metric-val { font-size: 24px; }
  .page-title { font-size: 24px; }
  .folder-btn { padding: 14px 14px; }
  .folder-icon { width: 36px; height: 36px; }
  .folder-name { font-size: 15px; }
}
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }
@media (prefers-reduced-motion: reduce) { * { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; } }
</style>
</head>
<body>
<?php if (function_exists('render_demo_banner')) render_demo_banner(); ?>
<?php if (function_exists('render_trial_banner')) render_trial_banner(); ?>
    <?php
}


/**
 * [trial-14j] Banniere globale essai gratuit
 * S'affiche en haut de chaque page si l'org est en status='trial'
 * Mobile responsive (flex-wrap + media query 640px)
 */
function render_trial_banner() {
    global $pdo;
    if (!function_exists('current_user')) return;
    $user = current_user();
    if (!$user || empty($user['org_id'])) return;
    $org_id = (int)$user['org_id'];

    try {
        $stmt = $pdo->prepare("
            SELECT s.status, o.trial_ends_at
            FROM subscriptions s
            JOIN organizations o ON o.id = s.org_id
            WHERE s.org_id = ?
            ORDER BY s.id DESC LIMIT 1
        ");
        $stmt->execute([$org_id]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] !== 'trial' || empty($row['trial_ends_at'])) return;

        $end_ts = strtotime($row['trial_ends_at']);
        if (!$end_ts) return;
        $days_left = max(0, (int)ceil(($end_ts - time()) / 86400));
        $end_fr = date('d/m/Y', $end_ts);

        if ($days_left === 0) {
            $msg = "se termine <strong>aujourd'hui</strong>";
        } elseif ($days_left === 1) {
            $msg = "se termine <strong>demain</strong> (" . $end_fr . ")";
        } else {
            $msg = "Plus que <strong>" . $days_left . " jours</strong> &middot; fin le " . $end_fr;
        }
        ?>
<style id="ak-trial-banner-css">
.ak-trial-banner { grid-column: 1 / -1; background: linear-gradient(135deg,#FEF3C7,#FDE68A); border-bottom: 2px solid #F59E0B; padding: 10px 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; font-size: 13px; }
.ak-trial-banner-info { display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1; }
.ak-trial-banner-icon { font-size: 22px; line-height: 1; flex-shrink: 0; }
.ak-trial-banner-text { color: #92400E; }
.ak-trial-banner-text strong { color: #78350F; }
.ak-trial-banner-btn { background: #92400E; color: #fff !important; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 12.5px; white-space: nowrap; flex-shrink: 0; transition: background 0.15s; }
.ak-trial-banner-btn:hover { background: #78350F; }
@media (max-width: 640px) {
  .ak-trial-banner { padding: 10px 14px; }
  .ak-trial-banner-info { flex-basis: 100%; }
  .ak-trial-banner-btn { width: 100%; text-align: center; padding: 10px; }
  .ak-trial-banner-text { font-size: 12.5px; }
}
</style>
<div class="ak-trial-banner">
  <div class="ak-trial-banner-info">
    <span class="ak-trial-banner-icon">🚀</span>
    <div class="ak-trial-banner-text"><strong>Mode Démo</strong> &middot; <?= $msg ?></div>
  </div>
  <a href="/abonnement?tab=plans" class="ak-trial-banner-btn">⚡ S'abonner</a>
</div>
        <?php
    } catch (Throwable $e) {}
}

/**
 * Affiche la barre latérale (sidebar).
 * @param string $active Nom de la page active : 'accueil', 'projets', 'adherents', 'agenda', 'messages', 'factures'
 */
function render_sidebar($active = 'accueil') {
    global $pdo;
    $user = current_user();

    // === MODE MAIRIE : sidebar dédiée ===
    if (!empty($_SESSION['parent_org_id']) || !empty($user['parent_org_id'])) {
        render_sidebar_mairie($active);
        return;
    }

    $user_org_id = (int) ($user['org_id'] ?? 0);

    // Charger l'organisation (si l'utilisateur en a une)
    $org = null;
    if ($user_org_id > 0) {
        $stmt = $pdo->prepare('SELECT name FROM organizations WHERE id = ? LIMIT 1');
        $stmt->execute([$user_org_id]);
        $org = $stmt->fetch();
    }

    // Compter les projets et adhérents (0 si pas d'org)
    $proj_count = 0;
    $user_count = 0;
    if ($user_org_id > 0) {
        $proj_count = (int)$pdo->query("SELECT COUNT(*) FROM projects p JOIN folders f ON p.folder_id = f.id WHERE f.org_id = {$user_org_id} AND p.status IN ('active','warning') AND p.archived_at IS NULL AND f.archived_at IS NULL")->fetchColumn();
        $user_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE org_id = {$user_org_id} AND deleted_at IS NULL AND is_active = 1")->fetchColumn();
    }

    $org_initials = mb_strtoupper(mb_substr($org['name'] ?? 'A', 0, 1) . mb_substr($org['name'] ?? '', 1, 1));
    $user_initials = user_initials($user['first_name'], $user['last_name']);

    // ===== Detection Super Admin / Fondateur (defensive : recharge via BDD si colonnes absentes) =====
    $is_founder = false;
    $is_sa = ($user['role'] ?? '') === 'super_admin';
    if (array_key_exists('is_founder', $user)) {
        $is_founder = !empty($user['is_founder']);
    }
    if (!$is_sa && array_key_exists('is_super_admin', $user)) {
        $is_sa = !empty($user['is_super_admin']);
    }
    // Fallback BDD si une des colonnes n'est pas dans current_user()
    if (!$is_founder || (!$is_sa && !array_key_exists('is_super_admin', $user))) {
        try {
            $stmt_sa = $pdo->prepare("SELECT is_super_admin, is_founder FROM users WHERE id = ?");
            $stmt_sa->execute([(int) $user['id']]);
            $row_sa = $stmt_sa->fetch(PDO::FETCH_ASSOC);
            if ($row_sa) {
                if (!$is_sa) $is_sa = (int) ($row_sa['is_super_admin'] ?? 0) === 1;
                if (!$is_founder) $is_founder = (int) ($row_sa['is_founder'] ?? 0) === 1;
            }
        } catch (Throwable $e) {}
    }
    if ($is_founder) $is_sa = true;
    ?>
<!-- Header mobile (visible uniquement <900px) -->
<header class="sb-mobile-header">
    <button type="button" class="sb-burger" onclick="document.getElementById('sidebar').classList.toggle('open'); document.getElementById('overlay').classList.toggle('active');" aria-label="Menu">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <?php if (!empty($org['name'])): ?>
    <div class="sb-mobile-org sb-mobile-org-main">
        <span class="sb-mobile-org-avatar"><?= h($org_initials ?? '?') ?></span>
        <span class="sb-mobile-org-name"><?= h($org['name']) ?></span>
    </div>
    <?php else: ?>
    <div class="sb-mobile-title">
        <span class="sb-logo-mark"></span>
        <span>Asso<i>kit</i></span>
    </div>
    <?php endif; ?>
    <button type="button" class="sb-mobile-logout" onclick="window.location.href='/deconnexion.php'" aria-label="Se déconnecter" title="Se déconnecter">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    </button>
</header>

<div class="app">
  <div class="sidebar-overlay sb-overlay" id="overlay" onclick="document.getElementById('sidebar').classList.remove('open'); this.classList.remove('active');"></div>

  <aside class="sidebar" id="sidebar">

    <?php if ($is_sa): ?>
      <?php if ($is_founder): ?>
        <a href="/super-admin" class="sb-backto-sa sb-backto-sa--founder">
          <div class="sb-backto-sa-label">🏗️ Mode Fondateur</div>
          <div class="sb-backto-sa-title">
            <span>Retour au cockpit</span>
            <span class="sb-backto-sa-arrow">→</span>
          </div>
        </a>
      <?php else: ?>
        <a href="/super-admin" class="sb-backto-sa sb-backto-sa--sa">
          <div class="sb-backto-sa-label">👑 Super Admin</div>
          <div class="sb-backto-sa-title">
            <span>Retour au cockpit</span>
            <span class="sb-backto-sa-arrow">→</span>
          </div>
        </a>
      <?php endif; ?>
    <?php endif; ?>

    <a href="/" class="sb-logo"><span class="sb-logo-mark"></span><span>Asso<i>kit</i></span></a>

    <button class="sb-org" aria-label="Organisation">
      <span class="sb-org-avatar"><?= h($org_initials) ?></span>
      <div class="sb-org-body">
        <div class="sb-org-name"><?= h($org['name'] ?? '—') ?></div>
        <div class="sb-org-role"><?= h(role_label($user['role'])) ?></div>
      </div>
      <svg class="sb-org-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="7 15 12 20 17 15"/><polyline points="7 9 12 4 17 9"/></svg>
    </button>

    <nav class="sb-nav">
      <a href="/dashboard" class="sb-link <?= $active === 'accueil' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
        Accueil
      </a>

      <?php
      // ============ NOTIFICATIONS (cloche avec badge dynamique) ============
      // Calculer le badge initial (sera mis à jour en JS toutes les 30s)
      $notif_unread = 0;
      if (file_exists(__DIR__ . '/notification-helpers.php')) {
          require_once __DIR__ . '/notification-helpers.php';
          if (function_exists('ak_notif_count_unread')) {
              try { $notif_unread = ak_notif_count_unread($GLOBALS['pdo'] ?? null, (int)current_user()['id']); }
              catch (Throwable $e) { $notif_unread = 0; }
          }
      }
      ?>
      <a href="/notifications" class="sb-link sb-link-notif <?= $active === 'notifications' ? 'active' : '' ?>" id="sbNotifLink" style="position:relative;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        Notifications
        <span class="sb-badge sb-badge-notif" id="sbNotifBadge" style="<?= $notif_unread > 0 ? 'background:#EF4444; color:#fff; font-weight:600;' : 'display:none;' ?>"><?= $notif_unread > 0 ? ($notif_unread > 99 ? '99+' : $notif_unread) : '' ?></span>
        <button type="button" id="ak-sound-toggle" class="ak-sound-tgl" title="Activer/couper le son des notifications" onclick="event.preventDefault(); event.stopPropagation(); window.akToggleSound && window.akToggleSound();">
          <span class="ak-sound-on">🔔</span>
          <span class="ak-sound-off" hidden>🔕</span>
        </button>
        <button type="button" id="ak-push-toggle" class="ak-push-tgl" title="Activer/couper les notifications système" onclick="event.preventDefault(); event.stopPropagation(); window.akTogglePush && window.akTogglePush();">
          <span class="ak-push-on" hidden>📢</span>
          <span class="ak-push-off">📵</span>
        </button>
      </a>

      <?php // Projets : tout le monde voit sauf les followers purs (mais on affiche quand même, filtré côté contenu) ?>
      <a href="/projets" class="sb-link <?= $active === 'projets' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7a2 2 0 0 1 2-2h4l2 2h6a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/></svg>
        <?= is_follower() ? 'Projets suivis' : 'Projets' ?>
        <?php if (!is_follower()): ?><span class="sb-badge"><?= $proj_count ?></span><?php endif; ?>
      </a>

      <?php // Tags (catégorisation des projets) — rattaché aux Projets ?>
      <?php if (can('manage_finances')): ?>
      <a href="/mon-asso-tags" class="sb-link <?= $active === 'tags' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
        Tags
      </a>
      <?php endif; ?>

      <?php // Adhérents (+ sous-menu Cotisations toggleable) ?>
      <?php if (!is_follower()): ?>
      <?php $adh_open = in_array($active, ['adherents','cotisations'], true); ?>
      <div class="ak-collapse <?= $adh_open ? 'is-open' : '' ?>">
        <div class="ak-collapse-row">
          <a href="/adherents" class="sb-link ak-collapse-link <?= $active === 'adherents' ? 'active' : '' ?>" style="flex:1;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 6a3 3 0 0 1 0 5"/></svg>
            Adhérents
            <span class="sb-badge"><?= $user_count ?></span>
          </a>
          <?php if (in_array(($user['role'] ?? ''), ['admin','coordinator'], true)): ?>
          <button type="button" class="ak-collapse-toggle" onclick="this.closest('.ak-collapse').classList.toggle('is-open')" aria-label="Déplier"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg></button>
          <?php endif; ?>
        </div>
        <?php if (in_array(($user['role'] ?? ''), ['admin','coordinator'], true)): ?>
        <div class="ak-collapse-body">
          <a href="/cotisations" class="sb-link <?= $active === 'cotisations' ? 'active' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><circle cx="6" cy="14.5" r="1.2"/></svg>
            Cotisations
          </a>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php // Agenda (+ sous-menu Emploi du temps toggleable) ?>
      <?php $ag_open = in_array($active, ['agenda','emploi-du-temps'], true); ?>
      <div class="ak-collapse <?= $ag_open ? 'is-open' : '' ?>">
        <div class="ak-collapse-row">
          <a href="/agenda" class="sb-link ak-collapse-link <?= $active === 'agenda' ? 'active' : '' ?>" style="flex:1;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9h18M8 2.5v4M16 2.5v4"/></svg>
            Agenda
          </a>
          <?php if (!is_follower()): ?>
          <button type="button" class="ak-collapse-toggle" onclick="this.closest('.ak-collapse').classList.toggle('is-open')" aria-label="Déplier"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg></button>
          <?php endif; ?>
        </div>
        <?php if (!is_follower()): ?>
        <div class="ak-collapse-body">
          <a href="/emploi-du-temps" class="sb-link <?= $active === 'emploi-du-temps' ? 'active' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Emploi du temps
          </a>
        </div>
        <?php endif; ?>
      </div>

      <?php // Assemblées (+ sous-menu Émargement) ?>
      <?php if (($user['role'] ?? '') === 'admin'): ?>
      <?php $ag_grp_open = in_array($active, ['assemblees','emargement'], true); ?>
      <div class="ak-collapse <?= $ag_grp_open ? 'is-open' : '' ?>">
        <div class="ak-collapse-row">
          <a href="/assemblees" class="sb-link ak-collapse-link <?= $active === 'assemblees' ? 'active' : '' ?>" style="flex:1;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V10l7-5 7 5v11M9 21V12h6v9"/></svg>
            Assemblées
          </a>
          <button type="button" class="ak-collapse-toggle" onclick="this.closest('.ak-collapse').classList.toggle('is-open')" aria-label="Déplier"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg></button>
        </div>
        <div class="ak-collapse-body">
          <a href="/emargement" class="sb-link <?= $active === 'emargement' ? 'active' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Émargement
          </a>
        </div>
      </div>
      <?php endif; ?>
            <?php // Coach Assokit : admin uniquement ?>
      <?php if (($user['role'] ?? '') === 'admin'): ?>
      <a href="/coach-ia" class="sb-link <?= $active === 'coach-ia' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><circle cx="9" cy="10" r=".8" fill="currentColor"/><circle cx="15" cy="10" r=".8" fill="currentColor"/><path d="M12 3v2M5 6l1.4 1.4M19 6l-1.4 1.4"/></svg>
        Coach Assokit
      </a>
      <?php endif; ?>

      <?php // Messages : tout le monde sauf followers ?>
      <?php if (!is_follower()): ?>
      <a href="/messages" class="sb-link <?= $active === 'messages' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v11H8l-4 3z"/></svg>
        Messages
      </a>
      <?php endif; ?>

      <?php
      // Support : tous les users de l'asso voient les tickets
      $support_unread = 0;
      if ($user_org_id > 0) {
          try {
              $stmt = $pdo->prepare("
                  SELECT COUNT(DISTINCT t.id)
                  FROM support_tickets t
                  JOIN support_messages m ON m.ticket_id = t.id
                  WHERE t.org_id = ?
                    AND m.author_side = 'support'
                    AND m.read_by_org = 0
                    AND m.is_internal_note = 0
              ");
              $stmt->execute([$user_org_id]);
              $support_unread = (int) $stmt->fetchColumn();
          } catch (Throwable $e) {}
      }
      ?>
      <a href="/support" class="sb-link <?= $active === 'support' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v10H4z"/><path d="M8 20h8M12 15v5"/></svg>
        Support
        <?php if ($support_unread > 0): ?>
          <span class="sb-badge" style="background:#EF4444; color:white;"><?= $support_unread ?></span>
        <?php endif; ?>
      </a>

      <?php // 💶 Finances : regroupe Facturation, Relances, Anomalies, Prévisions, Subventions ?>
      <?php if (can('manage_finances')):
          $is_admin_nav = (($user['role'] ?? '') === 'admin');
          $fin_children = ['devis','factures','clients','stats','relances','anomalies','previsions','comptabilite','export-fec','facturx','subventions','financements'];
          $fin_open = in_array($active, $fin_children, true);
      ?>
      <div class="ak-collapse <?= $fin_open ? 'is-open' : '' ?>">
        <div class="ak-collapse-row">
          <a href="/mon-asso-factures-client" class="sb-link ak-collapse-link <?= $fin_open ? 'active' : '' ?>" style="flex:1;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 3h12l4 4v14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M7 8h7M7 12h9M7 16h5"/></svg>
            Finances
          </a>
          <button type="button" class="ak-collapse-toggle" onclick="this.closest('.ak-collapse').classList.toggle('is-open')" aria-label="Déplier"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg></button>
        </div>
        <div class="ak-collapse-body">
          <a href="/mon-asso-factures-client" class="sb-link <?= in_array($active, ['devis','factures','clients','stats'], true) ? 'active' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"/><path d="M4 10h16M10 4v16"/></svg>
            Facturation
          </a>
          <a href="/relances" class="sb-link <?= $active === 'relances' ? 'active' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l9 6 9-6"/><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M16 3l2 2-2 2"/></svg>
            Relances
          </a>
          <a href="/anomalies" class="sb-link <?= $active === 'anomalies' ? 'active' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            Anomalies
          </a>
          <a href="/previsions" class="sb-link <?= $active === 'previsions' ? 'active' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/></svg>
            Prévisions
          </a>
          <a href="/comptabilite" class="sb-link <?= $active === 'comptabilite' ? 'active' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19a2 2 0 0 0 2 2h13M9 8h6"/></svg>
            Comptabilité
          </a>
          <a href="/export-fec" class="sb-link <?= $active === 'export-fec' ? 'active' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M9 15h6M9 12h2"/></svg>
            Export FEC
          </a>
          <a href="/facturx" class="sb-link <?= $active === 'facturx' ? 'active' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 3v6c0 5-3.4 8.5-8 11-4.6-2.5-8-6-8-11V5z"/><path d="M9 12l2 2 4-4"/></svg>
            E-facture (Factur-X)
          </a>
          <?php if ($is_admin_nav): ?>
          <a href="/financements" class="sb-link <?= $active === 'financements' ? 'active' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-3.5-3.5M11 7v4l2.5 2.5"/></svg>
            Radar subventions
          </a>
          <a href="/subventions" class="sb-link <?= $active === 'subventions' ? 'active' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            Mes candidatures
          </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php // Notes de frais : accessible à tous les membres (chacun gère les siennes) ?>
      <?php if (!is_follower()): ?>
      <a href="/notes-de-frais" class="sb-link <?= $active === 'notes-de-frais' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="12" rx="2"/><path d="M8 8V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18"/></svg>
        Notes de frais
      </a>
      <?php endif; ?>

      <?php // Communication : uniquement si capacité access_marketing ?>
      <?php if (can('access_marketing')): ?>
      <a href="/communication" class="sb-link <?= $active === 'communication' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l18-7-7 18-3-8z"/></svg>
        Communication
      </a>
      <?php endif; ?>

      <?php // ⚙️ Paramètres : visible pour tous les users connectés ?>
      <a href="/parametres" class="sb-link <?= $active === 'parametres' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.2"/><path d="M19 12a7 7 0 0 0-.1-1.4l2-1.5-2-3.5-2.3 1a7 7 0 0 0-2.4-1.4L15.8 2h-4l-.4 2.7A7 7 0 0 0 9 6.1L6.7 5 4.7 8.5l2 1.5A7 7 0 0 0 6.6 12a7 7 0 0 0 .1 1.4l-2 1.5 2 3.5 2.3-1a7 7 0 0 0 2.4 1.4l.4 2.7h4l.4-2.7a7 7 0 0 0 2.4-1.4l2.3 1 2-3.5-2-1.5A7 7 0 0 0 19 12z"/></svg>
        Paramètres
      </a>

      <?php // Bloc Admin : uniquement si admin ?>
      <?php if (can('admin') || $user['role'] === 'admin'): ?>
      <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--border);">
        <div style="font-size: 10.5px; color: var(--ink-4); font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; padding: 0 10px 6px;">Admin</div>
        <a href="/admin" class="sb-link <?= $active === 'admin' ? 'active' : '' ?>">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1l9 4v6c0 5.55-3.84 10.74-9 12-5.16-1.26-9-6.45-9-12V5l9-4z"/></svg>
          Administration
        </a>
        <a href="/mon-asso-sso" class="sb-link <?= $active === 'mon-asso-sso' ? 'active' : '' ?>">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg>
          Intégration WordPress
        </a>
<?php if (in_array($active, ['admin', 'archives', 'abonnement'], true)): ?>
        <a href="/archives" class="sb-link <?= $active === 'archives' ? 'active' : '' ?>">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
          Archives
        </a>
        <a href="/abonnement" class="sb-link <?= $active === 'abonnement' ? 'active' : '' ?>">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          Abonnement
        </a>
<?php endif; ?>
      </div>
      <?php endif; ?>
    </nav>

    <div class="sb-foot">
      <span class="sb-user-avatar"><?= h($user_initials) ?></span>
      <div class="sb-user-body">
        <div class="sb-user-name"><?= h($user['first_name'] . ' ' . $user['last_name']) ?></div>
        <div class="sb-user-email"><?= h($user['email']) ?></div>
      </div>
      <a href="/deconnexion.php" class="sb-user-menu" title="Se déconnecter">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </a>
    </div>
  </aside>

  <div>
    <?php
}


/**
 * Sidebar dédiée pour les agents mairie / collectivité
 */
function render_sidebar_mairie($active = 'mairie-dashboard') {
    global $pdo;
    $user = current_user();
    $parent_org_id = (int)($_SESSION['parent_org_id'] ?? $user['parent_org_id'] ?? 0);

    $parent_org = null;
    if ($parent_org_id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM parent_orgs WHERE id = ? LIMIT 1');
        $stmt->execute([$parent_org_id]);
        $parent_org = $stmt->fetch();
    }
    $nb_assos = 0;
    if ($parent_org_id > 0) {
        try {
            $nb_assos = (int)$pdo->query("SELECT COUNT(*) FROM organizations WHERE parent_org_id = $parent_org_id")->fetchColumn();
        } catch (Exception $e) {}
    }
    $type_emoji = match($parent_org['type'] ?? 'mairie') {
        'mairie' => '🏛', 'departement' => '🏢', 'region' => '🌍',
        'drac' => '🎭', 'caf' => '👨‍👩‍👧', 'federation' => '🤝', default => '🏢'
    };
    $user_initials = user_initials($user['first_name'], $user['last_name']);
    ?>
<header class="sb-mobile-header">
    <button type="button" class="sb-burger" onclick="document.getElementById('sidebar').classList.toggle('open'); document.getElementById('overlay').classList.toggle('active');" aria-label="Menu">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="sb-mobile-title"><span class="sb-logo-mark"></span><span><?= h($parent_org['name'] ?? 'Mairie') ?></span></div>
    <button type="button" class="sb-mobile-logout" onclick="window.location.href='/deconnexion.php'" aria-label="Se déconnecter">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    </button>
</header>

<div class="app">
  <div class="sidebar-overlay sb-overlay" id="overlay" onclick="document.getElementById('sidebar').classList.remove('open'); this.classList.remove('active');"></div>

  <aside class="sidebar" id="sidebar">
    <a href="/mairie-dashboard" class="sb-logo"><span class="sb-logo-mark"></span><span>Assokit</span></a>

    <div style="margin:14px 12px 16px;padding:14px;background:linear-gradient(135deg,#0A0A0B 0%,#1F2937 100%);color:#fff;border-radius:10px;">
        <div style="font-size:10px;opacity:0.65;text-transform:uppercase;letter-spacing:0.12em;font-weight:600;">Mairie</div>
        <div style="font-size:13.5px;font-weight:700;line-height:1.25;margin-top:4px;display:flex;align-items:center;gap:6px;">
            <span style="font-size:15px;"><?= $type_emoji ?></span>
            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($parent_org['name'] ?? 'Mairie') ?></span>
        </div>
    </div>

    <nav style="padding:0 12px;flex:1;">

      <a href="/mairie-dashboard" class="sb-link <?= $active === 'mairie-dashboard' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        Accueil
      </a>

      <a href="/mairie-associations" class="sb-link <?= $active === 'mairie-assos' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
        Associations
        <span style="margin-left:auto;background:#F4F4F5;color:#3F3F46;font-size:11px;padding:1px 7px;border-radius:10px;font-weight:600;"><?= $nb_assos ?></span>
      </a>

      <a href="/mairie-messages" class="sb-link <?= $active === 'messages' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        Messages
      </a>

      <a href="/mairie-emailing" class="sb-link <?= $active === 'emailing' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        Emailing
      </a>

      <a href="/mairie-support" class="sb-link <?= $active === 'support' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        Support
      </a>

      <a href="/mairie-parametres" class="sb-link <?= $active === 'parametres' ? 'active' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        Paramètres
      </a>

    </nav>

    <div class="sb-foot">
      <span class="sb-user-avatar"><?= h($user_initials) ?></span>
      <div class="sb-user-body">
        <div class="sb-user-name"><?= h($user['first_name'] . ' ' . $user['last_name']) ?></div>
        <div class="sb-user-email"><?= h($user['email']) ?></div>
      </div>
      <a href="/deconnexion.php" class="sb-user-menu" title="Se déconnecter">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </a>
    </div>

  </aside>

  <div>
    <?php
}

/**
 * Ferme la zone principale + script JS partagé (menu mobile + accordéon folder).
 */
function render_foot() {
    ?>
  </div>
</div>

<!-- ========== TOAST NOTIFICATIONS (style Facebook) ========== -->
<div id="notifToastContainer" style="position:fixed; bottom:20px; right:20px; z-index:9999; display:flex; flex-direction:column; gap:8px; max-width:360px;"></div>

<?php
// ========== COPILOTE IA — lanceur flottant permanent (toutes pages) ==========
if (function_exists('current_user') && function_exists('can')):
    $cop_user = current_user();
    if ($cop_user && (int)($cop_user['org_id'] ?? 0) > 0 && can('manage_finances')):
        $cop_csrf = h($_SESSION['csrf_token'] ?? '');
?>
<button id="akCopFab" type="button" aria-label="Ouvrir le Copilote IA" title="Copilote IA — pose ta question">
  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9l-4.6 1.4L12 15l-1.9-4.6L5.5 9l4.6-1.4z"/><path d="M18 15l.9 2.1L21 18l-2.1.9L18 21l-.9-2.1L15 18l2.1-.9z"/></svg>
</button>
<div id="akCopPanel" aria-hidden="true">
  <div class="akcop-head">
    <div class="akcop-head-t"><span class="akcop-dot"></span> Copilote IA</div>
    <button type="button" id="akCopClose" aria-label="Fermer">✕</button>
  </div>
  <div id="akCopLog" class="akcop-log">
    <div class="akcop-hello">Pose ta question sur ton asso 👇<br><span>adhérents · cotisations · factures · trésorerie · projets · événements</span></div>
    <div class="akcop-chips">
      <button type="button" class="akcop-chip">Combien d'adhérents actifs ?</button>
      <button type="button" class="akcop-chip">Quels adhérents relancer ?</button>
      <button type="button" class="akcop-chip">Quel est mon CA cette année ?</button>
      <button type="button" class="akcop-chip">Quelles factures sont en retard ?</button>
    </div>
  </div>
  <form id="akCopForm" class="akcop-form">
    <input id="akCopInput" type="text" autocomplete="off" maxlength="500" placeholder="Écris ta question…">
    <button type="submit" id="akCopSend" aria-label="Envoyer">➤</button>
  </form>
</div>
<style>
#akCopFab{position:fixed;right:22px;bottom:22px;z-index:10000;width:56px;height:56px;border:none;border-radius:50%;cursor:pointer;background:linear-gradient(135deg,#0CCB8F,#059669);box-shadow:0 10px 28px rgba(5,150,105,.45);display:flex;align-items:center;justify-content:center;transition:transform .15s;}
#akCopFab:hover{transform:translateY(-2px) scale(1.04);}
/* Le bouton flottant ne doit pas recouvrir une barre d'action collée en bas (facture/devis/copilote) ni le composeur de messages */
body:has(.main [style*="position:sticky"][style*="bottom"]), body:has(.main [style*="position: sticky"][style*="bottom"]), body:has(.msg-compose) { --ak-fab-off: 84px; }
#akCopFab { bottom: var(--ak-fab-off, 22px); }
#akCopPanel { bottom: calc(var(--ak-fab-off, 22px) + 66px); }
#notifToastContainer { bottom: 90px !important; right: 16px !important; }
#akCopPanel{position:fixed;right:22px;bottom:88px;z-index:10000;width:370px;max-width:calc(100vw - 32px);height:520px;max-height:calc(100vh - 130px);background:#fff;border:1px solid #E2E8F0;border-radius:18px;box-shadow:0 24px 60px rgba(15,23,42,.22);display:none;flex-direction:column;overflow:hidden;}
#akCopPanel.open{display:flex;}
.akcop-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:linear-gradient(135deg,#0F172A,#065F46);color:#fff;}
.akcop-head-t{font-weight:700;font-size:15px;display:flex;align-items:center;gap:8px;}
.akcop-dot{width:8px;height:8px;border-radius:50%;background:#0CCB8F;box-shadow:0 0 0 4px rgba(12,203,143,.25);}
#akCopClose{background:transparent;border:none;color:#fff;font-size:16px;cursor:pointer;opacity:.8;}
.akcop-log{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:#F8FAFC;}
.akcop-hello{color:#475569;font-size:13.5px;text-align:center;padding:6px 4px;}
.akcop-hello span{color:#94A3B8;font-size:12px;}
.akcop-chips{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin-top:4px;}
.akcop-chip{background:#EEF2F7;border:1px solid #E2E8F0;border-radius:999px;padding:6px 11px;font-size:12px;color:#334155;cursor:pointer;}
.akcop-me{align-self:flex-end;max-width:82%;background:#059669;color:#fff;padding:9px 12px;border-radius:13px 13px 4px 13px;font-size:13.5px;}
.akcop-bot{align-self:flex-start;max-width:92%;background:#fff;border:1px solid #E2E8F0;color:#0F172A;padding:10px 12px;border-radius:13px 13px 13px 4px;font-size:13.5px;}
.akcop-bot table{width:100%;border-collapse:collapse;font-size:12px;margin-top:6px;}
.akcop-bot th{text-align:left;padding:5px 7px;border-bottom:2px solid #E2E8F0;color:#64748B;}
.akcop-bot td{padding:5px 7px;border-bottom:1px solid #F1F5F9;}
.akcop-bot a.akcop-act{display:inline-block;margin-top:8px;background:#0F172A;color:#fff;text-decoration:none;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;}
.akcop-form{display:flex;gap:8px;padding:10px;border-top:1px solid #E2E8F0;background:#fff;}
#akCopInput{flex:1;border:1px solid #E2E8F0;border-radius:10px;padding:9px 11px;font-size:13.5px;outline:none;}
#akCopSend{background:linear-gradient(135deg,#0CCB8F,#059669);color:#fff;border:none;border-radius:10px;width:42px;font-size:15px;cursor:pointer;}
@media (max-width:900px){#akCopFab{bottom:78px;} #akCopPanel{bottom:78px;}}
</style>
<script>
(function(){
  var CSRF="<?= $cop_csrf ?>";
  var fab=document.getElementById('akCopFab'), panel=document.getElementById('akCopPanel'),
      log=document.getElementById('akCopLog'), form=document.getElementById('akCopForm'),
      input=document.getElementById('akCopInput'), send=document.getElementById('akCopSend');
  function esc(s){return (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
  function open(){panel.classList.add('open');panel.setAttribute('aria-hidden','false');setTimeout(function(){input.focus();},80);}
  function close(){panel.classList.remove('open');panel.setAttribute('aria-hidden','true');}
  fab.addEventListener('click',function(){panel.classList.contains('open')?close():open();});
  document.getElementById('akCopClose').addEventListener('click',close);
  function tbl(html,who){var b=document.createElement('div');b.className=who==='me'?'akcop-me':'akcop-bot';b.innerHTML=html;log.appendChild(b);b.scrollIntoView({block:'end'});return b;}
  function renderTable(t){if(!t||!t.columns)return '';var h='<div style="font-weight:700;margin-bottom:2px;">'+esc(t.title||'')+'</div><table><tr>'+t.columns.map(function(c){return '<th>'+esc(c)+'</th>';}).join('')+'</tr>';(t.rows||[]).forEach(function(r){h+='<tr>'+r.map(function(c){return '<td>'+esc(c)+'</td>';}).join('')+'</tr>';});return h+'</table>';}
  function ask(q){if(!q||!q.trim())return;tbl(esc(q),'me');input.value='';send.disabled=true;var w=tbl('<span style="color:#94A3B8;">…</span>','bot');
    fetch('/mon-asso-copilote-ask.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({question:q,csrf_token:CSRF})})
    .then(function(r){return r.json();}).then(function(d){var html='';
      if(d&&d.ok===false){html=esc(d.error==='forbidden'?'Accès non autorisé.':'Service momentanément indisponible.');}
      else{html='<div>'+esc(d.answer||'')+'</div>';if(d.table)html+=renderTable(d.table);if(d.action&&d.action.route)html+='<a class="akcop-act" href="'+esc(d.action.route)+'">'+esc(d.action.label||'Ouvrir')+' →</a>';}
      w.innerHTML=html;}).catch(function(){w.innerHTML=esc('Erreur réseau. Réessayez.');}).finally(function(){send.disabled=false;input.focus();});
  }
  form.addEventListener('submit',function(e){e.preventDefault();ask(input.value);});
  log.addEventListener('click',function(e){if(e.target.classList.contains('akcop-chip'))ask(e.target.textContent);});
})();
</script>
<?php endif; endif; ?>

<style>
.notif-toast {
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    padding: 12px 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
    transform: translateX(400px);
    opacity: 0;
    transition: transform 0.3s ease, opacity 0.3s ease;
    position: relative;
}
.notif-toast.show { transform: translateX(0); opacity: 1; }
.notif-toast.hide { transform: translateX(400px); opacity: 0; }
.notif-toast-close {
    position: absolute; top: 6px; right: 8px;
    background: transparent; border: none; cursor: pointer;
    color: #9CA3AF; padding: 2px; line-height: 1;
    font-size: 16px;
}
.notif-toast-icon {
    width: 38px; height: 38px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 18px; flex-shrink: 0;
}
.notif-toast-body { flex: 1; min-width: 0; padding-right: 12px; }
.notif-toast-title { font-size: 13px; font-weight: 600; color: #111827; line-height: 1.3; margin-bottom: 2px; }
.notif-toast-text { font-size: 12px; color: #6B7280; line-height: 1.4; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.notif-toast-time { font-size: 11px; color: #3B82F6; font-weight: 500; margin-top: 4px; }

/* Toggle son notifications */
.ak-sound-tgl, .ak-push-tgl { position: absolute; top: 50%; transform: translateY(-50%); background: transparent; border: 0; padding: 4px 6px; font-size: 12px; cursor: pointer; opacity: 0; visibility: hidden; border-radius: 5px; transition: opacity 0.15s, background 0.15s; }
.ak-sound-tgl { right: 32px; }
.ak-push-tgl { right: 6px; }
/* au repos : menu épuré (juste le badge rouge) ; au survol : les réglages son/push apparaissent */
.sb-link-notif:hover .ak-sound-tgl, .sb-link-notif:hover .ak-push-tgl { opacity: 0.6; visibility: visible; }
.sb-link-notif:hover .sb-badge-notif { opacity: 0; }
.ak-sound-tgl:hover, .ak-push-tgl:hover { opacity: 1 !important; background: rgba(0,0,0,0.05); }
.sb-link.sb-link-notif { padding-right: 62px !important; }

/* Pulse animation pour le badge cloche */
.sb-badge-notif { 
    animation: notifPulse 2s ease-in-out infinite;
}
@keyframes notifPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.08); }
}
</style>

<script>
(function () {
  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('overlay');
  var toggle = document.getElementById('menu-toggle');
  function open() { sidebar.classList.add('open'); overlay.classList.add('open'); }
  function close() { sidebar.classList.remove('open'); overlay.classList.remove('open'); }
  if (toggle) toggle.addEventListener('click', open);
  if (overlay) overlay.addEventListener('click', close);
  document.querySelectorAll('.sb-link').forEach(function (link) {
    link.addEventListener('click', function () { if (window.innerWidth < 900) close(); });
  });
  // Accordéon dossier (pour la page projets)
  document.querySelectorAll('.folder-btn').forEach(function (btn) {
    btn.addEventListener('click', function () { btn.closest('.folder').classList.toggle('open'); });
  });
})();

// ============================================================
// SYSTÈME DE NOTIFICATIONS (style Facebook)
// ============================================================
(function() {
    var badge = document.getElementById('sbNotifBadge');
    var toastContainer = document.getElementById('notifToastContainer');
    if (!badge || !toastContainer) return;
    
    // ID des notifs déjà vues (pour ne pas afficher 2x le même toast)
    var seenIds = new Set();
    var firstLoad = true;
    var POLL_INTERVAL = 30000; // 30 secondes
    
    function showToast(notif) {
        if (seenIds.has(notif.id)) return;
        seenIds.add(notif.id);
        
        var toast = document.createElement('a');
        toast.href = notif.link_url || '#';
        toast.className = 'notif-toast';
        toast.dataset.notifId = notif.id;
        
        var iconHtml = '';
        if (notif.actor) {
            // Avatar de l'auteur
            iconHtml = '<div class="notif-toast-icon" style="background:' + colorToHex(notif.actor.color) + ';">' + 
                escHtml(notif.actor.initials) + '</div>';
        } else {
            iconHtml = '<div class="notif-toast-icon" style="background:' + notif.color + ';">' + notif.icon + '</div>';
        }
        
        toast.innerHTML = 
            iconHtml +
            '<div class="notif-toast-body">' +
                '<div class="notif-toast-title">' + escHtml(notif.title) + '</div>' +
                (notif.body ? '<div class="notif-toast-text">' + escHtml(notif.body) + '</div>' : '') +
                '<div class="notif-toast-time">' + escHtml(notif.time_ago) + '</div>' +
            '</div>' +
            '<button type="button" class="notif-toast-close" onclick="event.preventDefault(); event.stopPropagation(); this.parentElement.classList.add(\'hide\'); setTimeout(()=>this.parentElement.remove(),300);">×</button>';
        
        toastContainer.appendChild(toast);
        // Trigger animation
        setTimeout(function(){ toast.classList.add('show'); }, 10);
        
        // Auto-hide après 6s
        setTimeout(function() {
            if (toast.parentNode) {
                toast.classList.add('hide');
                setTimeout(function(){ if (toast.parentNode) toast.remove(); }, 300);
            }
        }, 6000);
        
        // Marquer comme lu au clic (avant de naviguer)
        toast.addEventListener('click', function(e) {
            var notifId = this.dataset.notifId;
            try {
                var fd = new FormData();
                fd.append('csrf_token', getCsrfToken());
                fd.append('action', 'mark_one');
                fd.append('notif_id', notifId);
                fetch('/action-mark-read', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd, keepalive: true
                });
            } catch(_){}
        });
    }
    
    function escHtml(s) {
        if (s === null || s === undefined) return '';
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }
    
    function colorToHex(color) {
        var map = { blue: '#3B82F6', purple: '#8B5CF6', amber: '#F59E0B', pink: '#EC4899', teal: '#14B8A6', green: '#10B981', red: '#EF4444', indigo: '#6366F1' };
        return map[color] || '#3B82F6';
    }
    
    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.content;
        var input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }
    
    function updateBadge(count) {
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'inline-flex';
            badge.style.background = '#EF4444';
            badge.style.color = '#fff';
            badge.style.fontWeight = '600';
        } else {
            badge.style.display = 'none';
        }
    }
    
    function pollNotifications() {
        if (document.hidden) return;
        
        fetch('/api-notifications.php?action=count', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.ok ? r.json() : null; })
        .then(function(data) {
            if (!data || !data.ok) return;
            var newCount = data.unread_notifs || 0;
            updateBadge(newCount);
            // 🔔 Son + favicon badge si nouvelle notif
            if (newCount > lastUnreadCount && !firstLoad) {
                playDing();
                updateFavicon(newCount);
            } else if (newCount > 0 && document.hidden) {
                updateFavicon(newCount);
            } else if (newCount === 0) {
                updateFavicon(0);
            }
            lastUnreadCount = newCount;
            
            // Si nouvelles notifs, on récupère les détails pour afficher des toasts
            if (data.unread_notifs > 0 && !firstLoad) {
                fetchAndShowToasts();
            }
            firstLoad = false;
        })
        .catch(function(){});
    }
    
    // 🔔 SON DING (WebAudio, pas de fichier externe)
    var lastUnreadCount = 0;
    var soundEnabled = (localStorage.getItem('ak_sound') !== '0');
    function playDing() {
        if (!soundEnabled) return;
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var now = ctx.currentTime;
            // 2 notes : do-mi montant
            [880, 1175].forEach(function(freq, i) {
                var o = ctx.createOscillator();
                var g = ctx.createGain();
                o.type = 'sine';
                o.frequency.value = freq;
                g.gain.setValueAtTime(0, now + i*0.08);
                g.gain.linearRampToValueAtTime(0.18, now + i*0.08 + 0.01);
                g.gain.exponentialRampToValueAtTime(0.001, now + i*0.08 + 0.25);
                o.connect(g); g.connect(ctx.destination);
                o.start(now + i*0.08); o.stop(now + i*0.08 + 0.3);
            });
        } catch(e) {}
    }
    
    // 🔴 FAVICON BADGE (overlay rouge sur favicon natif)
    var origFavicon = null;
    function getFavicon() {
        var l = document.querySelector('link[rel*="icon"]');
        if (!l) { l = document.createElement('link'); l.rel = 'icon'; document.head.appendChild(l); }
        if (origFavicon === null) origFavicon = l.href;
        return l;
    }
    function updateFavicon(count) {
        var link = getFavicon();
        if (count <= 0) { link.href = origFavicon; document.title = document.title.replace(/^\(\d+\+?\)\s*/, ''); return; }
        // Dessine pastille sur canvas 32×32
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function() {
            var canvas = document.createElement('canvas');
            canvas.width = 32; canvas.height = 32;
            var c = canvas.getContext('2d');
            try { c.drawImage(img, 0, 0, 32, 32); } catch(e) {}
            // Pastille rouge
            c.fillStyle = '#EF4444';
            c.beginPath(); c.arc(24, 8, 8, 0, Math.PI*2); c.fill();
            c.fillStyle = '#fff';
            c.font = 'bold 11px sans-serif';
            c.textAlign = 'center'; c.textBaseline = 'middle';
            c.fillText(count > 9 ? '9+' : String(count), 24, 9);
            try { link.href = canvas.toDataURL('image/png'); } catch(e) {}
        };
        img.onerror = function() {
            // fallback : pastille seule
            var canvas = document.createElement('canvas');
            canvas.width = 32; canvas.height = 32;
            var c = canvas.getContext('2d');
            c.fillStyle = '#EF4444';
            c.beginPath(); c.arc(16, 16, 14, 0, Math.PI*2); c.fill();
            c.fillStyle = '#fff'; c.font = 'bold 16px sans-serif';
            c.textAlign = 'center'; c.textBaseline = 'middle';
            c.fillText(count > 9 ? '9+' : String(count), 16, 17);
            link.href = canvas.toDataURL('image/png');
        };
        img.src = origFavicon || '/favicon.ico';
        // Title aussi
        var t = document.title.replace(/^\(\d+\+?\)\s*/, '');
        document.title = '(' + (count > 9 ? '9+' : count) + ') ' + t;
    }
    
    function fetchAndShowToasts() {
        fetch('/api-notifications.php?action=list&unread=1&limit=5', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.ok ? r.json() : null; })
        .then(function(data) {
            if (!data || !data.ok || !data.notifications) return;
            // Afficher les nouveaux toasts (les autres sont déjà dans seenIds)
            data.notifications.forEach(function(n) {
                if (!n.is_read && !seenIds.has(n.id)) {
                    showToast(n);
                    // 🔔 Notification native OS (si autorisée + onglet pas focus)
                    showNativeNotif(n);
                }
            });
        })
        .catch(function(){});
    }

    // ============ PUSH NATIVES (Notification API) ============
    var pushEnabled = (localStorage.getItem('ak_push') === '1');
    function showNativeNotif(n) {
        if (!pushEnabled || !('Notification' in window)) return;
        if (Notification.permission !== 'granted') return;
        if (document.visibilityState === 'visible' && document.hasFocus()) return; // Onglet actif → toast suffit
        try {
            var title = (n.title || 'AssoKit') + '';
            var body  = (n.body || n.message || '') + '';
            var url   = n.url || n.link || '/notifications';
            var notif = new Notification(title, {
                body: body.substring(0, 180),
                icon: '/icons/icon-192.png',
                badge: '/icons/icon-192.png',
                tag: 'ak-' + n.id,
                requireInteraction: false,
                silent: !soundEnabled
            });
            notif.onclick = function() {
                window.focus();
                if (url) window.location.href = url;
                notif.close();
            };
            // Auto-close après 8s
            setTimeout(function(){ try { notif.close(); } catch(e){} }, 8000);
        } catch(e) {}
    }

    function akAskPushPermission(silent) {
        if (!('Notification' in window)) {
            if (!silent) alert('Ton navigateur ne supporte pas les notifications natives.');
            return;
        }
        if (Notification.permission === 'denied') {
            if (!silent) alert('Notifications bloquées. Pour les autoriser : clique sur l\'icône 🔒 à gauche de l\'URL → Notifications → Autoriser.');
            return;
        }
        if (Notification.permission === 'granted') {
            pushEnabled = !pushEnabled;
            localStorage.setItem('ak_push', pushEnabled ? '1' : '0');
            refreshPushToggle();
            if (pushEnabled && !silent) {
                try { new Notification('🎉 Notifications activées', { body: 'Tu recevras une notif système pour chaque alerte AssoKit.', icon: '/icons/icon-192.png' }); } catch(e){}
            }
            return;
        }
        Notification.requestPermission().then(function(p) {
            if (p === 'granted') {
                pushEnabled = true;
                localStorage.setItem('ak_push', '1');
                refreshPushToggle();
                try { new Notification('🎉 Notifications activées', { body: 'Tu recevras une notif système pour chaque alerte AssoKit.', icon: '/icons/icon-192.png' }); } catch(e){}
            }
        });
    }
    function refreshPushToggle() {
        var on = document.querySelector('.ak-push-on');
        var off = document.querySelector('.ak-push-off');
        var allowed = ('Notification' in window) && Notification.permission === 'granted' && pushEnabled;
        if (on)  on.hidden  = !allowed;
        if (off) off.hidden = allowed;
    }
    window.akTogglePush = akAskPushPermission;
    
    // Au premier chargement, on remplit seenIds avec les notifs déjà visibles
    // pour éviter de spammer de toasts
    function initSeenIds() {
        fetch('/api-notifications.php?action=list&limit=20', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.ok ? r.json() : null; })
        .then(function(data) {
            if (!data || !data.ok) return;
            data.notifications.forEach(function(n) { seenIds.add(n.id); });
        })
        .catch(function(){});
    }
    
    // Démarrage
    initSeenIds();
    refreshPushToggle();
    setTimeout(pollNotifications, 2000);
    setInterval(pollNotifications, POLL_INTERVAL);
    
    // Reprise immédiate quand on revient sur l'onglet
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            pollNotifications();
            // Quand l'utilisateur revient, on reset le favicon (les notifs ne sont plus "nouvelles")
            updateFavicon(0);
        }
    });
    
    // Toggle son : exposé globalement
    function refreshSoundToggle() {
        var on = document.querySelector('.ak-sound-on');
        var off = document.querySelector('.ak-sound-off');
        if (on && off) { on.hidden = !soundEnabled; off.hidden = soundEnabled; }
    }
    window.akToggleSound = function() {
        soundEnabled = !soundEnabled;
        localStorage.setItem('ak_sound', soundEnabled ? '1' : '0');
        refreshSoundToggle();
        if (soundEnabled) playDing();
    };
    refreshSoundToggle();
})();
</script>

<!-- 📱 PWA : Service Worker uniquement (bouton install retire 2026-05-15) -->
<script>
(function() {
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      navigator.serviceWorker.register('/service-worker.js').catch(function(){});
    });
  }
})();
</script>

<!-- 📱 PWA : invite d'installation discrete (rejetable, memorisee) -->
<script>
(function() {
  var KEY = 'ak_pwa_install_dismissed';
  // Dans l'app native (Capacitor) : jamais d'invite d'installation PWA
  if (window.Capacitor) return;
  try { if (localStorage.getItem(KEY)) return; } catch (e) { return; }
  // Deja installe (mode standalone) : ne rien afficher
  if ((window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone) return;

  var deferred = null;
  function dismiss(el){ try { localStorage.setItem(KEY, '1'); } catch(e){} if (el) el.remove(); }
  function showBanner(mode) {
    if (document.getElementById('ak-pwa-banner')) return;
    var b = document.createElement('div');
    b.id = 'ak-pwa-banner';
    b.style.cssText = 'position:fixed;left:12px;right:12px;bottom:12px;z-index:99999;max-width:440px;margin:0 auto;background:#fff;border:1px solid #E2E8F0;border-radius:14px;box-shadow:0 10px 40px rgba(15,23,42,.18);padding:14px 16px;display:flex;align-items:center;gap:12px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;';
    var txt = (mode === 'ios')
      ? 'Appuyez sur Partager puis « Sur l\'écran d\'accueil »'
      : 'Accès rapide, plein écran, même hors-ligne';
    b.innerHTML = '<img src="/icons/icon-192.png" alt="" width="40" height="40" style="border-radius:9px;flex:none;">'
      + '<div style="flex:1;min-width:0;"><div style="font-weight:650;font-size:14px;color:#0F172A;">Installer Assokit</div>'
      + '<div style="font-size:12.5px;color:#475569;line-height:1.35;">' + txt + '</div></div>';
    var actions = document.createElement('div');
    actions.style.cssText = 'display:flex;gap:6px;flex:none;align-items:center;';
    if (mode === 'android') {
      var inst = document.createElement('button');
      inst.type = 'button';
      inst.textContent = 'Installer';
      inst.style.cssText = 'background:#059669;color:#fff;border:0;border-radius:9px;padding:9px 14px;font-weight:600;font-size:13px;cursor:pointer;';
      inst.onclick = function() {
        if (deferred) { deferred.prompt(); deferred.userChoice.finally(function(){ deferred = null; dismiss(b); }); }
        else { dismiss(b); }
      };
      actions.appendChild(inst);
    }
    var close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Fermer');
    close.textContent = '✕';
    close.style.cssText = 'background:#F1F5F9;color:#475569;border:0;border-radius:9px;padding:9px 12px;font-size:13px;cursor:pointer;';
    close.onclick = function() { dismiss(b); };
    actions.appendChild(close);
    b.appendChild(actions);
    document.body.appendChild(b);
  }
  window.addEventListener('beforeinstallprompt', function(e) { e.preventDefault(); deferred = e; showBanner('android'); });
  var ua = navigator.userAgent || '';
  var isIOS = /iphone|ipad|ipod/i.test(ua);
  var isSafari = /safari/i.test(ua) && !/crios|fxios|chrome|android/i.test(ua);
  if (isIOS && isSafari) { setTimeout(function() { showBanner('ios'); }, 3000); }
})();
</script>
</body>
</html>
    <?php
}

/**
 * Renvoie les couleurs Tailwind-like pour un thème de dossier.
 * Compatible avec 16 couleurs.
 */
function folder_icon_class($theme) {
    $valid = ['blue','indigo','purple','magenta','pink','red','orange','amber',
              'yellow','lime','green','emerald','teal','cyan','slate','brown'];
    $theme = in_array($theme, $valid, true) ? $theme : 'blue';
    return 'fi-' . $theme;
}

/**
 * Map des couleurs HEX (utilisé pour les rendus inline si besoin).
 */
function folder_color_hex($theme) {
    $colors = [
        'blue'    => '#3B82F6',
        'indigo'  => '#6366F1',
        'purple'  => '#8B5CF6',
        'magenta' => '#D946EF',
        'pink'    => '#EC4899',
        'red'     => '#EF4444',
        'orange'  => '#F97316',
        'amber'   => '#F59E0B',
        'yellow'  => '#EAB308',
        'lime'    => '#84CC16',
        'green'   => '#10B981',
        'emerald' => '#059669',
        'teal'    => '#14B8A6',
        'cyan'    => '#06B6D4',
        'slate'   => '#64748B',
        'brown'   => '#8B4513',
    ];
    return $colors[$theme] ?? $colors['blue'];
}

/**
 * Renvoie le SVG inner d'une icône Lucide.
 * Si $icon n'est pas reconnu, fallback sur l'icône par défaut "folder".
 */
function folder_icon_inner($icon = 'folder') {
    $icons = [
        'folder'    => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'home'      => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'building'  => '<path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"/>',
        'clipboard' => '<rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
        'target'    => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'star'      => '<polygon points="12 2 15 9 22 9 17 14 19 21 12 17 5 21 7 14 2 9 9 9 12 2"/>',
        'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'palette'   => '<circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
        'graduation-cap' => '<path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>',
        'handshake' => '<path d="M11 17l-5-5-2 2 5 5 2-2zM21 12l-2-2-7 7 2 2 7-7zM12 7l3 3"/>',
        'lightbulb' => '<path d="M9 18h6M10 22h4M12 2a7 7 0 0 1 5 12c-1 1-2 2-2 4H9c0-2-1-3-2-4a7 7 0 0 1 5-12z"/>',
        'leaf'      => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19.2 2.96c.86 8.66-3.32 17.04-12.2 17.04L4 20l7-9"/>',
        'activity'  => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'megaphone' => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'wallet'    => '<path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12a2 2 0 0 0 2 2h14v-4"/><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4z"/>',
        'heart'     => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
        'baby'      => '<path d="M9 12h.01M15 12h.01M10 16c.5.3 1.2.5 2 .5s1.5-.2 2-.5"/><path d="M19 6.3a9 9 0 0 1 1.8 3.9 2 2 0 0 1 0 3.6 9 9 0 0 1-17.6 0 2 2 0 0 1 0-3.6A9 9 0 0 1 12 3a9 9 0 0 1 7 3.3z"/>',
        'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'globe'     => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    ];
    return $icons[$icon] ?? $icons['folder'];
}

/**
 * Icône SVG complète selon le thème + l'icône choisie.
 * Si pas d'icône (ancien dossier), fallback vers icône par défaut.
 * 
 * @param string $theme Couleur (blue, indigo, purple, ...)
 * @param string|null $icon Nom de l'icône (folder, home, building, ...)
 */
function folder_icon_svg($theme, $icon = null) {
    if (!$icon) {
        $icon = 'folder';
    }
    $inner = folder_icon_inner($icon);
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>';
}
