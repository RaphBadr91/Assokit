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
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
        'dot'      => '<circle cx="12" cy="12" r="5" fill="currentColor" stroke="none"/>',
        'info'     => '<circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'wallet'   => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M16 14h2"/>',
        'star'     => '<path d="M12 3l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 18.8 6.2 21.9l1.1-6.5L2.6 10.8l6.5-.9z"/>',
        'star-fill'=> '<path d="M12 3l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 18.8 6.2 21.9l1.1-6.5L2.6 10.8l6.5-.9z"/>',
        // Actions / rubriques
        'refresh'  => '<path d="M21 12a9 9 0 1 1-2.6-6.4M21 4v5h-5"/>',
        'folder'   => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h6a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'wrench'   => '<path d="M14.7 6.3a4 4 0 0 0-5.2 5.2L3 18v3h3l6.5-6.5a4 4 0 0 0 5.2-5.2l-2.6 2.6-2.4-.6-.6-2.4z"/>',
        'inbox'    => '<path d="M3 12h5l2 3h4l2-3h5"/><path d="M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/>',
        'gear'     => '<circle cx="12" cy="12" r="3.2"/><path d="M19 12a7 7 0 0 0-.1-1.4l2-1.5-2-3.5-2.3 1a7 7 0 0 0-2.4-1.4L15.8 2h-4l-.4 2.7A7 7 0 0 0 9 6.1L6.7 5 4.7 8.5l2 1.5A7 7 0 0 0 6.6 12a7 7 0 0 0 .1 1.4l-2 1.5 2 3.5 2.3-1a7 7 0 0 0 2.4 1.4l.4 2.7h4l.4-2.7a7 7 0 0 0 2.4-1.4l2.3 1 2-3.5-2-1.5A7 7 0 0 0 19 12z"/>',
        'bell'     => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'pin'      => '<path d="M12 21s-7-5.6-7-11a7 7 0 0 1 14 0c0 5.4-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'bolt'     => '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
        'robot'    => '<rect x="4" y="8" width="16" height="11" rx="2.5"/><path d="M12 4v4M9 13h.01M15 13h.01M2 12v3M22 12v3"/>',
        'receipt'  => '<path d="M5 3v18l2-1.3L9 21l2-1.3L13 21l2-1.3L17 21l2-1.3V3l-2 1.3L15 3l-2 1.3L11 3 9 4.3 7 3z"/><path d="M8 8h8M8 12h8"/>',
        'idcard'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="11" r="2"/><path d="M5.5 16a3 3 0 0 1 6 0M14 9h4M14 13h4"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'close'    => '<line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/>',
    ];
    $inner = $paths[$name] ?? $paths['dot'];
    $filled = in_array($name, ['dot','sparkle','star-fill'], true);
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
