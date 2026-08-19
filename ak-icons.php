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
        // Navigation & entités
        'home'     => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'users'    => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 6a3 3 0 0 1 0 5"/>',
        'user'     => '<circle cx="12" cy="8" r="3.5"/><path d="M5 21a7 7 0 0 1 14 0"/>',
        'user-plus'=> '<circle cx="9" cy="8" r="3.2"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M18 8v6M15 11h6"/>',
        'calendar' => '<rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9h18M8 2.5v4M16 2.5v4"/>',
        'tag'      => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7" cy="7" r="1.3" fill="currentColor" stroke="none"/>',
        'file'     => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/>',
        'file-text'=> '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/>',
        'edit'     => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/>',
        'trash'    => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
        'plus'     => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/>',
        'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5M12 3v12"/>',
        'eye'      => '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>',
        'lock'     => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'send'     => '<path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/>',
        'filter'   => '<path d="M3 5h18l-7 8v6l-4-2v-4z"/>',
        'euro'     => '<path d="M17 5a7 7 0 1 0 0 14M4 10h9M4 14h7"/>',
        'credit-card'=>'<rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20"/>',
        'building' => '<path d="M3 21h18M5 21V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v16M9 8h.01M13 8h.01M9 12h.01M13 12h.01M9 16h.01M13 16h.01"/><path d="M17 21V9h2a2 2 0 0 1 2 2v10"/>',
        'clipboard'=> '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1M9 12h6M9 16h4"/>',
        'message'  => '<path d="M21 15a2 2 0 0 1-2 2H8l-4 4V5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2z"/>',
        'phone'    => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2 4.2 2 2 0 0 1 4 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.6a16 16 0 0 0 6 6l1.1-1.1a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
        'flag'     => '<path d="M4 22V4a1 1 0 0 1 1-1h13l-2 4 2 4H5"/>',
        'gift'     => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v9h14v-9M12 8v13M12 8S9.5 3 7 5s5 3 5 3zM12 8s2.5-5 5-3-5 3-5 3z"/>',
        'trophy'   => '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M7 6H4v2a3 3 0 0 0 3 3M17 6h3v2a3 3 0 0 1-3 3"/>',
        'book'     => '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19a2 2 0 0 0 2 2h13"/>',
        'briefcase'=> '<rect x="3" y="8" width="18" height="12" rx="2"/><path d="M8 8V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18"/>',
        'heart'    => '<path d="M20.8 6.6a4 4 0 0 0-6.8-2.3L12 6l-2-1.7a4 4 0 1 0-5.6 5.7l7.6 7.6 7.6-7.6a4 4 0 0 0 1.2-3.4z"/>',
        'shield'   => '<path d="M12 2l8 3v6c0 5-3.4 8.5-8 11-4.6-2.5-8-6-8-11V5z"/>',
        'list'     => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'layers'   => '<path d="M12 2l10 6-10 6L2 8z"/><path d="M2 12l10 6 10-6M2 16l10 6 10-6"/>',
        'pie'      => '<path d="M12 3v9l7.5 5A9 9 0 1 1 12 3z"/><path d="M21 12a9 9 0 0 0-9-9v9z"/>',
        'trending-up'=>'<path d="M3 17l6-6 4 4 8-8M15 7h6v6"/>',
        'trending-down'=>'<path d="M3 7l6 6 4-4 8 8M15 17h6v-6"/>',
        'image'    => '<rect x="3" y="3" width="18" height="18" rx="2.5"/><circle cx="8.5" cy="8.5" r="1.6"/><path d="M21 15l-5-5L5 21"/>',
        'link'     => '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1.5 1.5M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1.5-1.5"/>',
        'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/>',
        'arrow-right'=>'<path d="M5 12h14M13 6l6 6-6 6"/>',
        'arrow-left'=>'<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'camera'   => '<rect x="3" y="7" width="18" height="13" rx="2.5"/><circle cx="12" cy="13.5" r="3.5"/><path d="M8 7l1.5-3h5L16 7"/>',
        'printer'  => '<path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="7" rx="1"/>',
        'ban'      => '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
        'help'     => '<circle cx="12" cy="12" r="9"/><path d="M9.2 9.2a2.8 2.8 0 0 1 5.4 1c0 1.8-2.6 2.3-2.6 4M12 17h.01"/>',
        'lightbulb'=> '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-4 10.5c.7.7 1 1.2 1 2.5h6c0-1.3.3-1.8 1-2.5A6 6 0 0 0 12 3z"/>',
        'rocket'   => '<path d="M5 15c-1.5 1.5-2 5-2 5s3.5-.5 5-2a2.8 2.8 0 0 0-3-3z"/><path d="M9 12a12 12 0 0 1 8-8c2 0 3 1 3 3a12 12 0 0 1-8 8zM9 12l3 3"/><circle cx="15" cy="9" r="1.3"/>',
        'handshake'=> '<path d="M11 17l2 2a2 2 0 0 0 3-3M8 14l3 3M3 10l4-4 5 5-2 2a2 2 0 0 1-3 0zM21 10l-4-4-3 3"/>',
        'palette'  => '<path d="M12 3a9 9 0 1 0 0 18c1.5 0 2-1 1.5-2s-.5-2 1-2H18a3 3 0 0 0 3-3 8 8 0 0 0-9-8z"/><circle cx="8" cy="10" r="1.1" fill="currentColor" stroke="none"/><circle cx="12" cy="7.5" r="1.1" fill="currentColor" stroke="none"/><circle cx="16" cy="10" r="1.1" fill="currentColor" stroke="none"/>',
        'key'      => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3M14 9l2 2"/>',
        'phone-scan'=>'<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M9 6h6M10 18h4"/>',
        'eye-off'  => '<path d="M9.9 5.1A9.6 9.6 0 0 1 12 5c7 0 11 7 11 7a13 13 0 0 1-3.2 3.6M6.6 6.6A13 13 0 0 0 1 12s4 7 11 7a9.6 9.6 0 0 0 4.1-.9M2 2l20 20"/>',
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
