<?php
/**
 * Plugin Name: Assokit
 * Plugin URI:  https://assokit.fr
 * Description: Intègre Assokit à votre site WordPress d'association : affichez vos événements et projets publics, et un bouton vers l'espace adhérent. (v1 — l'adhésion/don en ligne arrivera avec le paiement Assokit.)
 * Version:     1.0.0
 * Author:      Assokit
 * Author URI:  https://assokit.fr
 * License:     GPL-2.0-or-later
 * Text Domain: assokit
 * ------------------------------------------------------------------
 * Shortcodes :
 *   [assokit_evenement token="…" hauteur="1000"]   → événement public
 *   [assokit_projet token="…" hauteur="1200"]       → projet public
 *   [assokit_espace texte="Espace adhérent"]        → bouton connexion
 *   [assokit_bouton url="/tarifs" texte="Découvrir Assokit"]
 * Réglages : Réglages → Assokit (URL de base, défaut https://assokit.fr).
 * ------------------------------------------------------------------
 */

if (!defined('ABSPATH')) exit; // pas d'accès direct

/** URL de base Assokit (réglable), toujours https + hôte assokit.fr. */
function assokit_base_url() {
    $u = trim((string) get_option('assokit_base_url', 'https://assokit.fr'));
    $u = rtrim($u, '/');
    $host = parse_url($u, PHP_URL_HOST);
    // Sécurité : n'autorise que le domaine officiel.
    if (!$host || !preg_match('/(^|\.)assokit\.fr$/i', $host) || parse_url($u, PHP_URL_SCHEME) !== 'https') {
        $u = 'https://assokit.fr';
    }
    return $u;
}

/** Jeton public : hexadécimal 32–64 caractères. */
function assokit_clean_token($t) {
    $t = (string) $t;
    return preg_match('/^[a-f0-9]{32,64}$/i', $t) ? strtolower($t) : '';
}

/** iframe responsive commune. */
function assokit_iframe($path, $height) {
    $src = esc_url(assokit_base_url() . $path);
    $h   = max(300, min(3000, (int) $height));
    return '<div class="assokit-embed" style="position:relative;width:100%;margin:16px 0;">'
        . '<iframe src="' . $src . '" loading="lazy" '
        . 'style="width:100%;height:' . $h . 'px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;" '
        . 'sandbox="allow-scripts allow-forms allow-popups allow-same-origin" '
        . 'referrerpolicy="no-referrer-when-downgrade" title="Assokit"></iframe></div>';
}

/** [assokit_evenement token="…" hauteur="1000"] */
function assokit_sc_evenement($atts) {
    $a = shortcode_atts(['token' => '', 'hauteur' => 1000], $atts, 'assokit_evenement');
    $tok = assokit_clean_token($a['token']);
    if (!$tok) return '<em>Assokit : jeton d\'événement invalide.</em>';
    return assokit_iframe('/evenement-public/' . $tok, $a['hauteur']);
}
add_shortcode('assokit_evenement', 'assokit_sc_evenement');

/** [assokit_projet token="…" hauteur="1200"] */
function assokit_sc_projet($atts) {
    $a = shortcode_atts(['token' => '', 'hauteur' => 1200], $atts, 'assokit_projet');
    $tok = assokit_clean_token($a['token']);
    if (!$tok) return '<em>Assokit : jeton de projet invalide.</em>';
    return assokit_iframe('/projet-public/' . $tok, $a['hauteur']);
}
add_shortcode('assokit_projet', 'assokit_sc_projet');

/** [assokit_espace texte="Espace adhérent"] → bouton connexion. */
function assokit_sc_espace($atts) {
    $a = shortcode_atts(['texte' => 'Espace adhérent'], $atts, 'assokit_espace');
    return assokit_button('/connexion', $a['texte']);
}
add_shortcode('assokit_espace', 'assokit_sc_espace');

/** [assokit_bouton url="/tarifs" texte="…"] → bouton vers une page Assokit. */
function assokit_sc_bouton($atts) {
    $a = shortcode_atts(['url' => '/', 'texte' => 'Découvrir Assokit'], $atts, 'assokit_bouton');
    $path = '/' . ltrim((string) $a['url'], '/');
    return assokit_button($path, $a['texte']);
}
add_shortcode('assokit_bouton', 'assokit_sc_bouton');

function assokit_button($path, $texte) {
    $url = esc_url(assokit_base_url() . $path);
    return '<a href="' . $url . '" target="_blank" rel="noopener" class="assokit-btn" '
        . 'style="display:inline-flex;align-items:center;gap:8px;padding:12px 22px;'
        . 'background:linear-gradient(135deg,#10B981,#059669);color:#fff;text-decoration:none;'
        . 'border-radius:11px;font-weight:700;font-size:15px;">' . esc_html($texte) . ' →</a>';
}

/* ------------------------------ Réglages ------------------------------ */
add_action('admin_menu', function () {
    add_options_page('Assokit', 'Assokit', 'manage_options', 'assokit', 'assokit_settings_page');
});
add_action('admin_init', function () {
    register_setting('assokit', 'assokit_base_url', ['sanitize_callback' => 'esc_url_raw']);
});
function assokit_settings_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
      <h1>Assokit</h1>
      <form method="post" action="options.php">
        <?php settings_fields('assokit'); ?>
        <table class="form-table">
          <tr>
            <th scope="row"><label for="assokit_base_url">URL Assokit</label></th>
            <td>
              <input type="url" id="assokit_base_url" name="assokit_base_url" class="regular-text"
                     value="<?php echo esc_attr(get_option('assokit_base_url', 'https://assokit.fr')); ?>"
                     placeholder="https://assokit.fr">
              <p class="description">Domaine assokit.fr uniquement.</p>
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
      <h2>Shortcodes</h2>
      <ul style="list-style:disc;margin-left:20px;">
        <li><code>[assokit_evenement token="LE_JETON"]</code> — affiche un événement public</li>
        <li><code>[assokit_projet token="LE_JETON"]</code> — affiche un projet public</li>
        <li><code>[assokit_espace texte="Espace adhérent"]</code> — bouton connexion</li>
        <li><code>[assokit_bouton url="/tarifs" texte="Découvrir"]</code> — bouton vers une page</li>
      </ul>
      <p class="description">Le jeton public se trouve dans Assokit, sur la page de partage de l'événement / du projet.</p>
    </div>
    <?php
}
