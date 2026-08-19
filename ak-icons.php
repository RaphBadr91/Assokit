<?php
/**
 * ak-icons.php — Jeu d'icônes maison (SVG inline, style « ligne »).
 * ------------------------------------------------------------------
 * Remplace les emoji système par des icônes cohérentes et pro.
 *   ak_icon('target', 20)            -> <svg> ligne, hérite de currentColor
 *   ak_icon_badge('target', '#10B981') -> icône dans une pastille douce (en-têtes)
 *   ak_dot('#F59E0B')                -> pastille pleine colorée (statuts)
 * ------------------------------------------------------------------
 */

if (!function_exists('ak_icon')) {
function ak_icon(string $name, int $size = 16, string $stroke = '1.8'): string {
    $paths = [
        // En-têtes de page
        'target'   => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/>',
        'megaphone'=> '<path d="M3 11l14-6v14L3 13z"/><path d="M3 11v2a2 2 0 0 0 2 2h1v3a1 1 0 0 0 1 1h2v-4"/><path d="M20 9a3 3 0 0 1 0 6"/>',
        'alert-tri'=> '<path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13.5"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'chart'    => '<path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/>',
        'sparkle'  => '<path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/>',
        // Statuts / puces
        'check'    => '<path d="M20 6L9 17l-5-5"/>',
        'dot'      => '<circle cx="12" cy="12" r="5" fill="currentColor" stroke="none"/>',
        'info'     => '<circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'wallet'   => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M16 14h2"/>',
        'star'     => '<path d="M12 3l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 18.8 6.2 21.9l1.1-6.5L2.6 10.8l6.5-.9z"/>',
    ];
    $inner = $paths[$name] ?? $paths['dot'];
    $filled = in_array($name, ['dot','sparkle','star'], true);
    $fill = $filled ? 'currentColor' : 'none';
    return '<svg viewBox="0 0 24 24" width="'.$size.'" height="'.$size.'" fill="'.$fill.'" stroke="currentColor" stroke-width="'.$stroke.'" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$inner.'</svg>';
}
}

if (!function_exists('ak_icon_badge')) {
/** Icône dans une pastille douce colorée — pour les en-têtes de page. */
function ak_icon_badge(string $name, string $color = '#10B981', int $size = 40): string {
    $ico = $size >= 34 ? 22 : 18;
    $bg = ak_hex_tint($color, 0.12);
    return '<span style="display:inline-flex;align-items:center;justify-content:center;width:'.$size.'px;height:'.$size.'px;'
        . 'border-radius:'.round($size*0.3).'px;background:'.$bg.';color:'.$color.';flex:none;vertical-align:middle;">'
        . ak_icon($name, $ico, '2') . '</span>';
}
}

if (!function_exists('ak_dot')) {
/** Petite pastille pleine colorée (statuts dans les pilules). */
function ak_dot(string $color, int $size = 8): string {
    return '<span style="display:inline-block;width:'.$size.'px;height:'.$size.'px;border-radius:50%;background:'.$color.';flex:none;"></span>';
}
}

if (!function_exists('ak_hex_tint')) {
/** Mélange une couleur hex avec du blanc (alpha 0..1) -> teinte douce opaque. */
function ak_hex_tint(string $hex, float $alpha): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (strlen($hex) !== 6) return 'rgba(16,185,129,0.12)';
    $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
    $r = (int)round($r*$alpha + 255*(1-$alpha));
    $g = (int)round($g*$alpha + 255*(1-$alpha));
    $b = (int)round($b*$alpha + 255*(1-$alpha));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}
}
